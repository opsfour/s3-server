<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/** Real SQL transactions with faults injected only at the worker's commit boundary. */
final class ExternalWorkerCommitFaultTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_restore_keeps_published_copy_after_lost_commit_ack(string $driver, string $dsnEnv): void
    {
        [$metadata, $observer, $bucket, $hot, $cold, $source] = $this->restoreFixture($driver, $dsnEnv);
        $jobId = $metadata->enqueueRestoreJob($bucket, 'archive.bin', null, 'GLACIER', $source, 1);
        $faulting = $this->faultingStore($metadata, transaction: 2, afterCommit: true);

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new RestoreExecutor($faulting, $this->tiers($hot, $cold)))->processNext(1),
        );
        $restored = $observer->getObjectMetadata($bucket, 'archive.bin');
        self::assertNotNull($restored);
        self::assertSame('restored', $restored->restoreStatus);
        self::assertSame('STANDARD', $restored->restoredStorageTier);
        self::assertNotNull($restored->restoredStoragePath);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->restoredStoragePath)));
        self::assertSame('cold-data', \Amp\ByteStream\buffer($cold->getObjectByPath($source)));
        self::assertSame('completed', $observer->getRestoreJob($jobId)['status'] ?? null);
    }

    #[DataProvider('databases')]
    public function test_restore_removes_unpublished_copy_after_rollback(string $driver, string $dsnEnv): void
    {
        [$metadata, $observer, $bucket, $hot, $cold, $source] = $this->restoreFixture($driver, $dsnEnv);
        $jobId = $metadata->enqueueRestoreJob($bucket, 'archive.bin', null, 'GLACIER', $source, 1);
        $faulting = $this->faultingStore($metadata, transaction: 2, afterCommit: false);

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new RestoreExecutor($faulting, $this->tiers($hot, $cold)))->processNext(1),
        );
        self::assertNull($observer->getObjectMetadata($bucket, 'archive.bin')?->restoredStoragePath);
        self::assertSame('processing', $observer->getRestoreJob($jobId)['status'] ?? null);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($cold->getObjectByPath($source)));
        $this->assertPathMissing($hot, "memory://{$bucket}/archive.bin/1");
        $this->expireJobLease($driver, $dsnEnv, 's3_restore_jobs', $jobId);
        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new RestoreExecutor($metadata, $this->tiers($hot, $cold)))->processNext(1),
        );
        self::assertSame('completed', $observer->getRestoreJob($jobId)['status'] ?? null);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath(
            (string) $observer->getObjectMetadata($bucket, 'archive.bin')?->restoredStoragePath,
        )));
    }

    #[DataProvider('databases')]
    public function test_transition_retains_exact_old_path_queue_after_lost_commit_ack(string $driver, string $dsnEnv): void
    {
        [$metadata, $observer, $bucket, $hot, $cold, $source] = $this->transitionFixture($driver, $dsnEnv);
        $jobId = $metadata->enqueueTierTransitionJob($bucket, 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source);
        $faulting = $this->faultingStore($metadata, transaction: 2, afterCommit: true);

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($faulting, $this->tiers($hot, $cold)))->processNext(1),
        );
        $this->assertTransitionAndCollect($driver, $dsnEnv, $observer, $bucket, $hot, $cold, $source, $jobId);
    }

    #[DataProvider('databases')]
    public function test_transition_retains_exact_old_path_queue_when_post_commit_delete_fails(string $driver, string $dsnEnv): void
    {
        [$metadata, $observer, $bucket, $hot, $cold, $source] = $this->transitionFixture($driver, $dsnEnv);
        $jobId = $metadata->enqueueTierTransitionJob($bucket, 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source);
        $failingHot = new CallbackStorageBackend($hot, beforeDelete: static function (): never {
            throw new \RuntimeException('Injected old-path delete failure.');
        });

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($metadata, $this->tiers($failingHot, $cold)))->processNext(1),
        );
        $this->assertTransitionAndCollect($driver, $dsnEnv, $observer, $bucket, $hot, $cold, $source, $jobId);
    }

    #[DataProvider('databases')]
    public function test_transition_replays_cleanup_after_delete_succeeds_but_ack_fails(string $driver, string $dsnEnv): void
    {
        [$metadata, $observer, $bucket, $hot, $cold, $source] = $this->transitionFixture($driver, $dsnEnv);
        $jobId = $metadata->enqueueTierTransitionJob($bucket, 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source);
        $failingHot = new CallbackStorageBackend($hot, afterDelete: static function (): never {
            throw new \RuntimeException('Injected lost old-path delete acknowledgement.');
        });

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($metadata, $this->tiers($failingHot, $cold)))->processNext(1),
        );
        $this->assertTransitionAndCollect($driver, $dsnEnv, $observer, $bucket, $hot, $cold, $source, $jobId, sourceExists: false);
    }

    #[DataProvider('databases')]
    public function test_transition_rolls_back_placement_and_removes_unpublished_copy(string $driver, string $dsnEnv): void
    {
        [$metadata, $observer, $bucket, $hot, $cold, $source] = $this->transitionFixture($driver, $dsnEnv);
        $jobId = $metadata->enqueueTierTransitionJob($bucket, 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source);
        $faulting = $this->faultingStore($metadata, transaction: 2, afterCommit: false);

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($faulting, $this->tiers($hot, $cold)))->processNext(1),
        );
        $object = $observer->getObjectMetadata($bucket, 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame($source, $object->systemMetadata['storagePath'] ?? null);
        self::assertSame('processing', $observer->getTierTransitionJob($jobId)['status'] ?? null);
        self::assertSame('hot-data', \Amp\ByteStream\buffer($hot->getObjectByPath($source)));
        $this->assertPathMissing($cold, "memory://{$bucket}/archive.bin/1");
        $this->expireJobLease($driver, $dsnEnv, 's3_tier_transition_jobs', $jobId);
        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($metadata, $this->tiers($hot, $cold)))->processNext(1),
        );
        self::assertSame('completed', $observer->getTierTransitionJob($jobId)['status'] ?? null);
        self::assertSame('GLACIER', $observer->getObjectMetadata($bucket, 'archive.bin')?->storageTier);
        $this->assertPathMissing($hot, $source);
    }

    /** @return array{MetadataStore, MetadataStore, string, InMemoryBackend, InMemoryBackend, string} */
    private function restoreFixture(string $driver, string $dsnEnv): array
    {
        [$metadata, $observer, $bucket] = $this->stores($driver, $dsnEnv);
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket($bucket);
        $cold->createBucket($bucket);
        $write = $cold->putObject($bucket, 'archive.bin', new ReadableBuffer('cold-data'));
        $metadata->putObjectMetadata($bucket, 'archive.bin', 'owner', $write->size, '"' . $write->md5Hex . '"', 'application/octet-stream', $write->path);
        $metadata->updateObjectPlacement($bucket, 'archive.bin', null, 'GLACIER', 'GLACIER', $write->path);

        return [$metadata, $observer, $bucket, $hot, $cold, $write->path];
    }

    /** @return array{MetadataStore, MetadataStore, string, InMemoryBackend, InMemoryBackend, string} */
    private function transitionFixture(string $driver, string $dsnEnv): array
    {
        [$metadata, $observer, $bucket] = $this->stores($driver, $dsnEnv);
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket($bucket);
        $cold->createBucket($bucket);
        $write = $hot->putObject($bucket, 'archive.bin', new ReadableBuffer('hot-data'));
        $metadata->putObjectMetadata($bucket, 'archive.bin', 'owner', $write->size, '"' . $write->md5Hex . '"', 'application/octet-stream', $write->path);

        return [$metadata, $observer, $bucket, $hot, $cold, $write->path];
    }

    /** @return array{MetadataStore, MetadataStore, string} */
    private function stores(string $driver, string $dsnEnv): array
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} worker fault gate.");
        }
        $config = ['dsn' => trim($dsn)];
        $metadata = MetadataStoreFactory::create($driver, $config);
        $observer = MetadataStoreFactory::create($driver, $config, migrate: false);
        $bucket = 'worker-fault-' . bin2hex(random_bytes(8));
        $metadata->createBucket('owner', $bucket, 'us-east-1');

        return [$metadata, $observer, $bucket];
    }

    private function tiers(\OpsFour\S3Server\Storage\StorageBackend $hot, InMemoryBackend $cold): StorageTierRegistry
    {
        return new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]);
    }

    private function faultingStore(MetadataStore $metadata, int $transaction, bool $afterCommit): MetadataStore
    {
        $proxy = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            FencedQueueStore::class,
            RestoredCopyTierStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $proxy);
        foreach ([
            'dequeueRestoreJobs', 'dequeueTierTransitionJobs', 'getBucketOwner', 'lockOwnerForUpdate',
            'getObjectMetadata', 'getObjectMetadataByVersion', 'getRestoreJob', 'getTierTransitionJob',
            'renewRestoreJobLeaseFenced', 'renewTierTransitionJobLeaseFenced',
            'updateRestoreJobStatusLease', 'updateTierTransitionJobStatusLease',
            'updateObjectRestoreStateWithTier', 'updateObjectRestoreState', 'updateObjectPlacement',
            'enqueueStorageGarbage', 'discardStorageGarbage',
        ] as $method) {
            if (! is_callable([$metadata, $method])) {
                throw new \LogicException("Fault proxy cannot delegate {$method}.");
            }
            $proxy->method($method)->willReturnCallback(\Closure::fromCallable([$metadata, $method]));
        }
        $transactions = 0;
        $proxy->method('transaction')->willReturnCallback(static function (callable $callback) use ($metadata, $transaction, $afterCommit, &$transactions): mixed {
            $transactions++;
            $inject = $transactions === $transaction;
            $result = $metadata->transaction(static function () use ($callback, $inject, $afterCommit): mixed {
                $value = $callback();
                if ($inject && ! $afterCommit) {
                    throw new \RuntimeException('Injected worker publication rollback.');
                }

                return $value;
            });
            if ($inject && $afterCommit) {
                throw new \RuntimeException('Injected lost worker commit acknowledgement.');
            }

            return $result;
        });

        return $proxy;
    }

    private function assertTransitionAndCollect(string $driver, string $dsnEnv, MetadataStore $metadata, string $bucket, InMemoryBackend $hot, InMemoryBackend $cold, string $source, int $jobId, bool $sourceExists = true): void
    {
        $object = $metadata->getObjectMetadata($bucket, 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame('completed', $metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $target = $object->systemMetadata['storagePath'] ?? null;
        self::assertIsString($target);
        if ($sourceExists) {
            self::assertSame('hot-data', \Amp\ByteStream\buffer($hot->getObjectByPath($source)));
        } else {
            $this->assertPathMissing($hot, $source);
        }
        self::assertSame('hot-data', \Amp\ByteStream\buffer($cold->getObjectByPath($target)));

        $dsn = (string) getenv($dsnEnv);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        try {
            $rows = $pool->execute(
                $driver === 'postgres'
                ? 'SELECT storage_tier, storage_path FROM s3_storage_garbage WHERE bucket = $1 AND operation = $2'
                : 'SELECT storage_tier, storage_path FROM s3_storage_garbage WHERE bucket = ? AND operation = ?',
                [$bucket, 'delete_path'],
            );
            self::assertSame(['storage_tier' => 'STANDARD', 'storage_path' => $source], $rows->fetchRow());
            self::assertNull($rows->fetchRow());
        } finally {
            $pool->close();
        }

        self::assertSame(1, (new StorageGarbageCollector($metadata, $this->tiers($hot, $cold)))->collect(1));
        $this->assertPathMissing($hot, $source);
        self::assertSame('hot-data', \Amp\ByteStream\buffer($cold->getObjectByPath($target)));
    }

    private function expireJobLease(string $driver, string $dsnEnv, string $table, int $jobId): void
    {
        self::assertContains($table, ['s3_restore_jobs', 's3_tier_transition_jobs']);
        $dsn = (string) getenv($dsnEnv);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        try {
            $pool->execute($driver === 'postgres'
                ? "UPDATE {$table} SET next_attempt_at = 0 WHERE id = $1"
                : "UPDATE {$table} SET next_attempt_at = 0 WHERE id = ?", [$jobId]);
        } finally {
            $pool->close();
        }
    }

    private function assertPathMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            \Amp\ByteStream\buffer($storage->getObjectByPath($path));
            self::fail('Expected unpublished or collected storage path to be absent.');
        } catch (NoSuchKeyException) {
            return;
        }
    }
}
