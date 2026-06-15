<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class SqliteMetadataStoreLockTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-locks-' . bin2hex(random_bytes(4)) . '.sqlite';
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

    public function test_lock_can_only_be_acquired_by_one_owner_until_released(): void
    {
        $this->assertTrue($this->store->acquireLock('lifecycle:global', 'node-a', 60));
        $this->assertFalse($this->store->acquireLock('lifecycle:global', 'node-b', 60));
        $this->assertTrue($this->store->acquireLock('lifecycle:global', 'node-a', 60));

        $this->store->releaseLock('lifecycle:global', 'node-b');
        $this->assertFalse($this->store->acquireLock('lifecycle:global', 'node-b', 60));

        $this->store->releaseLock('lifecycle:global', 'node-a');
        $this->assertTrue($this->store->acquireLock('lifecycle:global', 'node-b', 60));
    }

    public function test_expired_lock_can_be_taken_over(): void
    {
        $this->assertTrue($this->store->acquireLock('lifecycle:global', 'node-a', 60));
        $this->sql(
            'UPDATE s3_locks SET expires_at = ? WHERE lock_name = ?',
            ['2000-01-01T00:00:00Z', 'lifecycle:global'],
        );

        $this->assertTrue($this->store->acquireLock('lifecycle:global', 'node-b', 60));
        $this->assertFalse($this->store->acquireLock('lifecycle:global', 'node-a', 60));
    }

    public function test_lifecycle_checkpoint_round_trip(): void
    {
        $this->assertNull($this->store->getLifecycleCheckpoint('bucket', 'rule', 'expire_current'));

        $this->store->putLifecycleCheckpoint('bucket', 'rule', 'expire_current', 'logs/b.txt', 'v2');
        $this->assertSame(
            ['cursorKey' => 'logs/b.txt', 'cursorVersionId' => 'v2', 'cursorUploadId' => null],
            $this->store->getLifecycleCheckpoint('bucket', 'rule', 'expire_current'),
        );

        $this->store->putLifecycleCheckpoint('bucket', 'rule', 'expire_current', 'logs/c.txt', cursorUploadId: 'upload-1');
        $this->assertSame(
            ['cursorKey' => 'logs/c.txt', 'cursorVersionId' => null, 'cursorUploadId' => 'upload-1'],
            $this->store->getLifecycleCheckpoint('bucket', 'rule', 'expire_current'),
        );

        $this->store->deleteLifecycleCheckpoint('bucket', 'rule', 'expire_current');
        $this->assertNull($this->store->getLifecycleCheckpoint('bucket', 'rule', 'expire_current'));
    }

    public function test_notification_queue_stats_are_counted_by_status(): void
    {
        $this->store->enqueueNotification('bucket', 'a.txt', 's3:ObjectCreated:Put', 'https://example.test/a', '{}');
        $this->store->enqueueNotification('bucket', 'b.txt', 's3:ObjectCreated:Put', 'https://example.test/b', '{}');
        $this->store->enqueueNotification('bucket', 'c.txt', 's3:ObjectCreated:Put', 'https://example.test/c', '{}');

        $items = $this->store->dequeueNotifications(2);
        $this->assertCount(2, $items);

        $this->store->updateNotificationStatus((int) $items[0]['id'], 'sent');
        $this->store->updateNotificationStatus((int) $items[1]['id'], 'dead_letter', 'failed');

        $stats = $this->store->getNotificationQueueStats();
        ksort($stats);

        $this->assertSame(['dead_letter' => 1, 'pending' => 1, 'sent' => 1], $stats);
    }

    /**
     * @param list<mixed> $params
     */
    private function sql(string $sql, array $params): void
    {
        $reflection = new \ReflectionClass($this->store);
        $method = $reflection->getMethod('connection');
        $method->setAccessible(true);
        /** @var \PDO $pdo */
        $pdo = $method->invoke($this->store);
        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
    }
}
