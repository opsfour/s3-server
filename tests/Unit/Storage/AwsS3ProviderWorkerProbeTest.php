<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\DeferredFuture;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use OpsFour\S3Server\Parallel\AwsS3ProviderProbeTask;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderWorkerProbe;
use PHPUnit\Framework\TestCase;

final class AwsS3ProviderWorkerProbeTest extends TestCase
{
    public function test_probe_submits_serializable_profile_task_and_returns_bounded_admission(): void
    {
        $future = new DeferredFuture();
        $future->complete(AwsS3ProviderAdmission::admitted('us-east-1'));
        $channel = $this->createMock(Channel::class);
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->expects(self::once())->method('submit')
            ->with(self::callback(static fn(Task $task): bool => $task instanceof AwsS3ProviderProbeTask))
            ->willReturnCallback(static fn(Task $task): Execution => new Execution($task, $channel, $future->getFuture()));

        $result = (new AwsS3ProviderWorkerProbe($pool, 1.0))->revalidate($this->profile());

        self::assertTrue($result->providerAdmitted);
        self::assertSame('admitted', $result->reason);
    }

    public function test_probe_await_is_bounded_without_running_sdk_work_on_the_caller(): void
    {
        $future = new DeferredFuture();
        \Revolt\EventLoop::delay(0.1, static function () use ($future): void {
            $future->complete(AwsS3ProviderAdmission::admitted('us-east-1'));
        });
        $channel = $this->createMock(Channel::class);
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('isIdle')->willReturn(true);
        $pool->method('submit')
            ->willReturnCallback(static fn(Task $task): Execution => new Execution($task, $channel, $future->getFuture()));

        try {
            (new AwsS3ProviderWorkerProbe($pool, 0.001))->revalidate($this->profile());
            self::fail('An unfinished worker probe exceeded its await bound.');
        } catch (\Amp\CancelledException $error) {
            self::assertInstanceOf(\Amp\TimeoutException::class, $error->getPrevious());
        }
    }

    public function test_probe_fails_closed_without_queueing_when_dedicated_worker_is_busy(): void
    {
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('isIdle')->willReturn(false);
        $pool->expects(self::never())->method('submit');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('probe worker is busy');
        (new AwsS3ProviderWorkerProbe($pool, 1.0))->revalidate($this->profile());
    }

    public function test_shared_probe_serializes_two_concurrent_callers_before_observing_pool_idleness(): void
    {
        $firstResult = new DeferredFuture();
        $secondResult = new DeferredFuture();
        $channel = $this->createMock(Channel::class);
        $submissions = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(2))->method('isIdle')->willReturn(true);
        $pool->expects(self::exactly(2))->method('submit')
            ->willReturnCallback(static function (Task $task) use (
                $channel,
                $firstResult,
                $secondResult,
                &$submissions,
            ): Execution {
                ++$submissions;
                return new Execution(
                    $task,
                    $channel,
                    ($submissions === 1 ? $firstResult : $secondResult)->getFuture(),
                );
            });
        $probe = new AwsS3ProviderWorkerProbe($pool, 1.0);

        $first = \Amp\async(fn(): AwsS3ProviderAdmission => $probe->revalidate($this->profile()));
        \Amp\delay(0);
        $second = \Amp\async(fn(): AwsS3ProviderAdmission => $probe->revalidate($this->profile()));
        \Amp\delay(0);
        self::assertSame(1, $submissions, 'The second caller bypassed the shared probe mutex.');

        $firstResult->complete(AwsS3ProviderAdmission::admitted('us-east-1'));
        self::assertTrue($first->await()->providerAdmitted);
        \Amp\delay(0);
        self::assertSame(2, $submissions);
        $secondResult->complete(AwsS3ProviderAdmission::admitted('us-east-1'));
        self::assertTrue($second->await()->providerAdmitted);
    }

    private function profile(): AwsS3ProviderProfile
    {
        return new AwsS3ProviderProfile(
            bucket: 'private-profile-bucket',
            region: 'us-east-1',
            expectedOwnerId: '123456789012',
            dataPrefix: 'data',
            reservedPrefix: '.opsfour',
        );
    }
}
