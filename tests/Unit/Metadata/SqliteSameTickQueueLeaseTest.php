<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\QueueLease;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class SqliteSameTickQueueLeaseTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-same-tick-lease-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_notification_stale_ack_cannot_complete_a_same_tick_reclaim(): void
    {
        $this->store->enqueueNotification('bucket', 'key', 's3:ObjectCreated:Put', 'https://example.test', '{}');
        $this->forceDue('s3_notification_queue', $this->lastInsertedId('s3_notification_queue'));
        $first = $this->store->dequeueNotifications(1)[0];
        $id = $first['id'];
        $this->forceDue('s3_notification_queue', $id);
        $second = $this->store->dequeueNotifications(1)[0];
        $this->collideLease('s3_notification_queue', $id, $first['lease_expires_at']);

        self::assertSame($id, $second['id']);
        $firstGeneration = $this->claimGeneration($first, 'lease_generation');
        $secondGeneration = $this->claimGeneration($second, 'lease_generation');
        self::assertSame($firstGeneration + 1, $secondGeneration);
        self::assertFalse($this->store->updateNotificationStatusLease(
            $id,
            'sent',
            null,
            null,
            true,
            $first['lease_expires_at'],
            $firstGeneration,
        ));
        self::assertSame('processing', $this->queueStatus('s3_notification_queue', $id));
        $this->collideLease('s3_notification_queue', $id, $second['lease_expires_at']);
        self::assertTrue($this->store->updateNotificationStatusLease(
            $id,
            'sent',
            null,
            null,
            true,
            $second['lease_expires_at'],
            $secondGeneration,
        ));
    }

    public function test_notification_stale_retry_cannot_reschedule_a_same_tick_reclaim(): void
    {
        $this->store->enqueueNotification('bucket', 'key', 's3:ObjectCreated:Put', 'https://example.test', '{}');
        $this->forceDue('s3_notification_queue', $this->lastInsertedId('s3_notification_queue'));
        $first = $this->store->dequeueNotifications(1)[0];
        $id = $first['id'];
        $this->forceDue('s3_notification_queue', $id);
        $second = $this->store->dequeueNotifications(1)[0];
        $this->collideLease('s3_notification_queue', $id, $first['lease_expires_at']);

        $firstGeneration = $this->claimGeneration($first, 'lease_generation');
        $secondGeneration = $this->claimGeneration($second, 'lease_generation');
        self::assertSame($firstGeneration + 1, $secondGeneration);
        self::assertFalse($this->store->updateNotificationStatusLease(
            $id,
            'pending',
            'stale failure',
            0,
            true,
            $first['lease_expires_at'],
            $firstGeneration,
        ));
        self::assertSame('processing', $this->queueStatus('s3_notification_queue', $id));
        $this->collideLease('s3_notification_queue', $id, $second['lease_expires_at']);
        self::assertTrue($this->store->updateNotificationStatusLease(
            $id,
            'pending',
            'current failure',
            0,
            true,
            $second['lease_expires_at'],
            $secondGeneration,
        ));
    }

    public function test_tier_stale_ack_cannot_complete_a_same_tick_reclaim(): void
    {
        $id = $this->tierJob();
        $this->forceDue('s3_tier_transition_jobs', $id);
        $first = $this->store->dequeueTierTransitionJobs(1)[0];
        $this->forceDue('s3_tier_transition_jobs', $id);
        $second = $this->store->dequeueTierTransitionJobs(1)[0];
        $this->collideLease('s3_tier_transition_jobs', $id, $first['nextAttemptAt']);

        self::assertSame($first['leaseGeneration'] + 1, $second['leaseGeneration']);
        self::assertFalse($this->store->updateTierTransitionJobStatusLease(
            $id,
            'completed',
            null,
            null,
            false,
            null,
            $first['nextAttemptAt'],
            $first['leaseGeneration'],
        ));
        self::assertSame('processing', $this->queueStatus('s3_tier_transition_jobs', $id));
        $this->collideLease('s3_tier_transition_jobs', $id, $second['nextAttemptAt']);
        self::assertTrue($this->store->updateTierTransitionJobStatusLease(
            $id,
            'completed',
            null,
            null,
            false,
            null,
            $second['nextAttemptAt'],
            $second['leaseGeneration'],
        ));
    }

    public function test_tier_stale_renew_cannot_extend_a_same_tick_reclaim(): void
    {
        $id = $this->tierJob();
        $this->forceDue('s3_tier_transition_jobs', $id);
        $first = $this->store->dequeueTierTransitionJobs(1)[0];
        $this->forceDue('s3_tier_transition_jobs', $id);
        $second = $this->store->dequeueTierTransitionJobs(1)[0];
        $this->collideLease('s3_tier_transition_jobs', $id, $first['nextAttemptAt']);

        self::assertSame($first['leaseGeneration'] + 1, $second['leaseGeneration']);
        self::assertFalse($this->store->renewTierTransitionJobLeaseFenced(
            $id,
            QueueLease::expiresAt() + 10,
            $first['nextAttemptAt'],
            $first['leaseGeneration'],
        ));
        $this->collideLease('s3_tier_transition_jobs', $id, $second['nextAttemptAt']);
        self::assertTrue($this->store->renewTierTransitionJobLeaseFenced(
            $id,
            QueueLease::expiresAt() + 10,
            $second['nextAttemptAt'],
            $second['leaseGeneration'],
        ));
    }

    public function test_restore_stale_ack_cannot_complete_a_same_tick_reclaim(): void
    {
        $id = $this->restoreJob();
        $this->forceDue('s3_restore_jobs', $id);
        $first = $this->store->dequeueRestoreJobs(1)[0];
        $this->forceDue('s3_restore_jobs', $id);
        $second = $this->store->dequeueRestoreJobs(1)[0];
        $this->collideLease('s3_restore_jobs', $id, $first['nextAttemptAt']);

        self::assertSame($first['leaseGeneration'] + 1, $second['leaseGeneration']);
        self::assertFalse($this->store->updateRestoreJobStatusLease(
            $id,
            'completed',
            null,
            null,
            false,
            null,
            $first['nextAttemptAt'],
            $first['leaseGeneration'],
        ));
        self::assertSame('processing', $this->queueStatus('s3_restore_jobs', $id));
        $this->collideLease('s3_restore_jobs', $id, $second['nextAttemptAt']);
        self::assertTrue($this->store->updateRestoreJobStatusLease(
            $id,
            'completed',
            null,
            null,
            false,
            null,
            $second['nextAttemptAt'],
            $second['leaseGeneration'],
        ));
    }

    public function test_restore_stale_renew_cannot_extend_a_same_tick_reclaim(): void
    {
        $id = $this->restoreJob();
        $this->forceDue('s3_restore_jobs', $id);
        $first = $this->store->dequeueRestoreJobs(1)[0];
        $this->forceDue('s3_restore_jobs', $id);
        $second = $this->store->dequeueRestoreJobs(1)[0];
        $this->collideLease('s3_restore_jobs', $id, $first['nextAttemptAt']);

        self::assertSame($first['leaseGeneration'] + 1, $second['leaseGeneration']);
        self::assertFalse($this->store->renewRestoreJobLeaseFenced(
            $id,
            QueueLease::expiresAt() + 10,
            $first['nextAttemptAt'],
            $first['leaseGeneration'],
        ));
        $this->collideLease('s3_restore_jobs', $id, $second['nextAttemptAt']);
        self::assertTrue($this->store->renewRestoreJobLeaseFenced(
            $id,
            QueueLease::expiresAt() + 10,
            $second['nextAttemptAt'],
            $second['leaseGeneration'],
        ));
    }

    public function test_storage_garbage_stale_ack_cannot_delete_a_same_tick_reclaim(): void
    {
        $this->store->enqueueStorageGarbage('bucket', 'hot', 'hot/key');
        $id = $this->lastInsertedId('s3_storage_garbage');
        $this->forceDue('s3_storage_garbage', $id);
        $first = $this->store->dequeueStorageGarbage(1)[0];
        $this->forceDue('s3_storage_garbage', $id);
        $second = $this->store->dequeueStorageGarbage(1)[0];
        $this->collideLease('s3_storage_garbage', $id, $first['lease_expires_at']);

        $firstGeneration = $this->claimGeneration($first, 'lease_generation');
        $secondGeneration = $this->claimGeneration($second, 'lease_generation');
        self::assertSame($firstGeneration + 1, $secondGeneration);
        self::assertFalse($this->store->completeStorageGarbageLease(
            $id,
            $first['lease_expires_at'],
            $firstGeneration,
        ));
        self::assertSame('processing', $this->queueStatus('s3_storage_garbage', $id));
        $this->collideLease('s3_storage_garbage', $id, $second['lease_expires_at']);
        self::assertTrue($this->store->completeStorageGarbageLease(
            $id,
            $second['lease_expires_at'],
            $secondGeneration,
        ));
        self::assertSame([], $this->store->dequeueStorageGarbage(1));
    }

    public function test_storage_garbage_stale_retry_cannot_reschedule_a_same_tick_reclaim(): void
    {
        $this->store->enqueueStorageGarbage('bucket', 'hot', 'hot/key');
        $id = $this->lastInsertedId('s3_storage_garbage');
        $this->forceDue('s3_storage_garbage', $id);
        $first = $this->store->dequeueStorageGarbage(1)[0];
        $this->forceDue('s3_storage_garbage', $id);
        $second = $this->store->dequeueStorageGarbage(1)[0];
        $this->collideLease('s3_storage_garbage', $id, $first['lease_expires_at']);

        $firstGeneration = $this->claimGeneration($first, 'lease_generation');
        $secondGeneration = $this->claimGeneration($second, 'lease_generation');
        self::assertSame($firstGeneration + 1, $secondGeneration);
        self::assertFalse($this->store->retryStorageGarbageLease(
            $id,
            'stale failure',
            0,
            $first['lease_expires_at'],
            $firstGeneration,
        ));
        self::assertSame('processing', $this->queueStatus('s3_storage_garbage', $id));
        $this->collideLease('s3_storage_garbage', $id, $second['lease_expires_at']);
        self::assertTrue($this->store->retryStorageGarbageLease(
            $id,
            'current failure',
            0,
            $second['lease_expires_at'],
            $secondGeneration,
        ));
        self::assertSame('pending', $this->queueStatus('s3_storage_garbage', $id));
    }

    private function tierJob(): int
    {
        return $this->store->enqueueTierTransitionJob(
            'bucket',
            'key',
            null,
            'hot',
            'archive',
            'GLACIER',
            'hot/key',
        );
    }

    private function restoreJob(): int
    {
        return $this->store->enqueueRestoreJob('bucket', 'key', null, 'archive', 'archive/key', 1);
    }

    private function forceDue(string $table, int $id): void
    {
        $this->update($table, $id, 0.0);
    }

    private function collideLease(string $table, int $id, float $firstLease): void
    {
        // Simulate two claims receiving the same database-clock tick. The
        // deadline alone must never authorize the older worker's mutation.
        $this->update($table, $id, $firstLease);
    }

    private function update(string $table, int $id, float $lease): void
    {
        $statement = (new \PDO('sqlite:' . $this->path))->prepare(
            "UPDATE {$table} SET next_attempt_at = ? WHERE id = ?",
        );
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute([sprintf('%.17F', $lease), $id]);
    }

    private function queueStatus(string $table, int $id): string
    {
        $statement = (new \PDO('sqlite:' . $this->path))->prepare(
            "SELECT status FROM {$table} WHERE id = ?",
        );
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute([$id]);

        return (string) $statement->fetchColumn();
    }

    private function lastInsertedId(string $table): int
    {
        $statement = (new \PDO('sqlite:' . $this->path))->query("SELECT MAX(id) FROM {$table}");
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }

    /** @param array<string, mixed> $claim */
    private function claimGeneration(array $claim, string $field): int
    {
        $generation = $claim[$field] ?? null;
        self::assertIsInt($generation);

        return $generation;
    }
}
