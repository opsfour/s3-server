<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Multipart\MultipartAclSnapshot;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ObjectPartLayoutCapabilityAdmissionTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function capabilityStates(): iterable
    {
        yield 'absent' => [false];
        yield 'disabled' => [true];
    }

    #[DataProvider('capabilityStates')]
    public function test_put_fails_before_storage_without_enabled_layout_capability(bool $disabled): void
    {
        $metadata = $this->metadataMock($disabled);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');
        $request = $this->request('PUT', '/bucket/key', '', ['content-length' => '0']);
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        $this->expectCapabilityFailure();
        (new PutObjectHandler($metadata, $storage))->handleRequest($request);
    }

    #[DataProvider('capabilityStates')]
    public function test_copy_fails_before_destination_storage_without_enabled_layout_capability(bool $disabled): void
    {
        $metadata = $this->metadataMock($disabled);
        $metadata->method('getBucket')->willReturnCallback(static fn(string $bucket): BucketInfo => new BucketInfo($bucket, 'owner'));
        $metadata->method('getBucketOwner')->willReturn('owner');
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo(
            'source',
            'key',
            0,
            '"d41d8cd98f00b204e9800998ecf8427e"',
            ownerId: 'owner',
            systemMetadata: ['storagePath' => 'source/path'],
        ));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');
        $request = $this->request('PUT', '/bucket/key', '', ['x-amz-copy-source' => '/source/key']);
        $request->setAttribute('s3.operation', S3Operation::CopyObject);

        $this->expectCapabilityFailure();
        (new CopyObjectHandler($metadata, $storage))->handleRequest($request);
    }

    #[DataProvider('capabilityStates')]
    public function test_multipart_completion_fails_before_assembly_without_enabled_layout_capability(bool $disabled): void
    {
        $real = new SqliteMetadataStore(':memory:');
        $real->initialize();
        $real->createBucket('owner', 'bucket', 'us-east-1');
        $uploadId = bin2hex(random_bytes(16));
        $real->createMultipartUpload($uploadId, 'bucket', 'key', 'owner', userMetadata: [
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '[]',
            MultipartAclSnapshot::METADATA_KEY => json_encode([[
                'granteeType' => 'CanonicalUser',
                'granteeId' => 'owner',
                'permission' => 'FULL_CONTROL',
            ]], JSON_THROW_ON_ERROR),
        ]);
        $etag = md5('part');
        $real->putPart($uploadId, 1, $etag, 4, 'parts/one');
        $metadata = $this->multipartMetadataProxy($real, $disabled);
        $storage = $this->createMock(RecoverableMultipartAssemblyBackend::class);
        $storage->expects(self::never())->method('planMultipartCompletionPaths');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>' . $etag . '</ETag></Part></CompleteMultipartUpload>';

        $this->expectCapabilityFailure();
        (new CompleteMultipartUploadHandler($metadata, $storage))->handleRequest(
            $this->request('POST', '/bucket/key?uploadId=' . $uploadId, $body),
        );
    }

    #[DataProvider('capabilityStates')]
    public function test_get_attributes_fails_before_response_without_enabled_layout_capability(bool $disabled): void
    {
        $metadata = $this->metadataMock($disabled);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getObjectMetadata')->willReturn(new ObjectInfo('bucket', 'key', 0, '"etag"'));
        $request = $this->request('GET', '/bucket/key?attributes', headers: ['x-amz-object-attributes' => 'ObjectSize']);

        $this->expectCapabilityFailure();
        (new GetObjectAttributesHandler($metadata))->handleRequest($request);
    }

    #[DataProvider('capabilityStates')]
    public function test_access_log_flush_fails_before_storage_without_enabled_layout_capability(bool $disabled): void
    {
        $metadata = $this->metadataMock($disabled);
        $metadata->method('getBucket')->willReturnCallback(static fn(string $bucket): BucketInfo => new BucketInfo($bucket, 'owner'));
        $metadata->method('getBucketLogging')->willReturn(['targetBucket' => 'logs', 'targetPrefix' => 'access/']);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');
        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('bucket', 'key', 'GetObject', 200, 0);

        $this->expectCapabilityFailure();
        $writer->flush();
    }

    private function expectCapabilityFailure(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Metadata store does not support authoritative object part layouts.');
    }

    private function metadataMock(bool $disabled): MetadataStore&MockObject
    {
        if (! $disabled) {
            return $this->createMock(MetadataStore::class);
        }
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(false);

        return $metadata;
    }

    private function multipartMetadataProxy(SqliteMetadataStore $real, bool $disabled): MetadataStore&MultipartWriteIntentStore
    {
        $interfaces = [MetadataStore::class, MultipartWriteIntentStore::class];
        if ($disabled) {
            $interfaces[] = ObjectPartLayoutStore::class;
        }
        $metadata = $this->createMockForIntersectionOfInterfaces($interfaces);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof MultipartWriteIntentStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $seen = [];
        foreach ([MetadataStore::class, MultipartWriteIntentStore::class] as $interface) {
            foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
                if (isset($seen[$method->name])) {
                    continue;
                }
                $seen[$method->name] = true;
                $metadata->method($method->name)->willReturnCallback(fn(mixed ...$arguments): mixed => $real->{$method->name}(...$arguments));
            }
        }
        if ($disabled) {
            if (! $metadata instanceof ObjectPartLayoutStore) {
                throw new \LogicException('Disabled layout mock is missing its capability interface.');
            }
            $metadata->method('supportsObjectPartLayouts')->willReturn(false);
        }

        return $metadata;
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     * @param array<non-empty-string, array<string>|string> $headers
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            new ReadableBuffer($body),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
