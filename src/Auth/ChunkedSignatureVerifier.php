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
     */
    public function createVerifiedStream(
        ReadableStream $body,
        string $signingKey,
        string $timestamp,
        string $credentialScope,
        string $seedSignature,
    ): ReadableStream {
        $queue = new Queue(bufferSize: 8);

        // Process chunks asynchronously using Amp's fiber-based concurrency.
        \Amp\async(function () use ($body, $signingKey, $timestamp, $credentialScope, $seedSignature, $queue): void {
            try {
                $previousSignature = $seedSignature;
                $buffer = '';

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
                        $result = self::tryParseChunk($buffer);
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
                            $queue->complete();

                            return;
                        }

                        // Yield the verified chunk data.
                        if ($chunkData !== '') {
                            $queue->push($chunkData);
                        }
                    }
                }

                $queue->complete();
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
    private static function tryParseChunk(string $buffer): ?array
    {
        // Find the chunk header line (terminated by \r\n).
        $headerEnd = strpos($buffer, "\r\n");
        if ($headerEnd === false) {
            return null;
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
        // Reject absurdly large chunks to prevent OOM (256 MiB max per chunk).
        if ($chunkSize < 0 || $chunkSize > 268_435_456) {
            throw new InvalidArgumentException('Chunk size exceeds maximum allowed limit.');
        }
        $chunkSignature = $matches[2];

        // Calculate the total bytes needed for this complete chunk:
        // header + \r\n + data + \r\n
        $dataStart = $headerEnd + 2; // After header's \r\n
        $totalNeeded = $dataStart + $chunkSize + 2; // +2 for trailing \r\n

        if (strlen($buffer) < $totalNeeded) {
            return null; // Need more data.
        }

        $chunkData = $chunkSize > 0 ? substr($buffer, $dataStart, $chunkSize) : '';

        // Verify the trailing \r\n after chunk data.
        $trailingCrlf = substr($buffer, $dataStart + $chunkSize, 2);
        if ($trailingCrlf !== "\r\n") {
            throw new InvalidArgumentException(
                'Invalid chunk encoding: missing CRLF after chunk data.',
            );
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
}
