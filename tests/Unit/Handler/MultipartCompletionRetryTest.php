<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidPartException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\MultipartPartWriteCapabilities;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Runtime\BufferedWorkLimiter;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartPartBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartCompletionRetryTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    private EncryptionService $encryption;

    /** @return iterable<string, array{bool}> */
    public static function completionVersioningModes(): iterable
    {
        yield 'unversioned' => [false];
        yield 'versioned' => [true];
    }

    public function test_replaced_object_and_restored_copy_cleanup_survive_lost_complete_acknowledgement(): void
    {
        $old = $this->storage->putObject('bucket', 'retry.bin', new \Amp\ByteStream\ReadableBuffer('old object'));
        $this->metadata->putObjectMetadata('bucket', 'retry.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'text/plain', $old->path);
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $restored = $hot->putObject('bucket', 'retry.bin', new \Amp\ByteStream\ReadableBuffer('restored copy'));
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'retry.bin', null, 'restored', $restored->path, new \DateTimeImmutable('+1 day'), 'HOT');
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->storage, defaultWriteTier: true),
            new StorageTier('HOT', $hot),
        ]);
        $created = (new CreateMultipartUploadHandler($this->metadata, storageTiers: $tiers))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage, storageTiers: $tiers))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'new object'));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        try {
            (new CompleteMultipartUploadHandler($this->metadataWithLostCompletionAck(), $this->storage, storageTiers: $tiers))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('The simulated completion acknowledgment must be lost.');
        } catch (\RuntimeException $error) {
            self::assertSame('lost completion acknowledgement', $error->getMessage());
        }

        $current = $this->metadata->getObjectMetadata('bucket', 'retry.bin');
        self::assertNotNull($current);
        self::assertNotSame($old->path, $current->systemMetadata['storagePath']);
        $jobs = $this->metadata->dequeueStorageGarbage(10);
        $staged = array_map(static fn(array $job): array => [$job['storage_tier'], $job['storage_path']], $jobs);
        self::assertContains(['STANDARD', $old->path], $staged);
        self::assertContains(['HOT', $restored->path], $staged);
        self::assertSame('old object', \Amp\ByteStream\buffer($this->storage->getObjectByPath($old->path)));
        self::assertSame('restored copy', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));
    }

    public function test_replaced_object_cleanup_discards_only_successfully_deleted_paths(): void
    {
        $old = $this->storage->putObject('bucket', 'retry.bin', new \Amp\ByteStream\ReadableBuffer('old object'));
        $this->metadata->putObjectMetadata('bucket', 'retry.bin', 'owner', $old->size, '"' . $old->md5Hex . '"', 'text/plain', $old->path);
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $restored = $hot->putObject('bucket', 'retry.bin', new \Amp\ByteStream\ReadableBuffer('restored copy'));
        $this->metadata->updateObjectRestoreStateWithTier('bucket', 'retry.bin', null, 'restored', $restored->path, new \DateTimeImmutable('+1 day'), 'HOT');
        $failingHot = new CallbackStorageBackend($hot, beforeDelete: static function (string $path) use ($restored): void {
            if ($path === $restored->path) {
                throw new \RuntimeException('physical delete failed');
            }
        });
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->storage, defaultWriteTier: true),
            new StorageTier('HOT', $failingHot),
        ]);
        $created = (new CreateMultipartUploadHandler($this->metadata, storageTiers: $tiers))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage, storageTiers: $tiers))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'new object'));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage, storageTiers: $tiers))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))->getStatus());
        $jobs = $this->metadata->dequeueStorageGarbage(10);
        self::assertCount(1, $jobs);
        self::assertSame(['HOT', $restored->path], [$jobs[0]['storage_tier'], $jobs[0]['storage_path']]);
        self::assertSame('restored copy', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));
        self::assertStoragePathMissing($this->storage, $old->path);
    }

    #[DataProvider('completionVersioningModes')]
    public function test_plain_multipart_completion_rejects_bucket_default_before_assembly(bool $versioned): void
    {
        if ($versioned) {
            $this->metadata->setBucketVersioning('bucket', 'Enabled');
        }
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        $this->metadata->putBucketEncryption('bucket', 'AES256', null, false);

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('A plain upload completed after bucket SSE was enabled.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }
    }

    public function test_object_lock_default_change_after_initiation_rejects_completion_before_assembly(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectLockConfig('bucket', ['objectLockEnabled' => 'Enabled']);
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        $this->metadata->putObjectLockConfig('bucket', [
            'objectLockEnabled' => 'Enabled',
            'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1]],
        ]);

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('A multipart upload completed after its Object Lock checksum contract changed.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }
    }

    public function test_object_lock_default_change_rejects_part_copy_before_commit_and_cleans_written_part(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectLockConfig('bucket', ['objectLockEnabled' => 'Enabled']);
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            7,
            '"' . md5('payload') . '"',
            'application/octet-stream',
            $source->path,
        );
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $copyRequest = fn(): Request => $this->request(
            'PUT',
            '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
            headers: ['x-amz-copy-source' => '/bucket/source.bin'],
        );

        $writtenPath = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putPart')->willReturnCallback(function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $body) use (&$writtenPath): StorageWriteResult {
            $result = $this->storage->putPart($bucket, $key, $id, $number, $body);
            $writtenPath = $result->path;
            $this->metadata->putObjectLockConfig('bucket', [
                'objectLockEnabled' => 'Enabled',
                'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1]],
            ]);

            return $result;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($copyRequest());
            self::fail('Copied part committed after the Object Lock configuration changed during its write.');
        } catch (OperationAbortedException) {
            self::assertNotNull($writtenPath);
            self::assertStoragePathMissing($this->storage, $writtenPath);
            self::assertSame([], $this->metadata->getParts($uploadId));
        }

        $storageBeforeWrite = $this->createMock(StorageBackend::class);
        $storageBeforeWrite->expects(self::never())->method('putPart');
        $this->expectException(OperationAbortedException::class);
        (new UploadPartCopyHandler($this->metadata, $storageBeforeWrite))->handleRequest($copyRequest());
    }

    public function test_part_write_cancellation_is_not_replaced_when_durable_cleanup_cannot_be_queued(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->metadataWithCleanupFailures();
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('planMultipartPartPath')->willReturn('uncommitted-part');
        $storage->method('putPartAtPath')->willThrowException($cancellation);
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('delete failed'));

        try {
            (new UploadPartHandler($metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                'payload',
            ));
            self::fail('The part write cancellation must propagate.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_part_copy_cancellation_is_not_replaced_when_durable_cleanup_cannot_be_queued(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            7,
            '"' . md5('payload') . '"',
            'application/octet-stream',
            $source->path,
        );
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->metadataWithCleanupFailures();
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('planMultipartPartPath')->willReturn('uncommitted-copy-part');
        $storage->method('putPartAtPath')->willThrowException($cancellation);
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('delete failed'));

        try {
            (new UploadPartCopyHandler($metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/bucket/source.bin'],
            ));
            self::fail('The part copy cancellation must propagate.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_completion_cancellation_is_not_replaced_when_attempt_abandonment_fails(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->metadataWithCleanupFailures(failAbandon: true);
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->method('assembleMultipartUploadAtPath')->willThrowException($cancellation);

        try {
            (new CompleteMultipartUploadHandler($metadata, $storage))->handleRequest(
                $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body),
            );
            self::fail('The assembly cancellation must propagate.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_copy_metadata_cancellation_is_not_replaced_when_durable_cleanup_cannot_be_queued(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            7,
            '"' . md5('payload') . '"',
            'application/octet-stream',
            $source->path,
        );
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        foreach ((new \ReflectionClass(MetadataStore::class))->getMethods() as $method) {
            $name = $method->getName();
            if (in_array($name, ['transaction', 'enqueueStorageGarbage'], true)) {
                continue;
            }
            $metadata->method($name)->willReturnCallback(fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments));
        }
        $metadata->method('transaction')->willThrowException($cancellation);
        $metadata->method('enqueueStorageGarbage')->willThrowException(new \RuntimeException('cleanup queue failed'));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putObject')->willReturnCallback($this->storage->putObject(...));
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('delete failed'));
        $request = $this->request(
            'PUT',
            '/bucket/retry.bin',
            headers: ['x-amz-copy-source' => '/bucket/source.bin'],
        );
        $request->setAttribute('s3.operation', \OpsFour\S3Server\Routing\S3Operation::CopyObject);

        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($request);
            self::fail('The copy metadata cancellation must propagate.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    /** @param array<string, string> $userMetadata */
    #[DataProvider('legacyObjectLockMetadata')]
    public function test_legacy_locked_multipart_completion_is_rejected_before_assembly(array $userMetadata): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUpload(
            $uploadId,
            'bucket',
            'retry.bin',
            'owner',
            'application/octet-stream',
            $userMetadata,
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('assembleMultipartUpload');

        $this->expectException(OperationAbortedException::class);
        (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId),
        );
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function legacyObjectLockMetadata(): iterable
    {
        yield 'retention' => [[
            '__object-lock-mode' => 'GOVERNANCE',
            '__object-lock-retain-until-date' => '2099-01-01T00:00:00Z',
        ]];
        yield 'legal hold' => [['__object-lock-legal-hold' => 'ON']];
    }

    public function test_legacy_multipart_completion_is_rejected_when_default_retention_is_now_active(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUpload(
            $uploadId,
            'bucket',
            'retry.bin',
            'owner',
            'application/octet-stream',
            [],
        );
        $this->metadata->putObjectLockConfig('bucket', [
            'objectLockEnabled' => 'Enabled',
            'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1]],
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('assembleMultipartUpload');

        $this->expectException(OperationAbortedException::class);
        (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId),
        );
    }

    #[DataProvider('completionVersioningModes')]
    public function test_plain_multipart_completion_rejects_sse_activation_during_assembly_and_cleans_path(bool $versioned): void
    {
        if ($versioned) {
            $this->metadata->setBucketVersioning('bucket', 'Enabled');
        }
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $assemblyPath = null;
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->method('assembleMultipartUploadAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, array $parts, string $path) use (&$assemblyPath): StorageWriteResult {
            $result = $this->storage->assembleMultipartUploadAtPath($bucket, $key, $id, $parts, $path);
            $assemblyPath = $path;
            $this->metadata->putBucketEncryption('bucket', 'AES256', null, false);

            return $result;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));

        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('A plain upload committed after bucket SSE was enabled during assembly.');
        } catch (OperationAbortedException) {
            self::assertNotNull($assemblyPath);
            self::assertStoragePathMissing($this->storage, $assemblyPath);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }
    }

    public function test_plain_part_upload_rejects_active_default_before_storage_and_during_write(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $partRequest = fn(): Request => $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload');

        $writtenPath = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putPart')->willReturnCallback(function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $body) use (&$writtenPath): StorageWriteResult {
            $result = $this->storage->putPart($bucket, $key, $id, $number, $body);
            $writtenPath = $result->path;
            $this->metadata->putBucketEncryption('bucket', 'AES256', null, false);

            return $result;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        try {
            (new UploadPartHandler($this->metadata, $storage))->handleRequest($partRequest());
            self::fail('Plain part committed after default SSE was enabled during its write.');
        } catch (OperationAbortedException) {
            self::assertNotNull($writtenPath);
            self::assertStoragePathMissing($this->storage, $writtenPath);
            self::assertSame([], $this->metadata->getParts($uploadId));
        }

        $storageBeforeWrite = $this->createMock(StorageBackend::class);
        $storageBeforeWrite->expects(self::never())->method('putPart');
        $this->expectException(OperationAbortedException::class);
        (new UploadPartHandler($this->metadata, $storageBeforeWrite))->handleRequest($partRequest());
    }

    public function test_plain_part_copy_rejects_active_default_before_storage_and_during_write(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', 7, '"' . md5('payload') . '"', 'application/octet-stream', $source->path);
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $copyRequest = fn(): Request => $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, headers: [
            'x-amz-copy-source' => '/bucket/source.bin',
        ]);

        $writtenPath = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putPart')->willReturnCallback(function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $body) use (&$writtenPath): StorageWriteResult {
            $result = $this->storage->putPart($bucket, $key, $id, $number, $body);
            $writtenPath = $result->path;
            $this->metadata->putBucketEncryption('bucket', 'AES256', null, false);

            return $result;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($copyRequest());
            self::fail('Plain copied part committed after default SSE was enabled during its write.');
        } catch (OperationAbortedException) {
            self::assertNotNull($writtenPath);
            self::assertStoragePathMissing($this->storage, $writtenPath);
            self::assertSame([], $this->metadata->getParts($uploadId));
        }

        $storageBeforeWrite = $this->createMock(StorageBackend::class);
        $storageBeforeWrite->expects(self::never())->method('putPart');
        $this->expectException(OperationAbortedException::class);
        (new UploadPartCopyHandler($this->metadata, $storageBeforeWrite))->handleRequest($copyRequest());
    }

    public function test_repeated_bad_digest_sse_parts_do_not_exhaust_durable_intent_cap(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $handler = new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption);

        for ($attempt = 0; $attempt < 16; $attempt++) {
            try {
                $handler->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload', [
                    'x-amz-checksum-sha256' => base64_encode(str_repeat("\0", 32)),
                ]));
                self::fail('Incorrect checksum was accepted.');
            } catch (BadDigestException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
            }
        }

        $count = (new \PDO('sqlite:' . $this->databasePath))
            ->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $count);
        self::assertSame(0, (int) $count->fetchColumn());
        self::assertSame(200, $handler->handleRequest($this->request(
            'PUT',
            '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
            'payload',
        ))->getStatus());
    }

    public function test_sse_part_rejects_bad_logical_checksum_before_planning_any_physical_write(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->expects(self::never())->method('planMultipartPartPath');
        $storage->expects(self::never())->method('putPartAtPath');

        try {
            (new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload', [
                    'x-amz-checksum-sha256' => base64_encode(str_repeat("\0", 32)),
                ]));
            self::fail('Invalid logical SSE checksum was accepted.');
        } catch (BadDigestException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_multiple_part_checksums_reject_before_planning_any_physical_write(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->expects(self::never())->method('planMultipartPartPath');
        $storage->expects(self::never())->method('putPartAtPath');

        try {
            (new UploadPartHandler($this->metadata, $storage))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload', [
                    'x-amz-checksum-sha256' => '',
                    'x-amz-checksum-crc32' => base64_encode(hash('crc32b', 'payload', true)),
                ]));
            self::fail('Multiple part checksums were accepted.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_new_sse_multipart_stores_authenticated_ciphertext_and_completes_without_assembly(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $upload = $this->metadata->getMultipartUpload($uploadId);
        self::assertSame(EncryptedMultipartPartCodec::FORMAT, $upload['user_metadata']['__mpu-part-format'] ?? null);

        $part = (new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'private payload'));
        $etag = $part->getHeader('ETag');
        self::assertSame('"' . md5('private payload') . '"', $etag);
        $parts = $this->metadata->getParts($uploadId);
        self::assertSame(15, $parts[0]['size']);
        $stored = \Amp\ByteStream\buffer($this->storage->getObjectByPath($parts[0]['storage_path']));
        self::assertStringStartsWith("S3MPU2\0", $stored);
        self::assertStringNotContainsString('private payload', $stored);

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putObjectAtPath')->willReturnCallback($this->storage->putObjectAtPath(...));
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->method('abortMultipartUpload')->willReturnCallback($this->storage->abortMultipartUpload(...));

        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars((string) $etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $storage, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'retry.bin');
        self::assertNotNull($object);
        self::assertArrayNotHasKey('__mpu-part-format', $object->userMetadata);
        self::assertSame('private payload', \Amp\ByteStream\buffer((new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody()));
    }

    public function test_new_sse_multipart_copy_part_stores_ciphertext(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            12,
            '"' . md5('copy payload') . '"',
            'application/octet-stream',
            $source->path,
        );
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        self::assertSame(200, (new UploadPartCopyHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, headers: [
                'x-amz-copy-source' => '/bucket/source.bin',
            ]))->getStatus());
        $parts = $this->metadata->getParts($uploadId);
        self::assertSame('"' . md5('copy payload') . '"', $parts[0]['etag']);
        $stored = \Amp\ByteStream\buffer($this->storage->getObjectByPath($parts[0]['storage_path']));
        self::assertStringStartsWith("S3MPU2\0", $stored);
        self::assertStringNotContainsString('copy payload', $stored);
    }

    public function test_sse_part_copy_rejects_corrupt_source_checksum_before_destination_write(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            12,
            '"' . md5('copy payload') . '"',
            'application/octet-stream',
            $source->path,
            checksumSha256: base64_encode(str_repeat("\0", 32)),
        );
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->expects(self::never())->method('planMultipartPartPath');
        $storage->expects(self::never())->method('putPartAtPath');

        try {
            (new UploadPartCopyHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, headers: [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
            self::fail('Corrupt copy source checksum was accepted.');
        } catch (InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_new_sse_completion_rejects_swapped_part_ciphertext_and_remains_retryable(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $partHandler = new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption);
        $partOne = $partHandler->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'first'));
        $partHandler->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=2&uploadId=' . $uploadId, 'second'));
        $parts = $this->metadata->getParts($uploadId);
        $update = (new \PDO('sqlite:' . $this->databasePath))
            ->prepare('UPDATE s3_parts SET storage_path = ? WHERE upload_id = ? AND part_number = 1');
        self::assertInstanceOf(\PDOStatement::class, $update);
        $update->execute([$parts[1]['storage_path'], $uploadId]);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars((string) $partOne->getHeader('ETag'), ENT_XML1)
            . '</ETag></Part></CompleteMultipartUpload>';

        try {
            (new CompleteMultipartUploadHandler($this->metadata, $this->storage, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('A ciphertext part from another part number was accepted.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
        }

        $update->execute([$parts[0]['storage_path'], $uploadId]);
        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))->getStatus());
    }

    public function test_encrypted_part_short_write_result_does_not_delete_an_unplanned_foreign_path(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $foreign = $this->storage->putObject('bucket', 'foreign.bin', new \Amp\ByteStream\ReadableBuffer('keep me'));
        $actualPath = null;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('planMultipartPartPath')->willReturnCallback($this->storage->planMultipartPartPath(...));
        $storage->method('putPartAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $data, string $path) use ($foreign, &$actualPath): StorageWriteResult {
            $actual = $this->storage->putPartAtPath($bucket, $key, $id, $number, $data, $path);
            $actualPath = $actual->path;

            return new StorageWriteResult($foreign->path, $actual->size - 1, $actual->md5Hex);
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));

        try {
            (new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'secret'));
            self::fail('A short encrypted storage write result was accepted.');
        } catch (InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
            self::assertSame('keep me', \Amp\ByteStream\buffer($this->storage->getObjectByPath($foreign->path)));
            self::assertNotNull($actualPath);
            self::assertStoragePathMissing($this->storage, $actualPath);
            $count = (new \PDO('sqlite:' . $this->databasePath))
                ->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $count);
            self::assertSame(0, (int) $count->fetchColumn());
        }
    }

    public function test_successful_encrypted_part_write_rejected_at_commit_retires_intent_after_delete(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $writtenPath = null;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('planMultipartPartPath')->willReturnCallback($this->storage->planMultipartPartPath(...));
        $storage->method('putPartAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $data, string $path) use ($uploadId, &$writtenPath): StorageWriteResult {
            $result = $this->storage->putPartAtPath($bucket, $key, $id, $number, $data, $path);
            $writtenPath = $path;
            $this->metadata->deleteMultipartUpload($uploadId);

            return $result;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));

        try {
            (new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'secret'));
            self::fail('Aborted upload accepted a late part.');
        } catch (\OpsFour\S3Server\Exception\NoSuchUploadException) {
            self::assertNotNull($writtenPath);
            self::assertStoragePathMissing($this->storage, $writtenPath);
            $count = (new \PDO('sqlite:' . $this->databasePath))
                ->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $count);
            self::assertSame(0, (int) $count->fetchColumn());
        }
    }

    public function test_encrypted_part_enforces_memory_size_cap_before_storage_write(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->expects(self::never())->method('putPartAtPath');

        $this->expectException(EntityTooLargeException::class);
        (new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption, maxEncryptedObjectSize: 5))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'secret'));
    }

    public function test_custom_storage_without_planned_part_contract_rejects_sse_initiation(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');

        $this->expectException(\OpsFour\S3Server\Exception\NotImplementedException::class);
        (new CreateMultipartUploadHandler($this->metadata, $this->encryption, storage: $storage))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: [
                'x-amz-server-side-encryption' => 'AES256',
            ]));
    }

    public function test_cached_custom_metadata_without_part_intents_rejects_sse_initiation(): void
    {
        $custom = $this->createMock(MetadataStore::class);
        $custom->method('getBucket')->willReturnCallback($this->metadata->getBucket(...));
        $custom->method('getBucketEncryption')->willReturnCallback($this->metadata->getBucketEncryption(...));
        $custom->expects(self::never())->method('createMultipartUpload');

        $this->expectException(\OpsFour\S3Server\Exception\NotImplementedException::class);
        (new CreateMultipartUploadHandler(
            new CachedMetadataStoreDecorator($custom),
            $this->encryption,
            storage: $this->storage,
        ))->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: [
            'x-amz-server-side-encryption' => 'AES256',
        ]));
    }

    public function test_encrypted_part_handlers_reject_nested_observed_storage_without_planned_part_capability(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: [
                'x-amz-server-side-encryption' => 'AES256',
            ]));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $metrics = new MetricsCollector();
        $storage = new ObservedStorageBackend(new ObservedStorageBackend(new CallbackStorageBackend($this->storage), $metrics, 'inner'), $metrics, 'outer');

        try {
            (new CreateMultipartUploadHandler($this->metadata, $this->encryption, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: [
                    'x-amz-server-side-encryption' => 'AES256',
                ]));
            self::fail('Encrypted upload initiation requires a real planned-part capability.');
        } catch (\OpsFour\S3Server\Exception\NotImplementedException) {
            // A previously created upload can still reach a node with reduced capabilities.
        }

        foreach ([
            new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption),
            new UploadPartCopyHandler($this->metadata, $storage, encryption: $this->encryption),
        ] as $handler) {
            try {
                $handler->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'secret', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
                self::fail('Encrypted parts require a real planned-part capability.');
            } catch (InternalErrorException $error) {
                self::assertStringContainsString('durable planned-path backends', $error->getMessage());
            }
        }

        self::assertSame([], $this->metadata->getParts($uploadId));
    }

    public function test_encrypted_part_etag_and_size_describe_plaintext(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'secret'));
        self::assertSame('"' . md5('secret') . '"', $part->getHeader('ETag'));
        self::assertNull($part->getHeader('x-amz-checksum-sha256'));
        self::assertSame(6, $this->metadata->getParts($uploadId)[0]['size']);
    }

    public function test_abort_wakes_active_completion_intent_for_immediate_cleanup(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $bucketInfo = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucketInfo);
        $attemptId = bin2hex(random_bytes(16));
        $paths = $this->storage->planMultipartCompletionPaths('bucket', 'retry.bin', $bucketInfo->generation, $attemptId);
        $intent = new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: $uploadId,
            bucket: 'bucket',
            bucketGeneration: $bucketInfo->generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            assemblyPath: $paths['assembly'],
            finalPath: $paths['final'],
            partDigest: str_repeat('a', 64),
            sseMode: null,
            leaseUntil: microtime(true) + 43_200,
            nextAttemptAt: microtime(true) + 43_200,
        );
        $this->metadata->createMultipartWriteIntent($intent);
        $this->storage->putObjectAtPath('bucket', 'retry.bin', new \Amp\ByteStream\ReadableBuffer('uncommitted'), $paths['assembly']);

        $aborted = (new AbortMultipartUploadHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('DELETE', '/bucket/retry.bin?uploadId=' . $uploadId));
        self::assertSame(204, $aborted->getStatus());
        self::assertNull($this->metadata->getMultipartUpload($uploadId));
        self::assertSame('abandoned', $this->metadata->getMultipartWriteIntent($attemptId)?->state);
        self::assertFalse($this->metadata->consumeMultipartWriteIntent($attemptId));

        $collector = new MultipartWriteIntentCollector($this->metadata, StorageTierRegistry::single($this->storage));
        self::assertSame(1, $collector->collect());
        self::assertStoragePathMissing($this->storage, $paths['assembly']);
    }

    public function test_malformed_part_number_does_not_write_a_part(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $path = '/bucket/retry.bin?partNumber=1junk&uploadId=' . $uploadId;

        foreach ([
            new UploadPartHandler($this->metadata, $this->storage),
            new UploadPartCopyHandler($this->metadata, $this->storage),
        ] as $handler) {
            try {
                $handler->handleRequest($this->request('PUT', $path, 'part'));
                self::fail('Malformed partNumber must be rejected.');
            } catch (InvalidArgumentException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
            }
        }
    }

    public function test_upload_part_rejects_oversized_or_malformed_length_before_storage_write(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $handler = new UploadPartHandler($this->metadata, $storage);
        $path = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;

        foreach ([
            ['content-length' => '5368709121'],
            ['content-length' => '3.0'],
            ['x-amz-content-sha256' => 'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
                'x-amz-decoded-content-length' => '5368709121'],
        ] as $headers) {
            try {
                $handler->handleRequest($this->request('PUT', $path, 'abc', $headers));
                self::fail('An invalid multipart length must be rejected before a storage write.');
            } catch (\OpsFour\S3Server\Exception\EntityTooLargeException|InvalidArgumentException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
            }
        }
    }

    public function test_upload_part_copy_rejects_oversized_full_source_and_range_before_storage_read(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            5_368_709_122,
            '"' . md5('abc') . '"',
            'application/octet-stream',
            'unread-oversized-source',
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $storage->expects(self::never())->method('putPart');
        $handler = new UploadPartCopyHandler($this->metadata, $storage);
        $path = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;

        foreach ([null, 'bytes=0-5368709120'] as $range) {
            $headers = ['x-amz-copy-source' => '/bucket/source.bin'];
            if ($range !== null) {
                $headers['x-amz-copy-source-range'] = $range;
            }
            try {
                $handler->handleRequest($this->request('PUT', $path, '', $headers));
                self::fail('An oversized copy part must fail before reading the source.');
            } catch (\OpsFour\S3Server\Exception\EntityTooLargeException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
            }
        }
    }

    public function test_upload_part_copy_rejects_repeated_source_and_range_headers(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('source'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            $source->size,
            '"' . $source->md5Hex . '"',
            'application/octet-stream',
            $source->path,
        );
        $path = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;

        foreach ([
            ['x-amz-copy-source' => ['/bucket/source.bin', '/bucket/other.bin']],
            [
                'x-amz-copy-source' => '/bucket/source.bin',
                'x-amz-copy-source-range' => ['bytes=0-1', 'bytes=2-3'],
            ],
        ] as $headers) {
            try {
                (new UploadPartCopyHandler($this->metadata, $this->storage))
                    ->handleRequest($this->request('PUT', $path, '', $headers));
                self::fail('Repeated singleton copy headers must be rejected.');
            } catch (InvalidArgumentException $error) {
                self::assertStringContainsString('must be supplied at most once', $error->getMessage());
                self::assertSame([], $this->metadata->getParts($uploadId));
            }
        }
    }

    public function test_copy_object_rejects_source_over_five_gib_before_storage_read(): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            5_368_709_121,
            '"' . md5('abc') . '"',
            'application/octet-stream',
            'unread-oversized-source',
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $storage->expects(self::never())->method('putObject');

        try {
            (new CopyObjectHandler($this->metadata, $storage))
                ->handleRequest($this->request('PUT', '/bucket/copy.bin', '', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
            self::fail('CopyObject must reject a source larger than five GiB before storage I/O.');
        } catch (EntityTooLargeException $error) {
            self::assertSame('EntityTooLarge', $error->getErrorCode());
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'copy.bin'));
        }
    }

    public function test_upload_part_cleans_oversized_storage_result_before_part_metadata_commit(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putPart')->willReturn(new StorageWriteResult(
            'oversized-part-path',
            5_368_709_121,
            md5('abc'),
        ));
        $storage->expects(self::once())->method('deleteObjectByPath')
            ->with('oversized-part-path', 'bucket');

        try {
            (new UploadPartHandler($this->metadata, $storage))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'abc'));
            self::fail('An oversized physical part must not commit metadata.');
        } catch (\OpsFour\S3Server\Exception\EntityTooLargeException $e) {
            self::assertSame('EntityTooLarge', $e->getErrorCode());
            self::assertSame(400, $e->getHttpStatus());
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    #[DataProvider('invalidMultipartPartStorageResults')]
    public function test_upload_part_rejects_invalid_backend_metadata_before_part_commit(
        string $path,
        int $size,
        string $md5,
        bool $cleanupExpected,
    ): void {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putPart')->willReturn(new StorageWriteResult(
            $path,
            $size,
            $md5,
        ));
        $delete = $storage->expects($cleanupExpected ? self::once() : self::never())->method('deleteObjectByPath');
        if ($cleanupExpected) {
            $delete->with($path, 'bucket');
        }

        try {
            (new UploadPartHandler($this->metadata, $storage))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'abc'));
            self::fail('Invalid physical part metadata must not be committed.');
        } catch (InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    /** @return iterable<string, array{string, int, string, bool}> */
    public static function invalidMultipartPartStorageResults(): iterable
    {
        yield 'negative size' => ['invalid-part-path', -1, md5('abc'), true];
        yield 'malformed md5' => ['invalid-part-path', 3, 'not-an-md5', true];
        yield 'empty path' => ['', 3, md5('abc'), false];
    }

    public function test_upload_part_normalizes_valid_uppercase_backend_md5(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putPart')->willReturn(new StorageWriteResult(
            'uppercase-part-path',
            3,
            strtoupper(md5('abc')),
        ));
        $storage->expects(self::never())->method('deleteObjectByPath');

        $response = (new UploadPartHandler($this->metadata, $storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'abc'));

        self::assertSame('"' . md5('abc') . '"', $response->getHeader('etag'));
        self::assertSame('"' . md5('abc') . '"', $this->metadata->getParts($uploadId)[0]['etag']);
    }

    public function test_upload_part_copy_rejects_invalid_backend_md5_before_part_commit(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            3,
            '"' . md5('source-multipart') . '-2"',
            'application/octet-stream',
            'source-path',
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('source-path', null, null)
            ->willReturn(new \Amp\ByteStream\ReadableBuffer('abc'));
        $storage->expects(self::once())->method('putPart')->willReturn(new StorageWriteResult(
            'invalid-copy-part-path',
            3,
            'not-an-md5',
        ));
        $storage->expects(self::once())->method('deleteObjectByPath')
            ->with('invalid-copy-part-path', 'bucket');

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/bucket/source.bin'],
            ));
            self::fail('Invalid copied-part MD5 metadata must not be committed.');
        } catch (InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_plain_part_timeout_retains_planned_path_for_late_write_recovery(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $plannedPath = null;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->expects(self::once())->method('planMultipartPartPath')
            ->willReturnCallback($this->storage->planMultipartPartPath(...));
        $storage->expects(self::never())->method('putPart');
        $storage->expects(self::once())->method('putPartAtPath')
            ->willReturnCallback(static function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $body, string $path) use (&$plannedPath): never {
                $plannedPath = $path;
                throw new \RuntimeException('Injected worker timeout.');
            });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new UploadPartHandler($this->metadata, $storage))->handleRequest(
                $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'plain'),
            );
            self::fail('Timed-out part write must fail the request.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected worker timeout.', $error->getMessage());
        }

        self::assertNotNull($plannedPath);
        self::assertSame([], $this->metadata->getParts($uploadId));
        $db = new \PDO('sqlite:' . $this->databasePath);
        $intent = $db->prepare("SELECT COUNT(*) FROM s3_storage_garbage WHERE storage_path = ? AND operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $intent);
        $intent->execute([$plannedPath]);
        self::assertSame(1, (int) $intent->fetchColumn());

        // A worker can publish after its caller times out. The existing
        // collector must find the registered exact path and remove it.
        $this->storage->putPartAtPath('bucket', 'retry.bin', $uploadId, 1, new \Amp\ByteStream\ReadableBuffer('late plain'), $plannedPath);
        $db->prepare('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = ?')->execute([$plannedPath]);
        self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect());
        $this->assertStoragePathMissing($this->storage, $plannedPath);
        $intent->execute([$plannedPath]);
        self::assertSame(1, (int) $intent->fetchColumn());
    }

    public function test_plain_part_uses_planned_path_and_consumes_intent_after_commit(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $response = (new UploadPartHandler($this->metadata, $this->storage))->handleRequest(
            $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'plain'),
        );

        self::assertSame(200, $response->getStatus());
        self::assertSame('"' . md5('plain') . '"', $response->getHeader('ETag'));
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);
        $generation = $this->metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        self::assertMatchesRegularExpression(
            '~^memory://\.parts/' . $uploadId . '/1-' . $generation . '-[a-f0-9]{32}$~D',
            $parts[0]['storage_path'],
        );
        self::assertSame('plain', \Amp\ByteStream\buffer($this->storage->getObjectByPath($parts[0]['storage_path'])));
        $intent = (new \PDO('sqlite:' . $this->databasePath))
            ->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $intent);
        self::assertSame(0, (int) $intent->fetchColumn());
    }

    public function test_local_worker_plain_part_validates_before_arm_and_commits_exact_dispatch(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer): void {
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $handler = new UploadPartHandler($this->metadata, $storage, localPartWriter: $writer);
            $uri = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;

            try {
                $handler->handleRequest($this->request('PUT', $uri, 'payload', ['content-length' => '8']));
                self::fail('Wrong logical part length was accepted before local worker dispatch.');
            } catch (\OpsFour\S3Server\Exception\IncompleteBodyException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
                self::assertSame(0, $this->partWriteIntentCount());
            }

            try {
                $handler->handleRequest($this->request('PUT', $uri, 'payload', [
                    'x-amz-checksum-sha256' => base64_encode(str_repeat("\0", 32)),
                ]));
                self::fail('Bad checksum was accepted before local worker dispatch.');
            } catch (BadDigestException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
                self::assertSame(0, $this->partWriteIntentCount());
            }

            $response = $handler->handleRequest($this->request('PUT', $uri, 'payload'));
            self::assertSame(200, $response->getStatus());
            self::assertSame('"' . md5('payload') . '"', $response->getHeader('ETag'));
            self::assertNull($response->getHeader('x-amz-checksum-sha256'));
            $parts = $this->metadata->getParts($uploadId);
            self::assertCount(1, $parts);
            self::assertSame('payload', file_get_contents($parts[0]['storage_path']));
            self::assertSame(0, $this->partWriteIntentCount());
            self::assertSame(0, fileperms($storage->getBasePath() . '/.tmp') & 0077);
            self::assertSame(0, fileperms($storage->getBasePath() . '/.parts') & 0077);
        });
    }

    public function test_required_local_completion_reads_historical_double_slash_part_path(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $_writer): void {
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $part = $storage->putPart('bucket', 'retry.bin', $uploadId, 1, new \Amp\ByteStream\ReadableBuffer('legacy part'));
            $legacyPath = $storage->getBasePath() . '/' . substr($part->path, strlen($storage->getBasePath()));
            $this->metadata->putPart($uploadId, 1, '"' . $part->md5Hex . '"', $part->size, $legacyPath);
            self::assertSame($legacyPath, $this->metadata->getParts($uploadId)[0]['storage_path']);
            $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"'
                . $part->md5Hex . '"</ETag></Part></CompleteMultipartUpload>';

            $response = (new CompleteMultipartUploadHandler($this->metadata, $storage, requireLocalFencedCompletion: true))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));

            self::assertSame(200, $response->getStatus());
            self::assertNull($this->metadata->getMultipartUpload($uploadId));
            self::assertSame('legacy part', \Amp\ByteStream\buffer(
                (new GetObjectHandler($this->metadata, $storage))->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody(),
            ));
        });
    }

    public function test_required_local_completion_keeps_upload_when_legacy_part_has_three_root_separators(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $_writer): void {
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $part = $storage->putPart('bucket', 'retry.bin', $uploadId, 1, new \Amp\ByteStream\ReadableBuffer('legacy part'));
            $tripleSlash = $storage->getBasePath() . '//' . substr($part->path, strlen($storage->getBasePath()));
            $this->metadata->putPart($uploadId, 1, '"' . $part->md5Hex . '"', $part->size, $tripleSlash);
            $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>"'
                . $part->md5Hex . '"</ETag></Part></CompleteMultipartUpload>';

            try {
                (new CompleteMultipartUploadHandler($this->metadata, $storage, requireLocalFencedCompletion: true))
                    ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
                self::fail('An unqualified historical path must not reach the assembly worker.');
            } catch (\InvalidArgumentException) {
                self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
                self::assertSame($tripleSlash, $this->metadata->getParts($uploadId)[0]['storage_path']);
                self::assertSame('legacy part', file_get_contents($part->path));
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            }
        });
    }

    public function test_local_worker_plain_copy_validates_source_before_arm_and_commits_exact_dispatch(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer): void {
            $bad = $storage->putObject('bucket', 'bad.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
            $this->metadata->putObjectMetadata(
                'bucket',
                'bad.bin',
                'owner',
                12,
                '"' . md5('copy payload') . '"',
                'application/octet-stream',
                $bad->path,
                checksumSha256: base64_encode(str_repeat("\0", 32)),
            );
            $good = $storage->putObject('bucket', 'good.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
            $this->metadata->putObjectMetadata(
                'bucket',
                'good.bin',
                'owner',
                12,
                '"' . md5('copy payload') . '"',
                'application/octet-stream',
                $good->path,
                checksumSha256: base64_encode(hash('sha256', 'copy payload', true)),
            );
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $handler = new UploadPartCopyHandler($this->metadata, $storage, localPartWriter: $writer);
            $uri = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;

            try {
                $handler->handleRequest($this->request('PUT', $uri, headers: ['x-amz-copy-source' => '/bucket/bad.bin']));
                self::fail('Corrupt source checksum was accepted before local worker dispatch.');
            } catch (InternalErrorException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
                self::assertSame(0, $this->partWriteIntentCount());
            }

            $response = $handler->handleRequest($this->request('PUT', $uri, headers: ['x-amz-copy-source' => '/bucket/good.bin']));
            self::assertSame(200, $response->getStatus());
            $parts = $this->metadata->getParts($uploadId);
            self::assertCount(1, $parts);
            self::assertSame('copy payload', file_get_contents($parts[0]['storage_path']));
            self::assertSame(0, $this->partWriteIntentCount());
            self::assertSame(0, fileperms($storage->getBasePath() . '/.tmp') & 0077);
            self::assertSame(0, fileperms($storage->getBasePath() . '/.parts') & 0077);
        });
    }

    public function test_local_worker_sse_s3_part_stages_only_ciphertext_and_commits_logical_metadata(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer): void {
            $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $handler = new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption, localPartWriter: $writer);
            $uri = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;

            foreach ([
                ['content-length' => '16'],
                ['x-amz-checksum-sha256' => base64_encode(str_repeat("\0", 32))],
            ] as $headers) {
                try {
                    $handler->handleRequest($this->request('PUT', $uri, 'private payload', $headers));
                    self::fail('Bad logical SSE part was accepted before worker dispatch.');
                } catch (\OpsFour\S3Server\Exception\S3Exception $error) {
                    self::assertContains($error->getErrorCode(), ['BadDigest', 'IncompleteBody']);
                    self::assertSame([], $this->metadata->getParts($uploadId));
                    self::assertSame(0, $this->partWriteIntentCount());
                }
            }

            $response = $handler->handleRequest($this->request('PUT', $uri, 'private payload'));
            self::assertSame(200, $response->getStatus());
            self::assertSame('"' . md5('private payload') . '"', $response->getHeader('ETag'));
            $part = $this->metadata->getParts($uploadId)[0];
            self::assertSame(15, $part['size']);
            $physical = file_get_contents($part['storage_path']);
            self::assertIsString($physical);
            self::assertStringStartsWith("S3MPU2\0", $physical);
            self::assertStringNotContainsString('private payload', $physical);
            self::assertSame(0, $this->partWriteIntentCount());
        });
    }

    public function test_local_worker_sse_c_part_stages_only_ciphertext(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer): void {
            $customerKey = random_bytes(32);
            $headers = [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
                'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
            ];
            $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: $headers));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $handler = new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption, localPartWriter: $writer);
            $uri = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;
            try {
                $handler->handleRequest($this->request('PUT', $uri, 'sse-c secret', $headers + [
                    'x-amz-checksum-sha256' => base64_encode(str_repeat("\0", 32)),
                ]));
                self::fail('Bad SSE-C part checksum was accepted before worker dispatch.');
            } catch (BadDigestException) {
                self::assertSame(0, $this->partWriteIntentCount());
            }

            $response = $handler->handleRequest($this->request('PUT', $uri, 'sse-c secret', $headers));
            self::assertSame(200, $response->getStatus());
            self::assertSame('"' . md5('sse-c secret') . '"', $response->getHeader('ETag'));
            self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption-customer-algorithm'));
            self::assertSame($headers['x-amz-server-side-encryption-customer-key-MD5'], $response->getHeader('x-amz-server-side-encryption-customer-key-MD5'));
            $part = $this->metadata->getParts($uploadId)[0];
            self::assertSame(12, $part['size']);
            $physical = file_get_contents($part['storage_path']);
            self::assertIsString($physical);
            self::assertStringStartsWith("S3MPU2\0", $physical);
            self::assertStringNotContainsString('sse-c secret', $physical);
            self::assertSame(0, $this->partWriteIntentCount());
        });
    }

    public function test_local_worker_sse_s3_copy_stages_ciphertext_and_checks_source_before_arm(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer): void {
            $badChecksum = $storage->putObject('bucket', 'bad-checksum.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
            $this->metadata->putObjectMetadata(
                'bucket',
                'bad-checksum.bin',
                'owner',
                12,
                '"' . md5('copy payload') . '"',
                'application/octet-stream',
                $badChecksum->path,
                checksumSha256: base64_encode(str_repeat("\0", 32)),
            );
            $badSize = $storage->putObject('bucket', 'bad-size.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
            $this->metadata->putObjectMetadata(
                'bucket',
                'bad-size.bin',
                'owner',
                11,
                '"' . md5('copy payload') . '"',
                'application/octet-stream',
                $badSize->path,
            );
            $source = $storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
            $this->metadata->putObjectMetadata(
                'bucket',
                'source.bin',
                'owner',
                12,
                '"' . md5('copy payload') . '"',
                'application/octet-stream',
                $source->path,
                checksumSha256: base64_encode(hash('sha256', 'copy payload', true)),
            );
            $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $handler = new UploadPartCopyHandler($this->metadata, $storage, $this->encryption, localPartWriter: $writer);
            foreach (['bad-checksum.bin', 'bad-size.bin'] as $sourceKey) {
                try {
                    $handler->handleRequest($this->request(
                        'PUT',
                        '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                        headers: ['x-amz-copy-source' => '/bucket/' . $sourceKey],
                    ));
                    self::fail('Bad SSE Copy source was accepted before local worker dispatch.');
                } catch (InternalErrorException) {
                    self::assertSame([], $this->metadata->getParts($uploadId));
                    self::assertSame(0, $this->partWriteIntentCount());
                }
            }
            $response = $handler
                ->handleRequest($this->request(
                    'PUT',
                    '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                    headers: ['x-amz-copy-source' => '/bucket/source.bin'],
                ));
            self::assertSame(200, $response->getStatus());
            self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption'));
            $part = $this->metadata->getParts($uploadId)[0];
            self::assertSame(12, $part['size']);
            self::assertSame('"' . md5('copy payload') . '"', $part['etag']);
            $physical = file_get_contents($part['storage_path']);
            self::assertIsString($physical);
            self::assertStringStartsWith("S3MPU2\0", $physical);
            self::assertStringNotContainsString('copy payload', $physical);
            self::assertSame(0, $this->partWriteIntentCount());
        });
    }

    public function test_local_worker_rejects_existing_public_temp_directory_without_chmod(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer): void {
            $temp = $storage->getBasePath() . '/.tmp';
            self::assertTrue(mkdir($temp, 0755));
            self::assertTrue(chmod($temp, 0755));
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;

            try {
                (new UploadPartHandler($this->metadata, $storage, localPartWriter: $writer))
                    ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
                self::fail('Existing public temp directory was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('private and process-owned', $error->getMessage());
                self::assertSame([], $this->metadata->getParts($uploadId));
                self::assertSame(0, $this->partWriteIntentCount());
                self::assertSame(0755, fileperms($temp) & 0777);
            }
        });
    }

    public function test_local_worker_part_commit_ack_loss_keeps_the_committed_reference(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $_writer): void {
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $metadata = $this->metadataWithLostPartCommitAck();
            $writer = new LocalFencedMultipartPartWriter(
                $storage->getBasePath(),
                dirname($storage->getBasePath()) . '/scratch',
                'STANDARD',
                $metadata,
            );
            try {
                (new UploadPartHandler($metadata, $storage, localPartWriter: $writer))
                    ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'committed'));
                self::fail('Lost part COMMIT acknowledgment was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('lost part commit acknowledgement', $error->getMessage());
                $parts = $this->metadata->getParts($uploadId);
                self::assertCount(1, $parts);
                self::assertSame('committed', file_get_contents($parts[0]['storage_path']));
                self::assertSame(0, $this->partWriteIntentCount());
            } finally {
                $writer->shutdown();
            }
        });
    }

    public function test_required_local_fence_rejects_plain_handlers_without_bound_writer(): void
    {
        $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $_writer): void {
            $created = (new CreateMultipartUploadHandler($this->metadata, storage: $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            foreach ([
                new UploadPartHandler($this->metadata, $storage, requireLocalFencedParts: true),
                new UploadPartCopyHandler($this->metadata, $storage, requireLocalFencedParts: true),
            ] as $handler) {
                try {
                    $handler->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'part'));
                    self::fail('A required local fence served without its bound writer.');
                } catch (InternalErrorException $error) {
                    self::assertStringContainsString('fenced filesystem', $error->getMessage());
                }
            }
            self::assertSame([], $this->metadata->getParts($uploadId));
            self::assertSame(0, $this->partWriteIntentCount());
        });
    }

    public function test_nested_observed_storage_without_planned_part_capability_uses_plain_upload_fallback(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $inner = new CallbackStorageBackend($this->storage);
        $metrics = new MetricsCollector();
        $storage = new ObservedStorageBackend(new ObservedStorageBackend($inner, $metrics, 'inner'), $metrics, 'outer');

        $response = (new UploadPartHandler($this->metadata, $storage))->handleRequest(
            $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'plain'),
        );

        self::assertSame(200, $response->getStatus());
        self::assertNotNull($inner->lastPartWrite);
        self::assertSame($inner->lastPartWrite->path, $this->metadata->getParts($uploadId)[0]['storage_path']);
        self::assertSame('plain', \Amp\ByteStream\buffer($this->storage->getObjectByPath($inner->lastPartWrite->path)));
    }

    public function test_nested_observed_storage_without_planned_part_capability_uses_copy_fallback(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('plain'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', 5, '"' . md5('plain') . '"', 'application/octet-stream', $source->path);
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $inner = new CallbackStorageBackend($this->storage);
        $metrics = new MetricsCollector();
        $storage = new ObservedStorageBackend(new ObservedStorageBackend($inner, $metrics, 'inner'), $metrics, 'outer');

        $response = (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($this->request(
            'PUT',
            '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
            headers: ['x-amz-copy-source' => '/bucket/source.bin'],
        ));

        self::assertSame(200, $response->getStatus());
        self::assertNotNull($inner->lastPartWrite);
        self::assertSame($inner->lastPartWrite->path, $this->metadata->getParts($uploadId)[0]['storage_path']);
        self::assertSame('plain', \Amp\ByteStream\buffer($this->storage->getObjectByPath($inner->lastPartWrite->path)));
    }

    public function test_planned_part_capability_requires_both_real_inner_capabilities(): void
    {
        $metrics = new MetricsCollector();
        $plannedStorage = new ObservedStorageBackend(new ObservedStorageBackend($this->storage, $metrics, 'inner'), $metrics, 'outer');
        $plainStorage = new ObservedStorageBackend(new ObservedStorageBackend(new CallbackStorageBackend($this->storage), $metrics, 'inner'), $metrics, 'outer');
        $plannedMetadata = new ObservedMetadataStore(new CachedMetadataStoreDecorator(new ObservedMetadataStore($this->metadata, $metrics, 'inner')), $metrics, 'outer');
        $plainMetadata = new ObservedMetadataStore(new CachedMetadataStoreDecorator(new ObservedMetadataStore($this->createMock(MetadataStore::class), $metrics, 'inner')), $metrics, 'outer');

        self::assertSame($plannedStorage, MultipartPartWriteCapabilities::storage($plannedStorage));
        self::assertSame($plannedMetadata, MultipartPartWriteCapabilities::metadata($plannedMetadata));
        self::assertNull(MultipartPartWriteCapabilities::storage($plainStorage));
        self::assertNull(MultipartPartWriteCapabilities::metadata($plainMetadata));
    }

    public function test_qualified_part_handlers_reject_unknown_storage_before_physical_io(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $unknown = $this->createMock(StorageBackend::class);
        $unknown->expects(self::never())->method('putPart');
        $unknown->expects(self::never())->method('getObjectByPath');
        foreach ([
            new UploadPartHandler($this->metadata, $unknown, requireLocalFencedParts: true),
            new UploadPartCopyHandler($this->metadata, $unknown, requireLocalFencedParts: true),
        ] as $handler) {
            try {
                $handler->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'part'));
                self::fail('Qualified multipart handler admitted unknown storage.');
            } catch (InternalErrorException $error) {
                self::assertStringContainsString('Local multipart parts require', $error->getMessage());
            }
        }
    }

    public function test_nested_metadata_without_part_intents_selects_plain_upload_fallback(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $innerMetadata = $this->createMock(MetadataStore::class);
        $innerMetadata->method('getBucket')->willReturnCallback($this->metadata->getBucket(...));
        $innerMetadata->method('getMultipartUpload')->willReturnCallback($this->metadata->getMultipartUpload(...));
        $metrics = new MetricsCollector();
        $metadata = new ObservedMetadataStore(new CachedMetadataStoreDecorator(new ObservedMetadataStore($innerMetadata, $metrics, 'inner')), $metrics, 'outer');
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->expects(self::never())->method('planMultipartPartPath');
        $storage->expects(self::never())->method('putPartAtPath');
        $storage->expects(self::once())->method('putPart')->willThrowException(new \RuntimeException('Plain fallback selected.'));

        try {
            (new UploadPartHandler($metadata, $storage))->handleRequest(
                $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'plain'),
            );
            self::fail('The fallback write must reach the storage backend.');
        } catch (\RuntimeException $error) {
            self::assertSame('Plain fallback selected.', $error->getMessage());
        }
    }

    public function test_nested_metadata_without_part_intents_selects_plain_copy_fallback(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('plain'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', 5, '"' . md5('plain') . '"', 'application/octet-stream', $source->path);
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $innerMetadata = $this->createMock(MetadataStore::class);
        $innerMetadata->method('getBucket')->willReturnCallback($this->metadata->getBucket(...));
        $innerMetadata->method('getBucketOwner')->willReturnCallback($this->metadata->getBucketOwner(...));
        $innerMetadata->method('getMultipartUpload')->willReturnCallback($this->metadata->getMultipartUpload(...));
        $innerMetadata->method('getObjectMetadata')->willReturnCallback($this->metadata->getObjectMetadata(...));
        $metrics = new MetricsCollector();
        $metadata = new ObservedMetadataStore(new CachedMetadataStoreDecorator(new ObservedMetadataStore($innerMetadata, $metrics, 'inner')), $metrics, 'outer');
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->expects(self::never())->method('planMultipartPartPath');
        $storage->expects(self::never())->method('putPartAtPath');
        $storage->expects(self::once())->method('putPart')->willThrowException(new \RuntimeException('Plain copy fallback selected.'));

        try {
            (new UploadPartCopyHandler($metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/bucket/source.bin'],
            ));
            self::fail('The fallback copy must reach the storage backend.');
        } catch (\RuntimeException $error) {
            self::assertSame('Plain copy fallback selected.', $error->getMessage());
        }
    }

    public function test_plain_part_copy_timeout_retains_planned_path_for_late_write_recovery(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('plain'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', 5, '"' . md5('plain') . '"', 'application/octet-stream', $source->path);
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $plannedPath = null;
        $storage = $this->createMock(RecoverableMultipartPartBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->expects(self::once())->method('planMultipartPartPath')
            ->willReturnCallback($this->storage->planMultipartPartPath(...));
        $storage->expects(self::never())->method('putPart');
        $storage->expects(self::once())->method('putPartAtPath')
            ->willReturnCallback(static function (string $bucket, string $key, string $id, int $number, \Amp\ByteStream\ReadableStream $body, string $path) use (&$plannedPath): never {
                $plannedPath = $path;
                throw new \RuntimeException('Injected copy worker timeout.');
            });

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/bucket/source.bin'],
            ));
            self::fail('Timed-out part copy must fail the request.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected copy worker timeout.', $error->getMessage());
        }

        self::assertNotNull($plannedPath);
        self::assertSame([], $this->metadata->getParts($uploadId));
        $db = new \PDO('sqlite:' . $this->databasePath);
        $intent = $db->prepare("SELECT COUNT(*) FROM s3_storage_garbage WHERE storage_path = ? AND operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $intent);
        $intent->execute([$plannedPath]);
        self::assertSame(1, (int) $intent->fetchColumn());

        $this->storage->putPartAtPath('bucket', 'retry.bin', $uploadId, 1, new \Amp\ByteStream\ReadableBuffer('late plain'), $plannedPath);
        $db->prepare('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = ?')->execute([$plannedPath]);
        self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect());
        $this->assertStoragePathMissing($this->storage, $plannedPath);
    }

    public function test_plain_part_copy_uses_planned_path_and_consumes_intent_after_commit(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('plain'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', 5, '"' . md5('plain') . '"', 'application/octet-stream', $source->path);
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $response = (new UploadPartCopyHandler($this->metadata, $this->storage))->handleRequest($this->request(
            'PUT',
            '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
            headers: ['x-amz-copy-source' => '/bucket/source.bin'],
        ));

        self::assertSame(200, $response->getStatus());
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);
        $generation = $this->metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        self::assertMatchesRegularExpression(
            '~^memory://\.parts/' . $uploadId . '/1-' . $generation . '-[a-f0-9]{32}$~D',
            $parts[0]['storage_path'],
        );
        self::assertSame('plain', \Amp\ByteStream\buffer($this->storage->getObjectByPath($parts[0]['storage_path'])));
        $intent = (new \PDO('sqlite:' . $this->databasePath))
            ->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $intent);
        self::assertSame(0, (int) $intent->fetchColumn());
    }

    public function test_upload_part_copy_closes_source_stream_when_part_write_fails_early(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            3,
            '"' . md5('abc') . '"',
            'application/octet-stream',
            'source-path',
        );
        $source = new \Amp\ByteStream\ReadableBuffer('abc');
        $capturedStream = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')->willReturn($source);
        $storage->expects(self::once())->method('putPart')->willReturnCallback(
            static function (string $bucket, string $key, string $uploadId, int $partNumber, \Amp\ByteStream\ReadableStream $body) use (&$capturedStream): never {
                $capturedStream = $body;
                throw new \RuntimeException('Injected part write failure.');
            },
        );

        try {
            (new UploadPartCopyHandler($this->metadata, $storage))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, '', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
            self::fail('The injected storage write must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Injected part write failure.', $e->getMessage());
        }

        self::assertInstanceOf(\Amp\ByteStream\ReadableStream::class, $capturedStream);
        self::assertTrue($capturedStream->isClosed());
        self::assertTrue($source->isClosed());
        self::assertSame([], $this->metadata->getParts($uploadId));
    }

    public function test_empty_checksum_header_rejects_object_put_without_publishing_metadata(): void
    {
        $handler = new PutObjectHandler($this->metadata, $this->storage);

        try {
            $handler->handleRequest($this->request('PUT', '/bucket/retry.bin', 'payload', [
                'x-amz-checksum-sha256' => '',
            ]));
            self::fail('An explicitly empty checksum must not be ignored.');
        } catch (BadDigestException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        }
    }

    public function test_empty_checksum_header_rejects_part_and_counts_as_second_checksum(): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $path = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;
        $handler = new UploadPartHandler($this->metadata, $this->storage);

        try {
            $handler->handleRequest($this->request('PUT', $path, 'payload', [
                'x-amz-checksum-sha256' => '',
            ]));
            self::fail('An explicitly empty part checksum must not be ignored.');
        } catch (BadDigestException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }

        try {
            $handler->handleRequest($this->request('PUT', $path, 'payload', [
                'x-amz-checksum-sha256' => '',
                'x-amz-checksum-crc32' => base64_encode(hash('crc32b', 'payload', true)),
            ]));
            self::fail('Two checksum headers must be rejected even if one is empty.');
        } catch (InvalidArgumentException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_completion_rejects_part_overwrite_that_commits_after_assembly(): void
    {
        $this->assertConcurrentPartReplacementRejected(false);
    }

    public function test_corrupt_stored_part_etag_is_rejected_before_assembly(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->metadata->putPart($uploadId, 1, 'invalid-md5', 7, 'missing-path');

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('assembleMultipartUpload');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>invalid-md5</ETag></Part></CompleteMultipartUpload>';

        $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
        (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body),
        );
    }

    public function test_completion_rejects_incorrect_assembly_size_and_preserves_retry(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $assembledPath = null;
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->method('assembleMultipartUploadAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, array $parts, string $path) use (&$assembledPath): StorageWriteResult {
            $result = $this->storage->assembleMultipartUploadAtPath($bucket, $key, $id, $parts, $path);
            $assembledPath = $result->path;

            return new StorageWriteResult(
                path: $result->path,
                size: $result->size - 1,
                md5Hex: $result->md5Hex,
            );
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('Completion accepted a short assembly result.');
        } catch (InternalErrorException) {
            self::assertNotNull($assembledPath);
            self::assertStoragePathMissing($this->storage, $assembledPath);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }

        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))->getStatus());
    }

    public function test_encrypted_completion_rejects_short_physical_part_read(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        $storage->expects(self::never())->method('putObjectAtPath');
        $storage->method('getObjectByPath')->willReturn(new \Amp\ByteStream\ReadableBuffer('paylo'));
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('Encrypted completion accepted a short physical part read.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }

        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))->getStatus());
    }

    /** @return iterable<string, array{string}> */
    public static function encryptedCompletionWriteFaults(): iterable
    {
        yield 'final path' => ['final-path'];
        yield 'final size' => ['final-size'];
        yield 'final digest' => ['final-digest'];
    }

    #[DataProvider('encryptedCompletionWriteFaults')]
    public function test_encrypted_completion_rejects_unplanned_paths_or_incorrect_final_write_and_preserves_retry(string $fault): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        $foreign = $this->storage->putObject('bucket', 'other.bin', new \Amp\ByteStream\ReadableBuffer('payload'));

        $finalPath = null;
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putObjectAtPath')->willReturnCallback(function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $stream, string $path) use ($fault, $foreign, &$finalPath): StorageWriteResult {
            $payload = \Amp\ByteStream\buffer($stream);
            if ($fault === 'final-size') {
                $payload = substr($payload, 0, -1);
            } elseif ($fault === 'final-digest') {
                $payload[0] = $payload[0] ^ "\x01";
            }
            $write = $this->storage->putObjectAtPath($bucket, $key, new \Amp\ByteStream\ReadableBuffer($payload), $path);
            $finalPath = $path;

            return new StorageWriteResult(
                $fault === 'final-path' ? $foreign->path : $write->path,
                $write->size,
                $write->md5Hex,
            );
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('Encrypted completion accepted an unplanned path or incorrect ciphertext write result.');
        } catch (InternalErrorException) {
            if ($finalPath !== null) {
                self::assertStoragePathMissing($this->storage, $finalPath);
            }
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
            self::assertSame('payload', \Amp\ByteStream\buffer($this->storage->getObjectByPath($foreign->path)));
        }

        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))->getStatus());
        self::assertSame('payload', \Amp\ByteStream\buffer((new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody()));
    }

    public function test_legacy_upload_without_verified_bucket_generation_cannot_assemble(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $etag = md5('payload');
        $this->metadata->putPart($uploadId, 1, $etag, 7, 'part-path');
        $update = (new \PDO('sqlite:' . $this->databasePath))
            ->prepare("UPDATE s3_multipart_uploads SET bucket_generation = '' WHERE upload_id = ?");
        self::assertInstanceOf(\PDOStatement::class, $update);
        $update->execute([$uploadId]);

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->expects(self::never())->method('planMultipartCompletionPaths');
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>' . $etag
            . '</ETag></Part></CompleteMultipartUpload>';
        $this->expectException(\OpsFour\S3Server\Exception\OperationAbortedException::class);
        (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body),
        );
    }

    public function test_completion_rejects_part_copy_that_commits_after_assembly(): void
    {
        $this->assertConcurrentPartReplacementRejected(true);
    }

    public function test_versioned_completion_rejects_part_overwrite_that_commits_after_assembly(): void
    {
        $this->assertConcurrentPartReplacementRejected(false, true);
    }

    private function assertConcurrentPartReplacementRejected(bool $copy, bool $versioned = false): void
    {
        if ($versioned) {
            $this->metadata->setBucketVersioning('bucket', 'Enabled');
        }
        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $original = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'original'));
        $originalEtag = $original->getHeader('ETag');
        self::assertNotNull($originalEtag);

        if ($copy) {
            $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('copied'));
            $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        }

        $replacementEtag = null;
        $assembledPath = null;
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->method('assembleMultipartUploadAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, array $parts, string $path) use ($copy, $uploadId, &$replacementEtag, &$assembledPath): StorageWriteResult {
            $assembled = $this->storage->assembleMultipartUploadAtPath($bucket, $key, $id, $parts, $path);
            $assembledPath = $assembled->path;
            $partUri = '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId;
            $replacement = $copy
                ? (new UploadPartCopyHandler($this->metadata, $this->storage))->handleRequest($this->request('PUT', $partUri, headers: ['x-amz-copy-source' => '/bucket/source.bin']))
                : (new UploadPartHandler($this->metadata, $this->storage))->handleRequest($this->request('PUT', $partUri, 'replacement'));
            self::assertSame(200, $replacement->getStatus());
            $replacementEtag = $copy
                ? (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($replacement->getBody())))->ETag
                : $replacement->getHeader('ETag');

            return $assembled;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->method('abortMultipartUpload')->willReturnCallback($this->storage->abortMultipartUpload(...));

        $originalBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($originalEtag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        try {
            (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest(
                $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $originalBody),
            );
            self::fail('Completion must reject a part replaced after its snapshot.');
        } catch (InvalidPartException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertNotNull($assembledPath);
            self::assertStoragePathMissing($this->storage, $assembledPath);
            self::assertSame($replacementEtag, $this->metadata->getParts($uploadId)[0]['etag']);
        }

        self::assertNotNull($replacementEtag);
        $replacementBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($replacementEtag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage))->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $replacementBody),
        )->getStatus());
        $read = (new GetObjectHandler($this->metadata, $this->storage))->handleRequest($this->request('GET', '/bucket/retry.bin'));
        self::assertSame($copy ? 'copied' : 'replacement', \Amp\ByteStream\buffer($read->getBody()));
    }

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-multipart-retry-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
        $this->encryption = new EncryptionService(
            new ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
        );
    }

    public function test_guarded_sse_s3_multipart_create_requires_the_worker_keyring_before_persisting_upload(): void
    {
        $oldKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        $oldKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');
        try {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
            putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
            $customKey = random_bytes(32);
            $custom = new class ($customKey) implements \OpsFour\S3Server\Encryption\MasterKeyProvider {
                public function __construct(private readonly string $key) {}
                public function getMasterKey(): string
                {
                    return $this->key;
                }
                public function getKeyId(): string
                {
                    return 'custom';
                }
                public function getMasterKeyById(string $keyId): string
                {
                    return $keyId === 'custom' ? $this->key : throw new \RuntimeException('Unknown key ID.');
                }
            };
            $request = $this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']);
            foreach ([
                'custom' => new EncryptionService($custom),
                'mismatched Config' => new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32)))),
            ] as $name => $service) {
                try {
                    (new CreateMultipartUploadHandler(
                        $this->metadata,
                        $service,
                        storage: $this->storage,
                        requireWorkerCompatibleSseS3: true,
                    ))->handleRequest($request);
                    self::fail($name . ' keyring created an upload that its completion worker cannot decrypt.');
                } catch (InternalErrorException $error) {
                    self::assertStringContainsString('worker-compatible Config keyring', $error->getMessage());
                    $count = (new \PDO('sqlite:' . $this->databasePath))->query('SELECT COUNT(*) FROM s3_multipart_uploads');
                    self::assertInstanceOf(\PDOStatement::class, $count);
                    self::assertSame(0, (int) $count->fetchColumn());
                }
            }

            $compatible = new EncryptionService(new ConfigMasterKeyProvider());
            $created = (new CreateMultipartUploadHandler(
                $this->metadata,
                $compatible,
                storage: $this->storage,
                requireWorkerCompatibleSseS3: true,
            ))->handleRequest($request);
            self::assertSame(200, $created->getStatus());
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
            $upload = $this->metadata->getMultipartUpload($uploadId);
            self::assertNotNull($upload);
            self::assertSame('AES256', $upload['user_metadata']['__sse-algorithm']);
            self::assertSame(EncryptedMultipartPartCodec::FORMAT, $upload['user_metadata']['__mpu-part-format']);
        } finally {
            putenv($oldKey === false ? 'S3_ENCRYPTION_MASTER_KEY' : 'S3_ENCRYPTION_MASTER_KEY=' . $oldKey);
            putenv($oldKeys === false ? 'S3_ENCRYPTION_MASTER_KEYS' : 'S3_ENCRYPTION_MASTER_KEYS=' . $oldKeys);
        }
    }

    public function test_guarded_sse_s3_part_uploads_reject_keyring_drift_before_physical_write(): void
    {
        $oldKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        $oldKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');
        try {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
            putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
            $encryption = new EncryptionService(new ConfigMasterKeyProvider());
            $this->withLocalPartWorker(function (FilesystemBackend $storage, LocalFencedMultipartPartWriter $writer) use ($encryption): void {
                $created = (new CreateMultipartUploadHandler(
                    $this->metadata,
                    $encryption,
                    storage: $storage,
                    requireWorkerCompatibleSseS3: true,
                ))->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', headers: ['x-amz-server-side-encryption' => 'AES256']));
                $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
                putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));

                foreach ([
                    new UploadPartHandler($this->metadata, $storage, encryption: $encryption, requireLocalFencedParts: true, localPartWriter: $writer),
                    new UploadPartCopyHandler($this->metadata, $storage, $encryption, requireLocalFencedParts: true, localPartWriter: $writer),
                ] as $handler) {
                    try {
                        $handler->handleRequest($this->request(
                            'PUT',
                            '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                            'sensitive part',
                            ['x-amz-copy-source' => '/bucket/source.bin'],
                        ));
                        self::fail('A part was encrypted with a keyring unavailable to completion workers.');
                    } catch (InternalErrorException $error) {
                        self::assertStringContainsString('worker-compatible Config keyring', $error->getMessage());
                    }
                }
                self::assertSame([], $this->metadata->getParts($uploadId));
                self::assertSame(0, $this->partWriteIntentCount());
                self::assertDirectoryDoesNotExist($storage->getBasePath() . '/.parts/' . $uploadId);
            });
        } finally {
            putenv($oldKey === false ? 'S3_ENCRYPTION_MASTER_KEY' : 'S3_ENCRYPTION_MASTER_KEY=' . $oldKey);
            putenv($oldKeys === false ? 'S3_ENCRYPTION_MASTER_KEYS' : 'S3_ENCRYPTION_MASTER_KEYS=' . $oldKeys);
        }
    }

    public function test_active_completion_renews_lease_while_assembly_yields(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->method('assembleMultipartUploadAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, array $parts, string $path): StorageWriteResult {
            $database = new \PDO('sqlite:' . $this->databasePath);
            $query = $database->query('SELECT lease_until FROM s3_multipart_write_intents LIMIT 1');
            self::assertInstanceOf(\PDOStatement::class, $query);
            $initial = $query->fetchColumn();
            $query->closeCursor();
            self::assertIsNumeric($initial);
            \Amp\delay(2.4);
            $query = $database->query('SELECT lease_until FROM s3_multipart_write_intents LIMIT 1');
            self::assertInstanceOf(\PDOStatement::class, $query);
            $active = $query->fetchColumn();
            $query->closeCursor();
            self::assertIsNumeric($active);
            self::assertGreaterThan((float) $initial, (float) $active);
            self::assertGreaterThan($this->metadata->multipartWriteIntentNow(), (float) $active);

            return $this->storage->assembleMultipartUploadAtPath($bucket, $key, $id, $parts, $path);
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));
        $storage->method('abortMultipartUpload')->willReturnCallback($this->storage->abortMultipartUpload(...));

        $handler = new CompleteMultipartUploadHandler(
            $this->metadata,
            $storage,
            intentLeaseSeconds: 2.0,
            intentRenewalSeconds: 0.2,
        );
        self::assertSame(200, $handler->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body),
        )->getStatus());
        self::assertNotNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
    }

    public function test_aborted_completion_cannot_renew_or_publish_after_assembly(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'));
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $attemptId = null;
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->method('planMultipartCompletionPaths')->willReturnCallback($this->storage->planMultipartCompletionPaths(...));
        $storage->method('assembleMultipartUploadAtPath')->willReturnCallback(function (string $bucket, string $key, string $id, array $parts, string $path) use (&$attemptId): StorageWriteResult {
            $database = new \PDO('sqlite:' . $this->databasePath);
            $query = $database->query('SELECT attempt_id FROM s3_multipart_write_intents LIMIT 1');
            self::assertInstanceOf(\PDOStatement::class, $query);
            $attemptId = $query->fetchColumn();
            self::assertIsString($attemptId);
            self::assertTrue($this->metadata->abandonMultipartWriteIntent($attemptId, $this->metadata->multipartWriteIntentNow()));
            \Amp\delay(0.35);

            return $this->storage->assembleMultipartUploadAtPath($bucket, $key, $id, $parts, $path);
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        $handler = new CompleteMultipartUploadHandler(
            $this->metadata,
            $storage,
            intentLeaseSeconds: 1.0,
            intentRenewalSeconds: 0.1,
        );
        try {
            $handler->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body));
            self::fail('An abandoned completion attempt published an object.');
        } catch (OperationAbortedException) {
            self::assertNotNull($attemptId);
            self::assertSame('abandoned', $this->metadata->getMultipartWriteIntent($attemptId)?->state);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        }
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_failed_sse_c_completion_keeps_parts_for_successful_retry(): void
    {
        $customerKey = random_bytes(32);
        $sseHeaders = [
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
            'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
        ];
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', '', $sseHeaders));
        $createXml = new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody()));
        $uploadId = (string) $createXml->UploadId;
        self::assertNotSame('', $uploadId);

        $part = (new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption))
            ->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                'retry payload',
                $sseHeaders,
            ));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $completeBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1)
            . '</ETag></Part></CompleteMultipartUpload>';
        $handler = new CompleteMultipartUploadHandler($this->metadata, $this->storage, $this->encryption);

        try {
            $handler->handleRequest($this->request(
                'POST',
                '/bucket/retry.bin?uploadId=' . $uploadId,
                $completeBody,
            ));
            self::fail('Expected missing SSE-C headers to reject completion.');
        } catch (InvalidArgumentException) {
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }

        $completed = $handler->handleRequest($this->request(
            'POST',
            '/bucket/retry.bin?uploadId=' . $uploadId,
            $completeBody,
            $sseHeaders,
        ));
        self::assertSame(200, $completed->getStatus());
        self::assertNull($this->metadata->getMultipartUpload($uploadId));

        $get = (new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin', '', $sseHeaders));
        self::assertSame('retry payload', \Amp\ByteStream\buffer($get->getBody()));
    }

    public function test_busy_buffered_slot_keeps_encrypted_parts_for_retry_without_final_write(): void
    {
        $headers = ['x-amz-server-side-encryption' => 'AES256'];
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', '', $headers));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'retry payload'));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $completeBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';
        $recording = new CallbackStorageBackend($this->storage);
        $handler = new CompleteMultipartUploadHandler($this->metadata, $recording, $this->encryption);

        $lock = BufferedWorkLimiter::acquire();
        try {
            $handler->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $completeBody));
            self::fail('Contended completion must return SlowDown.');
        } catch (SlowDownException) {
            self::assertNull($recording->lastWrite);
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        } finally {
            $lock->release();
        }

        self::assertSame(200, $handler->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $completeBody),
        )->getStatus());
    }

    public function test_oversized_encrypted_multipart_upload_is_rejected_before_plaintext_assembly(): void
    {
        $headers = ['x-amz-server-side-encryption' => 'AES256'];
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', '', $headers));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $partHandler = new UploadPartHandler($this->metadata, $this->storage, encryption: $this->encryption);
        $xmlParts = '';
        foreach ([1 => 'abc', 2 => 'def'] as $number => $payload) {
            $part = $partHandler->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=' . $number . '&uploadId=' . $uploadId,
                $payload,
            ));
            $etag = $part->getHeader('ETag');
            self::assertNotNull($etag);
            $xmlParts .= '<Part><PartNumber>' . $number . '</PartNumber><ETag>'
                . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }
        $completeBody = '<CompleteMultipartUpload>' . $xmlParts . '</CompleteMultipartUpload>';
        $recording = new CallbackStorageBackend($this->storage);
        $tooSmall = new CompleteMultipartUploadHandler(
            $this->metadata,
            $recording,
            $this->encryption,
            maxEncryptedObjectSize: 5,
        );

        try {
            $tooSmall->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $completeBody));
            self::fail('Oversized encrypted upload must be rejected.');
        } catch (EntityTooLargeException) {
            self::assertNull($recording->lastWrite);
            self::assertSame(0, $recording->putCalls);
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(2, $this->metadata->getParts($uploadId));
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        }

        $retry = new CompleteMultipartUploadHandler(
            $this->metadata,
            $recording,
            $this->encryption,
            maxEncryptedObjectSize: 6,
        );
        self::assertSame(200, $retry->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $completeBody),
        )->getStatus());
    }

    public function test_multipart_completion_enforces_expected_object_size_before_assembly(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))->handleRequest(
            $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'),
        );
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->expects(self::never())->method('planMultipartCompletionPaths');
        $storage->expects(self::never())->method('assembleMultipartUploadAtPath');
        $handler = new CompleteMultipartUploadHandler($this->metadata, $storage);
        foreach ([
            'mismatch' => [['x-amz-mp-object-size' => '8'], InvalidRequestException::class],
            'malformed' => [['x-amz-mp-object-size' => '7junk'], InvalidArgumentException::class],
            'duplicate' => [['x-amz-mp-object-size' => ['7', '7']], InvalidArgumentException::class],
            'above object limit' => [[
                'x-amz-mp-object-size' => (string) (MultipartPartSize::MAX_OBJECT_BYTES + 1),
            ], EntityTooLargeException::class],
        ] as $case => [$headers, $expectedException]) {
            try {
                $handler->handleRequest($this->request(
                    'POST',
                    '/bucket/retry.bin?uploadId=' . $uploadId,
                    $body,
                    $headers,
                ));
                self::fail("{$case} x-amz-mp-object-size must reject multipart completion.");
            } catch (\Throwable $error) {
                self::assertInstanceOf($expectedException, $error, $case);
                self::assertNotNull($this->metadata->getMultipartUpload($uploadId), $case);
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'), $case);
            }
        }

        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage))
            ->handleRequest($this->request(
                'POST',
                '/bucket/retry.bin?uploadId=' . $uploadId,
                $body,
                ['x-amz-mp-object-size' => '0007'],
            ))
            ->getStatus());
    }

    public function test_multipart_completion_preserves_standard_metadata_from_initiation(): void
    {
        $expires = 'Wed, 21 Oct 2037 07:28:00 GMT';
        $contentLanguage = 'de, en';
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request(
                'POST',
                '/bucket/retry.bin?uploads',
                headers: [
                    'expires' => $expires,
                    'content-language' => $contentLanguage,
                ],
            ));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))->handleRequest(
            $this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, 'payload'),
        );
        $etag = (string) $part->getHeader('ETag');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        self::assertSame(200, (new CompleteMultipartUploadHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body))
            ->getStatus());

        $object = $this->metadata->getObjectMetadata('bucket', 'retry.bin');
        self::assertNotNull($object);
        self::assertSame($expires, $object->userMetadata['__expires'] ?? null);
        self::assertSame($contentLanguage, $object->userMetadata['__content-language'] ?? null);
        self::assertArrayNotHasKey('__mpu-content-language', $object->userMetadata);
        $get = (new GetObjectHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'));
        self::assertSame($expires, $get->getHeader('Expires'));
        self::assertSame($contentLanguage, $get->getHeader('Content-Language'));
    }

    public function test_upload_part_copy_rejects_explicit_delete_marker_version(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $deleteMarkerVersionId = $this->metadata->deleteObjectVersioned('bucket', 'source.bin', 'owner');
        $uploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUpload($uploadId, 'bucket', 'retry.bin', 'owner');
        $request = $this->request(
            'PUT',
            '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
            headers: ['x-amz-copy-source' => '/bucket/source.bin?versionId=' . $deleteMarkerVersionId],
        );

        try {
            (new UploadPartCopyHandler($this->metadata, $this->storage))->handleRequest($request);
            self::fail('An explicit delete-marker copy source must be rejected as InvalidRequest.');
        } catch (InvalidRequestException $error) {
            self::assertSame('InvalidRequest', $error->getErrorCode());
            self::assertSame('The specified copy source is a delete marker.', $error->getMessage());
        }

        self::assertSame([], $this->metadata->getParts($uploadId));
    }

    public function test_multipart_completion_rejects_part_number_above_ten_thousand_before_storage_mutation(): void
    {
        $uploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUpload($uploadId, 'bucket', 'retry.bin', 'owner');

        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        foreach ([
            'putObject',
            'getObjectByPath',
            'deleteObjectByPath',
            'createBucket',
            'deleteBucket',
            'bucketExists',
            'putPart',
            'assembleMultipartUpload',
            'abortMultipartUpload',
            'copyObject',
            'planMultipartCompletionPaths',
            'assembleMultipartUploadAtPath',
            'putObjectAtPath',
        ] as $method) {
            $storage->expects(self::never())->method($method);
        }

        $body = '<CompleteMultipartUpload><Part><PartNumber>10001</PartNumber><ETag>"'
            . md5('10001') . '"</ETag></Part></CompleteMultipartUpload>';
        $this->expectException(\OpsFour\S3Server\Exception\MalformedXmlException::class);

        (new CompleteMultipartUploadHandler($this->metadata, $storage))->handleRequest(
            $this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body),
        );
    }

    public function test_busy_buffered_slot_cleans_copied_object_before_retry(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('copy payload'));
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'source.bin',
            ownerId: 'owner',
            size: $source->size,
            etag: '"' . $source->md5Hex . '"',
            contentType: 'application/octet-stream',
            storagePath: $source->path,
        );
        $recording = new CallbackStorageBackend($this->storage);
        $handler = new CopyObjectHandler($this->metadata, $recording, $this->encryption);
        $headers = [
            'x-amz-copy-source' => '/bucket/source.bin',
            'x-amz-server-side-encryption' => 'AES256',
        ];

        $lock = BufferedWorkLimiter::acquire();
        try {
            $handler->handleRequest($this->request('PUT', '/bucket/retry.bin', '', $headers));
            self::fail('Contended copy must return SlowDown.');
        } catch (SlowDownException) {
            self::assertSame(0, $recording->putCalls);
            self::assertSame(0, $recording->copyCalls);
            self::assertNull($recording->lastWrite);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        } finally {
            $lock->release();
        }

        self::assertSame(200, $handler->handleRequest(
            $this->request('PUT', '/bucket/retry.bin', '', $headers),
        )->getStatus());
    }

    public function test_sse_put_writes_only_ciphertext_to_object_storage(): void
    {
        $recording = new CallbackStorageBackend($this->storage);
        $handler = new PutObjectHandler($this->metadata, $recording, $this->encryption);
        $response = $handler->handleRequest($this->request('PUT', '/bucket/retry.bin', 'sensitive plaintext', [
            'x-amz-server-side-encryption' => 'AES256',
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame('"' . md5('sensitive plaintext') . '"', $response->getHeader('ETag'));
        self::assertSame(1, $recording->putCalls);
        self::assertNotNull($recording->lastWrite);
        self::assertNotSame('sensitive plaintext', \Amp\ByteStream\buffer($this->storage->getObjectByPath($recording->lastWrite->path)));
        self::assertSame('sensitive plaintext', \Amp\ByteStream\buffer((new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody()));
    }

    /** @return iterable<string, array{string}> */
    public static function ciphertextWriteFaults(): iterable
    {
        yield 'short result' => ['size'];
        yield 'wrong digest' => ['digest'];
    }

    #[DataProvider('ciphertextWriteFaults')]
    public function test_sse_put_rejects_incorrect_ciphertext_write_result_and_cleans_up(string $fault): void
    {
        $writtenPath = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $body) use ($fault, &$writtenPath): StorageWriteResult {
            $payload = \Amp\ByteStream\buffer($body);
            if ($fault === 'size') {
                $payload = substr($payload, 0, -1);
            } else {
                $payload[0] = $payload[0] ^ "\x01";
            }
            $write = $this->storage->putObject($bucket, $key, new \Amp\ByteStream\ReadableBuffer($payload));
            $writtenPath = $write->path;

            return $write;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new PutObjectHandler($this->metadata, $storage, $this->encryption))->handleRequest(
                $this->request('PUT', '/bucket/retry.bin', 'secret', ['x-amz-server-side-encryption' => 'AES256']),
            );
            self::fail('SSE PUT accepted an incorrect ciphertext write result.');
        } catch (InternalErrorException) {
            self::assertNotNull($writtenPath);
            self::assertStoragePathMissing($this->storage, $writtenPath);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        }

        self::assertSame(200, (new PutObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin', 'secret', ['x-amz-server-side-encryption' => 'AES256']))->getStatus());
    }

    public function test_sse_put_accepts_checksum_attached_after_body_is_consumed(): void
    {
        $recording = new CallbackStorageBackend($this->storage);
        $request = $this->request('PUT', '/bucket/retry.bin', '', [
            'x-amz-server-side-encryption' => 'AES256',
        ]);
        $checksum = base64_encode(hash('sha256', 'payload', true));
        $request->setBody(new \Amp\ByteStream\ReadableIterableStream((static function () use ($request, $checksum): \Generator {
            yield 'payload';
            $request->setHeader('x-amz-checksum-sha256', $checksum);
        })()));

        $response = (new PutObjectHandler($this->metadata, $recording, $this->encryption))->handleRequest($request);

        self::assertSame(200, $response->getStatus());
        self::assertSame($checksum, $response->getHeader('x-amz-checksum-sha256'));
        self::assertSame(1, $recording->putCalls);
        self::assertSame('payload', \Amp\ByteStream\buffer((new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody()));
    }

    public function test_sse_copy_writes_only_ciphertext_to_destination_storage(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('copy plaintext'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $recording = new CallbackStorageBackend($this->storage);
        $response = (new CopyObjectHandler($this->metadata, $recording, $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin', '', [
                'x-amz-copy-source' => '/bucket/source.bin',
                'x-amz-server-side-encryption' => 'AES256',
            ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame(1, $recording->putCalls);
        self::assertSame(0, $recording->copyCalls);
        self::assertNotNull($recording->lastWrite);
        self::assertNotSame('copy plaintext', \Amp\ByteStream\buffer($this->storage->getObjectByPath($recording->lastWrite->path)));
        self::assertSame('copy plaintext', \Amp\ByteStream\buffer($this->storage->getObjectByPath($source->path)));
        self::assertSame('copy plaintext', \Amp\ByteStream\buffer((new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody()));
    }

    #[DataProvider('ciphertextWriteFaults')]
    public function test_sse_copy_rejects_incorrect_ciphertext_write_result_and_cleans_up(string $fault): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer('source'));
        $this->metadata->putObjectMetadata('bucket', 'source.bin', 'owner', $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
        $writtenPath = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putObject')->willReturnCallback(function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $body) use ($fault, &$writtenPath): StorageWriteResult {
            $payload = \Amp\ByteStream\buffer($body);
            if ($fault === 'size') {
                $payload = substr($payload, 0, -1);
            } else {
                $payload[0] = $payload[0] ^ "\x01";
            }
            $write = $this->storage->putObject($bucket, $key, new \Amp\ByteStream\ReadableBuffer($payload));
            $writtenPath = $write->path;

            return $write;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new CopyObjectHandler($this->metadata, $storage, $this->encryption))->handleRequest(
                $this->request('PUT', '/bucket/retry.bin', '', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                    'x-amz-server-side-encryption' => 'AES256',
                ]),
            );
            self::fail('SSE COPY accepted an incorrect ciphertext write result.');
        } catch (InternalErrorException) {
            self::assertNotNull($writtenPath);
            self::assertStoragePathMissing($this->storage, $writtenPath);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertSame('source', \Amp\ByteStream\buffer($this->storage->getObjectByPath($source->path)));
        }

        self::assertSame(200, (new CopyObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin', '', [
                'x-amz-copy-source' => '/bucket/source.bin',
                'x-amz-server-side-encryption' => 'AES256',
            ]))->getStatus());
    }

    public function test_sse_put_rejects_oversize_body_before_any_storage_write(): void
    {
        $recording = new CallbackStorageBackend($this->storage);
        $handler = new PutObjectHandler($this->metadata, $recording, $this->encryption, maxEncryptedObjectSize: 3);

        try {
            $handler->handleRequest($this->request('PUT', '/bucket/retry.bin', 'four', [
                'x-amz-server-side-encryption' => 'AES256',
            ]));
            self::fail('Oversized SSE request must fail before storage receives plaintext.');
        } catch (EntityTooLargeException) {
            self::assertSame(0, $recording->putCalls);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        }
    }

    public function test_sse_put_key_failure_does_not_call_storage(): void
    {
        $recording = new CallbackStorageBackend($this->storage);
        $encryption = $this->createMock(\OpsFour\S3Server\Encryption\EncryptionServiceInterface::class);
        $encryption->expects(self::once())->method('encryptSseS3')->willThrowException(new \RuntimeException('master key unavailable'));
        $handler = new PutObjectHandler($this->metadata, $recording, $encryption);

        try {
            $handler->handleRequest($this->request('PUT', '/bucket/retry.bin', 'secret', [
                'x-amz-server-side-encryption' => 'AES256',
            ]));
            self::fail('Encryption failure must not write plaintext to storage.');
        } catch (\RuntimeException $e) {
            self::assertSame('master key unavailable', $e->getMessage());
            self::assertSame(0, $recording->putCalls);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        }
    }

    public function test_sse_c_source_to_sse_s3_copy_never_writes_plaintext_to_destination(): void
    {
        $customerKey = random_bytes(32);
        $encryptedSource = $this->encryption->encryptSseC('encrypted source', $customerKey);
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer($encryptedSource['ciphertext']));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            strlen('encrypted source'),
            '"' . md5('encrypted source') . '"',
            'application/octet-stream',
            $source->path,
            userMetadata: [
                '__sse-algorithm' => 'SSE-C',
                '__sse-iv' => $encryptedSource['iv'],
                '__sse-tag' => $encryptedSource['tag'],
                '__sse-customer-key-md5' => base64_encode(md5($customerKey, true)),
            ],
        );
        $recording = new CallbackStorageBackend($this->storage);
        $response = (new CopyObjectHandler($this->metadata, $recording, $this->encryption))
            ->handleRequest($this->request('PUT', '/bucket/retry.bin', '', [
                'x-amz-copy-source' => '/bucket/source.bin',
                'x-amz-copy-source-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-copy-source-server-side-encryption-customer-key' => base64_encode($customerKey),
                'x-amz-copy-source-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
                'x-amz-server-side-encryption' => 'AES256',
            ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame(1, $recording->putCalls);
        self::assertSame(0, $recording->copyCalls);
        self::assertNotNull($recording->lastWrite);
        self::assertNotSame('encrypted source', \Amp\ByteStream\buffer($this->storage->getObjectByPath($recording->lastWrite->path)));
        self::assertSame('encrypted source', \Amp\ByteStream\buffer((new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin'))->getBody()));
    }

    public function test_encrypted_copy_paths_reject_oversized_physical_source_before_decryption_or_commit(): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            3,
            '"' . md5('abc') . '"',
            'application/octet-stream',
            'corrupt-source-path',
            userMetadata: ['__sse-algorithm' => 'AES256'],
        );
        $sourceStorage = $this->createMock(StorageBackend::class);
        $sourceStorage->expects(self::exactly(3))
            ->method('getObjectByPath')
            ->with('corrupt-source-path')
            ->willReturnCallback(static fn(): \Amp\ByteStream\ReadableBuffer => new \Amp\ByteStream\ReadableBuffer('abcd'));
        $recording = new CallbackStorageBackend($this->storage);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $sourceStorage),
            new StorageTier('HOT', $recording, defaultWriteTier: true),
        ]);

        foreach ([[], ['x-amz-server-side-encryption' => 'AES256']] as $headers) {
            try {
                (new CopyObjectHandler($this->metadata, $recording, $this->encryption, storageTiers: $tiers))
                    ->handleRequest($this->request('PUT', '/bucket/retry.bin', '', [
                        'x-amz-copy-source' => '/bucket/source.bin',
                        ...$headers,
                    ]));
                self::fail('CopyObject must reject a physical source larger than its metadata.');
            } catch (InternalErrorException $e) {
                self::assertSame('Encrypted source size does not match metadata.', $e->getMessage());
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            }
        }
        self::assertSame(1, $recording->putCalls);
        self::assertNotNull($recording->lastWrite);
        self::assertStoragePathMissing($this->storage, $recording->lastWrite->path);

        $created = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        try {
            (new UploadPartCopyHandler($this->metadata, $recording, $this->encryption, storageTiers: $tiers))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, '', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
            self::fail('UploadPartCopy must reject a physical source larger than its metadata.');
        } catch (InternalErrorException $e) {
            self::assertSame('Encrypted source size does not match metadata.', $e->getMessage());
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_encrypted_upload_part_copy_range_rejects_decrypted_size_mismatch_before_part_write(): void
    {
        $sourcePayload = str_repeat('a', 5_242_881);
        $source = $this->storage->putObject(
            'bucket',
            'source.bin',
            new \Amp\ByteStream\ReadableBuffer($sourcePayload),
        );
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            strlen($sourcePayload),
            '"' . md5($sourcePayload) . '"',
            'application/octet-stream',
            $source->path,
            userMetadata: [
                '__sse-algorithm' => 'AES256',
                '__sse-key' => 'wrapped-key',
                '__sse-iv' => 'iv',
                '__sse-tag' => 'tag',
            ],
        );
        $created = (new CreateMultipartUploadHandler($this->metadata))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $encryption = $this->createMock(\OpsFour\S3Server\Encryption\EncryptionServiceInterface::class);
        $encryption->expects(self::once())->method('decryptSseS3')->willReturn($sourcePayload . 'x');

        try {
            (new UploadPartCopyHandler($this->metadata, $this->storage, $encryption))
                ->handleRequest($this->request(
                    'PUT',
                    '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                    headers: [
                        'x-amz-copy-source' => '/bucket/source.bin',
                        'x-amz-copy-source-range' => 'bytes=0-0',
                    ],
                ));
            self::fail('A ranged encrypted copy accepted a decrypted source with a different full size.');
        } catch (InternalErrorException $error) {
            self::assertSame('Decrypted copy source size does not match metadata.', $error->getMessage());
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
    }

    public function test_plain_cross_tier_copy_paths_stage_at_most_one_byte_beyond_source_metadata(): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            3,
            '"' . md5('abc') . '"',
            'application/octet-stream',
            'corrupt-source-path',
        );
        $sourceStorage = $this->createMock(StorageBackend::class);
        $sourceStorage->expects(self::exactly(2))
            ->method('getObjectByPath')
            ->with('corrupt-source-path')
            ->willReturnCallback(static fn(): \Amp\ByteStream\ReadableBuffer => new \Amp\ByteStream\ReadableBuffer(str_repeat('x', 1_000_000)));
        $recording = new CallbackStorageBackend($this->storage);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $sourceStorage),
            new StorageTier('HOT', $recording, defaultWriteTier: true),
        ]);

        try {
            (new CopyObjectHandler($this->metadata, $recording, storageTiers: $tiers))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin', '', ['x-amz-copy-source' => '/bucket/source.bin']));
            self::fail('CopyObject must reject an oversized physical source.');
        } catch (InternalErrorException) {
            self::assertSame(4, $recording->lastWrite?->size);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($recording->lastWrite);
            self::assertStoragePathMissing($this->storage, $recording->lastWrite->path);
        }

        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $recording, storageTiers: $tiers))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        try {
            (new UploadPartCopyHandler($this->metadata, $recording, storageTiers: $tiers))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId, '', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
            self::fail('UploadPartCopy must reject an oversized physical source.');
        } catch (InternalErrorException) {
            self::assertSame(4, $recording->lastPartWrite?->size);
            self::assertSame([], $this->metadata->getParts($uploadId));
            self::assertNotNull($recording->lastPartWrite);
            self::assertStoragePathMissing($this->storage, $recording->lastPartWrite->path);
        }
    }

    public function test_plain_same_tier_copy_rejects_oversized_physical_source_after_four_staged_bytes(): void
    {
        $source = $this->storage->putObject('bucket', 'source.bin', new \Amp\ByteStream\ReadableBuffer(str_repeat('x', 1_000_000)));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source.bin',
            'owner',
            3,
            '"' . md5('abc') . '"',
            'application/octet-stream',
            $source->path,
        );
        $recording = new CallbackStorageBackend($this->storage);

        try {
            (new CopyObjectHandler($this->metadata, $recording))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin', '', [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ]));
            self::fail('CopyObject must reject an oversized same-tier source.');
        } catch (InternalErrorException) {
            self::assertSame(1, $recording->copyCalls);
            self::assertSame(4, $recording->lastWrite?->size);
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
            self::assertNotNull($recording->lastWrite);
            self::assertStoragePathMissing($this->storage, $recording->lastWrite->path);
        }
    }

    /** @return iterable<string, array{array<non-empty-string, string>}> */
    public static function legacyEncryptionHeaders(): iterable
    {
        yield 'SSE-S3' => [['x-amz-server-side-encryption' => 'AES256']];
        $key = str_repeat('k', 32);
        yield 'SSE-C' => [[
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($key),
            'x-amz-server-side-encryption-customer-key-md5' => base64_encode(md5($key, true)),
        ]];
    }

    /** @param array<non-empty-string, string> $headers */
    #[DataProvider('legacyEncryptionHeaders')]
    public function test_legacy_encrypted_upload_rejects_part_copy_and_completion_but_can_be_aborted(array $headers): void
    {
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', '', $headers));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
        $this->markUploadAsLegacy($uploadId);
        $oldPart = $this->storage->putPart('bucket', 'retry.bin', $uploadId, 1, new \Amp\ByteStream\ReadableBuffer('old plaintext'));
        $etag = '"' . $oldPart->md5Hex . '"';
        $this->metadata->putPart($uploadId, 1, $etag, $oldPart->size, $oldPart->path);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part></CompleteMultipartUpload>';

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $storage->expects(self::never())->method('assembleMultipartUpload');
        foreach ([
            fn() => (new UploadPartHandler($this->metadata, $storage, encryption: $this->encryption))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=2&uploadId=' . $uploadId, 'new plaintext', $headers)),
            fn() => (new UploadPartCopyHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('PUT', '/bucket/retry.bin?partNumber=2&uploadId=' . $uploadId, headers: [
                    'x-amz-copy-source' => '/bucket/source.bin',
                ] + $headers)),
            fn() => (new CompleteMultipartUploadHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploadId=' . $uploadId, $body)),
        ] as $operation) {
            try {
                $operation();
                self::fail('Legacy encrypted upload accepted a write or completion.');
            } catch (OperationAbortedException) {
                self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            }
        }

        self::assertNull($this->metadata->getObjectMetadata('bucket', 'retry.bin'));
        self::assertCount(1, $this->metadata->getParts($uploadId));
        self::assertSame(204, (new AbortMultipartUploadHandler($this->metadata, $this->storage))
            ->handleRequest($this->request('DELETE', '/bucket/retry.bin?uploadId=' . $uploadId))->getStatus());
        self::assertNull($this->metadata->getMultipartUpload($uploadId));
        self::assertStoragePathMissing($this->storage, $oldPart->path);
    }

    /** @param array<non-empty-string, string> $headers */
    #[DataProvider('legacyEncryptionHeaders')]
    public function test_abort_of_legacy_encrypted_upload_collects_physical_parts_after_inline_delete_failure(array $headers): void
    {
        $base = sys_get_temp_dir() . '/s3-legacy-sse-abort-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0700));
        $storage = new FilesystemBackend($base);

        try {
            $storage->createBucket('bucket');
            $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
                ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', '', $headers));
            $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody())))->UploadId;
            $this->markUploadAsLegacy($uploadId);
            $upload = $this->metadata->getMultipartUpload($uploadId);
            self::assertNotNull($upload);
            self::assertArrayNotHasKey('__mpu-part-format', $upload['user_metadata']);

            $parts = [];
            foreach ([1 => 'legacy plaintext one', 2 => 'legacy plaintext two'] as $number => $plaintext) {
                $part = $storage->putPart('bucket', 'retry.bin', $uploadId, $number, new \Amp\ByteStream\ReadableBuffer($plaintext));
                $this->metadata->putPart($uploadId, $number, '"' . $part->md5Hex . '"', $part->size, $part->path);
                self::assertSame($plaintext, file_get_contents($part->path));
                $parts[$number] = $part->path;
            }

            $deleteGate = new class {
                public bool $blocked = true;
            };
            $failingStorage = new CallbackStorageBackend(
                $storage,
                beforeDelete: static function (string $path) use ($deleteGate, $parts): void {
                    if ($deleteGate->blocked && $path === $parts[2]) {
                        throw new \RuntimeException('simulated physical delete failure');
                    }
                },
            );
            self::assertSame(204, (new AbortMultipartUploadHandler($this->metadata, $failingStorage))
                ->handleRequest($this->request('DELETE', '/bucket/retry.bin?uploadId=' . $uploadId))->getStatus());
            self::assertNull($this->metadata->getMultipartUpload($uploadId));
            self::assertSame([], $this->metadata->getParts($uploadId));
            self::assertFileDoesNotExist($parts[1]);
            self::assertSame('legacy plaintext two', file_get_contents($parts[2]));

            $database = new \PDO('sqlite:' . $this->databasePath);
            $pending = $database->prepare("SELECT storage_path FROM s3_storage_garbage WHERE operation = 'delete_path' ORDER BY id");
            self::assertInstanceOf(\PDOStatement::class, $pending);
            $pending->execute();
            self::assertSame([$parts[2]], $pending->fetchAll(\PDO::FETCH_COLUMN));

            $deleteGate->blocked = false;
            self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($failingStorage)))->collect(1));
            self::assertFileDoesNotExist($parts[1]);
            self::assertFileDoesNotExist($parts[2]);
            $pending->execute();
            self::assertSame([], $pending->fetchAll(\PDO::FETCH_COLUMN));
        } finally {
            $storage->shutdown();
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($base);
        }
    }

    private function markUploadAsLegacy(string $uploadId): void
    {
        $upload = $this->metadata->getMultipartUpload($uploadId);
        self::assertNotNull($upload);
        $metadata = $upload['user_metadata'];
        unset($metadata['__mpu-part-format']);
        $update = (new \PDO('sqlite:' . $this->databasePath))
            ->prepare('UPDATE s3_multipart_uploads SET user_metadata = ? WHERE upload_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $update);
        $update->execute([json_encode($metadata, JSON_THROW_ON_ERROR), $uploadId]);
    }

    private static function assertStoragePathMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            $storage->getObjectByPath($path);
            self::fail('Uncommitted physical object was not deleted.');
        } catch (NoSuchKeyException) {
            return;
        }
    }

    private function metadataWithLostCompletionAck(): MetadataStore&MultipartWriteIntentStore
    {
        $proxy = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, MultipartWriteIntentStore::class]);
        self::assertInstanceOf(MetadataStore::class, $proxy);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $proxy);
        foreach ([MetadataStore::class, MultipartWriteIntentStore::class] as $contract) {
            foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
                $name = $method->getName();
                if ($name === 'transaction') {
                    continue;
                }
                $proxy->method($name)->willReturnCallback(fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments));
            }
        }
        $transactions = 0;
        $proxy->method('transaction')->willReturnCallback(function (callable $callback) use (&$transactions): mixed {
            $transactions++;
            $result = $this->metadata->transaction($callback);
            if ($transactions === 2) {
                throw new \RuntimeException('lost completion acknowledgement');
            }

            return $result;
        });

        return $proxy;
    }

    private function metadataWithLostPartCommitAck(): MetadataStore&FencedMultipartPartWriteIntentStore
    {
        $proxy = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, FencedMultipartPartWriteIntentStore::class]);
        self::assertInstanceOf(MetadataStore::class, $proxy);
        self::assertInstanceOf(FencedMultipartPartWriteIntentStore::class, $proxy);
        $seen = [];
        foreach ([MetadataStore::class, FencedMultipartPartWriteIntentStore::class] as $contract) {
            foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
                $name = $method->getName();
                if ($name === 'transaction' || isset($seen[$name])) {
                    continue;
                }
                $seen[$name] = true;
                $proxy->method($name)->willReturnCallback(fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments));
            }
        }
        $proxy->method('transaction')->willReturnCallback(function (callable $callback): mixed {
            $this->metadata->transaction($callback);
            throw new \RuntimeException('lost part commit acknowledgement');
        });

        return $proxy;
    }

    private function metadataWithCleanupFailures(bool $failAbandon = false): MetadataStore&FencedMultipartPartWriteIntentStore&MultipartWriteIntentStore
    {
        $proxy = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            FencedMultipartPartWriteIntentStore::class,
            MultipartWriteIntentStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $proxy);
        self::assertInstanceOf(FencedMultipartPartWriteIntentStore::class, $proxy);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $proxy);
        $seen = [];
        foreach ([MetadataStore::class, FencedMultipartPartWriteIntentStore::class, MultipartWriteIntentStore::class] as $contract) {
            foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
                $name = $method->getName();
                if (isset($seen[$name]) || $name === 'enqueueStorageGarbage' || ($failAbandon && $name === 'abandonMultipartWriteIntent')) {
                    continue;
                }
                $seen[$name] = true;
                $proxy->method($name)->willReturnCallback(fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments));
            }
        }
        $proxy->method('enqueueStorageGarbage')->willThrowException(new \RuntimeException('cleanup queue failed'));
        if ($failAbandon) {
            $proxy->method('abandonMultipartWriteIntent')->willThrowException(new \RuntimeException('abandon failed'));
        }

        return $proxy;
    }

    private function partWriteIntentCount(): int
    {
        $query = (new \PDO('sqlite:' . $this->databasePath))
            ->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $query);

        return (int) $query->fetchColumn();
    }

    /** @param callable(FilesystemBackend, LocalFencedMultipartPartWriter): void $test */
    private function withLocalPartWorker(callable $test): void
    {
        $base = sys_get_temp_dir() . '/s3-local-part-handler-' . bin2hex(random_bytes(8));
        $root = $base . '/storage';
        $scratch = $base . '/scratch';
        self::assertTrue(mkdir($base, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $canonicalRoot = realpath($root);
        self::assertIsString($canonicalRoot);
        $storage = new FilesystemBackend($canonicalRoot);
        $writer = new LocalFencedMultipartPartWriter($canonicalRoot, $scratch, 'STANDARD', $this->metadata);
        try {
            $storage->createBucket('bucket');
            $test($storage, $writer);
        } finally {
            $writer->shutdown();
            $storage->shutdown();
            $entries = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($base, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST,
            );
            foreach ($entries as $entry) {
                if ($entry->isDir() && ! $entry->isLink()) {
                    @rmdir($entry->getPathname());
                } else {
                    @unlink($entry->getPathname());
                }
            }
            @rmdir($base);
        }
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string|list<string>> $headers
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        $request = new Request(
            new MultipartRetryTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'retry.bin');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', \OpsFour\S3Server\Routing\S3Operation::PutObject);

        return $request;
    }
}

final class MultipartRetryTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
