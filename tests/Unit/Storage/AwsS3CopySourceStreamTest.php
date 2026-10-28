<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use GuzzleHttp\Psr7\Utils;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Storage\AwsS3CopySourceStream;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\StreamInterface;

final class AwsS3CopySourceStreamTest extends TestCase
{
    public function test_zero_byte_provider_body_closes_at_eof(): void
    {
        $stream = new AwsS3CopySourceStream(Utils::streamFor(''));

        self::assertNull($stream->read());
        self::assertTrue($stream->isClosed());
    }

    public function test_exact_64_kib_multiple_closes_after_the_terminal_empty_read(): void
    {
        $payload = str_repeat('x', 65_536);
        $stream = new AwsS3CopySourceStream(Utils::streamFor($payload));

        self::assertSame($payload, $stream->read());
        self::assertNull($stream->read());
        self::assertTrue($stream->isClosed());
    }

    public function test_empty_non_eof_provider_read_remains_an_error(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->method('eof')->willReturn(false);
        $body->expects(self::once())->method('read')->willReturn('');
        $body->expects(self::once())->method('close');
        $stream = new AwsS3CopySourceStream($body);

        $this->expectException(InternalErrorException::class);
        $stream->read();
    }

    public function test_already_requested_cancellation_closes_the_provider_body_and_notifies_subscribers(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects(self::once())->method('close');
        $stream = new AwsS3CopySourceStream($body);
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();

        try {
            $stream->read($cancellation->getCancellation());
            self::fail('Already requested cancellation must be propagated.');
        } catch (CancelledException) {
            self::assertTrue($stream->isClosed());
            self::assertSame(1, $closed);
        }
    }

    public function test_close_runs_every_subscriber_when_the_provider_body_close_throws(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects(self::once())->method('close')->willThrowException(new \RuntimeException('provider close failed'));
        $stream = new AwsS3CopySourceStream($body);
        $closed = [];
        $stream->onClose(static function () use (&$closed): void {
            $closed[] = 'first';
            throw new \RuntimeException('subscriber failed');
        });
        $stream->onClose(static function () use (&$closed): void {
            $closed[] = 'second';
        });

        try {
            $stream->close();
            self::fail('Provider close failure must be preserved.');
        } catch (\RuntimeException $error) {
            self::assertSame('provider close failed', $error->getMessage());
            self::assertSame(['first', 'second'], $closed);
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_cancellation_requested_during_provider_read_closes_before_returning_chunk(): void
    {
        $cancellation = new DeferredCancellation();
        $body = $this->createMock(StreamInterface::class);
        $body->method('eof')->willReturn(false);
        $body->expects(self::once())->method('read')->willReturnCallback(
            static function () use ($cancellation): string {
                $cancellation->cancel();
                return 'provider bytes';
            },
        );
        $body->expects(self::once())->method('close');
        $stream = new AwsS3CopySourceStream($body);
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });

        try {
            $stream->read($cancellation->getCancellation());
            self::fail('Cancellation requested during provider read must be propagated.');
        } catch (CancelledException) {
            self::assertTrue($stream->isClosed());
            self::assertSame(1, $closed);
        }
    }

    public function test_readiness_requires_the_provider_body_to_remain_readable(): void
    {
        $body = $this->createMock(StreamInterface::class);
        $body->expects(self::once())->method('isReadable')->willReturn(false);
        $stream = new AwsS3CopySourceStream($body);

        self::assertFalse($stream->isReadable());
        self::assertFalse($stream->isClosed());
    }
}
