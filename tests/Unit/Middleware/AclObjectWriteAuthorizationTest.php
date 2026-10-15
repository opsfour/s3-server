<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AclObjectWriteAuthorizationTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-acl-object-write-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'existing.txt',
            ownerId: 'bucket-owner',
            size: 4,
            etag: '"etag"',
            contentType: 'text/plain',
            storagePath: 'bucket/existing.txt',
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    #[DataProvider('objectAclWriteCasesProvider')]
    public function test_object_acl_cannot_authorize_object_write(
        string $permission,
        S3Operation $operation,
        string $query,
    ): void {
        $this->metadata->putAcl(
            'object',
            ObjectVersionResolver::aclResourceName('bucket', 'existing.txt', null),
            'bucket-owner',
            [[
                'granteeType' => 'CanonicalUser',
                'granteeId' => 'writer',
                'permission' => $permission,
            ]],
        );
        $this->metadata->putAcl('bucket', 'bucket', 'bucket-owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'bucket-owner',
            'permission' => 'FULL_CONTROL',
        ]]);

        $this->expectException(AccessDeniedException::class);

        (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $this->request($operation, $query),
            new AclObjectWriteTerminalHandler(),
        );
    }

    #[DataProvider('bucketAclWriteCasesProvider')]
    public function test_bucket_acl_authorizes_object_write(
        string $permission,
        S3Operation $operation,
        string $query,
    ): void {
        $this->metadata->putAcl(
            'object',
            ObjectVersionResolver::aclResourceName('bucket', 'existing.txt', null),
            'bucket-owner',
            [[
                'granteeType' => 'CanonicalUser',
                'granteeId' => 'bucket-owner',
                'permission' => 'FULL_CONTROL',
            ]],
        );
        $this->metadata->putAcl('bucket', 'bucket', 'bucket-owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'writer',
            'permission' => $permission,
        ]]);

        $response = (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $this->request($operation, $query, key: 'new.txt'),
            new AclObjectWriteTerminalHandler(),
        );

        self::assertSame(200, $response->getStatus());
    }

    #[DataProvider('publishedObjectWriteCasesProvider')]
    public function test_bucket_acl_write_cannot_replace_an_object_owned_by_another_account(
        S3Operation $operation,
        string $query,
        string $versioning,
    ): void {
        if ($versioning !== '') {
            $this->metadata->setBucketVersioning('bucket', $versioning);
        }
        $this->metadata->putAcl('bucket', 'bucket', 'bucket-owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'writer',
            'permission' => 'WRITE',
        ]]);

        $this->expectException(AccessDeniedException::class);

        (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $this->request($operation, $query),
            new AclObjectWriteTerminalHandler(),
        );
    }

    #[DataProvider('publishedObjectOperationsProvider')]
    public function test_bucket_acl_write_allows_the_current_object_owner_to_replace_its_object(
        S3Operation $operation,
        string $query,
    ): void {
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'writer.txt',
            ownerId: 'writer',
            size: 4,
            etag: '"etag"',
            contentType: 'text/plain',
            storagePath: 'bucket/writer.txt',
        );
        $this->metadata->putAcl('bucket', 'bucket', 'bucket-owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'writer',
            'permission' => 'WRITE',
        ]]);

        $response = (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $this->request($operation, $query, key: 'writer.txt'),
            new AclObjectWriteTerminalHandler(),
        );

        self::assertSame(200, $response->getStatus());
    }

    #[DataProvider('publishedObjectOperationsProvider')]
    public function test_bucket_owner_can_replace_an_existing_object(
        S3Operation $operation,
        string $query,
    ): void {
        $response = (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $this->request($operation, $query, ownerId: 'bucket-owner'),
            new AclObjectWriteTerminalHandler(),
        );

        self::assertSame(200, $response->getStatus());
    }

    #[DataProvider('publicObjectWritePermissionProvider')]
    public function test_public_object_acl_cannot_authorize_anonymous_put(string $permission): void
    {
        $this->metadata->putAcl(
            'object',
            ObjectVersionResolver::aclResourceName('bucket', 'existing.txt', null),
            'bucket-owner',
            [[
                'granteeType' => 'Group',
                'granteeId' => 'http://acs.amazonaws.com/groups/global/AllUsers',
                'permission' => $permission,
            ]],
        );
        $this->metadata->putAcl('bucket', 'bucket', 'bucket-owner', [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'bucket-owner',
            'permission' => 'FULL_CONTROL',
        ]]);

        $this->expectException(AccessDeniedException::class);

        (new AclEnforcementMiddleware($this->metadata))->handleRequest(
            $this->request(S3Operation::PutObject, '', ''),
            new AclObjectWriteTerminalHandler(),
        );
    }

    /** @return iterable<string, array{string, S3Operation, string}> */
    public static function objectAclWriteCasesProvider(): iterable
    {
        yield from self::writeCases('WRITE');
        yield from self::writeCases('FULL_CONTROL');
    }

    /** @return iterable<string, array{string, S3Operation, string}> */
    public static function bucketAclWriteCasesProvider(): iterable
    {
        yield from self::writeCases('WRITE');
        yield from self::writeCases('FULL_CONTROL');
    }

    /** @return iterable<string, array{string}> */
    public static function publicObjectWritePermissionProvider(): iterable
    {
        yield 'WRITE' => ['WRITE'];
        yield 'FULL_CONTROL' => ['FULL_CONTROL'];
    }

    /** @return iterable<string, array{string, S3Operation, string}> */
    private static function writeCases(string $permission): iterable
    {
        yield $permission . ' PutObject' => [$permission, S3Operation::PutObject, ''];
        yield $permission . ' PostObject' => [$permission, S3Operation::PostObject, ''];
        yield $permission . ' CopyObject' => [$permission, S3Operation::CopyObject, ''];
        yield $permission . ' CreateMultipartUpload' => [$permission, S3Operation::CreateMultipartUpload, '?uploads'];
        yield $permission . ' UploadPart' => [$permission, S3Operation::UploadPart, '?partNumber=1&uploadId=upload-id'];
        yield $permission . ' UploadPartCopy' => [$permission, S3Operation::UploadPartCopy, '?partNumber=1&uploadId=upload-id'];
        yield $permission . ' CompleteMultipartUpload' => [$permission, S3Operation::CompleteMultipartUpload, '?uploadId=upload-id'];
    }

    /** @return iterable<string, array{S3Operation, string, string}> */
    public static function publishedObjectWriteCasesProvider(): iterable
    {
        foreach (['never versioned' => '', 'versioning enabled' => 'Enabled', 'versioning suspended' => 'Suspended'] as $label => $versioning) {
            foreach (self::publishedObjectOperationsProvider() as $operationLabel => [$operation, $query]) {
                yield $label . ' ' . $operationLabel => [$operation, $query, $versioning];
            }
        }
    }

    /** @return iterable<string, array{S3Operation, string}> */
    public static function publishedObjectOperationsProvider(): iterable
    {
        yield 'PutObject' => [S3Operation::PutObject, ''];
        yield 'PostObject' => [S3Operation::PostObject, ''];
        yield 'CopyObject' => [S3Operation::CopyObject, ''];
        yield 'CompleteMultipartUpload' => [S3Operation::CompleteMultipartUpload, '?uploadId=upload-id'];
    }

    private function request(
        S3Operation $operation,
        string $query,
        string $ownerId = 'writer',
        string $key = 'existing.txt',
    ): Request {
        $method = match ($operation) {
            S3Operation::PostObject, S3Operation::CreateMultipartUpload, S3Operation::CompleteMultipartUpload => 'POST',
            default => 'PUT',
        };
        $request = new Request(
            new AclObjectWriteTestClient(),
            $method,
            Http::new('http://127.0.0.1/bucket/' . $key . $query),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', $key);
        $request->setAttribute('s3.operation', $operation);
        $request->setAttribute('ownerId', $ownerId);

        return $request;
    }
}

final class AclObjectWriteTerminalHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return new Response(status: 200);
    }
}

final class AclObjectWriteTestClient implements Client
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
