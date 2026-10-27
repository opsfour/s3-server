<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Quota\QuotaProvider;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class MultipartOwnershipSnapshotTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-multipart-ownership-' . bin2hex(random_bytes(6)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
    }

    public function test_create_rejects_ownership_mode_change_before_locked_persistence(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MultipartUploadTierStore::class,
        ]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof MultipartUploadTierStore) {
            throw new \LogicException('Intersection mock does not implement multipart metadata contracts.');
        }
        $bucket = new BucketInfo('bucket', 'bucket-owner', generation: str_repeat('a', 32));
        $metadata->method('getBucket')->willReturn($bucket);
        $metadata->expects(self::exactly(2))
            ->method('getBucketOwnershipControls')
            ->with('bucket')
            ->willReturnOnConsecutiveCalls(
                BucketOwnership::OBJECT_WRITER,
                BucketOwnership::BUCKET_OWNER_PREFERRED,
            );
        $metadata->method('getObjectLockConfig')->willReturn(null);
        $metadata->method('getBucketEncryption')->willReturn(null);
        $metadata->method('getPublicAccessBlock')->willReturn(null);
        $metadata->method('transaction')->willReturnCallback(
            static fn(callable $callback): mixed => $callback(),
        );
        $metadata->method('lockOwnerForUpdate');
        $metadata->expects(self::never())->method('createMultipartUploadWithTier');

        $storage = new InMemoryBackend();

        $this->expectException(OperationAbortedException::class);
        (new CreateMultipartUploadHandler($metadata, storage: $storage))->handleRequest(
            $this->request('POST', '/bucket/key?uploads', 'writer', S3Operation::CreateMultipartUpload),
        );
    }

    public function test_preferred_transfer_final_quota_race_rejects_and_cleans_before_successful_retry(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->putBucketOwnershipControls('bucket', BucketOwnership::BUCKET_OWNER_PREFERRED);
        $baseStorage = new InMemoryBackend();
        $baseStorage->createBucket('bucket');

        $created = (new CreateMultipartUploadHandler($metadata, storage: $baseStorage))->handleRequest(
            $this->request(
                'POST',
                '/bucket/key?uploads',
                'writer',
                S3Operation::CreateMultipartUpload,
                headers: ['x-amz-acl' => 'bucket-owner-full-control'],
            ),
        );
        $uploadId = (string) (new \SimpleXMLElement(ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($metadata, $baseStorage))->handleRequest(
            $this->request(
                'PUT',
                '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                'writer',
                S3Operation::UploadPart,
                '12345',
                ['content-length' => '5'],
            ),
        );
        $etag = $part->getHeader('etag');
        self::assertNotNull($etag);
        $metadata->deleteBucketOwnershipControls('bucket');

        $provider = new class implements QuotaProvider {
            public int $bucketOwnerLimit = 5;

            public function quotaForOwner(string $ownerId): QuotaConfig
            {
                return $ownerId === 'bucket-owner'
                    ? new QuotaConfig(maxBytesPerOwner: $this->bucketOwnerLimit)
                    : new QuotaConfig();
            }

            public function hasQuotaForOwner(string $ownerId): bool
            {
                return $ownerId === 'bucket-owner';
            }
        };
        $storage = new CallbackStorageBackend(
            $baseStorage,
            afterAssemble: static function () use ($metadata): void {
                $metadata->putObjectMetadata(
                    'bucket',
                    'racing-winner',
                    'bucket-owner',
                    1,
                    '"winner"',
                    'text/plain',
                    'winner-path',
                );
            },
        );
        $handler = new CompleteMultipartUploadHandler(
            $metadata,
            $storage,
            quotas: new QuotaManager($metadata, $provider),
        );
        $completionBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1)
            . '</ETag></Part></CompleteMultipartUpload>';

        try {
            $handler->handleRequest($this->request(
                'POST',
                '/bucket/key?uploadId=' . $uploadId,
                'writer',
                S3Operation::CompleteMultipartUpload,
                $completionBody,
            ));
            self::fail('The locked snapshot-principal quota check must reject the post-assembly race.');
        } catch (QuotaExceededException) {
            self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
            self::assertSame([], $metadata->getAcl('object', 'bucket/key'));
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $metadata->getParts($uploadId));
            self::assertNotNull($storage->lastWrite);
            try {
                $baseStorage->getObjectByPath($storage->lastWrite->path);
                self::fail('Rejected multipart assembly was not cleaned.');
            } catch (NoSuchKeyException) {
            }

            $attempt = (new \PDO('sqlite:' . $this->databasePath))
                ->query('SELECT attempt_id FROM s3_multipart_write_intents ORDER BY created_at DESC LIMIT 1');
            self::assertInstanceOf(\PDOStatement::class, $attempt);
            $attemptId = $attempt->fetchColumn();
            self::assertIsString($attemptId);
            self::assertSame('abandoned', $metadata->getMultipartWriteIntent($attemptId)?->state);
        }

        $provider->bucketOwnerLimit = 6;
        self::assertSame(200, $handler->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            'writer',
            S3Operation::CompleteMultipartUpload,
            $completionBody,
        ))->getStatus());
        self::assertSame('bucket-owner', $metadata->getObjectMetadata('bucket', 'key')?->ownerId);
        self::assertNull($metadata->getMultipartUpload($uploadId));
    }

    public function test_preferred_transfer_known_quota_failure_rejects_before_assembly(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $metadata->putBucketOwnershipControls('bucket', BucketOwnership::BUCKET_OWNER_PREFERRED);
        $baseStorage = new InMemoryBackend();
        $baseStorage->createBucket('bucket');
        $created = (new CreateMultipartUploadHandler($metadata, storage: $baseStorage))->handleRequest(
            $this->request(
                'POST',
                '/bucket/key?uploads',
                'writer',
                S3Operation::CreateMultipartUpload,
                headers: ['x-amz-acl' => 'bucket-owner-full-control'],
            ),
        );
        $uploadId = (string) (new \SimpleXMLElement(ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($metadata, $baseStorage))->handleRequest(
            $this->request(
                'PUT',
                '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                'writer',
                S3Operation::UploadPart,
                '12345',
                ['content-length' => '5'],
            ),
        );
        $metadata->deleteBucketOwnershipControls('bucket');
        $assembled = false;
        $storage = new CallbackStorageBackend(
            $baseStorage,
            afterAssemble: static function () use (&$assembled): void {
                $assembled = true;
            },
        );
        $provider = new class implements QuotaProvider {
            public function quotaForOwner(string $ownerId): QuotaConfig
            {
                return $ownerId === 'bucket-owner'
                    ? new QuotaConfig(maxBytesPerOwner: 4)
                    : new QuotaConfig();
            }

            public function hasQuotaForOwner(string $ownerId): bool
            {
                return $ownerId === 'bucket-owner';
            }
        };
        $handler = new CompleteMultipartUploadHandler(
            $metadata,
            $storage,
            quotas: new QuotaManager($metadata, $provider),
        );

        try {
            $handler->handleRequest($this->completionRequest($uploadId, (string) $part->getHeader('etag')));
            self::fail('Known insufficient quota must reject before multipart assembly.');
        } catch (QuotaExceededException) {
            self::assertFalse($assembled);
            self::assertNull($storage->lastWrite);
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
            $count = (new \PDO('sqlite:' . $this->databasePath))
                ->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $count);
            self::assertSame(0, (int) $count->fetchColumn());
        }
    }

    public function test_legacy_quota_preflight_resolves_current_mode_after_lock(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $baseStorage = new InMemoryBackend();
        $baseStorage->createBucket('bucket');
        $created = (new CreateMultipartUploadHandler($metadata, storage: $baseStorage))->handleRequest(
            $this->request('POST', '/bucket/key?uploads', 'writer', S3Operation::CreateMultipartUpload),
        );
        $uploadId = (string) (new \SimpleXMLElement(ByteStream\buffer($created->getBody())))->UploadId;
        $part = (new UploadPartHandler($metadata, $baseStorage))->handleRequest(
            $this->request(
                'PUT',
                '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                'writer',
                S3Operation::UploadPart,
                '12345',
                ['content-length' => '5'],
            ),
        );
        $upload = $metadata->getMultipartUpload($uploadId);
        self::assertNotNull($upload);
        unset($upload['user_metadata'][\OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot::METADATA_KEY]);
        $update = (new \PDO('sqlite:' . $this->databasePath))
            ->prepare('UPDATE s3_multipart_uploads SET user_metadata = ? WHERE upload_id = ?');
        self::assertTrue($update->execute([
            json_encode($upload['user_metadata'], JSON_THROW_ON_ERROR),
            $uploadId,
        ]));
        $assembled = false;
        $storage = new CallbackStorageBackend(
            $baseStorage,
            afterAssemble: static function () use (&$assembled): void {
                $assembled = true;
            },
        );
        $provider = new class ($metadata) implements QuotaProvider {
            private bool $ownershipChanged = false;

            public function __construct(private readonly SqliteMetadataStore $metadata) {}

            public function quotaForOwner(string $ownerId): QuotaConfig
            {
                return $ownerId === 'bucket-owner'
                    ? new QuotaConfig(maxBytesPerOwner: 4)
                    : new QuotaConfig();
            }

            public function hasQuotaForOwner(string $ownerId): bool
            {
                if (! $this->ownershipChanged) {
                    $this->metadata->putBucketOwnershipControls(
                        'bucket',
                        BucketOwnership::BUCKET_OWNER_ENFORCED,
                    );
                    $this->ownershipChanged = true;
                }

                return $ownerId === 'bucket-owner';
            }
        };
        $handler = new CompleteMultipartUploadHandler(
            $metadata,
            $storage,
            quotas: new QuotaManager($metadata, $provider),
        );

        try {
            $handler->handleRequest($this->completionRequest($uploadId, (string) $part->getHeader('etag')));
            self::fail('Legacy quota preflight must resolve the current mode after locking.');
        } catch (QuotaExceededException) {
            self::assertFalse($assembled);
            self::assertNull($storage->lastWrite);
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
        }
    }

    private function completionRequest(string $uploadId, string $etag): Request
    {
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1)
            . '</ETag></Part></CompleteMultipartUpload>';

        return $this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            'writer',
            S3Operation::CompleteMultipartUpload,
            $body,
        );
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(
        string $method,
        string $path,
        string $ownerId,
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
        $request->setAttribute('ownerId', $ownerId);
        $request->setAttribute('s3.operation', $operation);

        return $request;
    }
}
