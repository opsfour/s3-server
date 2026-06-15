<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class TierTransitionJobMetadataStoreTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-tier-transition-jobs-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_enqueue_get_dequeue_and_complete_transition_job(): void
    {
        $id = $this->store->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: 'version-1',
            sourceTier: 'hot',
            targetTier: 'archive',
            targetStorageClass: 'GLACIER',
            sourceStoragePath: '/hot/archive.bin',
            maxAttempts: 3,
        );

        self::assertGreaterThan(0, $id);

        $queued = $this->store->getTierTransitionJob($id);
        self::assertNotNull($queued);
        self::assertSame('bucket', $queued['bucket']);
        self::assertSame('archive.bin', $queued['key']);
        self::assertSame('version-1', $queued['versionId']);
        self::assertSame('hot', $queued['sourceTier']);
        self::assertSame('archive', $queued['targetTier']);
        self::assertSame('GLACIER', $queued['targetStorageClass']);
        self::assertSame('/hot/archive.bin', $queued['sourceStoragePath']);
        self::assertNull($queued['targetStoragePath']);
        self::assertSame('pending', $queued['status']);
        self::assertSame(0, $queued['attempts']);
        self::assertSame(3, $queued['maxAttempts']);

        $dequeued = $this->store->dequeueTierTransitionJobs(10);
        self::assertCount(1, $dequeued);
        self::assertSame($id, $dequeued[0]['id']);
        self::assertSame('pending', $dequeued[0]['status']);

        $processing = $this->store->getTierTransitionJob($id);
        self::assertNotNull($processing);
        self::assertSame('processing', $processing['status']);

        $this->store->updateTierTransitionJobStatus(
            id: $id,
            status: 'completed',
            incrementAttempts: false,
            targetStoragePath: '/archive/archive.bin',
        );

        $completed = $this->store->getTierTransitionJob($id);
        self::assertNotNull($completed);
        self::assertSame('completed', $completed['status']);
        self::assertSame(0, $completed['attempts']);
        self::assertSame('/archive/archive.bin', $completed['targetStoragePath']);
    }

    public function test_retry_updates_attempts_error_and_next_attempt(): void
    {
        $id = $this->store->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'retry.bin',
            versionId: null,
            sourceTier: 'hot',
            targetTier: 'archive',
            targetStorageClass: 'DEEP_ARCHIVE',
            sourceStoragePath: '/hot/retry.bin',
            maxAttempts: 2,
        );

        self::assertCount(1, $this->store->dequeueTierTransitionJobs(1));

        $nextAttemptAt = microtime(true) + 3600;
        $this->store->updateTierTransitionJobStatus(
            id: $id,
            status: 'pending',
            error: 'copy failed',
            nextAttemptAt: $nextAttemptAt,
        );

        $retry = $this->store->getTierTransitionJob($id);
        self::assertNotNull($retry);
        self::assertSame('pending', $retry['status']);
        self::assertSame(1, $retry['attempts']);
        self::assertSame('copy failed', $retry['lastError']);
        self::assertGreaterThanOrEqual($nextAttemptAt - 0.001, $retry['nextAttemptAt']);
        self::assertSame([], $this->store->dequeueTierTransitionJobs(1));
    }

    public function test_dequeue_respects_limit_and_recovers_stale_processing_jobs(): void
    {
        $firstId = $this->store->enqueueTierTransitionJob('bucket', 'first.bin', null, 'hot', 'archive', 'GLACIER', '/hot/first.bin');
        $secondId = $this->store->enqueueTierTransitionJob('bucket', 'second.bin', null, 'hot', 'archive', 'GLACIER', '/hot/second.bin');

        $firstBatch = $this->store->dequeueTierTransitionJobs(1);
        self::assertCount(1, $firstBatch);
        self::assertSame($firstId, $firstBatch[0]['id']);
        self::assertSame('processing', $this->store->getTierTransitionJob($firstId)['status']);

        $secondBatch = $this->store->dequeueTierTransitionJobs(10);
        self::assertCount(1, $secondBatch);
        self::assertSame($secondId, $secondBatch[0]['id']);

        $this->forceStaleProcessing($firstId);

        $recovered = $this->store->dequeueTierTransitionJobs(10);
        self::assertCount(1, $recovered);
        self::assertSame($firstId, $recovered[0]['id']);
    }

    public function test_restore_job_queue_lifecycle(): void
    {
        $id = $this->store->enqueueRestoreJob(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: null,
            sourceTier: 'GLACIER',
            sourceStoragePath: '/cold/archive.bin',
            restoreDays: 7,
            maxAttempts: 2,
        );

        $queued = $this->store->getRestoreJob($id);
        self::assertNotNull($queued);
        self::assertSame('archive.bin', $queued['key']);
        self::assertSame('GLACIER', $queued['sourceTier']);
        self::assertSame(7, $queued['restoreDays']);
        self::assertSame('pending', $queued['status']);

        $dequeued = $this->store->dequeueRestoreJobs(1);
        self::assertCount(1, $dequeued);
        self::assertSame($id, $dequeued[0]['id']);
        self::assertSame('processing', $this->store->getRestoreJob($id)['status']);

        $this->store->updateRestoreJobStatus($id, 'completed', incrementAttempts: false, restoredStoragePath: '/hot/archive.bin');
        $completed = $this->store->getRestoreJob($id);
        self::assertNotNull($completed);
        self::assertSame('completed', $completed['status']);
        self::assertSame('/hot/archive.bin', $completed['restoredStoragePath']);
        self::assertSame(0, $completed['attempts']);
    }

    private function forceStaleProcessing(int $id): void
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('connection');
        $method->setAccessible(true);

        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);
        $stmt = $pdo->prepare("UPDATE s3_tier_transition_jobs SET status = 'processing', next_attempt_at = ? WHERE id = ?");
        $stmt->execute([microtime(true) - 3600, $id]);
    }
}
