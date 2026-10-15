<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Multipart;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Storage\LimitedReadableStream;

final class MultipartPartSize
{
    public const int MAX_BYTES = 5_368_709_120;

    public const int MAX_OBJECT_BYTES = 53_687_091_200_000;

    public static function declaredLength(?string $value): ?int
    {
        if ($value === null) {
            return null;
        }
        if ($value === '' || ! ctype_digit($value)) {
            throw new InvalidArgumentException('Part length must be a non-negative decimal integer.');
        }

        $digits = ltrim($value, '0');
        $max = (string) self::MAX_BYTES;
        if (strlen($digits) > strlen($max)
            || (strlen($digits) === strlen($max) && strcmp($digits, $max) > 0)) {
            throw new EntityTooLargeException('Multipart part exceeds the 5 GiB maximum.');
        }

        return (int) $value;
    }

    public static function boundedBody(ReadableStream $body, int $maxBytes = self::MAX_BYTES): LimitedReadableStream
    {
        if ($maxBytes < 0 || $maxBytes >= PHP_INT_MAX) {
            throw new \InvalidArgumentException('Maximum multipart part size is invalid.');
        }

        return new LimitedReadableStream($body, $maxBytes + 1);
    }

    public static function assertStoredSize(int $size): void
    {
        if ($size < 0) {
            throw new InternalErrorException('Multipart storage backend returned a negative part size.');
        }
        if ($size > self::MAX_BYTES) {
            throw new EntityTooLargeException('Multipart part exceeds the 5 GiB maximum.');
        }
    }

    /**
     * @param iterable<int> $sizes Selected persisted part sizes.
     */
    public static function sumObjectBytes(iterable $sizes): int
    {
        $total = 0;
        foreach ($sizes as $size) {
            if ($size < 0 || $size > self::MAX_BYTES) {
                throw new InternalErrorException('Multipart part size is invalid in metadata.');
            }
            if ($size > self::MAX_OBJECT_BYTES - $total) {
                throw new EntityTooLargeException('Multipart object exceeds the 50,000 GiB maximum.');
            }

            $total += $size;
        }

        return $total;
    }
}
