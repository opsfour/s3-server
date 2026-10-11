<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for Phase 6: ACLs, Policies, Tagging, CORS.
 */
final class AclPolicyTaggingCorsTest extends S3FunctionalTestCase
{
    private static string $bucket = 'test-acl-policy-bucket';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
            'Body' => 'test content',
        ]);

        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Bucket ACL
    // -----------------------------------------------------------------

    public function test_get_bucket_acl_default(): void
    {
        $result = self::$s3->getBucketAcl([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame('default-owner', $result['Owner']['ID']);

        $grants = $result['Grants'] ?? [];
        $this->assertCount(1, $grants);
        $this->assertSame('CanonicalUser', $grants[0]['Grantee']['Type']);
        $this->assertSame('default-owner', $grants[0]['Grantee']['ID']);
        $this->assertSame('FULL_CONTROL', $grants[0]['Permission']);
    }

    public function test_put_bucket_acl_canned(): void
    {
        $result = self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'public-read',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Read back and verify grants were applied.
        $acl = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertSame('default-owner', $acl['Owner']['ID']);
        $grants = $acl['Grants'] ?? [];
        $this->assertCount(2, $grants);

        $permissions = array_map(fn($g) => $g['Permission'], $grants);
        $this->assertContains('FULL_CONTROL', $permissions);
        $this->assertContains('READ', $permissions);

        // Restore to private.
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'private']);
    }

    // -----------------------------------------------------------------
    // Object ACL
    // -----------------------------------------------------------------

    public function test_get_object_acl_default(): void
    {
        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame('default-owner', $result['Owner']['ID']);

        $grants = $result['Grants'] ?? [];
        $this->assertGreaterThanOrEqual(1, count($grants));
        $this->assertSame('CanonicalUser', $grants[0]['Grantee']['Type']);
        $this->assertSame('FULL_CONTROL', $grants[0]['Permission']);
    }

    public function test_put_object_acl_canned(): void
    {
        $result = self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
            'ACL' => 'private',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Read back and verify the ACL was applied.
        $acl = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
        ]);
        $this->assertSame('default-owner', $acl['Owner']['ID']);
        $grants = $acl['Grants'] ?? [];
        $this->assertCount(1, $grants);
        $this->assertSame('CanonicalUser', $grants[0]['Grantee']['Type']);
        $this->assertSame('default-owner', $grants[0]['Grantee']['ID']);
        $this->assertSame('FULL_CONTROL', $grants[0]['Permission']);
    }

    // -----------------------------------------------------------------
    // Bucket Policy
    // -----------------------------------------------------------------

    public function test_bucket_policy_crud(): void
    {
        $policy = json_encode([
            'Version' => '2012-10-17',
            'Statement' => [
                [
                    'Effect' => 'Allow',
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                ],
            ],
        ], JSON_THROW_ON_ERROR);

        // Put policy.
        $putResult = self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => $policy,
        ]);

        $this->assertSame(204, $putResult['@metadata']['statusCode']);

        // Get policy.
        $getResult = self::$s3->getBucketPolicy([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $returned = json_decode((string) $getResult['Policy'], true);
        $this->assertSame('2012-10-17', $returned['Version']);

        // Delete policy.
        $deleteResult = self::$s3->deleteBucketPolicy([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // Get after delete should fail.
        try {
            self::$s3->getBucketPolicy([
                'Bucket' => self::$bucket,
            ]);
            $this->fail('Expected NoSuchBucketPolicy exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchBucketPolicy', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Bucket Tagging
    // -----------------------------------------------------------------

    public function test_bucket_tagging_crud(): void
    {
        // Put tags.
        $putResult = self::$s3->putBucketTagging([
            'Bucket' => self::$bucket,
            'Tagging' => [
                'TagSet' => [
                    ['Key' => 'env', 'Value' => 'production'],
                    ['Key' => 'team', 'Value' => 'platform'],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get tags.
        $getResult = self::$s3->getBucketTagging([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $tags = $getResult['TagSet'] ?? [];
        $this->assertCount(2, $tags);

        $tagMap = [];
        foreach ($tags as $tag) {
            $tagMap[$tag['Key']] = $tag['Value'];
        }
        $this->assertSame('production', $tagMap['env']);
        $this->assertSame('platform', $tagMap['team']);

        // Delete tags.
        $deleteResult = self::$s3->deleteBucketTagging([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);
    }

    // -----------------------------------------------------------------
    // Object Tagging
    // -----------------------------------------------------------------

    public function test_object_tagging_crud(): void
    {
        // Put tags.
        $putResult = self::$s3->putObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
            'Tagging' => [
                'TagSet' => [
                    ['Key' => 'category', 'Value' => 'docs'],
                    ['Key' => 'priority', 'Value' => 'high'],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get tags.
        $getResult = self::$s3->getObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $tags = $getResult['TagSet'] ?? [];
        $this->assertCount(2, $tags);

        // Delete tags.
        $deleteResult = self::$s3->deleteObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // Get after delete returns empty tag set.
        $getAfter = self::$s3->getObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'test-object.txt',
        ]);

        $this->assertEmpty($getAfter['TagSet'] ?? []);
    }

    public function test_multibyte_tag_boundaries_roundtrip_through_header_and_xml(): void
    {
        $key = 'unicode-tag-' . bin2hex(random_bytes(4));
        $tagKey = str_repeat("\u{00E9}", 128);
        $tagValue = str_repeat("\u{00E9}", 256);

        self::$s3->putBucketTagging([
            'Bucket' => self::$bucket,
            'Tagging' => ['TagSet' => [['Key' => $tagKey, 'Value' => $tagValue]]],
        ]);
        $bucketTags = self::$s3->getBucketTagging(['Bucket' => self::$bucket]);
        $this->assertSame([['Key' => $tagKey, 'Value' => $tagValue]], $bucketTags['TagSet']);
        self::$s3->deleteBucketTagging(['Bucket' => self::$bucket]);

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'tagged',
            'Tagging' => rawurlencode($tagKey) . '=' . rawurlencode($tagValue),
        ]);
        $stored = self::$s3->getObjectTagging(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertSame([['Key' => $tagKey, 'Value' => $tagValue]], $stored['TagSet']);

        self::$s3->putObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Tagging' => ['TagSet' => [['Key' => $tagKey, 'Value' => $tagValue]]],
        ]);
        $stored = self::$s3->getObjectTagging(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertSame([['Key' => $tagKey, 'Value' => $tagValue]], $stored['TagSet']);

        try {
            self::$s3->putObjectTagging([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Tagging' => ['TagSet' => [['Key' => $tagKey . "\u{00E9}", 'Value' => 'invalid']]],
            ]);
            $this->fail('A tag key beyond 128 Unicode positions was accepted.');
        } catch (S3Exception $error) {
            $this->assertSame(400, $error->getStatusCode());
        }
        $unchanged = self::$s3->getObjectTagging(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertSame([['Key' => $tagKey, 'Value' => $tagValue]], $unchanged['TagSet']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Bucket CORS
    // -----------------------------------------------------------------

    public function test_bucket_cors_crud(): void
    {
        // Put CORS.
        $putResult = self::$s3->putBucketCors([
            'Bucket' => self::$bucket,
            'CORSConfiguration' => [
                'CORSRules' => [
                    [
                        'ID' => 'sdk-rule',
                        'AllowedOrigins' => ['https://example.com'],
                        'AllowedMethods' => ['GET', 'PUT'],
                        'AllowedHeaders' => ['*'],
                        'ExposeHeaders' => ['ETag'],
                        'MaxAgeSeconds' => 0,
                    ],
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get CORS.
        $getResult = self::$s3->getBucketCors([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $rules = $getResult['CORSRules'] ?? [];
        $this->assertCount(1, $rules);
        $this->assertSame('sdk-rule', $rules[0]['ID']);
        $this->assertContains('https://example.com', $rules[0]['AllowedOrigins']);
        $this->assertContains('GET', $rules[0]['AllowedMethods']);
        $this->assertSame(0, $rules[0]['MaxAgeSeconds']);

        // Delete CORS.
        $deleteResult = self::$s3->deleteBucketCors([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // Get after delete should fail.
        try {
            self::$s3->getBucketCors([
                'Bucket' => self::$bucket,
            ]);
            $this->fail('Expected NoSuchCORSConfiguration exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchCORSConfiguration', $e->getAwsErrorCode());
        }
    }
}
