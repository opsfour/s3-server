<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use OpsFour\S3Server\Auth\EncryptedTempReadableStream;
use PHPUnit\Framework\TestCase;

final class EncryptedTempReadableStreamTest extends TestCase
{
    public function test_close_failure_does_not_replace_source_cancellation_and_callbacks_still_run(): void
    {
        $cancellation = new CancelledException();
        $source = $this->createMock(ReadableStream::class);
        $source->method('read')->willThrowException($cancellation);
        $source->method('close')->willThrowException(new \RuntimeException('close failed'));
        $stream = new EncryptedTempReadableStream($source, str_repeat('k', 32), 1);
        $closed = false;
        $stream->onClose(static function () use (&$closed): void {
            $closed = true;
        });

        try {
            $stream->read();
            self::fail('The source cancellation must propagate.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertTrue($closed);
        }
    }
}
