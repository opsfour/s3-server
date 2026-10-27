<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Storage\AwsS3ReservationReadableStream;
use PHPUnit\Framework\TestCase;

final class AwsS3ReservationReadableStreamTest extends TestCase
{
    public function test_pre_read_heartbeat_failure_closes_source_and_notifies_subscribers(): void
    {
        $source = $this->createMock(ReadableStream::class);
        $source->expects(self::never())->method('read');
        $source->expects(self::once())->method('close');
        $stream = new AwsS3ReservationReadableStream(
            $source,
            static function (): never {
                throw new \RuntimeException('renewal failed');
            },
        );
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });

        try {
            $stream->read();
            self::fail('Pre-read renewal failure must be propagated.');
        } catch (\RuntimeException $error) {
            self::assertSame('renewal failed', $error->getMessage());
            self::assertTrue($stream->isClosed());
            self::assertSame(1, $closed);
        }
    }
}
