<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketDefaultEncryptionCutoverTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-encryption-cutover-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'target', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    #[DataProvider('versioningModes')]
    public function test_put_started_before_default_encryption_cannot_commit_plaintext_and_retry_is_encrypted(bool $versioned): void
    {
        if ($versioned) {
            $this->metadata->setBucketVersioning('target', 'Enabled');
        }
        $stagedPath = null;
        $storage = new CallbackStorageBackend($this->storage, afterPut: function (StorageWriteResult $write) use (&$stagedPath): void {
            $stagedPath = $write->path;
            self::assertSame('private payload', \Amp\ByteStream\buffer($this->storage->getObjectByPath($write->path)));
            $this->metadata->putBucketEncryption('target', 'AES256', null, false);
        });

        try {
            (new PutObjectHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('PUT', '/target/put.txt', 'private payload'));
            self::fail('A plaintext write must not commit after bucket default encryption becomes active.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'put.txt'));
            self::assertNotNull($stagedPath);
            $this->assertPathDeleted($stagedPath);
        }

        $response = (new PutObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('PUT', '/target/put.txt', 'private payload'));
        self::assertSame(200, $response->getStatus());
        self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption'));
        $this->assertEncryptedObject('put.txt', 'private payload');
    }

    #[DataProvider('versioningModes')]
    public function test_copy_started_before_default_encryption_cannot_commit_plaintext_and_retry_is_encrypted(bool $versioned): void
    {
        if ($versioned) {
            $this->metadata->setBucketVersioning('target', 'Enabled');
        }
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $source = $this->storage->putObject('source', 'original.txt', new ReadableBuffer('copy payload'));
        $this->metadata->putObjectMetadata('source', 'original.txt', 'owner', 12, '"' . md5('copy payload') . '"', 'text/plain', $source->path);
        $stagedPath = null;
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback($this->storage->getObjectByPath(...));
        $storage->method('putObject')->willReturnCallback(function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $body) use (&$stagedPath): StorageWriteResult {
            $write = $this->storage->putObject($bucket, $key, $body);
            $stagedPath = $write->path;
            self::assertSame('copy payload', \Amp\ByteStream\buffer($this->storage->getObjectByPath($write->path)));
            $this->metadata->putBucketEncryption('target', 'AES256', null, false);

            return $write;
        });
        $storage->method('deleteObjectByPath')->willReturnCallback($this->storage->deleteObjectByPath(...));

        try {
            (new CopyObjectHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request('PUT', '/target/copy.txt', headers: ['x-amz-copy-source' => '/source/original.txt']));
            self::fail('A plaintext copy must not commit after bucket default encryption becomes active.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copy.txt'));
            self::assertNotNull($stagedPath);
            $this->assertPathDeleted($stagedPath);
            self::assertSame('copy payload', \Amp\ByteStream\buffer($this->storage->getObjectByPath($source->path)));
        }

        $response = (new CopyObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('PUT', '/target/copy.txt', headers: ['x-amz-copy-source' => '/source/original.txt']));
        self::assertSame(200, $response->getStatus());
        self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption'));
        $this->assertEncryptedObject('copy.txt', 'copy payload');
    }

    public function test_multipart_initiation_cannot_store_plaintext_format_after_default_encryption_activates(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            \OpsFour\S3Server\Metadata\MultipartUploadTierStore::class,
        ]);
        if (! $metadata instanceof MetadataStore) {
            throw new \LogicException('The multipart cutover mock must implement MetadataStore.');
        }
        $metadata->method('getBucket')->willReturnCallback($this->metadata->getBucket(...));
        $metadata->method('getBucketEncryption')->willReturnCallback($this->metadata->getBucketEncryption(...));
        $metadata->method('transaction')->willReturnCallback($this->metadata->transaction(...));
        $metadata->method('lockOwnerForUpdate')->willReturnCallback($this->metadata->lockOwnerForUpdate(...));
        $metadata->method('getPublicAccessBlock')->willReturnCallback(function (): ?array {
            $this->metadata->putBucketEncryption('target', 'AES256', null, false);

            return null;
        });
        $metadata->expects(self::never())->method('createMultipartUploadWithTier');

        try {
            (new CreateMultipartUploadHandler($metadata, $this->encryption))
                ->handleRequest($this->request('POST', '/target/part.txt?uploads'));
            self::fail('A plaintext multipart upload must not be initiated after default SSE becomes active.');
        } catch (OperationAbortedException) {
            self::assertSame([], $this->metadata->listMultipartUploads('target')['uploads']);
        }

        $response = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/target/part.txt?uploads'));
        self::assertSame(200, $response->getStatus());
        self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($response->getBody())))->UploadId;
        $upload = $this->metadata->getMultipartUpload($uploadId);
        self::assertNotNull($upload);
        self::assertSame('AES256', $upload['user_metadata']['__sse-algorithm'] ?? null);
    }

    private function assertEncryptedObject(string $key, string $plaintext): void
    {
        $object = $this->metadata->getObjectMetadata('target', $key);
        self::assertNotNull($object);
        self::assertSame('AES256', $object->userMetadata['__sse-algorithm'] ?? null);
        $path = $object->systemMetadata['storagePath'] ?? null;
        self::assertIsString($path);
        self::assertNotSame($plaintext, \Amp\ByteStream\buffer($this->storage->getObjectByPath($path)));
        $response = (new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/target/' . $key));
        self::assertSame($plaintext, \Amp\ByteStream\buffer($response->getBody()));
    }

    /** @return iterable<string, array{bool}> */
    public static function versioningModes(): iterable
    {
        yield 'unversioned' => [false];
        yield 'versioned' => [true];
    }

    private function assertPathDeleted(string $path): void
    {
        try {
            $this->storage->getObjectByPath($path);
            self::fail('Uncommitted plaintext storage path survived the rejected write.');
        } catch (NoSuchKeyException) {
            return;
        }
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1' . $path), $headers, $body);
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', substr((string) parse_url($path, PHP_URL_PATH), strlen('/target/')));
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', $method === 'POST'
            ? S3Operation::CreateMultipartUpload
            : ($method === 'GET' ? S3Operation::GetObject
                : (isset($headers['x-amz-copy-source']) ? S3Operation::CopyObject : S3Operation::PutObject)));

        return $request;
    }
}
