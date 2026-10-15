<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class CopyObjectSelfEncryptionTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
        $this->encryption = new EncryptionService(
            new ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
        );
    }

    public function test_plain_self_copy_can_enable_explicit_sse_s3(): void
    {
        $this->storePlain('payload');

        $response = (new CopyObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request(['x-amz-server-side-encryption' => 'AES256']));

        self::assertSame(200, $response->getStatus());
        $this->assertSseS3Object('payload');
    }

    public function test_plain_self_copy_can_apply_new_bucket_default_sse_s3(): void
    {
        $this->storePlain('payload');
        $this->metadata->putBucketEncryption('bucket', 'AES256');

        $response = (new CopyObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        $this->assertSseS3Object('payload');
    }

    public function test_sse_c_self_copy_can_rotate_customer_key(): void
    {
        $sourceKey = random_bytes(32);
        $destinationKey = random_bytes(32);
        $encrypted = $this->encryption->encryptSseC('payload', $sourceKey);
        $write = $this->storage->putObject('bucket', 'key', new ReadableBuffer($encrypted['ciphertext']));
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            7,
            '"' . md5('payload') . '"',
            'text/plain',
            $write->path,
            userMetadata: [
                '__sse-algorithm' => 'SSE-C',
                '__sse-iv' => $encrypted['iv'],
                '__sse-tag' => $encrypted['tag'],
                '__sse-customer-key-md5' => base64_encode(md5($sourceKey, true)),
            ],
        );

        $response = (new CopyObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request([
                ...$this->customerKeyHeaders($sourceKey, true),
                ...$this->customerKeyHeaders($destinationKey, false),
            ]));

        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame(base64_encode(md5($destinationKey, true)), $object->userMetadata['__sse-customer-key-md5']);
        self::assertSame('payload', $this->encryption->decryptSseC(
            \Amp\ByteStream\buffer($this->storage->getObjectByPath($object->systemMetadata['storagePath'])),
            $destinationKey,
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        ));
    }

    public function test_unchanged_self_copy_is_rejected_before_storage_copy(): void
    {
        $this->storePlain('payload');
        $storage = new CallbackStorageBackend($this->storage);

        try {
            (new CopyObjectHandler($this->metadata, $storage, $this->encryption))
                ->handleRequest($this->request());
            self::fail('An unchanged self-copy must be rejected.');
        } catch (InvalidRequestException) {
            self::assertSame(0, $storage->copyCalls);
            self::assertSame(0, $storage->putCalls);
        }
    }

    private function storePlain(string $payload): void
    {
        $write = $this->storage->putObject('bucket', 'key', new ReadableBuffer($payload));
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
        );
    }

    private function assertSseS3Object(string $plaintext): void
    {
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('AES256', $object->userMetadata['__sse-algorithm']);
        self::assertSame($plaintext, $this->encryption->decryptSseS3(
            \Amp\ByteStream\buffer($this->storage->getObjectByPath($object->systemMetadata['storagePath'])),
            $object->userMetadata['__sse-key'],
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        ));
    }

    /** @return array<non-empty-string, string> */
    private function customerKeyHeaders(string $key, bool $copySource): array
    {
        $prefix = $copySource ? 'x-amz-copy-source-' : 'x-amz-';

        return [
            $prefix . 'server-side-encryption-customer-algorithm' => 'AES256',
            $prefix . 'server-side-encryption-customer-key' => base64_encode($key),
            $prefix . 'server-side-encryption-customer-key-md5' => base64_encode(md5($key, true)),
        ];
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['x-amz-copy-source' => '/bucket/key', ...$headers],
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
