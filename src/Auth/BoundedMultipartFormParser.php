<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Http1\Rfc7230;
use Amp\Http\HttpStatus;
use Amp\Http\InvalidHeaderException;
use Amp\Http\Server\ClientException;
use Amp\Http\Server\FormParser\StreamedField;
use Amp\Http\Server\HttpErrorException;
use Amp\Pipeline\Queue;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use Revolt\EventLoop;

/**
 * Browser POST multipart parser with bounded headers and exact delimiter checks.
 *
 * Only a CRLF followed by the complete boundary and a valid suffix separates
 * parts. A boundary prefix with another suffix remains unchanged file data.
 */
final class BoundedMultipartFormParser
{
    public const int MAX_HEADER_BYTES = 16_384;

    private const int SCAN_CHUNK_BYTES = 65_536;

    private string $buffer = '';

    private int $bufferOffset = 0;

    private int $bodyBytes = 0;

    public function __construct(
        private readonly ReadableStream $body,
        private readonly string $boundary,
        private readonly int $bodySizeLimit,
        private readonly int $fieldCountLimit = 100,
    ) {
        if ($boundary === '' || strlen($boundary) > 70) {
            throw new InvalidArgumentException('POST Object requires a valid multipart boundary.');
        }
        if ($bodySizeLimit < 0) {
            throw new \InvalidArgumentException('Multipart body size limit must not be negative.');
        }
    }

    /** @return \Traversable<int, StreamedField> */
    public function parse(): \Traversable
    {
        $source = new Queue();
        $pipeline = $source->pipe();

        EventLoop::queue(function () use ($source): void {
            try {
                $this->parseBody($source);
                $source->complete();
            } catch (\Throwable $error) {
                $source->error($error);
            }
        });

        return $pipeline->getIterator();
    }

    /** @param Queue<StreamedField> $source */
    private function parseBody(Queue $source): void
    {
        $opening = '--' . $this->boundary;
        if ($this->readExact(strlen($opening)) !== $opening) {
            throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Invalid boundary');
        }
        $firstSuffix = $this->readExact(2);
        if ($firstSuffix === '--') {
            if ($this->readExact(2) !== "\r\n") {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Invalid boundary');
            }

            $this->drainEpilog();

            return;
        }
        if ($firstSuffix !== "\r\n") {
            throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Invalid boundary');
        }

        $fieldCount = 0;
        while (true) {
            $headers = $this->readHeaders();
            if ($fieldCount++ === $this->fieldCountLimit) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Maximum number of variables exceeded');
            }

            try {
                $pairs = Rfc7230::parseHeaderPairs(substr($headers, 0, -2));
            } catch (InvalidHeaderException $error) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Invalid headers in body part', $error);
            }

            $headerMap = [];
            foreach ($pairs as [$name, $value]) {
                $headerMap[strtolower($name)][] = $value;
            }
            $matched = preg_match(
                '#^\s*form-data(?:\s*;\s*(?:name\s*=\s*"([^"]+)"|filename\s*=\s*"([^"]+)"))+\s*$#',
                $headerMap['content-disposition'][0] ?? '',
                $parts,
            );
            if (! $matched || ! isset($parts[1])) {
                throw new HttpErrorException(
                    HttpStatus::BAD_REQUEST,
                    'Invalid content-disposition header within multipart form',
                );
            }

            $queue = new Queue();
            $field = new StreamedField(
                $parts[1],
                new ReadableIterableStream($queue->iterate()),
                $headerMap['content-type'][0] ?? 'text/plain',
                $parts[2] ?? null,
                $pairs,
            );
            $received = $source->pushAsync($field);
            try {
                $last = $this->streamField($queue);
                $queue->complete();
            } catch (\Throwable $error) {
                $queue->error($error);
                throw $error;
            }
            $received->await();
            if ($last) {
                $this->drainEpilog();

                return;
            }
        }
    }

    private function readHeaders(): string
    {
        $headers = '';
        while (true) {
            $chunk = $this->readSome(4_096);
            if ($chunk === null) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Request body ended unexpectedly');
            }
            $headers .= $chunk;
            $end = strpos($headers, "\r\n\r\n");
            if ($end !== false) {
                $headerLength = $end + 4;
                if ($headerLength > self::MAX_HEADER_BYTES) {
                    throw new InvalidArgumentException('Multipart part header exceeds 16 KiB.');
                }
                $this->unread(substr($headers, $headerLength));

                return substr($headers, 0, $headerLength);
            }
            if (strlen($headers) > self::MAX_HEADER_BYTES) {
                throw new InvalidArgumentException('Multipart part header exceeds 16 KiB.');
            }
        }
    }

    /** @param Queue<string> $queue */
    private function streamField(Queue $queue): bool
    {
        $separator = "\r\n--" . $this->boundary;
        $candidate = '';
        $output = '';
        while (true) {
            $chunk = $this->readSome(self::SCAN_CHUNK_BYTES);
            if ($chunk === null) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Request body ended unexpectedly');
            }
            $candidate .= $chunk;
            $offset = 0;

            while ($offset < strlen($candidate)) {
                $at = strpos($candidate, $separator, $offset);
                if ($at === false) {
                    $held = self::matchingSuffixLength($candidate, $separator, $offset);
                    $ready = substr($candidate, $offset, strlen($candidate) - $offset - $held);
                    if ($ready !== '') {
                        self::appendOutput($queue, $output, $ready);
                    }
                    $candidate = $held === 0 ? '' : substr($candidate, -$held);
                    break;
                }

                if ($at > $offset) {
                    self::appendOutput($queue, $output, substr($candidate, $offset, $at - $offset));
                }

                $suffixOffset = strlen($separator);
                if (strlen($candidate) - $at < $suffixOffset + 2) {
                    $candidate = substr($candidate, $at);
                    break;
                }
                $suffix = substr($candidate, $at + $suffixOffset, 2);
                if ($suffix === "\r\n") {
                    if ($output !== '') {
                        $queue->push($output);
                    }
                    $this->unread(substr($candidate, $at + $suffixOffset + 2));

                    return false;
                }
                if ($suffix === '--') {
                    if (strlen($candidate) - $at < $suffixOffset + 4) {
                        $candidate = substr($candidate, $at);
                        break;
                    }
                    if (substr($candidate, $at + $suffixOffset + 2, 2) === "\r\n") {
                        if ($output !== '') {
                            $queue->push($output);
                        }
                        $this->unread(substr($candidate, $at + $suffixOffset + 4));

                        return true;
                    }
                }

                // A false prefix can overlap a later real one. Emit one byte
                // and rescan so no body byte is rewritten or dropped.
                self::appendOutput($queue, $output, $candidate[$at]);
                $offset = $at + 1;
            }
        }
    }

    /** @param Queue<string> $queue */
    private static function appendOutput(Queue $queue, string &$output, string $bytes): void
    {
        $output .= $bytes;
        while (strlen($output) >= self::SCAN_CHUNK_BYTES) {
            $queue->push(substr($output, 0, self::SCAN_CHUNK_BYTES));
            $output = substr($output, self::SCAN_CHUNK_BYTES);
        }
    }

    private static function matchingSuffixLength(string $bytes, string $prefix, int $offset): int
    {
        for ($length = min(strlen($bytes) - $offset, strlen($prefix) - 1); $length > 0; --$length) {
            if (substr_compare($bytes, $prefix, -$length, $length) === 0) {
                return $length;
            }
        }

        return 0;
    }

    private function readExact(int $length): string
    {
        $bytes = '';
        while (strlen($bytes) < $length) {
            $chunk = $this->readSome($length - strlen($bytes));
            if ($chunk === null) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Request body ended unexpectedly');
            }
            $bytes .= $chunk;
        }

        return $bytes;
    }

    private function readSome(int $maxLength): ?string
    {
        $emptyReads = 0;
        while ($this->bufferOffset >= strlen($this->buffer)) {
            $chunk = $this->readBodyChunk();
            if ($chunk === null) {
                return null;
            }
            if ($chunk === '' && ++$emptyReads >= 16) {
                throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Request body made no progress');
            }
            $this->countBodyBytes(strlen($chunk));
            $this->buffer = $chunk;
            $this->bufferOffset = 0;
        }

        $bytes = substr($this->buffer, $this->bufferOffset, $maxLength);
        $this->bufferOffset += strlen($bytes);
        if ($this->bufferOffset === strlen($this->buffer)) {
            $this->buffer = '';
            $this->bufferOffset = 0;
        }

        return $bytes;
    }

    private function unread(string $bytes): void
    {
        $this->buffer = $bytes . substr($this->buffer, $this->bufferOffset);
        $this->bufferOffset = 0;
    }

    private function drainEpilog(): void
    {
        $this->buffer = '';
        $this->bufferOffset = 0;
        $emptyReads = 0;
        while (($chunk = $this->readBodyChunk()) !== null) {
            if ($chunk === '') {
                if (++$emptyReads >= 16) {
                    throw new HttpErrorException(HttpStatus::BAD_REQUEST, 'Request body made no progress');
                }

                continue;
            }
            $emptyReads = 0;
            $this->countBodyBytes(strlen($chunk));
        }
    }

    private function countBodyBytes(int $bytes): void
    {
        if ($bytes > $this->bodySizeLimit - $this->bodyBytes) {
            throw new EntityTooLargeException('POST Object request body exceeds the configured limit.');
        }
        $this->bodyBytes += $bytes;
    }

    private function readBodyChunk(): ?string
    {
        try {
            return $this->body->read();
        } catch (ClientException $error) {
            if ($error->getCode() === HttpStatus::PAYLOAD_TOO_LARGE) {
                throw new EntityTooLargeException('POST Object request body exceeds the configured limit.');
            }

            throw $error;
        }
    }
}
