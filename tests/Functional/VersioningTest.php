<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use PHPUnit\Framework\Attributes\Depends;

/**
 * Functional tests for Phase 5: Versioning + Object Lock.
 *
 * Tests versioning lifecycle (enable, put versions, delete markers,
 * list versions, get by version ID), and Object Lock (retention, legal hold).
 */
final class VersioningTest extends S3FunctionalTestCase
{
    private static string $bucket = 'test-versioning-bucket';

    private static bool $bucketCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bucketCreated) {
            self::$s3->createBucket(['Bucket' => self::$bucket]);
            self::$bucketCreated = true;
        }
    }

    // -----------------------------------------------------------------
    // Bucket Versioning
    // -----------------------------------------------------------------

    public function test_get_bucket_versioning_default_disabled(): void
    {
        $result = self::$s3->getBucketVersioning([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        // Status is empty/null when versioning has never been enabled.
        $this->assertEmpty($result['Status'] ?? '');
    }

    public function test_enable_versioning(): void
    {
        self::$s3->putBucketVersioning([
            'Bucket' => self::$bucket,
            'VersioningConfiguration' => [
                'Status' => 'Enabled',
            ],
        ]);

        $result = self::$s3->getBucketVersioning([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame('Enabled', $result['Status']);
    }

    // -----------------------------------------------------------------
    // Version-aware PutObject
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_put_object_returns_version_id(): void
    {
        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'versioned.txt',
            'Body' => 'version 1',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertNotEmpty($result['VersionId'] ?? '');
    }

    #[Depends('test_enable_versioning')]
    public function test_multiple_versions_of_same_key(): void
    {
        $key = 'multi-version.txt';

        $v1 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'v1 content',
        ]);
        $versionId1 = $v1['VersionId'];

        $v2 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'v2 content',
        ]);
        $versionId2 = $v2['VersionId'];

        $v3 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'v3 content',
        ]);
        $versionId3 = $v3['VersionId'];

        // All version IDs should be different.
        $this->assertNotSame($versionId1, $versionId2);
        $this->assertNotSame($versionId2, $versionId3);

        // Latest GET returns v3 content.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('v3 content', (string) $get['Body']);

        // GET by specific version ID returns correct content.
        $getV1 = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId1,
        ]);
        $this->assertSame('v1 content', (string) $getV1['Body']);

        $getV2 = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId2,
        ]);
        $this->assertSame('v2 content', (string) $getV2['Body']);
    }

    // -----------------------------------------------------------------
    // Delete markers
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_delete_creates_delete_marker(): void
    {
        $key = 'delete-marker-test.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'original content',
        ]);

        // Delete without versionId creates a delete marker.
        $deleteResult = self::$s3->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);
        $this->assertNotEmpty($deleteResult['VersionId'] ?? '');
        $this->assertTrue($deleteResult['DeleteMarker'] ?? false);

        // GET on the key now returns 404.
        try {
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);
            $this->fail('Expected NoSuchKey exception after delete marker');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // List Object Versions
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_list_object_versions(): void
    {
        $key = 'list-versions-test.txt';

        $v1 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'list v1',
        ]);

        $v2 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'list v2',
        ]);

        $result = self::$s3->listObjectVersions([
            'Bucket' => self::$bucket,
            'Prefix' => $key,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Should have at least 2 versions.
        $versions = $result['Versions'] ?? [];
        $this->assertGreaterThanOrEqual(2, count($versions));

        // Verify the latest version is marked as latest.
        $latestVersions = array_filter($versions, fn ($v) => ($v['IsLatest'] ?? false) && $v['Key'] === $key);
        $this->assertCount(1, $latestVersions);
    }

    #[Depends('test_enable_versioning')]
    public function test_list_object_versions_with_delete_markers(): void
    {
        $key = 'dm-list-test.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'content',
        ]);

        self::$s3->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $result = self::$s3->listObjectVersions([
            'Bucket' => self::$bucket,
            'Prefix' => $key,
        ]);

        // Should have delete markers.
        $deleteMarkers = $result['DeleteMarkers'] ?? [];
        $this->assertGreaterThanOrEqual(1, count($deleteMarkers));
    }

    // -----------------------------------------------------------------
    // Permanent version delete
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_delete_specific_version(): void
    {
        $key = 'perm-delete-test.txt';

        $v1 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'perm v1',
        ]);

        $v2 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'perm v2',
        ]);

        // Permanently delete v1.
        $deleteResult = self::$s3->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $v1['VersionId'],
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // v1 should be gone.
        try {
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'VersionId' => $v1['VersionId'],
            ]);
            $this->fail('Expected 404 for deleted version');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }

        // v2 should still be accessible.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('perm v2', (string) $get['Body']);
    }

    // -----------------------------------------------------------------
    // HeadObject with versionId
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_head_object_by_version_id(): void
    {
        $key = 'head-version-test.txt';

        $v1 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'head test v1',
        ]);

        $v2 = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'head test v2 longer',
        ]);

        // HeadObject with versionId returns correct version's metadata.
        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $v1['VersionId'],
        ]);

        $this->assertSame(200, $head['@metadata']['statusCode']);
        $this->assertSame(strlen('head test v1'), $head['ContentLength']);
    }

    // -----------------------------------------------------------------
    // Suspend versioning
    // -----------------------------------------------------------------

    public function test_suspend_versioning(): void
    {
        $suspendBucket = 'test-suspend-bucket';
        self::$s3->createBucket(['Bucket' => $suspendBucket]);

        // Enable versioning.
        self::$s3->putBucketVersioning([
            'Bucket' => $suspendBucket,
            'VersioningConfiguration' => [
                'Status' => 'Enabled',
            ],
        ]);

        // Suspend versioning.
        self::$s3->putBucketVersioning([
            'Bucket' => $suspendBucket,
            'VersioningConfiguration' => [
                'Status' => 'Suspended',
            ],
        ]);

        $result = self::$s3->getBucketVersioning([
            'Bucket' => $suspendBucket,
        ]);

        $this->assertSame('Suspended', $result['Status']);

        // Put object with suspended versioning uses null version.
        $put = self::$s3->putObject([
            'Bucket' => $suspendBucket,
            'Key' => 'suspended.txt',
            'Body' => 'suspended content',
        ]);

        // No version ID should be returned for suspended buckets.
        $this->assertEmpty($put['VersionId'] ?? '');

        // Clean up — must delete all versions and delete markers before bucket deletion.
        $versions = self::$s3->listObjectVersions(['Bucket' => $suspendBucket]);
        foreach ($versions->get('Versions') ?? [] as $v) {
            self::$s3->deleteObject([
                'Bucket' => $suspendBucket,
                'Key' => $v['Key'],
                'VersionId' => $v['VersionId'],
            ]);
        }
        foreach ($versions->get('DeleteMarkers') ?? [] as $dm) {
            self::$s3->deleteObject([
                'Bucket' => $suspendBucket,
                'Key' => $dm['Key'],
                'VersionId' => $dm['VersionId'],
            ]);
        }
        self::$s3->deleteBucket(['Bucket' => $suspendBucket]);
    }

    // -----------------------------------------------------------------
    // Object Lock
    // -----------------------------------------------------------------

    public function test_object_lock_config_crud(): void
    {
        $lockBucket = 'test-lock-config-bucket';
        self::$s3->createBucket(['Bucket' => $lockBucket]);

        // Put Object Lock config.
        self::$s3->putObjectLockConfiguration([
            'Bucket' => $lockBucket,
            'ObjectLockConfiguration' => [
                'ObjectLockEnabled' => 'Enabled',
                'Rule' => [
                    'DefaultRetention' => [
                        'Mode' => 'GOVERNANCE',
                        'Days' => 30,
                    ],
                ],
            ],
        ]);

        // Get Object Lock config.
        $result = self::$s3->getObjectLockConfiguration([
            'Bucket' => $lockBucket,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame('Enabled', $result['ObjectLockConfiguration']['ObjectLockEnabled'] ?? '');
        $defaultRetention = $result['ObjectLockConfiguration']['Rule']['DefaultRetention'] ?? [];
        $this->assertSame('GOVERNANCE', $defaultRetention['Mode']);
        $this->assertSame(30, $defaultRetention['Days']);

        // Clean up.
        self::$s3->deleteBucket(['Bucket' => $lockBucket]);
    }

    // -----------------------------------------------------------------
    // Object Retention
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_object_retention_governance(): void
    {
        // Object Lock must be enabled before using retention.
        self::$s3->putObjectLockConfiguration([
            'Bucket' => self::$bucket,
            'ObjectLockConfiguration' => [
                'ObjectLockEnabled' => 'Enabled',
            ],
        ]);

        $key = 'retention-gov-test.txt';

        $put = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'retention test',
        ]);
        $versionId = $put['VersionId'];

        // Set governance retention for 1 day from now.
        $retainUntilDate = new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC'));

        self::$s3->putObjectRetention([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'Retention' => [
                'Mode' => 'GOVERNANCE',
                'RetainUntilDate' => $retainUntilDate,
            ],
        ]);

        // Get retention.
        $result = self::$s3->getObjectRetention([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
        ]);

        $this->assertSame('GOVERNANCE', $result['Retention']['Mode']);

        // Delete should fail (locked).
        try {
            self::$s3->deleteObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'VersionId' => $versionId,
            ]);
            $this->fail('Expected ObjectLocked exception');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('ObjectLocked', $e->getAwsErrorCode());
        }

        // Delete with bypass should succeed.
        $deleteResult = self::$s3->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            '@http' => [
                'headers' => [
                    'x-amz-bypass-governance-retention' => 'true',
                ],
            ],
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);
    }

    // -----------------------------------------------------------------
    // Legal Hold
    // -----------------------------------------------------------------

    #[Depends('test_enable_versioning')]
    public function test_object_legal_hold(): void
    {
        // Object Lock must be enabled before using legal hold.
        self::$s3->putObjectLockConfiguration([
            'Bucket' => self::$bucket,
            'ObjectLockConfiguration' => [
                'ObjectLockEnabled' => 'Enabled',
            ],
        ]);

        $key = 'legal-hold-test.txt';

        $put = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'legal hold test',
        ]);
        $versionId = $put['VersionId'];

        // Set legal hold ON.
        self::$s3->putObjectLegalHold([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'LegalHold' => [
                'Status' => 'ON',
            ],
        ]);

        // Get legal hold.
        $result = self::$s3->getObjectLegalHold([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
        ]);

        $this->assertSame('ON', $result['LegalHold']['Status']);

        // Delete should fail.
        try {
            self::$s3->deleteObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'VersionId' => $versionId,
            ]);
            $this->fail('Expected ObjectLocked exception');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('ObjectLocked', $e->getAwsErrorCode());
        }

        // Remove legal hold.
        self::$s3->putObjectLegalHold([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
            'LegalHold' => [
                'Status' => 'OFF',
            ],
        ]);

        // Delete should now succeed.
        $deleteResult = self::$s3->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'VersionId' => $versionId,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);
    }
}
