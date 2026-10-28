<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\AwsS3GarbageClaimStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Symfony\Component\Process\Process;

final class ExternalGarbageAtomicityTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    public function test_postgres_aws_garbage_success_executes_provider_and_completes_exact_current_lease(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the AWS garbage success gate.');
        }

        $oldAccessKey = getenv('AWS_ACCESS_KEY_ID');
        $oldSecretKey = getenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_ACCESS_KEY_ID=test');
        putenv('AWS_SECRET_ACCESS_KEY=test');
        $metadata = MetadataStoreFactory::create('postgres', ['dsn' => trim($dsn)]);
        self::assertInstanceOf(AwsS3GarbageClaimStore::class, $metadata);
        $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'aws-garbage-success-' . $suffix;
        $owner = 'owner-' . $suffix;
        $providerExecuted = false;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $bucketInfo = $metadata->getBucket($bucket);
            self::assertNotNull($bucketInfo);
            $attemptId = bin2hex(random_bytes(16));
            $mock = new MockHandler([
                new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
                new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
                new Response(200, [
                    'Content-Length' => '4',
                    'x-amz-meta-opsfour-write-attempt' => 'v2',
                    'x-amz-meta-opsfour-attempt-id' => $attemptId,
                    'x-amz-meta-opsfour-owner-digest' => hash('sha256', $owner),
                    'x-amz-meta-opsfour-content-size' => '4',
                ]),
                new Response(204),
                new Response(404, ['content-type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'),
                new Response(404, ['content-type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'),
            ]);
            $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
                'bucket' => 'backing-private',
                'region' => 'us-east-1',
                'expected_owner_id' => '123456789012',
                'data_prefix' => 'objects',
                'reserved_prefix' => '.opsfour/write-attempts',
            ], new AwsS3ProviderValidator(static function (RequestInterface $request, array $options) use ($mock, &$providerExecuted) {
                if ($request->getMethod() === 'DELETE') {
                    $providerExecuted = true;
                }

                return $mock($request, $options);
            }));
            self::assertTrue($binding->admission->providerAdmitted);
            self::assertNotNull($binding->profile);
            $path = AwsS3WriteAttemptPath::forProfileObject(
                $binding->profile,
                $bucket,
                'key',
                $bucketInfo->generation,
                'STANDARD',
                $attemptId,
            );
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', $path, $owner);
            $claim = $metadata->dequeueStorageGarbage(1)[0] ?? null;
            self::assertIsArray($claim);
            $generation = $claim['lease_generation'] ?? null;
            self::assertIsInt($generation);
            $claimedDeadline = $claim['lease_expires_at'];
            $currentDeadline = $claimedDeadline + 60.0;
            self::assertTrue($metadata->renewStorageGarbageLease(
                $claim['id'],
                $currentDeadline,
                $claimedDeadline,
                $generation,
            ));
            $renewalStopped = false;

            $completed = (new AwsS3StorageBackend($binding->admission, $binding))->deleteClaimedGarbage(
                $metadata,
                $claim['id'],
                $bucket,
                'STANDARD',
                $path,
                $owner,
                $generation,
                static fn(): float => $currentDeadline,
                static function () use (&$renewalStopped): void {
                    $renewalStopped = true;
                },
            );

            self::assertTrue($providerExecuted);
            self::assertTrue($renewalStopped);
            self::assertTrue($completed);
            self::assertNull($pool->execute(
                'SELECT id FROM s3_storage_garbage WHERE id = $1 AND bucket = $2 AND storage_tier = $3 AND storage_path = $4 AND owner_id = $5',
                [$claim['id'], $bucket, 'STANDARD', $path, $owner],
            )->fetchRow());
        } finally {
            $pool->execute('DELETE FROM s3_storage_garbage WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_buckets WHERE name = $1', [$bucket]);
            $pool->close();
            putenv($oldAccessKey === false ? 'AWS_ACCESS_KEY_ID' : 'AWS_ACCESS_KEY_ID=' . $oldAccessKey);
            putenv($oldSecretKey === false ? 'AWS_SECRET_ACCESS_KEY' : 'AWS_SECRET_ACCESS_KEY=' . $oldSecretKey);
        }
    }

    public function test_postgres_aws_garbage_claim_rejects_stale_leases_and_every_durable_path_reference(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the disposable AWS garbage reference gate.');
        }

        $metadata = MetadataStoreFactory::create('postgres', ['dsn' => trim($dsn)]);
        self::assertInstanceOf(AwsS3GarbageClaimStore::class, $metadata);
        self::assertInstanceOf(ObjectWriteIntentStore::class, $metadata);
        self::assertInstanceOf(RestoredCopyTierStore::class, $metadata);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $metadata);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $metadata);
        $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'aws-garbage-proof-' . $suffix;
        $owner = 'owner-' . $suffix;
        $path = '.opsfour/write-attempts/' . $bucket . '/' . str_repeat('a', 32)
            . '/5354414e44415244/' . str_repeat('b', 64) . '/' . str_repeat('c', 32);
        $uploadId = 'upload-' . $suffix;
        $openAttemptId = bin2hex(random_bytes(16));
        $sealAttemptId = bin2hex(random_bytes(16));

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $bucketInfo = $metadata->getBucket($bucket);
            self::assertNotNull($bucketInfo);
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', $path, $owner);
            $claim = $metadata->dequeueStorageGarbage(1)[0] ?? null;
            self::assertIsArray($claim);
            self::assertSame($owner, $claim['owner_id'] ?? null);
            $generation = $claim['lease_generation'] ?? null;
            self::assertIsInt($generation);
            $leaseUntil = $claim['lease_expires_at'];
            $awsGarbage = self::closedAwsGarbageBackend();
            $attemptDelete = function (int $claimGeneration) use ($awsGarbage, $metadata, $claim, $bucket, $path, $owner, $leaseUntil): bool {
                return $awsGarbage->deleteClaimedGarbage(
                    $metadata,
                    $claim['id'],
                    $bucket,
                    'STANDARD',
                    $path,
                    $owner,
                    $claimGeneration,
                    static fn(): float => $leaseUntil,
                    static function (): void {},
                );
            };

            self::assertFalse($attemptDelete($generation + 1), 'A stale lease generation must not authorize deletion.');

            $metadata->putObjectMetadata($bucket, 'current', $owner, 4, 'etag', 'application/octet-stream', $path);
            self::assertFalse($attemptDelete($generation), 'A current placement must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_objects WHERE bucket = $1', [$bucket]);

            $metadata->putObjectMetadata($bucket, 'restored', $owner, 4, 'etag', 'application/octet-stream', 'different-base-path');
            $metadata->updateObjectRestoreStateWithTier(
                $bucket,
                'restored',
                null,
                'restored',
                $path,
                new \DateTimeImmutable('+1 day'),
                'STANDARD',
            );
            self::assertFalse($attemptDelete($generation), 'A restored-copy placement must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_objects WHERE bucket = $1', [$bucket]);

            $metadata->createMultipartUpload($uploadId, $bucket, 'multipart', $owner);
            $metadata->putPart($uploadId, 1, 'etag', 4, $path);
            self::assertFalse($attemptDelete($generation), 'A committed multipart part must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_multipart_uploads WHERE upload_id = $1', [$uploadId]);

            $open = $metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
                $openAttemptId,
                $bucket,
                $bucketInfo->generation,
                $owner,
                'STANDARD',
                $path,
                'put_object',
            ), 60.0);
            self::assertFalse($attemptDelete($generation), 'An open publication attempt must protect its exact physical path.');
            self::assertTrue($metadata->retireUndispatchedObjectWriteIntent($open->attemptId));

            $seal = $metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
                $sealAttemptId,
                $bucket,
                $bucketInfo->generation,
                $owner,
                'STANDARD',
                $path,
                'put_object',
                sealReserved: true,
            ), 60.0);
            $seal = $metadata->armObjectWriteIntent(
                $seal->attemptId,
                $seal->leaseUntil,
                bin2hex(random_bytes(16)),
                60.0,
                4,
                hash('sha256', 'body'),
            );
            self::assertNotNull($seal);
            $pool->execute('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = $1', [$sealAttemptId]);
            $sealedClaim = $metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0)[0] ?? null;
            self::assertNotNull($sealedClaim);
            self::assertTrue($metadata->sealObjectWriteIntent($sealedClaim->attemptId, $sealedClaim->claimGeneration));
            self::assertFalse($attemptDelete($generation), 'A permanent same-key seal must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_object_write_intents WHERE attempt_id = $1', [$sealAttemptId]);

            $metadata->createMultipartUpload($uploadId, $bucket, 'multipart', $owner);
            $metadata->createMultipartPartWriteIntent(
                $bucket,
                $bucketInfo->generation,
                'STANDARD',
                $uploadId,
                $path,
            );
            self::assertFalse($attemptDelete($generation), 'An active multipart part attempt must protect its exact physical path.');
            $pool->execute("DELETE FROM s3_storage_garbage WHERE bucket = $1 AND operation = 'part_write_intent'", [$bucket]);
            $pool->execute('DELETE FROM s3_multipart_uploads WHERE upload_id = $1', [$uploadId]);

            $completionUpload = 'completion-' . $suffix;
            $metadata->createMultipartUpload($completionUpload, $bucket, 'completion', $owner);
            $completion = new MultipartWriteIntent(
                attemptId: bin2hex(random_bytes(16)),
                uploadId: $completionUpload,
                bucket: $bucket,
                bucketGeneration: $bucketInfo->generation,
                ownerId: $owner,
                storageTier: 'STANDARD',
                assemblyPath: $path,
                finalPath: 'other-final-path',
                partDigest: str_repeat('d', 64),
                sseMode: null,
                leaseUntil: $metadata->multipartWriteIntentNow() + 600,
                nextAttemptAt: $metadata->multipartWriteIntentNow() + 600,
            );
            $metadata->createMultipartWriteIntent($completion);
            self::assertFalse($attemptDelete($generation), 'A multipart assembly path must protect its exact physical path.');
            $pool->execute('UPDATE s3_multipart_write_intents SET assembly_path = $1, final_path = $2 WHERE attempt_id = $3', ['other-assembly-path', $path, $completion->attemptId]);
            self::assertFalse($attemptDelete($generation), 'A multipart final path must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1', [$completion->attemptId]);
            $pool->execute('DELETE FROM s3_multipart_uploads WHERE upload_id = $1', [$completionUpload]);

            $transitionId = $metadata->enqueueTierTransitionJob($bucket, 'transition', null, 'STANDARD', 'ARCHIVE', 'GLACIER', $path);
            self::assertFalse($attemptDelete($generation), 'An active transition source must protect its exact physical path.');
            $pool->execute('UPDATE s3_tier_transition_jobs SET source_storage_path = $1, target_storage_path = $2 WHERE id = $3', ['other-transition-source', $path, $transitionId]);
            self::assertFalse($attemptDelete($generation), 'An active transition target must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_tier_transition_jobs WHERE id = $1', [$transitionId]);

            $restoreId = $metadata->enqueueRestoreJob($bucket, 'restore-job', null, 'ARCHIVE', $path, 1);
            self::assertFalse($attemptDelete($generation), 'An active restore source must protect its exact physical path.');
            $pool->execute('UPDATE s3_restore_jobs SET source_storage_path = $1, restored_storage_path = $2 WHERE id = $3', ['other-restore-source', $path, $restoreId]);
            self::assertFalse($attemptDelete($generation), 'An active restore target must protect its exact physical path.');
            $pool->execute('DELETE FROM s3_restore_jobs WHERE id = $1', [$restoreId]);

        } finally {
            $pool->execute('DELETE FROM s3_object_write_intents WHERE attempt_id = $1 OR attempt_id = $2', [$openAttemptId, $sealAttemptId]);
            $pool->execute('DELETE FROM s3_storage_garbage WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_multipart_uploads WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_objects WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_buckets WHERE name = $1', [$bucket]);
            $pool->close();
        }
    }

    public function test_postgres_aws_garbage_preserves_original_owner_across_bucket_name_reuse(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the AWS garbage owner gate.');
        }

        $metadata = MetadataStoreFactory::create('postgres', ['dsn' => trim($dsn)]);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'aws-garbage-owner-' . $suffix;
        $originalOwner = 'original-' . $suffix;
        $replacementOwner = 'replacement-' . $suffix;

        try {
            $metadata->createBucket($originalOwner, $bucket, 'us-east-1');
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', 'owner-bound-path', $originalOwner);
            $metadata->deleteBucket($originalOwner, $bucket);
            $metadata->createBucket($replacementOwner, $bucket, 'us-east-1');

            $claim = $metadata->dequeueStorageGarbage(1)[0] ?? null;
            self::assertIsArray($claim);
            self::assertSame($originalOwner, $claim['owner_id'] ?? null);
        } finally {
            $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
            $pool->execute('DELETE FROM s3_storage_garbage WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_buckets WHERE name = $1', [$bucket]);
            $pool->close();
        }
    }

    public function test_postgres_aws_garbage_owner_lock_serializes_final_reference_race(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the AWS garbage owner-lock race.');
        }

        $writer = MetadataStoreFactory::create('postgres', ['dsn' => trim($dsn)]);
        $collector = MetadataStoreFactory::create('postgres', ['dsn' => trim($dsn)]);
        self::assertInstanceOf(AwsS3GarbageClaimStore::class, $collector);
        $suffix = bin2hex(random_bytes(6));
        $bucket = 'aws-garbage-race-' . $suffix;
        $owner = 'owner-' . $suffix;
        $path = 'race-path-' . $suffix;
        $lockHeld = new \Amp\DeferredFuture();
        $releaseWriter = new \Amp\DeferredFuture();
        $awsGarbage = self::closedAwsGarbageBackend();

        try {
            $writer->createBucket($owner, $bucket, 'us-east-1');
            $collector->enqueueStorageGarbage($bucket, 'STANDARD', $path, $owner);
            $claim = $collector->dequeueStorageGarbage(1)[0] ?? null;
            self::assertIsArray($claim);
            $generation = $claim['lease_generation'] ?? null;
            self::assertIsInt($generation);

            $referenceWriter = \Amp\async(function () use ($writer, $owner, $bucket, $path, $lockHeld, $releaseWriter): void {
                $writer->transaction(function () use ($writer, $owner, $bucket, $path, $lockHeld, $releaseWriter): void {
                    $writer->lockOwnerForUpdate($owner);
                    $lockHeld->complete();
                    $releaseWriter->getFuture()->await();
                    $writer->putObjectMetadata($bucket, 'racing-reference', $owner, 1, 'etag', 'application/octet-stream', $path);
                });
            });
            $lockHeld->getFuture()->await();

            $deletion = \Amp\async(function () use ($awsGarbage, $collector, $claim, $bucket, $path, $owner, $generation): bool {
                return $awsGarbage->deleteClaimedGarbage(
                    $collector,
                    $claim['id'],
                    $bucket,
                    'STANDARD',
                    $path,
                    $owner,
                    $generation,
                    static fn(): float => $claim['lease_expires_at'],
                    static function (): void {},
                );
            });
            \Amp\delay(0.05);
            self::assertFalse($deletion->isComplete(), 'Provider deletion must wait behind the owner reference-write lock.');

            $releaseWriter->complete();
            $referenceWriter->await();
            self::assertFalse($deletion->await());
        } finally {
            if (! $releaseWriter->isComplete()) {
                $releaseWriter->complete();
            }
            $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
            $pool->execute('DELETE FROM s3_storage_garbage WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_objects WHERE bucket = $1', [$bucket]);
            $pool->execute('DELETE FROM s3_buckets WHERE name = $1', [$bucket]);
            $pool->close();
        }
    }

    private static function closedAwsGarbageBackend(): AwsS3StorageBackend
    {
        return new AwsS3StorageBackend(AwsS3ProviderAdmission::denied(
            'us-east-1',
            true,
            true,
            AwsS3ProviderAdmission::VERSIONING_UNKNOWN,
            AwsS3ProviderAdmission::REASON_VERSIONING_CHECK_FAILED,
        ));
    }

    #[DataProvider('databases')]
    public function test_direct_discard_keeps_another_connection_claimed_job(string $driver, string $dsnEnv): void
    {
        $this->markDiscardPhase('start', $driver);
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} garbage discard gate.");
        }

        $this->markDiscardPhase('create-first-store', $driver);
        $firstStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $this->markDiscardPhase('create-second-store', $driver);
        $secondStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $this->markDiscardPhase('connect-diagnostic-pool', $driver);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $firstStore);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'garbage-discard-' . bin2hex(random_bytes(5));

        try {
            $this->markDiscardPhase('enqueue-claimed', $driver);
            $firstStore->enqueueStorageGarbage($bucket, 'STANDARD', 'claimed');
            $this->markDiscardPhase('claim', $driver);
            $claimed = $firstStore->dequeueStorageGarbage(1);
            self::assertCount(1, $claimed);
            $generation = $claimed[0]['lease_generation'] ?? null;
            self::assertIsInt($generation);

            $this->markDiscardPhase('discard-processing', $driver);
            $secondStore->discardStorageGarbage($bucket, 'STANDARD', 'claimed');
            $this->markDiscardPhase('read-processing', $driver);
            $row = $pool->execute($driver === 'postgres'
                ? 'SELECT status, lease_generation FROM s3_storage_garbage WHERE id = $1'
                : 'SELECT status, lease_generation FROM s3_storage_garbage WHERE id = ?', [$claimed[0]['id']])->fetchRow();
            self::assertNotNull($row);
            self::assertSame('processing', $row['status']);
            self::assertSame($generation, (int) $row['lease_generation']);
            $this->markDiscardPhase('complete-claim', $driver);
            self::assertTrue($firstStore->completeStorageGarbageLease(
                $claimed[0]['id'],
                $claimed[0]['lease_expires_at'],
                $generation,
            ));

            $this->markDiscardPhase('discard-pending', $driver);
            $secondStore->enqueueStorageGarbage($bucket, 'STANDARD', 'pending');
            $secondStore->discardStorageGarbage($bucket, 'STANDARD', 'pending');
            self::assertSame([], $firstStore->dequeueStorageGarbage(1));
            $this->markDiscardPhase('complete', $driver);
        } finally {
            $this->markDiscardPhase('cleanup', $driver);
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_storage_garbage WHERE bucket = $1'
                : 'DELETE FROM s3_storage_garbage WHERE bucket = ?', [$bucket]);
            $pool->close();
        }
    }

    private function markDiscardPhase(string $phase, string $driver): void
    {
        $path = getenv('S3_TEST_GC_PHASE_FILE');
        if ($path !== false && $path !== '') {
            file_put_contents($path, $driver . ':' . $phase . "\n", FILE_APPEND | LOCK_EX);
        }
    }

    #[DataProvider('databases')]
    public function test_two_connections_fence_reclaimed_storage_garbage_by_generation(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} garbage lease gate.");
        }

        $firstStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $secondStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $firstStore);
        self::assertInstanceOf(FencedStorageGarbageStore::class, $secondStore);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'garbage-lease-' . bin2hex(random_bytes(5));

        try {
            $firstStore->enqueueStorageGarbage($bucket, 'STANDARD', 'path');
            $first = $firstStore->dequeueStorageGarbage(1)[0];
            $firstGeneration = $first['lease_generation'] ?? null;
            self::assertIsInt($firstGeneration);
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE id = $1'
                : 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE id = ?', [$first['id']]);
            $second = $secondStore->dequeueStorageGarbage(1)[0];
            $secondGeneration = $second['lease_generation'] ?? null;
            self::assertIsInt($secondGeneration);
            self::assertSame($first['id'], $second['id']);
            self::assertSame($firstGeneration + 1, $secondGeneration);
            // Force the reclaimed claim to carry the exact old deadline. A
            // deadline-only CAS would now accept the stale worker.
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_storage_garbage SET next_attempt_at = $1 WHERE id = $2'
                : 'UPDATE s3_storage_garbage SET next_attempt_at = ? WHERE id = ?', [$first['lease_expires_at'], $first['id']]);
            self::assertFalse($firstStore->completeStorageGarbageLease($first['id'], $first['lease_expires_at'], $firstGeneration));
            self::assertFalse($firstStore->retryStorageGarbageLease($first['id'], 'stale', 0, $first['lease_expires_at'], $firstGeneration));
            self::assertTrue($secondStore->retryStorageGarbageLease($second['id'], 'current', 0, $first['lease_expires_at'], $secondGeneration));
            $third = $firstStore->dequeueStorageGarbage(1)[0];
            $thirdGeneration = $third['lease_generation'] ?? null;
            self::assertIsInt($thirdGeneration);
            self::assertSame($secondGeneration + 1, $thirdGeneration);
            self::assertTrue($firstStore->completeStorageGarbageLease($third['id'], $third['lease_expires_at'], $thirdGeneration));
        } finally {
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_two_connections_fence_reclaimed_notification_tier_and_restore_jobs(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} queue lease gate.");
        }

        $firstStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $secondStore = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(FencedQueueStore::class, $firstStore);
        self::assertInstanceOf(FencedQueueStore::class, $secondStore);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'queue-lease-' . bin2hex(random_bytes(5));

        try {
            $tierId = $firstStore->enqueueTierTransitionJob($bucket, 'key', null, 'STANDARD', 'archive', 'GLACIER', 'source');
            $restoreId = $firstStore->enqueueRestoreJob($bucket, 'key', null, 'archive', 'source', 1);
            $notificationId = $firstStore->enqueueNotification($bucket, 'key', 's3:ObjectCreated:Put', 'https://example.test', '{}');
            $firstTier = $firstStore->dequeueTierTransitionJobs(1)[0];
            $firstRestore = $firstStore->dequeueRestoreJobs(1)[0];
            $firstNotification = $firstStore->dequeueNotifications(1)[0];

            foreach (['s3_tier_transition_jobs' => $tierId, 's3_restore_jobs' => $restoreId, 's3_notification_queue' => $notificationId] as $table => $id) {
                $pool->execute($driver === 'postgres'
                    ? "UPDATE {$table} SET next_attempt_at = 0 WHERE id = $1"
                    : "UPDATE {$table} SET next_attempt_at = 0 WHERE id = ?", [$id]);
            }
            $secondTier = $secondStore->dequeueTierTransitionJobs(1)[0];
            $secondRestore = $secondStore->dequeueRestoreJobs(1)[0];
            $secondNotification = $secondStore->dequeueNotifications(1)[0];
            $firstNotificationGeneration = $firstNotification['lease_generation'] ?? null;
            $secondNotificationGeneration = $secondNotification['lease_generation'] ?? null;
            self::assertIsInt($firstNotificationGeneration);
            self::assertIsInt($secondNotificationGeneration);
            self::assertSame($firstTier['leaseGeneration'] + 1, $secondTier['leaseGeneration']);
            self::assertSame($firstRestore['leaseGeneration'] + 1, $secondRestore['leaseGeneration']);
            self::assertSame($firstNotificationGeneration + 1, $secondNotificationGeneration);

            foreach ([
                ['s3_tier_transition_jobs', $tierId, $firstTier['nextAttemptAt']],
                ['s3_restore_jobs', $restoreId, $firstRestore['nextAttemptAt']],
                ['s3_notification_queue', $notificationId, $firstNotification['lease_expires_at']],
            ] as [$table, $id, $oldDeadline]) {
                $pool->execute($driver === 'postgres'
                    ? "UPDATE {$table} SET next_attempt_at = $1 WHERE id = $2"
                    : "UPDATE {$table} SET next_attempt_at = ? WHERE id = ?", [$oldDeadline, $id]);
            }

            self::assertFalse($firstStore->updateTierTransitionJobStatusLease($tierId, 'completed', null, null, false, null, $firstTier['nextAttemptAt'], $firstTier['leaseGeneration']));
            self::assertFalse($firstStore->renewTierTransitionJobLeaseFenced($tierId, $secondTier['nextAttemptAt'] + 60, $firstTier['nextAttemptAt'], $firstTier['leaseGeneration']));
            self::assertFalse($firstStore->updateTierTransitionJobStatusLease($tierId, 'pending', 'stale', 0, true, null, $firstTier['nextAttemptAt'], $firstTier['leaseGeneration']));
            self::assertFalse($firstStore->updateRestoreJobStatusLease($restoreId, 'completed', null, null, false, null, $firstRestore['nextAttemptAt'], $firstRestore['leaseGeneration']));
            self::assertFalse($firstStore->renewRestoreJobLeaseFenced($restoreId, $secondRestore['nextAttemptAt'] + 60, $firstRestore['nextAttemptAt'], $firstRestore['leaseGeneration']));
            self::assertFalse($firstStore->updateRestoreJobStatusLease($restoreId, 'pending', 'stale', 0, true, null, $firstRestore['nextAttemptAt'], $firstRestore['leaseGeneration']));
            self::assertFalse($firstStore->updateNotificationStatusLease($notificationId, 'sent', null, null, true, $firstNotification['lease_expires_at'], $firstNotificationGeneration));
            self::assertFalse($firstStore->updateNotificationStatusLease($notificationId, 'pending', 'stale', 0, true, $firstNotification['lease_expires_at'], $firstNotificationGeneration));

            self::assertTrue($secondStore->updateTierTransitionJobStatusLease($tierId, 'completed', null, null, false, null, $firstTier['nextAttemptAt'], $secondTier['leaseGeneration']));
            self::assertTrue($secondStore->updateRestoreJobStatusLease($restoreId, 'completed', null, null, false, null, $firstRestore['nextAttemptAt'], $secondRestore['leaseGeneration']));
            self::assertTrue($secondStore->updateNotificationStatusLease($notificationId, 'sent', null, null, true, $firstNotification['lease_expires_at'], $secondNotificationGeneration));
        } finally {
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_garbage_claim_and_retry_use_database_time_with_a_thirteen_hour_fast_node(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} queue clock gate.");
        }

        $dsn = trim($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'garbage-clock-' . bin2hex(random_bytes(5));

        try {
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', 'path');
            $future = $metadata->multipartWriteIntentNow() + 3600;
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_storage_garbage SET next_attempt_at = $1 WHERE bucket = $2'
                : 'UPDATE s3_storage_garbage SET next_attempt_at = ? WHERE bucket = ?', [$future, $bucket]);
            self::assertSame('0', $this->skewedGarbageWorker($driver, $dsn, 'claim'));

            $pool->execute($driver === 'postgres'
                ? "UPDATE s3_storage_garbage SET status = 'processing' WHERE bucket = $1"
                : "UPDATE s3_storage_garbage SET status = 'processing' WHERE bucket = ?", [$bucket]);
            self::assertSame('0', $this->skewedGarbageWorker($driver, $dsn, 'claim'));

            $pool->execute($driver === 'postgres'
                ? "UPDATE s3_storage_garbage SET status = 'pending', next_attempt_at = 0 WHERE bucket = $1"
                : "UPDATE s3_storage_garbage SET status = 'pending', next_attempt_at = 0 WHERE bucket = ?", [$bucket]);
            self::assertSame('0', $this->skewedGarbageWorker($driver, $dsn, 'retry'));
            $result = $pool->execute($driver === 'postgres'
                ? 'SELECT status, attempts, next_attempt_at FROM s3_storage_garbage WHERE bucket = $1'
                : 'SELECT status, attempts, next_attempt_at FROM s3_storage_garbage WHERE bucket = ?', [$bucket]);
            $row = $result->fetchRow();
            self::assertNotNull($row);
            self::assertSame('pending', $row['status']);
            self::assertSame(1, (int) $row['attempts']);
            self::assertEqualsWithDelta($metadata->multipartWriteIntentNow() + 2, (float) $row['next_attempt_at'], 10.0);
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_storage_garbage WHERE bucket = $1'
                : 'DELETE FROM s3_storage_garbage WHERE bucket = ?', [$bucket]);
            $pool->close();
        }
    }

    /** @phpstan-impure */
    private function skewedGarbageWorker(string $driver, string $dsn, string $action): string
    {
        $worker = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Support/external-garbage-clock-skew-worker.php', $driver, $action],
            null,
            ['S3_TEST_QUEUE_CLOCK_DSN' => $dsn],
        );
        $worker->mustRun();

        return trim($worker->getOutput());
    }

    #[DataProvider('databases')]
    public function test_restore_and_lifecycle_keep_cleanup_durable_under_database_and_storage_faults(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} garbage atomicity gate.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(RestoredCopyTierStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(5));
        $bucket = 'garbage-atomic-' . $suffix;
        $owner = 'owner-' . $suffix;
        $trigger = 's3_garbage_reject_' . $suffix;
        $function = 's3_garbage_reject_fn_' . $suffix;
        $triggerActive = false;
        $storage = new InMemoryBackend();

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $storage->createBucket($bucket);
            $base = $storage->putObject($bucket, 'archive.bin', new ReadableBuffer('cold-source'));
            $restored = $storage->putObject($bucket, 'archive.bin', new ReadableBuffer('restored-copy'));
            $metadata->putObjectMetadata($bucket, 'archive.bin', $owner, $base->size, '"' . $base->md5Hex . '"', 'application/octet-stream', $base->path);
            $metadata->updateObjectRestoreStateWithTier($bucket, 'archive.bin', null, 'restored', $restored->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'STANDARD');

            $this->installQueueRejectTrigger($driver, $pool, $trigger, $function, $bucket);
            $triggerActive = true;
            $restoreCollector = new RestoreGarbageCollector($metadata, $storage);
            self::assertSame(['scanned' => 1, 'expired' => 0, 'failed' => 1], $restoreCollector->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
            self::assertSame($restored->path, $metadata->getObjectMetadata($bucket, 'archive.bin')?->restoredStoragePath);
            self::assertSame('restored-copy', \Amp\ByteStream\buffer($storage->getObjectByPath($restored->path)));
            $this->dropQueueRejectTrigger($driver, $pool, $trigger, $function);
            $triggerActive = false;

            $failingStorage = new CallbackStorageBackend($storage, beforeDelete: static function (): void {
                throw new \RuntimeException('physical delete unavailable');
            });
            self::assertSame(['scanned' => 1, 'expired' => 0, 'failed' => 1], (new RestoreGarbageCollector($metadata, $failingStorage))->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
            self::assertNull($metadata->getObjectMetadata($bucket, 'archive.bin')->restoredStoragePath);
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage)))->collect(1));
            $this->assertStoragePathMissing($storage, $restored->path);
            self::assertSame('cold-source', \Amp\ByteStream\buffer($storage->getObjectByPath($base->path)));

            $restoredAgain = $storage->putObject($bucket, 'archive.bin', new ReadableBuffer('another-restore'));
            $metadata->updateObjectRestoreStateWithTier($bucket, 'archive.bin', null, 'restored', $restoredAgain->path, new \DateTimeImmutable('2020-01-01T00:00:00Z'), 'STANDARD');
            self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], $restoreCollector->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z')));
            self::assertNull($metadata->getObjectMetadata($bucket, 'archive.bin')?->restoredStoragePath);
            $this->assertStoragePathMissing($storage, $restoredAgain->path);

            $expired = $storage->putObject($bucket, 'logs/expired.txt', new ReadableBuffer('lifecycle-copy'));
            $metadata->putObjectMetadata($bucket, 'logs/expired.txt', $owner, $expired->size, '"' . $expired->md5Hex . '"', 'text/plain', $expired->path);
            $pool->execute("UPDATE s3_objects SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00' WHERE bucket = '{$bucket}' AND key_name = 'logs/expired.txt'");
            $metadata->putBucketLifecycle($bucket, [[
                'id' => 'expire-logs', 'status' => 'Enabled', 'prefix' => 'logs/', 'filter' => null,
                'transitions' => null, 'expiration' => ['days' => 1],
                'noncurrentTransitions' => null, 'noncurrentExpiration' => null, 'abortIncompleteDays' => null,
            ]]);
            $this->installQueueRejectTrigger($driver, $pool, $trigger, $function, $bucket);
            $triggerActive = true;
            (new LifecycleExecutor($metadata, $storage))->processBucket($bucket);
            self::assertNotNull($metadata->getObjectMetadata($bucket, 'logs/expired.txt'));
            self::assertSame('lifecycle-copy', \Amp\ByteStream\buffer($storage->getObjectByPath($expired->path)));
            $this->dropQueueRejectTrigger($driver, $pool, $trigger, $function);
            $triggerActive = false;

            $lifecycle = new LifecycleExecutor($metadata, $storage);
            $lifecycle->processBucket($bucket); // Clear the pre-action checkpoint left by the failed sweep.
            $lifecycle->processBucket($bucket);
            self::assertNull($metadata->getObjectMetadata($bucket, 'logs/expired.txt'));
            $this->assertStoragePathMissing($storage, $expired->path);

            $failedDelete = $storage->putObject($bucket, 'logs/delete-fails.txt', new ReadableBuffer('later-garbage'));
            $metadata->putObjectMetadata($bucket, 'logs/delete-fails.txt', $owner, $failedDelete->size, '"' . $failedDelete->md5Hex . '"', 'text/plain', $failedDelete->path);
            $pool->execute("UPDATE s3_objects SET created_at = '2020-01-01 00:00:00', updated_at = '2020-01-01 00:00:00' WHERE bucket = '{$bucket}' AND key_name = 'logs/delete-fails.txt'");
            (new LifecycleExecutor($metadata, $failingStorage))->processBucket($bucket);
            self::assertNull($metadata->getObjectMetadata($bucket, 'logs/delete-fails.txt'));
            self::assertSame('later-garbage', \Amp\ByteStream\buffer($storage->getObjectByPath($failedDelete->path)));
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($storage)))->collect(1));
            $this->assertStoragePathMissing($storage, $failedDelete->path);
        } finally {
            if ($triggerActive) {
                $this->dropQueueRejectTrigger($driver, $pool, $trigger, $function);
            }
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_stale_multipart_cleanup_rejects_reused_bucket_generation(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} bucket generation gate.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(5));
        $bucket = 'stale-mpu-' . $suffix;
        $owner = 'owner-' . $suffix;
        $storage = new InMemoryBackend();

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $storage->createBucket($bucket);
            $observed = $metadata->getBucket($bucket);
            self::assertNotNull($observed);
            $metadata->deleteBucket($owner, $bucket);
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $uploadId = bin2hex(random_bytes(8));
            $metadata->createMultipartUpload($uploadId, $bucket, 'new-generation.bin', $owner);
            $part = $storage->putPart($bucket, 'new-generation.bin', $uploadId, 1, new ReadableBuffer('new-part'));
            $metadata->putPart($uploadId, 1, '"' . md5('new-part') . '"', $part->size, $part->path);
            $pool->execute("UPDATE s3_multipart_uploads SET created_at = '2020-01-01 00:00:00' WHERE upload_id = '{$uploadId}'");

            $executor = new LifecycleExecutor($metadata, $storage);
            $cleanup = (new \ReflectionClass($executor))->getMethod('cleanupStaleMultipartUploads');
            try {
                $cleanup->invoke($executor, $observed);
                self::fail('Old bucket generation must not abort a new upload.');
            } catch (OperationAbortedException) {
                self::assertNotNull($metadata->getMultipartUpload($uploadId));
                self::assertCount(1, $metadata->getParts($uploadId));
                $assembled = $storage->assembleMultipartUpload($bucket, 'new-generation.bin', $uploadId, [[
                    'partNumber' => 1,
                    'etag' => '"' . md5('new-part') . '"',
                    'storagePath' => $part->path,
                ]]);
                self::assertSame('new-part', \Amp\ByteStream\buffer($storage->getObjectByPath($assembled->path)));
            }
        } finally {
            $pool->close();
        }
    }

    private function assertStoragePathMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            $storage->getObjectByPath($path)->read();
            self::fail('Physical storage path still exists.');
        } catch (NoSuchKeyException) {
            return;
        }
    }

    /** @param PostgresConnectionPool|MysqlConnectionPool $pool */
    private function installQueueRejectTrigger(string $driver, PostgresConnectionPool|MysqlConnectionPool $pool, string $trigger, string $function, string $bucket): void
    {
        if ($driver === 'postgres') {
            $pool->execute("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS 'BEGIN RAISE EXCEPTION ''queue unavailable''; END'");
            $pool->execute("CREATE TRIGGER {$trigger} BEFORE INSERT ON s3_storage_garbage FOR EACH ROW WHEN (NEW.bucket = '{$bucket}') EXECUTE FUNCTION {$function}()");

            return;
        }

        $pool->query("CREATE TRIGGER {$trigger} BEFORE INSERT ON s3_storage_garbage FOR EACH ROW BEGIN IF NEW.bucket = '{$bucket}' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'queue unavailable'; END IF; END");
    }

    /** @param PostgresConnectionPool|MysqlConnectionPool $pool */
    private function dropQueueRejectTrigger(string $driver, PostgresConnectionPool|MysqlConnectionPool $pool, string $trigger, string $function): void
    {
        if ($driver === 'postgres') {
            $pool->execute("DROP TRIGGER {$trigger} ON s3_storage_garbage");
            $pool->execute("DROP FUNCTION {$function}()");

            return;
        }

        $pool->query("DROP TRIGGER {$trigger}");
    }
}
