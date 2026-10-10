<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class BufferedSsePayloadTest extends TestCase
{
    public function test_transient_empty_read_does_not_end_payload(): void
    {
        [$body, $checksums] = BufferedSsePayload::read(new ReadableIterableStream(['', 'payload']), 7);

        self::assertSame('payload', $body);
        self::assertSame(7, $checksums->size);
        self::assertSame(md5('payload'), $checksums->md5Hex);
    }

    public function test_permanently_empty_stream_hits_progress_deadline(): void
    {
        $stream = new ReadableIterableStream((static function (): \Generator {
            while (true) {
                yield '';
            }
        })());
        $time = 0.0;

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Request body made no progress');
        BufferedSsePayload::read($stream, 1, static function () use (&$time): float {
            $time += 16;

            return $time;
        });
    }

    public function test_runtime_memory_limit_rejects_payload_before_encryption(): void
    {
        $payload = str_repeat('x', 2_097_152);
        $previousLimit = ini_get('memory_limit');
        $limit = memory_get_usage(true) + 20_000_000;
        ini_set('memory_limit', (string) $limit);

        try {
            $this->expectException(EntityTooLargeException::class);
            $this->expectExceptionMessage('available memory');
            BufferedSsePayload::read(new \Amp\ByteStream\ReadableBuffer($payload), strlen($payload));
        } finally {
            ini_set('memory_limit', (string) $previousLimit);
        }
    }

    #[DataProvider('mismatchedCiphertexts')]
    public function test_expected_ciphertext_size_rejects_corrupt_source_and_closes_stream(string $chunk): void
    {
        $stream = $this->createMock(ReadableStream::class);
        $stream->method('read')->willReturnOnConsecutiveCalls($chunk, null);
        $stream->expects(self::once())->method('close');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Encrypted source size does not match metadata.');

        BufferedSsePayload::readExpected($stream, 3, 8);
    }

    /** @return iterable<string, array{string}> */
    public static function mismatchedCiphertexts(): iterable
    {
        yield 'larger than expected' => ['abcd'];
        yield 'shorter than expected' => ['ab'];
    }
}
