<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;

final class AuthorizationSecurityTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$bucket !== '') {
            return;
        }

        self::$bucket = 'auth-security-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'protected.txt',
            'Body' => 'protected',
        ]);
    }

    public function test_anonymous_create_bucket_is_denied(): void
    {
        $bucket = 'anonymous-create-' . bin2hex(random_bytes(4));
        $response = $this->http()->put($this->url($bucket));

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());

        $listed = self::$s3->listBuckets();
        $names = array_column($listed['Buckets'] ?? [], 'Name');
        $this->assertNotContains($bucket, $names);
    }

    public function test_bucket_policy_explicit_deny_applies_to_owner(): void
    {
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:DeleteObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/protected.txt',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        try {
            self::$s3->deleteObject([
                'Bucket' => self::$bucket,
                'Key' => 'protected.txt',
            ]);
            $this->fail('Expected explicit bucket-policy deny to reject the bucket owner.');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_restrict_public_buckets_blocks_anonymous_public_policy_access(): void
    {
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => true,
            ],
        ]);

        try {
            $response = $this->http()->get($this->url(self::$bucket . '/protected.txt'));
            $this->assertSame(403, $response->getStatusCode());

            $ownerRead = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'protected.txt',
            ]);
            $this->assertSame('protected', (string) $ownerRead['Body']);
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
        }
    }

    public function test_block_public_acls_rejects_public_acl_during_put_object(): void
    {
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => false,
            ],
        ]);

        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => 'blocked-public-acl.txt',
                'Body' => 'must not be stored',
                'ACL' => 'public-read',
            ]);
            $this->fail('Expected BlockPublicAcls to reject PutObject.');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_block_public_policy_rejects_not_principal_policy_that_includes_anonymous_users(): void
    {
        self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => true,
                'RestrictPublicBuckets' => false,
            ],
        ]);

        try {
            self::$s3->putBucketPolicy([
                'Bucket' => self::$bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Allow',
                        'NotPrincipal' => ['AWS' => 'tenant:owner'],
                        'Action' => 's3:GetObject',
                        'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);
            $this->fail('Expected BlockPublicPolicy to reject anonymous NotPrincipal access.');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
            try {
                self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            } catch (S3Exception) {
            }
        }
    }

    private function http(): Client
    {
        return new Client(['http_errors' => false, 'timeout' => 10]);
    }

    private function url(string $path): string
    {
        return sprintf('http://%s:%d/%s', self::$host, self::$port, $path);
    }
}
