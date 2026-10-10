<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Bucket\PutBucketCorsHandler;
use OpsFour\S3Server\Handler\Bucket\PutBucketVersioningHandler;
use OpsFour\S3Server\Handler\Bucket\PutObjectLockConfigHandler;
use OpsFour\S3Server\Handler\Bucket\PutPublicAccessBlockHandler;
use OpsFour\S3Server\Handler\Bucket\BucketOwnershipControlsHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class BucketConfigurationCommitAuthorizationTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-bucket-config-auth-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_owner_account_deny_added_during_body_read_blocks_cors_commit(): void
    {
        $request = $this->request('owner', function (): void {
            $this->metadata->putAccountPolicy('owner', self::policy('Deny'));
        });

        try {
            $this->handle($request);
            self::fail('A newly effective identity Deny must block the bucket configuration commit.');
        } catch (AccessDeniedException) {
            self::assertSame([], $this->metadata->getBucketCors('bucket'));
        }
    }

    public function test_revoked_cross_account_bucket_allow_blocks_cors_commit(): void
    {
        $this->metadata->putAccountPolicy('other', self::policy('Allow'));
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => ['AWS' => 'other'],
                'Action' => 's3:PutBucketCORS',
                'Resource' => 'arn:aws:s3:::bucket',
            ]],
        ], JSON_THROW_ON_ERROR));
        $request = $this->request('other', function (): void {
            $this->metadata->deleteBucketPolicy('bucket');
        });

        try {
            $this->handle($request);
            self::fail('A removed bucket-policy Allow must block the foreign account at commit.');
        } catch (AccessDeniedException) {
            self::assertSame([], $this->metadata->getBucketCors('bucket'));
        }
    }

    /** @return iterable<string, array{S3Operation, string, string, class-string<RequestHandler>}> */
    public static function specialConfigurationMutations(): iterable
    {
        yield 'Versioning' => [S3Operation::PutBucketVersioning, 'versioning',
            '<VersioningConfiguration><Status>Enabled</Status></VersioningConfiguration>', PutBucketVersioningHandler::class];
        yield 'Public Access Block' => [S3Operation::PutPublicAccessBlock, 'publicAccessBlock',
            '<PublicAccessBlockConfiguration><BlockPublicAcls>true</BlockPublicAcls></PublicAccessBlockConfiguration>', PutPublicAccessBlockHandler::class];
        yield 'Ownership Controls' => [S3Operation::PutBucketOwnershipControls, 'ownershipControls',
            '<OwnershipControls><Rule><ObjectOwnership>BucketOwnerEnforced</ObjectOwnership></Rule></OwnershipControls>', BucketOwnershipControlsHandler::class];
        yield 'Object Lock' => [S3Operation::PutObjectLockConfig, 'object-lock',
            '<ObjectLockConfiguration><ObjectLockEnabled>Enabled</ObjectLockEnabled></ObjectLockConfiguration>', PutObjectLockConfigHandler::class];
    }

    /** @param class-string<RequestHandler> $handlerClass */
    #[DataProvider('specialConfigurationMutations')]
    public function test_special_configuration_commit_rechecks_new_identity_deny(
        S3Operation $operation,
        string $query,
        string $xml,
        string $handlerClass,
    ): void {
        $request = $this->request('owner', function () use ($operation): void {
            $this->metadata->putAccountPolicy('owner', self::policy('Deny', PolicyEnforcementMiddleware::operationToAction($operation)));
        }, $operation, $query, $xml);

        try {
            $this->handle($request, new $handlerClass($this->metadata));
            self::fail('The newly effective identity Deny must block this configuration commit.');
        } catch (AccessDeniedException) {
            self::assertSame('', $this->metadata->getBucketVersioning('bucket'));
            self::assertNull($this->metadata->getPublicAccessBlock('bucket'));
            self::assertNull($this->metadata->getBucketOwnershipControls('bucket'));
            self::assertNull($this->metadata->getObjectLockConfig('bucket'));
        }
    }

    private function request(
        string $ownerId,
        \Closure $duringBodyRead,
        S3Operation $operation = S3Operation::PutBucketCors,
        string $query = 'cors',
        string $xml = '<CORSConfiguration><CORSRule><AllowedOrigin>*</AllowedOrigin><AllowedMethod>GET</AllowedMethod></CORSRule></CORSConfiguration>',
    ): Request {
        $body = new ReadableIterableStream((static function () use ($duringBodyRead, $xml): \Generator {
            $duringBodyRead();
            yield $xml;
        })());
        $request = new Request($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket?' . $query), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('ownerId', $ownerId);
        $request->setAttribute('s3.operation', $operation);

        return $request;
    }

    private function handle(Request $request, ?RequestHandler $handler = null): Response
    {
        $handler ??= new PutBucketCorsHandler($this->metadata);

        return (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new class ($this->metadata, $handler) implements RequestHandler {
            public function __construct(private readonly SqliteMetadataStore $metadata, private readonly RequestHandler $handler) {}

            public function handleRequest(Request $request): Response
            {
                return (new AclEnforcementMiddleware($this->metadata))->handleRequest(
                    $request,
                    $this->handler,
                );
            }
        });
    }

    private static function policy(string $effect, string $action = 's3:PutBucketCORS'): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => $effect,
                'Action' => $action,
                'Resource' => 'arn:aws:s3:::bucket',
            ]],
        ], JSON_THROW_ON_ERROR);
    }
}
