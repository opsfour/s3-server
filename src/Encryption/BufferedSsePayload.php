<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Checksum\ChecksumResult;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;

/**
 * Reads an SSE payload only into bounded process memory before encryption.
 * No plaintext storage write is made by this helper.
 */
final class BufferedSsePayload
{
    /**
     * @param (callable(): float)|null $now Monotonic clock override for deterministic progress tests.
     * @return array{string, ChecksumResult}
     */
    public static function read(ReadableStream $stream, int $maxBytes, ?callable $now = null, ?int $expectedBytes = null): array
    {
        $now ??= static fn(): float => hrtime(true) / 1_000_000_000;
        $plaintext = '';
        $checksums = new ChecksumCalculator();
        $emptySince = null;

        while (($chunk = $stream->read()) !== null) {
            if ($chunk === '') {
                $emptySince ??= $now();
                if ($now() - $emptySince >= 30) {
                    throw new InternalErrorException('Request body made no progress for 30 seconds.');
                }

                \Amp\delay(0.001);
                continue;
            }

            $emptySince = null;
            if ($expectedBytes !== null && strlen($chunk) > $expectedBytes - $checksums->currentSize()) {
                throw new InternalErrorException('Encrypted source size does not match metadata.');
            }
            if (strlen($chunk) > $maxBytes - $checksums->currentSize()) {
                throw new EntityTooLargeException(
                    'Object exceeds max size for server-side encryption (' . $maxBytes . ' bytes).',
                );
            }
            self::assertMemoryHeadroom($checksums->currentSize() + strlen($chunk));
            $checksums->update($chunk);
            $plaintext .= $chunk;
        }

        if ($expectedBytes !== null && $checksums->currentSize() !== $expectedBytes) {
            throw new InternalErrorException('Encrypted source size does not match metadata.');
        }

        return [$plaintext, $checksums->finalize()];
    }

    /** @return array{string, ChecksumResult} */
    public static function readExpected(ReadableStream $stream, int $expectedBytes, int $maxBytes): array
    {
        try {
            return self::read($stream, $maxBytes, expectedBytes: $expectedBytes);
        } finally {
            $stream->close();
        }
    }

    public static function assertMemoryHeadroom(int $bytes): void
    {
        $setting = trim((string) ini_get('memory_limit'));
        if ($setting === '' || $setting === '-1') {
            return;
        }

        $unit = strtolower(substr($setting, -1));
        $limit = (int) $setting;
        $limit *= match ($unit) {
            'g' => 1_073_741_824,
            'm' => 1_048_576,
            'k' => 1_024,
            default => 1,
        };

        // The request/source buffer, decryption, OpenSSL output and destination
        // stream can coexist. Reserve room before growing the plaintext buffer.
        if ($limit > 0 && memory_get_usage(true) + (4 * $bytes) + 16_777_216 > $limit) {
            throw new EntityTooLargeException('Object exceeds available memory for server-side encryption.');
        }
    }
}
