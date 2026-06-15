<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class PolicyEnforcementMiddlewareContextTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-policy-context-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: 'public/readme.txt',
            ownerId: 'owner',
            size: 4,
            etag: '"etag"',
            contentType: 'text/plain',
            storagePath: 'bucket/public/readme.txt',
        );
        $this->metadata->putObjectTagging('bucket', 'public/readme.txt', [
            ['key' => 'classification', 'value' => 'public'],
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_middleware_populates_user_agent_prefix_and_source_ip_conditions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:ListBucket',
                'Resource' => 'arn:aws:s3:::bucket',
                'Condition' => [
                    'IpAddress' => ['aws:SourceIp' => '127.0.0.0/8'],
                    'StringLike' => ['aws:UserAgent' => 'aws-sdk-*', 's3:prefix' => 'public/*'],
                ],
            ],
        ]));

        $request = $this->request('GET', '/bucket?list-type=2&prefix=public/images/', ['user-agent' => 'aws-sdk-php/3']);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.operation', S3Operation::ListObjectsV2);
        $request->setAttribute('ownerId', 'tenant:reader');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_middleware_populates_existing_object_tag_conditions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
                'Condition' => [
                    'StringEquals' => ['s3:ExistingObjectTag/classification' => 'public'],
                ],
            ],
        ]));

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_middleware_enforces_unsupported_condition_fail_closed(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => '*',
                'Condition' => ['StringEquals' => ['aws:PrincipalOrgID' => 'o-123']],
            ],
        ]));

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_middleware_populates_request_tag_header_numeric_and_version_conditions(): void
    {
        $this->metadata->putBucketPolicy('bucket', $this->policy([
            [
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
                'Condition' => [
                    'StringEquals' => [
                        's3:RequestObjectTag/project' => 'alpha',
                        's3:x-amz-acl' => 'private',
                        's3:x-amz-server-side-encryption' => 'AES256',
                        's3:x-amz-storage-class' => 'STANDARD',
                        's3:VersionId' => 'v1',
                    ],
                    'NumericLessThanEquals' => ['s3:max-keys' => 100],
                    'DateLessThan' => ['aws:CurrentTime' => '2999-01-01T00:00:00Z'],
                ],
            ],
        ]));

        $request = $this->request('PUT', '/bucket/public/new.txt?versionId=v1&max-keys=25', [
            'x-amz-tagging' => 'project=alpha',
            'x-amz-acl' => 'private',
            'x-amz-server-side-encryption' => 'AES256',
            'x-amz-storage-class' => 'STANDARD',
        ]);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/new.txt');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        $request->setAttribute('ownerId', 'tenant:writer');

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_scoped_credential_prefix_is_enforced_before_owner_bypass(): void
    {
        $request = $this->request('GET', '/bucket/private/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'private/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIASCOPED12345678',
            secretAccessKey: 'secret',
            ownerId: 'owner',
            displayName: 'Scoped',
            allowedPrefixes: ['public/'],
        ));

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_account_policy_explicit_deny_applies_before_owner_bypass(): void
    {
        $this->metadata->putAccountPolicy('owner', $this->policy([
            [
                'Effect' => 'Deny',
                'Action' => 's3:DeleteObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));

        $request = $this->request('DELETE', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::DeleteObject);
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    public function test_named_policy_attached_to_credential_can_allow_request(): void
    {
        $this->metadata->putNamedPolicy('readonly-public', $this->policy([
            [
                'Effect' => 'Allow',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::bucket/public/*',
            ],
        ]));

        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIANAMED12345678',
            secretAccessKey: 'secret',
            ownerId: 'tenant:reader',
            displayName: 'Named Policy Reader',
            policyNames: ['readonly-public'],
        ));

        $response = (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());

        $this->assertSame(200, $response->getStatus());
        $this->assertSame('Allow', $request->getAttribute('s3.policyResult'));
    }

    public function test_missing_named_policy_attached_to_credential_fails_closed(): void
    {
        $request = $this->request('GET', '/bucket/public/readme.txt');
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'public/readme.txt');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('ownerId', 'tenant:reader');
        $request->setAttribute('credential', new Credential(
            accessKeyId: 'AKIAMISSING123456',
            secretAccessKey: 'secret',
            ownerId: 'tenant:reader',
            displayName: 'Missing Policy Reader',
            policyNames: ['does-not-exist'],
        ));

        $this->expectException(AccessDeniedException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->handleRequest($request, new PolicyTerminalHandler());
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array $headers = []): Request
    {
        return new Request(
            new PolicyTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
        );
    }

    /**
     * @param list<array<string, mixed>> $statements
     */
    private function policy(array $statements): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => $statements,
        ], JSON_THROW_ON_ERROR);
    }
}

final class PolicyTerminalHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        return new Response(status: 200);
    }
}

final class PolicyTestClient implements Client
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
