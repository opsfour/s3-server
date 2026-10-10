<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Bucket\CreateBucketHandler;
use OpsFour\S3Server\Handler\Bucket\DeleteBucketHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketLifecycleCommitAuthorizationTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-bucket-lifecycle-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    #[DataProvider('deleteUploadCounts')]
    public function test_delete_bucket_rechecks_identity_deny_before_both_commit_paths(int $uploadCount): void
    {
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        for ($index = 0; $index < $uploadCount; $index++) {
            $this->metadata->createMultipartUpload(sprintf('upload-%04d', $index), 'bucket', sprintf('key-%04d', $index), 'owner');
        }
        $storage = $this->createMock(StorageBackend::class);
        $request = $this->request('DELETE', 'bucket', S3Operation::DeleteBucket);

        try {
            $this->handleWithMiddleware(
                $request,
                new DeleteBucketHandler($this->metadata, $storage),
                fn() => $this->metadata->putAccountPolicy('owner', self::deny('s3:DeleteBucket', 'bucket')),
            );
            self::fail('The newly effective identity Deny must block bucket deletion.');
        } catch (AccessDeniedException) {
            self::assertNotNull($this->metadata->getBucket('bucket'));
            self::assertCount($uploadCount, $this->metadata->listMultipartUploads('bucket', maxUploads: 1000)['uploads']);
        }
    }

    /** @return iterable<string, array{int}> */
    public static function deleteUploadCounts(): iterable
    {
        yield 'inline cleanup' => [0];
        yield 'queued cleanup' => [257];
    }

    public function test_create_bucket_rechecks_identity_deny_after_storage_provisioning(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('createBucket')->with('new-bucket')
            ->willReturnCallback(function (): void {
                $this->metadata->putAccountPolicy('owner', self::deny('s3:CreateBucket', 'new-bucket'));
            });
        $request = $this->request('PUT', 'new-bucket', S3Operation::CreateBucket);

        try {
            $this->handleWithMiddleware(
                $request,
                new CreateBucketHandler($this->metadata, $storage, new S3ServerConfig(storagePath: sys_get_temp_dir())),
            );
            self::fail('The newly effective identity Deny must block bucket creation.');
        } catch (AccessDeniedException) {
            self::assertNull($this->metadata->getBucket('new-bucket'));
        }
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $bucket, S3Operation $operation): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            $method,
            Http::new('http://127.0.0.1/' . $bucket),
        );
        $request->setAttribute('s3.bucket', $bucket);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', $operation);

        return $request;
    }

    private function handleWithMiddleware(Request $request, RequestHandler $handler, ?\Closure $afterAuthorization = null): Response
    {
        return (new PolicyEnforcementMiddleware($this->metadata))->handleRequest(
            $request,
            new class ($this->metadata, $handler, $afterAuthorization) implements RequestHandler {
                public function __construct(
                    private readonly SqliteMetadataStore $metadata,
                    private readonly RequestHandler $handler,
                    private readonly ?\Closure $afterAuthorization,
                ) {}

                public function handleRequest(Request $request): Response
                {
                    return (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                        $request,
                        new class ($this->handler, $this->afterAuthorization) implements RequestHandler {
                            public function __construct(
                                private readonly RequestHandler $handler,
                                private readonly ?\Closure $afterAuthorization,
                            ) {}

                            public function handleRequest(Request $request): Response
                            {
                                ($this->afterAuthorization ?? static function (): void {})();

                                return $this->handler->handleRequest($request);
                            }
                        },
                    );
                }
            },
        );
    }

    private static function deny(string $action, string $bucket): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny',
                'Action' => $action,
                'Resource' => 'arn:aws:s3:::' . $bucket,
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
