<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Multipart;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartPartSizeTest extends TestCase
{
    public function test_bounded_body_stops_after_one_detection_byte(): void
    {
        $body = MultipartPartSize::boundedBody(new ReadableBuffer('abcdef'), 3);

        self::assertSame('abcd', \Amp\ByteStream\buffer($body));
        self::assertTrue($body->isClosed());
    }

    public function test_object_size_sum_accepts_exactly_five_tibibytes(): void
    {
        self::assertSame(
            MultipartPartSize::MAX_OBJECT_BYTES,
            MultipartPartSize::sumObjectBytes(array_fill(0, 1024, MultipartPartSize::MAX_BYTES)),
        );
    }

    public function test_object_size_sum_rejects_one_more_maximum_part_without_overflow(): void
    {
        $this->expectException(EntityTooLargeException::class);
        $this->expectExceptionMessage('Multipart object exceeds the 5 TiB maximum.');

        MultipartPartSize::sumObjectBytes(array_fill(0, 1025, MultipartPartSize::MAX_BYTES));
    }

    #[DataProvider('invalidStoredSizes')]
    public function test_object_size_sum_rejects_invalid_persisted_part_size(int $size): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Multipart part size is invalid in metadata.');

        MultipartPartSize::sumObjectBytes([$size]);
    }

    /** @return iterable<string, array{int}> */
    public static function invalidStoredSizes(): iterable
    {
        yield 'negative' => [-1];
        yield 'above per-part maximum' => [MultipartPartSize::MAX_BYTES + 1];
    }
}
