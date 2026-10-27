<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class RestoreExecutorTest extends TestCase
{
    #[DataProvider('invalidLeaseRenewalIntervals')]
    public function test_invalid_lease_renewal_intervals_are_rejected(float $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new RestoreExecutor(
            $this->metadata,
            StorageTierRegistry::single(new InMemoryBackend()),
            leaseRenewalIntervalSeconds: $interval,
        );
    }

    /** @return iterable<string, array{float}> */
    public static function invalidLeaseRenewalIntervals(): iterable
    {
        yield 'zero' => [0.0];
        yield 'negative' => [-1.0];
        yield 'positive infinity' => [INF];
        yield 'negative infinity' => [-INF];
        yield 'not a number' => [NAN];
        yield 'half lease' => [150.0];
        yield 'full lease' => [300.0];
    }

    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-restore-executor-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_same_object_snapshot_includes_part_layout_digest(): void
    {
        $layout = ObjectPartLayout::knownSingle(4);
        $observed = new ObjectInfo('bucket', 'key', 4, '"etag"', ownerId: 'owner', systemMetadata: ['storagePath' => 'objects/key'], partLayout: $layout->summary(1));
        $replacement = new ObjectInfo('bucket', 'key', 4, '"etag"', ownerId: 'owner', systemMetadata: ['storagePath' => 'objects/key'], partLayout: $layout->summary(2));
        $executor = new RestoreExecutor($this->metadata, StorageTierRegistry::single(new InMemoryBackend()));
        $method = new \ReflectionMethod(RestoreExecutor::class, 'sameObject');

        self::assertTrue($method->invoke($executor, $observed, $observed));
        self::assertFalse($method->invoke($executor, $observed, $replacement));
    }

    public function test_backend_error_is_redacted_from_restore_job_and_logs(): void
    {
        $secret = 'backend-password-private-test-token';
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $failingCold = $this->createMock(StorageBackend::class);
        $failingCold->method('getObjectByPath')->willThrowException(new \RuntimeException($secret));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 4, '"test"', 'application/octet-stream', 'secret-source-path');
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', 'secret-source-path');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', 'secret-source-path', 1, maxAttempts: 1);
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $failingCold, restoreRequired: true),
        ]), $logger);

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertSame('Restore processing failed.', $this->metadata->getRestoreJob($jobId)['lastError'] ?? null);
        self::assertStringNotContainsString($secret, json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    public function test_legacy_oversized_restore_days_fail_before_copy(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'archive.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob(
            'bucket',
            'archive.bin',
            null,
            'GLACIER',
            $source->path,
            PHP_INT_MAX,
            maxAttempts: 1,
        );

        $stats = (new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ])))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $stats);
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoredStoragePath);
    }

    public function test_failed_target_copy_closes_retained_source_stream_before_retry(): void
    {
        $hot = $this->createMock(StorageBackend::class);
        $retainedStream = null;
        $hot->method('putObject')->willReturnCallback(static function (string $bucket, string $key, ReadableStream $body) use (&$retainedStream): never {
            $retainedStream = $body;
            throw new \RuntimeException('Target rejected the copy.');
        });
        $cold = new InMemoryBackend();
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $source->path, 1, maxAttempts: 1);

        $executor = new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $cold, restoreRequired: true),
            ]),
            new class extends AbstractLogger {
                /** @param array<string, mixed> $context */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    throw new \RuntimeException('logger unavailable');
                }
            },
        );

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertNotNull($retainedStream);
        self::assertTrue($retainedStream->isClosed());
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
    }

    public function test_encrypted_source_size_mismatch_does_not_publish_hot_copy(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'encrypted.bin', new ReadableBuffer('abc'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'encrypted.bin',
            'owner',
            4,
            '"plaintext-etag"',
            'application/octet-stream',
            $source->path,
            userMetadata: ['__sse-algorithm' => 'AES256'],
        );
        $this->metadata->updateObjectPlacement('bucket', 'encrypted.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'encrypted.bin', 'null', 'GLACIER', $source->path, 1, maxAttempts: 1);
        $this->metadata->updateObjectRestoreState('bucket', 'encrypted.bin', 'null', 'pending');
        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'encrypted.bin')?->restoredStoragePath);
    }

    public function test_backend_cancellation_does_not_consume_restore_retry(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold = $this->createMock(StorageBackend::class);
        $cold->method('getObjectByPath')->willThrowException(new \Amp\CancelledException());
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 4, '"test"', 'application/octet-stream', 'cold-path');
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', 'cold-path');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', 'cold-path', 1, maxAttempts: 1);
        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        try {
            $executor->processNext(1);
            self::fail('Expected backend cancellation to propagate.');
        } catch (\Amp\CancelledException) {
            $job = $this->metadata->getRestoreJob($jobId);
            self::assertSame('processing', $job['status'] ?? null);
            self::assertSame(0, $job['attempts'] ?? null);
            self::assertNull($job['lastError'] ?? null);
        }
    }

    public function test_processes_restore_job_into_hot_copy_and_restore_metadata(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $coldWrite = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $coldWrite->size, '"' . $coldWrite->md5Hex . '"', 'application/octet-stream', $coldWrite->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $coldWrite->path, 2);
        $event = null;
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'restore-events',
            'events' => ['s3:ObjectRestore:Completed'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://example.test/restore-hook',
            'filterRules' => null,
        ]]);
        $notifications = new NotificationDispatcher($this->metadata);
        $notifications->listen('s3:ObjectRestore:Completed', static function (S3Event $received) use (&$event): void {
            $event = $received;
        });

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]), notifications: $notifications);

        $stats = $executor->processNext(10);
        \Amp\delay(0.01);

        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['attempts']);
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertNotNull($object->restoredStoragePath);
        self::assertNotNull($object->restoreExpiresAt);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
        self::assertSame([
            'objectCount' => 1,
            'bytesUsed' => $coldWrite->size,
        ], $this->metadata->getBucketStorageStats('bucket'));
        self::assertInstanceOf(S3Event::class, $event);
        self::assertSame('s3:ObjectRestore:Completed', $event->name);
        self::assertSame('archive.bin', $event->key);
        self::assertSame($this->metadata->getBucket('bucket')?->generation, $event->bucketGeneration);
        self::assertSame('GLACIER', $event->attributes['sourceTier']);
        self::assertSame(2, $event->attributes['restoreDays']);
        $queued = $this->metadata->dequeueNotifications(1);
        self::assertCount(1, $queued);
        self::assertSame('https://example.test/restore-hook', $queued[0]['destination_url']);
        self::assertSame('archive.bin', $queued[0]['key_name']);
    }

    public function test_committed_versioned_restore_survives_lost_commit_acknowledgement(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $versionId = $this->metadata->putObjectVersioned(
            'bucket',
            'archive.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', $versionId, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', $versionId, 'GLACIER', $source->path, 1);
        $faulting = $this->commitAcknowledgementFaultStore(afterCommit: true);
        $executor = new RestoreExecutor($faulting, new StorageTierRegistry([
            new StorageTier('HOT', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        $executor->processNext(1);

        $restored = $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', $versionId);
        self::assertNotNull($restored);
        self::assertSame('restored', $restored->restoreStatus);
        self::assertSame('HOT', $restored->restoredStorageTier);
        self::assertNotNull($restored->restoredStoragePath);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->restoredStoragePath)));
        self::assertSame('completed', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
    }

    public function test_failed_restore_transaction_deletes_unpublished_target(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'archive.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $source->path, 1);
        $faulting = $this->commitAcknowledgementFaultStore(afterCommit: false);
        $executor = new RestoreExecutor($faulting, new StorageTierRegistry([
            new StorageTier('HOT', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        $executor->processNext(1);

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($object);
        self::assertNull($object->restoredStoragePath);
        self::assertSame('pending', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $hot->getObjectByPath('memory://bucket/archive.bin/1')->read();
    }

    public function test_unavailable_reference_probe_retains_target_after_lost_commit_acknowledgement(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'archive.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $source->path, 1);
        $executor = new RestoreExecutor($this->commitAcknowledgementFaultStore(afterCommit: true, probeFails: true), new StorageTierRegistry([
            new StorageTier('HOT', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        $executor->processNext(1);

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('HOT', $object->restoredStorageTier);
        self::assertNotNull($object->restoredStoragePath);
        self::assertSame('cold-data', \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
        self::assertSame('completed', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
    }

    private function commitAcknowledgementFaultStore(bool $afterCommit, bool $probeFails = false): MetadataStore
    {
        $proxy = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            RestoredCopyTierStore::class,
        ]);
        if (! $proxy instanceof MetadataStore) {
            throw new \LogicException('Restore fault proxy must implement MetadataStore.');
        }
        foreach ([
            'dequeueRestoreJobs', 'getBucketOwner', 'getBucket', 'lockOwnerForUpdate',
            'getObjectMetadata', 'getObjectMetadataByVersion', 'getRestoreJob',
            'renewRestoreJobLease', 'updateRestoreJobStatus',
            'updateObjectRestoreStateWithTier',
        ] as $method) {
            $proxy->method($method)->willReturnCallback(\Closure::fromCallable([$this->metadata, $method]));
        }
        $transactions = 0;
        $proxy->method('transaction')->willReturnCallback(function (callable $callback) use (&$transactions, $afterCommit, $probeFails): mixed {
            $transactions++;
            if ($transactions === 2 && ! $afterCommit) {
                throw new \RuntimeException('Injected failure before restore publication commit.');
            }
            if ($transactions === 3 && $probeFails) {
                throw new \RuntimeException('Injected reference probe failure.');
            }
            $result = $this->metadata->transaction($callback);
            if ($transactions === 2 && $afterCommit) {
                throw new \RuntimeException('Injected loss of restore publication commit acknowledgement.');
            }

            return $result;
        });

        return $proxy;
    }

    public function test_reclaimed_restore_job_ignores_old_worker_before_copy(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'reclaimed.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'reclaimed.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'reclaimed.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $id = $this->metadata->enqueueRestoreJob('bucket', 'reclaimed.bin', null, 'GLACIER', $source->path, 1);
        $first = $this->metadata->dequeueRestoreJobs(1)[0];
        $this->metadata->updateRestoreJobStatus($id, 'pending', nextAttemptAt: 0, incrementAttempts: false);
        $second = $this->metadata->dequeueRestoreJobs(1)[0];
        self::assertSame($first['leaseGeneration'] + 1, $second['leaseGeneration']);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));
        self::assertSame('completed', $executor->processDequeuedJob($first));
        self::assertSame('processing', $this->metadata->getRestoreJob($id)['status'] ?? null);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'reclaimed.bin')?->restoredStoragePath);

        self::assertSame('completed', $executor->processDequeuedJob($second));
        self::assertSame('completed', $this->metadata->getRestoreJob($id)['status'] ?? null);
        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'reclaimed.bin')?->restoredStoragePath);
    }

    public function test_failed_noncurrent_null_restore_does_not_mark_current_version_failed(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 4, '"old"', 'application/octet-stream', 'missing-cold-path');
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', 'null', 'GLACIER', 'GLACIER', 'missing-cold-path');
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', 'null', 'pending');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $current = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('current'));
        $currentVersion = $this->metadata->putObjectVersioned('bucket', 'archive.bin', 'owner', $current->size, '"' . $current->md5Hex . '"', 'application/octet-stream', $current->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', 'null', 'GLACIER', 'missing-cold-path', 1, maxAttempts: 1);

        $worker = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));
        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $worker->processNext(1));
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        self::assertSame('failed', $this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', 'null')?->restoreStatus);
        self::assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'archive.bin', $currentVersion)?->restoreStatus);
    }

    public function test_reclaimed_restore_job_cannot_publish_an_old_physical_copy(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'late.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'late.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'late.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $id = $this->metadata->enqueueRestoreJob('bucket', 'late.bin', null, 'GLACIER', $source->path, 1);
        $reclaimed = null;
        $racingHot = new CallbackStorageBackend($hot, afterPut: function () use ($id, &$reclaimed): void {
            $this->metadata->updateRestoreJobStatus($id, 'pending', nextAttemptAt: 0, incrementAttempts: false);
            $reclaimed = $this->metadata->dequeueRestoreJobs(1)[0];
        });
        $firstWorker = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $racingHot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));
        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $firstWorker->processNext(1));
        self::assertIsArray($reclaimed);
        self::assertSame('processing', $this->metadata->getRestoreJob($id)['status'] ?? null);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'late.bin')?->restoredStoragePath);

        $secondWorker = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));
        self::assertSame('completed', $secondWorker->processDequeuedJob($reclaimed));
        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'late.bin')?->restoredStoragePath);
    }

    public function test_worker_missing_source_tier_defers_without_exhausting_attempts_for_another_worker(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $source->path, 2, maxAttempts: 1);

        $limitedWorker = new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([new StorageTier('STANDARD', $hot, defaultWriteTier: true)]),
        );
        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
            $limitedWorker->processNext(1),
        );
        $deferred = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($deferred);
        self::assertNotNull($object);
        self::assertSame('pending', $deferred['status']);
        self::assertSame(0, $deferred['attempts']);
        self::assertGreaterThan(microtime(true), $deferred['nextAttemptAt']);
        self::assertSame('pending', $object->restoreStatus);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame($source->path, $object->systemMetadata['storagePath']);

        $this->metadata->updateRestoreJobStatus(
            $jobId,
            'pending',
            nextAttemptAt: microtime(true) - 1,
            incrementAttempts: false,
        );
        $configuredWorker = new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $cold, restoreRequired: true),
            ]),
        );
        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $configuredWorker->processNext(1),
        );
        $completed = $this->metadata->getRestoreJob($jobId);
        $restored = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($completed);
        self::assertNotNull($restored);
        self::assertSame('completed', $completed['status']);
        self::assertSame(0, $completed['attempts']);
        self::assertSame('restored', $restored->restoreStatus);
        self::assertNotNull($restored->restoredStoragePath);
        self::assertSame('cold-data', $hot->getObjectByPath($restored->restoredStoragePath)->read());
    }

    public function test_restores_encrypted_ciphertext_without_comparing_plaintext_etag(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $ciphertext = 'crypt';
        $coldWrite = $cold->putObject('bucket', 'encrypted.bin', new ReadableBuffer($ciphertext));
        $this->metadata->putObjectMetadata(
            'bucket',
            'encrypted.bin',
            'owner',
            5,
            '"' . md5('plain') . '"',
            'application/octet-stream',
            $coldWrite->path,
            userMetadata: ['__sse-algorithm' => 'AES256'],
            checksumSha256: base64_encode(hash('sha256', 'plain', true)),
        );
        $this->metadata->updateObjectPlacement('bucket', 'encrypted.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->enqueueRestoreJob('bucket', 'encrypted.bin', null, 'GLACIER', $coldWrite->path, 2);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );
        $object = $this->metadata->getObjectMetadata('bucket', 'encrypted.bin');
        self::assertNotNull($object);
        self::assertNotNull($object->restoredStoragePath);
        self::assertSame($ciphertext, \Amp\ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
        self::assertSame(5, $object->size);
    }

    public function test_rejects_corrupt_cold_copy_with_same_size_before_publishing_restore(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $corrupt = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('bad-data!'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'archive.bin',
            'owner',
            $corrupt->size,
            '"' . md5('good-data') . '"',
            'application/octet-stream',
            $corrupt->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $corrupt->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $corrupt->path, 2, maxAttempts: 1);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1],
            $executor->processNext(1),
        );
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('failed', $object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertSame('bad-data!', \Amp\ByteStream\buffer($cold->getObjectByPath($corrupt->path)));
    }

    public function test_rejects_corrupt_multipart_source_using_persisted_checksum(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $corrupt = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('bad-data!'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'archive.bin',
            ownerId: 'owner',
            size: $corrupt->size,
            etag: '"0123456789abcdef0123456789abcdef-2"',
            contentType: 'application/octet-stream',
            storagePath: $corrupt->path,
            checksumSha256: base64_encode(hash('sha256', 'good-data', true)),
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $corrupt->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $corrupt->path, 2, maxAttempts: 1);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1],
            $executor->processNext(1),
        );
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('failed', $object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
    }

    public function test_rejects_truncated_cold_copy_before_publishing_restore(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $truncated = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('short'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'archive.bin',
            'owner',
            9,
            '"' . md5('good-data') . '"',
            'application/octet-stream',
            $truncated->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $truncated->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $truncated->path, 2, maxAttempts: 1);

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1],
            $executor->processNext(1),
        );
        self::assertSame('dead_letter', $this->metadata->getRestoreJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('failed', $object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
    }

    public function test_stale_restore_job_after_overwrite_is_completed_without_restoring_old_data(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $coldWrite = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('old-cold'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $coldWrite->size, '"' . $coldWrite->md5Hex . '"', 'application/octet-stream', $coldWrite->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', null, 'GLACIER', $coldWrite->path, 2);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $hotWrite = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('new-hot'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $hotWrite->size, '"' . $hotWrite->md5Hex . '"', 'application/octet-stream', $hotWrite->path);

        $executor = new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $cold, restoreRequired: true),
            ]),
            new class extends AbstractLogger {
                /** @param array<string, mixed> $context */
                public function log($level, string|\Stringable $message, array $context = []): void
                {
                    throw new \RuntimeException('logger unavailable');
                }
            },
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['attempts']);
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertNull($object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertSame($hotWrite->path, $object->systemMetadata['storagePath']);
        self::assertSame('new-hot', \Amp\ByteStream\buffer($hot->getObjectByPath($object->systemMetadata['storagePath'])));
    }

    public function test_overwrite_during_physical_restore_does_not_attach_old_restore_copy(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $old = $cold->putObject('bucket', 'racing.bin', new ReadableBuffer('old-cold'));
        $this->metadata->putObjectMetadata('bucket', 'racing.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'racing.bin', null, 'GLACIER', 'GLACIER', $old->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'racing.bin', null, 'GLACIER', $old->path, 2);
        $this->metadata->updateObjectRestoreState('bucket', 'racing.bin', null, 'pending');

        $target = new CallbackStorageBackend($hot, afterPut: function () use ($hot): void {
            $new = $hot->putObject('bucket', 'racing.bin', new ReadableBuffer('new-hot'));
            $this->metadata->putObjectMetadata('bucket', 'racing.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        });
        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $target, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );

        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'racing.bin');
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertNull($object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertSame('new-hot', \Amp\ByteStream\buffer($hot->getObjectByPath($object->systemMetadata['storagePath'])));
        self::assertNotNull($target->lastWrite);

        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $hot->getObjectByPath($target->lastWrite->path)->read();
    }

    public function test_deleted_object_restore_job_is_completed_as_stale_noop(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $coldWrite = $cold->putObject('bucket', 'deleted.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'deleted.bin', 'owner', $coldWrite->size, '"' . $coldWrite->md5Hex . '"', 'application/octet-stream', $coldWrite->path);
        $this->metadata->updateObjectPlacement('bucket', 'deleted.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'deleted.bin', null, 'GLACIER', $coldWrite->path, 2);
        $this->metadata->deleteObjectMetadata('bucket', 'deleted.bin');

        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        $stats = $executor->processNext(10);
        $job = $this->metadata->getRestoreJob($jobId);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'deleted.bin'));
    }

    public function test_lost_processing_lease_cannot_attach_or_reopen_completed_restore_job(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');

        $source = $cold->putObject('bucket', 'lease.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'lease.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'lease.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $jobId = $this->metadata->enqueueRestoreJob('bucket', 'lease.bin', null, 'GLACIER', $source->path, 1);
        $target = new CallbackStorageBackend(
            $hot,
            afterPut: fn() => $this->metadata->updateRestoreJobStatus(
                $jobId,
                'completed',
                incrementAttempts: false,
            ),
        );
        $executor = new RestoreExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $target, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );

        $job = $this->metadata->getRestoreJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'lease.bin');
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['attempts']);
        self::assertNotNull($object);
        self::assertNull($object->restoredStoragePath);
    }

    public function test_cancelled_batch_does_not_claim_another_restore_job(): void
    {
        $storage = new InMemoryBackend();
        $cancellation = new \Amp\DeferredCancellation();
        $cancellation->cancel();
        $executor = new RestoreExecutor(
            $this->metadata,
            StorageTierRegistry::single($storage),
        );

        $this->expectException(\Amp\CancelledException::class);
        $executor->processNext(10, $cancellation->getCancellation());
    }
}
