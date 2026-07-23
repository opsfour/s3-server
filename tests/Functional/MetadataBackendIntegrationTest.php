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
                self::assertTrue(true);
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
    public function test_external_backend_migrates_v11_owner_lock_schema(string $driver, string $dsnEnv): void
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
            $pool->execute('DROP TABLE IF EXISTS s3_owner_write_locks');
            $pool->execute('DELETE FROM s3_schema_version WHERE version >= 11');
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES (11, 'Pre-v12 integration fixture')");

            $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $metadata->transaction(function () use ($metadata): void {
                $metadata->lockOwnerForUpdate('metadata-migration-owner');
            });

            $row = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertNotNull($row);
            self::assertSame(12, (int) $row['version']);
            self::assertNotNull(
                $pool->execute(
                    "SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = 'metadata-migration-owner'",
                )->fetchRow(),
            );
        } finally {
            // Restore a usable v12 schema even if an assertion above fails.
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
}
