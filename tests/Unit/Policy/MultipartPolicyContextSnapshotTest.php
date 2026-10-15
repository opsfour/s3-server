<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartPolicyContextSnapshotTest extends TestCase
{
    private SqliteMetadataStore $metadata;

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->metadata = new SqliteMetadataStore(':memory:');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->encryption = new EncryptionService(
            new ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
        );
    }

    /**
     * @return iterable<string, array{
     *   array<non-empty-string, string>,
     *   bool,
     *   array<string, string>,
     *   bool
     * }>
     */
    public static function policyHeaderCases(): iterable
    {
        yield 'omitted options' => [[], false, [], false];
        yield 'bucket default SSE-S3 is not an initiation header' => [[], true, [], false];
        yield 'explicit SSE-S3' => [
            ['x-amz-server-side-encryption' => 'AES256'],
            false,
            ['x-amz-server-side-encryption' => 'AES256'],
            false,
        ];
        yield 'explicit STANDARD storage class' => [
            ['x-amz-storage-class' => 'STANDARD'],
            false,
            ['x-amz-storage-class' => 'STANDARD'],
            false,
        ];
        yield 'explicit non-standard storage class' => [
            ['x-amz-storage-class' => 'STANDARD_IA'],
            false,
            ['x-amz-storage-class' => 'STANDARD_IA'],
            false,
        ];
        yield 'explicit private ACL' => [
            ['x-amz-acl' => 'private'],
            false,
            ['x-amz-acl' => 'private'],
            true,
        ];
        yield 'explicit grant header without canned ACL' => [
            ['x-amz-grant-read' => 'id="reader"'],
            false,
            [],
            true,
        ];
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param array<string, string> $expectedHeaders
     */
    #[DataProvider('policyHeaderCases')]
    public function test_initiation_snapshots_only_actual_condition_headers(
        array $headers,
        bool $bucketDefaultEncryption,
        array $expectedHeaders,
        bool $expectedAcl,
    ): void {
        if ($bucketDefaultEncryption) {
            $this->metadata->putBucketEncryption('bucket', 'AES256');
        }

        $context = $this->initiateAndAttach($headers);

        self::assertSame($expectedHeaders, $context['headers'] ?? null);
        self::assertSame($expectedAcl, $context['hasAcl'] ?? null);
    }

    public function test_sse_c_does_not_invent_standard_sse_condition_header(): void
    {
        $key = random_bytes(32);
        $context = $this->initiateAndAttach([
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($key),
            'x-amz-server-side-encryption-customer-key-md5' => base64_encode(md5($key, true)),
        ]);

        self::assertSame([], $context['headers'] ?? null);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function legacyMetadataCases(): iterable
    {
        yield 'plain or explicit-private ambiguity' => [[
            '__mpu-acl-grants' => json_encode([], JSON_THROW_ON_ERROR),
        ]];
        yield 'effective SSE-S3 may have come from bucket default' => [[
            '__sse-algorithm' => 'AES256',
        ]];
        yield 'legacy SSE-C had no standard SSE header' => [[
            '__sse-algorithm' => 'SSE-C',
            '__sse-customer-key-md5' => 'digest',
        ]];
        yield 'legacy explicit private ACL remains unsnapshotted' => [[
            '__mpu-acl-explicit' => '1',
            '__mpu-acl-header' => 'private',
        ]];
    }

    /** @param array<string, string> $legacyMetadata */
    #[DataProvider('legacyMetadataCases')]
    public function test_legacy_upload_without_exact_snapshot_must_be_reinitiated(array $legacyMetadata): void
    {
        $request = $this->completionRequest();
        $this->expectException(OperationAbortedException::class);
        $this->expectExceptionMessage('abort it and initiate a new upload');

        (new PolicyEnforcementMiddleware($this->metadata))->assertMultipartCompletionAllowedAtCommit(
            $request,
            [
                '__mpu-acl-grants' => json_encode(AclGrantResolver::privateAcl('owner'), JSON_THROW_ON_ERROR),
                ...$legacyMetadata,
            ],
            [],
            AclGrantResolver::privateAcl('owner'),
        );
    }

    /**
     * @return iterable<string, array{
     *   array<string, string>,
     *   list<array{granteeType: string, granteeId: string, permission: string}>
     * }>
     */
    public static function inconsistentSnapshotCases(): iterable
    {
        $privateAcl = AclGrantResolver::privateAcl('owner');

        yield 'canned ACL snapshot without stored canned header' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-acl' => 'private',
            ], JSON_THROW_ON_ERROR),
            '__mpu-acl-explicit' => '1',
        ], $privateAcl];
        yield 'stored canned ACL without condition snapshot' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '[]',
            '__mpu-acl-explicit' => '1',
            '__mpu-acl-header' => 'private',
        ], $privateAcl];
        yield 'different stored and snapshotted canned ACLs' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-acl' => 'public-read',
            ], JSON_THROW_ON_ERROR),
            '__mpu-acl-explicit' => '1',
            '__mpu-acl-header' => 'private',
        ], $privateAcl];
        yield 'canned ACL does not match published grants' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-acl' => 'private',
            ], JSON_THROW_ON_ERROR),
            '__mpu-acl-explicit' => '1',
            '__mpu-acl-header' => 'private',
        ], [
            ...$privateAcl,
            [
                'granteeType' => 'Group',
                'granteeId' => AclGrantResolver::ALL_USERS_URI,
                'permission' => 'READ',
            ],
        ]];
        yield 'standard SSE header without effective SSE-S3' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-server-side-encryption' => 'AES256',
            ], JSON_THROW_ON_ERROR),
        ], $privateAcl];
        yield 'standard SSE header with SSE-C metadata' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-server-side-encryption' => 'AES256',
            ], JSON_THROW_ON_ERROR),
            '__sse-algorithm' => 'SSE-C',
        ], $privateAcl];
        yield 'unsupported standard SSE snapshot' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-server-side-encryption' => 'aws:kms',
            ], JSON_THROW_ON_ERROR),
            '__sse-algorithm' => 'AES256',
        ], $privateAcl];
        yield 'storage class snapshot differs from effective class' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-storage-class' => 'STANDARD_IA',
            ], JSON_THROW_ON_ERROR),
        ], $privateAcl];
        yield 'non-standard effective class without snapshot' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '[]',
            '__mpu-storage-class' => 'STANDARD_IA',
        ], $privateAcl];
        yield 'unsupported storage class in both locations' => [[
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => json_encode([
                'x-amz-storage-class' => 'EXPRESS_ONEZONE',
            ], JSON_THROW_ON_ERROR),
            '__mpu-storage-class' => 'EXPRESS_ONEZONE',
        ], $privateAcl];
    }

    /**
     * @param array<string, string> $metadata
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants
     */
    #[DataProvider('inconsistentSnapshotCases')]
    public function test_versioned_policy_snapshot_must_match_published_options(
        array $metadata,
        array $aclGrants,
    ): void {
        $this->expectException(InternalErrorException::class);

        (new PolicyEnforcementMiddleware($this->metadata))->assertMultipartCompletionAllowedAtCommit(
            $this->completionRequest(),
            [
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY
                    => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
                ...$metadata,
            ],
            [],
            $aclGrants,
        );
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @return array<string, mixed>
     */
    private function initiateAndAttach(array $headers): array
    {
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploads'),
            $headers,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $response = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($request);
        $xml = new \SimpleXMLElement(\Amp\ByteStream\buffer($response->getBody()));
        $upload = $this->metadata->getMultipartUpload((string) $xml->UploadId);
        self::assertNotNull($upload);
        $metadata = $upload['user_metadata'];
        self::assertSame(
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
            $metadata[PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY] ?? null,
        );
        $acl = AclGrantResolver::validateGrants(json_decode(
            $metadata['__mpu-acl-grants'],
            true,
            16,
            JSON_THROW_ON_ERROR,
        ));
        $completion = $this->completionRequest();
        (new PolicyEnforcementMiddleware($this->metadata))->assertMultipartCompletionAllowedAtCommit(
            $completion,
            $metadata,
            [],
            $acl,
        );
        $context = $completion->getAttribute('s3.multipartCompletionPolicyContext');
        self::assertIsArray($context);

        return $context;
    }

    private function completionRequest(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploadId=upload'),
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
