<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\DeferredFuture;
use Amp\DeferredCancellation;
use Amp\CancelledException;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Storage\LocalEfsChunkReadableStream;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Sync\createChannelPair;

final class LocalEfsChunkReadableStreamTest extends TestCase
{
    public function test_prime_chunk_credit_and_worker_closed_eof(): void
    {
        [$parent, $worker] = createChannelPair();
        $done = new DeferredFuture();
        $producer = async(static function () use ($worker, $done): void {
            $worker->send(['chunk' => 'first']);
            self::assertSame('next', $worker->receive());
            $worker->send(['chunk' => 'second']);
            self::assertSame('next', $worker->receive());
            $worker->send(['eof' => true]);
            $done->complete();
        });
        $stream = new LocalEfsChunkReadableStream(
            new Execution($this->createMock(Task::class), $parent, $done->getFuture()),
            new StreamReadEvidence(),
        );

        $stream->primeRead();
        self::assertSame('first', $stream->read());
        self::assertSame('second', $stream->read());
        self::assertNull($stream->read());
        self::assertTrue($stream->isClosed());
        $producer->await();
    }

    public function test_concurrent_close_discards_chunk_that_arrives_later(): void
    {
        [$parent, $worker] = createChannelPair();
        $done = new DeferredFuture();
        $producer = async(static function () use ($worker, $done): void {
            $worker->send(['chunk' => 'first']);
            self::assertSame('next', $worker->receive());
            $worker->send(['chunk' => 'late']);
            self::assertSame('cancel', $worker->receive());
            $done->complete();
        });
        $stream = new LocalEfsChunkReadableStream(
            new Execution($this->createMock(Task::class), $parent, $done->getFuture()),
            new StreamReadEvidence(),
        );
        $stream->primeRead();
        self::assertSame('first', $stream->read());
        $waiting = async(static function () use ($stream): void {
            $stream->read();
        });
        \Amp\delay(0.001);
        $stream->close();
        try {
            $waiting->await();
            self::fail('A chunk escaped after the stream closed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('closed', $error->getMessage());
        }
        $producer->await();
    }

    public function test_close_after_prime_sends_cancel_without_waiting_for_worker_result(): void
    {
        [$parent, $worker] = createChannelPair();
        $done = new DeferredFuture();
        $producer = async(static function () use ($worker, $done): void {
            $worker->send(['chunk' => 'first']);
            self::assertSame('cancel', $worker->receive());
            $done->complete();
        });
        $stream = new LocalEfsChunkReadableStream(
            new Execution($this->createMock(Task::class), $parent, $done->getFuture()),
            new StreamReadEvidence(),
        );
        $stream->primeRead();
        $stream->close();
        self::assertTrue($stream->isClosed());
        $producer->await();
    }

    public function test_client_cancellation_after_prime_closes_without_api_file_handle(): void
    {
        [$parent, $worker] = createChannelPair();
        $done = new DeferredFuture();
        $producer = async(static function () use ($worker, $done): void {
            $worker->send(['chunk' => 'first']);
            self::assertSame('cancel', $worker->receive());
            $done->complete();
        });
        $stream = new LocalEfsChunkReadableStream(
            new Execution($this->createMock(Task::class), $parent, $done->getFuture()),
            new StreamReadEvidence(),
        );
        $stream->primeRead();
        $cancellation = new DeferredCancellation();
        $cancellation->cancel();

        try {
            $stream->read($cancellation->getCancellation());
            self::fail('A cancelled client received a primed chunk.');
        } catch (CancelledException) {
            self::assertTrue($stream->isClosed());
        }
        $producer->await();
    }
}

final class StreamReadEvidence implements EfsHostEvidence
{
    public function assertFresh(): void {}
}
