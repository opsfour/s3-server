<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Lifecycle;

use Amp\ByteStream\ReadableBuffer;
use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

final class LifecycleExecutorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    private FilesystemBackend $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-lifecycle-' . bin2hex(random_bytes(4));
        mkdir($this->path, 0o755, true);

        $this->metadata = new SqliteMetadataStore($this->path . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->storage = new FilesystemBackend($this->path . '/objects');
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_dir($this->path)) {
            $this->recursiveDelete($this->path);
        }

        parent::tearDown();
    }

    public function test_lifecycle_sweeps_stale_files_in_all_observed_filesystem_tiers(): void
    {
        $filesystem = new Filesystem(new BlockingFilesystemDriver());
        $metrics = new MetricsCollector();
        $backends = [];
        $stalePaths = [];
        foreach (['hot', 'cold'] as $name) {
            $root = $this->path . '/' . $name;
            $tmp = $root . '/.tmp';
            self::assertTrue(mkdir($tmp, 0700, true));
            $stale = $tmp . '/' . bin2hex(random_bytes(16));
            self::assertSame(5, file_put_contents($stale, 'stale'));
            self::assertTrue(touch($stale, time() - 7200));
            $stalePaths[] = $stale;
            $backends[$name] = new ObservedStorageBackend(
                new FilesystemBackend($root, $filesystem, $filesystem),
                $metrics,
                'filesystem:' . $name,
            );
        }

        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $backends['hot'], defaultWriteTier: true),
            new StorageTier('COLD', $backends['cold']),
        ]);
        (new LifecycleExecutor($this->metadata, $backends['hot'], storageTiers: $tiers))->execute();

        foreach ($stalePaths as $stale) {
            self::assertFileDoesNotExist($stale);
        }
    }

    #[DataProvider('stopKinds')]
    public function test_cancelled_sweep_does_not_start_stale_temp_cleanup(string $stopKind): void
    {
        $tmp = $this->path . '/objects/.tmp';
        self::assertTrue(mkdir($tmp, 0700, true));
        $stale = $tmp . '/stale';
        self::assertSame(5, file_put_contents($stale, 'stale'));
        self::assertTrue(touch($stale, time() - 7200));

        $executor = new LifecycleExecutor($this->metadata, $this->storage);
        if ($stopKind === 'direct') {
            $executor->requestStop();
        } else {
            $executor->requestRunnerStop();
        }

        $executor->execute();

        self::assertFileExists($stale);
    }

    /** @return iterable<string, array{string}> */
    public static function stopKinds(): iterable
    {
        yield 'direct' => ['direct'];
        yield 'runner' => ['runner'];
    }

    public function test_runner_stop_rejects_a_new_physical_delete(): void
    {
        $this->createBucket('bucket');
        $written = $this->putObject('bucket', 'object.txt', 'payload');
        $object = $this->metadata->getObjectMetadata('bucket', 'object.txt');
        self::assertNotNull($object);
        $executor = new LifecycleExecutor($this->metadata, $this->storage);
        $executor->requestRunnerStop();

        try {
            (new \ReflectionClass(LifecycleExecutor::class))->getMethod('deleteStoredData')
                ->invoke($executor, $object, 'expire_current');
            self::fail('Lifecycle launched a physical delete after stop.');
        } catch (OperationAbortedException) {
            self::assertFileExists($written->path);
        }
    }

    public function test_runner_stop_rejects_a_new_multipart_abort_cleanup(): void
    {
        $this->createBucket('bucket');
        $uploadId = $this->createMultipartUploadWithPart('bucket', 'object.bin');
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);
        $executor = new LifecycleExecutor($this->metadata, $this->storage);
        $executor->requestRunnerStop();

        try {
            (new \ReflectionClass(LifecycleExecutor::class))->getMethod('abortMultipartUploadDurably')
                ->invoke($executor, 'bucket', 'object.bin', $uploadId);
            self::fail('Lifecycle launched multipart cleanup after stop.');
        } catch (OperationAbortedException) {
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertFileExists($parts[0]['storage_path']);
        }
    }

    public function test_expiration_deletes_current_objects_and_storage_by_prefix(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/old.txt', 'expired');
        $keptByPrefix = $this->putObject('bucket', 'data/old.txt', 'kept-prefix');
        $keptByAge = $this->putObject('bucket', 'logs/new.txt', 'kept-age');

        $this->ageObject('bucket', 'logs/old.txt', 5);
        $this->ageObject('bucket', 'data/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'data/old.txt'));
        $this->assertFileExists($keptByPrefix->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/new.txt'));
        $this->assertFileExists($keptByAge->path);
    }

    public function test_stored_overflowing_lifecycle_days_fail_closed_without_deleting_objects(): void
    {
        $this->createBucket('bucket');
        $write = $this->putObject('bucket', 'old.txt', 'payload');
        $this->ageObject('bucket', 'old.txt', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'unsafe-days',
            'status' => 'Enabled',
            'prefix' => '',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => PHP_INT_MAX],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        (new LifecycleExecutor($this->metadata, $this->storage))->execute();

        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'old.txt'));
        self::assertFileExists($write->path);
    }

    public function test_expiration_keeps_metadata_and_bytes_when_cleanup_queue_insert_fails(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $connection = (new \ReflectionClass($this->metadata))->getMethod('connection')->invoke($this->metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $secret = 'backend-password-private-test-token';
        $connection->exec("CREATE TRIGGER reject_lifecycle_garbage BEFORE INSERT ON s3_storage_garbage BEGIN SELECT RAISE(ABORT, '$secret'); END");
        $logger = new LifecycleArrayLogger();
        (new LifecycleExecutor($this->metadata, $this->storage, $logger))->processBucket('bucket');
        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        self::assertFileExists($expired->path);
        self::assertStringNotContainsString($secret, json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertNotNull($logger->findContext('rule_failed', ['exception' => \PDOException::class]));

        $connection->exec('DROP TRIGGER reject_lifecycle_garbage');
        // The first sweep clears the cursor recorded before the failed action.
        $this->executor()->processBucket('bucket');
        $this->executor()->processBucket('bucket');
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        self::assertFileDoesNotExist($expired->path);
    }

    public function test_expiration_keeps_exact_path_queued_when_physical_delete_fails(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
        $failingStorage = new CallbackStorageBackend(
            $this->storage,
            beforeDelete: static function (): never {
                throw new \RuntimeException('physical delete unavailable');
            },
        );

        (new LifecycleExecutor($this->metadata, $failingStorage))->processBucket('bucket');
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        self::assertFileExists($expired->path);
        self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect(1));
        self::assertFileDoesNotExist($expired->path);
    }

    public function test_storage_cancellation_stops_bucket_sweep_and_keeps_cleanup_queued(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'expired.txt', 'expired');
        $this->ageObject('bucket', 'expired.txt', 5);
        $this->configureOneDayExpiration();
        $cancelledStorage = new CallbackStorageBackend(
            $this->storage,
            beforeDelete: static function (): never {
                throw new \Amp\CancelledException();
            },
        );

        try {
            (new LifecycleExecutor($this->metadata, $cancelledStorage))->processBucket('bucket');
            self::fail('Expected backend cancellation to stop lifecycle processing.');
        } catch (\Amp\CancelledException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'expired.txt'));
            self::assertFileExists($expired->path);
            self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect(1));
            self::assertFileDoesNotExist($expired->path);
        }
    }

    public function test_suspended_expiration_preserves_the_current_numbered_version_payload(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $numbered = $this->putVersionedObject('bucket', 'retained.txt', 'numbered payload');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->ageVersion('bucket', 'retained.txt', $numbered['versionId'], 5);
        $this->configureOneDayExpiration();

        $this->executor()->processBucket('bucket');

        $retained = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $numbered['versionId']);
        self::assertNotNull($retained);
        self::assertFileExists($numbered['path']);
        self::assertSame('numbered payload', \Amp\ByteStream\buffer($this->storage->getObjectByPath($numbered['path'])));
        self::assertTrue($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', 'null')?->isDeleteMarker);
    }

    public function test_suspended_expiration_replaces_only_an_older_null_version_payload(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $olderNumbered = $this->putVersionedObject('bucket', 'retained.txt', 'older numbered');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $oldNull = $this->putObject('bucket', 'retained.txt', 'old null payload');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $latestNumbered = $this->putVersionedObject('bucket', 'retained.txt', 'latest numbered');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->ageVersion('bucket', 'retained.txt', $latestNumbered['versionId'], 5);
        $this->configureOneDayExpiration();

        $this->executor()->processBucket('bucket');

        self::assertTrue($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', 'null')?->isDeleteMarker);
        self::assertFileDoesNotExist($oldNull->path);
        foreach ([$olderNumbered, $latestNumbered] as $numbered) {
            self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $numbered['versionId']));
            self::assertFileExists($numbered['path']);
        }
    }

    public function test_suspended_expiration_does_not_replace_a_protected_null_version(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->putVersionedObject('bucket', 'retained.txt', 'older numbered');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $oldNull = $this->putObject('bucket', 'retained.txt', 'locked null payload');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $latestNumbered = $this->putVersionedObject('bucket', 'retained.txt', 'latest numbered');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->ageVersion('bucket', 'retained.txt', $latestNumbered['versionId'], 5);
        $this->metadata->putObjectRetention(
            'bucket',
            'retained.txt',
            'COMPLIANCE',
            (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM),
            'null',
        );
        $this->configureOneDayExpiration();

        $this->executor()->processBucket('bucket');

        self::assertFalse($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', 'null')?->isDeleteMarker);
        self::assertFileExists($oldNull->path);
        self::assertFileExists($latestNumbered['path']);
        self::assertSame($latestNumbered['versionId'], $this->metadata->getObjectMetadata('bucket', 'retained.txt')?->versionId);
    }

    #[DataProvider('recreatedBucketOwners')]
    public function test_old_lifecycle_rules_cannot_delete_objects_after_bucket_name_is_reused(string $newOwner): void
    {
        $this->createBucket('bucket');
        $old = $this->putObject('bucket', 'a/old.txt', 'old');
        $this->ageObject('bucket', 'a/old.txt', 5);
        $rules = [];
        foreach (['a/', 'b/'] as $prefix) {
            $rules[] = [
                'id' => 'expire-' . $prefix,
                'status' => 'Enabled',
                'prefix' => $prefix,
                'filter' => null,
                'transitions' => null,
                'expiration' => ['days' => 1],
                'noncurrentTransitions' => null,
                'noncurrentExpiration' => null,
                'abortIncompleteDays' => null,
            ];
        }
        $this->metadata->putBucketLifecycle('bucket', $rules);

        $newWrite = null;
        $storage = new CallbackStorageBackend($this->storage, afterDelete: function () use (&$newWrite, $newOwner): void {
            if ($newWrite !== null) {
                return;
            }
            $this->metadata->deleteBucket('owner', 'bucket');
            $this->metadata->createBucket($newOwner, 'bucket', 'us-east-1');
            $newWrite = $this->storage->putObject('bucket', 'b/new.txt', new ReadableBuffer('new'));
            $this->metadata->putObjectMetadata(
                'bucket',
                'b/new.txt',
                $newOwner,
                $newWrite->size,
                '"' . $newWrite->md5Hex . '"',
                'text/plain',
                $newWrite->path,
            );
            $this->ageObject('bucket', 'b/new.txt', 5);
        });

        $this->expectException(OperationAbortedException::class);
        try {
            (new LifecycleExecutor($this->metadata, $storage))->processBucket('bucket');
        } finally {
            self::assertNotNull($newWrite);
            self::assertSame($newOwner, $this->metadata->getBucketOwner('bucket'));
            self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'b/new.txt'));
            self::assertFileExists($newWrite->path);
            self::assertFileDoesNotExist($old->path);
        }
    }

    /** @return iterable<string, array{string}> */
    public static function recreatedBucketOwners(): iterable
    {
        yield 'different owner' => ['new-owner'];
        yield 'same owner' => ['owner'];
    }

    public function test_stale_multipart_cleanup_rejects_reused_bucket_generation(): void
    {
        $this->createBucket('bucket');
        $observedBucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($observedBucket);
        $this->metadata->deleteBucket('owner', 'bucket');
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $uploadId = $this->createMultipartUploadWithPart('bucket', 'new-generation.bin');
        $this->ageMultipartUpload($uploadId, 10);
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);

        $executor = new LifecycleExecutor($this->metadata, $this->storage);
        $cleanup = (new \ReflectionClass($executor))->getMethod('cleanupStaleMultipartUploads');
        $this->expectException(OperationAbortedException::class);
        try {
            $cleanup->invoke($executor, $observedBucket);
        } finally {
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertSame($parts, $this->metadata->getParts($uploadId));
        }
    }

    public function test_noncurrent_version_expiration_deletes_only_old_noncurrent_versions(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $oldVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v1');
        $latestVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v2');
        $this->ageVersion('bucket', 'versioned.txt', $oldVersion['versionId'], 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $oldVersion['versionId']));
        $this->assertFileDoesNotExist($oldVersion['path']);
        $this->assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $latestVersion['versionId']));
        $this->assertFileExists($latestVersion['path']);
    }

    public function test_noncurrent_expiration_handles_suspended_null_versions_and_respects_legal_hold(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $protected = $this->putObject('bucket', 'a-protected.txt', 'held-null');
        $expired = $this->putObject('bucket', 'z-expired.txt', 'old-null');
        $this->metadata->putObjectLegalHold('bucket', 'a-protected.txt', 'ON', 'null');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $currentProtected = $this->putVersionedObject('bucket', 'a-protected.txt', 'current');
        $currentExpired = $this->putVersionedObject('bucket', 'z-expired.txt', 'current');
        $this->ageVersion('bucket', 'a-protected.txt', 'null', 5);
        $this->ageVersion('bucket', 'z-expired.txt', 'null', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);

        (new LifecycleExecutor($this->metadata, $this->storage, batchSize: 1, maxActionsPerRun: 5))->processBucket('bucket');

        self::assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'z-expired.txt', 'null'));
        self::assertFileDoesNotExist($expired->path);
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'a-protected.txt', 'null'));
        self::assertFileExists($protected->path);
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'z-expired.txt', $currentExpired['versionId']));
        self::assertFileExists($currentExpired['path']);
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'a-protected.txt', $currentProtected['versionId']));
        self::assertFileExists($currentProtected['path']);
    }

    public function test_noncurrent_expiration_does_not_delete_a_version_promoted_after_listing(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $version = $this->putVersionedObject('bucket', 'retained.txt', 'retained payload');
        $markerId = $this->metadata->deleteObjectVersioned('bucket', 'retained.txt', 'owner');
        $this->ageVersion('bucket', 'retained.txt', $version['versionId'], 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);
        $rule = $this->metadata->getBucketLifecycle('bucket')[0];
        $listedAsNoncurrent = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']);
        self::assertNotNull($listedAsNoncurrent);
        self::assertSame('0', $listedAsNoncurrent->systemMetadata['isLatest']);

        // A concurrent client removes the latest delete marker before the
        // lifecycle worker commits its previously selected noncurrent version.
        $this->metadata->deleteObjectVersion('bucket', 'retained.txt', $markerId);
        self::assertSame($version['versionId'], $this->metadata->getObjectMetadata('bucket', 'retained.txt')?->versionId);
        $bucketInfo = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $method = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('deleteNoncurrentVersion');
        $deleted = $method->invoke($this->executor(), $bucketInfo, $listedAsNoncurrent, $rule);

        self::assertFalse($deleted);
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']));
        self::assertFileExists($version['path']);
    }

    public function test_noncurrent_expiration_rechecks_a_fresh_redemotion_after_listing(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $version = $this->putVersionedObject('bucket', 'retained.txt', 'retained payload');
        $markerId = $this->metadata->deleteObjectVersioned('bucket', 'retained.txt', 'owner');
        $this->sql(
            'UPDATE s3_objects SET noncurrent_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?',
            [$this->past(5), 'bucket', 'retained.txt', $version['versionId']],
        );
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);
        $rule = $this->metadata->getBucketLifecycle('bucket')[0];
        $listedAsExpired = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']);
        self::assertNotNull($listedAsExpired);

        $this->metadata->deleteObjectVersion('bucket', 'retained.txt', $markerId);
        $this->putVersionedObject('bucket', 'retained.txt', 'new current payload');
        $redemoted = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']);
        self::assertNotNull($redemoted);
        self::assertGreaterThan(new \DateTimeImmutable('-1 minute'), new \DateTimeImmutable($redemoted->systemMetadata['noncurrentAt']));

        $bucketInfo = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $method = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('deleteNoncurrentVersion');
        self::assertFalse($method->invoke($this->executor(), $bucketInfo, $listedAsExpired, $rule));
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']));
        self::assertFileExists($version['path']);
    }

    public function test_current_expiration_does_not_use_a_rule_disabled_after_listing(): void
    {
        $this->createBucket('bucket');
        $write = $this->putObject('bucket', 'retained.txt', 'retained payload');
        $this->ageObject('bucket', 'retained.txt', 5);
        $this->configureOneDayExpiration();
        $observedBucket = $this->metadata->getBucket('bucket');
        $listedObject = $this->metadata->getObjectMetadata('bucket', 'retained.txt');
        $rule = $this->metadata->getBucketLifecycle('bucket')[0];
        self::assertNotNull($observedBucket);
        self::assertNotNull($listedObject);

        $disabledRule = $rule;
        $disabledRule['status'] = 'Disabled';
        $this->metadata->putBucketLifecycle('bucket', [$disabledRule]);

        $delete = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('deleteObject');
        self::assertFalse($delete->invoke($this->executor(), $observedBucket, $listedObject, $rule));
        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'retained.txt'));
        self::assertFileExists($write->path);
    }

    public function test_noncurrent_expiration_does_not_use_a_rule_changed_after_listing(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $oldVersion = $this->putVersionedObject('bucket', 'retained.txt', 'retained payload');
        $this->putVersionedObject('bucket', 'retained.txt', 'current payload');
        $this->ageVersion('bucket', 'retained.txt', $oldVersion['versionId'], 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);
        $observedBucket = $this->metadata->getBucket('bucket');
        $listedVersion = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $oldVersion['versionId']);
        $rule = $this->metadata->getBucketLifecycle('bucket')[0];
        self::assertNotNull($observedBucket);
        self::assertNotNull($listedVersion);

        $changedRule = $rule;
        $changedRule['noncurrentExpiration'] = ['noncurrentDays' => 365];
        $this->metadata->putBucketLifecycle('bucket', [$changedRule]);

        $delete = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('deleteNoncurrentVersion');
        self::assertFalse($delete->invoke($this->executor(), $observedBucket, $listedVersion, $rule));
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $oldVersion['versionId']));
        self::assertFileExists($oldVersion['path']);
    }

    public function test_orphan_marker_expiration_does_not_use_a_rule_removed_after_listing(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $markerId = $this->metadata->deleteObjectVersioned('bucket', 'retained.txt', 'owner');
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'delete-orphan-markers',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => ['expiredObjectDeleteMarker' => true],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
        $observedBucket = $this->metadata->getBucket('bucket');
        $listedMarker = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $markerId);
        $rule = $this->metadata->getBucketLifecycle('bucket')[0];
        self::assertNotNull($observedBucket);
        self::assertNotNull($listedMarker);

        $this->metadata->putBucketLifecycle('bucket', []);

        $delete = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('deleteOrphanedMarker');
        self::assertFalse($delete->invoke($this->executor(), $observedBucket, $listedMarker, $rule));
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $markerId));
    }

    public function test_noncurrent_expiration_preserves_compliance_legal_hold_and_unknown_retention_modes(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $retainUntil = (new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')))->format(\DateTimeInterface::ATOM);

        foreach (['compliance', 'legal-hold', 'unknown-mode', 'unprotected'] as $key) {
            $old = $this->putVersionedObject('bucket', $key, 'old');
            $this->putVersionedObject('bucket', $key, 'current');
            $this->ageVersion('bucket', $key, $old['versionId'], 5);
            $versions[$key] = $old;
        }

        $this->metadata->putObjectRetention('bucket', 'compliance', 'COMPLIANCE', $retainUntil, $versions['compliance']['versionId']);
        $this->metadata->putObjectLegalHold('bucket', 'legal-hold', 'ON', $versions['legal-hold']['versionId']);
        // Simulate legacy or corrupted metadata: the public API validates this field.
        $this->metadata->putObjectRetention('bucket', 'unknown-mode', 'UNKNOWN', $retainUntil, $versions['unknown-mode']['versionId']);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        foreach (['compliance', 'legal-hold', 'unknown-mode'] as $key) {
            self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', $key, $versions[$key]['versionId']));
            self::assertFileExists($versions[$key]['path']);
        }
        self::assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'unprotected', $versions['unprotected']['versionId']));
        self::assertFileDoesNotExist($versions['unprotected']['path']);
    }

    public function test_abort_incomplete_multipart_upload_removes_metadata_and_part_files(): void
    {
        $this->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, 'bucket', 'large.bin', 'owner');
        $part = $this->storage->putPart('bucket', 'large.bin', $uploadId, 1, new ReadableBuffer('part-data'));
        $this->metadata->putPart($uploadId, 1, '"' . md5('part-data') . '"', $part->size, $part->path);
        $this->ageMultipartUpload($uploadId, 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'abort-old-mpu',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => 1,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getMultipartUpload($uploadId));
        $this->assertSame([], $this->metadata->getParts($uploadId));
        $this->assertFileDoesNotExist($part->path);
    }

    public function test_abort_incomplete_multipart_upload_respects_prefix_filter(): void
    {
        $this->createBucket('bucket');
        $expiredUploadId = $this->createMultipartUploadWithPart('bucket', 'logs/large.bin');
        $keptUploadId = $this->createMultipartUploadWithPart('bucket', 'data/large.bin');
        $this->ageMultipartUpload($expiredUploadId, 5);
        $this->ageMultipartUpload($keptUploadId, 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'abort-old-logs-mpu',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => ['prefix' => 'logs/'],
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => 1,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getMultipartUpload($expiredUploadId));
        $this->assertNotNull($this->metadata->getMultipartUpload($keptUploadId));
    }

    #[DataProvider('staleAbortRuleMutations')]
    public function test_lifecycle_multipart_abort_rejects_a_rule_changed_after_listing(string $mutation): void
    {
        $this->createBucket('bucket');
        $uploadId = $this->createMultipartUploadWithPart('bucket', 'retained.bin');
        $this->ageMultipartUpload($uploadId, 5);
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'abort-old-mpu',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => 1,
        ]]);
        $observedBucket = $this->metadata->getBucket('bucket');
        $observedRule = $this->metadata->getBucketLifecycle('bucket')[0];
        self::assertNotNull($observedBucket);

        $currentRule = $observedRule;
        if ($mutation === 'disabled') {
            $currentRule['status'] = 'Disabled';
        } elseif ($mutation === 'later') {
            $currentRule['abortIncompleteDays'] = 365;
        }
        $this->metadata->putBucketLifecycle('bucket', $mutation === 'removed' ? [] : [$currentRule]);

        $abort = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('abortMultipartUploadDurably');
        self::assertFalse($abort->invoke($this->executor(), 'bucket', 'retained.bin', $uploadId, $observedBucket, $observedRule));
        self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
        self::assertSame($parts, $this->metadata->getParts($uploadId));
        self::assertFileExists($parts[0]['storage_path']);
    }

    /** @return iterable<string, array{string}> */
    public static function staleAbortRuleMutations(): iterable
    {
        yield 'disabled' => ['disabled'];
        yield 'removed' => ['removed'];
        yield 'raised age' => ['later'];
    }

    public function test_global_multipart_max_age_uses_exact_seconds_without_lifecycle_rules(): void
    {
        $this->createBucket('bucket');
        $uploadId = $this->createMultipartUploadWithPart('bucket', 'stale.bin');
        $this->sql(
            'UPDATE s3_multipart_uploads SET created_at = ? WHERE upload_id = ?',
            [
                (new \DateTimeImmutable('-2 seconds', new \DateTimeZone('UTC')))->format('Y-m-d\TH:i:s\Z'),
                $uploadId,
            ],
        );

        $executor = new LifecycleExecutor(
            $this->metadata,
            $this->storage,
            multipartMaxAgeSeconds: 1,
        );
        $executor->processBucket('bucket');

        self::assertNull($this->metadata->getMultipartUpload($uploadId));
    }

    public function test_expiration_tag_filter_deletes_only_matching_objects(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'tmp/delete.txt', 'expired');
        $kept = $this->putObject('bucket', 'tmp/keep.txt', 'kept');
        $this->ageObject('bucket', 'tmp/delete.txt', 5);
        $this->ageObject('bucket', 'tmp/keep.txt', 5);
        $this->metadata->putObjectTagging('bucket', 'tmp/delete.txt', [['key' => 'class', 'value' => 'tmp']]);
        $this->metadata->putObjectTagging('bucket', 'tmp/keep.txt', [['key' => 'class', 'value' => 'keep']]);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-tmp-tag',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => ['tag' => ['key' => 'class', 'value' => 'tmp']],
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'tmp/delete.txt'));
        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'tmp/keep.txt'));
        $this->assertFileExists($kept->path);
    }

    public function test_expiration_and_filter_requires_prefix_and_all_tags(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/prod-hot.txt', 'expired');
        $keptByTag = $this->putObject('bucket', 'logs/prod-cold.txt', 'kept-tag');
        $keptByPrefix = $this->putObject('bucket', 'data/prod-hot.txt', 'kept-prefix');

        foreach (['logs/prod-hot.txt', 'logs/prod-cold.txt', 'data/prod-hot.txt'] as $key) {
            $this->ageObject('bucket', $key, 5);
        }

        $this->metadata->putObjectTagging('bucket', 'logs/prod-hot.txt', [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => 'tier', 'value' => 'hot'],
        ]);
        $this->metadata->putObjectTagging('bucket', 'logs/prod-cold.txt', [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => 'tier', 'value' => 'cold'],
        ]);
        $this->metadata->putObjectTagging('bucket', 'data/prod-hot.txt', [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => 'tier', 'value' => 'hot'],
        ]);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-prod-hot-logs',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => [
                'and' => [
                    'prefix' => 'logs/',
                    'tags' => [
                        ['key' => 'env', 'value' => 'prod'],
                        ['key' => 'tier', 'value' => 'hot'],
                    ],
                ],
            ],
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/prod-hot.txt'));
        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/prod-cold.txt'));
        $this->assertFileExists($keptByTag->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'data/prod-hot.txt'));
        $this->assertFileExists($keptByPrefix->path);
    }

    public function test_persisted_duplicate_filter_tag_keys_fail_closed(): void
    {
        $this->createBucket('bucket');
        $kept = $this->putObject('bucket', 'old.txt', 'kept');
        $this->ageObject('bucket', 'old.txt', 5);
        $this->metadata->putObjectTagging('bucket', 'old.txt', [['key' => 'env', 'value' => 'dev']]);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'invalid-legacy-filter',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => [
                'and' => [
                    'tags' => [
                        ['key' => 'env', 'value' => 'prod'],
                        ['key' => 'env', 'value' => 'dev'],
                    ],
                ],
            ],
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'old.txt'));
        self::assertFileExists($kept->path);
    }

    public function test_persisted_incomplete_tag_filter_fails_closed(): void
    {
        $this->createBucket('bucket');
        $kept = $this->putObject('bucket', 'old.txt', 'kept');
        $this->ageObject('bucket', 'old.txt', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'invalid-tag-filter',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => ['tag' => ['key' => 'env']],
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'old.txt'));
        self::assertFileExists($kept->path);
    }

    public function test_malformed_later_action_prevents_transition_job_from_being_enqueued(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'old.txt', 'kept');
        $this->ageObject('bucket', 'old.txt', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'invalid-expiration',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => [['days' => 0, 'storageClass' => 'GLACIER']],
            'expiration' => ['date' => 'not-a-lifecycle-date'],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        self::assertSame([], $this->tierTransitionJobs());
        self::assertSame('available', $this->metadata->getObjectMetadata('bucket', 'old.txt')?->transitionStatus);
    }

    public function test_lifecycle_run_stops_at_configured_action_budget(): void
    {
        $this->createBucket('bucket');

        for ($i = 1; $i <= 5; $i++) {
            $this->putObject('bucket', "logs/old-{$i}.txt", 'expired');
            $this->ageObject('bucket', "logs/old-{$i}.txt", 5);
        }

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $executor = new LifecycleExecutor($this->metadata, $this->storage, batchSize: 2, maxActionsPerRun: 3);
        $executor->processBucket('bucket');

        $remaining = 0;
        for ($i = 1; $i <= 5; $i++) {
            if ($this->metadata->getObjectMetadata('bucket', "logs/old-{$i}.txt") !== null) {
                $remaining++;
            }
        }

        $this->assertSame(2, $remaining);
    }

    public function test_execute_applies_action_budget_across_all_buckets(): void
    {
        foreach (['bucket-a', 'bucket-b'] as $bucket) {
            $this->createBucket($bucket);
            $this->putObject($bucket, 'old.txt', 'expired');
            $this->ageObject($bucket, 'old.txt', 5);
            $this->metadata->putBucketLifecycle($bucket, [[
                'id' => 'expire-all',
                'status' => 'Enabled',
                'prefix' => '',
                'filter' => null,
                'transitions' => null,
                'expiration' => ['days' => 1],
                'noncurrentTransitions' => null,
                'noncurrentExpiration' => null,
                'abortIncompleteDays' => null,
            ]]);
        }

        $executor = new LifecycleExecutor($this->metadata, $this->storage, maxActionsPerRun: 1);
        $executor->execute();

        $remaining = 0;
        foreach (['bucket-a', 'bucket-b'] as $bucket) {
            if ($this->metadata->getObjectMetadata($bucket, 'old.txt') !== null) {
                $remaining++;
            }
        }
        self::assertSame(1, $remaining);

        (new LifecycleExecutor($this->metadata, $this->storage, maxActionsPerRun: 1))->execute();
        self::assertNull($this->metadata->getObjectMetadata('bucket-a', 'old.txt'));
        self::assertNull($this->metadata->getObjectMetadata('bucket-b', 'old.txt'));
    }

    public function test_lifecycle_checkpoint_resumes_large_backlog_across_sweeps(): void
    {
        $this->createBucket('bucket');

        for ($i = 1; $i <= 5; $i++) {
            $this->putObject('bucket', "logs/old-{$i}.txt", 'expired');
            $this->ageObject('bucket', "logs/old-{$i}.txt", 5);
        }

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $executor = new LifecycleExecutor($this->metadata, $this->storage, batchSize: 2, maxActionsPerRun: 2);

        $executor->processBucket('bucket');
        $this->assertSame(
            ['cursorKey' => 'logs/old-2.txt', 'cursorVersionId' => null, 'cursorUploadId' => null],
            $this->metadata->getLifecycleCheckpoint('bucket', 'expire-logs', 'expire_current'),
        );
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-1.txt'));
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-2.txt'));
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old-3.txt'));

        $executor->processBucket('bucket');
        $this->assertSame(
            ['cursorKey' => 'logs/old-4.txt', 'cursorVersionId' => null, 'cursorUploadId' => null],
            $this->metadata->getLifecycleCheckpoint('bucket', 'expire-logs', 'expire_current'),
        );
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-3.txt'));
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-4.txt'));
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old-5.txt'));

        $executor->processBucket('bucket');
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-5.txt'));
        $this->assertNull($this->metadata->getLifecycleCheckpoint('bucket', 'expire-logs', 'expire_current'));
    }

    public function test_execute_skips_when_another_node_holds_lifecycle_lock(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->assertTrue($this->metadata->acquireLock('lifecycle:global', 'node-a', 60));
        $metrics = new MetricsCollector();

        $executor = new LifecycleExecutor(
            $this->metadata,
            $this->storage,
            lockOwnerId: 'node-b',
            metrics: $metrics,
        );
        $executor->execute();

        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        $this->assertStringContainsString('s3_server_lifecycle_sweeps_total{status="skipped_lock"} 1', $metrics->renderPrometheus());

        $this->metadata->releaseLock('lifecycle:global', 'node-a');
        $executor->execute();

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('s3_server_lifecycle_sweeps_total{status="completed"} 1', $rendered);
        $this->assertStringContainsString('s3_server_lifecycle_actions_total{action="expire_current"} 1', $rendered);
    }

    public function test_lease_renewal_aborts_after_lock_ownership_is_lost(): void
    {
        self::assertTrue($this->metadata->acquireLock('lifecycle:global', 'node-a', 1));
        $executor = new LifecycleExecutor(
            $this->metadata,
            $this->storage,
            lockTtlSeconds: 1,
            lockOwnerId: 'node-a',
        );

        $reflection = new \ReflectionClass($executor);
        $reflection->getProperty('leaseHeld')->setValue($executor, true);
        $reflection->getProperty('lastLeaseRenewal')->setValue($executor, 0.0);
        $this->sql(
            'UPDATE s3_locks SET expires_at = ? WHERE lock_name = ?',
            ['2000-01-01T00:00:00Z', 'lifecycle:global'],
        );
        self::assertTrue($this->metadata->acquireLock('lifecycle:global', 'node-b', 60));

        $this->expectException(OperationAbortedException::class);
        $reflection->getMethod('renewLeaseIfNeeded')->invoke($executor);
    }

    public function test_shutdown_request_cancels_sweep_before_acquiring_global_lease(): void
    {
        $executor = new LifecycleExecutor(
            $this->metadata,
            $this->storage,
            lockOwnerId: 'node-a',
        );

        $executor->requestStop();
        $executor->execute();

        self::assertTrue($this->metadata->acquireLock('lifecycle:global', 'node-b', 60));
    }

    public function test_lifecycle_action_logs_include_structured_context(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $logger = new LifecycleArrayLogger();
        $executor = new LifecycleExecutor($this->metadata, $this->storage, $logger);

        $executor->processBucket('bucket');

        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($logger->findContext('action_completed', [
            'component' => 'lifecycle',
            'bucket' => 'bucket',
            'rule_id' => 'expire-logs',
            'action' => 'expire_current',
            'status' => 'completed',
            'count' => 1,
            'expiration_mode' => 'days',
            'expiration_days' => 1,
        ]));
        $this->assertNotNull($logger->findContext('rule_completed', [
            'component' => 'lifecycle',
            'bucket' => 'bucket',
            'rule_id' => 'expire-logs',
            'applied_actions' => 1,
        ]));
    }

    public function test_throwing_logger_cannot_interrupt_a_sweep_or_leave_its_lock_held(): void
    {
        $logger = new class extends \Psr\Log\AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };

        (new LifecycleExecutor($this->metadata, $this->storage, $logger))->execute();

        self::assertTrue($this->metadata->acquireLock('lifecycle:global', 'node-b', 60));
    }

    public function test_lifecycle_action_dispatches_internal_event_without_webhook_enqueue(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'catch-all-webhook',
            'events' => ['s3:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);

        $notifications = new NotificationDispatcher($this->metadata);
        $received = [];
        $notifications->listen('s3:Lifecycle:*', static function (S3Event $event) use (&$received): void {
            $received[] = $event;
        });

        $executor = new LifecycleExecutor($this->metadata, $this->storage, notifications: $notifications);
        $executor->processBucket('bucket');
        $this->runEventLoopTick();

        $this->assertCount(1, $received);
        $this->assertSame('s3:Lifecycle:ActionApplied', $received[0]->name);
        $this->assertSame('bucket', $received[0]->bucket);
        $this->assertSame('', $received[0]->key);
        $this->assertSame('expire-logs', $received[0]->attributes['rule_id'] ?? null);
        $this->assertSame('expire_current', $received[0]->attributes['lifecycle_action'] ?? null);
        $this->assertSame(1, $received[0]->attributes['count'] ?? null);
        $this->assertSame([], $this->metadata->dequeueNotifications(10));
    }

    public function test_expired_object_delete_marker_removes_only_orphaned_delete_markers(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $orphanMarker = $this->metadata->deleteObjectVersioned('bucket', 'orphan.txt', 'owner');
        $keptVersion = $this->putVersionedObject('bucket', 'has-version.txt', 'v1');
        $keptMarker = $this->metadata->deleteObjectVersioned('bucket', 'has-version.txt', 'owner');

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'delete-orphan-markers',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => ['expiredObjectDeleteMarker' => true],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'orphan.txt', $orphanMarker));
        $this->assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'has-version.txt', $keptVersion['versionId']));
        $this->assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'has-version.txt', $keptMarker));
    }

    public function test_transition_rule_enqueues_durable_job_idempotently(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'logs/old.txt', 'archive me');
        $this->putObject('bucket', 'logs/new.txt', 'not yet');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => [['days' => 1, 'storageClass' => 'GLACIER']],
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');
        $this->executor()->processBucket('bucket');

        $jobs = $this->tierTransitionJobs();
        $object = $this->metadata->getObjectMetadata('bucket', 'logs/old.txt');
        $newObject = $this->metadata->getObjectMetadata('bucket', 'logs/new.txt');

        $this->assertCount(1, $jobs);
        $this->assertSame('bucket', $jobs[0]['bucket']);
        $this->assertSame('logs/old.txt', $jobs[0]['key_name']);
        $this->assertNull($jobs[0]['version_id']);
        $this->assertSame('STANDARD', $jobs[0]['source_tier']);
        $this->assertSame('GLACIER', $jobs[0]['target_tier']);
        $this->assertSame('GLACIER', $jobs[0]['target_storage_class']);
        $this->assertNotNull($object);
        $this->assertSame('pending', $object->transitionStatus);
        $this->assertSame('GLACIER', $object->transitionTargetTier);
        $this->assertNotNull($newObject);
        $this->assertSame('available', $newObject->transitionStatus);
    }

    public function test_failed_transition_is_not_requeued_for_the_same_target_but_allows_a_different_target(): void
    {
        $this->createBucket('bucket');
        $write = $this->putObject('bucket', 'archive.txt', 'archive me');
        $this->metadata->updateObjectPlacement(
            bucket: 'bucket',
            key: 'archive.txt',
            versionId: null,
            storageClass: 'STANDARD',
            storageTier: 'STANDARD',
            storagePath: $write->path,
            transitionStatus: 'failed',
            transitionTargetTier: 'GLACIER',
            transitionError: 'Tier transition processing failed.',
        );

        $bucket = $this->metadata->getBucket('bucket');
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.txt');
        self::assertNotNull($bucket);
        self::assertNotNull($object);
        $method = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('enqueueTransitionIfNeeded');

        self::assertFalse($method->invoke($this->executor(), $bucket, $object, 'GLACIER', true, 'archive'));
        self::assertSame([], $this->tierTransitionJobs());
        self::assertSame('failed', $this->metadata->getObjectMetadata('bucket', 'archive.txt')?->transitionStatus);

        self::assertTrue($method->invoke($this->executor(), $bucket, $object, 'DEEP_ARCHIVE', true, 'archive'));
        $jobs = $this->tierTransitionJobs();
        self::assertCount(1, $jobs);
        self::assertSame('DEEP_ARCHIVE', $jobs[0]['target_tier']);
        self::assertSame('pending', $this->metadata->getObjectMetadata('bucket', 'archive.txt')?->transitionStatus);
    }

    public function test_noncurrent_transition_rule_enqueues_versioned_job(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $oldVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v1');
        $latestVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v2');
        $this->ageVersion('bucket', 'versioned.txt', $oldVersion['versionId'], 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => [['noncurrentDays' => 1, 'storageClass' => 'GLACIER']],
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $jobs = $this->tierTransitionJobs();
        $old = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $oldVersion['versionId']);
        $latest = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $latestVersion['versionId']);

        $this->assertCount(1, $jobs);
        $this->assertSame($oldVersion['versionId'], $jobs[0]['version_id']);
        $this->assertSame('GLACIER', $jobs[0]['target_storage_class']);
        $this->assertNotNull($old);
        $this->assertSame('pending', $old->transitionStatus);
        $this->assertSame('GLACIER', $old->transitionTargetTier);
        $this->assertNotNull($latest);
        $this->assertSame('available', $latest->transitionStatus);
    }

    public function test_noncurrent_transition_targets_null_version_under_suspended_versioning(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $old = $this->putObject('bucket', 'versioned.txt', 'null version');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $latest = $this->putVersionedObject('bucket', 'versioned.txt', 'numbered version');
        $this->ageVersion('bucket', 'versioned.txt', 'null', 5);
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition-null',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => [['noncurrentDays' => 1, 'storageClass' => 'GLACIER']],
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $jobs = $this->tierTransitionJobs();
        self::assertCount(1, $jobs);
        self::assertSame('null', $jobs[0]['version_id']);
        self::assertSame('pending', $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', 'null')?->transitionStatus);
        self::assertSame('available', $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $latest['versionId'])?->transitionStatus);
        self::assertFileExists($old->path);
        self::assertFileExists($latest['path']);
    }

    public function test_noncurrent_transition_skips_version_promoted_after_listing(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $version = $this->putVersionedObject('bucket', 'retained.txt', 'payload');
        $markerId = $this->metadata->deleteObjectVersioned('bucket', 'retained.txt', 'owner');
        $listedAsNoncurrent = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']);
        self::assertNotNull($listedAsNoncurrent);
        self::assertSame('0', $listedAsNoncurrent->systemMetadata['isLatest']);

        $this->metadata->deleteObjectVersion('bucket', 'retained.txt', $markerId);
        $bucketInfo = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $method = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('enqueueTransitionIfNeeded');
        $queued = $method->invoke($this->executor(), $bucketInfo, $listedAsNoncurrent, 'GLACIER', false, 'transition-noncurrent');

        self::assertFalse($queued);
        self::assertSame([], $this->tierTransitionJobs());
        self::assertSame('available', $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId'])?->transitionStatus);
        self::assertFileExists($version['path']);
    }

    public function test_current_transition_skips_version_demoted_after_listing(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $version = $this->putVersionedObject('bucket', 'retained.txt', 'original');
        $listedAsCurrent = $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId']);
        self::assertNotNull($listedAsCurrent);
        self::assertSame('1', $listedAsCurrent->systemMetadata['isLatest']);

        $this->putVersionedObject('bucket', 'retained.txt', 'replacement');
        $bucketInfo = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $method = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('enqueueTransitionIfNeeded');
        $queued = $method->invoke($this->executor(), $bucketInfo, $listedAsCurrent, 'GLACIER', true, 'transition-current');

        self::assertFalse($queued);
        self::assertSame([], $this->tierTransitionJobs());
        self::assertSame('available', $this->metadata->getObjectMetadataByVersion('bucket', 'retained.txt', $version['versionId'])?->transitionStatus);
        self::assertFileExists($version['path']);
    }

    private function executor(): LifecycleExecutor
    {
        return new LifecycleExecutor($this->metadata, $this->storage);
    }

    private function configureOneDayExpiration(): void
    {
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-current',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
    }

    private function createBucket(string $bucket): void
    {
        $this->metadata->createBucket('owner', $bucket, 'us-east-1');
        $this->storage->createBucket($bucket);
    }

    private function putObject(string $bucket, string $key, string $body): \OpsFour\S3Server\Storage\StorageWriteResult
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($body));
        $this->metadata->putObjectMetadata(
            bucket: $bucket,
            key: $key,
            ownerId: 'owner',
            size: $write->size,
            etag: '"' . $write->md5Hex . '"',
            contentType: 'text/plain',
            storagePath: $write->path,
        );

        return $write;
    }

    /**
     * @return array{versionId: string, path: string}
     */
    private function putVersionedObject(string $bucket, string $key, string $body): array
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($body));
        $versionId = $this->metadata->putObjectVersioned(
            bucket: $bucket,
            key: $key,
            ownerId: 'owner',
            size: $write->size,
            etag: '"' . $write->md5Hex . '"',
            contentType: 'text/plain',
            storagePath: $write->path,
        );

        return ['versionId' => $versionId, 'path' => $write->path];
    }

    private function createMultipartUploadWithPart(string $bucket, string $key): string
    {
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, $bucket, $key, 'owner');
        $part = $this->storage->putPart($bucket, $key, $uploadId, 1, new ReadableBuffer('part-data'));
        $this->metadata->putPart($uploadId, 1, '"' . md5('part-data') . '"', $part->size, $part->path);

        return $uploadId;
    }

    private function ageObject(string $bucket, string $key, int $days): void
    {
        $this->sql(
            'UPDATE s3_objects SET created_at = ?, updated_at = ? WHERE bucket = ? AND key_name = ?',
            [$this->past($days), $this->past($days), $bucket, $key],
        );
    }

    private function ageVersion(string $bucket, string $key, string $versionId, int $days): void
    {
        $this->sql(
            'UPDATE s3_objects SET created_at = ?, noncurrent_at = CASE WHEN is_latest = 0 THEN ? ELSE noncurrent_at END, updated_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?',
            [$this->past($days), $this->past($days), $this->past($days), $bucket, $key, $versionId],
        );
    }

    private function ageMultipartUpload(string $uploadId, int $days): void
    {
        $this->sql(
            'UPDATE s3_multipart_uploads SET created_at = ? WHERE upload_id = ?',
            [$this->past($days), $uploadId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tierTransitionJobs(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM s3_tier_transition_jobs ORDER BY id ASC');
        if ($stmt === false) {
            return [];
        }

        $result = [];
        foreach ($stmt->fetchAll(\PDO::FETCH_ASSOC) as $row) {
            if (is_array($row)) {
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * @param  list<mixed>  $params
     */
    private function sql(string $sql, array $params): void
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function pdo(): \PDO
    {
        $reflection = new \ReflectionClass($this->metadata);
        $method = $reflection->getMethod('connection');
        $connection = $method->invoke($this->metadata);
        if (! $connection instanceof \PDO) {
            throw new \RuntimeException('SQLite metadata test connection is unavailable.');
        }

        return $connection;
    }

    private function past(int $days): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function runEventLoopTick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }

    private function recursiveDelete(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}

final class LifecycleArrayLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param  array<string, mixed>  $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param  array<string, mixed>  $expected
     * @return array<string, mixed>|null
     */
    public function findContext(string $event, array $expected): ?array
    {
        foreach ($this->records as $record) {
            $context = $record['context'];
            if (($context['event'] ?? null) !== $event) {
                continue;
            }

            foreach ($expected as $key => $value) {
                if (($context[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            return $context;
        }

        return null;
    }
}
