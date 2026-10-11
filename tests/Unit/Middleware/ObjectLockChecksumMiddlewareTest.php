<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\ContextWorkerPool;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\ObjectLockChecksumMiddleware;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;
use OpsFour\S3Server\ObjectLock\ObjectLockConfigurationSnapshot;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class ObjectLockChecksumMiddlewareTest extends TestCase
{
    private string $databasePath = '';

    private string $spoolPath = '';

    private SqliteMetadataStore $metadata;

    private ContextWorkerPool $pool;

    protected function setUp(): void
    {
        $suffix = bin2hex(random_bytes(6));
        $this->databasePath = sys_get_temp_dir() . '/s3-object-lock-checksum-' . $suffix . '.sqlite';
        $this->spoolPath = sys_get_temp_dir() . '/s3-object-lock-checksum-' . $suffix;
        self::assertTrue(mkdir($this->spoolPath, 0o700));
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->putObjectLockConfig('bucket', ['objectLockEnabled' => 'Enabled']);
        $this->pool = new ContextWorkerPool(1);
    }

    protected function tearDown(): void
    {
        $this->pool->shutdown();
        @rmdir($this->spoolPath);
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    /** @param array<non-empty-string, string> $headers */
    #[DataProvider('explicitObjectLockHeaders')]
    public function test_put_object_with_explicit_object_lock_requires_a_checksum(array $headers): void
    {
        $request = $this->request(S3Operation::PutObject, 'PUT', '/bucket/key', $headers);

        try {
            $this->dispatch($request);
            self::fail('An Object Lock PutObject without a checksum reached storage dispatch.');
        } catch (InvalidRequestException $error) {
            self::assertSame('InvalidRequest', $error->getErrorCode());
            self::assertSame(400, $error->getHttpStatus());
        }
    }

    /** @return iterable<string, array{array<non-empty-string, string>}> */
    public static function explicitObjectLockHeaders(): iterable
    {
        yield 'retention' => [[
            'x-amz-object-lock-mode' => 'GOVERNANCE',
            'x-amz-object-lock-retain-until-date' => '2099-01-01T00:00:00Z',
        ]];
        yield 'legal hold' => [['x-amz-object-lock-legal-hold' => 'ON']];
    }

    public function test_put_object_with_bucket_default_retention_requires_and_validates_sdk_checksum(): void
    {
        $this->enableDefaultRetention();
        $body = 'locked payload';

        $missing = $this->request(S3Operation::PutObject, 'PUT', '/bucket/key', body: $body);
        $this->expectMissingChecksum($missing);

        $checksum = base64_encode(hash('crc32b', $body, true));
        $valid = $this->request(S3Operation::PutObject, 'PUT', '/bucket/key', [
            'x-amz-sdk-checksum-algorithm' => 'CRC32',
            'x-amz-checksum-crc32' => $checksum,
        ], $body);
        $seenBody = null;
        $response = $this->dispatch($valid, static function (Request $request) use (&$seenBody): void {
            $seenBody = buffer($request->getBody());
        });

        self::assertSame(204, $response->getStatus());
        self::assertSame($body, $seenBody);
    }

    public function test_object_lock_enabled_without_retention_does_not_require_a_checksum(): void
    {
        $response = $this->dispatch($this->request(S3Operation::PutObject, 'PUT', '/bucket/key'));

        self::assertSame(204, $response->getStatus());
    }

    public function test_browser_post_with_default_retention_uses_the_object_lock_checksum_gate(): void
    {
        $this->enableDefaultRetention();
        $body = 'browser payload';
        $this->expectMissingChecksum(
            $this->request(S3Operation::PostObject, 'POST', '/bucket', body: $body),
        );

        $checksum = base64_encode(hash('sha256', $body, true));
        $response = $this->dispatch($this->request(S3Operation::PostObject, 'POST', '/bucket', [
            'x-amz-checksum-sha256' => $checksum,
        ], $body));

        self::assertSame(204, $response->getStatus());
    }

    public function test_unverified_streaming_sdk_checksum_declaration_is_rejected(): void
    {
        $this->enableDefaultRetention();
        $headers = [
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-trailer' => 'X-Amz-Checksum-Sha256',
            'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
        ];

        $this->expectMissingChecksum(
            $this->request(S3Operation::PutObject, 'PUT', '/bucket/key', $headers),
        );

        $headers['x-amz-trailer'] = 'x-amz-checksum-crc32';
        $this->expectMissingChecksum(
            $this->request(S3Operation::PutObject, 'PUT', '/bucket/key', $headers),
        );
    }

    public function test_verified_streaming_sdk_checksum_trailer_satisfies_presence_gate(): void
    {
        $this->enableDefaultRetention();
        $body = 'streamed payload';
        $request = $this->request(S3Operation::PutObject, 'PUT', '/bucket/key', [
            'x-amz-sdk-checksum-algorithm' => 'SHA256',
            'x-amz-trailer' => 'x-amz-checksum-sha256',
            'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
        ], $body);
        $request->setBody(new ReadableIterableStream((static function () use ($request, $body): iterable {
            yield $body;
            $request->setHeader('x-amz-checksum-sha256', base64_encode(hash('sha256', $body, true)));
            $request->setAttribute('s3.verifiedChecksumTrailers', ['x-amz-checksum-sha256']);
        })()));
        $seenBody = null;

        $response = $this->dispatch($request, static function (Request $request) use (&$seenBody): void {
            $seenBody = buffer($request->getBody());
        });

        self::assertSame(204, $response->getStatus());
        self::assertSame($body, $seenBody);
    }

    /**
     * @param non-empty-string $method
     * @param non-empty-string $path
     */
    #[DataProvider('copyAndControlOperations')]
    public function test_copy_and_multipart_control_operations_do_not_require_object_payload_checksum(
        S3Operation $operation,
        string $method,
        string $path,
    ): void {
        $this->enableDefaultRetention();

        $response = $this->dispatch($this->request($operation, $method, $path));

        self::assertSame(204, $response->getStatus());
    }

    /** @return iterable<string, array{S3Operation, non-empty-string, non-empty-string}> */
    public static function copyAndControlOperations(): iterable
    {
        yield 'CopyObject' => [S3Operation::CopyObject, 'PUT', '/bucket/key'];
        yield 'CreateMultipartUpload' => [S3Operation::CreateMultipartUpload, 'POST', '/bucket/key?uploads'];
        yield 'UploadPartCopy' => [S3Operation::UploadPartCopy, 'PUT', '/bucket/key?uploadId=id&partNumber=1'];
        yield 'CompleteMultipartUpload' => [S3Operation::CompleteMultipartUpload, 'POST', '/bucket/key?uploadId=id'];
    }

    public function test_upload_part_with_saved_object_lock_parameters_requires_a_checksum(): void
    {
        $this->createUpload('locked', [
            '__object-lock-mode' => 'GOVERNANCE',
            '__object-lock-retain-until-date' => '2099-01-01T00:00:00Z',
        ]);

        $this->expectMissingChecksum($this->uploadPartRequest('locked'));
    }

    public function test_upload_part_with_effective_bucket_default_retention_requires_a_checksum(): void
    {
        $this->enableDefaultRetention();
        $this->createUpload('default');

        $this->expectMissingChecksum($this->uploadPartRequest('default'));
    }

    public function test_unlocked_upload_part_and_stale_generation_keep_handler_error_precedence(): void
    {
        $this->createUpload('plain');
        self::assertSame(204, $this->dispatch($this->uploadPartRequest('plain'))->getStatus());

        $stale = $this->uploadPartRequest('plain');
        $stale->setAttribute('s3.cachedBucketGeneration', str_repeat('f', 32));
        self::assertSame(204, $this->dispatch($stale)->getStatus());
    }

    public function test_configuration_change_during_put_object_body_processing_aborts_before_handler_write(): void
    {
        $request = $this->request(S3Operation::PutObject, 'PUT', '/bucket/key');
        $inner = new class ($this->metadata) implements RequestHandler {
            public function __construct(private readonly SqliteMetadataStore $metadata) {}

            public function handleRequest(Request $request): Response
            {
                $this->metadata->putObjectLockConfig('bucket', [
                    'objectLockEnabled' => 'Enabled',
                    'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1]],
                ]);
                (new ObjectLockRequestApplier($this->metadata))->validate($request, 'bucket');

                return new Response(204);
            }
        };

        $this->expectException(OperationAbortedException::class);
        (new ObjectLockChecksumMiddleware($this->metadata))->handleRequest($request, $inner);
    }

    public function test_configuration_change_before_upload_part_dispatch_aborts_before_storage_write(): void
    {
        $this->createUpload('race');
        $request = $this->uploadPartRequest('race');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putPart');
        $inner = new class ($this->metadata, $storage) implements RequestHandler {
            public function __construct(
                private readonly SqliteMetadataStore $metadata,
                private readonly StorageBackend $storage,
            ) {}

            public function handleRequest(Request $request): Response
            {
                $this->metadata->putObjectLockConfig('bucket', [
                    'objectLockEnabled' => 'Enabled',
                    'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1]],
                ]);

                return (new UploadPartHandler($this->metadata, $this->storage))->handleRequest($request);
            }
        };

        $this->expectException(OperationAbortedException::class);
        (new ObjectLockChecksumMiddleware($this->metadata))->handleRequest($request, $inner);
    }

    public function test_configuration_change_during_upload_part_body_write_aborts_metadata_commit(): void
    {
        $this->createUpload('mid-stream-race');
        $request = $this->uploadPartRequest('mid-stream-race');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putPart')->willReturnCallback(function (): StorageWriteResult {
            $this->enableDefaultRetention();

            return new StorageWriteResult('uncommitted-part', 7, md5('payload'));
        });
        $storage->expects(self::once())->method('deleteObjectByPath')
            ->with('uncommitted-part', 'bucket');
        $inner = new UploadPartHandler($this->metadata, $storage);

        try {
            (new ObjectLockChecksumMiddleware($this->metadata))->handleRequest($request, $inner);
            self::fail('A part committed after its Object Lock checksum contract changed during storage write.');
        } catch (OperationAbortedException) {
            self::assertSame([], $this->metadata->getParts('mid-stream-race'));
        }
    }

    /** @param array<string, string> $metadata */
    #[DataProvider('legacyLockedUploadMetadata')]
    public function test_legacy_locked_upload_part_is_rejected_without_configuration_binding(array $metadata): void
    {
        $this->createLegacyUpload('legacy-locked', $metadata);

        $this->expectException(OperationAbortedException::class);
        (new ObjectLockChecksumMiddleware($this->metadata))->handleRequest(
            $this->uploadPartRequest('legacy-locked'),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    throw new \LogicException('Legacy locked upload reached dispatch.');
                }
            },
        );
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function legacyLockedUploadMetadata(): iterable
    {
        yield 'retention' => [[
            '__object-lock-mode' => 'GOVERNANCE',
            '__object-lock-retain-until-date' => '2099-01-01T00:00:00Z',
        ]];
        yield 'legal hold' => [['__object-lock-legal-hold' => 'ON']];
    }

    public function test_legacy_upload_part_is_rejected_when_default_retention_is_now_active(): void
    {
        $this->createLegacyUpload('legacy-default');
        $this->enableDefaultRetention();

        $this->expectException(OperationAbortedException::class);
        (new ObjectLockChecksumMiddleware($this->metadata))->handleRequest(
            $this->uploadPartRequest('legacy-default'),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    throw new \LogicException('Legacy upload subject to default retention reached dispatch.');
                }
            },
        );
    }

    public function test_plain_legacy_upload_without_effective_lock_remains_compatible(): void
    {
        $this->createLegacyUpload('legacy-plain');

        self::assertSame(204, $this->dispatch($this->uploadPartRequest('legacy-plain'))->getStatus());
    }

    private function enableDefaultRetention(): void
    {
        $this->metadata->putObjectLockConfig('bucket', [
            'objectLockEnabled' => 'Enabled',
            'rule' => ['defaultRetention' => ['mode' => 'GOVERNANCE', 'days' => 1]],
        ]);
    }

    /** @param array<string, string> $userMetadata */
    private function createUpload(string $uploadId, array $userMetadata = []): void
    {
        $userMetadata[ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY]
            = ObjectLockConfigurationSnapshot::fingerprint(
                $this->metadata->getObjectLockConfig('bucket'),
            );
        $this->metadata->createMultipartUpload(
            $uploadId,
            'bucket',
            'key',
            'owner',
            'application/octet-stream',
            $userMetadata,
        );
    }

    /** @param array<string, string> $userMetadata */
    private function createLegacyUpload(string $uploadId, array $userMetadata = []): void
    {
        $this->metadata->createMultipartUpload(
            $uploadId,
            'bucket',
            'key',
            'owner',
            'application/octet-stream',
            $userMetadata,
        );
    }

    private function uploadPartRequest(string $uploadId): Request
    {
        return $this->request(
            S3Operation::UploadPart,
            'PUT',
            '/bucket/key?uploadId=' . rawurlencode($uploadId) . '&partNumber=1',
        );
    }

    private function expectMissingChecksum(Request $request): void
    {
        try {
            $this->dispatch($request);
            self::fail('An Object Lock payload without a checksum reached storage dispatch.');
        } catch (InvalidRequestException $error) {
            self::assertSame('InvalidRequest', $error->getErrorCode());
            self::assertSame(400, $error->getHttpStatus());
        }
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param non-empty-string $method
     * @param non-empty-string $path
     */
    private function request(
        S3Operation $operation,
        string $method,
        string $path,
        array $headers = [],
        string $body = 'payload',
    ): Request {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute(
            's3.cachedBucketGeneration',
            $this->metadata->getBucket('bucket')?->generation,
        );

        return $request;
    }

    private function dispatch(Request $request, ?\Closure $onDispatch = null): Response
    {
        $handler = new class ($onDispatch) implements RequestHandler {
            public function __construct(private readonly ?\Closure $onDispatch) {}

            public function handleRequest(Request $request): Response
            {
                if ($this->onDispatch !== null) {
                    ($this->onDispatch)($request);
                }

                return new Response(204);
            }
        };

        return \Amp\Http\Server\Middleware\stackMiddleware(
            $handler,
            new ObjectLockChecksumMiddleware($this->metadata),
            new ContentMd5Middleware(new RequestBodySpool($this->pool, $this->spoolPath)),
        )->handleRequest($request);
    }
}
