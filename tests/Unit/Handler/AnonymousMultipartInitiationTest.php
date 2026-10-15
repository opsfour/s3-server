<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use League\Uri\Http;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class AnonymousMultipartInitiationTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('bucket-owner', 'bucket', 'us-east-1');
    }

    public function test_direct_handler_rejects_anonymous_multipart_initiation_without_persisting_upload(): void
    {
        $this->assertAnonymousInitiationDenied(
            fn(): Response => (new CreateMultipartUploadHandler($this->metadata))
                ->handleRequest($this->request()),
        );

        self::assertSame([], $this->metadata->listMultipartUploads('bucket')['uploads']);
    }

    public function test_public_put_policy_cannot_enable_anonymous_multipart_initiation(): void
    {
        $this->metadata->putBucketPolicy('bucket', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/*',
            ]],
        ], JSON_THROW_ON_ERROR));
        $handler = new class (new CreateMultipartUploadHandler($this->metadata)) implements RequestHandler {
            public bool $called = false;

            public function __construct(private readonly RequestHandler $inner) {}

            public function handleRequest(Request $request): Response
            {
                $this->called = true;

                return $this->inner->handleRequest($request);
            }
        };
        $acl = new class ($this->metadata, $handler) implements RequestHandler {
            public function __construct(
                private readonly SqliteMetadataStore $metadata,
                private readonly RequestHandler $handler,
            ) {}

            public function handleRequest(Request $request): Response
            {
                return (new AclEnforcementMiddleware($this->metadata))
                    ->handleRequest($request, $this->handler);
            }
        };
        $request = $this->request();

        $this->assertAnonymousInitiationDenied(
            fn(): Response => (new PolicyEnforcementMiddleware($this->metadata))
                ->handleRequest($request, $acl),
        );

        self::assertSame('Allow', $request->getAttribute('s3.policyResult'));
        self::assertTrue($handler->called);
        self::assertSame([], $this->metadata->listMultipartUploads('bucket')['uploads']);
    }

    /** @param \Closure(): Response $operation */
    private function assertAnonymousInitiationDenied(\Closure $operation): void
    {
        try {
            $operation();
            self::fail('Anonymous multipart initiation must be rejected.');
        } catch (AccessDeniedException $error) {
            self::assertSame('Anonymous multipart uploads are not supported.', $error->getMessage());
        }
    }

    private function request(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploads'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::CreateMultipartUpload);
        $request->setAttribute('ownerId', '');
        $request->setAttribute('credential', null);

        return $request;
    }
}
