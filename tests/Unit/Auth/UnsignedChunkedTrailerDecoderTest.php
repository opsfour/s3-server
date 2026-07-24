<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Auth\UnsignedChunkedTrailerDecoder;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use PHPUnit\Framework\TestCase;

final class UnsignedChunkedTrailerDecoderTest extends TestCase
{
    public function test_decodes_chunks_and_validates_declared_checksum_trailer(): void
    {
        $payload = 'unsigned streamed payload';
        $checksum = base64_encode(hash('crc32b', $payload, true));
        $trailers = [];
        $body = dechex(strlen($payload)) . "\r\n{$payload}\r\n"
            . "0\r\n"
            . "x-amz-checksum-crc32:{$checksum}\r\n\r\n";

        $stream = (new UnsignedChunkedTrailerDecoder())->createDecodedStream(
            new ReadableBuffer($body),
            ['x-amz-checksum-crc32'],
            static function (array $values) use (&$trailers): void {
                $trailers = $values;
            },
        );

        self::assertSame($payload, \Amp\ByteStream\buffer($stream));
        self::assertSame(['x-amz-checksum-crc32' => $checksum], $trailers);
    }

    public function test_rejects_wrong_unsigned_trailer_checksum(): void
    {
        $payload = 'payload';
        $body = dechex(strlen($payload)) . "\r\n{$payload}\r\n"
            . "0\r\n"
            . 'x-amz-checksum-sha256:' . base64_encode(hash('sha256', 'wrong', true)) . "\r\n\r\n";
        $stream = (new UnsignedChunkedTrailerDecoder())->createDecodedStream(
            new ReadableBuffer($body),
            ['x-amz-checksum-sha256'],
        );

        $this->expectException(BadDigestException::class);
        \Amp\ByteStream\buffer($stream);
    }

    public function test_rejects_bytes_after_trailer_block(): void
    {
        $payload = 'payload';
        $checksum = base64_encode(hash('sha1', $payload, true));
        $body = dechex(strlen($payload)) . "\r\n{$payload}\r\n"
            . "0\r\n"
            . "x-amz-checksum-sha1:{$checksum}\r\n\r\nextra";
        $stream = (new UnsignedChunkedTrailerDecoder())->createDecodedStream(
            new ReadableBuffer($body),
            ['x-amz-checksum-sha1'],
        );

        $this->expectException(InvalidArgumentException::class);
        \Amp\ByteStream\buffer($stream);
    }
}
