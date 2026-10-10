<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class StorageTierStartupTest extends TestCase
{
    /** @return iterable<string, array{string}> */
    public static function missingTierSources(): iterable
    {
        yield 'lifecycle rule' => ['lifecycle'];
        yield 'stored object' => ['object'];
        yield 'pending object transition' => ['object-target'];
        yield 'queued transition' => ['transition'];
        yield 'queued restore' => ['restore'];
        yield 'garbage entry' => ['garbage'];
        yield 'multipart upload' => ['multipart-upload'];
        yield 'multipart completion intent' => ['multipart-intent'];
        yield 'object write intent' => ['object-intent'];
    }

    #[DataProvider('missingTierSources')]
    public function test_api_server_refuses_to_start_with_referenced_unconfigured_tier(string $source): void
    {
        $path = sys_get_temp_dir() . '/s3-tier-start-' . bin2hex(random_bytes(8)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $metadata->createBucket('owner', 'tier-start-bucket', 'us-east-1');

        try {
            switch ($source) {
                case 'lifecycle':
                    $metadata->putBucketLifecycle('tier-start-bucket', [[
                        'id' => 'archive', 'status' => 'Enabled', 'prefix' => null,
                        'filter' => null,
                        'transitions' => [['days' => 1, 'storageClass' => 'GLACIER']],
                        'expiration' => null,
                        'noncurrentTransitions' => null,
                        'noncurrentExpiration' => null,
                        'abortIncompleteDays' => null,
                    ]]);
                    break;
                case 'object':
                    $metadata->putObjectMetadata('tier-start-bucket', 'key', 'owner', 1, '"etag"', 'text/plain', 'hot/key');
                    $metadata->updateObjectPlacement('tier-start-bucket', 'key', null, 'GLACIER', 'GLACIER', 'cold/key');
                    break;
                case 'object-target':
                    $metadata->putObjectMetadata('tier-start-bucket', 'key', 'owner', 1, '"etag"', 'text/plain', 'hot/key');
                    $metadata->updateObjectPlacement('tier-start-bucket', 'key', null, 'STANDARD', 'STANDARD', 'hot/key', transitionStatus: 'pending', transitionTargetTier: 'GLACIER');
                    break;
                case 'transition':
                    $metadata->enqueueTierTransitionJob('tier-start-bucket', 'key', null, 'STANDARD', 'GLACIER', 'GLACIER', 'hot/key');
                    break;
                case 'restore':
                    $metadata->enqueueRestoreJob('tier-start-bucket', 'key', null, 'GLACIER', 'cold/key', 1);
                    break;
                case 'garbage':
                    $metadata->enqueueStorageGarbage('tier-start-bucket', 'GLACIER', 'cold/key');
                    break;
                case 'multipart-upload':
                    $metadata->createMultipartUploadWithTier('tier-start-upload', 'tier-start-bucket', 'key', 'owner', 'GLACIER');
                    break;
                case 'multipart-intent':
                    $bucket = $metadata->getBucket('tier-start-bucket');
                    self::assertNotNull($bucket);
                    $metadata->createMultipartUploadWithTier('stale-upload', 'tier-start-bucket', 'key', 'owner', 'GLACIER');
                    $metadata->createMultipartWriteIntent(new MultipartWriteIntent(
                        attemptId: bin2hex(random_bytes(16)),
                        uploadId: 'stale-upload',
                        bucket: 'tier-start-bucket',
                        bucketGeneration: $bucket->generation,
                        ownerId: 'owner',
                        storageTier: 'GLACIER',
                        assemblyPath: 'cold/assembly',
                        finalPath: 'cold/final',
                        partDigest: str_repeat('a', 64),
                        sseMode: null,
                        leaseUntil: microtime(true) - 1,
                        nextAttemptAt: microtime(true) - 1,
                    ));
                    break;
                case 'object-intent':
                    $bucket = $metadata->getBucket('tier-start-bucket');
                    self::assertNotNull($bucket);
                    $metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
                        attemptId: bin2hex(random_bytes(16)),
                        bucket: 'tier-start-bucket',
                        bucketGeneration: $bucket->generation,
                        ownerId: 'owner',
                        storageTier: 'GLACIER',
                        storagePath: 'cold/object-attempt',
                        purpose: 'put',
                    ), 300.0);
                    break;
            }

            self::assertContains('GLACIER', $metadata->listReferencedStorageTiers());
            $filesystem = new Filesystem(new BlockingFilesystemDriver());
            $server = new S3Server(
                new S3ServerConfig(storagePath: sys_get_temp_dir()),
                metadata: $metadata,
                storage: new FilesystemBackend(sys_get_temp_dir(), $filesystem, $filesystem),
            );
            $server->addMiddleware(new AuthMiddleware(
                new InMemoryCredentialProvider(new Credential('access-key', 'secret-key', 'owner')),
                'us-east-1',
            ));

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('unconfigured storage tiers referenced by metadata: GLACIER');
            $server->start();
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_completed_tier_jobs_do_not_keep_retired_tiers_referenced(): void
    {
        $path = sys_get_temp_dir() . '/s3-tier-start-' . bin2hex(random_bytes(8)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();

        try {
            $transitionId = $metadata->enqueueTierTransitionJob('bucket', 'key', null, 'STANDARD', 'GLACIER', 'GLACIER', 'hot/key');
            $restoreId = $metadata->enqueueRestoreJob('bucket', 'key', null, 'GLACIER', 'cold/key', 1);
            self::assertContains('GLACIER', $metadata->listReferencedStorageTiers());

            $metadata->updateTierTransitionJobStatus($transitionId, 'completed');
            $metadata->updateRestoreJobStatus($restoreId, 'completed');
            self::assertSame([], $metadata->listReferencedStorageTiers());
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
