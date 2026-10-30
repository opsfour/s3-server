<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\ClusterWorkClaim;
use OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder;
use OpsFour\S3Server\Metadata\ClusterWorkToken;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedMultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Tests\Support\AwsS3OperationalStateFixture;
use OpsFour\S3Server\Tests\Support\RecordingClusterWorkFencingStore;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class MultipartWriteIntentCollectorTest extends TestCase
{
    public function test_takeover_retry_records_failed_takeover_outcome(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'REMOTE');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $now = $metadata->multipartWriteIntentNow();
        $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
            attemptId: 'attempt',
            uploadId: 'upload',
            bucket: 'bucket',
            bucketGeneration: $bucket->generation,
            ownerId: 'owner',
            storageTier: 'REMOTE',
            assemblyPath: 'assembly',
            finalPath: 'final',
            partDigest: 'digest',
            sseMode: null,
            leaseUntil: $now + 30,
            nextAttemptAt: $now + 30,
        ));
        self::assertTrue($metadata->armMultipartWriteIntent('attempt', str_repeat('a', 32), $now + 30, $now + 60));
        $connection = (new \ReflectionMethod($metadata, 'connection'))->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $expire = $connection->prepare('UPDATE s3_multipart_write_intents SET lease_until = ?, next_attempt_at = ? WHERE attempt_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $expire);
        $expire->execute([$now - 1, $now - 1, 'attempt']);
        $intent = $metadata->claimDueMultipartWriteIntents(1, $now, $now + 300)[0];
        $token = RecordingClusterWorkFencingStore::takeoverToken(ClusterWorkToken::MULTIPART_INTENT, $intent->attemptId);
        $fencing = new RecordingClusterWorkFencingStore(new ClusterWorkClaim($token, $intent));
        $backend = $this->createMock(StorageBackend::class);
        $backend->method('deleteObjectByPath')->willThrowException(new \RuntimeException('delete failed'));
        $collector = new MultipartWriteIntentCollector(
            $metadata,
            StorageTierRegistry::single($backend, 'REMOTE'),
            clusterFencing: $fencing,
            clusterWorkerId: 'node:worker',
        );

        self::assertSame(0, $collector->collect(1));
        self::assertSame([[
            'token' => $token,
            'outcome' => ClusterWorkOutcomeRecorder::FAILED_TAKEOVER,
        ]], $fencing->outcomes);
    }

    public function test_retained_aws_tombstone_is_backlog_not_a_collector_failure(): void
    {
        $oldKey = getenv('AWS_ACCESS_KEY_ID');
        $oldSecret = getenv('AWS_SECRET_ACCESS_KEY');
        putenv('AWS_ACCESS_KEY_ID=test');
        putenv('AWS_SECRET_ACCESS_KEY=test');
        try {
            $requests = [];
            $mock = new MockHandler([
                new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
                new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
            ]);
            $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
                'bucket' => 'backing-private',
                'region' => 'us-east-1',
                'expected_owner_id' => '123456789012',
                'data_prefix' => 'data',
                'reserved_prefix' => '.opsfour',
            ], new AwsS3ProviderValidator(static function (RequestInterface $request, array $options) use ($mock, &$requests) {
                $requests[] = $request;
                return $mock($request, $options);
            }));
            self::assertTrue($binding->admission->providerAdmitted);
            $backend = new AwsS3StorageBackend($binding->admission, $binding);
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $operationalMetadata = new AwsS3MultipartOperationalMetadataFixture($metadata);
            $state = AwsS3OperationalStateFixture::attach($backend, $binding, 'AWS', $operationalMetadata);
            $state->markCollectorFailure('storage_garbage');
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $metadata->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'AWS');
            $bucket = $metadata->getBucket('bucket');
            self::assertNotNull($bucket);
            $attemptId = str_repeat('a', 32);
            $path = '.opsfour/write-attempts/bucket/key/' . $attemptId;
            $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
                attemptId: $attemptId,
                uploadId: 'upload',
                bucket: 'bucket',
                bucketGeneration: $bucket->generation,
                ownerId: 'owner',
                storageTier: 'AWS',
                assemblyPath: $path,
                finalPath: $path,
                partDigest: str_repeat('b', 64),
                sseMode: null,
                leaseUntil: microtime(true) - 1,
                nextAttemptAt: microtime(true) - 1,
            ));

            $collector = new MultipartWriteIntentCollector(
                $operationalMetadata,
                StorageTierRegistry::single($backend, 'AWS'),
                awsOperationalState: $state,
            );
            self::assertSame(1, $collector->collect(1));
            self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attemptId)?->state);
            self::assertSame('collector', $state->snapshot()['reason']);
            $state->markCollectorHealthy('storage_garbage');
            self::assertSame('healthy', $state->snapshot()['reason']);
            self::assertTrue($state->mutationReady());
            self::assertCount(2, $requests);
        } finally {
            putenv($oldKey === false ? 'AWS_ACCESS_KEY_ID' : 'AWS_ACCESS_KEY_ID=' . $oldKey);
            putenv($oldSecret === false ? 'AWS_SECRET_ACCESS_KEY' : 'AWS_SECRET_ACCESS_KEY=' . $oldSecret);
        }
    }

    public function test_local_collector_retires_never_dispatched_attempt_but_preserves_armed_and_legacy_fences(): void
    {
        $root = sys_get_temp_dir() . '/s3-multipart-undispatched-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $storage = new FilesystemBackend($root);
        $now = $metadata->multipartWriteIntentNow();
        $attempts = [
            'undispatched' => str_repeat('a', 32),
            'armed' => str_repeat('b', 32),
            'legacy' => str_repeat('c', 32),
        ];
        $paths = [];

        try {
            foreach ($attempts as $name => $attemptId) {
                $uploadId = 'upload-' . $name;
                $metadata->createMultipartUploadWithTier($uploadId, 'bucket', $name, 'owner', 'STANDARD');
                $paths[$name] = $storage->planMultipartCompletionPaths(
                    'bucket',
                    $name,
                    $bucket->generation,
                    $attemptId,
                );
                $lease = $name === 'undispatched' || $name === 'legacy' ? $now - 1 : $now + 30;
                $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
                    attemptId: $attemptId,
                    uploadId: $uploadId,
                    bucket: 'bucket',
                    bucketGeneration: $bucket->generation,
                    ownerId: 'owner',
                    storageTier: 'STANDARD',
                    assemblyPath: $paths[$name]['assembly'],
                    finalPath: $paths[$name]['final'],
                    partDigest: str_repeat('d', 64),
                    sseMode: null,
                    leaseUntil: $lease,
                    nextAttemptAt: $lease,
                ));
            }

            self::assertTrue($metadata->armMultipartWriteIntent(
                $attempts['armed'],
                str_repeat('e', 32),
                $now + 30,
                $now + 60,
            ));
            $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
            self::assertInstanceOf(\PDO::class, $connection);
            $expire = $connection->prepare('UPDATE s3_multipart_write_intents
                SET lease_until = ?, next_attempt_at = ? WHERE attempt_id = ?');
            self::assertInstanceOf(\PDOStatement::class, $expire);
            $expire->execute([$now - 1, $now - 1, $attempts['armed']]);
            $legacy = $connection->prepare('UPDATE s3_multipart_write_intents
                SET dispatch_id = ? WHERE attempt_id = ?');
            self::assertInstanceOf(\PDOStatement::class, $legacy);
            $legacy->execute([ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $attempts['legacy']]);

            $collector = new MultipartWriteIntentCollector($metadata, StorageTierRegistry::single($storage));
            self::assertSame(2, $collector->collect(3));
            self::assertNull($metadata->getMultipartWriteIntent($attempts['undispatched']));
            self::assertFileDoesNotExist($paths['undispatched']['assembly']);
            self::assertFileDoesNotExist($paths['undispatched']['final']);

            self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attempts['armed'])?->state);
            foreach ($paths['armed'] as $path) {
                $seal = file_get_contents($path);
                self::assertIsString($seal);
                self::assertStringStartsWith(
                    "OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . $attempts['armed'] . "\n",
                    $seal,
                );
            }

            // A legacy sentinel does not prove whether an old writer was ever
            // dispatched. Keep its row for operator reconciliation without
            // treating it like the provably undispatched NULL state.
            self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attempts['legacy'])?->state);
            foreach ($paths['legacy'] as $path) {
                self::assertFileDoesNotExist($path);
            }
        } finally {
            $storage->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_second_collector_cannot_reclaim_during_yielding_cleanup_and_stale_generation_cannot_retry(): void
    {
        $path = sys_get_temp_dir() . '/s3-multipart-claim-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = new SqliteMetadataStore($path);
            $first->initialize();
            $second = new SqliteMetadataStore($path);
            $second->initialize();
            $first->createBucket('owner', 'bucket', 'us-east-1');
            $first->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'hot');
            $bucket = $first->getBucket('bucket');
            self::assertNotNull($bucket);
            $attemptId = str_repeat('f', 32);
            $first->createMultipartWriteIntent(new MultipartWriteIntent(
                attemptId: $attemptId,
                uploadId: 'upload',
                bucket: 'bucket',
                bucketGeneration: $bucket->generation,
                ownerId: 'owner',
                storageTier: 'hot',
                assemblyPath: 'memory://.assemblies/bucket/path/assembly',
                finalPath: 'memory://.assemblies/bucket/path/assembly',
                partDigest: str_repeat('c', 64),
                sseMode: null,
                leaseUntil: $first->multipartWriteIntentNow() - 1,
                nextAttemptAt: $first->multipartWriteIntentNow() - 1,
            ));
            $initialLease = null;
            $backend = $this->createMock(StorageBackend::class);
            $backend->method('deleteObjectByPath')->willReturnCallback(static function () use ($first, $attemptId, &$initialLease): void {
                $initialLease = $first->getMultipartWriteIntent($attemptId)?->nextAttemptAt;
                \Amp\delay(0.7);
            });
            $backend->method('getObjectByPath')->willThrowException(new NoSuchKeyException());
            $tiers = StorageTierRegistry::single($backend, 'hot');
            $future = \Amp\async(static fn(): int => (new MultipartWriteIntentCollector($first, $tiers, claimLeaseSeconds: 0.3, renewIntervalSeconds: 0.05))->collect(1));
            \Amp\delay(0.45);
            self::assertNotNull($initialLease);
            $current = $second->getMultipartWriteIntent($attemptId);
            self::assertNotNull($current);
            self::assertGreaterThan($initialLease, $current->nextAttemptAt);
            self::assertSame(1, $current->claimGeneration);
            self::assertSame(0, (new MultipartWriteIntentCollector($second, $tiers, claimLeaseSeconds: 0.3, renewIntervalSeconds: 0.05))->collect(1));
            self::assertSame(1, $future->await());

            $retained = $second->getMultipartWriteIntent($attemptId);
            self::assertNotNull($retained);
            self::assertTrue($second->retryMultipartWriteIntent($attemptId, $retained->nextAttemptAt, $second->multipartWriteIntentNow() - 1));
            $now = $second->multipartWriteIntentNow();
            $reclaimed = $second->claimDueMultipartWriteIntents(1, $now, $now + 60);
            self::assertCount(1, $reclaimed);
            self::assertSame(2, $reclaimed[0]->claimGeneration);
            self::assertFalse($first->renewMultipartCollectorClaim($attemptId, $now + 120, $reclaimed[0]->nextAttemptAt, 1));
            self::assertFalse($first->retryMultipartCollectorClaim($attemptId, $now + 120, $reclaimed[0]->nextAttemptAt, 1));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_pre_cancelled_collection_does_not_claim_due_intent(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'hot');
        $bucketInfo = $metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $generation = $bucketInfo->generation;
        $attemptId = str_repeat('a', 32);
        $intent = new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: 'upload',
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'hot',
            assemblyPath: 'memory://.assemblies/bucket/path/assembly',
            finalPath: 'memory://.assemblies/bucket/path/final',
            partDigest: str_repeat('c', 64),
            sseMode: null,
            leaseUntil: microtime(true) - 1,
            nextAttemptAt: microtime(true) - 1,
        );
        $metadata->createMultipartWriteIntent($intent);
        $cancellation = new \Amp\DeferredCancellation();
        $cancellation->cancel();

        try {
            (new MultipartWriteIntentCollector($metadata, StorageTierRegistry::single(new InMemoryBackend(), 'hot')))
                ->collect(1, $cancellation->getCancellation());
            self::fail('Cancellation should stop collection.');
        } catch (\Amp\CancelledException) {
            $remaining = $metadata->getMultipartWriteIntent($attemptId);
            self::assertNotNull($remaining);
            self::assertSame('active', $remaining->state);
            self::assertEqualsWithDelta($intent->nextAttemptAt, $remaining->nextAttemptAt, 0.001);
        }
    }

    public function test_crashed_sse_attempt_is_recovered_and_late_write_is_deleted_on_next_sweep(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createMultipartUploadWithTier('upload', 'bucket', 'key', 'owner', 'hot');
        $bucketInfo = $metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $generation = $bucketInfo->generation;
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $oldPaths = $storage->planMultipartCompletionPaths('bucket', 'key', $generation, str_repeat('b', 32));
        $newPaths = $storage->planMultipartCompletionPaths('bucket', 'key', str_repeat('c', 32), str_repeat('d', 32));
        $intent = new MultipartWriteIntent(
            attemptId: str_repeat('b', 32),
            uploadId: 'upload',
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'hot',
            assemblyPath: $oldPaths['assembly'],
            finalPath: $oldPaths['final'],
            partDigest: str_repeat('e', 64),
            sseMode: 'AES256',
            leaseUntil: microtime(true) - 1,
            nextAttemptAt: microtime(true) - 1,
        );
        $metadata->createMultipartWriteIntent($intent);
        $storage->putObjectAtPath('bucket', 'key', new ReadableBuffer('plaintext'), $oldPaths['assembly']);
        $storage->putObjectAtPath('bucket', 'key', new ReadableBuffer('ciphertext'), $oldPaths['final']);
        $storage->putObjectAtPath('bucket', 'key', new ReadableBuffer('new generation'), $newPaths['final']);

        $collector = new MultipartWriteIntentCollector($metadata, StorageTierRegistry::single($storage, 'hot'));
        self::assertSame(1, $collector->collect());
        self::assertSame('abandoned', $metadata->getMultipartWriteIntent($intent->attemptId)?->state);
        $this->assertMissing($storage, $oldPaths['assembly']);
        $this->assertMissing($storage, $oldPaths['final']);
        self::assertSame('new generation', \Amp\ByteStream\buffer($storage->getObjectByPath($newPaths['final'])));

        // A remote worker may complete after the first absence check. The
        // tombstone remains and another sweep removes the same exact path.
        $storage->putObjectAtPath('bucket', 'key', new ReadableBuffer('late bytes'), $oldPaths['final']);
        $claimed = $metadata->getMultipartWriteIntent($intent->attemptId);
        self::assertSame($oldPaths['final'], $claimed->finalPath);
        self::assertTrue($metadata->retryMultipartWriteIntent($intent->attemptId, $claimed->nextAttemptAt, microtime(true) - 1));
        self::assertSame(1, $collector->collect());
        $this->assertMissing($storage, $oldPaths['final']);
        self::assertSame('new generation', \Amp\ByteStream\buffer($storage->getObjectByPath($newPaths['final'])));
    }

    private function assertMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            $storage->getObjectByPath($path);
            self::fail('Uncommitted path remains readable.');
        } catch (NoSuchKeyException $error) {
            self::assertSame('NoSuchKey', $error->getErrorCode());
        }
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}

/** Exact operational identity for the retained AWS multipart collector fixture. */
final readonly class AwsS3MultipartOperationalMetadataFixture implements MultipartWriteIntentStore, FencedMultipartWriteIntentStore, AwsS3OperationalSummaryReader
{
    public function __construct(private SqliteMetadataStore $inner) {}

    public function multipartWriteIntentNow(): float
    {
        return $this->inner->multipartWriteIntentNow();
    }
    public function createMultipartWriteIntent(MultipartWriteIntent $intent): void
    {
        $this->inner->createMultipartWriteIntent($intent);
    }
    public function getMultipartWriteIntent(string $attemptId): ?MultipartWriteIntent
    {
        return $this->inner->getMultipartWriteIntent($attemptId);
    }
    public function renewMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $newLeaseUntil): bool
    {
        return $this->inner->renewMultipartWriteIntent($attemptId, $expectedLeaseUntil, $newLeaseUntil);
    }
    public function abandonMultipartWriteIntent(string $attemptId, float $nextAttemptAt): bool
    {
        return $this->inner->abandonMultipartWriteIntent($attemptId, $nextAttemptAt);
    }
    public function abandonMultipartWriteIntentsForUpload(string $uploadId, float $nextAttemptAt): int
    {
        return $this->inner->abandonMultipartWriteIntentsForUpload($uploadId, $nextAttemptAt);
    }
    public function consumeMultipartWriteIntent(string $attemptId): bool
    {
        return $this->inner->consumeMultipartWriteIntent($attemptId);
    }
    public function claimDueMultipartWriteIntents(int $limit, float $now, float $leaseUntil): array
    {
        return $this->inner->claimDueMultipartWriteIntents($limit, $now, $leaseUntil);
    }
    public function retryMultipartWriteIntent(string $attemptId, float $expectedLeaseUntil, float $nextAttemptAt): bool
    {
        return $this->inner->retryMultipartWriteIntent($attemptId, $expectedLeaseUntil, $nextAttemptAt);
    }
    public function renewMultipartCollectorClaim(string $attemptId, float $newLeaseUntil, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        return $this->inner->renewMultipartCollectorClaim($attemptId, $newLeaseUntil, $expectedLeaseUntil, $claimGeneration);
    }
    public function retryMultipartCollectorClaim(string $attemptId, float $nextAttemptAt, float $expectedLeaseUntil, int $claimGeneration): bool
    {
        return $this->inner->retryMultipartCollectorClaim($attemptId, $nextAttemptAt, $expectedLeaseUntil, $claimGeneration);
    }

    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        return [
            'open' => 0, 'ambiguous' => 0, 'seal_reserved' => 0, 'sealed' => 0,
            'multipart' => 1, 'part' => 0, 'cleanup' => 0, 'failures' => 0,
            'oldest_age_seconds' => 0.0, 'max_owner_open' => 0, 'max_owner_sealed' => 0,
            'max_owner_multipart' => 1, 'max_owner_part' => 0, 'global_open' => 0,
            'global_seal_reserved' => 0, 'global_multipart' => 1, 'global_part' => 0,
            'global_cleanup' => 0,
        ];
    }
}
