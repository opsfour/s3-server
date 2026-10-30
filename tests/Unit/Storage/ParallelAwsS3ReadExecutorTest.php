<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\Cancellation;
use Amp\DeferredFuture;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use Amp\Sync\ChannelException;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Parallel\AwsS3PayloadReadTask;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ReadAdmission;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\ParallelAwsS3ReadExecutor;
use PHPUnit\Framework\TestCase;

use function Amp\delay;

final class ParallelAwsS3ReadExecutorTest extends TestCase
{
    public function test_first_byte_timeout_retains_admission_until_execution_ends_then_recovers_cleanly(): void
    {
        $blockedCompletion = new DeferredFuture();
        $blockedChannel = $this->createMock(Channel::class);
        $blockedChannel->expects(self::once())->method('receive')->willReturnCallback(
            static function (?Cancellation $cancellation = null): ?string {
                delay(1, true, $cancellation);

                return null;
            },
        );
        $blockedChannel->expects(self::once())->method('send')->with('cancel');
        $blockedChannel->expects(self::once())->method('close');

        $recoveryCompletion = new DeferredFuture();
        $recoveryChannel = $this->createMock(Channel::class);
        $receiveCount = 0;
        $recoveryChannel->expects(self::exactly(2))->method('receive')->willReturnCallback(
            static function () use (&$receiveCount, $recoveryCompletion): ?string {
                if ($receiveCount++ === 0) {
                    return 'recovered';
                }
                $recoveryCompletion->complete(null);

                return null;
            },
        );
        $recoveryChannel->expects(self::once())->method('send')->with('next');
        $recoveryChannel->expects(self::once())->method('close');

        $pool = $this->createMock(WorkerPool::class);
        $submission = 0;
        $pool->expects(self::exactly(2))->method('submit')->willReturnCallback(
            static function (Task $task) use (
                &$submission,
                $blockedChannel,
                $blockedCompletion,
                $recoveryChannel,
                $recoveryCompletion,
            ): Execution {
                return $submission++ === 0
                    ? new Execution($task, $blockedChannel, $blockedCompletion->getFuture())
                    : new Execution($task, $recoveryChannel, $recoveryCompletion->getFuture());
            },
        );
        $executor = new ParallelAwsS3ReadExecutor($pool, 1, 0, 1, 0.01);

        try {
            $this->openCommitted($executor);
            self::fail('The stalled first byte did not time out.');
        } catch (SlowDownException) {
        }
        self::assertSame(1, $this->active($executor));

        try {
            $this->openCommitted($executor);
            self::fail('A timed-out but still-running execution released its admission slot.');
        } catch (SlowDownException) {
        }
        self::assertSame(1, $this->active($executor));

        $blockedCompletion->complete(null);
        delay(0);
        self::assertSame(0, $this->active($executor));

        $stream = $this->openCommitted($executor);
        self::assertSame(1, $this->active($executor));
        self::assertSame('recovered', $stream->read());

        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });
        self::assertNull($stream->read());
        self::assertTrue($stream->isClosed());
        self::assertSame(0, $this->active($executor));
        self::assertSame(1, $closed);

        $stream->close();
        self::assertSame(0, $this->active($executor));
        self::assertSame(1, $closed);
    }

    public function test_midstream_worker_failure_closes_once_releases_admission_and_recovers(): void
    {
        $failedCompletion = new DeferredFuture();
        $failedChannel = $this->createMock(Channel::class);
        $receiveCount = 0;
        $failedChannel->expects(self::exactly(2))->method('receive')->willReturnCallback(
            static function () use (&$receiveCount, $failedCompletion): string {
                if ($receiveCount++ === 0) {
                    return 'first-chunk';
                }
                $failedCompletion->error(new \RuntimeException('worker failed'));
                throw new ChannelException('channel closed');
            },
        );
        $sent = [];
        $failedChannel->expects(self::exactly(2))->method('send')->willReturnCallback(
            static function (mixed $message) use (&$sent): void {
                $sent[] = $message;
            },
        );
        $failedChannel->expects(self::once())->method('close');

        $pool = $this->createMock(WorkerPool::class);
        $submission = 0;
        $pool->expects(self::exactly(2))->method('submit')->willReturnCallback(
            function (Task $task) use (&$submission, $failedChannel, $failedCompletion): Execution {
                self::assertInstanceOf(AwsS3PayloadReadTask::class, $task);

                return $submission++ === 0
                    ? new Execution($task, $failedChannel, $failedCompletion->getFuture())
                    : $this->successfulExecution($task, 'recovered');
            },
        );
        $executor = new ParallelAwsS3ReadExecutor($pool, 1, 0, 1, 1);
        $stream = $this->openCommitted($executor);
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            ++$closed;
        });

        self::assertSame('first-chunk', $stream->read());
        self::assertSame(1, $this->active($executor));
        $this->expectException(InternalErrorException::class);
        try {
            $stream->read();
        } finally {
            self::assertSame(['next', 'cancel'], $sent);
            self::assertSame(1, $closed);
            self::assertSame(0, $this->active($executor));
            $stream->close();
            self::assertSame(1, $closed);

            $recovered = $this->openCommitted($executor);
            self::assertSame('recovered', $recovered->read());
            self::assertNull($recovered->read());
            self::assertSame(0, $this->active($executor));
        }
    }

    public function test_worker_submission_failure_releases_admission_for_recovery(): void
    {
        $pool = $this->createMock(WorkerPool::class);
        $submission = 0;
        $pool->expects(self::exactly(2))->method('submit')->willReturnCallback(
            function (Task $task) use (&$submission): Execution {
                self::assertInstanceOf(AwsS3PayloadReadTask::class, $task);
                if ($submission++ === 0) {
                    throw new \RuntimeException('submit failed');
                }

                return $this->successfulExecution($task, 'recovered');
            },
        );
        $executor = new ParallelAwsS3ReadExecutor($pool, 1, 0, 1, 1);

        $failure = null;
        try {
            $this->openCommitted($executor);
        } catch (InternalErrorException $error) {
            $failure = $error;
        }
        self::assertNotNull($failure, 'Worker submission failure did not surface.');
        self::assertSame(0, $this->active($executor));

        $stream = $this->openCommitted($executor);
        self::assertSame('recovered', $stream->read());
        self::assertNull($stream->read());
        self::assertSame(0, $this->active($executor));
    }

    public function test_multipart_executor_maps_identity_and_size_window_and_streams_the_part(): void
    {
        $profile = new AwsS3ProviderProfile(
            'backing-private',
            'us-east-1',
            '123456789012',
            'objects',
            '.opsfour/write-attempts',
        );
        $generation = str_repeat('a', 32);
        $bucket = new BucketInfo('bucket', 'owner', generation: $generation);
        $logicalScope = '@multipart-part/scope';
        $path = AwsS3WriteAttemptPath::forProfileObject(
            $profile,
            'bucket',
            $logicalScope,
            $generation,
            'STANDARD',
            str_repeat('b', 32),
        );
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturnCallback(
            function (Task $task) use ($profile, $generation, $logicalScope, $path): Execution {
                self::assertInstanceOf(AwsS3PayloadReadTask::class, $task);
                self::assertSame($profile, $this->taskField($task, 'profile'));
                self::assertSame(AwsS3PayloadReadTask::MULTIPART_PART, $this->taskField($task, 'mode'));
                self::assertSame('bucket', $this->taskField($task, 'bucket'));
                self::assertSame($generation, $this->taskField($task, 'bucketGeneration'));
                self::assertSame($logicalScope, $this->taskField($task, 'logicalScope'));
                self::assertSame($path, $this->taskField($task, 'path'));
                self::assertSame('STANDARD', $this->taskField($task, 'storageTier'));
                self::assertSame('owner', $this->taskField($task, 'expectedOwnerId'));
                self::assertSame(4, $this->taskField($task, 'expectedSize'));
                self::assertSame(16, $this->taskField($task, 'maximumSize'));
                self::assertNull($this->taskField($task, 'offset'));
                self::assertNull($this->taskField($task, 'length'));

                return $this->successfulExecution($task, 'part-body');
            },
        );
        $executor = new ParallelAwsS3ReadExecutor($pool, 1, 0, 1, 1);

        $stream = $executor->readMultipartPart(
            $profile,
            $bucket,
            $logicalScope,
            $path,
            'STANDARD',
            'owner',
            4,
            16,
        );
        self::assertSame('part-body', $stream->read());
        self::assertNull($stream->read());
        self::assertSame(0, $this->active($executor));
    }

    private function openCommitted(ParallelAwsS3ReadExecutor $executor): \Amp\ByteStream\ReadableStream
    {
        $profile = new AwsS3ProviderProfile(
            'backing-private',
            'us-east-1',
            '123456789012',
            'objects',
            '.opsfour/write-attempts',
        );
        $generation = str_repeat('a', 32);
        $attempt = str_repeat('b', 32);

        return $executor->readCommittedObject(
            $profile,
            new BucketInfo('bucket', 'owner', generation: $generation),
            new ObjectInfo('bucket', 'key', 9, '"etag"', ownerId: 'owner'),
            'key',
            AwsS3WriteAttemptPath::forProfileObject(
                $profile,
                'bucket',
                'key',
                $generation,
                'STANDARD',
                $attempt,
            ),
            'STANDARD',
            null,
            null,
        );
    }

    private function active(ParallelAwsS3ReadExecutor $executor): int
    {
        $admission = (new \ReflectionProperty($executor, 'admission'))->getValue($executor);
        self::assertInstanceOf(AwsS3ReadAdmission::class, $admission);

        return $admission->active();
    }

    /** @return Execution<null, string, string|null> */
    private function successfulExecution(AwsS3PayloadReadTask $task, string $chunk): Execution
    {
        $completion = new DeferredFuture();
        $channel = $this->createMock(Channel::class);
        $receiveCount = 0;
        $channel->expects(self::exactly(2))->method('receive')->willReturnCallback(
            static function () use (&$receiveCount, $completion, $chunk): ?string {
                if ($receiveCount++ === 0) {
                    return $chunk;
                }
                $completion->complete(null);

                return null;
            },
        );
        $channel->expects(self::once())->method('send')->with('next');
        $channel->expects(self::once())->method('close');

        return new Execution($task, $channel, $completion->getFuture());
    }

    private function taskField(AwsS3PayloadReadTask $task, string $field): mixed
    {
        return (new \ReflectionProperty($task, $field))->getValue($task);
    }
}
