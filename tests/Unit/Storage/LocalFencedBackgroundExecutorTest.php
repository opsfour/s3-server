<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseSql;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use PHPUnit\Framework\TestCase;

final class LocalFencedBackgroundExecutorTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private FilesystemBackend $local;

    private LocalFencedObjectWriter $writer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-fenced-background-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base . '/objects', 0700, true));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->local = new FilesystemBackend($this->base . '/objects');
        $this->local->createBucket('bucket');
        $this->writer = new LocalFencedObjectWriter($this->base . '/objects', $this->base . '/scratch', 'LOCAL');
    }

    protected function tearDown(): void
    {
        $this->writer->shutdown();
        $this->local->shutdown();
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->base);
    }

    public function test_restore_consumes_exact_dispatch_in_restore_metadata_commit(): void
    {
        [$remote, $path] = $this->remoteSource('GLACIER');
        $this->metadata->enqueueRestoreJob('bucket', 'key', null, 'GLACIER', $path, 1);
        $tiers = new StorageTierRegistry([
            new StorageTier('LOCAL', $this->local, defaultWriteTier: true),
            new StorageTier('GLACIER', $remote, restoreRequired: true),
        ]);

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new RestoreExecutor($this->metadata, $tiers, localFencedWriter: $this->adapter()))->processNext(1),
        );
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('LOCAL', $object->restoredStorageTier);
        self::assertIsString($object->restoredStoragePath);
        self::assertSame('payload', file_get_contents($object->restoredStoragePath));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($object->restoredStoragePath, -32)));
    }

    public function test_transition_consumes_exact_dispatch_in_placement_commit(): void
    {
        [$remote, $path] = $this->remoteSource('STANDARD');
        $this->metadata->enqueueTierTransitionJob('bucket', 'key', null, 'STANDARD', 'LOCAL', 'STANDARD', $path);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $remote, defaultWriteTier: true),
            new StorageTier('LOCAL', $this->local),
        ]);

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($this->metadata, $tiers, localFencedWriter: $this->adapter()))->processNext(1),
        );
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('LOCAL', $object->storageTier);
        $localPath = $object->systemMetadata['storagePath'] ?? null;
        self::assertIsString($localPath);
        self::assertSame('payload', file_get_contents($localPath));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($localPath, -32)));
    }

    public function test_lost_restore_commit_acknowledgement_keeps_published_fenced_bytes(): void
    {
        [$remote, $path] = $this->remoteSource('GLACIER');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'key', null, 'GLACIER', $path, 1);
        $tiers = new StorageTierRegistry([
            new StorageTier('LOCAL', $this->local, defaultWriteTier: true),
            new StorageTier('GLACIER', $remote, restoreRequired: true),
        ]);
        $proxy = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            RestoredCopyTierStore::class,
            ObjectWriteIntentStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $proxy);
        foreach ([
            'dequeueRestoreJobs', 'getBucketOwner', 'getBucket', 'lockOwnerForUpdate',
            'getObjectMetadata', 'getObjectMetadataByVersion', 'getRestoreJob',
            'renewRestoreJobLease', 'updateRestoreJobStatus',
            'updateObjectRestoreStateWithTier', 'consumeArmedObjectWriteIntent',
        ] as $method) {
            $proxy->method($method)->willReturnCallback(\Closure::fromCallable([$this->metadata, $method]));
        }
        $transactions = 0;
        $proxy->method('transaction')->willReturnCallback(function (callable $callback) use (&$transactions): mixed {
            $transactions++;
            $result = $this->metadata->transaction($callback);
            if ($transactions === 2) {
                throw new \RuntimeException('Injected loss of restore commit acknowledgement.');
            }

            return $result;
        });

        (new RestoreExecutor($proxy, $tiers, localFencedWriter: $this->adapter()))->processNext(1);

        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('completed', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        self::assertIsString($object->restoredStoragePath);
        self::assertSame('payload', file_get_contents($object->restoredStoragePath));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($object->restoredStoragePath, -32)));
    }

    public function test_lost_token_retains_transition_bytes_for_collector_without_switching_metadata(): void
    {
        [$remote, $sourcePath] = $this->remoteSource('STANDARD');
        $this->metadata->enqueueTierTransitionJob('bucket', 'key', null, 'STANDARD', 'LOCAL', 'STANDARD', $sourcePath, maxAttempts: 1);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $remote, defaultWriteTier: true),
            new StorageTier('LOCAL', $this->local),
        ]);
        $adapter = $this->adapter(function (string $attemptId): void {
            $this->metadata->abandonObjectWriteIntent($attemptId);
        });

        $stats = (new TierTransitionExecutor($this->metadata, $tiers, localFencedWriter: $adapter))->processNext(1);
        self::assertSame(1, $stats['processed']);
        self::assertSame('STANDARD', $this->metadata->getObjectMetadata('bucket', 'key')?->storageTier);
        $paths = glob($this->base . '/objects/bucket/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('payload', file_get_contents($paths[0]));
        $attemptId = substr($paths[0], -32);
        self::assertNotNull($this->metadata->getObjectWriteIntent($attemptId));
        $collector = new LocalObjectWriteIntentCollector($this->metadata, $tiers, 'LOCAL');
        try {
            self::assertSame(1, $collector->collect(1));
            self::assertSame('sealed', $this->metadata->getObjectWriteIntent($attemptId)?->state);
            self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($paths[0]));
        } finally {
            $collector->shutdown();
        }
    }

    public function test_stale_queue_result_does_not_consume_unpublished_local_intent(): void
    {
        [$remote, $sourcePath] = $this->remoteSource('STANDARD');
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'key', null, 'STANDARD', 'LOCAL', 'STANDARD', $sourcePath);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $remote, defaultWriteTier: true),
            new StorageTier('LOCAL', $this->local),
        ]);
        $adapter = $this->adapter(function () use ($jobId): void {
            self::assertTrue($this->metadata->updateTierTransitionJobStatus($jobId, 'completed', incrementAttempts: false));
        });

        (new TierTransitionExecutor($this->metadata, $tiers, localFencedWriter: $adapter))->processNext(1);

        self::assertSame('STANDARD', $this->metadata->getObjectMetadata('bucket', 'key')?->storageTier);
        $paths = glob($this->base . '/objects/bucket/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('payload', file_get_contents($paths[0]));
        self::assertNotNull($this->metadata->getObjectWriteIntent(substr($paths[0], -32)));
    }

    /** @return array{InMemoryBackend, string} */
    private function remoteSource(string $tier): array
    {
        $remote = new InMemoryBackend();
        $remote->createBucket('bucket');
        $write = $remote->putObject('bucket', 'key', new ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', $write->size, '"' . $write->md5Hex . '"', 'text/plain', $write->path);
        $this->metadata->updateObjectPlacement('bucket', 'key', null, $tier === 'GLACIER' ? 'GLACIER' : 'STANDARD', $tier, $write->path);

        return [$remote, $write->path];
    }

    /** @param null|\Closure(string):void $afterArm */
    private function adapter(?\Closure $afterArm = null): LocalFencedBackgroundObjectWriter
    {
        $factory = new class ($this->metadata, $afterArm) implements LocalObjectWriteLeaseGuardFactory {
            private LocalObjectWriteLeaseAdmission $admission;

            public function __construct(private SqliteMetadataStore $metadata, private ?\Closure $afterArm)
            {
                $this->admission = new LocalObjectWriteLeaseAdmission(2);
            }

            public function reserve(ObjectWriteIntent $planned): LocalObjectWriteLeaseGuard
            {
                $sql = new class ($this->metadata, $this->afterArm) implements LocalObjectWriteLeaseSql {
                    public function __construct(private SqliteMetadataStore $metadata, private ?\Closure $afterArm) {}

                    public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent
                    {
                        return $this->metadata->reserveObjectWriteIntent($planned, $leaseSeconds);
                    }

                    public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
                    {
                        $armed = $this->metadata->armObjectWriteIntent($current->attemptId, $current->leaseUntil, $dispatchId, $leaseSeconds);
                        ($this->afterArm)?->__invoke($current->attemptId);

                        return $armed;
                    }
                };
                $renewer = new class ($this->metadata) implements LocalObjectWriteLeaseRenewer {
                    public function __construct(private SqliteMetadataStore $metadata) {}

                    public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
                    {
                        return $this->metadata->renewObjectWriteIntent($current->attemptId, $current->leaseUntil, $leaseSeconds);
                    }
                };
                $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
                $guard = $method->invoke(null, $planned, $sql, $renewer, $this->admission);
                if (! $guard instanceof LocalObjectWriteLeaseGuard) {
                    throw new \LogicException('Lease guard test seam returned another type.');
                }

                return $guard;
            }
        };

        return new LocalFencedBackgroundObjectWriter($this->writer, $factory);
    }
}
