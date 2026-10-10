<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use PHPUnit\Framework\TestCase;

final class MysqlLifecycleIdentifierMigrationTest extends TestCase
{
    public function test_v31_preserves_multibyte_ids_and_distinguishes_byte_distinct_ids_after_partial_retry(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false
            || preg_match('/(?:^|[;\s])dbname=s3audit_lifecycle_identity(?:[;\s]|$)/i', $dsn) !== 1
            || getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Requires the dedicated s3audit_lifecycle_identity MySQL database and destructive migration opt-in.');
        }

        $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $database = $pool->query('SELECT DATABASE() AS database_name')->fetchRow();
        if (($database['database_name'] ?? null) !== 's3audit_lifecycle_identity') {
            $pool->close();
            self::fail('The lifecycle migration test requires its dedicated disposable database.');
        }

        $store = MysqlMetadataStore::fromDsn($dsn);
        $store->initialize();
        $this->assertBinaryColumns($pool);
        $suffix = bin2hex(random_bytes(8));
        $owner = 'lifecycle-identity-' . $suffix;
        $bucket = 'lifecycle-identity-' . $suffix;
        $oldId = str_repeat('é', 255);
        $store->createBucket($owner, $bucket, 'us-east-1');

        try {
            $store->putBucketLifecycle($bucket, [$this->lifecycleRule('Alpha'), $this->lifecycleRule('alpha')]);
            $store->putBucketNotification($bucket, [$this->notification('Alpha'), $this->notification('alpha')]);
            $store->putLifecycleCheckpoint($bucket, 'Alpha', 'expire_current', 'upper-cursor');
            $store->putLifecycleCheckpoint($bucket, 'alpha', 'expire_current', 'lower-cursor');
            self::assertSame(['Alpha', 'alpha'], array_column($store->getBucketLifecycle($bucket), 'id'));
            self::assertSame(['Alpha', 'alpha'], array_column($store->getBucketNotification($bucket), 'id'));
            self::assertSame('upper-cursor', $store->getLifecycleCheckpoint($bucket, 'Alpha', 'expire_current')['cursorKey'] ?? null);
            self::assertSame('lower-cursor', $store->getLifecycleCheckpoint($bucket, 'alpha', 'expire_current')['cursorKey'] ?? null);
            $store->deleteLifecycleCheckpoint($bucket, 'Alpha', 'expire_current');
            $store->deleteLifecycleCheckpoint($bucket, 'alpha', 'expire_current');

            $store->putBucketLifecycle($bucket, [$this->lifecycleRule($oldId)]);
            $store->putLifecycleCheckpoint($bucket, $oldId, 'expire_current', 'old-cursor');
            $store->putBucketNotification($bucket, [$this->notification($oldId)]);

            // Recreate only the three legacy columns in this dedicated database.
            // The prior utf8mb4 VARCHAR(255) accepts this 510-byte ID.
            $pool->execute('ALTER TABLE s3_lifecycle_rules MODIFY rule_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL');
            $pool->execute('ALTER TABLE s3_lifecycle_checkpoints MODIFY rule_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL');
            $pool->execute('ALTER TABLE s3_notification_configs MODIFY config_id VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL');
            $pool->execute('DELETE FROM s3_schema_version WHERE version = 31');
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES (30, 's3audit_lifecycle_identity fixture')");

            // MySQL DDL commits per statement. A restart must finish a
            // migration whose first ALTER already succeeded.
            $pool->execute(MysqlSchema::getMigrationStatements(30)[0]);
            $migrated = MysqlMetadataStore::fromDsn($dsn);
            $migrated->initialize();
            $this->assertBinaryColumns($pool);
            self::assertSame($oldId, $migrated->getBucketLifecycle($bucket)[0]['id']);
            self::assertSame('old-cursor', $migrated->getLifecycleCheckpoint($bucket, $oldId, 'expire_current')['cursorKey'] ?? null);
            self::assertSame($oldId, $migrated->getBucketNotification($bucket)[0]['id']);

            $caseIds = ['Alpha', 'alpha', 'É', 'é', 'tail', 'tail '];
            $migrated->putBucketLifecycle($bucket, array_map(
                $this->lifecycleRule(...),
                [$oldId, ...$caseIds],
            ));
            $migrated->putBucketNotification($bucket, array_map($this->notification(...), [$oldId, ...$caseIds]));
            foreach ($caseIds as $index => $id) {
                $migrated->putLifecycleCheckpoint($bucket, $id, 'expire_current', 'cursor-' . $index);
            }

            $ruleIds = array_column($migrated->getBucketLifecycle($bucket), 'id');
            $notificationIds = array_column($migrated->getBucketNotification($bucket), 'id');
            sort($ruleIds, SORT_STRING);
            sort($notificationIds, SORT_STRING);
            $expectedIds = [$oldId, ...$caseIds];
            sort($expectedIds, SORT_STRING);
            self::assertSame($expectedIds, $ruleIds);
            self::assertSame($expectedIds, $notificationIds);
            foreach ($caseIds as $index => $id) {
                self::assertSame('cursor-' . $index, $migrated->getLifecycleCheckpoint($bucket, $id, 'expire_current')['cursorKey'] ?? null);
            }

            $pool->execute('DELETE FROM s3_schema_version WHERE version = 31');
            $migrated->initialize();
            $this->assertBinaryColumns($pool);
            self::assertCount(7, $migrated->getBucketLifecycle($bucket));
            self::assertCount(7, $migrated->getBucketNotification($bucket));
        } finally {
            // Restore the latest schema even if an assertion fails, then only
            // delete rows created under this unique bucket.
            foreach (MysqlSchema::getMigrationStatements(30) as $statement) {
                $pool->execute($statement);
            }
            $pool->execute('DELETE FROM s3_schema_version WHERE version = 31');
            $store->initialize();
            $pool->execute("DELETE FROM s3_schema_version WHERE version = 30 AND description = 's3audit_lifecycle_identity fixture'");
            $store->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    /** @return array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int} */
    private function lifecycleRule(string $id): array
    {
        return [
            'id' => $id,
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ];
    }

    /** @return array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, array{name: string, value: string}>} */
    private function notification(string $id): array
    {
        return [
            'id' => $id,
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'queue',
            'destinationArn' => 'arn:aws:sqs:us-east-1:123456789012:test',
            'filterRules' => null,
        ];
    }

    private function assertBinaryColumns(MysqlConnectionPool $pool): void
    {
        foreach ([
            ['s3_lifecycle_rules', 'rule_id'],
            ['s3_lifecycle_checkpoints', 'rule_id'],
            ['s3_notification_configs', 'config_id'],
        ] as [$table, $column]) {
            $row = $pool->execute(
                'SELECT DATA_TYPE, CHARACTER_MAXIMUM_LENGTH AS max_length FROM information_schema.COLUMNS '
                . 'WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND COLUMN_NAME = ?',
                [$table, $column],
            )->fetchRow();
            self::assertSame('varbinary', $row['DATA_TYPE'] ?? null);
            self::assertSame(1020, (int) ($row['max_length'] ?? 0));
        }
    }
}
