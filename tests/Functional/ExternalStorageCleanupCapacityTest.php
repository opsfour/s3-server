<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\DeferredFuture;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RecoveryStorageGarbageStore;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;
use function Amp\Future\awaitAll;

final class ExternalStorageCleanupCapacityTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_two_connections_serialize_normal_and_recovery_boundaries(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $first = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(RecoveryStorageGarbageStore::class, $first);
        self::assertInstanceOf(RecoveryStorageGarbageStore::class, $second);
        self::assertInstanceOf(StorageCleanupCapacityReader::class, $first);
        $bucket = 'cleanup-boundary-' . bin2hex(random_bytes(6));

        try {
            self::setCapacity($pool, 899_999);
            $normal = [
                async(fn(): string => self::normalAdmission($first, $bucket, 'normal-a')),
                async(fn(): string => self::normalAdmission($second, $bucket, 'normal-b')),
            ];
            $normalResults = [$normal[0]->await(), $normal[1]->await()];
            sort($normalResults);
            self::assertSame(['accepted', 'full'], $normalResults);
            self::assertSame(900_000, $first->getStorageCleanupCount());
            self::assertSame(1, self::bucketGarbageCount($driver, $pool, $bucket));

            self::deleteBucketGarbage($driver, $pool, $bucket);
            self::setCapacity($pool, 999_999);
            $recovery = [
                async(fn(): string => self::recoveryAdmission($first, $bucket, 'recovery-a')),
                async(fn(): string => self::recoveryAdmission($second, $bucket, 'recovery-b')),
            ];
            $recoveryResults = [$recovery[0]->await(), $recovery[1]->await()];
            sort($recoveryResults);
            self::assertSame(['accepted', 'full'], $recoveryResults);
            self::assertSame(1_000_000, $first->getStorageCleanupCount());
            self::assertSame(1, self::bucketGarbageCount($driver, $pool, $bucket));
        } finally {
            self::deleteBucketGarbage($driver, $pool, $bucket);
            self::restoreExactCapacity($pool);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_collector_deletion_unblocks_waiting_normal_admission_without_deadlock(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $collector = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $admitter = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $collector);
        $bucket = 'cleanup-collector-' . bin2hex(random_bytes(6));
        $collector->enqueueStorageGarbage($bucket, 'STANDARD', 'collected');
        $item = $collector->dequeueStorageGarbage(1)[0] ?? null;
        self::assertIsArray($item);
        self::assertSame($bucket, $item['bucket']);
        $generation = $item['lease_generation'] ?? null;
        self::assertIsInt($generation);
        self::setCapacity($pool, 900_000);
        $deleted = new DeferredFuture();

        try {
            $deletion = async(function () use ($collector, $item, $generation, $deleted): void {
                $collector->transaction(function () use ($collector, $item, $generation, $deleted): void {
                    if (! $collector->completeStorageGarbageLease($item['id'], $item['lease_expires_at'], $generation)) {
                        throw new \RuntimeException('Collector fixture could not delete its claimed cleanup row.');
                    }
                    $deleted->complete();
                    delay(0.2);
                });
            });
            $deleted->getFuture()->await();
            $admission = async(function () use ($admitter, $bucket): void {
                $admitter->enqueueStorageGarbage($bucket, 'STANDARD', 'replacement');
            });
            [$errors] = awaitAll([$deletion, $admission]);
            if (isset($errors[0])) {
                throw $errors[0];
            }
            if (isset($errors[1])) {
                if (! $errors[1] instanceof StorageCleanupCapacityException) {
                    throw $errors[1];
                }
                $admitter->enqueueStorageGarbage($bucket, 'STANDARD', 'replacement');
            }
            self::assertSame(900_000, self::cleanupCount($pool));
            self::assertSame(1, self::bucketGarbageCount($driver, $pool, $bucket));
        } finally {
            self::deleteBucketGarbage($driver, $pool, $bucket);
            self::restoreExactCapacity($pool);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_bulk_bucket_cleanup_rolls_back_rows_and_owning_bucket_mutation(string $driver, string $dsnEnv): void
    {
        [$dsn, $pool] = self::database($driver, $dsnEnv);
        $store = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'cleanup-bulk-' . $suffix;
        $owner = 'owner-' . $suffix;
        $upload = 'upload-' . $suffix;

        try {
            $store->createBucket($owner, $bucket, 'us-east-1');
            $store->createMultipartUpload($upload, $bucket, 'key', $owner);
            $store->putPart($upload, 1, 'etag', 1, 'parts/' . $suffix . '/1');
            $store->putPart($upload, 2, 'etag', 1, 'parts/' . $suffix . '/2');
            self::setCapacity($pool, 899_999);

            try {
                $store->transaction(function () use ($store, $owner, $bucket): void {
                    $store->stageBucketMultipartCleanup($bucket, 'STANDARD');
                    $store->deleteBucket($owner, $bucket);
                });
                self::fail('External bulk cleanup exceeded its one remaining normal slot.');
            } catch (StorageCleanupCapacityException) {
            }

            self::assertNotNull($store->getBucket($bucket));
            self::assertNotNull($store->getMultipartUpload($upload));
            self::assertCount(2, $store->getParts($upload));
            self::assertSame(0, self::bucketGarbageCount($driver, $pool, $bucket));
            self::assertSame(899_999, self::cleanupCount($pool));
        } finally {
            self::deleteBucketGarbage($driver, $pool, $bucket);
            self::restoreExactCapacity($pool);
            $store->deleteMultipartUpload($upload);
            if ($store->getBucket($bucket) !== null) {
                $store->deleteBucket($owner, $bucket);
            }
            $pool->close();
        }
    }

    public function test_mysql_operation_classification_is_exact_and_preserves_known_operations(): void
    {
        [, $pool] = self::database('mysql', 'S3_TEST_MYSQL_METADATA_DSN');
        self::assertInstanceOf(MysqlConnectionPool::class, $pool);
        $bucket = 'cleanup-operation-' . bin2hex(random_bytes(6));
        $cleanupBefore = self::cleanupCount($pool);
        $partBefore = self::partCount($pool);

        try {
            foreach (['delete_path', 'abort_multipart', 'part_write_intent'] as $operation) {
                $pool->execute(
                    'INSERT INTO s3_storage_garbage
                     (bucket, storage_tier, storage_path, operation, next_attempt_at)
                     VALUES (?, ?, ?, ?, 0)',
                    [$bucket, 'STANDARD', $bucket . '/' . $operation, $operation],
                );
            }
            self::assertSame($cleanupBefore + 2, self::cleanupCount($pool));
            self::assertSame($partBefore + 1, self::partCount($pool));

            try {
                $pool->execute(
                    'INSERT INTO s3_storage_garbage
                     (bucket, storage_tier, storage_path, operation, next_attempt_at)
                     VALUES (?, ?, ?, ?, 0)',
                    [$bucket, 'STANDARD', $bucket . '/noncanonical', 'PART_WRITE_INTENT'],
                );
                self::fail('MySQL accepted a noncanonical cleanup operation.');
            } catch (\Amp\Sql\SqlQueryError $error) {
                self::assertStringContainsString('check constraint', strtolower($error->getMessage()));
            }

            self::assertSame($cleanupBefore + 2, self::cleanupCount($pool));
            self::assertSame($partBefore + 1, self::partCount($pool));
        } finally {
            self::deleteBucketGarbage('mysql', $pool, $bucket);
            $pool->close();
        }
    }

    private static function normalAdmission(MetadataStore $store, string $bucket, string $path): string
    {
        try {
            $store->enqueueStorageGarbage($bucket, 'STANDARD', $path);
            return 'accepted';
        } catch (StorageCleanupCapacityException) {
            return 'full';
        }
    }

    private static function recoveryAdmission(RecoveryStorageGarbageStore $store, string $bucket, string $path): string
    {
        try {
            $store->enqueueRecoveryStorageGarbage($bucket, 'STANDARD', $path);
            return 'accepted';
        } catch (StorageCleanupCapacityException) {
            return 'full';
        }
    }

    /** @return array{string, PostgresConnectionPool|MysqlConnectionPool} */
    private static function database(string $driver, string $dsnEnv): array
    {
        $dsn = getenv($dsnEnv);
        if (! is_string($dsn) || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} cleanup capacity gate.");
        }
        $dsn = trim($dsn);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));

        return [$dsn, $pool];
    }

    private static function setCapacity(PostgresConnectionPool|MysqlConnectionPool $pool, int $count): void
    {
        $pool->execute("UPDATE s3_storage_cleanup_capacity SET retained_count = {$count} WHERE id = 1");
    }

    private static function restoreExactCapacity(PostgresConnectionPool|MysqlConnectionPool $pool): void
    {
        $pool->execute("UPDATE s3_storage_cleanup_capacity SET retained_count =
            (SELECT COUNT(*) FROM s3_storage_garbage WHERE operation <> 'part_write_intent') WHERE id = 1");
    }

    private static function cleanupCount(PostgresConnectionPool|MysqlConnectionPool $pool): int
    {
        return (int) ($pool->query('SELECT retained_count FROM s3_storage_cleanup_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1);
    }

    private static function partCount(MysqlConnectionPool $pool): int
    {
        return (int) ($pool->query("SELECT retained_count FROM s3_write_intent_capacity WHERE kind = 'part'")->fetchRow()['retained_count'] ?? -1);
    }

    private static function bucketGarbageCount(string $driver, PostgresConnectionPool|MysqlConnectionPool $pool, string $bucket): int
    {
        $row = $pool->execute(
            $driver === 'postgres'
                ? 'SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE bucket = $1'
                : 'SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE bucket = ?',
            [$bucket],
        )->fetchRow();

        return (int) ($row['n'] ?? -1);
    }

    private static function deleteBucketGarbage(string $driver, PostgresConnectionPool|MysqlConnectionPool $pool, string $bucket): void
    {
        $pool->execute(
            $driver === 'postgres'
                ? 'DELETE FROM s3_storage_garbage WHERE bucket = $1'
                : 'DELETE FROM s3_storage_garbage WHERE bucket = ?',
            [$bucket],
        );
    }
}
