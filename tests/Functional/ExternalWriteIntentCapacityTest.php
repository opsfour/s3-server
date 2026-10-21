<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\DeferredFuture;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaGuard;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Metadata\WriteIntentCapacityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;

final class ExternalWriteIntentCapacityTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_direct_dml_and_rollback_track_both_counters(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $reader = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(WriteIntentCapacityReader::class, $reader);
        $suffix = bin2hex(random_bytes(8));
        $objectId = 'direct-' . $suffix;
        $path = 'part-' . $suffix;
        $beforeObject = self::capacity($pool, 'object');
        $beforePart = self::capacity($pool, 'part');
        $objectSql = "INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until)
            VALUES ('{$objectId}', 'bucket', 'generation', 'owner', 'STANDARD', '{$objectId}', '{$suffix}', 'put', 0)";
        $garbageSql = "INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '{$path}', 'part_write_intent', 0)";
        try {
            $tx = $pool->beginTransaction();
            try {
                $tx->execute($objectSql);
                $tx->execute($garbageSql);
                self::assertSame($beforeObject + 1, (int) ($tx->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object'")->fetchRow()['retained_count'] ?? -1));
                self::assertSame($beforePart + 1, (int) ($tx->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part'")->fetchRow()['retained_count'] ?? -1));
            } finally {
                $tx->rollback();
            }
            self::assertSame($beforeObject, self::capacity($pool, 'object'));
            self::assertSame($beforePart, self::capacity($pool, 'part'));

            $pool->execute($objectSql);
            $pool->execute($garbageSql);
            self::assertSame($beforeObject + 1, self::capacity($pool, 'object'));
            self::assertSame($beforePart + 1, self::capacity($pool, 'part'));
            self::assertSame(
                ['object' => $beforeObject + 1, 'part' => $beforePart + 1],
                $reader->getWriteIntentCapacityCounts(),
            );
            $pool->execute("UPDATE s3_storage_garbage SET operation = 'delete_path' WHERE storage_path = '{$path}'");
            self::assertSame($beforePart, self::capacity($pool, 'part'));
            $pool->execute("UPDATE s3_storage_garbage SET operation = 'part_write_intent' WHERE storage_path = '{$path}'");
            self::assertSame($beforePart + 1, self::capacity($pool, 'part'));

            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = 1000000 WHERE kind IN ('object', 'part')");
            self::rejects(static fn() => $pool->execute(str_replace($objectId, 'blocked-' . $suffix, $objectSql)));
            self::rejects(static fn() => $pool->execute(str_replace($path, 'blocked-' . $suffix, $garbageSql)));
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = 0 WHERE kind IN ('object', 'part')");
            self::rejects(static fn() => $pool->execute("DELETE FROM s3_object_write_intents WHERE attempt_id = '{$objectId}'"));
            self::rejects(static fn() => $pool->execute("UPDATE s3_storage_garbage SET operation = 'delete_path' WHERE storage_path = '{$path}'"));
            self::assertSame(1, self::rowCount($pool, "SELECT COUNT(*) AS n FROM s3_object_write_intents WHERE attempt_id = '{$objectId}'"));
            self::assertSame(1, self::rowCount($pool, "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE storage_path = '{$path}' AND operation = 'part_write_intent'"));

            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = " . ($beforeObject + 1) . " WHERE kind = 'object'");
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = " . ($beforePart + 1) . " WHERE kind = 'part'");
            $pool->execute("DELETE FROM s3_object_write_intents WHERE attempt_id = '{$objectId}'");
            $pool->execute("DELETE FROM s3_storage_garbage WHERE storage_path = '{$path}'");
            self::assertSame($beforeObject, self::capacity($pool, 'object'));
            self::assertSame($beforePart, self::capacity($pool, 'part'));

            $pool->execute("DELETE FROM s3_write_intent_capacity WHERE kind = 'part'");
            self::rejects(static fn() => $pool->execute($garbageSql));
            try {
                MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: false);
                self::fail('Serving accepted a missing capacity counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('capacity counter', $error->getMessage());
            }
        } finally {
            $pool->execute("INSERT INTO s3_write_intent_capacity (kind, retained_count)
                SELECT 'part', (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent')
                WHERE NOT EXISTS (SELECT 1 FROM s3_write_intent_capacity WHERE kind = 'part')");
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = (SELECT COUNT(*) FROM s3_object_write_intents) WHERE kind = 'object'");
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent') WHERE kind = 'part'");
            $pool->execute("DELETE FROM s3_object_write_intents WHERE attempt_id IN ('{$objectId}', 'blocked-{$suffix}')");
            $pool->execute("DELETE FROM s3_storage_garbage WHERE storage_path IN ('{$path}', 'blocked-{$suffix}')");
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_partial_migration_retry_backfills_and_rejects_drift(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $latest = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
        $previous = $latest - 1;
        $suffix = bin2hex(random_bytes(8));
        $statements = $driver === 'postgres'
            ? PostgresSchema::getMigrationStatements($previous)
            : MysqlSchema::getMigrationStatements($previous);
        try {
            foreach (['object_insert' => 's3_object_write_intents', 'object_delete' => 's3_object_write_intents', 'part_insert' => 's3_storage_garbage', 'part_delete' => 's3_storage_garbage', 'part_update' => 's3_storage_garbage'] as $trigger => $table) {
                $pool->query('DROP TRIGGER IF EXISTS s3_write_intent_capacity_' . $trigger . ($driver === 'postgres' ? ' ON ' . $table : ''));
            }
            $pool->query('DROP TABLE s3_write_intent_capacity');
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_schema_version SET version = $1 WHERE version = $2'
                : 'UPDATE s3_schema_version SET version = ? WHERE version = ?', [$previous, $latest]);
            $pool->execute("INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until)
                VALUES ('{$suffix}', 'bucket', 'generation', 'owner', 'STANDARD', '{$suffix}', '{$suffix}', 'put', 0)");
            $pool->execute("INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, next_attempt_at)
                VALUES ('bucket', 'STANDARD', '{$suffix}', 'part_write_intent', 0)");

            if ($driver === 'mysql') {
                // MySQL DDL auto-commits: simulate interruption after table/backfill, before triggers.
                foreach (array_slice($statements, 0, 3) as $sql) {
                    $pool->query($sql);
                }
                self::assertSame($previous, self::schemaVersion($pool));
            }
            MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: true);
            self::assertSame($latest, self::schemaVersion($pool));
            self::assertSame(self::rowCount($pool, 'SELECT COUNT(*) AS n FROM s3_object_write_intents'), self::capacity($pool, 'object'));
            self::assertSame(self::rowCount($pool, "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE operation = 'part_write_intent'"), self::capacity($pool, 'part'));

            // Repeating the old migration with a bad retained row must never publish the new version.
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_schema_version SET version = $1 WHERE version = $2'
                : 'UPDATE s3_schema_version SET version = ? WHERE version = ?', [$previous, $latest]);
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = retained_count + 1 WHERE kind = 'part'");
            try {
                MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: true);
                self::fail('A drifted partial migration published its schema version.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('capacity counter is inconsistent', $error->getMessage());
            }
            self::assertSame($previous, self::schemaVersion($pool));
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count =
                (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent') WHERE kind = 'part'");
            MetadataStoreFactory::create($driver, ['dsn' => $dsn], migrate: true);
            self::assertSame($latest, self::schemaVersion($pool));
        } finally {
            $pool->execute("DELETE FROM s3_object_write_intents WHERE attempt_id = '{$suffix}'");
            $pool->execute("DELETE FROM s3_storage_garbage WHERE storage_path = '{$suffix}'");
            $pool->close();
        }
    }

    public function test_mysql_trigger_rejects_a_stale_repeatable_read_snapshot(): void
    {
        [$dsn, $pool] = self::database('mysql', 'S3_TEST_MYSQL_METADATA_DSN');
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        $before = self::capacity($pool, 'part');
        $path = 'stale-' . bin2hex(random_bytes(8));
        $tx = $pool->beginTransaction();
        try {
            $tx->query('SELECT COUNT(*) AS n FROM s3_storage_garbage')->fetchRow();
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = 1000000 WHERE kind = 'part'");
            self::rejects(static fn() => $tx->execute("INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, next_attempt_at)
                VALUES ('bucket', 'STANDARD', '{$path}', 'part_write_intent', 0)"));
        } finally {
            $tx->rollback();
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = {$before} WHERE kind = 'part'");
            self::assertSame(0, self::rowCount($pool, "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE storage_path = '{$path}'"));
            $pool->close();
        }
    }

    public function test_postgres_serving_and_readiness_reject_a_later_missing_capacity_trigger(): void
    {
        [$dsn, $pool] = self::database('postgres', 'S3_TEST_POSTGRES_METADATA_DSN');
        $store = MetadataStoreFactory::create('postgres', ['dsn' => $dsn]);
        self::assertInstanceOf(PostgresMetadataStore::class, $store);
        $pool->query('DROP TRIGGER s3_write_intent_capacity_part_update ON s3_storage_garbage');
        try {
            foreach ([
                static fn() => MetadataStoreFactory::create('postgres', ['dsn' => $dsn], migrate: false),
                static fn() => $store->assertMetadataWritable(),
            ] as $check) {
                try {
                    $check();
                    self::fail('PostgreSQL serving accepted a missing capacity trigger.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('recovery triggers are missing', $error->getMessage());
                }
            }
        } finally {
            $pool->query('CREATE TRIGGER s3_write_intent_capacity_part_update BEFORE UPDATE OF operation
                ON s3_storage_garbage FOR EACH ROW EXECUTE FUNCTION s3_write_intent_capacity_part_fn()');
            $pool->close();
        }
    }

    public function test_mysql_migrator_detects_missing_capacity_trigger(): void
    {
        [$dsn, $pool] = self::database('mysql', 'S3_TEST_MYSQL_METADATA_DSN');
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        $pool->query('DROP TRIGGER s3_write_intent_capacity_part_update');
        try {
            try {
                MetadataSchemaGuard::assertMysqlWriteIntentTriggersForMigration($pool);
                self::fail('The MySQL migrator accepted a missing capacity trigger.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('recovery triggers are missing', $error->getMessage());
            }
        } finally {
            $restored = false;
            foreach (MysqlSchema::getMigrationStatements(MysqlSchema::VERSION - 1) as $sql) {
                if (str_starts_with(ltrim($sql), 'CREATE TRIGGER s3_write_intent_capacity_part_update ')) {
                    $pool->query($sql);
                    $restored = true;
                    break;
                }
            }
            $pool->close();
            self::assertTrue($restored, 'The MySQL trigger fixture must restore its database state.');
        }
    }

    public function test_mysql_serving_and_readiness_reject_a_later_missing_capacity_trigger(): void
    {
        [$dsn, $pool] = self::database('mysql', 'S3_TEST_MYSQL_METADATA_DSN');
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        $store = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        self::assertInstanceOf(MysqlMetadataStore::class, $store);
        $pool->query('DROP TRIGGER s3_write_intent_capacity_part_update');

        try {
            foreach ([
                static fn() => MetadataStoreFactory::create('mysql', ['dsn' => $dsn], migrate: false),
                static fn() => $store->assertMetadataWritable(),
            ] as $check) {
                try {
                    $check();
                    self::fail('MySQL serving accepted a missing capacity trigger.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('recovery triggers are missing', $error->getMessage());
                }
            }
        } finally {
            $restored = false;
            foreach (MysqlSchema::getCreateStatements() as $sql) {
                if (str_starts_with(ltrim($sql), 'CREATE TRIGGER s3_write_intent_capacity_part_update ')) {
                    $pool->query($sql);
                    $restored = true;
                    break;
                }
            }
            $pool->close();
            self::assertTrue($restored, 'The MySQL capacity trigger fixture must restore its database state.');
        }
    }

    #[DataProvider('databases')]
    public function test_both_admission_paths_serialize_last_global_slot(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $first = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(ObjectWriteIntentStore::class, $first);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $first);
        self::assertInstanceOf(ObjectWriteIntentStore::class, $second);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $second);
        $suffix = bin2hex(random_bytes(6));
        $ownerA = 'capacity-a-' . $suffix;
        $ownerB = 'capacity-b-' . $suffix;
        $bucketA = 'capacity-a-' . $suffix;
        $bucketB = 'capacity-b-' . $suffix;
        $first->createBucket($ownerA, $bucketA, 'us-east-1');
        $first->createBucket($ownerB, $bucketB, 'us-east-1');
        $generationA = $first->getBucket($bucketA)?->generation;
        $generationB = $first->getBucket($bucketB)?->generation;
        self::assertNotNull($generationA);
        self::assertNotNull($generationB);
        $intentA = new ObjectWriteIntent(bin2hex(random_bytes(16)), $bucketA, $generationA, $ownerA, 'STANDARD', 'objects/' . $suffix . '/a', 'put');
        $intentB = new ObjectWriteIntent(bin2hex(random_bytes(16)), $bucketB, $generationB, $ownerB, 'STANDARD', 'objects/' . $suffix . '/b', 'put');
        $uploadA = bin2hex(random_bytes(16));
        $uploadB = bin2hex(random_bytes(16));
        $first->createMultipartUpload($uploadA, $bucketA, 'key', $ownerA);
        $first->createMultipartUpload($uploadB, $bucketB, 'key', $ownerB);

        try {
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = 999999 WHERE kind = 'object'");
            $admitObject = static function (ObjectWriteIntentStore $store, ObjectWriteIntent $intent): string {
                try {
                    $store->reserveObjectWriteIntent($intent, 60.0);

                    return 'accepted';
                } catch (SlowDownException) {
                    return 'full';
                }
            };
            $a = async(fn(): string => $admitObject($first, $intentA));
            $b = async(fn(): string => $admitObject($second, $intentB));
            $results = [$a->await(), $b->await()];
            sort($results);
            self::assertSame(['accepted', 'full'], $results);
            self::assertSame(1000000, self::capacity($pool, 'object'));
            $pool->execute("DELETE FROM s3_object_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')");
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = (SELECT COUNT(*) FROM s3_object_write_intents) WHERE kind = 'object'");

            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = 999999 WHERE kind = 'part'");
            $admitPart = static function (MultipartPartWriteIntentStore $store, string $bucket, string $generation, string $uploadId, string $path): string {
                try {
                    $store->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $path);

                    return 'accepted';
                } catch (SlowDownException) {
                    return 'full';
                }
            };
            $a = async(fn(): string => $admitPart($first, $bucketA, $generationA, $uploadA, 'parts/' . $suffix . '/a'));
            $b = async(fn(): string => $admitPart($second, $bucketB, $generationB, $uploadB, 'parts/' . $suffix . '/b'));
            $results = [$a->await(), $b->await()];
            sort($results);
            self::assertSame(['accepted', 'full'], $results);
            self::assertSame(1000000, self::capacity($pool, 'part'));
        } finally {
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent') WHERE kind = 'part'");
            $pool->execute("DELETE FROM s3_storage_garbage WHERE owner_id IN ('{$ownerA}', '{$ownerB}')");
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent') WHERE kind = 'part'");
            $pool->execute("DELETE FROM s3_object_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')");
            $pool->execute("UPDATE s3_write_intent_capacity SET retained_count = (SELECT COUNT(*) FROM s3_object_write_intents) WHERE kind = 'object'");
            $first->deleteMultipartUpload($uploadA);
            $first->deleteMultipartUpload($uploadB);
            $first->deleteBucket($ownerA, $bucketA);
            $first->deleteBucket($ownerB, $bucketB);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_part_admission_and_commit_consume_do_not_deadlock(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $admission = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $committer = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $admission);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $committer);
        self::assertTrue($admission instanceof PostgresMetadataStore || $admission instanceof MysqlMetadataStore);
        $suffix = bin2hex(random_bytes(6));
        $owner = 'part-lock-' . $suffix;
        $bucket = 'part-lock-' . $suffix;
        $uploadId = bin2hex(random_bytes(16));
        $oldPath = 'parts/' . $suffix . '/old';
        $newPath = 'parts/' . $suffix . '/new';
        $admission->createBucket($owner, $bucket, 'us-east-1');
        $admission->createMultipartUploadWithTier($uploadId, $bucket, 'key', $owner, 'STANDARD');
        $generation = $admission->getBucket($bucket)?->generation;
        self::assertNotNull($generation);
        $admission->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $oldPath);
        $ready = new DeferredFuture();

        try {
            $commit = async(function () use ($committer, $ready, $owner, $bucket, $uploadId, $oldPath): void {
                $committer->transaction(function () use ($committer, $ready, $owner, $bucket, $uploadId, $oldPath): void {
                    $committer->lockOwnerForUpdate($owner);
                    $ready->complete();
                    delay(0.2);
                    if (! $committer->consumeMultipartPartWriteIntent($bucket, $uploadId, $oldPath)) {
                        throw new \RuntimeException('The staged part intent was not consumed.');
                    }
                });
            });
            $ready->getFuture()->await();
            $reserve = async(function () use ($admission, $bucket, $generation, $uploadId, $newPath): void {
                $admission->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $newPath);
            });
            [$errors] = awaitAll([$commit, $reserve]);
            self::assertSame([], array_map(static fn(\Throwable $error): string => $error::class . ': ' . $error->getMessage(), $errors));
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_storage_garbage WHERE upload_id = $1'
                : 'DELETE FROM s3_storage_garbage WHERE upload_id = ?', [$uploadId]);
            $admission->deleteMultipartUpload($uploadId);
            $admission->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_object_admission_does_not_wait_for_commit_owner_lock(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $admission = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $committer = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(ObjectWriteIntentStore::class, $admission);
        self::assertInstanceOf(ObjectWriteIntentStore::class, $committer);
        $suffix = bin2hex(random_bytes(6));
        $owner = 'object-lock-' . $suffix;
        $bucket = 'object-lock-' . $suffix;
        $admission->createBucket($owner, $bucket, 'us-east-1');
        $generation = $admission->getBucket($bucket)?->generation;
        self::assertNotNull($generation);
        $old = new ObjectWriteIntent(bin2hex(random_bytes(16)), $bucket, $generation, $owner, 'STANDARD', 'objects/' . $suffix . '/old', 'put');
        $new = new ObjectWriteIntent(bin2hex(random_bytes(16)), $bucket, $generation, $owner, 'STANDARD', 'objects/' . $suffix . '/new', 'put');
        $admission->reserveObjectWriteIntent($old, 60.0);
        $ready = new DeferredFuture();

        try {
            $commit = async(function () use ($committer, $ready, $owner, $old): void {
                $committer->transaction(function () use ($committer, $ready, $owner, $old): void {
                    $committer->lockOwnerForUpdate($owner);
                    $ready->complete();
                    delay(0.2);
                    if (! $committer->consumeObjectWriteIntent($old->attemptId)) {
                        throw new \RuntimeException('The staged object intent was not consumed.');
                    }
                });
            });
            $ready->getFuture()->await();
            $reserve = async(fn(): ObjectWriteIntent => $admission->reserveObjectWriteIntent($new, 60.0));
            [$errors] = awaitAll([$commit, $reserve]);
            self::assertSame([], array_map(static fn(\Throwable $error): string => $error::class . ': ' . $error->getMessage(), $errors));
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_object_write_intents WHERE owner_id = $1'
                : 'DELETE FROM s3_object_write_intents WHERE owner_id = ?', [$owner]);
            $admission->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    public function test_mysql_part_retirement_does_not_deadlock_with_admission_insert(): void
    {
        [$dsn, $pool] = self::database('mysql', 'S3_TEST_MYSQL_METADATA_DSN');
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        $retirer = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        self::assertInstanceOf(MysqlMetadataStore::class, $retirer);
        $suffix = bin2hex(random_bytes(8));
        $oldPath = 'retire-old-' . $suffix;
        $newPath = 'retire-new-' . $suffix;
        $pool->execute("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, next_attempt_at, dispatch_id)
            VALUES ('bucket', 'STANDARD', '{$oldPath}', 'part_write_intent', 0, NULL)");
        $tx = $pool->beginTransaction();
        try {
            $tx->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part' FOR UPDATE")->fetchRow();
            $retirement = async(fn(): int => $retirer->retireExpiredUndispatchedLocalMultipartPartWriteIntents('STANDARD', 1));
            delay(0.2);
            $tx->execute("INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, next_attempt_at, dispatch_id)
                VALUES ('bucket', 'STANDARD', '{$newPath}', 'part_write_intent', 9999999999, NULL)");
            $tx->commit();
            self::assertSame(1, $retirement->await());
        } finally {
            try {
                $tx->rollback();
            } catch (\Throwable) {
                // The transaction may already have committed or been deadlock-aborted.
            }
            $pool->execute("DELETE FROM s3_storage_garbage WHERE storage_path IN ('{$oldPath}', '{$newPath}')");
            $pool->close();
        }
    }

    public function test_mysql_object_retirement_does_not_deadlock_with_admission_insert(): void
    {
        [$dsn, $pool] = self::database('mysql', 'S3_TEST_MYSQL_METADATA_DSN');
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        $retirer = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        self::assertInstanceOf(MysqlMetadataStore::class, $retirer);
        $suffix = bin2hex(random_bytes(8));
        $oldId = 'retire-old-' . $suffix;
        $newId = 'retire-new-' . $suffix;
        $oldDigest = hash('sha256', $oldId);
        $newDigest = hash('sha256', $newId);
        $pool->execute("INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until, dispatch_id)
            VALUES ('{$oldId}', 'bucket', 'generation', 'owner', 'STANDARD', '{$oldId}', '{$oldDigest}', 'put', 0, NULL)");
        $tx = $pool->beginTransaction();
        try {
            $tx->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'object' FOR UPDATE")->fetchRow();
            $retirement = async(fn(): int => $retirer->retireExpiredUndispatchedObjectWriteIntents('STANDARD', 1));
            delay(0.2);
            $tx->execute("INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until, dispatch_id)
                VALUES ('{$newId}', 'bucket', 'generation', 'owner', 'STANDARD', '{$newId}', '{$newDigest}', 'put', 9999999999, NULL)");
            $tx->commit();
            self::assertSame(1, $retirement->await());
        } finally {
            try {
                $tx->rollback();
            } catch (\Throwable) {
                // The transaction may already have committed or been deadlock-aborted.
            }
            $pool->execute("DELETE FROM s3_object_write_intents WHERE attempt_id IN ('{$oldId}', '{$newId}')");
            $pool->close();
        }
    }

    /** @return array{string, PostgresConnectionPool|MysqlConnectionPool} */
    private static function database(string $driver, string $dsnEnv): array
    {
        $dsn = getenv($dsnEnv);
        if (! is_string($dsn) || preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn) !== 1) {
            self::markTestSkipped("Set {$dsnEnv} to a disposable s3audit_ metadata database.");
        }

        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));

        return [$dsn, $pool];
    }

    private static function capacity(PostgresConnectionPool|MysqlConnectionPool $pool, string $kind): int
    {
        return (int) ($pool->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = '{$kind}'")->fetchRow()['retained_count'] ?? -1);
    }

    private static function rowCount(PostgresConnectionPool|MysqlConnectionPool $pool, string $sql): int
    {
        return (int) ($pool->query($sql)->fetchRow()['n'] ?? -1);
    }

    private static function schemaVersion(PostgresConnectionPool|MysqlConnectionPool $pool): int
    {
        return (int) ($pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow()['version'] ?? -1);
    }

    /** @param \Closure(): mixed $write */
    private static function rejects(\Closure $write): void
    {
        try {
            $write();
        } catch (\Throwable $error) {
            self::assertStringContainsString('capacity', strtolower($error->getMessage()));

            return;
        }

        self::fail('The database accepted a write without recovery capacity.');
    }
}
