<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

final class NumericListingMarkerTest extends S3FunctionalTestCase
{
    public function test_v1_next_marker_uses_binary_key_order(): void
    {
        $bucket = 'numeric-v1-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        foreach (['10a', '9', 'z'] as $key) {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'x']);
        }

        $first = self::$s3->listObjects(['Bucket' => $bucket, 'Delimiter' => '0', 'MaxKeys' => 2]);
        self::assertSame(['10'], array_column($first['CommonPrefixes'] ?? [], 'Prefix'));
        self::assertSame(['9'], array_column($first['Contents'] ?? [], 'Key'));
        self::assertTrue($first['IsTruncated']);
        self::assertSame('9', $first['NextMarker']);

        $second = self::$s3->listObjects([
            'Bucket' => $bucket, 'Delimiter' => '0', 'MaxKeys' => 2, 'Marker' => $first['NextMarker'],
        ]);
        self::assertSame(['z'], array_column($second['Contents'] ?? [], 'Key'));
        self::assertSame([], $second['CommonPrefixes'] ?? []);
        self::assertFalse($second['IsTruncated']);
    }

    public function test_numeric_common_prefix_survives_v2_version_and_multipart_markers(): void
    {
        $bucket = 'numeric-all-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);
        foreach (['9a', '9b', 'z'] as $key) {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'x']);
            self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key]);
        }

        $firstObjects = self::$s3->listObjectsV2([
            'Bucket' => $bucket, 'Delimiter' => '9', 'MaxKeys' => 1, 'StartAfter' => '10',
        ]);
        self::assertSame(['9'], array_column($firstObjects['CommonPrefixes'] ?? [], 'Prefix'));
        self::assertTrue($firstObjects['IsTruncated']);
        $secondObjects = self::$s3->listObjectsV2([
            'Bucket' => $bucket, 'Delimiter' => '9', 'MaxKeys' => 1,
            'ContinuationToken' => $firstObjects['NextContinuationToken'],
        ]);
        self::assertSame(['z'], array_column($secondObjects['Contents'] ?? [], 'Key'));
        self::assertFalse($secondObjects['IsTruncated']);

        $firstVersions = self::$s3->listObjectVersions([
            'Bucket' => $bucket, 'Delimiter' => '9', 'MaxKeys' => 1, 'KeyMarker' => '10',
        ]);
        self::assertSame(['9'], array_column($firstVersions['CommonPrefixes'] ?? [], 'Prefix'));
        self::assertTrue($firstVersions['IsTruncated']);
        $secondVersions = self::$s3->listObjectVersions([
            'Bucket' => $bucket, 'Delimiter' => '9', 'MaxKeys' => 1,
            'KeyMarker' => $firstVersions['NextKeyMarker'],
            'VersionIdMarker' => $firstVersions['NextVersionIdMarker'],
        ]);
        self::assertSame(['z'], array_column($secondVersions['Versions'] ?? [], 'Key'));
        self::assertFalse($secondVersions['IsTruncated']);

        $firstUploads = self::$s3->listMultipartUploads([
            'Bucket' => $bucket, 'Delimiter' => '9', 'MaxUploads' => 1, 'KeyMarker' => '10',
        ]);
        self::assertSame(['9'], array_column($firstUploads['CommonPrefixes'] ?? [], 'Prefix'));
        self::assertTrue($firstUploads['IsTruncated']);
        $secondUploads = self::$s3->listMultipartUploads([
            'Bucket' => $bucket, 'Delimiter' => '9', 'MaxUploads' => 1,
            'KeyMarker' => $firstUploads['NextKeyMarker'],
            'UploadIdMarker' => $firstUploads['NextUploadIdMarker'],
        ]);
        self::assertSame(['z'], array_column($secondUploads['Uploads'] ?? [], 'Key'));
        self::assertFalse($secondUploads['IsTruncated']);
    }
}
