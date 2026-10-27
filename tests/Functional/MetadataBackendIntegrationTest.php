<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Sql\SqlTransactionIsolationLevel;
use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\MalformedPolicyException;
use OpsFour\S3Server\Exception\InvalidArgumentException as S3InvalidArgumentException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Middleware\CurrentObjectReadAuthorizationMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\FencedMultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartFenceAdmissionStore;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\StorageGarbageLeaseStore;
use OpsFour\S3Server\Metadata\UtcMysqlConnector;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Metadata\OwnerReadLock;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MetadataBackendIntegrationTest extends TestCase
{
    public function test_mysql_tier_inventory_and_garbage_discard_are_byte_exact(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN to run MySQL byte-exact identity tests.');
        }

        $store = MetadataStoreFactory::create('mysql', ['dsn' => trim($dsn)]);
        $bucket = 'mysql-byte-' . bin2hex(random_bytes(8));
        $tiers = ['HOT', 'hot', 'HÓT', 'HOT '];
        $paths = ['Path', 'path'];
        $store->createBucket('owner', $bucket, 'us-east-1');
        try {
            foreach ($tiers as $index => $tier) {
                $store->enqueueStorageGarbage($bucket, $tier, "tier-{$index}");
            }
            foreach ($paths as $path) {
                $store->enqueueStorageGarbage($bucket, 'STANDARD', $path);
            }

            $referenced = $store->listReferencedStorageTiers();
            foreach ($tiers as $tier) {
                self::assertContains($tier, $referenced);
            }

            $store->discardStorageGarbage($bucket, 'STANDARD', 'Path');
            $pool = new MysqlConnectionPool(MysqlConfig::fromString(trim($dsn)));
            try {
                $result = $pool->execute(
                    'SELECT storage_path FROM s3_storage_garbage WHERE bucket = ? AND BINARY bucket = BINARY ? AND storage_tier = ? AND BINARY storage_tier = BINARY ? ORDER BY BINARY storage_path',
                    [$bucket, $bucket, 'STANDARD', 'STANDARD'],
                );
                $rows = [];
                foreach ($result as $row) {
                    $rows[] = $row;
                }
                self::assertSame([['storage_path' => 'path']], $rows);
            } finally {
                $pool->close();
            }
        } finally {
            foreach ($tiers as $index => $tier) {
                $store->discardStorageGarbage($bucket, $tier, "tier-{$index}");
            }
            foreach ($paths as $path) {
                $store->discardStorageGarbage($bucket, 'STANDARD', $path);
            }
            if ($store->getBucketOwner($bucket) === 'owner') {
                $store->deleteBucket('owner', $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_transaction_end_without_active_transaction_fails_and_store_remains_usable(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} transaction-boundary tests.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $store);
        foreach (['commit', 'rollback'] as $method) {
            try {
                \Amp\async(fn() => $store->{$method}())->await();
                self::fail("{$method}() without an active transaction must fail closed.");
            } catch (\LogicException $error) {
                self::assertStringContainsString('active transaction', $error->getMessage());
            }
        }
        try {
            \Amp\async(fn() => $store->lockOwnerForUpdate('owner'))->await();
            self::fail('Owner lock without an active transaction must fail closed.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('active transaction', $error->getMessage());
        }

        $store->transaction(static function () use ($store): void {
            $claims = [
                static fn() => $store->claimDueMultipartWriteIntents(1, 1.0, 2.0),
                static fn() => $store->dequeueTierTransitionJobs(1),
                static fn() => $store->dequeueRestoreJobs(1),
                static fn() => $store->dequeueNotifications(1),
                static fn() => $store->dequeueStorageGarbage(1),
            ];
            foreach ($claims as $claim) {
                try {
                    $claim();
                    self::fail('Queue claim inside an outer transaction must fail closed.');
                } catch (\LogicException $error) {
                    self::assertSame('Queue claims require their own committed transaction.', $error->getMessage());
                }
            }
        });

        $bucket = 'tx-end-' . bin2hex(random_bytes(8));
        try {
            $store->transaction(static function () use ($store, $bucket): void {
                $store->lockOwnerForUpdate('owner');
                $store->createBucket('owner', $bucket, 'us-east-1');
            });
            self::assertSame('owner', $store->getBucketOwner($bucket));
        } finally {
            if ($store->getBucketOwner($bucket) === 'owner') {
                $store->deleteBucket('owner', $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_rejected_nested_begin_keeps_outer_transaction_owned(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} transaction-boundary tests.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $before = 'tx-before-' . bin2hex(random_bytes(8));
        $after = 'tx-after-' . bin2hex(random_bytes(8));
        try {
            try {
                $store->transaction(function () use ($store, $before, $after): void {
                    $store->createBucket('owner', $before, 'us-east-1');
                    try {
                        $store->beginTransaction();
                        self::fail('Nested direct beginTransaction() must fail closed.');
                    } catch (\LogicException $error) {
                        self::assertStringContainsString('Nested beginTransaction()', $error->getMessage());
                    }
                    $store->createBucket('owner', $after, 'us-east-1');
                    throw new \RuntimeException('roll back owning transaction');
                });
            } catch (\RuntimeException $error) {
                self::assertSame('roll back owning transaction', $error->getMessage());
            }

            self::assertNull($store->getBucket($before));
            self::assertNull($store->getBucket($after));
        } finally {
            foreach ([$before, $after] as $bucket) {
                if ($store->getBucketOwner($bucket) === 'owner') {
                    $store->deleteBucket('owner', $bucket);
                }
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_generation_bound_multipart_listing_filters_before_pagination(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} multipart listing tests.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $store->initialize();
        self::assertInstanceOf(\OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore::class, $store);
        self::assertInstanceOf(\OpsFour\S3Server\Metadata\GenerationBoundBucketMultipartCleanupStore::class, $store);
        $bucketName = 'mpu-list-' . bin2hex(random_bytes(8));
        $store->createBucket('owner', $bucketName, 'us-east-1');
        $bucket = $store->getBucket($bucketName);
        self::assertNotNull($bucket);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)))
            : new MysqlConnectionPool(MysqlConfig::fromString(trim($dsn)));

        try {
            foreach ([
                ['stale', 'group/a/old', 'owner'],
                ['current-a', 'group/b/new', 'owner'],
                ['current-b', 'group/c/new', 'owner'],
                ['legacy-other', 'group/d/old', 'other'],
            ] as [$uploadId, $key, $owner]) {
                $store->createMultipartUpload($uploadId . '-' . $bucketName, $bucketName, $key, $owner);
            }
            $sql = $driver === 'postgres'
                ? 'UPDATE s3_multipart_uploads SET bucket_generation = $1 WHERE upload_id = $2'
                : 'UPDATE s3_multipart_uploads SET bucket_generation = ? WHERE upload_id = ?';
            $pool->execute($sql, [str_repeat('a', 32), 'stale-' . $bucketName]);
            $pool->execute($sql, ['', 'legacy-other-' . $bucketName]);
            $store->putPart('stale-' . $bucketName, 1, '"etag"', 1, 'stale-path-' . $bucketName);

            $first = $store->listMultipartUploadsForBucket($bucket, prefix: 'group/', delimiter: '/', maxUploads: 1);
            self::assertSame(['group/b/'], $first['commonPrefixes']);
            self::assertTrue($first['isTruncated']);
            $second = $store->listMultipartUploadsForBucket(
                $bucket,
                prefix: 'group/',
                delimiter: '/',
                maxUploads: 1,
                keyMarker: $first['nextKeyMarker'],
                uploadIdMarker: $first['nextUploadIdMarker'],
            );
            self::assertSame(['group/c/'], $second['commonPrefixes']);
            self::assertFalse($second['isTruncated']);
            $flat = $store->listMultipartUploadsForBucket($bucket, prefix: 'group/');
            self::assertSame(
                ['current-a-' . $bucketName, 'current-b-' . $bucketName],
                array_column($flat['uploads'], 'upload_id'),
            );
            try {
                $store->transaction(static function () use ($store, $bucket): void {
                    $store->stageBucketMultipartCleanupForBucket($bucket, 'STANDARD');
                });
                self::fail('Bulk cleanup accepted an upload from a prior bucket generation.');
            } catch (OperationAbortedException) {
                self::assertNotNull($store->getMultipartUpload('stale-' . $bucketName));
            }
        } finally {
            $store->deleteBucket('owner', $bucketName);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_local_multipart_dispatch_is_one_shot_and_legacy_blocks_admission(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} local multipart dispatch tests.");
        }
        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(FencedMultipartPartWriteIntentStore::class, $store);
        self::assertInstanceOf(LocalMultipartCompletionIntentStore::class, $store);
        self::assertInstanceOf(LocalMultipartFenceAdmissionStore::class, $store);
        $bucket = 'local-mpu-' . bin2hex(random_bytes(8));
        $uploadId = bin2hex(random_bytes(16));
        $store->createBucket('owner', $bucket, 'us-east-1');
        $bucketInfo = $store->getBucket($bucket);
        self::assertNotNull($bucketInfo);
        $store->createMultipartUpload($uploadId, $bucket, 'key', 'owner');
        $path = '/local-test/' . bin2hex(random_bytes(16));
        $first = bin2hex(random_bytes(16));
        $second = bin2hex(random_bytes(16));
        $attempt = bin2hex(random_bytes(16));
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)))
            : new MysqlConnectionPool(MysqlConfig::fromString(trim($dsn)));
        try {
            $store->reserveLocalMultipartPartWriteIntent($bucket, $bucketInfo->generation, 'STANDARD', $uploadId, $path);
            try {
                $store->reserveLocalMultipartPartWriteIntent($bucket, $bucketInfo->generation, 'STANDARD', $uploadId, $path);
                self::fail('The same local part path was reserved twice.');
            } catch (OperationAbortedException $error) {
                self::assertSame('Multipart part path has already been used.', $error->getMessage());
            }
            $store->assertNoLegacyLocalMultipartIntents('STANDARD');
            self::assertTrue($store->armLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $first));
            self::assertFalse($store->armLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $second));
            self::assertFalse($store->consumeMultipartPartWriteIntent($bucket, $uploadId, $path));
            self::assertFalse($store->consumeLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $second));
            self::assertTrue($store->consumeLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $first));

            $terminalPath = '/local-test/' . bin2hex(random_bytes(16));
            $store->reserveLocalMultipartPartWriteIntent($bucket, $bucketInfo->generation, 'STANDARD', $uploadId, $terminalPath);
            self::assertTrue($store->armLocalMultipartPartWriteIntent($bucket, $uploadId, $terminalPath, $first));
            self::assertFalse($store->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $terminalPath, $second));
            self::assertTrue($store->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $terminalPath, $first));
            self::assertFalse($store->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $terminalPath, $first));

            $referencedPath = '/local-test/' . bin2hex(random_bytes(16));
            $store->reserveLocalMultipartPartWriteIntent($bucket, $bucketInfo->generation, 'STANDARD', $uploadId, $referencedPath);
            self::assertTrue($store->armLocalMultipartPartWriteIntent($bucket, $uploadId, $referencedPath, $first));
            $store->putPart($uploadId, 2, '"etag"', 7, $referencedPath);
            self::assertFalse($store->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $referencedPath, $first));

            $store->createMultipartPartWriteIntent($bucket, $bucketInfo->generation, 'STANDARD', $uploadId, $path);
            try {
                $store->assertNoLegacyLocalMultipartIntents('STANDARD');
                self::fail('Legacy local multipart part intent was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Legacy multipart part intent', $error->getMessage());
            }
            self::assertTrue($store->consumeMultipartPartWriteIntent($bucket, $uploadId, $path));

            $now = $store->multipartWriteIntentNow();
            $store->createMultipartWriteIntent(new MultipartWriteIntent(
                attemptId: $attempt,
                uploadId: $uploadId,
                bucket: $bucket,
                bucketGeneration: $bucketInfo->generation,
                ownerId: 'owner',
                storageTier: 'STANDARD',
                assemblyPath: '/local-test/assembly',
                finalPath: '/local-test/final',
                partDigest: str_repeat('a', 64),
                sseMode: null,
                leaseUntil: $now + 300,
                nextAttemptAt: $now + 300,
            ));
            $pool->execute($driver === 'postgres'
                ? "UPDATE s3_multipart_write_intents SET dispatch_id = '00000000000000000000000000000000' WHERE attempt_id = $1"
                : "UPDATE s3_multipart_write_intents SET dispatch_id = '00000000000000000000000000000000' WHERE attempt_id = ?", [$attempt]);
            try {
                $store->assertNoLegacyLocalMultipartIntents('STANDARD');
                self::fail('Legacy local multipart completion intent was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Legacy multipart completion intent', $error->getMessage());
            }
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_multipart_write_intents SET dispatch_id = NULL WHERE attempt_id = $1'
                : 'UPDATE s3_multipart_write_intents SET dispatch_id = NULL WHERE attempt_id = ?', [$attempt]);
            self::assertTrue($store->armMultipartWriteIntent($attempt, $first, $now + 300, $now + 600));
            self::assertFalse($store->armMultipartWriteIntent($attempt, $second, $now + 600, $now + 900));
            self::assertFalse($store->consumeMultipartWriteIntent($attempt));
            self::assertFalse($store->consumeMultipartWriteIntentWithDispatch($attempt, $second));
            self::assertTrue($store->consumeMultipartWriteIntentWithDispatch($attempt, $first));

            $store->putPart($uploadId, 1, '"etag"', 7, $path);
            try {
                $store->reserveLocalMultipartPartWriteIntent($bucket, $bucketInfo->generation, 'STANDARD', $uploadId, $path);
                self::fail('A committed local part path was reserved again.');
            } catch (OperationAbortedException) {
                self::assertSame($path, $store->getParts($uploadId)[0]['storage_path']);
            }
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1'
                : 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = ?', [$attempt]);
            $pool->execute($driver === 'postgres'
                ? "DELETE FROM s3_storage_garbage WHERE bucket = $1 AND operation = 'part_write_intent'"
                : "DELETE FROM s3_storage_garbage WHERE bucket = ? AND operation = 'part_write_intent'", [$bucket]);
            $store->deleteMultipartUpload($uploadId);
            $store->deleteBucket('owner', $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_delete_stages_exact_path_before_inline_cleanup(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} delete cleanup tests.");
        }
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $metadata->initialize();
        $bucket = 'delete-garbage-' . bin2hex(random_bytes(8));
        $metadata->createBucket('owner', $bucket, 'us-east-1');
        $storage = new InMemoryBackend();
        $storage->createBucket($bucket);
        try {
            $write = $storage->putObject($bucket, 'key', new ReadableBuffer('recover-me'));
            $metadata->putObjectMetadata($bucket, 'key', 'owner', $write->size, '"' . $write->md5Hex . '"', 'text/plain', $write->path);
            $failing = new CallbackStorageBackend($storage, beforeDelete: static function (): never {
                throw new \RuntimeException('injected physical delete failure');
            });
            $request = new Request($this->createMock(Client::class), 'DELETE', Http::new('http://127.0.0.1/' . $bucket . '/key'));
            $request->setAttribute('s3.bucket', $bucket);
            $request->setAttribute('s3.key', 'key');
            $request->setAttribute('ownerId', 'owner');

            self::assertSame(204, (new DeleteObjectHandler($metadata, StorageTierRegistry::single($failing)))->handleRequest($request)->getStatus());
            self::assertNull($metadata->getObjectMetadata($bucket, 'key'));
            self::assertSame('recover-me', \Amp\ByteStream\buffer($storage->getObjectByPath($write->path)));
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage)))->collect(1));
            try {
                $storage->getObjectByPath($write->path);
                self::fail('Collector did not remove the queued physical path.');
            } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
                // The exact queued path was removed after the metadata delete.
            }
        } finally {
            $metadata->deleteBucket('owner', $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_collector_generation_upgrade_preserves_legacy_intents_and_garbage(string $driver, string $dsnEnv): void
    {
        if (getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Requires an isolated disposable metadata database.');
        }
        $dsn = getenv($dsnEnv);
        if ($dsn === false || preg_match('/(?:^|[ ;])dbname=s3audit_package(?:[ ;]|$)/', $dsn) !== 1) {
            self::markTestSkipped('Requires the isolated s3audit_package database.');
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $store->initialize();
        self::assertInstanceOf(MultipartWriteIntentStore::class, $store);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(8));
        $bucket = 'collector-upgrade-' . $suffix;
        $activeUpload = 'active-' . $suffix;
        $expiredUpload = 'expired-' . $suffix;
        $activeAttempt = str_repeat($suffix, 2);
        $expiredAttempt = str_repeat(strrev($suffix), 2);
        $garbagePath = 'garbage/' . $suffix;
        $latestVersion = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
        $store->createBucket('owner', $bucket, 'us-east-1');
        $bucketInfo = $store->getBucket($bucket);
        self::assertNotNull($bucketInfo);
        $store->createMultipartUpload($activeUpload, $bucket, 'active-key', 'owner');
        $store->createMultipartUpload($expiredUpload, $bucket, 'expired-key', 'owner');
        $now = microtime(true);
        foreach ([
            [$activeAttempt, $activeUpload, $now + 3600],
            [$expiredAttempt, $expiredUpload, $now - 1],
        ] as [$attemptId, $uploadId, $deadline]) {
            $store->createMultipartWriteIntent(new MultipartWriteIntent(
                attemptId: $attemptId,
                uploadId: $uploadId,
                bucket: $bucket,
                bucketGeneration: $bucketInfo->generation,
                ownerId: 'owner',
                storageTier: 'STANDARD',
                assemblyPath: 'assembly/' . $attemptId,
                finalPath: 'final/' . $attemptId,
                partDigest: str_repeat('a', 64),
                sseMode: null,
                leaseUntil: $deadline,
                nextAttemptAt: $deadline,
            ));
        }
        $store->enqueueStorageGarbage($bucket, 'STANDARD', $garbagePath);
        try {
            $pool->execute(
                $driver === 'postgres'
                ? 'UPDATE s3_storage_garbage SET status = $1, next_attempt_at = 0, lease_generation = 1, attempts = 2 WHERE bucket = $2 AND storage_path = $3'
                : 'UPDATE s3_storage_garbage SET status = ?, next_attempt_at = 0, lease_generation = 1, attempts = 2 WHERE bucket = ? AND storage_path = ?',
                ['processing', $bucket, $garbagePath],
            );
            $pool->execute('ALTER TABLE s3_multipart_write_intents DROP COLUMN claim_generation');
            $pool->execute('ALTER TABLE s3_multipart_write_intents DROP COLUMN dispatch_id');
            $pool->execute('ALTER TABLE s3_storage_garbage DROP COLUMN dispatch_id');
            $pool->execute('DELETE FROM s3_schema_version');
            $pool->execute(
                $driver === 'postgres'
                ? 'INSERT INTO s3_schema_version (version, description) VALUES ($1, $2)'
                : 'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
                [$driver === 'postgres' ? 32 : 34, 'Before collector generation fence'],
            );

            $migrated = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $migrated->initialize();
            self::assertInstanceOf(MultipartWriteIntentStore::class, $migrated);
            self::assertInstanceOf(FencedMultipartWriteIntentStore::class, $migrated);
            self::assertInstanceOf(FencedStorageGarbageStore::class, $migrated);
            $versionRow = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertSame($latestVersion, (int) ($versionRow['version'] ?? 0));
            $active = $migrated->getMultipartWriteIntent($activeAttempt);
            $expired = $migrated->getMultipartWriteIntent($expiredAttempt);
            self::assertNotNull($active);
            self::assertNotNull($expired);
            self::assertSame('active', $active->state);
            self::assertSame('active', $expired->state);
            self::assertSame(0, $active->claimGeneration);
            self::assertSame(0, $expired->claimGeneration);
            self::assertSame($bucketInfo->generation, $expired->bucketGeneration);

            $claimNow = $migrated->multipartWriteIntentNow();
            $firstClaim = $migrated->claimDueMultipartWriteIntents(1, $claimNow, $claimNow + 60);
            self::assertCount(1, $firstClaim);
            self::assertSame($expiredAttempt, $firstClaim[0]->attemptId);
            self::assertSame(1, $firstClaim[0]->claimGeneration);
            self::assertSame('active', $migrated->getMultipartWriteIntent($activeAttempt)?->state);
            self::assertTrue($migrated->retryMultipartCollectorClaim($expiredAttempt, $migrated->multipartWriteIntentNow() - 1, $firstClaim[0]->nextAttemptAt, 1));
            $reclaimNow = $migrated->multipartWriteIntentNow();
            $secondClaim = $migrated->claimDueMultipartWriteIntents(1, $reclaimNow, $reclaimNow + 60);
            self::assertCount(1, $secondClaim);
            self::assertSame(2, $secondClaim[0]->claimGeneration);
            self::assertFalse($migrated->renewMultipartCollectorClaim($expiredAttempt, $reclaimNow + 120, $secondClaim[0]->nextAttemptAt, 1));
            self::assertFalse($migrated->retryMultipartCollectorClaim($expiredAttempt, $reclaimNow + 120, $secondClaim[0]->nextAttemptAt, 1));

            $garbage = $migrated->dequeueStorageGarbage(1);
            self::assertCount(1, $garbage);
            self::assertSame($garbagePath, $garbage[0]['storage_path']);
            self::assertSame(2, $garbage[0]['attempts']);
            $generation = $garbage[0]['lease_generation'] ?? null;
            self::assertSame(2, $generation);
            self::assertFalse($migrated->completeStorageGarbageLease($garbage[0]['id'], $garbage[0]['lease_expires_at'], 1));
            self::assertTrue($migrated->completeStorageGarbageLease($garbage[0]['id'], $garbage[0]['lease_expires_at'], 2));
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_multipart_write_intents WHERE bucket = $1'
                : 'DELETE FROM s3_multipart_write_intents WHERE bucket = ?', [$bucket]);
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_storage_garbage WHERE bucket = $1 AND storage_path = $2'
                : 'DELETE FROM s3_storage_garbage WHERE bucket = ? AND storage_path = ?', [$bucket, $garbagePath]);
            $store->deleteMultipartUpload($activeUpload);
            $store->deleteMultipartUpload($expiredUpload);
            $store->deleteBucket('owner', $bucket);
            $pool->close();
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_collector_claim_renewal_is_generation_fenced(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} collector lease tests.");
        }
        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $first->initialize();
        self::assertInstanceOf(FencedMultipartWriteIntentStore::class, $first);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $first);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $first);
        self::assertInstanceOf(StorageGarbageLeaseStore::class, $first);
        self::assertInstanceOf(MultipartUploadTierStore::class, $first);
        self::assertInstanceOf(FencedMultipartWriteIntentStore::class, $second);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $second);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $second);
        $suffix = bin2hex(random_bytes(8));
        $bucket = 'collector-lease-' . $suffix;
        $upload = 'upload-' . $suffix;
        $attempt = str_repeat($suffix, 2);
        $first->createBucket('owner', $bucket, 'us-east-1');
        $bucketInfo = $first->getBucket($bucket);
        self::assertNotNull($bucketInfo);
        $first->createMultipartUploadWithTier($upload, $bucket, 'key', 'owner', 'STANDARD');
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)))
            : new MysqlConnectionPool(MysqlConfig::fromString(trim($dsn)));
        try {
            $now = $first->multipartWriteIntentNow();
            $first->createMultipartWriteIntent(new MultipartWriteIntent(
                attemptId: $attempt,
                uploadId: $upload,
                bucket: $bucket,
                bucketGeneration: $bucketInfo->generation,
                ownerId: 'owner',
                storageTier: 'STANDARD',
                assemblyPath: 'assembly/' . $attempt,
                finalPath: 'final/' . $attempt,
                partDigest: str_repeat('a', 64),
                sseMode: null,
                leaseUntil: $now - 1,
                nextAttemptAt: $now - 1,
            ));
            $claimed = $first->claimDueMultipartWriteIntents(1, $now, $now + 60);
            self::assertCount(1, $claimed);
            self::assertSame(1, $claimed[0]->claimGeneration);
            $renewedUntil = $first->multipartWriteIntentNow() + 120;
            self::assertTrue($first->renewMultipartCollectorClaim($attempt, $renewedUntil, $claimed[0]->nextAttemptAt, 1));
            self::assertFalse($second->retryMultipartCollectorClaim($attempt, $now + 180, $claimed[0]->nextAttemptAt, 1));
            self::assertSame([], $second->claimDueMultipartWriteIntents(1, $second->multipartWriteIntentNow(), $second->multipartWriteIntentNow() + 60));
            self::assertTrue($first->retryMultipartCollectorClaim($attempt, $first->multipartWriteIntentNow() - 1, $renewedUntil, 1));
            $reclaimedAt = $second->multipartWriteIntentNow();
            $reclaimed = $second->claimDueMultipartWriteIntents(1, $reclaimedAt, $reclaimedAt + 60);
            self::assertCount(1, $reclaimed);
            self::assertSame(2, $reclaimed[0]->claimGeneration);
            self::assertFalse($first->renewMultipartCollectorClaim($attempt, $reclaimedAt + 120, $reclaimed[0]->nextAttemptAt, 1));
            self::assertFalse($first->retryMultipartCollectorClaim($attempt, $reclaimedAt + 120, $reclaimed[0]->nextAttemptAt, 1));

            $first->enqueueStorageGarbage($bucket, 'STANDARD', 'garbage/' . $attempt);
            $garbage = $first->dequeueStorageGarbage(1);
            self::assertCount(1, $garbage);
            $item = $garbage[0];
            $leaseGeneration = $item['lease_generation'] ?? null;
            self::assertIsInt($leaseGeneration);
            $garbageUntil = $first->multipartWriteIntentNow() + 600;
            self::assertTrue($first->renewStorageGarbageLease($item['id'], $garbageUntil, $item['lease_expires_at'], $leaseGeneration));
            self::assertFalse($second->completeStorageGarbageLease($item['id'], $item['lease_expires_at'], $leaseGeneration));
            self::assertTrue($first->completeStorageGarbageLease($item['id'], $garbageUntil, $leaseGeneration));
        } finally {
            $sql = $driver === 'postgres'
                ? 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1'
                : 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = ?';
            $pool->execute($sql, [$attempt]);
            $first->deleteMultipartUpload($upload);
            $first->deleteBucket('owner', $bucket);
        }
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function externalMetadataDrivers(): iterable
    {
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_version_bookkeeping_preserves_last_modified_and_projects_noncurrent_time(
        string $driver,
        string $dsnEnv,
    ): void {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} version timestamp parity tests.");
        }

        $dsn = trim($dsn);
        if ($driver === 'mysql') {
            $connector = new UtcMysqlConnector();
            foreach ([1, 2] as $_) {
                $connection = $connector->connect(MysqlConfig::fromString($dsn));
                try {
                    self::assertSame('+00:00', $connection->query('SELECT @@session.time_zone AS time_zone')->fetchRow()['time_zone'] ?? null);
                } finally {
                    $connection->close();
                }
            }
        }
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $pool = self::externalPool($driver, $dsn);
        $suffix = bin2hex(random_bytes(8));
        $bucket = 'version-time-' . $suffix;
        $owner = 'owner-' . $suffix;
        $key = 'object';
        $created = false;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $created = true;
            $metadata->setBucketVersioning($bucket, 'Enabled');
            $first = $metadata->putObjectVersioned($bucket, $key, $owner, 1, 'first', 'text/plain', 'first');
            $fixedLastModified = new \DateTimeImmutable('2000-01-02T03:04:05Z');
            $pool->execute(
                $driver === 'mysql'
                    ? 'UPDATE s3_objects SET updated_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?'
                    : 'UPDATE s3_objects SET updated_at = $1::timestamptz WHERE bucket = $2 AND key_name = $3 AND version_id = $4',
                [
                    $fixedLastModified->format($driver === 'mysql' ? 'Y-m-d H:i:s' : DATE_ATOM),
                    $bucket,
                    $key,
                    $first,
                ],
            );
            $baseline = $metadata->getObjectMetadataByVersion($bucket, $key, $first);
            self::assertNotNull($baseline);
            self::assertSame($fixedLastModified->getTimestamp(), $baseline->lastModified->getTimestamp());

            $second = $metadata->putObjectVersioned($bucket, $key, $owner, 1, 'second', 'text/plain', 'second');
            $listed = array_values(array_filter(
                $metadata->listObjectVersions($bucket)['versions'],
                static fn($version): bool => $version->versionId === $first,
            ));
            self::assertCount(1, $listed);
            self::assertArrayHasKey('noncurrentAt', $listed[0]->systemMetadata);
            self::assertSame($baseline->lastModified->getTimestamp(), $listed[0]->lastModified->getTimestamp());

            $metadata->deleteObjectVersion($bucket, $key, $second);
            $promoted = $metadata->getObjectMetadataByVersion($bucket, $key, $first);
            self::assertNotNull($promoted);
            self::assertSame('1', $promoted->systemMetadata['isLatest']);
            self::assertArrayNotHasKey('noncurrentAt', $promoted->systemMetadata);
            self::assertSame($baseline->lastModified->getTimestamp(), $promoted->lastModified->getTimestamp());

            $metadata->setBucketVersioning($bucket, 'Suspended');
            $metadata->putObjectMetadata($bucket, $key, $owner, 1, 'null', 'text/plain', 'null');
            $afterNullWrite = $metadata->getObjectMetadataByVersion($bucket, $key, $first);
            self::assertNotNull($afterNullWrite);
            self::assertArrayHasKey('noncurrentAt', $afterNullWrite->systemMetadata);
            self::assertSame($baseline->lastModified->getTimestamp(), $afterNullWrite->lastModified->getTimestamp());

            $metadata->deleteObjectVersion($bucket, $key, 'null');
            $afterNullDeletion = $metadata->getObjectMetadataByVersion($bucket, $key, $first);
            self::assertNotNull($afterNullDeletion);
            self::assertSame('1', $afterNullDeletion->systemMetadata['isLatest']);
            self::assertSame($baseline->lastModified->getTimestamp(), $afterNullDeletion->lastModified->getTimestamp());

            self::assertSame('null', $metadata->deleteObjectVersioned($bucket, $key, $owner, true));
            $afterDeleteMarker = $metadata->getObjectMetadataByVersion($bucket, $key, $first);
            self::assertNotNull($afterDeleteMarker);
            self::assertArrayHasKey('noncurrentAt', $afterDeleteMarker->systemMetadata);
            self::assertSame($baseline->lastModified->getTimestamp(), $afterDeleteMarker->lastModified->getTimestamp());
            $markers = $metadata->listObjectVersions($bucket)['deleteMarkers'];
            self::assertCount(1, $markers);
            self::assertNull($markers[0]->versionId);
            self::assertSame('1', $markers[0]->systemMetadata['isLatest']);
        } finally {
            $pool->close();
            if ($created) {
                for ($attempt = 0; $attempt < 4; $attempt++) {
                    $entries = $metadata->listObjectVersions($bucket)['entries'] ?? [];
                    if ($entries === []) {
                        break;
                    }
                    foreach ($entries as $entry) {
                        $metadata->deleteObjectVersion($bucket, $entry->key, $entry->versionId ?? 'null');
                    }
                }
                $metadata->deleteBucket($owner, $bucket);
            }
        }
    }

    /** @return iterable<string, array{string, string, string}> */
    public static function externalReadLockScenarios(): iterable
    {
        foreach (self::externalMetadataDrivers() as $driver => [$name, $dsnEnv]) {
            yield $driver . ' object' => [$name, $dsnEnv, 'object'];
            yield $driver . ' policy' => [$name, $dsnEnv, 'policy'];
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_shared_authorization_reads_overlap_while_owner_write_waits(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} shared read-lock tests.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $writer = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $owner = 'shared-read-' . bin2hex(random_bytes(8));
        OwnerReadLock::prepare($first, $owner);
        OwnerReadLock::prepare($second, $owner);
        $firstHeld = new \Amp\DeferredFuture();
        $secondHeld = new \Amp\DeferredFuture();
        $releaseFirst = new \Amp\DeferredFuture();
        $releaseSecond = new \Amp\DeferredFuture();
        $firstRead = \Amp\async(static function () use ($first, $owner, $firstHeld, $releaseFirst): void {
            $first->transaction(static function () use ($first, $owner, $firstHeld, $releaseFirst): void {
                OwnerReadLock::acquire($first, $owner);
                $firstHeld->complete();
                $releaseFirst->getFuture()->await();
            });
        });
        $secondRead = null;
        $ownerWrite = null;

        try {
            $firstHeld->getFuture()->await(new \Amp\TimeoutCancellation(10));
            $secondRead = \Amp\async(static function () use ($second, $owner, $secondHeld, $releaseSecond): void {
                $second->transaction(static function () use ($second, $owner, $secondHeld, $releaseSecond): void {
                    OwnerReadLock::acquire($second, $owner);
                    $secondHeld->complete();
                    $releaseSecond->getFuture()->await();
                });
            });
            $secondHeld->getFuture()->await(new \Amp\TimeoutCancellation(10));
            self::assertFalse($firstRead->isComplete(), 'Second reader must overlap the first reader.');
            $ownerWrite = \Amp\async(static function () use ($writer, $owner): void {
                $writer->transaction(static function () use ($writer, $owner): void {
                    OwnerWriteLock::acquire($writer, $owner);
                });
            });
            \Amp\delay(0.05);
            self::assertFalse($ownerWrite->isComplete(), 'Exclusive writer must wait for both readers.');
            $releaseFirst->complete();
            $firstRead->await(new \Amp\TimeoutCancellation(10));
            \Amp\delay(0.05);
            self::assertFalse($ownerWrite->isComplete(), 'Exclusive writer must still wait for the second reader.');
        } finally {
            if (! $releaseFirst->getFuture()->isComplete()) {
                $releaseFirst->complete();
            }
            $releaseSecond->complete();
            try {
                $firstRead->await(new \Amp\TimeoutCancellation(10));
            } finally {
                try {
                    $secondRead?->await(new \Amp\TimeoutCancellation(10));
                } finally {
                    $ownerWrite?->await(new \Amp\TimeoutCancellation(10));
                }
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_shared_named_policy_reads_overlap_and_update_waits_for_both(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} shared named-policy tests.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $updater = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(SharedAuthorizationLockStore::class, $first);
        self::assertInstanceOf(SharedAuthorizationLockStore::class, $second);
        $name = 'shared-policy-' . bin2hex(random_bytes(8));
        $oldPolicy = '{"Statement":[]}';
        $newPolicy = '{"Statement":[{"Effect":"Deny","Action":"s3:GetObject","Resource":"*"}]}';
        $first->putNamedPolicy($name, $oldPolicy);
        $firstHeld = new \Amp\DeferredFuture();
        $secondHeld = new \Amp\DeferredFuture();
        $releaseFirst = new \Amp\DeferredFuture();
        $releaseSecond = new \Amp\DeferredFuture();
        $firstRead = \Amp\async(static function () use ($first, $name, $oldPolicy, $firstHeld, $releaseFirst): void {
            $first->transaction(static function () use ($first, $name, $oldPolicy, $firstHeld, $releaseFirst): void {
                self::assertEquals(
                    json_decode($oldPolicy, true, 512, JSON_THROW_ON_ERROR),
                    json_decode((string) ($first->lockNamedPoliciesForRead([$name])[$name] ?? ''), true, 512, JSON_THROW_ON_ERROR),
                );
                $firstHeld->complete();
                $releaseFirst->getFuture()->await();
            });
        });
        $secondRead = null;
        $update = null;

        try {
            try {
                $firstHeld->getFuture()->await(new \Amp\TimeoutCancellation(10));
                $secondRead = \Amp\async(static function () use ($second, $name, $oldPolicy, $secondHeld, $releaseSecond): void {
                    $second->transaction(static function () use ($second, $name, $oldPolicy, $secondHeld, $releaseSecond): void {
                        self::assertEquals(
                            json_decode($oldPolicy, true, 512, JSON_THROW_ON_ERROR),
                            json_decode((string) ($second->lockNamedPoliciesForRead([$name])[$name] ?? ''), true, 512, JSON_THROW_ON_ERROR),
                        );
                        $secondHeld->complete();
                        $releaseSecond->getFuture()->await();
                    });
                });
                $secondHeld->getFuture()->await(new \Amp\TimeoutCancellation(10));
                self::assertFalse($firstRead->isComplete(), 'Both named-policy readers must overlap.');
                $update = \Amp\async(static function () use ($updater, $name, $newPolicy): void {
                    $updater->putNamedPolicy($name, $newPolicy);
                });
                \Amp\delay(0.05);
                self::assertFalse($update->isComplete(), 'Policy update must wait for the shared readers.');
                $releaseFirst->complete();
                $firstRead->await(new \Amp\TimeoutCancellation(10));
                \Amp\delay(0.05);
                self::assertFalse($update->isComplete(), 'Policy update must still wait for the second reader.');
            } finally {
                if (! $releaseFirst->getFuture()->isComplete()) {
                    $releaseFirst->complete();
                }
                $releaseSecond->complete();
                try {
                    $firstRead->await(new \Amp\TimeoutCancellation(10));
                } finally {
                    try {
                        $secondRead?->await(new \Amp\TimeoutCancellation(10));
                    } finally {
                        $update?->await(new \Amp\TimeoutCancellation(10));
                    }
                }
            }
            self::assertEquals(
                json_decode($newPolicy, true, 512, JSON_THROW_ON_ERROR),
                json_decode((string) $updater->getNamedPolicy($name), true, 512, JSON_THROW_ON_ERROR),
            );
        } finally {
            $updater->deleteNamedPolicy($name);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_existing_owner_read_lock_preparation_does_not_wait_for_exclusive_writer(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} owner read-lock preparation tests.");
        }

        $writer = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $reader = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $owner = 'prepare-read-' . bin2hex(random_bytes(8));
        OwnerReadLock::prepare($writer, $owner);
        $writerHeld = new \Amp\DeferredFuture();
        $releaseWriter = new \Amp\DeferredFuture();
        $writerFuture = \Amp\async(static function () use ($writer, $owner, $writerHeld, $releaseWriter): void {
            $writer->transaction(static function () use ($writer, $owner, $writerHeld, $releaseWriter): void {
                OwnerWriteLock::acquire($writer, $owner);
                $writerHeld->complete();
                $releaseWriter->getFuture()->await();
            });
        });
        $prepareFuture = null;

        try {
            $writerHeld->getFuture()->await(new \Amp\TimeoutCancellation(10));
            $prepareFuture = \Amp\async(static fn() => OwnerReadLock::prepare($reader, $owner));
            $prepareFuture->await(new \Amp\TimeoutCancellation(2));
            self::assertTrue($prepareFuture->isComplete());
        } finally {
            $releaseWriter->complete();
            $writerFuture->await(new \Amp\TimeoutCancellation(10));
            $prepareFuture?->await(new \Amp\TimeoutCancellation(10));
        }
    }

    #[DataProvider('externalReadLockScenarios')]
    public function test_current_object_read_waits_for_owner_commit_before_final_authorization(
        string $driver,
        string $dsnEnv,
        string $change,
    ): void {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} read-lock tests.");
        }

        $writer = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $readerPool = null;
        if ($driver === 'mysql') {
            // Force the isolation where a plain SELECT before the owner lock
            // would leave subsequent authorization reads on a stale snapshot.
            $config = MysqlConfig::fromString(trim($dsn));
            $constructor = new \ReflectionMethod(MysqlConnectionPool::class, '__construct');
            $supportsIsolation = array_filter(
                $constructor->getParameters(),
                static fn(\ReflectionParameter $parameter): bool => $parameter->getName() === 'transactionIsolation',
            ) !== [];
            $arguments = $supportsIsolation
                ? [$config, MysqlConnectionPool::DEFAULT_MAX_CONNECTIONS, MysqlConnectionPool::DEFAULT_IDLE_TIMEOUT, null, SqlTransactionIsolationLevel::Repeatable]
                : [$config];
            $readerPool = (new \ReflectionClass(MysqlConnectionPool::class))->newInstanceArgs($arguments);
            self::assertInstanceOf(MysqlConnectionPool::class, $readerPool);
            $reader = new MysqlMetadataStore($readerPool);
            $reader->initialize();
        } else {
            $reader = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        }

        $bucket = 'read-lock-' . bin2hex(random_bytes(8));
        $owner = 'read-owner-' . bin2hex(random_bytes(8));
        $oldPath = 'objects/' . $bucket . '/old';
        $newPath = 'objects/' . $bucket . '/new';
        $writer->createBucket($owner, $bucket, 'us-east-1');
        $writer->putObjectMetadata($bucket, 'key', $owner, 3, '"old"', 'text/plain', $oldPath);
        $source = new ReadableIterableStream(['old']);
        $initialAuthorized = new \Amp\DeferredFuture();
        $writerPrepared = new \Amp\DeferredFuture();
        $handlerReached = new \Amp\DeferredFuture();
        $releaseWriter = new \Amp\DeferredFuture();
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/' . $bucket . '/key'));
        $request->setAttribute('s3.bucket', $bucket);
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', $owner);
        $readHandler = $this->createMock(RequestHandler::class);
        $readHandler->method('handleRequest')->willReturnCallback(static function () use ($handlerReached, $source): Response {
            $handlerReached->complete();

            return new Response(200, body: $source);
        });
        $policyTerminal = $this->createMock(RequestHandler::class);
        $policyTerminal->method('handleRequest')->willReturnCallback(static function (Request $request) use (
            $reader,
            $readHandler,
            $initialAuthorized,
            $writerPrepared,
        ): Response {
            $initialAuthorized->complete();
            $writerPrepared->getFuture()->await();

            return (new CurrentObjectReadAuthorizationMiddleware($reader))->handleRequest($request, $readHandler);
        });
        $readerFuture = \Amp\async(static fn(): Response => (new PolicyEnforcementMiddleware($reader))->handleRequest($request, $policyTerminal));
        $writerFuture = null;

        try {
            try {
                $initialAuthorized->getFuture()->await(new \Amp\TimeoutCancellation(10));
                $writerFuture = \Amp\async(static function () use (
                    $writer,
                    $owner,
                    $bucket,
                    $change,
                    $newPath,
                    $writerPrepared,
                    $releaseWriter,
                ): void {
                    try {
                        $writer->transaction(static function () use (
                            $writer,
                            $owner,
                            $bucket,
                            $change,
                            $newPath,
                            $writerPrepared,
                            $releaseWriter,
                        ): void {
                            OwnerWriteLock::acquire($writer, $owner);
                            if ($change === 'object') {
                                $writer->putObjectMetadata($bucket, 'key', $owner, 3, '"new"', 'text/plain', $newPath);
                            } else {
                                $writer->putBucketPolicy($bucket, json_encode([
                                    'Statement' => [[
                                        'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:GetObject',
                                        'Resource' => "arn:aws:s3:::{$bucket}/key",
                                    ]],
                                ], JSON_THROW_ON_ERROR));
                            }
                            $writerPrepared->complete();
                            $releaseWriter->getFuture()->await();
                        });
                    } catch (\Throwable $error) {
                        if (! $writerPrepared->getFuture()->isComplete()) {
                            $writerPrepared->error($error);
                        }
                        throw $error;
                    }
                });
                $handlerReached->getFuture()->await(new \Amp\TimeoutCancellation(10));
                \Amp\delay(0.05);
                self::assertFalse($readerFuture->isComplete(), 'The final read authorization must wait for the owner commit.');
            } finally {
                if (! $writerPrepared->getFuture()->isComplete()) {
                    $writerPrepared->error(new \RuntimeException('Read-lock test did not reach writer preparation.'));
                }
                $releaseWriter->complete();
                $writerFuture?->await();
            }

            try {
                $readerFuture->await(new \Amp\TimeoutCancellation(10));
                self::fail('Object or policy change after the initial snapshot must reject the response.');
            } catch (AccessDeniedException) {
                self::assertTrue($source->isClosed());
                if ($change === 'object') {
                    self::assertSame($newPath, $reader->getObjectMetadata($bucket, 'key')?->systemMetadata['storagePath']);
                } else {
                    self::assertNotNull($reader->getBucketPolicy($bucket));
                }
            }
        } finally {
            if ($writer->getBucketOwner($bucket) === $owner) {
                $writer->deleteBucketPolicy($bucket);
                $writer->deleteObjectMetadata($bucket, 'key');
                $writer->deleteBucket($owner, $bucket);
            }
            $readerPool?->close();
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_direct_identity_policy_writes_validate_before_replacing_rows(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} identity-policy write tests.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $store->initialize();
        $suffix = bin2hex(random_bytes(8));
        $owner = 'policy-validation-' . $suffix;
        $name = 'policy-validation-' . $suffix;
        $valid = '{"Statement":[{"Effect":"Allow","Action":"s3:GetObject","Resource":"*","Condition":{"StringEquals":{"aws:UserAgent":"client-a"}}}]}';
        $invalid = '{"Statement":[{"Effect":"Deny","Action":"s3:GetObject","Resource":"*","Condition":{"StringEquals":{"aws:UserAgent":[]}}}]}';

        try {
            $store->putAccountPolicy($owner, $valid);
            $store->putNamedPolicy($name, $valid);
            foreach ([
                fn() => $store->putAccountPolicy($owner, $invalid),
                fn() => $store->putNamedPolicy($name, $invalid),
            ] as $write) {
                try {
                    $write();
                    self::fail('Expected invalid identity policy to be rejected.');
                } catch (MalformedPolicyException) {
                }
            }
            self::assertEquals(json_decode($valid, true, 512, JSON_THROW_ON_ERROR), json_decode((string) $store->getAccountPolicy($owner), true, 512, JSON_THROW_ON_ERROR));
            self::assertEquals(json_decode($valid, true, 512, JSON_THROW_ON_ERROR), json_decode((string) $store->getNamedPolicy($name), true, 512, JSON_THROW_ON_ERROR));

            $store->putAccountPolicy($owner, '{"Statement":[]}');
            $store->putNamedPolicy($name, '{"Statement":[]}');
            self::assertEquals(['Statement' => []], json_decode((string) $store->getAccountPolicy($owner), true, 512, JSON_THROW_ON_ERROR));
            self::assertEquals(['Statement' => []], json_decode((string) $store->getNamedPolicy($name), true, 512, JSON_THROW_ON_ERROR));
        } finally {
            $store->deleteAccountPolicy($owner);
            $store->deleteNamedPolicy($name);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_metadata_rejects_a_newer_schema_version(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} schema-version compatibility tests.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)))
            : new MysqlConnectionPool(MysqlConfig::fromString(trim($dsn)));
        $currentVersion = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
        $futureVersion = $currentVersion + 1;
        $marker = 'Future-version fixture ' . bin2hex(random_bytes(6));
        $pool->execute(
            $driver === 'postgres'
                ? 'INSERT INTO s3_schema_version (version, description) VALUES ($1, $2)'
                : 'INSERT INTO s3_schema_version (version, description) VALUES (?, ?)',
            [$futureVersion, $marker],
        );

        try {
            try {
                $store->initialize();
                self::fail('The older package accepted a newer metadata schema.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString((string) $futureVersion, $error->getMessage());
                self::assertStringContainsString((string) $currentVersion, $error->getMessage());
            }
        } finally {
            $pool->execute(
                $driver === 'postgres'
                    ? 'DELETE FROM s3_schema_version WHERE description = $1'
                    : 'DELETE FROM s3_schema_version WHERE description = ?',
                [$marker],
            );
        }

        $store->initialize();
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_account_policy_writes_wait_for_owner_commit(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} account policy lock tests.");
        }

        $firstStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $secondStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $owner = 'policy-lock-' . bin2hex(random_bytes(8));
        $oldPolicy = '{"Statement":[]}';
        $newPolicy = '{"Statement":[{"Effect":"Deny","Action":"s3:PutObject","Resource":"*"}]}';
        $firstStore->putAccountPolicy($owner, $oldPolicy);

        try {
            foreach (['put', 'delete'] as $operation) {
                $lockHeld = new \Amp\DeferredFuture();
                $releaseLock = new \Amp\DeferredFuture();
                $writeStarted = new \Amp\DeferredFuture();
                $ownerCommit = \Amp\async(function () use ($firstStore, $owner, $lockHeld, $releaseLock): void {
                    $firstStore->transaction(function () use ($firstStore, $owner, $lockHeld, $releaseLock): void {
                        $firstStore->lockOwnerForUpdate($owner);
                        $lockHeld->complete();
                        $releaseLock->getFuture()->await();
                    });
                });
                $policyWrite = \Amp\async(function () use ($secondStore, $owner, $newPolicy, $operation, $lockHeld, $writeStarted): void {
                    $lockHeld->getFuture()->await();
                    $writeStarted->complete();
                    if ($operation === 'put') {
                        $secondStore->putAccountPolicy($owner, $newPolicy);
                    } else {
                        $secondStore->deleteAccountPolicy($owner);
                    }
                });

                try {
                    $writeStarted->getFuture()->await();
                    \Amp\delay(0.05);
                    self::assertFalse($policyWrite->isComplete(), "{$operation} must wait for the owner transaction.");
                } finally {
                    $releaseLock->complete();
                    $ownerCommit->await();
                    $policyWrite->await();
                }
                $storedPolicy = $secondStore->getAccountPolicy($owner);
                if ($operation === 'put') {
                    self::assertNotNull($storedPolicy);
                    self::assertEquals(
                        json_decode($newPolicy, true, 512, JSON_THROW_ON_ERROR),
                        json_decode($storedPolicy, true, 512, JSON_THROW_ON_ERROR),
                    );
                } else {
                    self::assertNull($storedPolicy);
                }
            }
        } finally {
            $firstStore->deleteAccountPolicy($owner);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_named_policy_update_and_delete_wait_for_commit_row_locks(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} named policy lock tests.");
        }

        $reader = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $updater = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $deleter = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(8));
        $firstName = 'named-a-' . $suffix;
        $secondName = 'named-b-' . $suffix;
        $oldPolicy = '{"Statement":[]}';
        $newPolicy = '{"Statement":[{"Effect":"Deny","Action":"s3:PutObject","Resource":"*"}]}';
        $reader->putNamedPolicy($firstName, $oldPolicy);
        $reader->putNamedPolicy($secondName, $oldPolicy);

        try {
            $rowsLocked = new \Amp\DeferredFuture();
            $releaseRows = new \Amp\DeferredFuture();
            $writersStarted = new \Amp\DeferredFuture();
            $commit = \Amp\async(function () use ($reader, $firstName, $secondName, $rowsLocked, $releaseRows): void {
                $reader->transaction(function () use ($reader, $firstName, $secondName, $rowsLocked, $releaseRows): void {
                    $locked = $reader->lockNamedPoliciesForUpdate([$secondName, $firstName]);
                    self::assertArrayHasKey($firstName, $locked);
                    self::assertArrayHasKey($secondName, $locked);
                    $rowsLocked->complete();
                    $releaseRows->getFuture()->await();
                });
            });
            $update = \Amp\async(function () use ($updater, $firstName, $newPolicy, $rowsLocked, $writersStarted): void {
                $rowsLocked->getFuture()->await();
                $writersStarted->complete();
                $updater->putNamedPolicy($firstName, $newPolicy);
            });
            $delete = \Amp\async(function () use ($deleter, $secondName, $rowsLocked): void {
                $rowsLocked->getFuture()->await();
                $deleter->deleteNamedPolicy($secondName);
            });

            try {
                $writersStarted->getFuture()->await();
                \Amp\delay(0.05);
                self::assertFalse($update->isComplete(), 'UPDATE must wait for the named-policy row lock.');
                self::assertFalse($delete->isComplete(), 'DELETE must wait for the named-policy row lock.');
            } finally {
                $releaseRows->complete();
                $commit->await();
                $update->await();
                $delete->await();
            }
            $storedPolicy = $reader->getNamedPolicy($firstName);
            self::assertNotNull($storedPolicy);
            self::assertEquals(
                json_decode($newPolicy, true, 512, JSON_THROW_ON_ERROR),
                json_decode($storedPolicy, true, 512, JSON_THROW_ON_ERROR),
            );
            self::assertNull($reader->getNamedPolicy($secondName));
        } finally {
            $reader->deleteNamedPolicy($firstName);
            $reader->deleteNamedPolicy($secondName);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_named_policy_lock_reads_current_row_after_earlier_transaction_snapshot(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} named policy snapshot tests.");
        }

        $reader = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $writer = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        if ($driver === 'mysql') {
            // Hosts can provide their own pool with stronger isolation than
            // Amp's READ COMMITTED default. Amp MySQL 3.0 predates the pool
            // isolation argument and keeps the server's REPEATABLE READ default.
            $config = MysqlConfig::fromString(trim($dsn));
            $constructor = new \ReflectionMethod(MysqlConnectionPool::class, '__construct');
            $supportsIsolation = array_filter(
                $constructor->getParameters(),
                static fn(\ReflectionParameter $parameter): bool => $parameter->getName() === 'transactionIsolation',
            ) !== [];
            $arguments = $supportsIsolation
                ? [$config, MysqlConnectionPool::DEFAULT_MAX_CONNECTIONS, MysqlConnectionPool::DEFAULT_IDLE_TIMEOUT, null, SqlTransactionIsolationLevel::Repeatable]
                : [$config];
            $pool = (new \ReflectionClass(MysqlConnectionPool::class))->newInstanceArgs($arguments);
            self::assertInstanceOf(MysqlConnectionPool::class, $pool);
            $reader = new MysqlMetadataStore($pool);
            $reader->initialize();
        }
        $name = 'named-snapshot-' . bin2hex(random_bytes(8));
        $oldPolicy = '{"Statement":[]}';
        $newPolicy = '{"Statement":[{"Effect":"Deny","Action":"s3:PutObject","Resource":"*"}]}';
        $reader->putNamedPolicy($name, $oldPolicy);

        try {
            $reader->transaction(function () use ($reader, $writer, $name, $newPolicy): void {
                // InnoDB REPEATABLE READ creates the transaction snapshot here.
                self::assertNotNull($reader->getNamedPolicy($name));
                $writer->putNamedPolicy($name, $newPolicy);
                // Use the current value returned by the locking read. A
                // separate plain SELECT would reuse the old InnoDB snapshot.
                $current = $reader->lockNamedPoliciesForUpdate([$name])[$name] ?? null;
                self::assertNotNull($current);
                self::assertEquals(
                    json_decode($newPolicy, true, 512, JSON_THROW_ON_ERROR),
                    json_decode($current, true, 512, JSON_THROW_ON_ERROR),
                );
            });
        } finally {
            $reader->deleteNamedPolicy($name);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_multipart_intent_migration_and_active_claim_fencing(
        string $driver,
        string $dsnVariable,
    ): void {
        if (getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Requires a disposable metadata database.');
        }
        $dsn = getenv($dsnVariable);
        if ($dsn === false || ! str_contains($dsn, 'dbname=s3audit_mpu')) {
            self::markTestSkipped('Requires the isolated s3audit_mpu database.');
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $store);
        $bucket = 'intent-migration-' . bin2hex(random_bytes(4));
        $store->createBucket('owner', $bucket, 'us-east-1');
        $store->createMultipartUpload('legacy-' . $bucket, $bucket, 'key', 'owner');

        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        try {
            $pool->execute('DROP TABLE IF EXISTS s3_multipart_write_intents');
            $pool->execute('ALTER TABLE s3_multipart_uploads DROP COLUMN bucket_generation');
            $pool->execute('DELETE FROM s3_schema_version');
            $previous = $driver === 'mysql' ? 24 : 22;
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES ({$previous}, 'Pre-intent fixture')");

            $migrated = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            self::assertInstanceOf(MultipartWriteIntentStore::class, $migrated);
            $tierIndex = $driver === 'mysql'
                ? $pool->execute("SELECT INDEX_NAME AS index_name FROM information_schema.statistics
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 's3_multipart_write_intents'
                    AND INDEX_NAME = 'idx_s3_multipart_intents_tier'")->fetchRow()
                : $pool->execute("SELECT indexname AS index_name FROM pg_indexes
                    WHERE tablename = 's3_multipart_write_intents'
                    AND indexname = 'idx_s3_multipart_intents_tier'")->fetchRow();
            self::assertSame('idx_s3_multipart_intents_tier', $tierIndex['index_name'] ?? null);
            self::assertSame('', $migrated->getMultipartUpload('legacy-' . $bucket)['bucket_generation'] ?? null);
            $current = $migrated->getBucket($bucket);
            self::assertNotNull($current);
            $uploadId = 'new-' . $bucket;
            $migrated->createMultipartUpload($uploadId, $bucket, 'key', 'owner');
            self::assertSame($current->generation, $migrated->getMultipartUpload($uploadId)['bucket_generation'] ?? null);

            $first = new MultipartWriteIntent(
                attemptId: bin2hex(random_bytes(16)),
                uploadId: $uploadId,
                bucket: $bucket,
                bucketGeneration: $current->generation,
                ownerId: 'owner',
                storageTier: 'STANDARD',
                assemblyPath: 'assembly/first',
                finalPath: 'final/first',
                partDigest: str_repeat('a', 64),
                sseMode: 'AES256',
                leaseUntil: microtime(true) - 1,
                nextAttemptAt: microtime(true) - 1,
            );
            $second = new MultipartWriteIntent(
                attemptId: bin2hex(random_bytes(16)),
                uploadId: $uploadId,
                bucket: $bucket,
                bucketGeneration: $current->generation,
                ownerId: 'owner',
                storageTier: 'STANDARD',
                assemblyPath: 'assembly/second',
                finalPath: 'final/second',
                partDigest: str_repeat('b', 64),
                sseMode: null,
                leaseUntil: microtime(true) + 100,
                nextAttemptAt: microtime(true) + 100,
            );
            $migrated->createMultipartWriteIntent($first);
            $otherNode = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            self::assertInstanceOf(MultipartWriteIntentStore::class, $otherNode);
            try {
                $otherNode->createMultipartWriteIntent($second);
                self::fail('Second node claimed an active multipart upload.');
            } catch (\Amp\Sql\SqlQueryError) {
                self::assertNull($otherNode->getMultipartWriteIntent($second->attemptId));
            }
            self::assertFalse($migrated->consumeMultipartWriteIntent($first->attemptId));
            self::assertNotNull($otherNode->getMultipartWriteIntent($first->attemptId));
            self::assertCount(1, $otherNode->claimDueMultipartWriteIntents(1, microtime(true), microtime(true) + 60));
            self::assertFalse($migrated->consumeMultipartWriteIntent($first->attemptId));
            $otherNode->createMultipartWriteIntent($second);
            self::assertTrue($otherNode->consumeMultipartWriteIntent($second->attemptId));
            $otherNode->createMultipartWriteIntent($second);
            self::assertSame(1, $migrated->abandonMultipartWriteIntentsForUpload($uploadId, microtime(true) - 1));
            self::assertSame('abandoned', $otherNode->getMultipartWriteIntent($second->attemptId)?->state);
            self::assertFalse($otherNode->consumeMultipartWriteIntent($second->attemptId));
            self::assertCount(1, $migrated->claimDueMultipartWriteIntents(2, microtime(true), microtime(true) + 60));

            // Upgrade a database that had already adopted the intent schema
            // before the tier index was introduced, retaining tombstones.
            $pool->execute($driver === 'mysql'
                ? 'ALTER TABLE s3_multipart_write_intents DROP INDEX idx_s3_multipart_intents_tier'
                : 'DROP INDEX idx_s3_multipart_intents_tier');
            $previousIndexVersion = $driver === 'mysql' ? 25 : 23;
            $pool->execute('DELETE FROM s3_schema_version');
            $pool->execute("INSERT INTO s3_schema_version (version, description)
                VALUES ({$previousIndexVersion}, 'Intent schema before tier index')");
            $indexed = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            self::assertInstanceOf(MultipartWriteIntentStore::class, $indexed);
            $version = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertSame($driver === 'mysql' ? MysqlSchema::VERSION : PostgresSchema::VERSION, (int) ($version['version'] ?? 0));
            self::assertSame('abandoned', $indexed->getMultipartWriteIntent($first->attemptId)?->state);
            $tierIndex = $driver === 'mysql'
                ? $pool->execute("SELECT INDEX_NAME AS index_name FROM information_schema.statistics
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 's3_multipart_write_intents'
                    AND INDEX_NAME = 'idx_s3_multipart_intents_tier'")->fetchRow()
                : $pool->execute("SELECT indexname AS index_name FROM pg_indexes
                    WHERE tablename = 's3_multipart_write_intents'
                    AND indexname = 'idx_s3_multipart_intents_tier'")->fetchRow();
            self::assertSame('idx_s3_multipart_intents_tier', $tierIndex['index_name'] ?? null);
            // Repeat from the immediately preceding schema with an existing
            // abandoned row; the owner-admission index must be added in place.
            $pool->execute($driver === 'mysql'
                ? 'ALTER TABLE s3_multipart_write_intents DROP INDEX idx_s3_multipart_intents_owner'
                : 'DROP INDEX idx_s3_multipart_intents_owner');
            $previousOwnerVersion = $driver === 'mysql' ? 28 : 26;
            $pool->execute('DELETE FROM s3_schema_version');
            $pool->execute("INSERT INTO s3_schema_version (version, description)
                VALUES ({$previousOwnerVersion}, 'Before completion owner index')");
            $ownerIndexed = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            self::assertInstanceOf(MultipartWriteIntentStore::class, $ownerIndexed);
            self::assertSame('abandoned', $ownerIndexed->getMultipartWriteIntent($first->attemptId)?->state);
            $ownerIndex = $driver === 'mysql'
                ? $pool->execute("SELECT INDEX_NAME AS index_name FROM information_schema.statistics
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 's3_multipart_write_intents'
                    AND INDEX_NAME = 'idx_s3_multipart_intents_owner'")->fetchRow()
                : $pool->execute("SELECT indexname AS index_name FROM pg_indexes
                    WHERE tablename = 's3_multipart_write_intents'
                    AND indexname = 'idx_s3_multipart_intents_owner'")->fetchRow();
            self::assertSame('idx_s3_multipart_intents_owner', $ownerIndex['index_name'] ?? null);
        } finally {
            $pool->execute('DELETE FROM s3_multipart_write_intents');
            $store->deleteMultipartUpload('legacy-' . $bucket);
            $store->deleteMultipartUpload('new-' . $bucket);
            $store->deleteBucket('owner', $bucket);
            $pool->close();
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_object_listing_rejects_invalid_continuation_tokens(
        string $driver,
        string $dsnVariable,
    ): void {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the disposable listing token test.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'listing-token-' . $suffix;
        $owner = 'owner-' . $suffix;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->putObjectMetadata($bucket, 'a', $owner, 1, 'a', 'text/plain', 'path/a');
            $metadata->putObjectMetadata($bucket, 'b', $owner, 1, 'b', 'text/plain', 'path/b');

            $first = $metadata->listObjects($bucket, maxKeys: 1);
            self::assertSame(['a'], array_map(static fn($object): string => $object->key, $first->objects));
            self::assertNotNull($first->nextContinuationToken);
            $second = $metadata->listObjects($bucket, maxKeys: 1, continuationToken: $first->nextContinuationToken);
            self::assertSame(['b'], array_map(static fn($object): string => $object->key, $second->objects));

            foreach (['@@@', '', 'YQ', base64_encode(str_repeat('a', 1025))] as $token) {
                try {
                    $metadata->listObjects($bucket, maxKeys: 0, continuationToken: $token);
                    self::fail('Malformed continuation token was accepted.');
                } catch (S3InvalidArgumentException $exception) {
                    self::assertSame('InvalidArgument', $exception->getErrorCode());
                }
            }
        } finally {
            $metadata->deleteObjectMetadata($bucket, 'a');
            $metadata->deleteObjectMetadata($bucket, 'b');
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_tier_job_provenance_migration_preserves_legacy_jobs_and_retries_partial_ddl(
        string $driver,
        string $dsnVariable,
    ): void {
        if (getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Set S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 for a disposable metadata database.');
        }
        $dsn = getenv($dsnVariable);
        if ($dsn === false || preg_match('/(?:^|[; ])dbname=s3audit_[a-z0-9_]+(?:[; ]|$)/i', $dsn) !== 1) {
            self::markTestSkipped('A disposable s3audit_ metadata database is required.');
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $bucket = 'legacy-tier-' . bin2hex(random_bytes(6));
        $jobId = $metadata->enqueueTierTransitionJob($bucket, 'old-key', null, 'hot', 'cold', 'GLACIER', 'old/path');
        $previousVersion = $driver === 'mysql' ? 23 : 21;
        $currentVersion = $driver === 'mysql' ? MysqlSchema::VERSION : PostgresSchema::VERSION;

        try {
            foreach (['origin', 'lifecycle_rule_id', 'expected_is_latest'] as $column) {
                $pool->execute("ALTER TABLE s3_tier_transition_jobs DROP COLUMN {$column}");
            }
            $pool->execute("DELETE FROM s3_schema_version WHERE version >= {$currentVersion}");
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES ({$previousVersion}, 'Pre-provenance fixture')");

            if ($driver === 'mysql') {
                // Simulate a process dying after MySQL committed only the first ADD COLUMN.
                $pool->execute("ALTER TABLE s3_tier_transition_jobs ADD COLUMN origin VARCHAR(16) NOT NULL DEFAULT 'legacy'");
            }

            $migrated = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $legacy = $migrated->getTierTransitionJob($jobId);
            self::assertSame('legacy', $legacy['origin'] ?? null);
            self::assertNull($legacy['lifecycleRuleId'] ?? null);
            self::assertNull($legacy['expectedIsLatest'] ?? null);
            self::assertSame('pending', $legacy['status'] ?? null);
            $versionRow = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertSame($currentVersion, (int) ($versionRow['version'] ?? 0));

            $newId = $migrated->enqueueTierTransitionJob($bucket, 'new-key', null, 'hot', 'cold', 'GLACIER', 'new/path');
            self::assertSame('direct', $migrated->getTierTransitionJob($newId)['origin'] ?? null);
            $pool->execute('DELETE FROM s3_tier_transition_jobs WHERE id = ' . (int) $newId);
        } finally {
            $pool->execute('DELETE FROM s3_tier_transition_jobs WHERE id = ' . (int) $jobId);
            $pool->close();
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_numeric_common_prefix_markers_resume_without_skips(
        string $driver,
        string $dsnVariable,
    ): void {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the disposable numeric-marker test.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'numeric-marker-' . $suffix;
        $owner = 'owner-' . $suffix;
        $versions = [];
        $uploadIds = [];

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->setBucketVersioning($bucket, 'Enabled');
            foreach (['9a', '9b', 'z'] as $key) {
                $versions[$key] = $metadata->putObjectVersioned($bucket, $key, $owner, 1, 'etag', 'text/plain', 'path/' . $key);
                $uploadIds[$key] = 'upload-' . $key . '-' . $suffix;
                $metadata->createMultipartUpload($uploadIds[$key], $bucket, $key, $owner);
            }

            $firstObjects = $metadata->listObjects($bucket, delimiter: '9', maxKeys: 1, startAfter: '10');
            self::assertSame(['9'], $firstObjects->commonPrefixes);
            self::assertTrue($firstObjects->isTruncated);
            self::assertNotNull($firstObjects->nextContinuationToken);
            $secondObjects = $metadata->listObjects($bucket, delimiter: '9', maxKeys: 1, continuationToken: $firstObjects->nextContinuationToken);
            self::assertSame(['z'], array_map(static fn($object): string => $object->key, $secondObjects->objects));
            self::assertSame([], $secondObjects->commonPrefixes);
            self::assertFalse($secondObjects->isTruncated);

            $firstVersions = $metadata->listObjectVersions($bucket, delimiter: '9', maxKeys: 1, keyMarker: '10');
            self::assertSame(['9'], $firstVersions['commonPrefixes']);
            self::assertTrue($firstVersions['isTruncated']);
            $secondVersions = $metadata->listObjectVersions(
                $bucket,
                delimiter: '9',
                maxKeys: 1,
                keyMarker: $firstVersions['nextKeyMarker'],
                versionIdMarker: $firstVersions['nextVersionIdMarker'],
            );
            self::assertSame(['z'], array_map(static fn($object): string => $object->key, $secondVersions['versions']));
            self::assertSame([], $secondVersions['commonPrefixes']);
            self::assertFalse($secondVersions['isTruncated']);

            $firstUploads = $metadata->listMultipartUploads($bucket, delimiter: '9', maxUploads: 1, keyMarker: '10');
            self::assertSame(['9'], $firstUploads['commonPrefixes']);
            self::assertTrue($firstUploads['isTruncated']);
            $secondUploads = $metadata->listMultipartUploads(
                $bucket,
                delimiter: '9',
                maxUploads: 1,
                keyMarker: $firstUploads['nextKeyMarker'],
                uploadIdMarker: $firstUploads['nextUploadIdMarker'],
            );
            self::assertSame(['z'], array_column($secondUploads['uploads'], 'key_name'));
            self::assertSame([], $secondUploads['commonPrefixes']);
            self::assertFalse($secondUploads['isTruncated']);
        } finally {
            foreach ($uploadIds as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            foreach ($versions as $key => $versionId) {
                $metadata->deleteObjectVersion($bucket, $key, $versionId);
            }
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_delimiter_pages_jump_over_a_common_prefix_larger_than_one_chunk(
        string $driver,
        string $dsnVariable,
    ): void {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the large delimiter-prefix test.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'delimiter-bound-' . $suffix;
        $owner = 'owner-' . $suffix;
        $keys = [];
        $uploadIds = [];

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            for ($number = 1; $number <= 520; $number++) {
                $keys[] = sprintf('group/a/%04d', $number);
            }
            $keys[] = 'group/b/item';

            foreach ($keys as $number => $key) {
                $metadata->putObjectMetadata($bucket, $key, $owner, 1, 'etag', 'text/plain', 'path/' . $number);
                $uploadId = 'upload-' . $number . '-' . $suffix;
                $metadata->createMultipartUpload($uploadId, $bucket, $key, $owner);
                $uploadIds[] = $uploadId;
            }

            $objects = $metadata->listObjects($bucket, prefix: 'group/', delimiter: '/', maxKeys: 1);
            self::assertSame(['group/a/'], $objects->commonPrefixes);
            self::assertSame(base64_encode('group/a/'), $objects->nextContinuationToken);
            $nextObjects = $metadata->listObjects($bucket, prefix: 'group/', delimiter: '/', maxKeys: 1, continuationToken: $objects->nextContinuationToken);
            self::assertSame(['group/b/'], $nextObjects->commonPrefixes);
            self::assertFalse($nextObjects->isTruncated);

            $versions = $metadata->listObjectVersions($bucket, prefix: 'group/', delimiter: '/', maxKeys: 1);
            self::assertSame(['group/a/'], $versions['commonPrefixes']);
            self::assertSame('group/a/', $versions['nextKeyMarker']);
            self::assertNull($versions['nextVersionIdMarker']);
            $nextVersions = $metadata->listObjectVersions($bucket, prefix: 'group/', delimiter: '/', maxKeys: 1, keyMarker: $versions['nextKeyMarker']);
            self::assertSame(['group/b/'], $nextVersions['commonPrefixes']);
            self::assertFalse($nextVersions['isTruncated']);

            $uploads = $metadata->listMultipartUploads($bucket, prefix: 'group/', delimiter: '/', maxUploads: 1);
            self::assertSame(['group/a/'], $uploads['commonPrefixes']);
            self::assertSame('group/a/', $uploads['nextKeyMarker']);
            self::assertNull($uploads['nextUploadIdMarker']);
            $nextUploads = $metadata->listMultipartUploads($bucket, prefix: 'group/', delimiter: '/', maxUploads: 1, keyMarker: $uploads['nextKeyMarker']);
            self::assertSame(['group/b/'], $nextUploads['commonPrefixes']);
            self::assertFalse($nextUploads['isTruncated']);
        } finally {
            foreach ($uploadIds as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            foreach ($keys as $key) {
                $metadata->deleteObjectMetadata($bucket, $key);
            }
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_same_key_multipart_pages_follow_initiation_time(
        string $driver,
        string $dsnVariable,
    ): void {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the same-key multipart pagination test.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'multipart-order-' . $suffix;
        $owner = 'owner-' . $suffix;
        $uploadIds = ['z-earliest-' . $suffix, 'b-tie-' . $suffix, 'a-tie-' . $suffix];

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            foreach ($uploadIds as $uploadId) {
                $metadata->createMultipartUpload($uploadId, $bucket, 'same-key', $owner);
            }
            $pool = self::externalPool($driver, $dsn);
            try {
                foreach ([
                    [$uploadIds[0], '2024-01-01 00:00:00'],
                    [$uploadIds[1], '2024-01-02 00:00:00'],
                    [$uploadIds[2], '2024-01-02 00:00:00'],
                ] as [$uploadId, $createdAt]) {
                    $pool->execute(
                        $driver === 'mysql'
                            ? 'UPDATE s3_multipart_uploads SET created_at = ? WHERE upload_id = ?'
                            : 'UPDATE s3_multipart_uploads SET created_at = $1 WHERE upload_id = $2',
                        [$createdAt, $uploadId],
                    );
                }
            } finally {
                $pool->close();
            }

            foreach ([null, '/'] as $delimiter) {
                $listed = [];
                $keyMarker = null;
                $uploadIdMarker = null;
                do {
                    $page = $metadata->listMultipartUploads(
                        $bucket,
                        delimiter: $delimiter,
                        maxUploads: 1,
                        keyMarker: $keyMarker,
                        uploadIdMarker: $uploadIdMarker,
                    );
                    array_push($listed, ...array_column($page['uploads'], 'upload_id'));
                    $keyMarker = $page['nextKeyMarker'];
                    $uploadIdMarker = $page['nextUploadIdMarker'];
                } while ($page['isTruncated']);

                self::assertSame([$uploadIds[0], $uploadIds[2], $uploadIds[1]], $listed);
            }
        } finally {
            foreach ($uploadIds as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_listing_preserves_missing_upload_marker_and_version_wire_order(
        string $driver,
        string $dsnVariable,
    ): void {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the disposable listing ordering test.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'listing-order-' . $suffix;
        $owner = 'owner-' . $suffix;
        $uploadIds = [
            'upload-a-' . $suffix,
            'upload-m-' . $suffix,
            'upload-z-' . $suffix,
            'upload-other-' . $suffix,
        ];
        $versionId = null;
        $deleteMarkerId = null;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            foreach (array_slice($uploadIds, 0, 3) as $uploadId) {
                $metadata->createMultipartUpload($uploadId, $bucket, 'same-key', $owner);
            }
            $metadata->createMultipartUpload($uploadIds[3], $bucket, 'z-next-key', $owner);

            $uploads = $metadata->listMultipartUploads(
                $bucket,
                keyMarker: 'same-key',
                uploadIdMarker: 'upload-l-' . $suffix,
            );
            self::assertSame(
                [$uploadIds[1], $uploadIds[2], $uploadIds[3]],
                array_column($uploads['uploads'], 'upload_id'),
            );

            $metadata->setBucketVersioning($bucket, 'Enabled');
            $versionId = $metadata->putObjectVersioned($bucket, 'versioned-key', $owner, 1, '"etag"', 'text/plain', 'path');
            $deleteMarkerId = $metadata->deleteObjectVersioned($bucket, 'versioned-key', $owner);
            $versions = $metadata->listObjectVersions($bucket);
            $entries = $versions['entries'] ?? [];

            self::assertSame(
                [$deleteMarkerId, $versionId],
                array_map(static fn($entry): ?string => $entry->versionId, $entries),
            );
            self::assertSame(
                [true, false],
                array_map(static fn($entry): bool => $entry->isDeleteMarker, $entries),
            );
        } finally {
            foreach ($uploadIds as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            if ($deleteMarkerId !== null) {
                $metadata->deleteObjectVersion($bucket, 'versioned-key', $deleteMarkerId);
            }
            if ($versionId !== null) {
                $metadata->deleteObjectVersion($bucket, 'versioned-key', $versionId);
            }
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_suspended_null_replacement_retains_numbered_versions_on_external_metadata(
        string $driver,
        string $dsnVariable,
    ): void {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the disposable suspended-versioning metadata test.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'suspended-null-' . $suffix;
        $owner = 'owner-' . $suffix;
        $key = 'same-key.txt';
        $versions = [];

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->setBucketVersioning($bucket, 'Enabled');
            $versions[] = $metadata->putObjectVersioned($bucket, $key, $owner, 2, 'v1', 'text/plain', 'path/v1');

            $metadata->setBucketVersioning($bucket, 'Suspended');
            $metadata->putObjectMetadata($bucket, $key, $owner, 3, 'null1', 'text/plain', 'path/null1');
            $nullAcl = $bucket . '/' . $key . '?versionId=null';
            $metadata->putObjectRetention($bucket, $key, 'COMPLIANCE', '2030-01-01T00:00:00Z', 'null');
            $metadata->putObjectLegalHold($bucket, $key, 'ON', 'null');
            $metadata->putObjectTagging($bucket, $key, [['key' => 'old', 'value' => 'yes']], 'null');
            $metadata->putAcl('object', $nullAcl, $owner, [
                ['granteeType' => 'CanonicalUser', 'granteeId' => $owner, 'permission' => 'FULL_CONTROL'],
            ]);
            $metadata->setBucketVersioning($bucket, 'Enabled');
            $versions[] = $metadata->putObjectVersioned($bucket, $key, $owner, 4, 'v2', 'text/plain', 'path/v2');

            $metadata->setBucketVersioning($bucket, 'Suspended');
            $metadata->putObjectMetadata($bucket, $key, $owner, 5, 'null2', 'text/plain', 'path/null2');
            self::assertSame('path/null2', $metadata->getObjectMetadata($bucket, $key)?->systemMetadata['storagePath']);
            self::assertSame('path/null2', $metadata->getObjectMetadataByVersion($bucket, $key, 'null')?->systemMetadata['storagePath']);
            self::assertSame('path/v1', $metadata->getObjectMetadataByVersion($bucket, $key, $versions[0])?->systemMetadata['storagePath']);
            self::assertSame('path/v2', $metadata->getObjectMetadataByVersion($bucket, $key, $versions[1])?->systemMetadata['storagePath']);
            self::assertSame(['objectCount' => 3, 'bytesUsed' => 11], $metadata->getBucketStorageStats($bucket));
            $firstPage = $metadata->listObjectVersions($bucket, maxKeys: 1);
            self::assertSame('path/null2', $firstPage['versions'][0]->systemMetadata['storagePath']);
            self::assertTrue($firstPage['isTruncated']);
            self::assertSame($key, $firstPage['nextKeyMarker']);
            self::assertSame('null', $firstPage['nextVersionIdMarker']);
            $secondPage = $metadata->listObjectVersions(
                $bucket,
                maxKeys: 1,
                keyMarker: $firstPage['nextKeyMarker'],
                versionIdMarker: $firstPage['nextVersionIdMarker'],
            );
            self::assertSame('path/v2', $secondPage['versions'][0]->systemMetadata['storagePath']);
            self::assertNull($metadata->getObjectRetention($bucket, $key, 'null'));
            self::assertNull($metadata->getObjectLegalHold($bucket, $key, 'null'));
            self::assertSame([], $metadata->getObjectTagging($bucket, $key, 'null'));
            self::assertSame([], $metadata->getAcl('object', $nullAcl));

            $metadata->deleteObjectVersioned($bucket, $key, $owner, suspended: true);
            self::assertTrue($metadata->getObjectMetadataByVersion($bucket, $key, 'null')->isDeleteMarker);
            self::assertSame('path/v1', $metadata->getObjectMetadataByVersion($bucket, $key, $versions[0])->systemMetadata['storagePath']);
            self::assertSame('path/v2', $metadata->getObjectMetadataByVersion($bucket, $key, $versions[1])->systemMetadata['storagePath']);
            self::assertSame(['objectCount' => 2, 'bytesUsed' => 6], $metadata->getBucketStorageStats($bucket));
        } finally {
            foreach ($versions as $versionId) {
                $metadata->deleteObjectVersion($bucket, $key, $versionId);
            }
            $metadata->deleteObjectVersion($bucket, $key, 'null');
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    public function test_mysql_account_and_named_policy_identifiers_are_byte_exact(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN for the disposable MySQL identity test.');
        }

        $metadata = MetadataStoreFactory::create('mysql', ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $upperOwner = 'Account-' . $suffix;
        $lowerOwner = 'account-' . $suffix;
        $upperBucket = 'identity-upper-' . $suffix;
        $lowerBucket = 'identity-lower-' . $suffix;
        $upperPolicy = 'Policy-' . $suffix;
        $lowerPolicy = 'policy-' . $suffix;

        try {
            $metadata->createBucket($upperOwner, $upperBucket, 'us-east-1');
            $metadata->createBucket($lowerOwner, $lowerBucket, 'us-east-1');
            self::assertSame([$upperBucket], array_map(static fn($bucket): string => $bucket->name, $metadata->listBuckets($upperOwner)));
            self::assertSame([$lowerBucket], array_map(static fn($bucket): string => $bucket->name, $metadata->listBuckets($lowerOwner)));
            self::assertSame(1, $metadata->countBuckets($upperOwner));
            self::assertSame(1, $metadata->countBuckets($lowerOwner));

            $metadata->putObjectMetadata($upperBucket, 'upper.txt', $upperOwner, 7, 'etag', 'text/plain', 'upper/path');
            $metadata->putObjectMetadata($lowerBucket, 'lower.txt', $lowerOwner, 11, 'etag', 'text/plain', 'lower/path');
            self::assertSame(7, $metadata->getOwnerStorageStats($upperOwner)['bytesUsed']);
            self::assertSame(11, $metadata->getOwnerStorageStats($lowerOwner)['bytesUsed']);

            $metadata->putAccountQuota($upperOwner, new QuotaConfig(maxBucketsPerOwner: 1));
            self::assertNull($metadata->getAccountQuota($lowerOwner));
            $metadata->putAccountPolicy($upperOwner, '{"Statement":[]}');
            self::assertNull($metadata->getAccountPolicy($lowerOwner));
            $metadata->putNamedPolicy($upperPolicy, '{"Statement":[]}');
            self::assertNull($metadata->getNamedPolicy($lowerPolicy));
        } finally {
            $metadata->deleteObjectMetadata($upperBucket, 'upper.txt');
            $metadata->deleteObjectMetadata($lowerBucket, 'lower.txt');
            $metadata->deleteBucket($upperOwner, $upperBucket);
            $metadata->deleteBucket($lowerOwner, $lowerBucket);
            $metadata->deleteAccountQuota($upperOwner);
            $metadata->deleteAccountPolicy($upperOwner);
            $metadata->deleteNamedPolicy($upperPolicy);
        }
    }

    public function test_mysql_multipart_upload_ids_are_byte_exact(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN for the disposable MySQL multipart ID test.');
        }

        $metadata = MetadataStoreFactory::create('mysql', ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'upload-case-' . $suffix;
        $uploadId = 'upload-' . $suffix;
        $otherCase = strtoupper($uploadId);
        $owner = 'owner-' . $suffix;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->createMultipartUpload($uploadId, $bucket, 'key', $owner, 'text/plain', []);
            self::assertNull($metadata->getMultipartUpload($otherCase));
            self::assertSame($uploadId, $metadata->getMultipartUpload($uploadId)['upload_id'] ?? null);
            $metadata->putPart($uploadId, 1, 'etag', 7, 'test/path');
            self::assertSame([], $metadata->getParts($otherCase));
            $metadata->deleteParts($otherCase);
            self::assertCount(1, $metadata->getParts($uploadId));
            $metadata->deleteMultipartUpload($otherCase);
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $metadata->getParts($uploadId));
        } finally {
            $metadata->deleteMultipartUpload($uploadId);
            $metadata->deleteBucket($owner, $bucket);
        }
    }

    public function test_mysql_v22_owner_identity_migration_preserves_data_and_retries(): void
    {
        if (getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Set S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 for a disposable MySQL database.');
        }
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || preg_match('/(?:^|;)dbname=s3audit_[a-z0-9_]+(?:;|$)/i', $dsn) !== 1) {
            self::markTestSkipped('A disposable s3audit_ MySQL database is required.');
        }

        $metadata = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $upperOwner = 'Account-' . $suffix;
        $lowerOwner = 'account-' . $suffix;
        $bucket = 'identity-migration-' . $suffix;
        $upperPolicy = 'Policy-' . $suffix;
        $lowerPolicy = 'policy-' . $suffix;

        try {
            $metadata->createBucket($upperOwner, $bucket, 'us-east-1');
            $metadata->putObjectMetadata($bucket, 'object.txt', $upperOwner, 7, 'etag', 'text/plain', 'object/path');
            $metadata->putAccountQuota($upperOwner, new QuotaConfig(maxBucketsPerOwner: 2));
            $metadata->putNamedPolicy($upperPolicy, '{"Statement":[]}');

            foreach ([
                's3_buckets' => 'owner_id',
                's3_objects' => 'owner_id',
                's3_account_quotas' => 'owner_id',
                's3_named_policies' => 'policy_name',
            ] as $table => $column) {
                $pool->execute("ALTER TABLE {$table} MODIFY {$column} VARCHAR(255) COLLATE utf8mb4_unicode_ci NOT NULL");
            }
            $pool->execute('DELETE FROM s3_schema_version WHERE version >= 23');
            self::assertSame([$bucket], array_map(static fn($item): string => $item->name, $metadata->listBuckets($lowerOwner)));
            self::assertSame(7, $metadata->getOwnerStorageStats($lowerOwner)['bytesUsed']);
            self::assertNotNull($metadata->getAccountQuota($lowerOwner));
            self::assertNotNull($metadata->getNamedPolicy($lowerPolicy));

            $migrated = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
            self::assertSame([], $migrated->listBuckets($lowerOwner));
            self::assertSame(0, $migrated->getOwnerStorageStats($lowerOwner)['bytesUsed']);
            self::assertNull($migrated->getAccountQuota($lowerOwner));
            self::assertNull($migrated->getNamedPolicy($lowerPolicy));
            self::assertSame(7, $migrated->getObjectMetadata($bucket, 'object.txt')?->size);

            $pool->execute('DELETE FROM s3_schema_version WHERE version >= 23');
            $retried = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
            self::assertSame([], $retried->listBuckets($lowerOwner));
            self::assertSame(7, $retried->getOwnerStorageStats($upperOwner)['bytesUsed']);
        } finally {
            MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
            $metadata->deleteObjectMetadata($bucket, 'object.txt');
            $metadata->deleteBucket($upperOwner, $bucket);
            $metadata->deleteAccountQuota($upperOwner);
            $metadata->deleteNamedPolicy($upperPolicy);
            $pool->close();
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_staged_put_cannot_publish_after_bucket_recreation_on_another_connection(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} bucket-generation race tests.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $bucket = 'put-race-' . bin2hex(random_bytes(8));
        $oldOwner = 'old-' . bin2hex(random_bytes(4));
        $newOwner = 'new-' . bin2hex(random_bytes(4));
        $first->createBucket($oldOwner, $bucket, 'us-east-1');
        $storage = $this->createMock(StorageBackend::class);
        $stagedPath = 'memory://' . $bucket . '/staged';
        $storage->method('putObject')->willReturnCallback(function () use ($second, $oldOwner, $newOwner, $bucket, $stagedPath): StorageWriteResult {
            $second->deleteBucket($oldOwner, $bucket);
            $second->createBucket($newOwner, $bucket, 'us-east-1');

            return new StorageWriteResult($stagedPath, 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')->with($stagedPath, $bucket);
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/' . $bucket . '/key'), [], 'payload');
        $request->setAttribute('s3.bucket', $bucket);
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', $oldOwner);

        try {
            (new PutObjectHandler($first, $storage))->handleRequest($request);
            self::fail('A staged write must not commit into the recreated bucket.');
        } catch (OperationAbortedException) {
            self::assertSame($newOwner, $second->getBucketOwner($bucket));
            self::assertNull($second->getObjectMetadata($bucket, 'key'));
        } finally {
            if ($second->getBucketOwner($bucket) === $newOwner) {
                $second->deleteBucket($newOwner, $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_restored_copy_tier_persists_and_legacy_fails_closed(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} restored-copy tier tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(\OpsFour\S3Server\Metadata\RestoredCopyTierStore::class, $metadata);
        $bucket = 'restore-tier-' . bin2hex(random_bytes(8));
        $metadata->createBucket('owner', $bucket, 'us-east-1');
        $versionId = $metadata->putObjectVersioned($bucket, 'key', 'owner', 1, 'etag', 'text/plain', 'cold/key');
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $fixedLastModified = new \DateTimeImmutable('2000-01-02T03:04:05Z');
        $pool->execute(
            $driver === 'mysql'
                ? 'UPDATE s3_objects SET updated_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?'
                : 'UPDATE s3_objects SET updated_at = $1::timestamptz WHERE bucket = $2 AND key_name = $3 AND version_id = $4',
            [$driver === 'mysql' ? $fixedLastModified->format('Y-m-d H:i:s') : $fixedLastModified->format(\DateTimeInterface::ATOM), $bucket, 'key', $versionId],
        );
        $metadata->updateObjectRestoreState($bucket, 'key', $versionId, 'pending');
        self::assertSame($fixedLastModified->getTimestamp(), $metadata->getObjectMetadataByVersion($bucket, 'key', $versionId)?->lastModified->getTimestamp());
        $metadata->updateObjectRestoreStateWithTier($bucket, 'key', $versionId, 'restored', 'hot/key', new \DateTimeImmutable('+1 day'), 'HOT');
        $version = $metadata->getObjectMetadataByVersion($bucket, 'key', $versionId);
        self::assertNotNull($version);
        self::assertSame(['hot/key', 'HOT'], [$version->restoredStoragePath, $version->restoredStorageTier]);
        self::assertSame($fixedLastModified->getTimestamp(), $version->lastModified->getTimestamp());
        self::assertContains('HOT', $metadata->listReferencedStorageTiers());

        try {
            $metadata->updateObjectRestoreState($bucket, 'key', $versionId, 'restored', 'unbound/key', new \DateTimeImmutable('+1 day'));
            self::fail('The old API may not publish a restored path without its tier.');
        } catch (\InvalidArgumentException) {
            self::assertSame('HOT', $metadata->getObjectMetadataByVersion($bucket, 'key', $versionId)?->restoredStorageTier);
        }

        $pool->execute(
            $driver === 'mysql'
                ? 'UPDATE s3_objects SET restored_storage_tier = NULL, updated_at = updated_at WHERE bucket = ? AND key_name = ? AND version_id = ?'
                : 'UPDATE s3_objects SET restored_storage_tier = NULL WHERE bucket = $1 AND key_name = $2 AND version_id = $3',
            [$bucket, 'key', $versionId],
        );
        try {
            $metadata->listReferencedStorageTiers();
            self::fail('An old restored path with unknown tier must block API startup.');
        } catch (\UnexpectedValueException) {
            $legacy = $metadata->getObjectMetadataByVersion($bucket, 'key', $versionId);
            self::assertNull($legacy->restoredStorageTier);
        }
        $metadata->updateObjectRestoreState($bucket, 'key', $versionId, null);
        $cleared = $metadata->getObjectMetadataByVersion($bucket, 'key', $versionId);
        self::assertNotNull($cleared);
        self::assertNull($cleared->restoredStoragePath);
        self::assertNull($cleared->restoredStorageTier);
        self::assertSame($fixedLastModified->getTimestamp(), $cleared->lastModified->getTimestamp());
        $metadata->deleteObjectVersion($bucket, 'key', $versionId);
        $metadata->deleteBucket('owner', $bucket);
        $pool->close();
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_multipart_upload_tier_pinning_and_cleanup(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} multipart tier tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(\OpsFour\S3Server\Metadata\MultipartUploadTierStore::class, $metadata);
        $bucket = 'tier-mpu-' . bin2hex(random_bytes(8));
        $metadata->createBucket('owner', $bucket, 'us-east-1');
        $metadata->createMultipartUploadWithTier('hot-' . $bucket, $bucket, 'hot-key', 'owner', 'HOT');
        $metadata->createMultipartUploadWithTier('cold-' . $bucket, $bucket, 'cold-key', 'owner', 'COLD');
        $metadata->putPart('hot-' . $bucket, 1, 'etag', 1, 'parts/hot-' . $bucket);
        $metadata->putPart('cold-' . $bucket, 1, 'etag', 1, 'parts/cold-' . $bucket);

        self::assertSame('HOT', $metadata->getMultipartUpload('hot-' . $bucket)['storage_tier'] ?? null);
        self::assertContains('HOT', $metadata->listReferencedStorageTiers());
        self::assertContains('COLD', $metadata->listReferencedStorageTiers());
        self::assertInstanceOf(\OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore::class, $metadata);
        $bucketInfo = $metadata->getBucket($bucket);
        self::assertNotNull($bucketInfo);
        $generation = $bucketInfo->generation;
        try {
            $metadata->createMultipartPartWriteIntent($bucket, $generation, 'COLD', 'hot-' . $bucket, 'parts/wrong-' . $bucket);
            self::fail('The part intent must be bound to the upload tier.');
        } catch (\OpsFour\S3Server\Exception\NoSuchUploadException) {
            // Expected: no cleanup job may be created on a foreign tier.
        }

        $metadata->transaction(function () use ($metadata, $bucket): void {
            $metadata->stageBucketMultipartCleanup($bucket, 'WRONG-DEFAULT');
            $metadata->deleteBucket('owner', $bucket);
        });
        $items = $metadata->dequeueStorageGarbage(300);
        $matching = array_values(array_filter($items, static fn(array $item): bool => $item['bucket'] === $bucket));
        self::assertCount(2, $matching);
        $byPath = [];
        foreach ($matching as $item) {
            self::assertSame('delete_path', $item['operation']);
            $byPath[$item['storage_path']] = $item['storage_tier'];
            $metadata->completeStorageGarbage($item['id']);
        }
        self::assertSame(['parts/hot-' . $bucket => 'HOT', 'parts/cold-' . $bucket => 'COLD'], $byPath);
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_object_and_version_listings_preserve_physical_tier(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} object listing tier tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(\OpsFour\S3Server\Metadata\RestoredCopyTierStore::class, $metadata);
        $bucket = 'tier-list-' . bin2hex(random_bytes(8));
        $metadata->createBucket('owner', $bucket, 'us-east-1');
        $versionId = $metadata->putObjectVersioned($bucket, 'dir/item', 'owner', 4, 'etag', 'text/plain', 'path/original');
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $fixedLastModified = new \DateTimeImmutable('2000-01-02T03:04:05Z');
        $pool->execute(
            $driver === 'mysql'
                ? 'UPDATE s3_objects SET updated_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?'
                : 'UPDATE s3_objects SET updated_at = $1::timestamptz WHERE bucket = $2 AND key_name = $3 AND version_id = $4',
            [$driver === 'mysql' ? $fixedLastModified->format('Y-m-d H:i:s') : $fixedLastModified->format(\DateTimeInterface::ATOM), $bucket, 'dir/item', $versionId],
        );
        $pool->close();
        $metadata->updateObjectPlacement($bucket, 'dir/item', $versionId, 'STANDARD', 'COLD', 'path/cold', 'failed', 'WARM', 'transition unavailable');
        $metadata->updateObjectRestoreStateWithTier($bucket, 'dir/item', $versionId, 'restored', 'path/restore', new \DateTimeImmutable('+1 day'), 'WARM');

        $version = $metadata->getObjectMetadataByVersion($bucket, 'dir/item', $versionId);
        self::assertNotNull($version);
        self::assertSame('COLD', $version->storageTier);
        self::assertSame($fixedLastModified->getTimestamp(), $version->lastModified->getTimestamp());
        foreach ([null, '/'] as $delimiter) {
            $objects = $metadata->listObjects($bucket, prefix: 'dir/', delimiter: $delimiter)->objects;
            self::assertCount(1, $objects);
            self::assertSame('COLD', $objects[0]->storageTier);
            self::assertSame('failed', $objects[0]->transitionStatus);
            self::assertSame('WARM', $objects[0]->transitionTargetTier);
            self::assertSame('transition unavailable', $objects[0]->transitionError);
            self::assertSame('restored', $objects[0]->restoreStatus);
            self::assertSame('path/restore', $objects[0]->restoredStoragePath);
            self::assertSame('WARM', $objects[0]->restoredStorageTier);

            $versions = $metadata->listObjectVersions($bucket, prefix: 'dir/', delimiter: $delimiter)['versions'];
            self::assertCount(1, $versions);
            self::assertSame('COLD', $versions[0]->storageTier);
            self::assertSame('failed', $versions[0]->transitionStatus);
            self::assertSame('WARM', $versions[0]->transitionTargetTier);
            self::assertSame('transition unavailable', $versions[0]->transitionError);
            self::assertSame('restored', $versions[0]->restoreStatus);
            self::assertSame('path/restore', $versions[0]->restoredStoragePath);
            self::assertSame('WARM', $versions[0]->restoredStorageTier);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_bucket_deletion_stages_multipart_aborts_in_durable_queue(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} multipart cleanup queue tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $bucket = 'mpu-cleanup-' . bin2hex(random_bytes(8));
        $owner = 'owner-' . bin2hex(random_bytes(4));
        $uploadPrefix = 'upload-' . bin2hex(random_bytes(8));
        $firstUpload = $uploadPrefix . '-000';
        $secondUpload = $uploadPrefix . '-001';
        $metadata->createBucket($owner, $bucket, 'us-east-1');
        for ($index = 0; $index < 257; $index++) {
            $metadata->createMultipartUpload($uploadPrefix . '-' . sprintf('%03d', $index), $bucket, match ($index) {
                0 => 'first/key',
                1 => 'second/key',
                default => 'other/key',
            }, $owner);
        }
        $metadata->putPart($firstUpload, 1, 'etag', 1, 'parts/first');
        $metadata->putPart($firstUpload, 2, 'etag', 1, 'parts/first-second');
        $metadata->putPart($secondUpload, 1, 'etag', 1, 'parts/second');

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('abortMultipartUpload');
        $request = new Request($this->createMock(Client::class), 'DELETE', Http::new('http://127.0.0.1/' . $bucket));
        $request->setAttribute('s3.bucket', $bucket);
        $request->setAttribute('ownerId', $owner);
        self::assertSame(204, (new DeleteBucketHandler($metadata, $storage))->handleRequest($request)->getStatus());

        self::assertNull($metadata->getBucket($bucket));
        self::assertNull($metadata->getMultipartUpload($firstUpload));
        $items = $metadata->dequeueStorageGarbage(300);
        $matching = array_values(array_filter($items, static fn(array $item): bool => $item['bucket'] === $bucket));
        self::assertCount(3, $matching);
        self::assertSame(0, count(array_filter($matching, static fn(array $item): bool => $item['operation'] === 'abort_multipart')));
        $representative = [];
        foreach ($matching as $item) {
            if (in_array($item['upload_id'], [$firstUpload, $secondUpload], true)) {
                $representative[] = [$item['upload_id'], $item['key_name'], $item['storage_path'], $item['operation']];
            }
        }
        self::assertEqualsCanonicalizing(
            [
                [$firstUpload, 'first/key', 'parts/first', 'delete_path'],
                [$firstUpload, 'first/key', 'parts/first-second', 'delete_path'],
                [$secondUpload, 'second/key', 'parts/second', 'delete_path'],
            ],
            $representative,
        );
        foreach ($matching as $item) {
            $metadata->completeStorageGarbage($item['id']);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_backend_reclaims_multipart_abort_after_worker_exit(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} multipart abort crash recovery tests.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $bucket = 'mpu-crash-' . bin2hex(random_bytes(8));
        $owner = 'owner-' . bin2hex(random_bytes(4));
        $uploadId = 'upload-' . bin2hex(random_bytes(8));
        $base = sys_get_temp_dir() . '/s3-mpu-crash-' . bin2hex(random_bytes(8));
        $startPath = $base . '-start';
        $readyPath = $base . '-ready';

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            $storage = new InMemoryBackend();
            $storage->createBucket($bucket);
            $part = $storage->putPart($bucket, 'key', $uploadId, 1, new ReadableBuffer('part data'));
            $metadata->putPart($uploadId, 1, $part->md5Hex, $part->size, $part->path);
            $metadata->transaction(function () use ($metadata, $owner, $bucket): void {
                $metadata->lockOwnerForUpdate($owner);
                $metadata->stageBucketMultipartCleanup($bucket, 'STANDARD');
                $metadata->deleteBucket($owner, $bucket);
            });

            file_put_contents($startPath, 'start');
            $worker = new Process(
                [PHP_BINARY, dirname(__DIR__) . '/Support/metadata-notification-lease-worker.php', $driver, $readyPath, $startPath, $bucket, '1', 'storage_garbage'],
                dirname(__DIR__, 2),
                ['S3_TEST_WORKER_DSN' => $dsn],
            );
            $worker->setTimeout(20);
            $worker->mustRun();
            $claims = json_decode(trim($worker->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            self::assertIsArray($claims);
            self::assertCount(1, $claims);
            self::assertIsArray($claims[0]);
            $garbageId = (int) $claims[0]['id'];

            $collector = new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage));
            self::assertSame(0, $collector->collect(1));
            self::expireStorageGarbageLease($driver, $dsn, $garbageId);
            self::assertSame(1, $collector->collect(1));
            self::assertSame([], $metadata->dequeueStorageGarbage(1));

            $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
            $storage->assembleMultipartUpload($bucket, 'key', $uploadId, [
                ['partNumber' => 1, 'etag' => $part->md5Hex, 'storagePath' => $part->path],
            ]);
        } finally {
            @unlink($startPath);
            @unlink($readyPath);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_webhook_sequencer_increases_across_two_metadata_connections(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} webhook sequencer tests.");
        }

        $firstStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $secondStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $bucket = 'sequence-' . bin2hex(random_bytes(8));
        $owner = 'sequence-' . bin2hex(random_bytes(4));
        $firstStore->createBucket($owner, $bucket, 'us-east-1');
        try {
            $firstStore->putBucketNotification($bucket, [[
                'id' => 'events',
                'events' => ['s3:ObjectCreated:*', 's3:ObjectRemoved:*'],
                'destinationType' => 'Topic',
                'destinationArn' => 'https://example.test/hook',
                'filterRules' => null,
            ]]);
            $generation = $firstStore->getBucket($bucket)?->generation;
            self::assertNotNull($generation);

            (new NotificationDispatcher($firstStore))->dispatch('s3:ObjectCreated:Put', $bucket, 'same-key', bucketGeneration: $generation);
            (new NotificationDispatcher($secondStore))->dispatch('s3:ObjectRemoved:Delete', $bucket, 'same-key', bucketGeneration: $generation);

            $queued = $firstStore->dequeueNotifications(2);
            self::assertCount(2, $queued);
            $first = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0]['s3']['object']['sequencer'];
            $second = json_decode($queued[1]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0]['s3']['object']['sequencer'];
            self::assertMatchesRegularExpression('/^[0-9A-F]{16,}$/', $first);
            self::assertLessThan(hexdec($second), hexdec($first));
            foreach ($queued as $item) {
                $firstStore->updateNotificationStatus($item['id'], 'sent');
            }

            $lockHeld = new \Amp\DeferredFuture();
            $firstWrite = \Amp\async(function () use ($firstStore, $owner, $bucket, $lockHeld, $generation): void {
                $firstStore->transaction(function () use ($firstStore, $owner, $bucket, $lockHeld, $generation): void {
                    OwnerWriteLock::acquire($firstStore, $owner);
                    (new NotificationDispatcher($firstStore))->dispatch('s3:ObjectCreated:Put', $bucket, 'same-key', bucketGeneration: $generation);
                    $lockHeld->complete();
                    \Amp\delay(0.15);
                });
            });
            $secondWrite = \Amp\async(function () use ($secondStore, $bucket, $lockHeld, $generation): void {
                $lockHeld->getFuture()->await();
                (new NotificationDispatcher($secondStore))->dispatch('s3:ObjectRemoved:Delete', $bucket, 'same-key', bucketGeneration: $generation);
            });
            $lockHeld->getFuture()->await();
            \Amp\delay(0.05);
            self::assertFalse($secondWrite->isComplete(), 'The second connection must wait for the first owner transaction.');
            $firstWrite->await();
            $secondWrite->await();

            $raced = $firstStore->dequeueNotifications(2);
            self::assertCount(2, $raced);
            $firstRaceRecord = json_decode($raced[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
            $secondRaceRecord = json_decode($raced[1]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
            self::assertSame('ObjectCreated:Put', $firstRaceRecord['eventName']);
            self::assertSame('ObjectRemoved:Delete', $secondRaceRecord['eventName']);
            self::assertLessThan(
                hexdec($secondRaceRecord['s3']['object']['sequencer']),
                hexdec($firstRaceRecord['s3']['object']['sequencer']),
            );
            foreach ($raced as $item) {
                $firstStore->updateNotificationStatus($item['id'], 'sent');
            }
        } finally {
            $firstStore->putBucketNotification($bucket, []);
            $firstStore->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_webhook_generation_rejects_delayed_events_after_bucket_reuse_across_connections(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} webhook generation tests.");
        }

        $firstStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $secondStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)))
            : new MysqlConnectionPool(MysqlConfig::fromString(trim($dsn)));
        $suffix = bin2hex(random_bytes(8));
        $bucket = 'notify-reuse-' . $suffix;
        $alice = 'notify-alice-' . $suffix;
        $bob = 'notify-bob-' . $suffix;
        $firstStore->createBucket($alice, $bucket, 'us-east-1');

        try {
            $firstStore->putBucketNotification($bucket, [[
                'id' => 'alice-events',
                'events' => ['s3:ObjectCreated:*'],
                'destinationType' => 'Topic',
                'destinationArn' => 'https://example.test/alice-hook',
                'filterRules' => null,
            ]]);
            $aliceGeneration = $firstStore->getBucket($bucket)?->generation;
            self::assertNotNull($aliceGeneration);
            $dispatcher = new NotificationDispatcher($secondStore);
            $delayed = $dispatcher->createEvent(
                's3:ObjectCreated:Put',
                $bucket,
                'alice-key',
                ownerId: $alice,
                bucketGeneration: $aliceGeneration,
            );

            // The second connection observes Alice's bucket, then waits for
            // the owner lock while the first connection replaces that bucket.
            $pending = null;
            $firstStore->transaction(function () use ($firstStore, $dispatcher, $delayed, $alice, $bob, $bucket, &$pending): void {
                OwnerWriteLock::acquire($firstStore, $alice);
                $pending = \Amp\async(static function () use ($dispatcher, $delayed): void {
                    $dispatcher->enqueueWebhooks($delayed);
                });
                \Amp\delay(0.1);
                self::assertFalse($pending->isComplete(), 'The delayed enqueue must wait for Alice\'s owner lock.');
                $firstStore->deleteBucket($alice, $bucket);
                $firstStore->createBucket($bob, $bucket, 'us-east-1');
                $firstStore->putBucketNotification($bucket, [[
                    'id' => 'bob-events',
                    'events' => ['s3:ObjectCreated:*'],
                    'destinationType' => 'Topic',
                    'destinationArn' => 'https://example.test/bob-hook',
                    'filterRules' => null,
                ]]);
            });
            self::assertNotNull($pending);
            $pending->await(new \Amp\TimeoutCancellation(5));

            $queueCount = static function () use ($pool, $driver, $bucket): int {
                $result = $pool->execute($driver === 'postgres'
                    ? 'SELECT COUNT(*) AS count FROM s3_notification_queue WHERE bucket = $1'
                    : 'SELECT COUNT(*) AS count FROM s3_notification_queue WHERE bucket = ?', [$bucket]);

                return (int) ($result->fetchRow()['count'] ?? 0);
            };
            self::assertSame(0, $queueCount(), 'Alice\'s delayed event must not use Bob\'s webhook.');

            $dispatcher->dispatch('s3:ObjectCreated:Put', $bucket, 'unscoped-key', ownerId: $alice);
            self::assertSame(0, $queueCount(), 'An unscoped dispatch must not use Bob\'s webhook.');

            $bobGeneration = $secondStore->getBucket($bucket)?->generation;
            self::assertNotNull($bobGeneration);
            self::assertNotSame($aliceGeneration, $bobGeneration);
            $dispatcher->dispatch('s3:ObjectCreated:Put', $bucket, 'bob-key', ownerId: $bob, bucketGeneration: $bobGeneration);
            self::assertSame(1, $queueCount());
            $queued = $firstStore->dequeueNotifications(1);
            self::assertCount(1, $queued);
            self::assertSame('bob-key', $queued[0]['key_name']);
            self::assertSame('https://example.test/bob-hook', $queued[0]['destination_url']);
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_notification_queue WHERE bucket = $1'
                : 'DELETE FROM s3_notification_queue WHERE bucket = ?', [$bucket]);
            $current = $firstStore->getBucket($bucket);
            if ($current !== null) {
                $firstStore->deleteBucket($current->ownerId, $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_access_logs_do_not_cross_source_owner_after_name_reuse(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} access-log owner tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $source = 'log-source-' . bin2hex(random_bytes(8));
        $oldTarget = 'log-old-' . bin2hex(random_bytes(8));
        $newTarget = 'log-new-' . bin2hex(random_bytes(8));
        $oldOwner = 'log-old-' . bin2hex(random_bytes(4));
        $newOwner = 'log-new-' . bin2hex(random_bytes(4));
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        try {
            $metadata->createBucket($oldOwner, $source, 'us-east-1');
            $metadata->createBucket($oldOwner, $oldTarget, 'us-east-1');
            $metadata->putBucketLogging($source, $oldTarget, 'access/');
            $writer->log($source, 'old-private-key', 'GetObject', 200, 1, bucketOwnerId: $oldOwner);

            $metadata->deleteBucket($oldOwner, $source);
            $metadata->createBucket($newOwner, $source, 'us-east-1');
            $metadata->createBucket($newOwner, $newTarget, 'us-east-1');
            $metadata->putBucketLogging($source, $newTarget, 'access/');
            $writer->log($source, 'new-key', 'GetObject', 200, 1, bucketOwnerId: $newOwner);
            $writer->flush();

            self::assertSame([], $metadata->listObjects($oldTarget)->objects);
            $objects = $metadata->listObjects($newTarget)->objects;
            self::assertCount(1, $objects);
            $body = \Amp\ByteStream\buffer($storage->getObjectByPath($objects[0]->systemMetadata['storagePath']));
            self::assertStringContainsString('new-key', $body);
            self::assertStringNotContainsString('old-private-key', $body);
            self::assertSame(0, $writer->getBufferCount());
        } finally {
            if ($metadata->getBucketOwner($newTarget) === $newOwner) {
                foreach ($metadata->listObjects($newTarget)->objects as $object) {
                    $metadata->deleteObjectMetadata($newTarget, $object->key);
                }
            }
            if ($metadata->getBucketOwner($source) === $newOwner) {
                $metadata->deleteBucket($newOwner, $source);
            }
            if ($metadata->getBucketOwner($oldTarget) === $oldOwner) {
                $metadata->deleteBucket($oldOwner, $oldTarget);
            }
            if ($metadata->getBucketOwner($newTarget) === $newOwner) {
                $metadata->deleteBucket($newOwner, $newTarget);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_access_logs_discard_old_generation_after_same_owner_name_reuse(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} access-log generation tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $owner = 'log-generation-' . bin2hex(random_bytes(4));
        $source = 'log-generation-source-' . bin2hex(random_bytes(8));
        $target = 'log-generation-target-' . bin2hex(random_bytes(8));
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        try {
            $metadata->createBucket($owner, $source, 'us-east-1');
            $metadata->createBucket($owner, $target, 'us-east-1');
            $metadata->putBucketLogging($source, $target, 'access/');
            $oldGeneration = $metadata->getBucket($source)?->generation;
            self::assertNotNull($oldGeneration);
            $writer->log($source, 'old-private-key', 'GetObject', 200, 1, bucketOwnerId: $owner, bucketGeneration: $oldGeneration);

            $metadata->deleteBucket($owner, $source);
            $metadata->createBucket($owner, $source, 'us-east-1');
            $metadata->putBucketLogging($source, $target, 'access/');
            $newGeneration = $metadata->getBucket($source)?->generation;
            self::assertNotNull($newGeneration);
            self::assertNotSame($oldGeneration, $newGeneration);
            $writer->log($source, 'in-flight-old-key', 'GetObject', 200, 1, bucketOwnerId: $owner, bucketGeneration: $oldGeneration);
            $writer->log($source, 'new-key', 'GetObject', 200, 1, bucketOwnerId: $owner, bucketGeneration: $newGeneration);
            $writer->flush();

            $objects = $metadata->listObjects($target)->objects;
            self::assertCount(1, $objects);
            $body = \Amp\ByteStream\buffer($storage->getObjectByPath($objects[0]->systemMetadata['storagePath']));
            self::assertStringContainsString('new-key', $body);
            self::assertStringNotContainsString('old-private-key', $body);
            self::assertStringNotContainsString('in-flight-old-key', $body);
        } finally {
            if ($metadata->getBucketOwner($target) === $owner) {
                foreach ($metadata->listObjects($target)->objects as $object) {
                    $metadata->deleteObjectMetadata($target, $object->key);
                }
            }
            if ($metadata->getBucketOwner($source) === $owner) {
                $metadata->deleteBucket($owner, $source);
            }
            if ($metadata->getBucketOwner($target) === $owner) {
                $metadata->deleteBucket($owner, $target);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_access_logs_discard_old_target_generation_after_same_owner_name_reuse(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} access-log target generation tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $owner = 'log-target-generation-' . bin2hex(random_bytes(4));
        $source = 'log-target-source-' . bin2hex(random_bytes(8));
        $target = 'log-target-bucket-' . bin2hex(random_bytes(8));
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        try {
            $metadata->createBucket($owner, $source, 'us-east-1');
            $metadata->createBucket($owner, $target, 'us-east-1');
            $metadata->putBucketLogging($source, $target, 'access/');
            $writer->log($source, 'old-private-key', 'GetObject', 200, 1, bucketOwnerId: $owner);

            $oldGeneration = $metadata->getBucket($target)?->generation;
            $metadata->deleteBucket($owner, $target);
            $metadata->createBucket($owner, $target, 'us-east-1');
            self::assertNotSame($oldGeneration, $metadata->getBucket($target)?->generation);
            $writer->log($source, 'new-key', 'GetObject', 200, 1, bucketOwnerId: $owner);
            $writer->flush();

            $objects = $metadata->listObjects($target)->objects;
            self::assertCount(1, $objects);
            $body = \Amp\ByteStream\buffer($storage->getObjectByPath($objects[0]->systemMetadata['storagePath']));
            self::assertStringContainsString('new-key', $body);
            self::assertStringNotContainsString('old-private-key', $body);
        } finally {
            if ($metadata->getBucketOwner($target) === $owner) {
                foreach ($metadata->listObjects($target)->objects as $object) {
                    $metadata->deleteObjectMetadata($target, $object->key);
                }
            }
            if ($metadata->getBucketOwner($source) === $owner) {
                $metadata->deleteBucket($owner, $source);
            }
            if ($metadata->getBucketOwner($target) === $owner) {
                $metadata->deleteBucket($owner, $target);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_access_log_storage_write_does_not_block_target_deletion(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} access-log deletion contention tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $owner = 'log-race-' . bin2hex(random_bytes(4));
        $source = 'log-race-source-' . bin2hex(random_bytes(8));
        $target = 'log-race-target-' . bin2hex(random_bytes(8));
        $readyPath = sys_get_temp_dir() . '/s3-log-delete-' . bin2hex(random_bytes(8)) . '.ready';
        $deleter = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            function (string $bucket, string $key, ReadableStream $body) use ($driver, $dsn, $owner, $target, $readyPath, &$deleter): StorageWriteResult {
                $content = \Amp\ByteStream\buffer($body);
                $deleter = new Process([
                    PHP_BINARY,
                    dirname(__DIR__) . '/Support/metadata-access-log-delete-worker.php',
                    $driver,
                    $target,
                    $owner,
                    $readyPath,
                ], dirname(__DIR__, 2), ['S3_TEST_WORKER_DSN' => trim($dsn)]);
                $deleter->setTimeout(10);
                $deleter->start();
                $deadline = microtime(true) + 5;
                while (! is_file($readyPath) && microtime(true) < $deadline) {
                    usleep(10_000);
                }
                self::assertFileExists($readyPath, 'Delete worker did not become ready: ' . $deleter->getErrorOutput());
                // The remote write has not returned yet. Bucket deletion must
                // finish without waiting for this storage operation.
                $deleter->wait();
                self::assertSame(0, $deleter->getExitCode(), $deleter->getErrorOutput());
                self::assertSame('deleted', trim($deleter->getOutput()));

                return new StorageWriteResult('memory://' . $bucket . '/' . $key, strlen($content), md5($content));
            },
        );

        try {
            $metadata->createBucket($owner, $source, 'us-east-1');
            $metadata->createBucket($owner, $target, 'us-east-1');
            $metadata->putBucketLogging($source, $target, 'access/');
            $writer = new AccessLogWriter($metadata, $storage);
            $writer->log($source, 'private-key', 'GetObject', 200, 1, bucketOwnerId: $owner);
            $writer->flush();

            self::assertInstanceOf(Process::class, $deleter);
            self::assertNull($metadata->getBucketOwner($target));
            self::assertSame(1, $writer->getBufferCount());
        } finally {
            if ($deleter instanceof Process && $deleter->isRunning()) {
                $deleter->stop(1);
            }
            @unlink($readyPath);
            if ($metadata->getBucketOwner($target) === $owner) {
                foreach ($metadata->listObjects($target)->objects as $object) {
                    $metadata->deleteObjectMetadata($target, $object->key);
                }
            }
            if ($metadata->getBucketOwner($source) === $owner) {
                $metadata->deleteBucket($owner, $source);
            }
            if ($metadata->getBucketOwner($target) === $owner) {
                $metadata->deleteBucket($owner, $target);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_bucket_ownership_controls_survive_updates_and_bucket_reuse(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} ownership-control tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $ownerId = 'ownership-' . bin2hex(random_bytes(4));
        $bucket = 'ownership-' . bin2hex(random_bytes(8));
        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            self::assertNull($metadata->getBucketOwnershipControls($bucket));
            $metadata->putBucketOwnershipControls($bucket, 'BucketOwnerEnforced');
            self::assertSame('BucketOwnerEnforced', $metadata->getBucketOwnershipControls($bucket));
            $metadata->putBucketOwnershipControls($bucket, 'BucketOwnerPreferred');
            self::assertSame('BucketOwnerPreferred', $metadata->getBucketOwnershipControls($bucket));
            $metadata->putBucketOwnershipControls($bucket, 'ObjectWriter');
            self::assertSame('ObjectWriter', $metadata->getBucketOwnershipControls($bucket));
            $metadata->deleteBucket($ownerId, $bucket);
            self::assertNull($metadata->getBucketOwnershipControls($bucket));
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            self::assertNull($metadata->getBucketOwnershipControls($bucket));
        } finally {
            if ($metadata->getBucketOwner($bucket) === $ownerId) {
                $metadata->deleteBucket($ownerId, $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_bucket_listing_pages_filter_and_keep_owner_scope(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} bucket pagination tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $ownerId = 'listing-' . bin2hex(random_bytes(4));
        $otherOwner = 'other-' . bin2hex(random_bytes(4));
        $prefix = 'listing-' . bin2hex(random_bytes(8));
        $buckets = array_map(static fn(int $number): string => sprintf('%s-%02d', $prefix, $number), range(0, 3));
        $foreign = $prefix . '-99';
        $created = [];

        try {
            foreach ($buckets as $index => $bucket) {
                $metadata->createBucket($ownerId, $bucket, $index === 3 ? 'us-west-2' : 'us-east-1');
                $created[] = [$ownerId, $bucket];
            }
            $metadata->createBucket($otherOwner, $foreign, 'us-east-1');
            $created[] = [$otherOwner, $foreign];
            self::assertSame(4, $metadata->countBuckets($ownerId));
            self::assertSame(1, $metadata->countBuckets($otherOwner));

            $first = $metadata->listBucketsPage($ownerId, null, $prefix, null, 2);
            self::assertSame(array_slice($buckets, 0, 2), array_map(static fn($bucket): string => $bucket->name, $first['buckets']));
            self::assertSame($buckets[1], $first['nextMarker']);

            $second = $metadata->listBucketsPage($ownerId, $first['nextMarker'], $prefix, null, 2);
            self::assertSame(array_slice($buckets, 2), array_map(static fn($bucket): string => $bucket->name, $second['buckets']));
            self::assertNull($second['nextMarker']);

            $west = $metadata->listBucketsPage($ownerId, null, $prefix, 'us-west-2', 2);
            self::assertSame([$buckets[3]], array_map(static fn($bucket): string => $bucket->name, $west['buckets']));
            self::assertSame([], $metadata->listBucketsPage($ownerId, null, $prefix . '%', null, 2)['buckets']);
        } finally {
            foreach ($created as [$owner, $bucket]) {
                $metadata->deleteBucket($owner, $bucket);
            }
        }
    }

    public function test_mysql_preserves_exact_object_key_identity_across_metadata(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN to run the MySQL key identity test.');
        }

        $metadata = MetadataStoreFactory::create('mysql', ['dsn' => trim($dsn)]);
        $ownerId = 'key-identity-' . bin2hex(random_bytes(4));
        $bucket = 'key-identity-' . bin2hex(random_bytes(8));
        $sharedPrefix = str_repeat('prefix-', 45);
        $keys = [
            $sharedPrefix . 'first',
            $sharedPrefix . 'second',
            'Case',
            'case',
            'café',
            'cafe',
            'trail',
            'trail ',
        ];
        $uploadIds = [];

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            foreach ($keys as $index => $key) {
                $metadata->putObjectMetadata(
                    bucket: $bucket,
                    key: $key,
                    ownerId: $ownerId,
                    size: $index + 1,
                    etag: '"etag"',
                    contentType: 'text/plain',
                    storagePath: $bucket . '/' . $index,
                );
            }

            self::assertSame(count($keys), $metadata->getBucketStorageStats($bucket)['objectCount']);
            foreach ($keys as $index => $key) {
                self::assertSame($index + 1, $metadata->getObjectMetadata($bucket, $key)?->size, $key);
            }
            $listed = array_map(static fn($object): string => $object->key, $metadata->listObjects($bucket)->objects);
            sort($listed, SORT_STRING);
            $expected = $keys;
            sort($expected, SORT_STRING);
            self::assertSame($expected, $listed);
            self::assertSame(
                ['café'],
                array_map(static fn($object): string => $object->key, $metadata->listObjects($bucket, prefix: 'café')->objects),
            );

            foreach (['Case', 'case'] as $key) {
                $uploadId = bin2hex(random_bytes(8));
                $metadata->createMultipartUpload($uploadId, $bucket, $key, $ownerId);
                $uploadIds[] = $uploadId;
                $upload = $metadata->getMultipartUpload($uploadId);
                self::assertNotNull($upload);
                self::assertSame($key, $upload['key_name']);
            }
            self::assertSame(
                ['Case', 'case'],
                array_column($metadata->listMultipartUploads($bucket)['uploads'], 'key_name'),
            );

            $first = $keys[0];
            $second = $keys[1];
            $metadata->putObjectTagging($bucket, $first, [['key' => 'identity', 'value' => 'first']]);
            $metadata->putObjectTagging($bucket, $second, [['key' => 'identity', 'value' => 'second']]);
            self::assertSame('first', $metadata->getObjectTagging($bucket, $first)[0]['value']);
            self::assertSame('second', $metadata->getObjectTagging($bucket, $second)[0]['value']);
            $metadata->putObjectTagging($bucket, 'Case', [['key' => 'identity', 'value' => 'upper']]);
            $metadata->putObjectTagging($bucket, 'case', [['key' => 'identity', 'value' => 'lower']]);
            self::assertSame('upper', $metadata->getObjectTagging($bucket, 'Case')[0]['value']);
            self::assertSame('lower', $metadata->getObjectTagging($bucket, 'case')[0]['value']);

            $metadata->putObjectLegalHold($bucket, $first, 'ON');
            $metadata->putObjectLegalHold($bucket, $second, 'OFF');
            self::assertSame('ON', $metadata->getObjectLegalHold($bucket, $first));
            self::assertSame('OFF', $metadata->getObjectLegalHold($bucket, $second));

            $longKey = str_repeat('a', 600);
            $resource = $bucket . '/' . $longKey . '?versionId=null';
            $grant = [['granteeType' => 'CanonicalUser', 'granteeId' => $ownerId, 'permission' => 'FULL_CONTROL']];
            $metadata->putAcl('object', $resource, $ownerId, $grant);
            self::assertSame($grant, $metadata->getAcl('object', $resource));
            $upperResource = $bucket . '/Case?versionId=null';
            $lowerResource = $bucket . '/case?versionId=null';
            $otherGrant = [['granteeType' => 'Group', 'granteeId' => 'AllUsers', 'permission' => 'READ']];
            $metadata->putAcl('object', $upperResource, $ownerId, $grant);
            $metadata->putAcl('object', $lowerResource, $ownerId, $otherGrant);
            self::assertSame($grant, $metadata->getAcl('object', $upperResource));
            self::assertSame($otherGrant, $metadata->getAcl('object', $lowerResource));
        } finally {
            foreach ($uploadIds as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            foreach ($keys as $key) {
                $metadata->deleteObjectMetadata($bucket, $key);
            }
            $metadata->deleteBucket($ownerId, $bucket);
        }
    }

    public function test_mysql_version_ids_are_case_sensitive_across_subresources(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN to run the MySQL version identity test.');
        }

        $metadata = MetadataStoreFactory::create('mysql', ['dsn' => trim($dsn)]);
        $ownerId = 'version-identity-' . bin2hex(random_bytes(4));
        $bucket = 'version-identity-' . bin2hex(random_bytes(8));
        $key = 'versioned.txt';
        $versionIds = [];

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            do {
                $versionId = $metadata->putObjectVersioned($bucket, $key, $ownerId, 1, '"etag"', 'text/plain', 'version-path');
                $versionIds[] = $versionId;
                $wrongCase = strtoupper($versionId);
            } while ($wrongCase === $versionId && count($versionIds) < 5);
            self::assertNotSame($versionId, $wrongCase);

            $metadata->putObjectTagging($bucket, $key, [['key' => 'state', 'value' => 'real']], $versionId);
            $metadata->putObjectLegalHold($bucket, $key, 'ON', $versionId);
            self::assertNotNull($metadata->getObjectMetadataByVersion($bucket, $key, $versionId));
            self::assertNull($metadata->getObjectMetadataByVersion($bucket, $key, $wrongCase));
            self::assertSame([], $metadata->getObjectTagging($bucket, $key, $wrongCase));
            self::assertNull($metadata->getObjectLegalHold($bucket, $key, $wrongCase));
            self::assertSame('ON', $metadata->getObjectLegalHold($bucket, $key, $versionId));
        } finally {
            foreach ($versionIds as $versionId) {
                $metadata->deleteObjectVersion($bucket, $key, $versionId);
            }
            $metadata->deleteBucket($ownerId, $bucket);
        }
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
            $firstTransition = $metadata->dequeueTierTransitionJobs(1);
            self::assertCount(1, $firstTransition);
            self::assertSame([], $metadata->dequeueTierTransitionJobs(1));
            self::agePendingQueueItem($driver, trim($dsn), 's3_tier_transition_jobs', $transitionJobId);
            $reclaimedTransition = $metadata->dequeueTierTransitionJobs(1);
            self::assertCount(1, $reclaimedTransition);
            self::assertSame([$transitionJobId], array_column($reclaimedTransition, 'id'));
            $reclaimedTransitionJob = reset($reclaimedTransition);
            self::assertIsArray($reclaimedTransitionJob);
            self::assertNotSame($firstTransition[0]['nextAttemptAt'], $reclaimedTransitionJob['nextAttemptAt']);
            $renewedTransitionLease = microtime(true) + 300;
            self::assertFalse($metadata->renewTierTransitionJobLease($transitionJobId, $renewedTransitionLease, $firstTransition[0]['nextAttemptAt']));
            self::assertTrue($metadata->renewTierTransitionJobLease($transitionJobId, $renewedTransitionLease, $reclaimedTransitionJob['nextAttemptAt']));
            self::assertFalse($metadata->updateTierTransitionJobStatus(
                $transitionJobId,
                'completed',
                expectedLeaseExpiresAt: $firstTransition[0]['nextAttemptAt'],
            ));
            self::assertSame('processing', $metadata->getTierTransitionJob($transitionJobId)['status'] ?? null);
            self::assertTrue($metadata->updateTierTransitionJobStatus(
                $transitionJobId,
                'completed',
                incrementAttempts: false,
                targetStoragePath: $bucket . '/archive/object.txt',
                expectedLeaseExpiresAt: $renewedTransitionLease,
            ));
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
            $firstRestore = $metadata->dequeueRestoreJobs(1);
            self::assertCount(1, $firstRestore);
            self::assertSame([], $metadata->dequeueRestoreJobs(1));
            self::agePendingQueueItem($driver, trim($dsn), 's3_restore_jobs', $restoreJobId);
            $reclaimedRestore = $metadata->dequeueRestoreJobs(1);
            self::assertCount(1, $reclaimedRestore);
            self::assertSame([$restoreJobId], array_column($reclaimedRestore, 'id'));
            $reclaimedRestoreJob = reset($reclaimedRestore);
            self::assertIsArray($reclaimedRestoreJob);
            self::assertNotSame($firstRestore[0]['nextAttemptAt'], $reclaimedRestoreJob['nextAttemptAt']);
            $renewedRestoreLease = microtime(true) + 300;
            self::assertFalse($metadata->renewRestoreJobLease($restoreJobId, $renewedRestoreLease, $firstRestore[0]['nextAttemptAt']));
            self::assertTrue($metadata->renewRestoreJobLease($restoreJobId, $renewedRestoreLease, $reclaimedRestoreJob['nextAttemptAt']));
            self::assertFalse($metadata->updateRestoreJobStatus(
                $restoreJobId,
                'completed',
                expectedLeaseExpiresAt: $firstRestore[0]['nextAttemptAt'],
            ));
            self::assertSame('processing', $metadata->getRestoreJob($restoreJobId)['status'] ?? null);
            self::assertTrue($metadata->updateRestoreJobStatus(
                $restoreJobId,
                'completed',
                incrementAttempts: false,
                restoredStoragePath: $bucket . '/restored/object.txt',
                expectedLeaseExpiresAt: $renewedRestoreLease,
            ));
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
            self::assertNotSame($leasedGarbage[0]['lease_expires_at'], $reclaimedGarbage[0]['lease_expires_at']);
            self::assertFalse($metadata->completeStorageGarbage($leasedGarbage[0]['id'], $leasedGarbage[0]['lease_expires_at']));
            self::assertFalse($metadata->retryStorageGarbage($leasedGarbage[0]['id'], 'stale', 0, $leasedGarbage[0]['lease_expires_at']));
            self::assertTrue($metadata->completeStorageGarbage($reclaimedGarbage[0]['id'], $reclaimedGarbage[0]['lease_expires_at']));

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
    public function test_external_backend_assigns_notification_leases_to_only_one_process(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} notification lease tests.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $ownerId = 'lease-' . bin2hex(random_bytes(4));
        $bucket = 'lease-' . bin2hex(random_bytes(8));
        $base = sys_get_temp_dir() . '/s3-lease-' . bin2hex(random_bytes(8));
        $startPath = $base . '-start';
        $processes = [];

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            for ($number = 0; $number < 20; $number++) {
                $metadata->enqueueNotification($bucket, sprintf('key-%02d', $number), 's3:ObjectCreated:Put', 'https://example.test/webhook', '{}');
            }
            $pool = self::externalPool($driver, $dsn);
            try {
                $pool->execute($driver === 'mysql'
                    ? 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE bucket = ?'
                    : 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE bucket = $1', [$bucket]);
            } finally {
                $pool->close();
            }

            foreach ([0, 1] as $workerNumber) {
                $readyPath = $base . '-ready-' . $workerNumber;
                $process = new Process(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/metadata-notification-lease-worker.php', $driver, $readyPath, $startPath, $bucket, '10'],
                    dirname(__DIR__, 2),
                    ['S3_TEST_WORKER_DSN' => $dsn],
                );
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;
            while (! is_file($base . '-ready-0') || ! is_file($base . '-ready-1')) {
                if (! $processes[0]->isRunning() || ! $processes[1]->isRunning()) {
                    self::fail('Notification lease worker exited before readiness.');
                }
                if (microtime(true) >= $deadline) {
                    self::fail('Notification lease workers did not become ready.');
                }
                usleep(10_000);
            }
            file_put_contents($startPath, 'start');

            $allIds = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $ids = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($ids);
                self::assertCount(10, $ids);
                $allIds = [...$allIds, ...$ids];
            }
            self::assertCount(20, array_unique($allIds));
            $abandonedId = (int) array_pop($allIds);
            foreach ($allIds as $id) {
                $metadata->updateNotificationStatus((int) $id, 'sent');
            }
            $pool = self::externalPool($driver, $dsn);
            try {
                $pool->execute($driver === 'mysql'
                    ? 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE id = ?'
                    : 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE id = $1', [$abandonedId]);
            } finally {
                $pool->close();
            }

            $recoveryProcess = new Process(
                [PHP_BINARY, dirname(__DIR__) . '/Support/metadata-notification-lease-worker.php', $driver, $base . '-ready-2', $startPath, $bucket, '1'],
                dirname(__DIR__, 2),
                ['S3_TEST_WORKER_DSN' => $dsn],
            );
            $recoveryProcess->setTimeout(20);
            $processes[] = $recoveryProcess;
            $recoveryProcess->mustRun();
            self::assertSame([$abandonedId], json_decode(trim($recoveryProcess->getOutput()), true, 512, JSON_THROW_ON_ERROR));
            $metadata->updateNotificationStatus($abandonedId, 'sent');
            self::assertSame([], $metadata->dequeueNotifications(1));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            @unlink($startPath);
            @unlink($base . '-ready-0');
            @unlink($base . '-ready-1');
            @unlink($base . '-ready-2');
            if ($metadata->getBucketOwner($bucket) === $ownerId) {
                $metadata->deleteBucket($ownerId, $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_backend_assigns_storage_cleanup_leases_to_only_one_process(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} storage cleanup lease tests.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $metadata);
        $ownerId = 'garbage-lease-' . bin2hex(random_bytes(4));
        $bucket = 'garbage-lease-' . bin2hex(random_bytes(8));
        $base = sys_get_temp_dir() . '/s3-garbage-lease-' . bin2hex(random_bytes(8));
        $startPath = $base . '-start';
        $processes = [];

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            for ($number = 0; $number < 20; $number++) {
                $metadata->enqueueStorageGarbage($bucket, 'STANDARD', sprintf('old-part-%02d', $number));
            }
            $pool = self::externalPool($driver, $dsn);
            try {
                $pool->execute($driver === 'mysql'
                    ? 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE bucket = ?'
                    : 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE bucket = $1', [$bucket]);
            } finally {
                $pool->close();
            }

            foreach ([0, 1] as $workerNumber) {
                $readyPath = $base . '-ready-' . $workerNumber;
                $process = new Process(
                    [PHP_BINARY, dirname(__DIR__) . '/Support/metadata-notification-lease-worker.php', $driver, $readyPath, $startPath, $bucket, '10', 'storage_garbage'],
                    dirname(__DIR__, 2),
                    ['S3_TEST_WORKER_DSN' => $dsn],
                );
                $process->setTimeout(20);
                $process->start();
                $processes[] = $process;
            }

            $deadline = microtime(true) + 10;
            while (!is_file($base . '-ready-0') || !is_file($base . '-ready-1')) {
                if (!$processes[0]->isRunning() || !$processes[1]->isRunning()) {
                    self::fail('Storage cleanup lease worker exited before readiness.');
                }
                if (microtime(true) >= $deadline) {
                    self::fail('Storage cleanup lease workers did not become ready.');
                }
                usleep(10_000);
            }
            file_put_contents($startPath, 'start');

            $allClaims = [];
            foreach ($processes as $process) {
                $process->wait();
                self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $claims = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($claims);
                self::assertCount(10, $claims);
                $allClaims = [...$allClaims, ...$claims];
            }
            $allIds = array_column($allClaims, 'id');
            self::assertCount(20, array_unique($allIds));
            foreach ($allClaims as $claim) {
                self::assertIsArray($claim);
                self::assertTrue($metadata->completeStorageGarbageLease(
                    (int) $claim['id'],
                    (float) $claim['lease_expires_at'],
                    (int) $claim['lease_generation'],
                ));
            }
            self::assertSame([], $metadata->dequeueStorageGarbage(1));
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop(1);
                }
            }
            @unlink($startPath);
            @unlink($base . '-ready-0');
            @unlink($base . '-ready-1');
            if ($metadata->getBucketOwner($bucket) === $ownerId) {
                $metadata->deleteBucket($ownerId, $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_backend_fences_old_notification_lease_after_reclaim(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} notification lease tests.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $ownerId = 'lease-fence-' . bin2hex(random_bytes(4));
        $bucket = 'lease-fence-' . bin2hex(random_bytes(8));

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            $metadata->enqueueNotification($bucket, 'key', 's3:ObjectCreated:Put', 'https://example.test/webhook', '{}');
            $pool = self::externalPool($driver, $dsn);
            try {
                $pool->execute($driver === 'mysql'
                    ? 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE bucket = ?'
                    : 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE bucket = $1', [$bucket]);
            } finally {
                $pool->close();
            }

            $first = $metadata->dequeueNotifications(1)[0];
            $pool = self::externalPool($driver, $dsn);
            try {
                $pool->execute($driver === 'mysql'
                    ? 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE id = ?'
                    : 'UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE id = $1', [$first['id']]);
            } finally {
                $pool->close();
            }
            $second = $metadata->dequeueNotifications(1)[0];
            self::assertSame($first['id'], $second['id']);
            self::assertNotSame($first['lease_expires_at'], $second['lease_expires_at']);
            $sentBefore = $metadata->getNotificationQueueStats()['sent'] ?? 0;

            self::assertFalse($metadata->updateNotificationStatus($first['id'], 'dead_letter', expectedLeaseExpiresAt: $first['lease_expires_at']));
            self::assertSame(1, $metadata->getNotificationQueueStats()['processing'] ?? 0);
            self::assertSame($sentBefore, $metadata->getNotificationQueueStats()['sent'] ?? 0);
            self::assertTrue($metadata->updateNotificationStatus($second['id'], 'sent', expectedLeaseExpiresAt: $second['lease_expires_at']));
            self::assertSame(0, $metadata->getNotificationQueueStats()['processing'] ?? 0);
            self::assertSame($sentBefore + 1, $metadata->getNotificationQueueStats()['sent'] ?? 0);
            self::assertFalse($metadata->updateNotificationStatus($first['id'], 'pending', nextAttemptAt: 0, expectedLeaseExpiresAt: $first['lease_expires_at']));
            self::assertSame(0, $metadata->getNotificationQueueStats()['pending'] ?? 0);
            self::assertSame($sentBefore + 1, $metadata->getNotificationQueueStats()['sent'] ?? 0);
        } finally {
            if ($metadata->getBucketOwner($bucket) === $ownerId) {
                $metadata->deleteBucket($ownerId, $bucket);
            }
        }
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_backend_lists_persisted_tier_references_for_startup(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} tier reference tests.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $ownerId = 'tier-ref-' . bin2hex(random_bytes(4));
        $bucket = 'tier-ref-' . bin2hex(random_bytes(8));

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            $metadata->putBucketLifecycle($bucket, [[
                'id' => 'archive', 'status' => 'Enabled', 'prefix' => null,
                'filter' => null,
                'transitions' => [['days' => 1, 'storageClass' => 'GLACIER']],
                'expiration' => null,
                'noncurrentTransitions' => [['noncurrentDays' => 2, 'storageClass' => 'DEEP_ARCHIVE']],
                'noncurrentExpiration' => null,
                'abortIncompleteDays' => null,
            ]]);
            self::assertContains('GLACIER', $metadata->listReferencedStorageTiers());
            self::assertContains('DEEP_ARCHIVE', $metadata->listReferencedStorageTiers());

            $metadata->putObjectMetadata($bucket, 'key', $ownerId, 1, '"etag"', 'text/plain', 'hot/key');
            $metadata->updateObjectPlacement($bucket, 'key', null, 'GLACIER', 'GLACIER', 'cold/key');
            $metadata->enqueueTierTransitionJob($bucket, 'key', null, 'GLACIER', 'DEEP_ARCHIVE', 'DEEP_ARCHIVE', 'cold/key');
            $metadata->enqueueRestoreJob($bucket, 'key', null, 'GLACIER', 'cold/key', 1);
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', 'hot/key');
            $tiers = $metadata->listReferencedStorageTiers();
            self::assertContains('STANDARD', $tiers);
            self::assertContains('GLACIER', $tiers);
            self::assertContains('DEEP_ARCHIVE', $tiers);
        } finally {
            if ($metadata->getBucketOwner($bucket) === $ownerId) {
                if ($metadata->getObjectMetadata($bucket, 'key') !== null) {
                    $metadata->deleteObjectMetadata($bucket, 'key');
                }
                $pool = self::externalPool($driver, trim($dsn));
                try {
                    $pool->execute($driver === 'mysql'
                        ? 'DELETE FROM s3_storage_garbage WHERE bucket = ?'
                        : 'DELETE FROM s3_storage_garbage WHERE bucket = $1', [$bucket]);
                } finally {
                    $pool->close();
                }
                $metadata->deleteBucket($ownerId, $bucket);
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
        $scaleCount = self::migrationScaleCount($dsn);
        $pool = $driver === 'mysql'
            ? new MysqlConnectionPool(MysqlConfig::fromString($dsn))
            : new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $ownerId = 'quota-migration-' . bin2hex(random_bytes(4));
        $bucket = 'quota-migration-' . bin2hex(random_bytes(8));

        try {
            // Bootstrap the complete schema first so this test can also run by
            // itself against an empty disposable database.
            $initial = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            $initial->createBucket($ownerId, $bucket, 'us-east-1');
            $initial->putObjectMetadata($bucket, 'owned.txt', $ownerId, 4, '"etag"', 'text/plain', 'owned-path');
            self::seedMigrationObjects($pool, $bucket, $ownerId, $scaleCount);
            $pool->execute('DROP TABLE IF EXISTS s3_storage_garbage');
            $pool->execute('DROP TABLE IF EXISTS s3_owner_write_locks');

            if ($driver === 'mysql') {
                $pool->execute('ALTER TABLE s3_buckets DROP COLUMN generation');
                $pool->execute('ALTER TABLE s3_objects DROP INDEX idx_s3_objects_owner');
                $pool->execute('ALTER TABLE s3_objects DROP INDEX idx_s3_objects_tier_state');
                $pool->execute('ALTER TABLE s3_objects DROP INDEX idx_s3_objects_transition_target_tier');
                $pool->execute('ALTER TABLE s3_multipart_uploads DROP INDEX idx_s3_multipart_uploads_owner');
                $pool->execute('ALTER TABLE s3_buckets DROP INDEX idx_s3_buckets_owner_name');
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
                $pool->execute('ALTER TABLE s3_buckets DROP COLUMN generation');
                $pool->execute('DROP INDEX idx_s3_objects_owner');
                $pool->execute('DROP INDEX idx_s3_objects_tier_state');
                $pool->execute('DROP INDEX idx_s3_objects_transition_target_tier');
                $pool->execute('DROP INDEX idx_s3_multipart_uploads_owner');
                $pool->execute('DROP INDEX idx_s3_buckets_owner_name');
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

            $progressPath = getenv('S3_TEST_MIGRATION_PROGRESS_FILE');
            if ($progressPath !== false && $progressPath !== '') {
                self::recordMigrationProbeEvent($progressPath, 'migration-start', $scaleCount);
            }
            $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
            if ($progressPath !== false && $progressPath !== '') {
                self::recordMigrationProbeEvent($progressPath, 'migration-complete', $scaleCount);
            }
            $metadata->transaction(function () use ($metadata): void {
                $metadata->lockOwnerForUpdate('metadata-migration-owner');
            });
            self::assertSame(['objectCount' => 1 + $scaleCount, 'bytesUsed' => 4 + $scaleCount], $metadata->getOwnerStorageStats($ownerId));
            $migratedBucket = $metadata->getBucket($bucket);
            self::assertNotNull($migratedBucket);
            self::assertSame(32, strlen($migratedBucket->generation));
            if ($scaleCount > 0) {
                self::assertSame(1, $metadata->getObjectMetadata($bucket, sprintf('scale-%08d', $scaleCount - 1))?->size);
                self::assertSame(
                    [['key' => 'state', 'value' => 'scale']],
                    $metadata->getObjectTagging($bucket, sprintf('scale-%08d', intdiv($scaleCount - 1, 10) * 10)),
                );
            }

            $row = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertNotNull($row);
            self::assertSame($driver === 'mysql' ? MysqlSchema::VERSION : PostgresSchema::VERSION, (int) $row['version']);
            self::assertNotNull(
                $pool->execute(
                    "SELECT owner_id FROM s3_owner_write_locks WHERE owner_id = 'metadata-migration-owner'",
                )->fetchRow(),
            );
            $pool->execute('SELECT version_id FROM s3_tagging LIMIT 1');
            $pool->execute('SELECT max_multipart_uploads_per_bucket FROM s3_account_quotas LIMIT 1');
            $pool->execute('SELECT storage_path FROM s3_storage_garbage LIMIT 1');
            if ($driver === 'mysql') {
                self::assertNotNull($pool->execute("SHOW INDEX FROM s3_objects WHERE Key_name = 'idx_s3_objects_owner'")->fetchRow());
                self::assertNotNull($pool->execute("SHOW INDEX FROM s3_objects WHERE Key_name = 'idx_s3_objects_tier_state'")->fetchRow());
                self::assertNotNull($pool->execute("SHOW INDEX FROM s3_objects WHERE Key_name = 'idx_s3_objects_transition_target_tier'")->fetchRow());
                self::assertNotNull($pool->execute("SHOW INDEX FROM s3_multipart_uploads WHERE Key_name = 'idx_s3_multipart_uploads_owner'")->fetchRow());
                self::assertNotNull($pool->execute("SHOW INDEX FROM s3_buckets WHERE Key_name = 'idx_s3_buckets_owner_name'")->fetchRow());
            } else {
                self::assertNotNull($pool->execute("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_s3_objects_owner'")->fetchRow());
                self::assertNotNull($pool->execute("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_s3_objects_tier_state'")->fetchRow());
                self::assertNotNull($pool->execute("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_s3_objects_transition_target_tier'")->fetchRow());
                self::assertNotNull($pool->execute("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_s3_multipart_uploads_owner'")->fetchRow());
                self::assertNotNull($pool->execute("SELECT indexname FROM pg_indexes WHERE indexname = 'idx_s3_buckets_owner_name'")->fetchRow());
            }
        } finally {
            // The dedicated 100m probe owns and removes its entire disposable
            // database container. Deleting 100m rows here could consume the
            // remaining VM lifetime and erase the migration result.
            if (getenv('S3_TEST_MIGRATION_100M_PROBE') === '1') {
                $pool->close();
            } else {
                // Restore a usable current schema even if an assertion above fails.
                $restored = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
                $pool->execute($driver === 'mysql' ? 'DELETE FROM s3_tagging WHERE bucket = ?' : 'DELETE FROM s3_tagging WHERE bucket = $1', [$bucket]);
                $pool->execute($driver === 'mysql' ? 'DELETE FROM s3_objects WHERE bucket = ?' : 'DELETE FROM s3_objects WHERE bucket = $1', [$bucket]);
                $restored->deleteBucket($ownerId, $bucket);
                $pool->close();
            }
        }
    }

    public function test_mysql_v15_key_identity_migration_preserves_existing_objects_and_retries(): void
    {
        if (getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1') {
            self::markTestSkipped('Set S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS=1 only for a disposable MySQL database.');
        }
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN to run the MySQL key migration test.');
        }

        $dsn = trim($dsn);
        $scaleCount = self::migrationScaleCount($dsn);
        $metadata = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
        $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $ownerId = 'key-migration-' . bin2hex(random_bytes(4));
        $bucket = 'key-migration-' . bin2hex(random_bytes(8));

        try {
            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            $metadata->putObjectMetadata($bucket, 'Case', $ownerId, 1, '"old"', 'text/plain', 'old-path');
            self::seedMigrationObjects($pool, $bucket, $ownerId, $scaleCount);

            $pool->execute('ALTER TABLE s3_objects DROP INDEX uk_s3_objects_exact');
            $pool->execute('ALTER TABLE s3_objects DROP COLUMN key_identity');
            $pool->execute('ALTER TABLE s3_objects MODIFY key_name TEXT NOT NULL');
            $pool->execute('ALTER TABLE s3_objects ADD UNIQUE KEY uk_s3_objects_bkv (bucket, key_name(255), version_id)');
            $pool->execute('DELETE FROM s3_schema_version WHERE version >= 16');
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES (15, 'Pre-v16 key fixture')");
            self::assertSame(1, $metadata->getObjectMetadata($bucket, 'case')?->size);

            $migrated = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
            self::assertSame(1, $migrated->getObjectMetadata($bucket, 'Case')?->size);
            self::assertNull($migrated->getObjectMetadata($bucket, 'case'));
            $migrated->putObjectMetadata($bucket, 'case', $ownerId, 2, '"new"', 'text/plain', 'new-path');
            self::assertSame(2 + $scaleCount, $migrated->getBucketStorageStats($bucket)['objectCount']);
            if ($scaleCount > 0) {
                self::assertSame(1, $migrated->getObjectMetadata($bucket, sprintf('scale-%08d', $scaleCount - 1))?->size);
                self::assertSame(
                    [['key' => 'state', 'value' => 'scale']],
                    $migrated->getObjectTagging($bucket, sprintf('scale-%08d', intdiv($scaleCount - 1, 10) * 10)),
                );
            }

            // A previously interrupted DDL run may leave v16 objects in place
            // while the schema-version row still says 15.
            $pool->execute('DELETE FROM s3_schema_version WHERE version >= 16');
            $retried = MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
            self::assertSame(1, $retried->getObjectMetadata($bucket, 'Case')?->size);
            self::assertSame(2, $retried->getObjectMetadata($bucket, 'case')?->size);
            $row = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertNotNull($row);
            self::assertSame(MysqlSchema::VERSION, (int) $row['version']);
            self::assertSame(2 + $scaleCount, $retried->getBucketStorageStats($bucket)['objectCount']);
        } finally {
            MetadataStoreFactory::create('mysql', ['dsn' => $dsn]);
            $pool->execute('DELETE FROM s3_tagging WHERE bucket = ?', [$bucket]);
            $pool->execute('DELETE FROM s3_objects WHERE bucket = ?', [$bucket]);
            $metadata->deleteBucket($ownerId, $bucket);
            $pool->close();
        }
    }

    private static function migrationScaleCount(string $dsn): int
    {
        $raw = getenv('S3_TEST_MIGRATION_SCALE_OBJECTS');
        if ($raw === false || $raw === '') {
            return 0;
        }

        $max = getenv('S3_TEST_MIGRATION_100M_PROBE') === '1' ? 100000000 : 10000000;
        $count = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => $max]]);
        if ($count === false) {
            throw new \InvalidArgumentException(sprintf('S3_TEST_MIGRATION_SCALE_OBJECTS must be between 1 and %d.', $max));
        }
        if (preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn) !== 1) {
            throw new \InvalidArgumentException('Scale migrations require a disposable dbname starting with s3audit_.');
        }

        return $count;
    }

    private static function seedMigrationObjects(
        MysqlConnectionPool|PostgresConnectionPool $pool,
        string $bucket,
        string $ownerId,
        int $count,
    ): void {
        if (preg_match('/^[a-z0-9-]+$/', $bucket) !== 1 || preg_match('/^[a-z0-9-]+$/', $ownerId) !== 1) {
            throw new \InvalidArgumentException('Migration seed identifiers must be generated alphanumeric test values.');
        }

        $progressPath = getenv('S3_TEST_MIGRATION_PROGRESS_FILE');
        if ($progressPath !== false && $progressPath !== '') {
            self::recordMigrationProbeEvent($progressPath, 'seed', 0);
        }

        for ($offset = 0; $offset < $count; $offset += 500) {
            $rows = [];
            $tagRows = [];
            for ($number = $offset; $number < min($offset + 500, $count); $number++) {
                $key = sprintf('scale-%08d', $number);
                $rows[] = sprintf("('%s', '%s', '%s', '\"scale\"', 1, 'scale-path-%08d', '{}')", $bucket, $key, $ownerId, $number);
                if ($number % 10 === 0) {
                    $tagRows[] = sprintf("('object', '%s', '%s', 'state', 'scale')", $bucket, $key);
                }
            }
            // The fixture values are generated and validated above. Plain SQL
            // avoids stressing the MySQL binary protocol with hundreds of
            // parameters per synthetic bulk statement before migration starts.
            $pool->query('INSERT INTO s3_objects (bucket, key_name, owner_id, etag, size, storage_path, user_metadata) VALUES ' . implode(', ', $rows));
            if ($tagRows !== []) {
                $pool->query('INSERT INTO s3_tagging (resource_type, bucket, key_name, tag_key, tag_value) VALUES ' . implode(', ', $tagRows));
            }
            if ($progressPath !== false && $progressPath !== '') {
                self::recordMigrationProbeEvent($progressPath, 'seed', min($offset + 500, $count));
            }
        }
    }

    private static function recordMigrationProbeEvent(string $path, string $phase, int $completedRows): void
    {
        $line = sprintf("%s\t%s\t%d\n", gmdate('Y-m-d\TH:i:s\Z'), $phase, $completedRows);
        if (file_put_contents($path, $line, FILE_APPEND | LOCK_EX) !== strlen($line)) {
            throw new \RuntimeException('Could not persist migration seed progress.');
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
