<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Log\AbstractLogger;

final class TierTransitionExecutorTest extends TestCase
{
    #[DataProvider('invalidLeaseRenewalIntervals')]
    public function test_invalid_lease_renewal_intervals_are_rejected(float $interval): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new TierTransitionExecutor(
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
        $this->path = sys_get_temp_dir() . '/s3-tier-transition-executor-' . bin2hex(random_bytes(4)) . '.sqlite';
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
        $executor = new TierTransitionExecutor($this->metadata, StorageTierRegistry::single(new InMemoryBackend()));
        $method = new \ReflectionMethod(TierTransitionExecutor::class, 'sameObject');

        self::assertTrue($method->invoke($executor, $observed, $observed));
        self::assertFalse($method->invoke($executor, $observed, $replacement));
    }

    public function test_transition_cancels_pending_restore_for_the_old_source_placement(): void
    {
        $cold = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot = new InMemoryBackend();
        foreach ([$cold, $archive, $hot] as $backend) {
            $backend->createBucket('bucket');
        }
        $source = $cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $source->path);
        $restoreJobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', 'null', 'GLACIER', $source->path, 1);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', 'null', 'pending');
        $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', 'null', 'GLACIER', 'DEEP_ARCHIVE', 'DEEP_ARCHIVE', $source->path);

        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
            new StorageTier('DEEP_ARCHIVE', $archive, restoreRequired: true),
        ]);
        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], (new TierTransitionExecutor($this->metadata, $tiers))->processNext(1));
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertNotNull($object);
        self::assertSame('DEEP_ARCHIVE', $object->storageTier);
        self::assertNull($object->restoreStatus);
        $newSourcePath = $object->systemMetadata['storagePath'] ?? null;
        self::assertNotNull($newSourcePath);
        $newRestoreJobId = $this->metadata->enqueueRestoreJob('bucket', 'archive.bin', 'null', 'DEEP_ARCHIVE', $newSourcePath, 1);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', 'null', 'pending');
        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], (new RestoreExecutor($this->metadata, $tiers))->processNext(1));
        self::assertSame('completed', $this->metadata->getRestoreJob($restoreJobId)['status'] ?? null);
        self::assertSame('pending', $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoreStatus);
        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], (new RestoreExecutor($this->metadata, $tiers))->processNext(1));
        self::assertSame('completed', $this->metadata->getRestoreJob($newRestoreJobId)['status'] ?? null);
        self::assertSame('restored', $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->restoreStatus);
    }

    public function test_backend_failure_is_redacted_from_transition_job_object_and_logs(): void
    {
        $secret = 'backend-password-private-test-token';
        $sourcePath = 'storage-path-private-test-token';
        $hot = $this->createMock(StorageBackend::class);
        $hot->method('getObjectByPath')->willThrowException(new \RuntimeException($secret));
        $archive = new InMemoryBackend();
        $archive->createBucket('bucket');
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 4, '"test"', 'application/octet-stream', $sourcePath);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'STANDARD', 'STANDARD', $sourcePath, transitionStatus: 'pending', transitionTargetTier: 'GLACIER');
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $sourcePath, maxAttempts: 1);
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]), $logger);

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertSame('Tier transition processing failed.', $this->metadata->getTierTransitionJob($jobId)['lastError'] ?? null);
        self::assertSame('Tier transition processing failed.', $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->transitionError);
        $rendered = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $rendered);
        self::assertStringNotContainsString($sourcePath, $rendered);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
    }

    public function test_early_target_write_failure_closes_retained_source_stream(): void
    {
        $source = new ReadableBuffer('abc');
        $retainedStream = null;
        $hot = $this->createMock(StorageBackend::class);
        $hot->expects(self::once())->method('getObjectByPath')->willReturn($source);
        $archive = $this->createMock(StorageBackend::class);
        $archive->expects(self::once())->method('putObject')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body) use (&$retainedStream): never {
                $retainedStream = $body;
                throw new \RuntimeException('Injected early write failure.');
            },
        );
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 3, '"' . md5('abc') . '"', 'application/octet-stream', 'source-path');
        $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', 'source-path', maxAttempts: 1);

        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));
        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertInstanceOf(ReadableStream::class, $retainedStream);
        self::assertTrue($retainedStream->isClosed());
        self::assertTrue($source->isClosed());
    }

    public function test_encrypted_source_size_mismatch_does_not_publish_tier_copy(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'encrypted.bin', new ReadableBuffer('abc'));
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
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'encrypted.bin', 'null', 'STANDARD', 'GLACIER', 'GLACIER', $source->path, maxAttempts: 1);
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertSame('dead_letter', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        self::assertSame('STANDARD', $this->metadata->getObjectMetadata('bucket', 'encrypted.bin')?->storageTier);
    }

    public function test_early_write_error_keeps_its_exception_when_source_close_also_fails(): void
    {
        $source = $this->createMock(ReadableStream::class);
        $source->expects(self::once())->method('close')->willThrowException(new \LogicException('close failed'));
        $hot = $this->createMock(StorageBackend::class);
        $hot->expects(self::once())->method('getObjectByPath')->willReturn($source);
        $archive = $this->createMock(StorageBackend::class);
        $archive->expects(self::once())->method('putObject')
            ->willThrowException(new \UnexpectedValueException('target write failed'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 3, '"' . md5('abc') . '"', 'application/octet-stream', 'source-path');
        $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', 'source-path', maxAttempts: 1);
        $logger = new class extends AbstractLogger {
            /** @var list<array<string, mixed>> */
            public array $contexts = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->contexts[] = $context;
            }
        };

        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]), $logger);
        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1], $executor->processNext(1));
        self::assertContains(\UnexpectedValueException::class, array_column($logger->contexts, 'exception'));
    }

    public function test_backend_cancellation_does_not_consume_transition_retry(): void
    {
        $sourcePath = 'cold-path';
        $hot = $this->createMock(StorageBackend::class);
        $hot->method('getObjectByPath')->willThrowException(new \Amp\CancelledException());
        $archive = new InMemoryBackend();
        $archive->createBucket('bucket');
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', 4, '"test"', 'application/octet-stream', $sourcePath);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'STANDARD', 'STANDARD', $sourcePath, transitionStatus: 'pending', transitionTargetTier: 'GLACIER');
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $sourcePath, maxAttempts: 1);
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));

        try {
            $executor->processNext(1);
            self::fail('Expected backend cancellation to propagate.');
        } catch (\Amp\CancelledException) {
            $job = $this->metadata->getTierTransitionJob($jobId);
            self::assertSame('processing', $job['status'] ?? null);
            self::assertSame(0, $job['attempts'] ?? null);
            self::assertNull($job['lastError'] ?? null);
        }
    }

    public function test_failed_source_delete_is_queued_without_logging_backend_secret(): void
    {
        $secret = 'backend-password-private-test-token';
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
        $failingHot = new CallbackStorageBackend(
            $hot,
            beforeDelete: static function () use ($secret): never {
                throw new \RuntimeException($secret);
            },
        );
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $failingHot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]), $logger);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $executor->processNext(1));
        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $rendered = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $rendered);
        self::assertStringNotContainsString($source->path, $rendered);
        self::assertSame(hash('sha256', $source->path), $logger->records[0]['context']['source_storage_path_hash'] ?? null);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception'] ?? null);
        self::assertSame(1, (new StorageGarbageCollector($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ])))->collect(1));
    }

    public function test_unqueueable_source_cleanup_rolls_back_placement_before_losing_source_reference(): void
    {
        $secret = 'backend-password-private-test-token';
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
        $connection = (new \ReflectionClass($this->metadata))->getMethod('connection')->invoke($this->metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("CREATE TRIGGER reject_tier_garbage BEFORE INSERT ON s3_storage_garbage BEGIN SELECT RAISE(ABORT, 'queue-private-test-token'); END");
        $failingHot = new CallbackStorageBackend(
            $hot,
            beforeDelete: static function () use ($secret): never {
                throw new \RuntimeException($secret);
            },
        );
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $failingHot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]), $logger);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $executor->processNext(1));
        $rendered = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($secret, $rendered);
        self::assertStringNotContainsString('queue-private-test-token', $rendered);
        self::assertStringNotContainsString($source->path, $rendered);
        self::assertSame('STANDARD', $this->metadata->getObjectMetadata('bucket', 'archive.bin')?->storageTier);
        self::assertSame('archive-data', \Amp\ByteStream\buffer($hot->getObjectByPath($source->path)));
        self::assertNotSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
    }

    #[DataProvider('commitProbeOutcomes')]
    public function test_lost_commit_ack_keeps_the_published_target_and_durable_source_cleanup(bool $probeFails, bool $nullVersionBecomesNoncurrent): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('archive-data'));
        $latestObject = new ObjectInfo(
            'bucket',
            'archive.bin',
            $source->size,
            '"' . $source->md5Hex . '"',
            ownerId: 'owner',
            systemMetadata: ['storagePath' => $source->path],
        );
        $nullObject = null;
        $job = [
            'id' => 1,
            'bucket' => 'bucket',
            'key' => 'archive.bin',
            'versionId' => null,
            'sourceTier' => 'STANDARD',
            'targetTier' => 'GLACIER',
            'targetStorageClass' => 'GLACIER',
            'sourceStoragePath' => $source->path,
            'origin' => 'direct',
            'status' => 'processing',
            'nextAttemptAt' => microtime(true) + 600,
        ];
        $status = 'processing';
        $transactions = 0;
        $garbage = [];
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('dequeueTierTransitionJobs')->willReturn([$job]);
        $metadata->method('getBucketOwner')->willReturn('owner');
        $metadata->method('getObjectMetadata')->willReturnCallback(static function () use (&$latestObject): ObjectInfo {
            return $latestObject;
        });
        $metadata->method('getObjectMetadataByVersion')->willReturnCallback(
            static function (string $bucket, string $key, string $versionId) use (&$nullObject): ?ObjectInfo {
                return $versionId === 'null' ? $nullObject : null;
            },
        );
        $metadata->method('getTierTransitionJob')->willReturnCallback(static function () use (&$status): array {
            return ['status' => $status];
        });
        $metadata->method('renewTierTransitionJobLease')->willReturn(true);
        $metadata->method('updateTierTransitionJobStatus')->willReturnCallback(
            static function (int $id, string $newStatus) use (&$status): bool {
                $status = $newStatus;

                return true;
            },
        );
        $metadata->method('updateObjectPlacement')->willReturnCallback(
            static function (
                string $bucket,
                string $key,
                ?string $versionId,
                string $storageClass,
                string $storageTier,
                string $storagePath,
                string $transitionStatus = 'available',
                ?string $transitionTargetTier = null,
            ) use (&$latestObject, &$nullObject, $nullVersionBecomesNoncurrent): void {
                $transitioned = new ObjectInfo(
                    $bucket,
                    $key,
                    $latestObject->size,
                    $latestObject->etag,
                    storageClass: $storageClass,
                    storageTier: $storageTier,
                    transitionStatus: $transitionStatus,
                    transitionTargetTier: $transitionTargetTier,
                    ownerId: $latestObject->ownerId,
                    systemMetadata: ['storagePath' => $storagePath],
                );
                $nullObject = $transitioned;
                if ($transitionStatus === 'processing') {
                    $latestObject = $transitioned;

                    return;
                }
                $latestObject = $nullVersionBecomesNoncurrent
                    ? new ObjectInfo(
                        $bucket,
                        $key,
                        5,
                        '"newer"',
                        storageTier: 'STANDARD',
                        ownerId: 'owner',
                        versionId: 'numbered-version',
                        systemMetadata: ['storagePath' => 'newer-path'],
                    )
                    : $transitioned;
            },
        );
        $metadata->method('enqueueStorageGarbage')->willReturnCallback(
            static function (string $bucket, string $tier, string $path) use (&$garbage): void {
                $garbage[] = [$bucket, $tier, $path];
            },
        );
        $metadata->method('transaction')->willReturnCallback(
            static function (callable $callback) use (&$transactions, $probeFails): mixed {
                if ($transactions === 2 && $probeFails) {
                    $transactions++;
                    throw new \RuntimeException('publication probe unavailable');
                }
                $result = $callback();
                $transactions++;
                if ($transactions === 2) {
                    throw new \RuntimeException('commit acknowledgement lost');
                }

                return $result;
            },
        );
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]);
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => (string) $message, 'context' => $context];
            }
        };

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            (new TierTransitionExecutor($metadata, $tiers, $logger))->processNext(1),
            json_encode($logger->records, JSON_THROW_ON_ERROR),
        );
        self::assertSame(3, $transactions);
        self::assertInstanceOf(ObjectInfo::class, $nullObject);
        self::assertSame('GLACIER', $nullObject->storageTier);
        self::assertSame('archive-data', \Amp\ByteStream\buffer($archive->getObjectByPath((string) $nullObject->systemMetadata['storagePath'])));
        self::assertSame([['bucket', 'STANDARD', $source->path]], $garbage);
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function commitProbeOutcomes(): iterable
    {
        yield 'published placement confirmed' => [false, false];
        yield 'publication probe unavailable' => [true, false];
        yield 'published null version became noncurrent' => [false, true];
    }

    public function test_cancelled_source_delete_after_commit_leaves_exact_gc_job(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->enqueueTierTransitionJob('bucket', 'archive.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
        $cancelledSource = new CallbackStorageBackend(
            $hot,
            beforeDelete: static function (): never {
                throw new \Amp\CancelledException();
            },
        );
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $cancelledSource, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]);

        try {
            (new TierTransitionExecutor($this->metadata, $tiers))->processNext(1);
            self::fail('Expected cancellation after the placement commit.');
        } catch (\Amp\CancelledException) {
            $moved = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
            self::assertNotNull($moved);
            self::assertSame('GLACIER', $moved->storageTier);
            self::assertSame('archive-data', \Amp\ByteStream\buffer($hot->getObjectByPath($source->path)));
            self::assertSame(1, (new StorageGarbageCollector($this->metadata, new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ])))->collect(1));
            self::assertSame('archive-data', \Amp\ByteStream\buffer($archive->getObjectByPath((string) $moved->systemMetadata['storagePath'])));
            $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
            $hot->getObjectByPath($source->path)->read();
        }
    }

    public function test_processes_transition_job_with_copy_verify_commit_and_source_cleanup(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'archive.bin',
            ownerId: 'owner',
            size: $source->size,
            etag: '"' . $source->md5Hex . '"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: null,
            sourceTier: 'STANDARD',
            targetTier: 'GLACIER',
            targetStorageClass: 'GLACIER',
            sourceStoragePath: $source->path,
        );

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
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

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);

        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');

        self::assertNotNull($job);
        self::assertNotNull($object);
        self::assertSame('completed', $job['status']);
        self::assertSame('GLACIER', $object->storageClass);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame($object->systemMetadata['storagePath'], $job['targetStoragePath']);

        $targetData = $archive->getObjectByPath($object->systemMetadata['storagePath'])->read();
        self::assertSame('archive-data', $targetData);
        self::assertSame([
            'objectCount' => 1,
            'bytesUsed' => $source->size,
        ], $this->metadata->getBucketStorageStats('bucket'));

        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $hot->getObjectByPath($source->path)->read();
    }

    public function test_reclaimed_transition_job_ignores_old_worker_before_copy(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'reclaimed.bin', new ReadableBuffer('source'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'reclaimed.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $id = $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'reclaimed.bin',
            null,
            'STANDARD',
            'GLACIER',
            'GLACIER',
            $source->path,
        );
        $first = $this->metadata->dequeueTierTransitionJobs(1)[0];
        $this->metadata->updateTierTransitionJobStatus($id, 'pending', nextAttemptAt: 0, incrementAttempts: false);
        $second = $this->metadata->dequeueTierTransitionJobs(1)[0];
        self::assertSame($first['leaseGeneration'] + 1, $second['leaseGeneration']);

        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));
        self::assertSame('completed', $executor->processDequeuedJob($first));
        self::assertSame('processing', $this->metadata->getTierTransitionJob($id)['status'] ?? null);
        self::assertSame('STANDARD', $this->metadata->getObjectMetadata('bucket', 'reclaimed.bin')?->storageTier);

        self::assertSame('completed', $executor->processDequeuedJob($second));
        self::assertSame('completed', $this->metadata->getTierTransitionJob($id)['status'] ?? null);
        self::assertSame('GLACIER', $this->metadata->getObjectMetadata('bucket', 'reclaimed.bin')?->storageTier);
    }

    public function test_reclaimed_transition_job_cannot_publish_an_old_physical_copy(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'late.bin', new ReadableBuffer('source'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'late.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $id = $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'late.bin',
            null,
            'STANDARD',
            'GLACIER',
            'GLACIER',
            $source->path,
        );
        $reclaimed = null;
        $racingArchive = new CallbackStorageBackend($archive, afterPut: function () use ($id, &$reclaimed): void {
            $this->metadata->updateTierTransitionJobStatus($id, 'pending', nextAttemptAt: 0, incrementAttempts: false);
            $reclaimed = $this->metadata->dequeueTierTransitionJobs(1)[0];
        });
        $firstWorker = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $racingArchive, restoreRequired: true),
        ]));
        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $firstWorker->processNext(1));
        self::assertIsArray($reclaimed);
        self::assertSame('processing', $this->metadata->getTierTransitionJob($id)['status'] ?? null);
        self::assertSame('STANDARD', $this->metadata->getObjectMetadata('bucket', 'late.bin')?->storageTier);

        $secondWorker = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));
        self::assertSame('completed', $secondWorker->processDequeuedJob($reclaimed));
        self::assertSame('GLACIER', $this->metadata->getObjectMetadata('bucket', 'late.bin')?->storageTier);
    }

    public function test_worker_missing_target_tier_defers_without_exhausting_attempts_for_another_worker(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'move.bin', new ReadableBuffer('archive-data'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'move.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'move.bin',
            null,
            'STANDARD',
            'GLACIER',
            'GLACIER',
            $source->path,
            maxAttempts: 1,
        );

        $limitedWorker = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([new StorageTier('STANDARD', $hot, defaultWriteTier: true)]),
        );
        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
            $limitedWorker->processNext(1),
        );
        $deferred = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'move.bin');
        self::assertNotNull($deferred);
        self::assertNotNull($object);
        self::assertSame('pending', $deferred['status']);
        self::assertSame(0, $deferred['attempts']);
        self::assertGreaterThan(microtime(true), $deferred['nextAttemptAt']);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame('pending', $object->transitionStatus);
        self::assertSame($source->path, $object->systemMetadata['storagePath']);

        // Advance the deferred queue item to model the next worker poll.
        $this->metadata->updateTierTransitionJobStatus(
            $jobId,
            'pending',
            nextAttemptAt: microtime(true) - 1,
            incrementAttempts: false,
        );
        $configuredWorker = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );
        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $configuredWorker->processNext(1),
        );
        $completed = $this->metadata->getTierTransitionJob($jobId);
        $moved = $this->metadata->getObjectMetadata('bucket', 'move.bin');
        self::assertNotNull($completed);
        self::assertNotNull($moved);
        self::assertSame('completed', $completed['status']);
        self::assertSame(0, $completed['attempts']);
        self::assertSame('GLACIER', $moved->storageTier);
        self::assertSame('archive-data', $archive->getObjectByPath($moved->systemMetadata['storagePath'])->read());
    }

    public function test_transition_copies_encrypted_physical_payload_without_comparing_plaintext_etag(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $ciphertext = 'crypt';
        $source = $hot->putObject('bucket', 'encrypted.bin', new ReadableBuffer($ciphertext));
        $plaintext = 'plain';
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'encrypted.bin',
            ownerId: 'owner',
            size: strlen($plaintext),
            etag: '"' . md5($plaintext) . '"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
            userMetadata: ['__sse-algorithm' => 'AES256'],
            checksumSha256: base64_encode(hash('sha256', $plaintext, true)),
        );
        $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'encrypted.bin',
            null,
            'STANDARD',
            'GLACIER',
            'GLACIER',
            $source->path,
        );

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );
        $object = $this->metadata->getObjectMetadata('bucket', 'encrypted.bin');
        self::assertNotNull($object);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame($ciphertext, $archive->getObjectByPath($object->systemMetadata['storagePath'])->read());
        self::assertSame(strlen($plaintext), $object->size);
        self::assertSame('"' . md5($plaintext) . '"', $object->etag);
    }

    public function test_retries_failed_transition_without_switching_metadata(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'corrupt.bin', new ReadableBuffer('good-data'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'corrupt.bin',
            ownerId: 'owner',
            size: $source->size + 1,
            etag: '"00000000000000000000000000000000"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'corrupt.bin',
            versionId: null,
            sourceTier: 'STANDARD',
            targetTier: 'GLACIER',
            targetStorageClass: 'GLACIER',
            sourceStoragePath: $source->path,
            maxAttempts: 2,
        );

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0], $stats);

        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'corrupt.bin');

        self::assertNotNull($job);
        self::assertNotNull($object);
        self::assertSame('pending', $job['status']);
        self::assertSame(1, $job['attempts']);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame($source->path, $object->systemMetadata['storagePath']);
        self::assertSame('pending', $object->transitionStatus);
        self::assertSame('GLACIER', $object->transitionTargetTier);
        self::assertNotNull($object->transitionError);
    }

    public function test_rejects_corrupt_multipart_source_using_persisted_checksum(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'corrupt.bin', new ReadableBuffer('bad-data!'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'corrupt.bin',
            ownerId: 'owner',
            size: $source->size,
            etag: '"0123456789abcdef0123456789abcdef-2"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
            checksumSha256: base64_encode(hash('sha256', 'good-data', true)),
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            bucket: 'bucket',
            key: 'corrupt.bin',
            versionId: null,
            sourceTier: 'STANDARD',
            targetTier: 'GLACIER',
            targetStorageClass: 'GLACIER',
            sourceStoragePath: $source->path,
            maxAttempts: 1,
        );

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 0, 'deadLetter' => 1],
            $executor->processNext(1),
        );
        self::assertSame('dead_letter', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadata('bucket', 'corrupt.bin');
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame($source->path, $object->systemMetadata['storagePath']);
    }

    public function test_stale_unversioned_transition_job_after_overwrite_is_completed_without_switching_new_object(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $old = $hot->putObject('bucket', 'same-size.bin', new ReadableBuffer('old'));
        $this->metadata->putObjectMetadata('bucket', 'same-size.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'same-size.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'same-size.bin', null, 'STANDARD', 'STANDARD', $old->path, 'pending', 'GLACIER');

        $new = $hot->putObject('bucket', 'same-size.bin', new ReadableBuffer('new'));
        $this->metadata->putObjectMetadata('bucket', 'same-size.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
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
        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'same-size.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['attempts']);
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame($new->path, $object->systemMetadata['storagePath']);
        self::assertSame('new', $hot->getObjectByPath($object->systemMetadata['storagePath'])->read());
    }

    public function test_overwrite_during_physical_transition_does_not_switch_new_object(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $old = $hot->putObject('bucket', 'racing.bin', new ReadableBuffer('old'));
        $this->metadata->putObjectMetadata('bucket', 'racing.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'racing.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'racing.bin', null, 'STANDARD', 'STANDARD', $old->path, 'pending', 'GLACIER');

        $target = new CallbackStorageBackend($archive, afterPut: function () use ($hot): void {
            $new = $hot->putObject('bucket', 'racing.bin', new ReadableBuffer('new'));
            $this->metadata->putObjectMetadata('bucket', 'racing.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        });
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $target, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );

        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'racing.bin');
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame('new', \Amp\ByteStream\buffer($hot->getObjectByPath($object->systemMetadata['storagePath'])));
        self::assertNotNull($target->lastWrite);

        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $archive->getObjectByPath($target->lastWrite->path)->read();
    }

    public function test_versioned_transition_job_moves_only_target_version_when_latest_changes(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $old = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('old-version'));
        $oldVersionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'application/octet-stream', $old->path);
        $new = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('new-version'));
        $newVersionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'versioned.bin', $oldVersionId, 'STANDARD', 'GLACIER', 'GLACIER', $old->path);

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);
        $oldObject = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $oldVersionId);
        $newObject = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $newVersionId);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($oldObject);
        self::assertSame('GLACIER', $oldObject->storageTier);
        self::assertSame('old-version', $archive->getObjectByPath($oldObject->systemMetadata['storagePath'])->read());
        self::assertNotNull($newObject);
        self::assertSame('STANDARD', $newObject->storageTier);
        self::assertSame($new->path, $newObject->systemMetadata['storagePath']);
    }

    public function test_noncurrent_lifecycle_job_is_cancelled_when_version_becomes_current_before_copy(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $source = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('payload'));
        $versionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $markerId = $this->metadata->deleteObjectVersioned('bucket', 'versioned.bin', 'owner');
        $this->configureLifecycleTransition(false);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'versioned.bin', $versionId, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: false);
        $this->metadata->updateObjectPlacement('bucket', 'versioned.bin', $versionId, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');

        $this->metadata->deleteObjectVersion('bucket', 'versioned.bin', $markerId);
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));
        $executor->processNext(1);

        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $current = $this->metadata->getObjectMetadata('bucket', 'versioned.bin');
        self::assertNotNull($current);
        self::assertSame('STANDARD', $current->storageTier);
        self::assertSame('available', $current->transitionStatus);
        self::assertSame('payload', $hot->getObjectByPath($source->path)->read());
    }

    public function test_noncurrent_lifecycle_job_uses_demotion_time_when_revalidating_age(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $source = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('payload'));
        $versionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $replacement = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('replacement'));
        $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $replacement->size, '"' . $replacement->md5Hex . '"', 'application/octet-stream', $replacement->path);
        $connection = (new \ReflectionClass($this->metadata))->getMethod('connection')->invoke($this->metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->prepare('UPDATE s3_objects SET created_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?')
            ->execute(['2000-01-01T00:00:00Z', 'bucket', 'versioned.bin', $versionId]);

        $this->configureLifecycleTransition(false, noncurrentDays: 1);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'versioned.bin', $versionId, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: false);
        $this->metadata->updateObjectPlacement('bucket', 'versioned.bin', $versionId, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');

        (new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ])))->processNext(1);

        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $versionId);
        self::assertNotNull($object);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame('STANDARD', $object->storageTier);
    }

    public function test_noncurrent_null_version_lifecycle_job_moves_only_null_version(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $source = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('null version'));
        $this->metadata->putObjectMetadata('bucket', 'versioned.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $new = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('numbered version'));
        $newVersionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        $connection = (new \ReflectionClass($this->metadata))->getMethod('connection')->invoke($this->metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->prepare('UPDATE s3_objects SET noncurrent_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?')
            ->execute(['2000-01-01T00:00:00Z', 'bucket', 'versioned.bin', 'null']);
        $this->configureLifecycleTransition(false);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'versioned.bin', 'null', 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: false);
        $this->metadata->updateObjectPlacement('bucket', 'versioned.bin', 'null', 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');
        (new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ])))->processNext(1);

        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $oldObject = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', 'null');
        self::assertNotNull($oldObject);
        self::assertSame('GLACIER', $oldObject->storageTier);
        self::assertSame('null version', $archive->getObjectByPath($oldObject->systemMetadata['storagePath'])->read());
        self::assertSame('STANDARD', $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $newVersionId)?->storageTier);
    }

    public function test_current_lifecycle_job_is_cancelled_when_version_becomes_noncurrent_during_copy(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $source = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('payload'));
        $versionId = $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->configureLifecycleTransition(true);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'versioned.bin', $versionId, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: true);
        $this->metadata->updateObjectPlacement('bucket', 'versioned.bin', $versionId, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');
        $target = new CallbackStorageBackend($archive, afterPut: function () use ($hot): void {
            $new = $hot->putObject('bucket', 'versioned.bin', new ReadableBuffer('replacement'));
            $this->metadata->putObjectVersioned('bucket', 'versioned.bin', 'owner', $new->size, '"' . $new->md5Hex . '"', 'application/octet-stream', $new->path);
        });
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $target, restoreRequired: true),
        ]));
        $executor->processNext(1);

        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $previous = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.bin', $versionId);
        self::assertNotNull($previous);
        self::assertSame('STANDARD', $previous->storageTier);
        self::assertSame('available', $previous->transitionStatus);
        self::assertNotNull($target->lastWrite);
        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $archive->getObjectByPath($target->lastWrite->path)->read();
    }

    public function test_lifecycle_job_is_cancelled_when_rule_target_changes_before_copy(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'rule.bin', new ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata('bucket', 'rule.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->configureLifecycleTransition(true);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'rule.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: true);
        $this->metadata->updateObjectPlacement('bucket', 'rule.bin', null, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');
        $this->configureLifecycleTransition(true, 'DEEP_ARCHIVE');

        (new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ])))->processNext(1);

        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadata('bucket', 'rule.bin');
        self::assertNotNull($object);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame('STANDARD', $object->storageTier);
    }

    public function test_lifecycle_job_is_cancelled_when_stored_rule_becomes_malformed(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $target = new CallbackStorageBackend($archive);
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'rule.bin', new ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata('bucket', 'rule.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->configureLifecycleTransition(true);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'rule.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: true);
        $this->metadata->updateObjectPlacement('bucket', 'rule.bin', null, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => [['days' => 0, 'storageClass' => 'GLACIER']],
            'expiration' => ['date' => 'invalid'],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        (new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $target, restoreRequired: true),
        ])))->processNext(1);

        self::assertSame('completed', $this->metadata->getTierTransitionJob($jobId)['status'] ?? null);
        $object = $this->metadata->getObjectMetadata('bucket', 'rule.bin');
        self::assertNotNull($object);
        self::assertSame('available', $object->transitionStatus);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertNull($target->lastWrite);
    }

    public function test_legacy_job_is_cancelled_and_direct_job_still_transitions(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');
        $source = $hot->putObject('bucket', 'legacy.bin', new ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata('bucket', 'legacy.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $legacyId = $this->metadata->enqueueTierTransitionJob('bucket', 'legacy.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'legacy');
        $this->metadata->updateObjectPlacement('bucket', 'legacy.bin', null, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));
        $executor->processNext(1);
        self::assertSame('completed', $this->metadata->getTierTransitionJob($legacyId)['status'] ?? null);
        self::assertSame('available', $this->metadata->getObjectMetadata('bucket', 'legacy.bin')?->transitionStatus);

        $directId = $this->metadata->enqueueTierTransitionJob('bucket', 'legacy.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
        $executor->processNext(1);
        self::assertSame('direct', $this->metadata->getTierTransitionJob($directId)['origin'] ?? null);
        self::assertSame('GLACIER', $this->metadata->getObjectMetadata('bucket', 'legacy.bin')?->storageTier);
    }

    public function test_sqlite_upgrade_marks_existing_transition_jobs_as_legacy_without_rewriting_objects(): void
    {
        $connection = new \PDO('sqlite::memory:');
        $connection->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $connection->exec('CREATE TABLE s3_schema_version (version INTEGER NOT NULL, description TEXT NOT NULL)');
        $connection->exec("INSERT INTO s3_schema_version (version, description) VALUES (20, 'previous schema')");
        $connection->exec("CREATE TABLE s3_objects (
            id INTEGER PRIMARY KEY AUTOINCREMENT, bucket TEXT NOT NULL, key_name TEXT NOT NULL,
            version_id TEXT NOT NULL DEFAULT 'null', is_latest INTEGER NOT NULL DEFAULT 1,
            is_delete_marker INTEGER NOT NULL DEFAULT 0, owner_id TEXT NOT NULL, etag TEXT NOT NULL,
            size INTEGER NOT NULL, content_type TEXT NOT NULL DEFAULT 'application/octet-stream',
            content_encoding TEXT, content_disposition TEXT, cache_control TEXT,
            storage_class TEXT NOT NULL DEFAULT 'STANDARD', storage_tier TEXT NOT NULL DEFAULT 'STANDARD',
            transition_status TEXT NOT NULL DEFAULT 'available', transition_target_tier TEXT, transition_error TEXT,
            restore_status TEXT, restored_storage_path TEXT, restore_expires_at TEXT, storage_path TEXT NOT NULL,
            user_metadata TEXT NOT NULL DEFAULT '{}', checksum_crc32 TEXT, checksum_crc32c TEXT,
            checksum_sha1 TEXT, checksum_sha256 TEXT,
            created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
            UNIQUE(bucket, key_name, version_id)
        )");
        $connection->exec('CREATE TABLE s3_tier_transition_jobs (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $connection->exec("INSERT INTO s3_tier_transition_jobs (id, status) VALUES (1, 'pending')");
        // The physical garbage queue was introduced in v14 and gained
        // multipart-abort fields in v20; later migrations expect it to exist.
        $connection->exec('CREATE TABLE s3_storage_garbage (
            id INTEGER PRIMARY KEY AUTOINCREMENT, bucket TEXT NOT NULL,
            storage_tier TEXT NOT NULL, storage_path TEXT NOT NULL,
            status TEXT NOT NULL DEFAULT \'pending\', attempts INTEGER NOT NULL DEFAULT 0,
            next_attempt_at REAL NOT NULL, last_error TEXT,
            created_at TEXT NOT NULL DEFAULT (datetime(\'now\')),
            operation TEXT NOT NULL DEFAULT \'delete_path\', key_name TEXT, upload_id TEXT
        )');
        $connection->exec('CREATE INDEX idx_s3_storage_gc_status
            ON s3_storage_garbage(status, next_attempt_at)');
        $connection->exec('CREATE TABLE s3_restore_jobs (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        $connection->exec('CREATE TABLE s3_notification_queue (id INTEGER PRIMARY KEY, status TEXT NOT NULL)');
        // Bucket encryption metadata has existed since schema v1. Keep this
        // historical v20 fixture representative now that migrations validate
        // its stored encryption inventory before applying newer versions.
        $connection->exec('CREATE TABLE s3_encryption_configs (
            id INTEGER PRIMARY KEY AUTOINCREMENT,
            bucket TEXT NOT NULL UNIQUE, sse_algorithm TEXT NOT NULL,
            kms_master_key_id TEXT, bucket_key_enabled INTEGER NOT NULL DEFAULT 0,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')),
            updated_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\'))
        )');
        // Multipart uploads and parts existed before schema v20; later
        // migrations must see the same table shape as an actual upgrade rather
        // than a partial fixture.
        $connection->exec('CREATE TABLE s3_multipart_uploads (
            upload_id TEXT PRIMARY KEY, bucket TEXT NOT NULL, key_name TEXT NOT NULL,
            owner_id TEXT NOT NULL, content_type TEXT, user_metadata TEXT NOT NULL DEFAULT \'{}\'
        )');
        $connection->exec('CREATE TABLE s3_parts (
            id INTEGER PRIMARY KEY AUTOINCREMENT, upload_id TEXT NOT NULL,
            part_number INTEGER NOT NULL, etag TEXT NOT NULL, size INTEGER NOT NULL,
            storage_path TEXT NOT NULL,
            created_at TEXT NOT NULL DEFAULT (strftime(\'%Y-%m-%dT%H:%M:%SZ\', \'now\')),
            UNIQUE(upload_id, part_number),
            FOREIGN KEY (upload_id) REFERENCES s3_multipart_uploads(upload_id) ON DELETE CASCADE
        )');
        $connection->exec("INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id)
            VALUES ('legacy-upload', 'bucket', 'key', 'owner')");

        (new SchemaManager($connection))->migrate();

        $statement = $connection->query('SELECT origin, lifecycle_rule_id, expected_is_latest FROM s3_tier_transition_jobs WHERE id = 1');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertSame(['origin' => 'legacy', 'lifecycle_rule_id' => null, 'expected_is_latest' => null], $row);
        $uploadStatement = $connection->query("SELECT bucket_generation FROM s3_multipart_uploads WHERE upload_id = 'legacy-upload'");
        self::assertInstanceOf(\PDOStatement::class, $uploadStatement);
        self::assertSame('', $uploadStatement->fetchColumn());
        self::assertSame(SqliteSchema::VERSION, (new SchemaManager($connection))->getCurrentVersion());
    }

    private function configureLifecycleTransition(bool $current, string $targetStorageClass = 'GLACIER', int $noncurrentDays = 1): void
    {
        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => $current ? [['days' => 0, 'storageClass' => $targetStorageClass]] : null,
            'expiration' => null,
            'noncurrentTransitions' => $current ? null : [['noncurrentDays' => $noncurrentDays, 'storageClass' => $targetStorageClass]],
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
    }

    public function test_deleted_object_transition_job_is_completed_as_stale_noop(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'deleted.bin', new ReadableBuffer('deleted-data'));
        $this->metadata->putObjectMetadata('bucket', 'deleted.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'deleted.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
        $this->metadata->deleteObjectMetadata('bucket', 'deleted.bin');

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'deleted.bin'));
    }

    public function test_object_lock_legal_hold_does_not_block_internal_transition(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'locked.bin', new ReadableBuffer('locked-data'));
        $this->metadata->putObjectMetadata('bucket', 'locked.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $this->metadata->putObjectLegalHold('bucket', 'locked.bin', 'ON');
        $jobId = $this->metadata->enqueueTierTransitionJob('bucket', 'locked.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);

        $executor = new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $archive, restoreRequired: true),
            ]),
        );

        $stats = $executor->processNext(10);
        $job = $this->metadata->getTierTransitionJob($jobId);
        $object = $this->metadata->getObjectMetadata('bucket', 'locked.bin');

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertNotNull($object);
        self::assertSame('GLACIER', $object->storageTier);
        self::assertSame('ON', $this->metadata->getObjectLegalHold('bucket', 'locked.bin'));
        self::assertSame('locked-data', $archive->getObjectByPath($object->systemMetadata['storagePath'])->read());
    }

    public function test_lost_processing_lease_cannot_reopen_completed_transition_job(): void
    {
        $hot = new InMemoryBackend();
        $archive = new InMemoryBackend();
        $hot->createBucket('bucket');
        $archive->createBucket('bucket');

        $source = $hot->putObject('bucket', 'lease.bin', new ReadableBuffer('source'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'lease.bin',
            'owner',
            $source->size + 1,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $jobId = $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'lease.bin',
            null,
            'STANDARD',
            'GLACIER',
            'GLACIER',
            $source->path,
        );
        $target = new CallbackStorageBackend(
            $archive,
            afterPut: fn() => $this->metadata->updateTierTransitionJobStatus(
                $jobId,
                'completed',
                incrementAttempts: false,
            ),
        );
        $executor = new TierTransitionExecutor($this->metadata, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $target, restoreRequired: true),
        ]));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );

        $job = $this->metadata->getTierTransitionJob($jobId);
        self::assertNotNull($job);
        self::assertSame('completed', $job['status']);
        self::assertSame(0, $job['attempts']);
    }

    public function test_cancelled_batch_does_not_claim_another_transition_job(): void
    {
        $storage = new InMemoryBackend();
        $cancellation = new \Amp\DeferredCancellation();
        $cancellation->cancel();
        $executor = new TierTransitionExecutor(
            $this->metadata,
            StorageTierRegistry::single($storage),
        );

        $this->expectException(\Amp\CancelledException::class);
        $executor->processNext(10, $cancellation->getCancellation());
    }
}
