<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Pipeline\Queue;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;

/**
 * Verifies AWS SigV4 chunked transfer encoding signatures.
 *
 * When a client sends a request with content-encoding "aws-chunked" and
 * x-amz-content-sha256 of "STREAMING-AWS4-HMAC-SHA256-PAYLOAD", each chunk
 * of the body includes a signature that chains from the initial seed
 * signature in the Authorization header.
 *
 * Chunk format:
 *   {hex-size};chunk-signature={signature}\r\n
 *   {chunk-data}\r\n
 *
 * Final chunk:
 *   0;chunk-signature={signature}\r\n
 *   \r\n
 *
 * Each chunk signature is computed as:
 *   HMAC-SHA256(signingKey, stringToSign)
 *
 * Where stringToSign is:
 *   AWS4-HMAC-SHA256-PAYLOAD\n
 *   {timestamp}\n
 *   {credentialScope}\n
 *   {previousSignature}\n
 *   {hash("")}\n
 *   {hash(chunkData)}
 *
 * @see https://docs.aws.amazon.com/AmazonS3/latest/API/sigv4-streaming.html
 */
final class ChunkedSignatureVerifier
{
    /**
     * SHA-256 hash of empty string, precomputed for chunk signature verification.
     */
    private const string EMPTY_HASH = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

    /**
     * Regex for parsing the chunk header line.
     *
     * Matches: {hex-size};chunk-signature={64-char-hex-signature}
     */
    private const string CHUNK_HEADER_PATTERN = '/^([0-9a-fA-F]+);chunk-signature=([0-9a-f]{64})$/';

    private const int MAX_HEADER_SIZE = 1024;

    private const int MAX_TRAILER_SIZE = 16_384;

    /**
     * Create a verified readable stream that strips chunked encoding framing
     * and verifies each chunk's signature.
     *
     * The returned stream yields only the raw decoded data, with all chunked
     * framing and signatures stripped. If any chunk signature is invalid,
     * the stream will error with a SignatureDoesNotMatch exception.
     *
     * @param  ReadableStream  $body  The raw chunked body stream.
     * @param  string  $signingKey  The derived signing key (binary).
     * @param  string  $timestamp  The request timestamp (ISO 8601 basic format).
     * @param  string  $credentialScope  The credential scope (date/region/s3/aws4_request).
     * @param  string  $seedSignature  The initial signature from the Authorization header.
     * @return ReadableStream A stream yielding verified, decoded chunk data.
     * @param list<string> $trailerNames
     */
    public function createVerifiedStream(
        ReadableStream $body,
        string $signingKey,
        string $timestamp,
        string $credentialScope,
        string $seedSignature,
        array $trailerNames = [],
        ?\Closure $onTrailers = null,
    ): ReadableStream {
        $queue = new Queue(bufferSize: 8);

        // Process chunks asynchronously using Amp's fiber-based concurrency.
        \Amp\async(function () use (
            $body,
            $signingKey,
            $timestamp,
            $credentialScope,
            $seedSignature,
            $trailerNames,
            $onTrailers,
            $queue,
        ): void {
            try {
                $previousSignature = $seedSignature;
                $buffer = '';
                $checksumContexts = self::checksumContexts($trailerNames);

                while (true) {
                    // Read data from the underlying stream into our buffer.
                    $chunk = $body->read();
                    if ($chunk === null) {
                        // Stream ended without final 0-size chunk — truncated body.
                        throw new InternalErrorException(
                            'The request body terminated unexpectedly during chunked transfer '
                            . '(missing final 0-size chunk).',
                        );
                    }

                    $buffer .= $chunk;

                    // Process as many complete chunks as possible from the buffer.
                    while (true) {
                        $result = self::tryParseChunk($buffer, $trailerNames !== []);
                        if ($result === null) {
                            // Need more data to parse a complete chunk.
                            break;
                        }

                        [$chunkSize, $chunkSignature, $chunkData, $consumed] = $result;
                        $buffer = substr($buffer, $consumed);

                        // Verify the chunk signature.
                        $expectedSignature = self::computeChunkSignature(
                            $signingKey,
                            $timestamp,
                            $credentialScope,
                            $previousSignature,
                            $chunkData,
                        );

                        if (! hash_equals($expectedSignature, $chunkSignature)) {
                            throw new SignatureDoesNotMatchException(
                                'Chunk signature verification failed. The chunk signature does not match.',
                            );
                        }

                        $previousSignature = $chunkSignature;

                        // Final chunk (size 0) means we're done.
                        if ($chunkSize === 0) {
                            if ($trailerNames !== []) {
                                $trailers = self::readAndVerifyTrailers(
                                    $body,
                                    $buffer,
                                    $signingKey,
                                    $timestamp,
                                    $credentialScope,
                                    $previousSignature,
                                    $trailerNames,
                                    $checksumContexts,
                                );
                                $onTrailers?->__invoke($trailers);
                            } elseif ($buffer !== '') {
                                throw new InvalidArgumentException('Unexpected data after the final signed chunk.');
                            }
                            if ($body->read() !== null) {
                                throw new InvalidArgumentException('Unexpected data after the final signed chunk.');
                            }
                            $queue->complete();

                            return;
                        }

                        // Yield the verified chunk data.
                        if ($chunkData !== '') {
                            foreach ($checksumContexts as $context) {
                                hash_update($context, $chunkData);
                            }
                            $queue->push($chunkData);
                        }
                    }
                }
            } catch (\Throwable $e) {
                $queue->error($e);
            }
        });

        return new ReadableIterableStream($queue->iterate());
    }

    /**
     * Attempt to parse a complete chunk from the buffer.
     *
     * Returns null if the buffer does not contain a complete chunk.
     * Returns [chunkSize, signature, chunkData, bytesConsumed] on success.
     *
     * @return array{0: int, 1: string, 2: string, 3: int}|null
     */
    private static function tryParseChunk(string $buffer, bool $expectTrailers): ?array
    {
        // Find the chunk header line (terminated by \r\n).
        $headerEnd = strpos($buffer, "\r\n");
        if ($headerEnd === false) {
            if (strlen($buffer) > self::MAX_HEADER_SIZE) {
                throw new InvalidArgumentException('Signed chunk header exceeds maximum allowed size.');
            }

            return null;
        }
        if ($headerEnd > self::MAX_HEADER_SIZE) {
            throw new InvalidArgumentException('Signed chunk header exceeds maximum allowed size.');
        }

        $headerLine = substr($buffer, 0, $headerEnd);

        // Parse the chunk header.
        if (! preg_match(self::CHUNK_HEADER_PATTERN, $headerLine, $matches)) {
            throw new InvalidArgumentException(
                sprintf('Invalid chunk header format: "%s".', $headerLine),
            );
        }

        $chunkSize = hexdec($matches[1]);
        if (! is_int($chunkSize)) {
            $chunkSize = (int) $chunkSize;
        }
        // The parser buffers one encoded chunk. Keep this bounded independently
        // from the total request size so a validly signed request cannot exhaust RAM.
        if ($chunkSize < 0 || $chunkSize > 16_777_216) {
            throw new InvalidArgumentException('Chunk size exceeds maximum allowed limit.');
        }
        $chunkSignature = $matches[2];

        // Calculate the total bytes needed for this complete chunk:
        // header + \r\n + data + \r\n
        $dataStart = $headerEnd + 2; // After header's \r\n
        $totalNeeded = $dataStart + $chunkSize + ($chunkSize === 0 && $expectTrailers ? 0 : 2);

        if (strlen($buffer) < $totalNeeded) {
            return null; // Need more data.
        }

        $chunkData = $chunkSize > 0 ? substr($buffer, $dataStart, $chunkSize) : '';

        if (! ($chunkSize === 0 && $expectTrailers)) {
            // Verify the trailing \r\n after chunk data.
            $trailingCrlf = substr($buffer, $dataStart + $chunkSize, 2);
            if ($trailingCrlf !== "\r\n") {
                throw new InvalidArgumentException(
                    'Invalid chunk encoding: missing CRLF after chunk data.',
                );
            }
        }

        return [$chunkSize, $chunkSignature, $chunkData, $totalNeeded];
    }

    /**
     * Compute the expected signature for a chunk.
     *
     * String to sign:
     *   AWS4-HMAC-SHA256-PAYLOAD\n
     *   {timestamp}\n
     *   {credentialScope}\n
     *   {previousSignature}\n
     *   {hash("")}\n
     *   {hash(chunkData)}
     */
    private static function computeChunkSignature(
        string $signingKey,
        string $timestamp,
        string $credentialScope,
        string $previousSignature,
        string $chunkData,
    ): string {
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256-PAYLOAD',
            $timestamp,
            $credentialScope,
            $previousSignature,
            self::EMPTY_HASH,
            hash('sha256', $chunkData),
        ]);

        return hash_hmac('sha256', $stringToSign, $signingKey);
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
                default => throw new InvalidArgumentException("Unsupported signed trailer: {$name}."),
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
    private static function readAndVerifyTrailers(
        ReadableStream $body,
        string &$buffer,
        string $signingKey,
        string $timestamp,
        string $credentialScope,
        string $previousSignature,
        array $trailerNames,
        array $checksumContexts,
    ): array {
        while (($end = strpos($buffer, "\r\n\r\n")) === false) {
            if (strlen($buffer) > self::MAX_TRAILER_SIZE) {
                throw new InvalidArgumentException('Signed trailer block exceeds 16 KiB.');
            }
            $next = $body->read();
            if ($next === null) {
                throw new InvalidArgumentException('Signed trailer block terminated unexpectedly.');
            }
            $buffer .= $next;
        }
        if ($end > self::MAX_TRAILER_SIZE) {
            throw new InvalidArgumentException('Signed trailer block exceeds 16 KiB.');
        }

        $block = substr($buffer, 0, $end);
        $remaining = substr($buffer, $end + 4);
        if ($remaining !== '' || $body->read() !== null) {
            throw new InvalidArgumentException('Unexpected data after the signed trailer block.');
        }

        $trailers = [];
        foreach (explode("\r\n", $block) as $line) {
            $separator = strpos($line, ':');
            if ($separator === false) {
                throw new InvalidArgumentException('Malformed signed trailer header.');
            }
            $name = strtolower(trim(substr($line, 0, $separator)));
            $value = trim(preg_replace('/[ \t]+/', ' ', substr($line, $separator + 1)) ?? '');
            if ($name === '' || isset($trailers[$name])) {
                throw new InvalidArgumentException('Duplicate or empty signed trailer header.');
            }
            $trailers[$name] = $value;
        }

        $trailerSignature = $trailers['x-amz-trailer-signature'] ?? null;
        unset($trailers['x-amz-trailer-signature']);
        if ($trailerSignature === null || preg_match('/^[a-f0-9]{64}$/', $trailerSignature) !== 1) {
            throw new SignatureDoesNotMatchException('Missing or malformed x-amz-trailer-signature.');
        }

        sort($trailerNames, SORT_STRING);
        if (array_keys($trailers) !== $trailerNames) {
            ksort($trailers, SORT_STRING);
            if (array_keys($trailers) !== $trailerNames) {
                throw new InvalidArgumentException('Signed trailers do not match the x-amz-trailer declaration.');
            }
        }

        $canonicalTrailers = '';
        foreach ($trailerNames as $name) {
            $canonicalTrailers .= $name . ':' . $trailers[$name] . "\n";
        }
        $stringToSign = implode("\n", [
            'AWS4-HMAC-SHA256-TRAILER',
            $timestamp,
            $credentialScope,
            $previousSignature,
            hash('sha256', $canonicalTrailers),
        ]);
        $expectedSignature = hash_hmac('sha256', $stringToSign, $signingKey);
        if (! hash_equals($expectedSignature, $trailerSignature)) {
            throw new SignatureDoesNotMatchException('Trailer signature verification failed.');
        }

        foreach ($checksumContexts as $name => $context) {
            $computed = base64_encode(hash_final($context, true));
            if (! hash_equals($computed, $trailers[$name])) {
                throw new \OpsFour\S3Server\Exception\BadDigestException(
                    "The {$name} trailer did not match the streamed payload.",
                );
            }
        }

        return $trailers;
    }
}
