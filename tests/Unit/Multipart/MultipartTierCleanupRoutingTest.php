<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Multipart;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketHandler;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class MultipartTierCleanupRoutingTest extends TestCase
{
    public function test_abort_uses_pinned_tier_and_preserves_same_path_in_new_default_tier(): void
    {
        $metadata = $this->metadata();
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'key', 'owner', 'HOT');
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $path = $this->samePathInBothTiers($metadata, $hot, $cold, $uploadId);
        $tiers = $this->tiers($hot, $cold);

        $response = (new AbortMultipartUploadHandler($metadata, $cold, storageTiers: $tiers))
            ->handleRequest($this->request('DELETE', '/bucket/key?uploadId=' . $uploadId, 'key'));

        self::assertSame(204, $response->getStatus());
        self::assertNull($metadata->getMultipartUpload($uploadId));
        self::assertSame('cold sentinel', buffer($cold->getObjectByPath($path)));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
        try {
            $hot->getObjectByPath($path);
            self::fail('Abort left the pinned HOT part behind.');
        } catch (NoSuchKeyException) {
        }
    }

    public function test_lifecycle_abort_uses_pinned_tier_after_default_changes(): void
    {
        $metadata = $this->metadata();
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'key', 'owner', 'HOT');
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $path = $this->samePathInBothTiers($metadata, $hot, $cold, $uploadId);
        $executor = new LifecycleExecutor($metadata, $cold, storageTiers: $this->tiers($hot, $cold));
        $abort = (new \ReflectionClass(LifecycleExecutor::class))->getMethod('abortMultipartUploadDurably');

        self::assertTrue($abort->invoke($executor, 'bucket', 'key', $uploadId));
        self::assertNull($metadata->getMultipartUpload($uploadId));
        self::assertSame('cold sentinel', buffer($cold->getObjectByPath($path)));
        try {
            $hot->getObjectByPath($path);
            self::fail('Lifecycle left the pinned HOT part behind.');
        } catch (NoSuchKeyException) {
        }
    }

    public function test_delete_bucket_inline_uses_each_upload_tier(): void
    {
        $metadata = $this->metadata();
        $hotUploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($hotUploadId, 'bucket', 'key', 'owner', 'HOT');
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $path = $this->samePathInBothTiers($metadata, $hot, $cold, $hotUploadId);
        $coldUploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($coldUploadId, 'bucket', 'cold-key', 'owner', 'COLD');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $coldPath = $cold->planMultipartPartPath('bucket', $generation, $coldUploadId, 1, bin2hex(random_bytes(16)));
        $coldPart = $cold->putPartAtPath('bucket', 'cold-key', $coldUploadId, 1, new ReadableBuffer('cold bytes'), $coldPath);
        $hot->putPartAtPath('bucket', 'cold-key', $coldUploadId, 1, new ReadableBuffer('hot sentinel'), $coldPath);
        $metadata->putPart($coldUploadId, 1, $coldPart->md5Hex, $coldPart->size, $coldPath);

        $response = (new DeleteBucketHandler($metadata, $cold, $this->tiers($hot, $cold)))
            ->handleRequest($this->request('DELETE', '/bucket'));

        self::assertSame(204, $response->getStatus());
        self::assertNull($metadata->getBucket('bucket'));
        self::assertSame('cold sentinel', buffer($cold->getObjectByPath($path)));
        self::assertSame('hot sentinel', buffer($hot->getObjectByPath($coldPath)));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
        try {
            $hot->getObjectByPath($path);
            self::fail('DeleteBucket left the pinned HOT part behind.');
        } catch (NoSuchKeyException) {
        }
        try {
            $cold->getObjectByPath($coldPath);
            self::fail('DeleteBucket left the pinned COLD part behind.');
        } catch (NoSuchKeyException) {
        }
    }

    public function test_bulk_delete_bucket_stages_per_upload_tier(): void
    {
        $metadata = $this->metadata();
        for ($index = 0; $index < 257; $index++) {
            $uploadId = sprintf('%032x', $index + 1);
            $metadata->createMultipartUploadWithTier(
                $uploadId,
                'bucket',
                sprintf('key-%03d', $index),
                'owner',
                $index === 0 ? 'HOT' : 'COLD',
            );
            $metadata->putPart($uploadId, 1, 'etag', 1, 'parts/' . $uploadId . '/1');
        }
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();

        $response = (new DeleteBucketHandler($metadata, $cold, $this->tiers($hot, $cold)))
            ->handleRequest($this->request('DELETE', '/bucket'));

        self::assertSame(204, $response->getStatus());
        self::assertNull($metadata->getBucket('bucket'));
        $jobs = $metadata->dequeueStorageGarbage(300);
        self::assertCount(257, $jobs);
        self::assertSame('HOT', $jobs[0]['storage_tier']);
        self::assertSame('COLD', $jobs[256]['storage_tier']);
    }

    public function test_abort_keeps_failed_exact_delete_queued_on_pinned_tier(): void
    {
        $metadata = $this->metadata();
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'key', 'owner', 'HOT');
        $metadata->putPart($uploadId, 1, 'etag', 1, 'hot-part-path');
        $hot = $this->createMock(StorageBackend::class);
        $hot->expects(self::once())->method('deleteObjectByPath')
            ->with('hot-part-path', 'bucket')
            ->willThrowException(new \RuntimeException('Injected physical delete failure.'));
        $cold = new InMemoryBackend();
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $hot),
            new StorageTier('COLD', $cold, defaultWriteTier: true),
        ]);

        $response = (new AbortMultipartUploadHandler($metadata, $cold, storageTiers: $tiers))
            ->handleRequest($this->request('DELETE', '/bucket/key?uploadId=' . $uploadId, 'key'));

        self::assertSame(204, $response->getStatus());
        $jobs = $metadata->dequeueStorageGarbage(1);
        self::assertCount(1, $jobs);
        self::assertSame('delete_path', $jobs[0]['operation']);
        self::assertSame('HOT', $jobs[0]['storage_tier']);
        self::assertSame('hot-part-path', $jobs[0]['storage_path']);
    }

    public function test_unpinned_legacy_upload_cannot_be_aborted_or_delete_its_bucket(): void
    {
        $metadata = $this->metadata();
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'key', 'owner', 'HOT');
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("UPDATE s3_multipart_uploads SET storage_tier = NULL WHERE upload_id = '{$uploadId}'");
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $tiers = $this->tiers($hot, $cold);

        try {
            (new AbortMultipartUploadHandler($metadata, $cold, storageTiers: $tiers))
                ->handleRequest($this->request('DELETE', '/bucket/key?uploadId=' . $uploadId, 'key'));
            self::fail('Unpinned upload must not be aborted against a guessed tier.');
        } catch (InternalErrorException) {
        }
        try {
            (new DeleteBucketHandler($metadata, $cold, $tiers))
                ->handleRequest($this->request('DELETE', '/bucket'));
            self::fail('Unpinned upload must not be deleted with its bucket.');
        } catch (InternalErrorException) {
        }
        self::assertNotNull($metadata->getMultipartUpload($uploadId));
        self::assertNotNull($metadata->getBucket('bucket'));
    }

    public function test_cleanup_stage_rejects_a_tier_different_from_the_pinned_upload(): void
    {
        $metadata = $this->metadata();
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'key', 'owner', 'HOT');
        $metadata->putPart($uploadId, 1, 'etag', 1, 'hot-part-path');

        try {
            $metadata->transaction(function () use ($metadata, $uploadId): void {
                $metadata->lockOwnerForUpdate('owner');
                MultipartCleanup::stage($metadata, 'bucket', 'key', $uploadId, 'owner', 'COLD');
            });
            self::fail('A cleanup plan must not reassign the physical tier.');
        } catch (InternalErrorException) {
        }

        self::assertNotNull($metadata->getMultipartUpload($uploadId));
        self::assertCount(1, $metadata->getParts($uploadId));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    private function metadata(): SqliteMetadataStore&MultipartUploadTierStore
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');

        return $metadata;
    }

    private function tiers(InMemoryBackend $hot, InMemoryBackend $cold): StorageTierRegistry
    {
        return new StorageTierRegistry([
            new StorageTier('HOT', $hot),
            new StorageTier('COLD', $cold, defaultWriteTier: true),
        ]);
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $path, ?string $key = null): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1' . $path));
        $request->setAttribute('s3.bucket', 'bucket');
        if ($key !== null) {
            $request->setAttribute('s3.key', $key);
        }
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    private function samePathInBothTiers(
        SqliteMetadataStore $metadata,
        InMemoryBackend $hot,
        InMemoryBackend $cold,
        string $uploadId,
    ): string {
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $path = $hot->planMultipartPartPath('bucket', $generation, $uploadId, 1, bin2hex(random_bytes(16)));
        $part = $hot->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('hot bytes'), $path);
        $cold->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('cold sentinel'), $path);
        $metadata->putPart($uploadId, 1, $part->md5Hex, $part->size, $path);

        return $path;
    }
}
