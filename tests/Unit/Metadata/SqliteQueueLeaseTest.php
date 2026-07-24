<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\QueueLease;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class SqliteQueueLeaseTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-queue-lease-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_old_transition_job_gets_a_fresh_processing_lease(): void
    {
        $id = $this->store->enqueueTierTransitionJob(
            'bucket',
            'object',
            null,
            'hot',
            'archive',
            'GLACIER',
            'hot/object',
        );
        $this->agePendingItem('s3_tier_transition_jobs', $id);

        self::assertCount(1, $this->store->dequeueTierTransitionJobs(1));
        $this->assertFreshLease('s3_tier_transition_jobs', $id);
        self::assertSame([], $this->store->dequeueTierTransitionJobs(1));
    }

    public function test_old_restore_job_gets_a_fresh_processing_lease(): void
    {
        $id = $this->store->enqueueRestoreJob('bucket', 'object', null, 'archive', 'archive/object', 1);
        $this->agePendingItem('s3_restore_jobs', $id);

        self::assertCount(1, $this->store->dequeueRestoreJobs(1));
        $this->assertFreshLease('s3_restore_jobs', $id);
        self::assertSame([], $this->store->dequeueRestoreJobs(1));
    }

    public function test_old_notification_gets_a_fresh_processing_lease(): void
    {
        $this->store->enqueueNotification('bucket', 'object', 's3:ObjectCreated:Put', 'https://example.test', '{}');
        $id = $this->lastInsertedId('s3_notification_queue');
        $this->agePendingItem('s3_notification_queue', $id);

        self::assertCount(1, $this->store->dequeueNotifications(1));
        $this->assertFreshLease('s3_notification_queue', $id);
        self::assertSame([], $this->store->dequeueNotifications(1));
    }

    public function test_old_storage_garbage_gets_a_fresh_processing_lease(): void
    {
        $this->store->enqueueStorageGarbage('bucket', 'hot', 'hot/object');
        $id = $this->lastInsertedId('s3_storage_garbage');
        $this->agePendingItem('s3_storage_garbage', $id);

        self::assertCount(1, $this->store->dequeueStorageGarbage(1));
        $this->assertFreshLease('s3_storage_garbage', $id);
        self::assertSame([], $this->store->dequeueStorageGarbage(1));
    }

    public function test_expired_processing_leases_are_reclaimed(): void
    {
        $transitionId = $this->store->enqueueTierTransitionJob(
            'bucket',
            'object',
            null,
            'hot',
            'archive',
            'GLACIER',
            'hot/object',
        );
        $restoreId = $this->store->enqueueRestoreJob('bucket', 'object', null, 'archive', 'archive/object', 1);
        $this->store->enqueueNotification('bucket', 'object', 's3:ObjectCreated:Put', 'https://example.test', '{}');
        $notificationId = $this->lastInsertedId('s3_notification_queue');
        $this->store->enqueueStorageGarbage('bucket', 'hot', 'hot/object');
        $garbageId = $this->lastInsertedId('s3_storage_garbage');

        self::assertCount(1, $this->store->dequeueTierTransitionJobs(1));
        self::assertCount(1, $this->store->dequeueRestoreJobs(1));
        self::assertCount(1, $this->store->dequeueNotifications(1));
        self::assertCount(1, $this->store->dequeueStorageGarbage(1));

        foreach ([
            's3_tier_transition_jobs' => $transitionId,
            's3_restore_jobs' => $restoreId,
            's3_notification_queue' => $notificationId,
            's3_storage_garbage' => $garbageId,
        ] as $table => $id) {
            $this->sql("UPDATE {$table} SET next_attempt_at = 0 WHERE id = ?", [$id]);
        }

        self::assertSame($transitionId, $this->store->dequeueTierTransitionJobs(1)[0]['id']);
        self::assertSame($restoreId, $this->store->dequeueRestoreJobs(1)[0]['id']);
        self::assertSame($notificationId, $this->store->dequeueNotifications(1)[0]['id']);
        self::assertSame($garbageId, $this->store->dequeueStorageGarbage(1)[0]['id']);
    }

    public function test_only_processing_tier_and_restore_jobs_can_renew_their_lease(): void
    {
        $transitionId = $this->store->enqueueTierTransitionJob(
            'bucket',
            'object',
            null,
            'hot',
            'archive',
            'GLACIER',
            'hot/object',
        );
        $restoreId = $this->store->enqueueRestoreJob('bucket', 'object', null, 'archive', 'archive/object', 1);

        self::assertCount(1, $this->store->dequeueTierTransitionJobs(1));
        self::assertCount(1, $this->store->dequeueRestoreJobs(1));
        self::assertTrue($this->store->renewTierTransitionJobLease($transitionId, QueueLease::expiresAt()));
        self::assertTrue($this->store->renewRestoreJobLease($restoreId, QueueLease::expiresAt()));

        $this->store->updateTierTransitionJobStatus($transitionId, 'completed', incrementAttempts: false);
        $this->store->updateRestoreJobStatus($restoreId, 'completed', incrementAttempts: false);

        self::assertFalse($this->store->renewTierTransitionJobLease($transitionId, QueueLease::expiresAt()));
        self::assertFalse($this->store->renewRestoreJobLease($restoreId, QueueLease::expiresAt()));
    }

    private function agePendingItem(string $table, int $id): void
    {
        $this->sql(
            "UPDATE {$table} SET next_attempt_at = ? WHERE id = ?",
            [microtime(true) - QueueLease::DURATION_SECONDS - 60, $id],
        );
    }

    private function assertFreshLease(string $table, int $id): void
    {
        $expiresAt = (float) $this->scalar("SELECT next_attempt_at FROM {$table} WHERE id = ?", [$id]);
        self::assertGreaterThan(microtime(true) + QueueLease::DURATION_SECONDS - 5, $expiresAt);
    }

    private function lastInsertedId(string $table): int
    {
        return (int) $this->scalar("SELECT MAX(id) FROM {$table}");
    }

    /**
     * @param list<mixed> $params
     */
    private function sql(string $sql, array $params = []): void
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);
    }

    /**
     * @param list<mixed> $params
     */
    private function scalar(string $sql, array $params = []): mixed
    {
        $statement = $this->pdo()->prepare($sql);
        $statement->execute($params);

        return $statement->fetchColumn();
    }

    private function pdo(): \PDO
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('connection');
        $method->setAccessible(true);

        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);

        return $pdo;
    }
}
