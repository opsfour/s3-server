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

    public function test_tier_and_restore_renewals_fence_submillisecond_old_leases(): void
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
        $this->agePendingItem('s3_tier_transition_jobs', $transitionId);
        $this->agePendingItem('s3_restore_jobs', $restoreId);

        $transition = $this->store->dequeueTierTransitionJobs(1)[0];
        $restore = $this->store->dequeueRestoreJobs(1)[0];
        $first = microtime(true) + 60;
        $second = $first + 0.000001;
        self::assertNotSame($first, $second);

        self::assertTrue($this->store->renewTierTransitionJobLease($transitionId, $first, $transition['nextAttemptAt']));
        self::assertTrue($this->store->renewTierTransitionJobLease($transitionId, $second, $first));
        self::assertFalse($this->store->renewTierTransitionJobLease($transitionId, $second + 60, $first));

        self::assertTrue($this->store->renewRestoreJobLease($restoreId, $first, $restore['nextAttemptAt']));
        self::assertTrue($this->store->renewRestoreJobLease($restoreId, $second, $first));
        self::assertFalse($this->store->renewRestoreJobLease($restoreId, $second + 60, $first));
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

    public function test_expired_notification_worker_cannot_overwrite_reclaimed_lease(): void
    {
        $this->store->enqueueNotification('bucket', 'object', 's3:ObjectCreated:Put', 'https://example.test', '{}');
        $first = $this->store->dequeueNotifications(1)[0];
        $id = $first['id'];
        $this->sql('UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE id = ?', [$id]);
        $second = $this->store->dequeueNotifications(1)[0];
        self::assertSame($id, $second['id']);
        $firstGeneration = $first['lease_generation'] ?? null;
        $secondGeneration = $second['lease_generation'] ?? null;
        self::assertIsInt($firstGeneration);
        self::assertIsInt($secondGeneration);
        self::assertSame($firstGeneration + 1, $secondGeneration);

        self::assertFalse($this->store->updateNotificationStatusLease($id, 'sent', null, null, true, $first['lease_expires_at'], $firstGeneration));
        self::assertSame('processing', $this->scalar('SELECT status FROM s3_notification_queue WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->scalar('SELECT attempts FROM s3_notification_queue WHERE id = ?', [$id]));

        self::assertTrue($this->store->updateNotificationStatusLease($id, 'sent', null, null, true, $second['lease_expires_at'], $secondGeneration));
        self::assertSame('sent', $this->scalar('SELECT status FROM s3_notification_queue WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->scalar('SELECT attempts FROM s3_notification_queue WHERE id = ?', [$id]));

        self::assertFalse($this->store->updateNotificationStatusLease($id, 'pending', null, 0, true, $first['lease_expires_at'], $firstGeneration));
        self::assertSame('sent', $this->scalar('SELECT status FROM s3_notification_queue WHERE id = ?', [$id]));
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

    public function test_expired_storage_garbage_worker_cannot_acknowledge_or_retry_reclaimed_lease(): void
    {
        $this->store->enqueueStorageGarbage('bucket', 'hot', 'hot/object');
        $first = $this->store->dequeueStorageGarbage(1)[0];
        $firstGeneration = $first['lease_generation'] ?? null;
        self::assertIsInt($firstGeneration);
        $id = $first['id'];
        $this->sql('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE id = ?', [$id]);
        $second = $this->store->dequeueStorageGarbage(1)[0];
        $secondGeneration = $second['lease_generation'] ?? null;
        self::assertIsInt($secondGeneration);
        self::assertSame($id, $second['id']);
        self::assertSame($firstGeneration + 1, $secondGeneration);

        self::assertFalse($this->store->completeStorageGarbageLease($id, $first['lease_expires_at'], $firstGeneration));
        self::assertFalse($this->store->retryStorageGarbageLease($id, 'stale failure', 0, $first['lease_expires_at'], $firstGeneration));
        self::assertSame('processing', $this->scalar('SELECT status FROM s3_storage_garbage WHERE id = ?', [$id]));
        self::assertSame(0, (int) $this->scalar('SELECT attempts FROM s3_storage_garbage WHERE id = ?', [$id]));

        self::assertTrue($this->store->retryStorageGarbageLease($id, 'current failure', 0, $second['lease_expires_at'], $secondGeneration));
        self::assertSame('pending', $this->scalar('SELECT status FROM s3_storage_garbage WHERE id = ?', [$id]));
        self::assertSame(1, (int) $this->scalar('SELECT attempts FROM s3_storage_garbage WHERE id = ?', [$id]));
        $third = $this->store->dequeueStorageGarbage(1)[0];
        $thirdGeneration = $third['lease_generation'] ?? null;
        self::assertIsInt($thirdGeneration);
        self::assertFalse($this->store->completeStorageGarbageLease($id, $second['lease_expires_at'], $secondGeneration));
        self::assertTrue($this->store->completeStorageGarbageLease($id, $third['lease_expires_at'], $thirdGeneration));
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

    public function test_reclaimed_tier_and_restore_jobs_reject_old_worker_renewals(): void
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

        $firstTransition = $this->store->dequeueTierTransitionJobs(1)[0];
        $firstRestore = $this->store->dequeueRestoreJobs(1)[0];
        $this->sql('UPDATE s3_tier_transition_jobs SET next_attempt_at = 0 WHERE id = ?', [$transitionId]);
        $this->sql('UPDATE s3_restore_jobs SET next_attempt_at = 0 WHERE id = ?', [$restoreId]);
        $secondTransition = $this->store->dequeueTierTransitionJobs(1)[0];
        $secondRestore = $this->store->dequeueRestoreJobs(1)[0];

        self::assertSame($firstTransition['leaseGeneration'] + 1, $secondTransition['leaseGeneration']);
        self::assertSame($firstRestore['leaseGeneration'] + 1, $secondRestore['leaseGeneration']);
        $newLease = QueueLease::expiresAt() + 10;
        self::assertFalse($this->store->renewTierTransitionJobLeaseFenced($transitionId, $newLease, $firstTransition['nextAttemptAt'], $firstTransition['leaseGeneration']));
        self::assertFalse($this->store->renewRestoreJobLeaseFenced($restoreId, $newLease, $firstRestore['nextAttemptAt'], $firstRestore['leaseGeneration']));
        self::assertTrue($this->store->renewTierTransitionJobLeaseFenced($transitionId, $newLease, $secondTransition['nextAttemptAt'], $secondTransition['leaseGeneration']));
        self::assertTrue($this->store->renewRestoreJobLeaseFenced($restoreId, $newLease, $secondRestore['nextAttemptAt'], $secondRestore['leaseGeneration']));
        $currentTransition = $this->store->getTierTransitionJob($transitionId);
        $currentRestore = $this->store->getRestoreJob($restoreId);
        self::assertNotNull($currentTransition);
        self::assertNotNull($currentRestore);
        self::assertEqualsWithDelta($newLease, $currentTransition['nextAttemptAt'], 0.001);
        self::assertEqualsWithDelta($newLease, $currentRestore['nextAttemptAt'], 0.001);
    }

    public function test_reclaimed_tier_and_restore_jobs_reject_old_worker_status_updates(): void
    {
        $transitionId = $this->store->enqueueTierTransitionJob('bucket', 'object', null, 'hot', 'archive', 'GLACIER', 'hot/object');
        $restoreId = $this->store->enqueueRestoreJob('bucket', 'object', null, 'archive', 'archive/object', 1);
        $firstTransition = $this->store->dequeueTierTransitionJobs(1)[0];
        $firstRestore = $this->store->dequeueRestoreJobs(1)[0];
        $this->sql('UPDATE s3_tier_transition_jobs SET next_attempt_at = 0 WHERE id = ?', [$transitionId]);
        $this->sql('UPDATE s3_restore_jobs SET next_attempt_at = 0 WHERE id = ?', [$restoreId]);
        $secondTransition = $this->store->dequeueTierTransitionJobs(1)[0];
        $secondRestore = $this->store->dequeueRestoreJobs(1)[0];

        self::assertFalse($this->store->updateTierTransitionJobStatusLease(
            $transitionId,
            'completed',
            null,
            null,
            false,
            null,
            $firstTransition['nextAttemptAt'],
            $firstTransition['leaseGeneration'],
        ));
        self::assertFalse($this->store->updateRestoreJobStatusLease(
            $restoreId,
            'completed',
            null,
            null,
            false,
            null,
            $firstRestore['nextAttemptAt'],
            $firstRestore['leaseGeneration'],
        ));
        self::assertSame('processing', $this->store->getTierTransitionJob($transitionId)['status'] ?? null);
        self::assertSame('processing', $this->store->getRestoreJob($restoreId)['status'] ?? null);

        self::assertTrue($this->store->updateTierTransitionJobStatusLease(
            $transitionId,
            'completed',
            null,
            null,
            false,
            null,
            $secondTransition['nextAttemptAt'],
            $secondTransition['leaseGeneration'],
        ));
        self::assertTrue($this->store->updateRestoreJobStatusLease(
            $restoreId,
            'completed',
            null,
            null,
            false,
            null,
            $secondRestore['nextAttemptAt'],
            $secondRestore['leaseGeneration'],
        ));
    }

    public function test_expired_tier_and_restore_leases_cannot_be_revived_or_completed(): void
    {
        $transitionId = $this->store->enqueueTierTransitionJob('bucket', 'object', null, 'hot', 'archive', 'GLACIER', 'hot/object');
        $restoreId = $this->store->enqueueRestoreJob('bucket', 'object', null, 'archive', 'archive/object', 1);
        $this->store->dequeueTierTransitionJobs(1);
        $this->store->dequeueRestoreJobs(1);
        $this->sql('UPDATE s3_tier_transition_jobs SET next_attempt_at = 1 WHERE id = ?', [$transitionId]);
        $this->sql('UPDATE s3_restore_jobs SET next_attempt_at = 1 WHERE id = ?', [$restoreId]);

        self::assertFalse($this->store->renewTierTransitionJobLease($transitionId, QueueLease::expiresAt(), 1.0));
        self::assertFalse($this->store->renewRestoreJobLease($restoreId, QueueLease::expiresAt(), 1.0));
        self::assertFalse($this->store->updateTierTransitionJobStatus($transitionId, 'completed', expectedLeaseExpiresAt: 1.0));
        self::assertFalse($this->store->updateRestoreJobStatus($restoreId, 'completed', expectedLeaseExpiresAt: 1.0));
        self::assertSame('processing', $this->store->getTierTransitionJob($transitionId)['status'] ?? null);
        self::assertSame('processing', $this->store->getRestoreJob($restoreId)['status'] ?? null);
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

        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);

        return $pdo;
    }
}
