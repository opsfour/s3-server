<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Result;
use Aws\S3\Exception\S3Exception;

/**
 * Comprehensive functional tests for the ACL system.
 *
 * Covers canned ACLs (bucket + object), XML body ACLs via SDK AccessControlPolicy,
 * owner verification, default ACLs, error cases with awsErrorCode, ACL replacement,
 * sequential canned ACL changes, Public Access Block integration, object lifecycle ACL,
 * idempotency, and return status verification.
 */
final class AclComprehensiveTest extends S3FunctionalTestCase
{
    private const ALL_USERS_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    private const AUTH_USERS_URI = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    /**
     * The owner ID assigned by the credential provider (bin/s3-server defaults to 'default-owner').
     */
    private const OWNER_ID = 'default-owner';

    private static string $bucket = 'test-acl-comprehensive';

    private static string $objectKey = 'acl-test-object.txt';

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
            'Key' => self::$objectKey,
            'Body' => 'test content for ACL tests',
        ]);

        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Assertion helpers
    // -----------------------------------------------------------------

    private function assertOwnerFullControl(array $grant): void
    {
        $this->assertSame('CanonicalUser', $grant['Grantee']['Type']);
        $this->assertSame(self::OWNER_ID, $grant['Grantee']['ID']);
        $this->assertSame('FULL_CONTROL', $grant['Permission']);
    }

    private function assertGroupGrant(array $grant, string $uri, string $permission): void
    {
        $this->assertSame('Group', $grant['Grantee']['Type']);
        $this->assertSame($uri, $grant['Grantee']['URI']);
        $this->assertSame($permission, $grant['Permission']);
    }

    private function assertOwnerIdInResult(Result $result): void
    {
        $this->assertSame(self::OWNER_ID, $result['Owner']['ID']);
    }

    // -----------------------------------------------------------------
    // Canned Bucket ACLs (5 tests)
    // -----------------------------------------------------------------

    public function test_bucket_canned_private(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'private',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $grants = $result['Grants'];

        $this->assertOwnerIdInResult($result);
        $this->assertCount(1, $grants);
        $this->assertOwnerFullControl($grants[0]);
    }

    public function test_bucket_canned_public_read(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'public-read',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $grants = $result['Grants'];

        $this->assertOwnerIdInResult($result);
        $this->assertCount(2, $grants);
        $this->assertOwnerFullControl($grants[0]);
        $this->assertGroupGrant($grants[1], self::ALL_USERS_URI, 'READ');
    }

    public function test_bucket_canned_public_read_write(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'public-read-write',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $grants = $result['Grants'];

        $this->assertOwnerIdInResult($result);
        $this->assertCount(3, $grants);
        $this->assertOwnerFullControl($grants[0]);
        $this->assertGroupGrant($grants[1], self::ALL_USERS_URI, 'READ');
        $this->assertGroupGrant($grants[2], self::ALL_USERS_URI, 'WRITE');
    }

    public function test_bucket_canned_authenticated_read(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'authenticated-read',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $grants = $result['Grants'];

        $this->assertOwnerIdInResult($result);
        $this->assertCount(2, $grants);
        $this->assertOwnerFullControl($grants[0]);
        $this->assertGroupGrant($grants[1], self::AUTH_USERS_URI, 'READ');
    }

    public function test_bucket_unknown_canned_defaults_to_private(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'bucket-owner-full-control',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $grants = $result['Grants'];

        $this->assertOwnerIdInResult($result);
        $this->assertCount(1, $grants);
        $this->assertOwnerFullControl($grants[0]);
    }

    // -----------------------------------------------------------------
    // Canned Object ACLs (4 tests)
    // -----------------------------------------------------------------

    public function test_object_canned_private(): void
    {
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'private',
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertOwnerIdInResult($result);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
    }

    public function test_object_canned_public_read(): void
    {
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'public-read',
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertOwnerIdInResult($result);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');
    }

    public function test_object_canned_public_read_write(): void
    {
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'public-read-write',
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertOwnerIdInResult($result);
        $this->assertCount(3, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');
        $this->assertGroupGrant($result['Grants'][2], self::ALL_USERS_URI, 'WRITE');
    }

    public function test_object_canned_authenticated_read(): void
    {
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'authenticated-read',
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertOwnerIdInResult($result);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::AUTH_USERS_URI, 'READ');
    }

    // -----------------------------------------------------------------
    // XML Body ACLs via SDK AccessControlPolicy (3 tests)
    // -----------------------------------------------------------------

    public function test_bucket_acl_via_access_control_policy(): void
    {
        $ownerId = self::OWNER_ID;

        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'AccessControlPolicy' => [
                'Owner' => ['ID' => $ownerId],
                'Grants' => [
                    [
                        'Grantee' => ['ID' => $ownerId, 'Type' => 'CanonicalUser'],
                        'Permission' => 'FULL_CONTROL',
                    ],
                    [
                        'Grantee' => ['URI' => self::ALL_USERS_URI, 'Type' => 'Group'],
                        'Permission' => 'READ',
                    ],
                ],
            ],
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $grants = $result['Grants'];

        $this->assertOwnerIdInResult($result);
        $this->assertCount(2, $grants);
        $this->assertOwnerFullControl($grants[0]);
        $this->assertGroupGrant($grants[1], self::ALL_USERS_URI, 'READ');
    }

    public function test_object_acl_via_access_control_policy_canonical_user(): void
    {
        $ownerId = self::OWNER_ID;

        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'AccessControlPolicy' => [
                'Owner' => ['ID' => $ownerId],
                'Grants' => [
                    [
                        'Grantee' => ['ID' => $ownerId, 'Type' => 'CanonicalUser'],
                        'Permission' => 'FULL_CONTROL',
                    ],
                ],
            ],
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertOwnerIdInResult($result);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
    }

    public function test_object_acl_via_access_control_policy_mixed_grants(): void
    {
        $ownerId = self::OWNER_ID;

        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'AccessControlPolicy' => [
                'Owner' => ['ID' => $ownerId],
                'Grants' => [
                    [
                        'Grantee' => ['ID' => $ownerId, 'Type' => 'CanonicalUser'],
                        'Permission' => 'FULL_CONTROL',
                    ],
                    [
                        'Grantee' => ['URI' => self::ALL_USERS_URI, 'Type' => 'Group'],
                        'Permission' => 'READ',
                    ],
                ],
            ],
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertOwnerIdInResult($result);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');
    }

    // -----------------------------------------------------------------
    // Owner Verification (2 tests)
    // -----------------------------------------------------------------

    public function test_bucket_acl_owner_matches_requester(): void
    {
        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);

        $this->assertArrayHasKey('Owner', $result->toArray());
        $this->assertSame(self::OWNER_ID, $result['Owner']['ID']);
    }

    public function test_object_acl_owner_matches_requester(): void
    {
        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);

        $this->assertArrayHasKey('Owner', $result->toArray());
        $this->assertSame(self::OWNER_ID, $result['Owner']['ID']);
    }

    // -----------------------------------------------------------------
    // Default ACLs (2 tests)
    // -----------------------------------------------------------------

    public function test_new_bucket_default_acl(): void
    {
        $bucket = 'test-acl-default-bucket-' . uniqid();
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $result = self::$s3->getBucketAcl(['Bucket' => $bucket]);

            $this->assertOwnerIdInResult($result);
            $this->assertCount(1, $result['Grants']);
            $this->assertOwnerFullControl($result['Grants'][0]);
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_new_object_default_acl(): void
    {
        $key = 'acl-default-test-' . uniqid() . '.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'default acl test',
        ]);

        try {
            $result = self::$s3->getObjectAcl([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);

            $this->assertOwnerIdInResult($result);
            $this->assertCount(1, $result['Grants']);
            $this->assertOwnerFullControl($result['Grants'][0]);
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    // -----------------------------------------------------------------
    // Error Cases (4 tests) — verify statusCode AND awsErrorCode
    // -----------------------------------------------------------------

    public function test_get_bucket_acl_nonexistent_bucket(): void
    {
        try {
            self::$s3->getBucketAcl(['Bucket' => 'nonexistent-bucket-' . uniqid()]);
            $this->fail('Expected S3Exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchBucket', $e->getAwsErrorCode());
        }
    }

    public function test_put_bucket_acl_nonexistent_bucket(): void
    {
        try {
            self::$s3->putBucketAcl([
                'Bucket' => 'nonexistent-bucket-' . uniqid(),
                'ACL' => 'private',
            ]);
            $this->fail('Expected S3Exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchBucket', $e->getAwsErrorCode());
        }
    }

    public function test_get_object_acl_nonexistent_object(): void
    {
        try {
            self::$s3->getObjectAcl([
                'Bucket' => self::$bucket,
                'Key' => 'nonexistent-key-' . uniqid(),
            ]);
            $this->fail('Expected S3Exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }
    }

    public function test_put_object_acl_nonexistent_object(): void
    {
        try {
            self::$s3->putObjectAcl([
                'Bucket' => self::$bucket,
                'Key' => 'nonexistent-key-' . uniqid(),
                'ACL' => 'private',
            ]);
            $this->fail('Expected S3Exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // ACL Replacement (3 tests)
    // -----------------------------------------------------------------

    public function test_bucket_acl_replacement(): void
    {
        // Set public-read.
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'public-read',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');

        // Replace with private.
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'private',
        ]);

        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
    }

    public function test_object_acl_replacement(): void
    {
        // Set public-read.
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'public-read',
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');

        // Replace with private.
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'private',
        ]);

        $result = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
    }

    public function test_bucket_acl_sequential_canned_changes(): void
    {
        // private
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'private']);
        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);

        // public-read
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'public-read']);
        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');

        // authenticated-read
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'authenticated-read']);
        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(2, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::AUTH_USERS_URI, 'READ');

        // public-read-write
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'public-read-write']);
        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(3, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
        $this->assertGroupGrant($result['Grants'][1], self::ALL_USERS_URI, 'READ');
        $this->assertGroupGrant($result['Grants'][2], self::ALL_USERS_URI, 'WRITE');

        // back to private
        self::$s3->putBucketAcl(['Bucket' => self::$bucket, 'ACL' => 'private']);
        $result = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);
    }

    // -----------------------------------------------------------------
    // Public Access Block Integration (6 tests)
    // -----------------------------------------------------------------

    public function test_pab_blocks_public_read_on_bucket(): void
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
            self::$s3->putBucketAcl([
                'Bucket' => self::$bucket,
                'ACL' => 'public-read',
            ]);
            $this->fail('Expected AccessDenied exception');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_pab_blocks_public_read_write_on_bucket(): void
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
            self::$s3->putBucketAcl([
                'Bucket' => self::$bucket,
                'ACL' => 'public-read-write',
            ]);
            $this->fail('Expected AccessDenied exception');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_pab_blocks_authenticated_read_on_bucket(): void
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
            self::$s3->putBucketAcl([
                'Bucket' => self::$bucket,
                'ACL' => 'authenticated-read',
            ]);
            $this->fail('Expected AccessDenied exception');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_pab_allows_private_on_bucket(): void
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
            $result = self::$s3->putBucketAcl([
                'Bucket' => self::$bucket,
                'ACL' => 'private',
            ]);
            $this->assertSame(200, $result['@metadata']['statusCode']);
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_pab_blocks_public_read_on_object(): void
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
            self::$s3->putObjectAcl([
                'Bucket' => self::$bucket,
                'Key' => self::$objectKey,
                'ACL' => 'public-read',
            ]);
            $this->fail('Expected AccessDenied exception');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('AccessDenied', $e->getAwsErrorCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
        }
    }

    public function test_pab_roundtrip(): void
    {
        $config = [
            'BlockPublicAcls' => true,
            'IgnorePublicAcls' => true,
            'BlockPublicPolicy' => true,
            'RestrictPublicBuckets' => true,
        ];

        // Put PAB.
        $putResult = self::$s3->putPublicAccessBlock([
            'Bucket' => self::$bucket,
            'PublicAccessBlockConfiguration' => $config,
        ]);
        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get PAB — verify config was stored correctly.
        $getResult = self::$s3->getPublicAccessBlock(['Bucket' => self::$bucket]);
        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $stored = $getResult['PublicAccessBlockConfiguration'];
        $this->assertTrue($stored['BlockPublicAcls']);
        $this->assertTrue($stored['IgnorePublicAcls']);
        $this->assertTrue($stored['BlockPublicPolicy']);
        $this->assertTrue($stored['RestrictPublicBuckets']);

        // Delete PAB.
        self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);

        // Verify PAB is gone.
        try {
            self::$s3->getPublicAccessBlock(['Bucket' => self::$bucket]);
            $this->fail('Expected exception after PAB deletion');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchPublicAccessBlockConfiguration', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Object Lifecycle ACL (3 tests)
    // -----------------------------------------------------------------

    public function test_object_overwrite_resets_acl(): void
    {
        $key = 'acl-overwrite-test-' . uniqid() . '.txt';

        // Create object.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'original',
        ]);

        // Set public-read.
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ACL' => 'public-read',
        ]);

        $result = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertCount(2, $result['Grants']);

        // Overwrite without ACL headers resets the object to private.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'overwritten',
        ]);

        $result = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_object_delete_recreate_resets_acl(): void
    {
        $key = 'acl-delete-recreate-' . uniqid() . '.txt';

        // Create object and set public-read.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'original',
        ]);
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ACL' => 'public-read',
        ]);
        $result = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertCount(2, $result['Grants']);

        // Delete object.
        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);

        // Recreate object.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'recreated',
        ]);

        // Recreating without ACL headers receives the default private ACL.
        $result = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertOwnerIdInResult($result);
        $this->assertCount(1, $result['Grants']);
        $this->assertOwnerFullControl($result['Grants'][0]);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_defaults_to_private_acl(): void
    {
        $key = 'acl-no-header-test-' . uniqid() . '.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'no acl header test',
        ]);

        try {
            $result = self::$s3->getObjectAcl([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);

            $this->assertOwnerIdInResult($result);
            $this->assertCount(1, $result['Grants']);
            $this->assertOwnerFullControl($result['Grants'][0]);
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_copy_object_applies_requested_acl_and_resets_it_when_omitted(): void
    {
        $source = 'acl-copy-source-' . uniqid() . '.txt';
        $destination = 'acl-copy-destination-' . uniqid() . '.txt';
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $source, 'Body' => 'copy acl']);

        try {
            self::$s3->copyObject([
                'Bucket' => self::$bucket,
                'Key' => $destination,
                'CopySource' => self::$bucket . '/' . $source,
                'ACL' => 'public-read',
            ]);
            $public = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $destination]);
            $this->assertCount(2, $public['Grants']);

            self::$s3->copyObject([
                'Bucket' => self::$bucket,
                'Key' => $destination,
                'CopySource' => self::$bucket . '/' . $source,
            ]);
            $private = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $destination]);
            $this->assertCount(1, $private['Grants']);
            $this->assertOwnerFullControl($private['Grants'][0]);
        } finally {
            self::$s3->deleteObjects([
                'Bucket' => self::$bucket,
                'Delete' => ['Objects' => [['Key' => $source], ['Key' => $destination]]],
            ]);
        }
    }

    public function test_multipart_upload_preserves_initiation_acl(): void
    {
        $key = 'acl-multipart-' . uniqid() . '.txt';
        $upload = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ACL' => 'public-read',
        ]);

        try {
            $part = self::$s3->uploadPart([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $upload['UploadId'],
                'PartNumber' => 1,
                'Body' => 'multipart acl',
            ]);
            self::$s3->completeMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $upload['UploadId'],
                'MultipartUpload' => [
                    'Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]],
                ],
            ]);

            $acl = self::$s3->getObjectAcl(['Bucket' => self::$bucket, 'Key' => $key]);
            $this->assertCount(2, $acl['Grants']);
            $this->assertGroupGrant($acl['Grants'][1], self::ALL_USERS_URI, 'READ');
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    // -----------------------------------------------------------------
    // ACL Idempotency (2 tests)
    // -----------------------------------------------------------------

    public function test_double_put_bucket_acl_same_value(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'private',
        ]);

        $result = self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'private',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        $acl = self::$s3->getBucketAcl(['Bucket' => self::$bucket]);
        $this->assertOwnerIdInResult($acl);
        $this->assertCount(1, $acl['Grants']);
        $this->assertOwnerFullControl($acl['Grants'][0]);
    }

    public function test_double_put_object_acl_same_value(): void
    {
        self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'private',
        ]);

        $result = self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'private',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        $acl = self::$s3->getObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
        ]);
        $this->assertOwnerIdInResult($acl);
        $this->assertCount(1, $acl['Grants']);
        $this->assertOwnerFullControl($acl['Grants'][0]);
    }

    // -----------------------------------------------------------------
    // Return Status Verification (2 tests)
    // -----------------------------------------------------------------

    public function test_put_bucket_acl_returns_200(): void
    {
        $result = self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'private',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    public function test_put_object_acl_returns_200(): void
    {
        $result = self::$s3->putObjectAcl([
            'Bucket' => self::$bucket,
            'Key' => self::$objectKey,
            'ACL' => 'private',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }
}
