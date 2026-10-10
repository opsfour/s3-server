<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\NullCancellation;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class LocalObjectWriteIntentCollectorTest extends TestCase
{
    private string $root;

    private SqliteMetadataStore $metadata;

    private LocalObjectWriteFence $fence;

    private StorageTierRegistry $tiers;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s3-local-collector-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->metadata = new SqliteMetadataStore($this->root . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->fence = new LocalObjectWriteFence($this->root);
        $this->tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', new FilesystemBackend($this->root), defaultWriteTier: true),
            new StorageTier('REMOTE', new InMemoryBackend()),
        ]);
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->root);
    }

    public function test_worker_seals_only_its_local_tier(): void
    {
        $remote = $this->reserve('REMOTE', 'remote://bucket/object');
        self::assertTrue($this->metadata->abandonObjectWriteIntent($remote->attemptId));
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD');

        try {
            self::assertSame(1, $collector->collect(2));
            self::assertSame('sealed', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
            self::assertSame('active', $this->metadata->getObjectWriteIntent($remote->attemptId)?->state);
            self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($local->storagePath));
        } finally {
            $collector->shutdown();
        }
    }

    public function test_never_dispatched_reservation_is_retired_without_seal_and_counts_toward_limit(): void
    {
        $attempt = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $path = $this->fence->planPath('bucket', 'key', $generation, $attempt);
        $unarmed = $this->metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            storagePath: $path,
            purpose: 'put',
        ), 60.0);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($unarmed->attemptId));
        $armed = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($armed->attemptId));
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD');

        try {
            self::assertSame(1, $collector->collect(1));
            self::assertNull($this->metadata->getObjectWriteIntent($unarmed->attemptId));
            self::assertFileDoesNotExist($path);
            self::assertSame('active', $this->metadata->getObjectWriteIntent($armed->attemptId)?->state);
            self::assertSame(1, $collector->collect(1));
            self::assertSame('sealed', $this->metadata->getObjectWriteIntent($armed->attemptId)?->state);
        } finally {
            $collector->shutdown();
        }
    }

    public function test_invalid_local_path_keeps_claim_for_retry_without_touching_foreign_path(): void
    {
        $bad = $this->reserve('STANDARD', 'outside/path');
        self::assertTrue($this->metadata->abandonObjectWriteIntent($bad->attemptId));
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD');

        try {
            self::assertSame(0, $collector->collect(1));
            self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($bad->attemptId)?->state);
            self::assertSame(1, $this->metadata->getObjectWriteIntent($bad->attemptId)->claimGeneration);
            self::assertFileDoesNotExist($this->root . '/outside/path');
        } finally {
            $collector->shutdown();
        }
    }

    public function test_cancellation_before_claim_leaves_active_intent_untouched(): void
    {
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD');
        $cancel = new DeferredCancellation();
        $cancel->cancel();

        try {
            $this->expectException(\Amp\CancelledException::class);
            $collector->collect(1, $cancel->getCancellation());
        } finally {
            self::assertSame('active', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
            $collector->shutdown();
        }
    }

    public function test_held_seal_worker_keeps_claim_live_until_it_finishes(): void
    {
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $completion = new DeferredFuture();
        $submitted = new DeferredFuture();
        $task = null;
        $pool = $this->heldSealPool($completion, $submitted, $task);
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD', sealPool: $pool, claimLeaseSeconds: 0.6);

        $collection = async(fn(): int => $collector->collect(1));
        try {
            $submitted->getFuture()->await();
            $initial = $this->metadata->getObjectWriteIntent($local->attemptId);
            self::assertNotNull($initial);
            delay(0.9);
            $renewed = $this->metadata->getObjectWriteIntent($local->attemptId);
            self::assertNotNull($renewed);
            self::assertGreaterThan($initial->leaseUntil, $renewed->leaseUntil);
            self::assertSame([], $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 0.6));
            self::assertInstanceOf(\OpsFour\S3Server\Parallel\LocalObjectSealTask::class, $task);
            $task->run($this->createMock(Channel::class), new NullCancellation());
            $completion->complete();
            self::assertSame(1, $collection->await());
            self::assertSame('sealed', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
        } finally {
            if (! $completion->isComplete()) {
                $completion->complete();
            }
            $collector->shutdown();
        }
    }

    public function test_cancellation_waits_for_dispatched_seal_and_does_not_confirm_sql(): void
    {
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $completion = new DeferredFuture();
        $submitted = new DeferredFuture();
        $task = null;
        $pool = $this->heldSealPool($completion, $submitted, $task);
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD', sealPool: $pool, claimLeaseSeconds: 0.6);
        $cancel = new DeferredCancellation();

        $collection = async(fn(): int => $collector->collect(1, $cancel->getCancellation()));
        try {
            $submitted->getFuture()->await();
            $cancel->cancel();
            delay(0.8);
            self::assertFalse($collection->isComplete(), 'The worker must remain observed after caller cancellation.');
            self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
            self::assertSame([], $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 0.6));
            self::assertInstanceOf(\OpsFour\S3Server\Parallel\LocalObjectSealTask::class, $task);
            $task->run($this->createMock(Channel::class), new NullCancellation());
            $completion->complete();
            try {
                $collection->await();
                self::fail('Cancelled collection cannot commit a sealed SQL row.');
            } catch (\Amp\CancelledException) {
                self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
                self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($local->storagePath));
            }
        } finally {
            if (! $completion->isComplete()) {
                $completion->complete();
            }
            $collector->shutdown();
        }
    }

    public function test_stolen_claim_during_held_worker_cannot_confirm_sql(): void
    {
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $completion = new DeferredFuture();
        $submitted = new DeferredFuture();
        $task = null;
        $pool = $this->heldSealPool($completion, $submitted, $task);
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD', sealPool: $pool, claimLeaseSeconds: 0.6);

        $collection = async(fn(): int => $collector->collect(1));
        try {
            $submitted->getFuture()->await();
            $pdo = new \PDO('sqlite:' . $this->root . '/metadata.sqlite');
            $stmt = $pdo->prepare('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?');
            self::assertInstanceOf(\PDOStatement::class, $stmt);
            $stmt->execute([$local->attemptId]);
            $newClaim = $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 0.6);
            self::assertCount(1, $newClaim);
            self::assertSame(2, $newClaim[0]->claimGeneration);
            delay(0.25);
            self::assertInstanceOf(\OpsFour\S3Server\Parallel\LocalObjectSealTask::class, $task);
            $task->run($this->createMock(Channel::class), new NullCancellation());
            $completion->complete();
            self::assertSame(0, $collection->await());
            $persisted = $this->metadata->getObjectWriteIntent($local->attemptId);
            self::assertNotNull($persisted);
            self::assertSame('reconciling', $persisted->state);
            self::assertSame(2, $persisted->claimGeneration);
        } finally {
            if (! $completion->isComplete()) {
                $completion->complete();
            }
            $collector->shutdown();
        }
    }

    public function test_full_pool_queue_keeps_claim_live_before_worker_dispatch(): void
    {
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $completion = new DeferredFuture();
        $submitted = new DeferredFuture();
        $task = null;
        $pool = $this->queuedSealPool($entered, $release, $completion, $submitted, $task);
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD', sealPool: $pool, claimLeaseSeconds: 0.6);

        $collection = async(fn(): int => $collector->collect(1));
        try {
            $entered->getFuture()->await();
            $initial = $this->metadata->getObjectWriteIntent($local->attemptId);
            self::assertNotNull($initial);
            delay(0.9);
            $renewed = $this->metadata->getObjectWriteIntent($local->attemptId);
            self::assertNotNull($renewed);
            self::assertGreaterThan($initial->leaseUntil, $renewed->leaseUntil);
            self::assertSame([], $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 0.6));
            $release->complete();
            $submitted->getFuture()->await();
            self::assertInstanceOf(\OpsFour\S3Server\Parallel\LocalObjectSealTask::class, $task);
            $task->run($this->createMock(Channel::class), new NullCancellation());
            $completion->complete();
            self::assertSame(1, $collection->await());
            self::assertSame('sealed', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
        } finally {
            if (! $release->isComplete()) {
                $release->complete();
            }
            if (! $completion->isComplete()) {
                $completion->complete();
            }
            $collector->shutdown();
        }
    }

    public function test_cancellation_while_pool_is_full_still_observes_later_dispatched_worker(): void
    {
        $local = $this->reserve('STANDARD', null);
        self::assertTrue($this->metadata->abandonObjectWriteIntent($local->attemptId));
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $completion = new DeferredFuture();
        $submitted = new DeferredFuture();
        $task = null;
        $pool = $this->queuedSealPool($entered, $release, $completion, $submitted, $task);
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $this->tiers, 'STANDARD', sealPool: $pool, claimLeaseSeconds: 0.6);
        $cancel = new DeferredCancellation();

        $collection = async(fn(): int => $collector->collect(1, $cancel->getCancellation()));
        try {
            $entered->getFuture()->await();
            $cancel->cancel();
            delay(0.8);
            self::assertFalse($collection->isComplete());
            self::assertSame([], $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 0.6));
            $release->complete();
            $submitted->getFuture()->await();
            self::assertFalse($collection->isComplete());
            self::assertInstanceOf(\OpsFour\S3Server\Parallel\LocalObjectSealTask::class, $task);
            $task->run($this->createMock(Channel::class), new NullCancellation());
            $completion->complete();
            try {
                $collection->await();
                self::fail('Cancelled queued collection cannot commit a sealed SQL row.');
            } catch (\Amp\CancelledException) {
                self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($local->attemptId)?->state);
            }
        } finally {
            if (! $release->isComplete()) {
                $release->complete();
            }
            if (! $completion->isComplete()) {
                $completion->complete();
            }
            $collector->shutdown();
        }
    }

    /**
     * @param DeferredFuture<null> $entered
     * @param DeferredFuture<null> $release
     * @param DeferredFuture<null> $completion
     * @param DeferredFuture<null> $submitted
     * @param \OpsFour\S3Server\Parallel\LocalObjectSealTask|null $task
     */
    private function queuedSealPool(
        DeferredFuture $entered,
        DeferredFuture $release,
        DeferredFuture $completion,
        DeferredFuture $submitted,
        mixed &$task,
    ): WorkerPool {
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturnCallback(function ($sealTask, $cancellation = null) use ($entered, $release, $completion, $submitted, &$task): Execution {
            self::assertNull($cancellation, 'Queued submission must not receive caller cancellation.');
            $entered->complete();
            $release->getFuture()->await();
            $task = $sealTask;
            $submitted->complete();

            return new Execution($sealTask, $this->createMock(Channel::class), $completion->getFuture());
        });

        return $pool;
    }

    /**
     * @param DeferredFuture<null> $completion
     * @param DeferredFuture<null> $submitted
     * @param \OpsFour\S3Server\Parallel\LocalObjectSealTask|null $task
     */
    private function heldSealPool(DeferredFuture $completion, DeferredFuture $submitted, mixed &$task): WorkerPool
    {
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('submit')->willReturnCallback(function ($sealTask, $cancellation = null) use ($completion, $submitted, &$task): Execution {
            self::assertNull($cancellation, 'Caller cancellation must not be passed into an already dispatched seal.');
            $task = $sealTask;
            $submitted->complete();

            return new Execution($sealTask, $this->createMock(Channel::class), $completion->getFuture());
        });

        return $pool;
    }

    private function reserve(string $tier, ?string $path): ObjectWriteIntent
    {
        $attempt = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $reserved = $this->metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: $tier,
            storagePath: $path ?? $this->fence->planPath('bucket', 'key', $generation, $attempt),
            purpose: 'put',
        ), 60.0);
        return $this->metadata->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, bin2hex(random_bytes(16)), 60.0)
            ?? throw new \RuntimeException('Fixture dispatch was not armed.');
    }
}
