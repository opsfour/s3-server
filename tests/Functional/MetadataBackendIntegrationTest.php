<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MetadataBackendIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function externalMetadataDrivers(): iterable
    {
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_metadata_backend_round_trip_and_observability(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} metadata backend integration tests.");
        }

        $metrics = new MetricsCollector();
        $metadata = new ObservedMetadataStore(
            MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]),
            $metrics,
            $driver,
            slowThresholdNs: 0,
        );

        $ownerId = 'metadata-it-' . bin2hex(random_bytes(4));
        $bucket = 'metadata-it-' . bin2hex(random_bytes(8));
        $namedPolicy = 'metadata-it-' . bin2hex(random_bytes(4));

        try {
            $this->cleanup($metadata, $ownerId, $bucket, $namedPolicy);

            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            self::assertSame($ownerId, $metadata->getBucketOwner($bucket));
            self::assertTrue($metadata->bucketExists($bucket));

            $metadata->putObjectMetadata(
                bucket: $bucket,
                key: 'object.txt',
                ownerId: $ownerId,
                size: 12,
                etag: '"etag"',
                contentType: 'text/plain',
                storagePath: $bucket . '/object.txt',
                userMetadata: ['source' => 'integration'],
            );
            self::assertSame(1, $metadata->countObjects($bucket));
            self::assertSame(12, $metadata->getObjectMetadata($bucket, 'object.txt')?->size);

            $transitionJobId = $metadata->enqueueTierTransitionJob(
                $bucket,
                'object.txt',
                null,
                'STANDARD',
                'GLACIER',
                'GLACIER',
                $bucket . '/object.txt',
            );
            self::assertGreaterThan(0, $transitionJobId);
            self::agePendingQueueItem($driver, trim($dsn), 's3_tier_transition_jobs', $transitionJobId);
            self::assertCount(1, $metadata->dequeueTierTransitionJobs(1));
            self::assertSame([], $metadata->dequeueTierTransitionJobs(1));
            self::assertTrue($metadata->renewTierTransitionJobLease($transitionJobId, microtime(true) + 300));
            $metadata->updateTierTransitionJobStatus(
                $transitionJobId,
                'completed',
                incrementAttempts: false,
                targetStoragePath: $bucket . '/archive/object.txt',
            );
            self::assertSame('completed', $metadata->getTierTransitionJob($transitionJobId)['status'] ?? null);
            self::assertFalse($metadata->renewTierTransitionJobLease($transitionJobId, microtime(true) + 300));

            $restoreJobId = $metadata->enqueueRestoreJob(
                $bucket,
                'object.txt',
                null,
                'GLACIER',
                $bucket . '/archive/object.txt',
                2,
            );
            self::assertGreaterThan(0, $restoreJobId);
            self::agePendingQueueItem($driver, trim($dsn), 's3_restore_jobs', $restoreJobId);
            self::assertCount(1, $metadata->dequeueRestoreJobs(1));
            self::assertSame([], $metadata->dequeueRestoreJobs(1));
            self::assertTrue($metadata->renewRestoreJobLease($restoreJobId, microtime(true) + 300));
            $metadata->updateRestoreJobStatus(
                $restoreJobId,
                'completed',
                incrementAttempts: false,
                restoredStoragePath: $bucket . '/restored/object.txt',
            );
            self::assertSame('completed', $metadata->getRestoreJob($restoreJobId)['status'] ?? null);
            self::assertFalse($metadata->renewRestoreJobLease($restoreJobId, microtime(true) + 300));

            $metadata->enqueueNotification(
                $bucket,
                'object.txt',
                's3:ObjectCreated:Put',
                'https://example.test/webhook',
                '{}',
            );
            $notificationId = self::lastQueueItemId($driver, trim($dsn), 's3_notification_queue', $bucket);
            self::agePendingQueueItem($driver, trim($dsn), 's3_notification_queue', $notificationId);
            self::assertSame($notificationId, $metadata->dequeueNotifications(1)[0]['id']);
            self::assertSame([], $metadata->dequeueNotifications(1));
            $metadata->updateNotificationStatus($notificationId, 'sent');

            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', $bucket . '/garbage-discard');
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', $bucket . '/garbage-collect');
            $metadata->discardStorageGarbage($bucket, 'STANDARD', $bucket . '/garbage-discard');
            $garbage = $metadata->dequeueStorageGarbage(10);
            self::assertCount(1, $garbage);
            self::assertSame($bucket . '/garbage-collect', $garbage[0]['storage_path']);
            $metadata->completeStorageGarbage($garbage[0]['id']);
            self::assertSame([], $metadata->dequeueStorageGarbage(10));

            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', $bucket . '/garbage-reclaim');
            $leasedGarbage = $metadata->dequeueStorageGarbage(1);
            self::assertCount(1, $leasedGarbage);
            self::expireStorageGarbageLease($driver, trim($dsn), $leasedGarbage[0]['id']);
            $reclaimedGarbage = $metadata->dequeueStorageGarbage(1);
            self::assertCount(1, $reclaimedGarbage);
            self::assertSame($leasedGarbage[0]['id'], $reclaimedGarbage[0]['id']);
            $metadata->completeStorageGarbage($reclaimedGarbage[0]['id']);

            $longPrefix = str_repeat('shared-prefix/', 25);
            $versionPrefix = str_repeat('version-prefix-', 10);
            $metadata->putObjectTagging(
                $bucket,
                $longPrefix . 'first',
                [['key' => 'state', 'value' => 'first']],
                $versionPrefix . 'first',
            );
            $metadata->putObjectTagging(
                $bucket,
                $longPrefix . 'second',
                [['key' => 'state', 'value' => 'second']],
                $versionPrefix . 'second',
            );
            self::assertSame(
                [['key' => 'state', 'value' => 'first']],
                $metadata->getObjectTagging($bucket, $longPrefix . 'first', $versionPrefix . 'first'),
            );
            self::assertSame(
                [['key' => 'state', 'value' => 'second']],
                $metadata->getObjectTagging($bucket, $longPrefix . 'second', $versionPrefix . 'second'),
            );

            $quota = new QuotaConfig(maxBucketsPerOwner: 2, maxObjectsPerBucket: 3, maxBytesPerBucket: 1024, maxBytesPerOwner: 2048);
            $metadata->putAccountQuota($ownerId, $quota);
            self::assertSame($quota->maxBytesPerOwner, $metadata->getAccountQuota($ownerId)?->maxBytesPerOwner);

            $policy = '{"Version":"2012-10-17","Statement":[]}';
            $metadata->putNamedPolicy($namedPolicy, $policy);
            $storedPolicy = $metadata->getNamedPolicy($namedPolicy);
            self::assertNotNull($storedPolicy);
            self::assertJsonStringEqualsJsonString($policy, $storedPolicy);

            try {
                $metadata->createBucket($ownerId, $bucket, 'us-east-1');
                self::fail('Expected duplicate bucket creation to fail.');
            } catch (BucketAlreadyExistsException) {
            }

            $rendered = $metrics->renderPrometheus();
            self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="' . $driver . '",operation="createBucket"} 2', $rendered);
            self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="' . $driver . '",operation="putObjectMetadata"} 1', $rendered);
            self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="' . $driver . '",operation="putAccountQuota"} 1', $rendered);
            self::assertStringContainsString('s3_server_backend_slow_operations_total{backend="metadata",driver="' . $driver . '",operation="createBucket"} 2', $rendered);
            self::assertStringContainsString(
                's3_server_backend_errors_total{backend="metadata",driver="' . $driver . '",exception="' . str_replace('\\', '\\\\', BucketAlreadyExistsException::class) . '",operation="createBucket"} 1',
                $rendered,
            );
        } finally {
            $this->cleanup($metadata, $ownerId, $bucket, $namedPolicy);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_backend_serializes_concurrent_account_quota_writes(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} metadata backend concurrency tests.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $ownerId = 'metadata-quota-' . bin2hex(random_bytes(4));
        $bucket = 'metadata-quota-' . bin2hex(random_bytes(8));
        $keys = ['first.bin', 'second.bin'];

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            $metadata->putAccountQuota($ownerId, new QuotaConfig(maxBytesPerOwner: 100));

            $worker = dirname(__DIR__) . '/Support/metadata-quota-worker.php';
            $processes = [];
            foreach ($keys as $key) {
                $process = new Process(
                    [PHP_BINARY, $worker, $driver, $ownerId, $bucket, $key],
                    dirname(__DIR__, 2),
                    ['S3_TEST_WORKER_DSN' => $dsn],
                );
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }

            $results = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $results[] = trim($process->getOutput());
            }
            sort($results);

            self::assertSame(['ok', 'quota'], $results);
            self::assertSame(1, $metadata->countObjects($bucket));
            self::assertSame(60, $metadata->getBucketStorageStats($bucket)['bytesUsed']);
        } finally {
            foreach ($keys as $key) {
                try {
                    $metadata->deleteObjectMetadata($bucket, $key);
                } catch (\Throwable) {
                }
            }
            try {
                $metadata->deleteBucket($ownerId, $bucket);
            } catch (\Throwable) {
            }
            try {
                $metadata->deleteAccountQuota($ownerId);
            } catch (\Throwable) {
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_backend_migrates_v11_schema_to_current(string $driver, string $dsnEnv): void
    {
        if (getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Set S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 only for a disposable metadata database.');
        }

        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} metadata migration tests.");
        }

        $dsn = trim($dsn);
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));

        try {
            // Bootstrap the complete schema first so this test can also run by
            // itself against an empty disposable database.
            MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $pool->execute('DROP TABLE IF EXISTS s3_storage_garbage');
            $pool->execute('DROP TABLE IF EXISTS s3_owner_write_locks');

            if ($driver === 'mysql') {
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_uploads_per_bucket');
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_uploads_per_owner');
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_bytes_per_bucket');
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_bytes_per_owner');
                $pool->execute('ALTER TABLE s3_tagging DROP INDEX uq_s3_tagging_version');
                $pool->execute('ALTER TABLE s3_tagging DROP INDEX idx_s3_tagging_resource');
                $pool->execute('ALTER TABLE s3_tagging DROP COLUMN tag_identity');
                $pool->execute('ALTER TABLE s3_tagging DROP COLUMN version_id');
                $pool->execute('ALTER TABLE s3_tagging ADD UNIQUE KEY uk_s3_tagging (resource_type, bucket, key_name(255), tag_key)');
                $pool->execute('ALTER TABLE s3_tagging ADD KEY idx_s3_tagging_resource (resource_type, bucket, key_name(255))');
            } else {
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_uploads_per_bucket');
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_uploads_per_owner');
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_bytes_per_bucket');
                $pool->execute('ALTER TABLE s3_account_quotas DROP COLUMN max_multipart_bytes_per_owner');
                $pool->execute('ALTER TABLE s3_tagging DROP CONSTRAINT uq_s3_tagging_version');
                $pool->execute('DROP INDEX idx_s3_tagging_resource');
                $pool->execute('ALTER TABLE s3_tagging DROP COLUMN version_id');
                $pool->execute('ALTER TABLE s3_tagging ADD CONSTRAINT s3_tagging_resource_type_bucket_key_name_tag_key_key UNIQUE (resource_type, bucket, key_name, tag_key)');
                $pool->execute('CREATE INDEX idx_s3_tagging_resource ON s3_tagging(resource_type, bucket, key_name)');
            }

            $pool->execute('DELETE FROM s3_schema_version WHERE version >= 12');
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES (11, 'Pre-v12 integration fixture')");

            $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $metadata->transaction(function () use ($metadata): void {
                $metadata->lockOwnerForUpdate('metadata-migration-owner');
            });

            $row = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertNotNull($row);
            self::assertSame(15, (int) $row['version']);
            self::assertNotNull(
                $pool->execute(
                    "SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = 'metadata-migration-owner'",
                )->fetchRow(),
            );
            $pool->execute('SELECT version_id FROM s3_tagging LIMIT 1');
            $pool->execute('SELECT max_multipart_uploads_per_bucket FROM s3_account_quotas LIMIT 1');
            $pool->execute('SELECT storage_path FROM s3_storage_garbage LIMIT 1');
        } finally {
            // Restore a usable current schema even if an assertion above fails.
            MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $pool->close();
        }
    }

    private function cleanup(MetadataStore $metadata, string $ownerId, string $bucket, string $namedPolicy): void
    {
        try {
            $metadata->deleteObjectMetadata($bucket, 'object.txt');
        } catch (\Throwable) {
        }

        try {
            $metadata->deleteBucket($ownerId, $bucket);
        } catch (\Throwable) {
        }

        try {
            $metadata->deleteAccountQuota($ownerId);
        } catch (\Throwable) {
        }

        try {
            $metadata->deleteNamedPolicy($namedPolicy);
        } catch (\Throwable) {
        }
    }

    private static function expireStorageGarbageLease(string $driver, string $dsn, int $id): void
    {
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));

        try {
            $pool->execute(
                $driver === 'mysql'
                    ? 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE id = ?'
                    : 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE id = $1',
                [$id],
            );
        } finally {
            $pool->close();
        }
    }

    private static function agePendingQueueItem(string $driver, string $dsn, string $table, int $id): void
    {
        $pool = self::externalPool($driver, $dsn);

        try {
            $pool->execute(
                $driver === 'mysql'
                    ? "UPDATE {$table} SET next_attempt_at = 0 WHERE id = ?"
                    : "UPDATE {$table} SET next_attempt_at = 0 WHERE id = $1",
                [$id],
            );
        } finally {
            $pool->close();
        }
    }

    private static function lastQueueItemId(string $driver, string $dsn, string $table, string $bucket): int
    {
        $pool = self::externalPool($driver, $dsn);

        try {
            $row = $pool->execute(
                $driver === 'mysql'
                    ? "SELECT MAX(id) AS id FROM {$table} WHERE bucket = ?"
                    : "SELECT MAX(id) AS id FROM {$table} WHERE bucket = $1",
                [$bucket],
            )->fetchRow();

            return (int) ($row['id'] ?? 0);
        } finally {
            $pool->close();
        }
    }

    private static function externalPool(string $driver, string $dsn): MysqlConnectionPool|PostgresConnectionPool
    {
        return $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
    }
}
