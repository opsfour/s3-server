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
