<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Pipeline\Queue;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;

/**
 * Decodes STREAMING-UNSIGNED-PAYLOAD-TRAILER bodies and validates their
 * declared trailing checksums without buffering the complete object.
 */
final class UnsignedChunkedTrailerDecoder
{
    private const int MAX_CHUNK_SIZE = 16_777_216;

    private const int MAX_HEADER_SIZE = 1024;

    private const int MAX_TRAILER_SIZE = 16_384;

    /**
     * @param list<string> $trailerNames
     */
    public function createDecodedStream(
        ReadableStream $body,
        array $trailerNames,
        ?\Closure $onTrailers = null,
    ): ReadableStream {
        $queue = new Queue(bufferSize: 8);

        \Amp\async(function () use ($body, $trailerNames, $onTrailers, $queue): void {
            try {
                $buffer = '';
                $checksumContexts = self::checksumContexts($trailerNames);

                while (true) {
                    $next = $body->read();
                    if ($next === null) {
                        throw new InvalidArgumentException('Unsigned chunked body is missing its completion chunk.');
                    }
                    $buffer .= $next;

                    while (true) {
                        $parsed = self::tryParseChunk($buffer);
                        if ($parsed === null) {
                            break;
                        }

                        [$size, $data, $consumed] = $parsed;
                        $buffer = substr($buffer, $consumed);

                        if ($size === 0) {
                            $trailers = self::readTrailers($body, $buffer, $trailerNames, $checksumContexts);
                            $onTrailers?->__invoke($trailers);
                            $queue->complete();

                            return;
                        }

                        foreach ($checksumContexts as $context) {
                            hash_update($context, $data);
                        }
                        $queue->push($data);
                    }
                }
            } catch (\Throwable $e) {
                $queue->error($e);
            }
        });

        return new ReadableIterableStream($queue->iterate());
    }

    /**
     * @return array{0: int, 1: string, 2: int}|null
     */
    private static function tryParseChunk(string $buffer): ?array
    {
        $headerEnd = strpos($buffer, "\r\n");
        if ($headerEnd === false) {
            if (strlen($buffer) > self::MAX_HEADER_SIZE) {
                throw new InvalidArgumentException('Unsigned chunk header exceeds maximum allowed size.');
            }

            return null;
        }
        if ($headerEnd > self::MAX_HEADER_SIZE) {
            throw new InvalidArgumentException('Unsigned chunk header exceeds maximum allowed size.');
        }

        $header = substr($buffer, 0, $headerEnd);
        if (preg_match('/^[0-9a-fA-F]+$/', $header) !== 1) {
            throw new InvalidArgumentException('Invalid unsigned chunk header.');
        }
        $size = hexdec($header);
        if (! is_int($size)) {
            $size = (int) $size;
        }
        if ($size < 0 || $size > self::MAX_CHUNK_SIZE) {
            throw new InvalidArgumentException('Unsigned chunk size exceeds maximum allowed limit.');
        }

        $dataStart = $headerEnd + 2;
        $total = $dataStart + $size + ($size === 0 ? 0 : 2);
        if (strlen($buffer) < $total) {
            return null;
        }

        $data = $size > 0 ? substr($buffer, $dataStart, $size) : '';
        if ($size > 0 && substr($buffer, $dataStart + $size, 2) !== "\r\n") {
            throw new InvalidArgumentException('Invalid unsigned chunk encoding after chunk data.');
        }

        return [$size, $data, $total];
    }

    /**
     * @param list<string> $trailerNames
     * @return array<string, \HashContext>
     */
    private static function checksumContexts(array $trailerNames): array
    {
        $contexts = [];
        foreach ($trailerNames as $name) {
            $algorithm = match ($name) {
                'x-amz-checksum-crc32' => 'crc32b',
                'x-amz-checksum-crc32c' => 'crc32c',
                'x-amz-checksum-sha1' => 'sha1',
                'x-amz-checksum-sha256' => 'sha256',
                default => throw new InvalidArgumentException("Unsupported unsigned trailer: {$name}."),
            };
            $contexts[$name] = hash_init($algorithm);
        }

        return $contexts;
    }

    /**
     * @param list<string> $trailerNames
     * @param array<string, \HashContext> $checksumContexts
     * @return array<string, string>
     */
    private static function readTrailers(
        ReadableStream $body,
        string $buffer,
        array $trailerNames,
        array $checksumContexts,
    ): array {
        while (($end = strpos($buffer, "\r\n\r\n")) === false) {
            if (strlen($buffer) > self::MAX_TRAILER_SIZE) {
                throw new InvalidArgumentException('Unsigned trailer block exceeds 16 KiB.');
            }
            $next = $body->read();
            if ($next === null) {
                throw new InvalidArgumentException('Unsigned trailer block terminated unexpectedly.');
            }
            $buffer .= $next;
        }
        if ($end > self::MAX_TRAILER_SIZE) {
            throw new InvalidArgumentException('Unsigned trailer block exceeds 16 KiB.');
        }
        if (substr($buffer, $end + 4) !== '' || $body->read() !== null) {
            throw new InvalidArgumentException('Unexpected data after the unsigned trailer block.');
        }

        $trailers = [];
        foreach (explode("\r\n", substr($buffer, 0, $end)) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                throw new InvalidArgumentException('Malformed unsigned trailer header.');
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(substr($line, $separator + 1));
            if ($name === '' || isset($trailers[$name])) {
                throw new InvalidArgumentException('Duplicate or empty unsigned trailer header.');
            }
            $trailers[$name] = $value;
        }

        sort($trailerNames, SORT_STRING);
        ksort($trailers, SORT_STRING);
        if (array_keys($trailers) !== $trailerNames) {
            throw new InvalidArgumentException('Unsigned trailers do not match the x-amz-trailer declaration.');
        }

        foreach ($checksumContexts as $name => $context) {
            $computed = base64_encode(hash_final($context, true));
            if (! hash_equals($computed, $trailers[$name])) {
                throw new BadDigestException("The {$name} trailer did not match the streamed payload.");
            }
        }

        return $trailers;
    }
}
