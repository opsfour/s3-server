<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class DestinationReplacementOwnerLockTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-replacement-owner-lock-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_put_locks_foreign_owner_before_replacing_null_version(): void
    {
        $this->store('bucket', 'key', 'foreign-owner', 'old');
        $lockedOwners = [];
        $transactionState = new class {
            public int $nextId = 0;

            public ?int $activeId = null;

            public ?int $replacementLockId = null;

            public ?int $publicationId = null;
        };
        $metadata = $this->metadataProxy(
            transaction: function (callable $callback) use ($transactionState): mixed {
                $transactionState->activeId = ++$transactionState->nextId;
                try {
                    return $this->metadata->transaction($callback);
                } finally {
                    $transactionState->activeId = null;
                }
            },
            lockOwner: function (string $ownerId) use (&$lockedOwners, $transactionState): void {
                $lockedOwners[] = $ownerId;
                if ($ownerId === 'foreign-owner') {
                    $transactionState->replacementLockId = $transactionState->activeId;
                }
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
            beforePutObjectMetadata: function () use ($transactionState): void {
                $transactionState->publicationId = $transactionState->activeId;
            },
        );

        $response = (new PutObjectHandler($metadata, $this->storage))->handleRequest($this->putRequest('new'));

        self::assertSame(200, $response->getStatus());
        self::assertSame(['bucket-owner', 'foreign-owner', 'writer'], $lockedOwners);
        self::assertNotNull($transactionState->replacementLockId);
        self::assertSame($transactionState->replacementLockId, $transactionState->publicationId);
        self::assertSame('writer', $this->metadata->getObjectMetadata('bucket', 'key')?->ownerId);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $this->metadata->getOwnerStorageStats('foreign-owner'));
    }

    public function test_copy_locks_foreign_owner_before_replacing_null_version(): void
    {
        $this->createCopySource();
        $this->store('bucket', 'key', 'foreign-owner', 'old');
        $lockedOwners = [];
        $transactionState = new class {
            public int $nextId = 0;

            public ?int $activeId = null;

            public ?int $replacementLockId = null;

            public ?int $publicationId = null;
        };
        $metadata = $this->metadataProxy(
            transaction: function (callable $callback) use ($transactionState): mixed {
                $transactionState->activeId = ++$transactionState->nextId;
                try {
                    return $this->metadata->transaction($callback);
                } finally {
                    $transactionState->activeId = null;
                }
            },
            lockOwner: function (string $ownerId) use (&$lockedOwners, $transactionState): void {
                $lockedOwners[] = $ownerId;
                if ($ownerId === 'foreign-owner') {
                    $transactionState->replacementLockId = $transactionState->activeId;
                }
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
            beforePutObjectMetadata: function () use ($transactionState): void {
                $transactionState->publicationId = $transactionState->activeId;
            },
        );

        $response = (new CopyObjectHandler($metadata, $this->storage))->handleRequest($this->copyRequest());

        self::assertSame(200, $response->getStatus());
        self::assertSame(['bucket-owner', 'foreign-owner', 'writer'], $lockedOwners);
        self::assertNotNull($transactionState->replacementLockId);
        self::assertSame($transactionState->replacementLockId, $transactionState->publicationId);
        self::assertSame('writer', $this->metadata->getObjectMetadata('bucket', 'key')?->ownerId);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $this->metadata->getOwnerStorageStats('foreign-owner'));
    }

    public function test_multipart_completion_locks_foreign_owner_before_replacing_null_version(): void
    {
        [$uploadId, $etag] = $this->createMultipartUpload();
        $this->store('bucket', 'key', 'foreign-owner', 'old');
        $lockedOwners = [];
        $transactionState = new class {
            public int $nextId = 0;

            public ?int $activeId = null;

            public ?int $replacementLockId = null;

            public ?int $publicationId = null;
        };
        $metadata = $this->multipartMetadataProxy(
            transaction: function (callable $callback) use ($transactionState): mixed {
                $transactionState->activeId = ++$transactionState->nextId;
                try {
                    return $this->metadata->transaction($callback);
                } finally {
                    $transactionState->activeId = null;
                }
            },
            lockOwner: function (string $ownerId) use (&$lockedOwners, $transactionState): void {
                $lockedOwners[] = $ownerId;
                if ($ownerId === 'foreign-owner') {
                    $transactionState->replacementLockId = $transactionState->activeId;
                }
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
            beforePutObjectMetadata: function () use ($transactionState): void {
                $transactionState->publicationId = $transactionState->activeId;
            },
        );

        $response = (new CompleteMultipartUploadHandler($metadata, $this->storage))
            ->handleRequest($this->completionRequest($uploadId, $etag));

        self::assertSame(200, $response->getStatus());
        self::assertSame(['bucket-owner', 'foreign-owner', 'writer'], array_slice($lockedOwners, -3));
        self::assertNotNull($transactionState->replacementLockId);
        self::assertSame($transactionState->replacementLockId, $transactionState->publicationId);
        self::assertSame('writer', $this->metadata->getObjectMetadata('bucket', 'key')?->ownerId);
        self::assertSame(['objectCount' => 0, 'bytesUsed' => 0], $this->metadata->getOwnerStorageStats('foreign-owner'));
    }

    public function test_put_aborts_and_cleans_payload_when_locked_observation_becomes_stale(): void
    {
        $this->store('bucket', 'key', 'observed-owner', 'observed');
        $replacement = $this->storage->putObject('bucket', 'key', new ReadableBuffer('replacement'));
        $replaced = false;
        $metadata = $this->metadataProxy(transaction: function (callable $callback) use (&$replaced, $replacement): mixed {
            if (! $replaced) {
                $replaced = true;
                $this->metadata->putObjectMetadata(
                    'bucket',
                    'key',
                    'replacement-owner',
                    $replacement->size,
                    '"' . $replacement->md5Hex . '"',
                    'text/plain',
                    $replacement->path,
                );
            }

            return $this->metadata->transaction($callback);
        });
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new PutObjectHandler($metadata, $storage))->handleRequest($this->putRequest('rejected'));
            self::fail('A replacement owned by an unlocked principal was overwritten.');
        } catch (OperationAbortedException) {
            $current = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($current);
            self::assertSame('replacement-owner', $current->ownerId);
            self::assertSame($replacement->path, $current->systemMetadata['storagePath']);
            self::assertNotNull($storage->lastWrite);
            self::assertPathMissing($this->storage, $storage->lastWrite->path);
        }
    }

    public function test_copy_aborts_and_cleans_payload_when_locked_observation_becomes_stale(): void
    {
        $this->createCopySource();
        $this->store('bucket', 'key', 'observed-owner', 'observed');
        $replacement = $this->storage->putObject('bucket', 'key', new ReadableBuffer('replacement'));
        $replaced = false;
        $metadata = $this->metadataProxy(transaction: function (callable $callback) use (&$replaced, $replacement): mixed {
            if (! $replaced) {
                $replaced = true;
                $this->metadata->putObjectMetadata(
                    'bucket',
                    'key',
                    'replacement-owner',
                    $replacement->size,
                    '"' . $replacement->md5Hex . '"',
                    'text/plain',
                    $replacement->path,
                );
            }

            return $this->metadata->transaction($callback);
        });
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->copyRequest());
            self::fail('A replacement owned by an unlocked principal was overwritten.');
        } catch (OperationAbortedException) {
            $current = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($current);
            self::assertSame('replacement-owner', $current->ownerId);
            self::assertSame($replacement->path, $current->systemMetadata['storagePath']);
            self::assertNotNull($storage->lastWrite);
            self::assertPathMissing($this->storage, $storage->lastWrite->path);
        }
    }

    public function test_multipart_aborts_and_abandons_assembly_when_locked_observation_becomes_stale(): void
    {
        [$uploadId, $etag] = $this->createMultipartUpload();
        $this->store('bucket', 'key', 'observed-owner', 'observed');
        $replacement = $this->storage->putObject('bucket', 'key', new ReadableBuffer('replacement'));
        $transactionNumber = 0;
        $metadata = $this->multipartMetadataProxy(transaction: function (callable $callback) use (&$transactionNumber, $replacement): mixed {
            $transactionNumber++;
            if ($transactionNumber === 2) {
                $this->metadata->putObjectMetadata(
                    'bucket',
                    'key',
                    'replacement-owner',
                    $replacement->size,
                    '"' . $replacement->md5Hex . '"',
                    'text/plain',
                    $replacement->path,
                );
            }

            return $this->metadata->transaction($callback);
        });
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new CompleteMultipartUploadHandler($metadata, $storage))
                ->handleRequest($this->completionRequest($uploadId, $etag));
            self::fail('A replacement owned by an unlocked principal was overwritten.');
        } catch (OperationAbortedException) {
            $current = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($current);
            self::assertSame('replacement-owner', $current->ownerId);
            self::assertSame($replacement->path, $current->systemMetadata['storagePath']);
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertNotNull($storage->lastWrite);
            self::assertPathMissing($this->storage, $storage->lastWrite->path);
        }
    }

    public function test_put_rejects_empty_replacement_owner_and_cleans_payload(): void
    {
        $this->store('bucket', 'key', '', 'old');
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new PutObjectHandler($this->metadataProxy(), $storage))->handleRequest($this->putRequest('rejected'));
            self::fail('An accounted replacement with an empty owner was overwritten.');
        } catch (OperationAbortedException) {
            self::assertSame('', $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null')?->ownerId);
            self::assertNotNull($storage->lastWrite);
            self::assertPathMissing($this->storage, $storage->lastWrite->path);
        }
    }

    public function test_copy_rejects_empty_replacement_owner_and_cleans_payload(): void
    {
        $this->createCopySource();
        $this->store('bucket', 'key', '', 'old');
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new CopyObjectHandler($this->metadataProxy(), $storage))->handleRequest($this->copyRequest());
            self::fail('An accounted replacement with an empty owner was overwritten.');
        } catch (OperationAbortedException) {
            self::assertSame('', $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null')?->ownerId);
            self::assertNotNull($storage->lastWrite);
            self::assertPathMissing($this->storage, $storage->lastWrite->path);
        }
    }

    public function test_multipart_rejects_empty_replacement_owner_and_abandons_assembly(): void
    {
        [$uploadId, $etag] = $this->createMultipartUpload();
        $this->store('bucket', 'key', '', 'old');
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new CompleteMultipartUploadHandler($this->multipartMetadataProxy(), $storage))
                ->handleRequest($this->completionRequest($uploadId, $etag));
            self::fail('An accounted replacement with an empty owner was overwritten.');
        } catch (OperationAbortedException) {
            self::assertSame('', $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null')?->ownerId);
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertNotNull($storage->lastWrite);
            self::assertPathMissing($this->storage, $storage->lastWrite->path);
        }
    }

    public function test_put_does_not_lock_or_replace_retained_null_owner_when_versioning_is_enabled(): void
    {
        $this->store('bucket', 'key', 'foreign-owner', 'retained null');
        $retainedNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($retainedNull);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $lockedOwners = [];
        $metadata = $this->metadataProxy(lockOwner: function (string $ownerId) use (&$lockedOwners): void {
            $lockedOwners[] = $ownerId;
            $this->metadata->lockOwnerForUpdate($ownerId);
        });

        (new PutObjectHandler($metadata, $this->storage))->handleRequest($this->putRequest('new'));

        self::assertSame(['bucket-owner', 'writer'], $lockedOwners);
        $storedNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($storedNull);
        self::assertSame('foreign-owner', $storedNull->ownerId);
        self::assertSame($retainedNull->systemMetadata['storagePath'], $storedNull->systemMetadata['storagePath']);
    }

    public function test_copy_does_not_lock_or_replace_retained_null_owner_when_versioning_is_enabled(): void
    {
        $this->createCopySource();
        $this->store('bucket', 'key', 'foreign-owner', 'retained null');
        $retainedNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($retainedNull);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $lockedOwners = [];
        $metadata = $this->metadataProxy(lockOwner: function (string $ownerId) use (&$lockedOwners): void {
            $lockedOwners[] = $ownerId;
            $this->metadata->lockOwnerForUpdate($ownerId);
        });

        (new CopyObjectHandler($metadata, $this->storage))->handleRequest($this->copyRequest());

        self::assertSame(['bucket-owner', 'writer'], $lockedOwners);
        $storedNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($storedNull);
        self::assertSame('foreign-owner', $storedNull->ownerId);
        self::assertSame($retainedNull->systemMetadata['storagePath'], $storedNull->systemMetadata['storagePath']);
    }

    public function test_multipart_does_not_lock_or_replace_retained_null_owner_when_versioning_is_enabled(): void
    {
        [$uploadId, $etag] = $this->createMultipartUpload();
        $this->store('bucket', 'key', 'foreign-owner', 'retained null');
        $retainedNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($retainedNull);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $lockedOwners = [];
        $metadata = $this->multipartMetadataProxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );

        (new CompleteMultipartUploadHandler($metadata, $this->storage))
            ->handleRequest($this->completionRequest($uploadId, $etag));

        self::assertNotContains('foreign-owner', $lockedOwners);
        $storedNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($storedNull);
        self::assertSame('foreign-owner', $storedNull->ownerId);
        self::assertSame($retainedNull->systemMetadata['storagePath'], $storedNull->systemMetadata['storagePath']);
    }

    public function test_copy_does_not_lock_retained_numbered_owner_when_versioning_is_suspended(): void
    {
        $this->createCopySource();
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $write = $this->storage->putObject('bucket', 'key', new ReadableBuffer('numbered'));
        $numberedVersionId = $this->metadata->putObjectVersioned(
            'bucket',
            'key',
            'numbered-owner',
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $lockedOwners = [];
        $metadata = $this->metadataProxy(lockOwner: function (string $ownerId) use (&$lockedOwners): void {
            $lockedOwners[] = $ownerId;
            $this->metadata->lockOwnerForUpdate($ownerId);
        });

        (new CopyObjectHandler($metadata, $this->storage))->handleRequest($this->copyRequest());

        self::assertSame(['bucket-owner', 'writer'], $lockedOwners);
        self::assertSame('numbered-owner', $this->metadata
            ->getObjectMetadataByVersion('bucket', 'key', $numberedVersionId)?->ownerId);
    }

    public function test_multipart_does_not_lock_null_delete_marker_owner(): void
    {
        [$uploadId, $etag] = $this->createMultipartUpload();
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->metadata->deleteObjectVersioned('bucket', 'key', 'marker-owner', true);
        $lockedOwners = [];
        $metadata = $this->multipartMetadataProxy(
            lockOwner: function (string $ownerId) use (&$lockedOwners): void {
                $lockedOwners[] = $ownerId;
                $this->metadata->lockOwnerForUpdate($ownerId);
            },
        );

        (new CompleteMultipartUploadHandler($metadata, $this->storage))
            ->handleRequest($this->completionRequest($uploadId, $etag));

        self::assertSame(['bucket-owner', 'writer'], array_slice($lockedOwners, -2));
        self::assertSame('writer', $this->metadata->getObjectMetadata('bucket', 'key')?->ownerId);
    }

    private function store(string $bucket, string $key, string $ownerId, string $body): void
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($body));
        $this->metadata->putObjectMetadata(
            $bucket,
            $key,
            $ownerId,
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
    }

    private function createCopySource(): void
    {
        $this->metadata->createBucket('writer', 'source', 'us-east-1');
        $this->storage->createBucket('source');
        $this->store('source', 'source-key', 'writer', 'source payload');
    }

    /** @return array{string, string} */
    private function createMultipartUpload(): array
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->storage))
            ->handleRequest($this->multipartRequest('POST', '/bucket/key?uploads', S3Operation::CreateMultipartUpload));
        $uploadId = (string) (new \SimpleXMLElement(ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($this->metadata, $this->storage))->handleRequest(
            $this->multipartRequest(
                'PUT',
                '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                S3Operation::UploadPart,
                'multipart payload',
                ['content-length' => '17'],
            ),
        );
        $etag = $part->getHeader('etag');
        self::assertNotNull($etag);

        return [$uploadId, $etag];
    }

    private function putRequest(string $body): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['content-length' => (string) strlen($body)],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', 'writer');

        return $request;
    }

    private function copyRequest(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['x-amz-copy-source' => '/source/source-key'],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::CopyObject);
        $request->setAttribute('ownerId', 'writer');

        return $request;
    }

    private function completionRequest(string $uploadId, string $etag): Request
    {
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1)
            . '</ETag></Part></CompleteMultipartUpload>';

        return $this->multipartRequest(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            S3Operation::CompleteMultipartUpload,
            $body,
        );
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function multipartRequest(
        string $method,
        string $path,
        S3Operation $operation,
        string $body = '',
        array $headers = [],
    ): Request {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('ownerId', 'writer');

        return $request;
    }

    private function metadataProxy(
        ?callable $transaction = null,
        ?callable $lockOwner = null,
        ?callable $beforePutObjectMetadata = null,
    ): MetadataStore {
        $proxy = $this->createMock(MetadataStore::class);
        foreach ((new \ReflectionClass(MetadataStore::class))->getMethods() as $method) {
            $name = $method->getName();
            if ($name === 'transaction'
                || $name === 'lockOwnerForUpdate'
                || ($name === 'putObjectMetadata' && $beforePutObjectMetadata !== null)) {
                continue;
            }
            $proxy->method($name)->willReturnCallback(
                fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments),
            );
        }
        $proxy->method('transaction')->willReturnCallback(
            $transaction ?? fn(callable $callback): mixed => $this->metadata->transaction($callback),
        );
        $proxy->method('lockOwnerForUpdate')->willReturnCallback(
            $lockOwner ?? fn(string $ownerId) => $this->metadata->lockOwnerForUpdate($ownerId),
        );
        if ($beforePutObjectMetadata !== null) {
            $proxy->method('putObjectMetadata')->willReturnCallback(
                /** @param array<string, string> $userMetadata */
                function (
                    string $bucket,
                    string $key,
                    string $ownerId,
                    int $size,
                    string $etag,
                    string $contentType,
                    string $storagePath,
                    string $storageClass = 'STANDARD',
                    ?string $contentEncoding = null,
                    ?string $contentDisposition = null,
                    ?string $cacheControl = null,
                    array $userMetadata = [],
                    ?string $checksumCrc32 = null,
                    ?string $checksumCrc32c = null,
                    ?string $checksumSha1 = null,
                    ?string $checksumSha256 = null,
                ) use ($beforePutObjectMetadata): void {
                    $beforePutObjectMetadata();
                    $this->metadata->putObjectMetadata(
                        $bucket,
                        $key,
                        $ownerId,
                        $size,
                        $etag,
                        $contentType,
                        $storagePath,
                        $storageClass,
                        $contentEncoding,
                        $contentDisposition,
                        $cacheControl,
                        $userMetadata,
                        $checksumCrc32,
                        $checksumCrc32c,
                        $checksumSha1,
                        $checksumSha256,
                    );
                },
            );
        }

        return $proxy;
    }

    private function multipartMetadataProxy(
        ?callable $transaction = null,
        ?callable $lockOwner = null,
        ?callable $beforePutObjectMetadata = null,
    ): MetadataStore&MultipartWriteIntentStore {
        $proxy = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MultipartWriteIntentStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $proxy);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $proxy);
        $configured = [];
        foreach ([MetadataStore::class, MultipartWriteIntentStore::class] as $contract) {
            foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
                $name = $method->getName();
                if ($name === 'transaction'
                    || $name === 'lockOwnerForUpdate'
                    || ($name === 'putObjectMetadata' && $beforePutObjectMetadata !== null)
                    || isset($configured[$name])) {
                    continue;
                }
                $configured[$name] = true;
                $proxy->method($name)->willReturnCallback(
                    fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments),
                );
            }
        }
        $proxy->method('transaction')->willReturnCallback(
            $transaction ?? fn(callable $callback): mixed => $this->metadata->transaction($callback),
        );
        $proxy->method('lockOwnerForUpdate')->willReturnCallback(
            $lockOwner ?? fn(string $ownerId) => $this->metadata->lockOwnerForUpdate($ownerId),
        );
        if ($beforePutObjectMetadata !== null) {
            $proxy->method('putObjectMetadata')->willReturnCallback(
                /** @param array<string, string> $userMetadata */
                function (
                    string $bucket,
                    string $key,
                    string $ownerId,
                    int $size,
                    string $etag,
                    string $contentType,
                    string $storagePath,
                    string $storageClass = 'STANDARD',
                    ?string $contentEncoding = null,
                    ?string $contentDisposition = null,
                    ?string $cacheControl = null,
                    array $userMetadata = [],
                    ?string $checksumCrc32 = null,
                    ?string $checksumCrc32c = null,
                    ?string $checksumSha1 = null,
                    ?string $checksumSha256 = null,
                ) use ($beforePutObjectMetadata): void {
                    $beforePutObjectMetadata();
                    $this->metadata->putObjectMetadata(
                        $bucket,
                        $key,
                        $ownerId,
                        $size,
                        $etag,
                        $contentType,
                        $storagePath,
                        $storageClass,
                        $contentEncoding,
                        $contentDisposition,
                        $cacheControl,
                        $userMetadata,
                        $checksumCrc32,
                        $checksumCrc32c,
                        $checksumSha1,
                        $checksumSha256,
                    );
                },
            );
        }

        return $proxy;
    }

    private static function assertPathMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            $storage->getObjectByPath($path);
            self::fail('Uncommitted physical object was not deleted.');
        } catch (NoSuchKeyException) {
        }
    }
}
