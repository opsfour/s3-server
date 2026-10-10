<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class CopySourceVersionPolicyTest extends S3FunctionalTestCase
{
    public function test_copy_responses_report_current_null_source_version_in_suspended_bucket(): void
    {
        $bucket = 'copy-source-null-' . bin2hex(random_bytes(5));
        $uploadId = null;
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'source.txt', 'Body' => 'null source']);

            $copy = self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'copy.txt', 'CopySource' => "{$bucket}/source.txt",
            ]);
            self::assertSame('null', $copy['CopySourceVersionId']);

            $uploadId = (string) self::$s3->createMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'multipart.txt',
            ])['UploadId'];
            $part = self::$s3->uploadPartCopy([
                'Bucket' => $bucket,
                'Key' => 'multipart.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'CopySource' => "{$bucket}/source.txt",
            ]);
            self::assertSame('null', $part['CopySourceVersionId']);
        } finally {
            if ($uploadId !== null) {
                self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => 'multipart.txt', 'UploadId' => $uploadId]);
            }
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $version['Key'],
                    'VersionId' => $version['VersionId'],
                ]);
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_copy_responses_report_the_selected_current_source_version(): void
    {
        $bucket = 'copy-source-header-' . bin2hex(random_bytes(5));
        $uploadId = null;
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning([
                'Bucket' => $bucket,
                'VersioningConfiguration' => ['Status' => 'Enabled'],
            ]);
            $sourceVersionId = (string) self::$s3->putObject([
                'Bucket' => $bucket,
                'Key' => 'source.txt',
                'Body' => 'source data',
            ])['VersionId'];

            $copy = self::$s3->copyObject([
                'Bucket' => $bucket,
                'Key' => 'copy.txt',
                'CopySource' => "{$bucket}/source.txt",
            ]);
            self::assertSame($sourceVersionId, $copy['CopySourceVersionId']);

            $uploadId = (string) self::$s3->createMultipartUpload([
                'Bucket' => $bucket,
                'Key' => 'multipart.txt',
            ])['UploadId'];
            $part = self::$s3->uploadPartCopy([
                'Bucket' => $bucket,
                'Key' => 'multipart.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'CopySource' => "{$bucket}/source.txt",
            ]);
            self::assertSame($sourceVersionId, $part['CopySourceVersionId']);
        } finally {
            if ($uploadId !== null) {
                self::$s3->abortMultipartUpload([
                    'Bucket' => $bucket,
                    'Key' => 'multipart.txt',
                    'UploadId' => $uploadId,
                ]);
            }
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $bucket,
                    'Key' => $version['Key'],
                    'VersionId' => $version['VersionId'],
                ]);
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    public function test_implicit_copy_source_version_does_not_satisfy_version_id_policy_condition(): void
    {
        $bucket = 'copy-source-version-' . bin2hex(random_bytes(5));
        $source = 'source.txt';
        $uploadId = null;
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning([
                'Bucket' => $bucket,
                'VersioningConfiguration' => ['Status' => 'Enabled'],
            ]);
            $sourceVersionId = (string) self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => $source, 'Body' => 'source data',
            ])['VersionId'];
            $uploadId = (string) self::$s3->createMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'part-destination.txt',
            ])['UploadId'];
            self::$s3->putBucketPolicy([
                'Bucket' => $bucket,
                'Policy' => json_encode([
                    'Version' => '2012-10-17',
                    'Statement' => [[
                        'Effect' => 'Deny',
                        'Principal' => '*',
                        'Action' => ['s3:GetObject', 's3:GetObjectVersion'],
                        'Resource' => "arn:aws:s3:::{$bucket}/{$source}",
                        'Condition' => ['Null' => ['s3:VersionId' => 'true']],
                    ]],
                ], JSON_THROW_ON_ERROR),
            ]);

            self::assertTrue(self::isAccessDenied(fn() => self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => $source,
            ])));
            self::assertSame('source data', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => $source, 'VersionId' => $sourceVersionId,
            ])['Body']);

            $copyDenied = self::isAccessDenied(fn() => self::$s3->copyObject([
                'Bucket' => $bucket,
                'Key' => 'copy-destination.txt',
                'CopySource' => "{$bucket}/{$source}",
            ]));
            $partDenied = self::isAccessDenied(fn() => self::$s3->uploadPartCopy([
                'Bucket' => $bucket,
                'Key' => 'part-destination.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'CopySource' => "{$bucket}/{$source}",
            ]));
            self::assertSame(['copy' => true, 'part' => true], [
                'copy' => $copyDenied,
                'part' => $partDenied,
            ]);

            self::$s3->copyObject([
                'Bucket' => $bucket,
                'Key' => 'explicit-version-copy.txt',
                'CopySource' => "{$bucket}/{$source}?versionId={$sourceVersionId}",
            ]);
            self::assertSame('source data', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'explicit-version-copy.txt',
            ])['Body']);
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            if ($uploadId !== null) {
                self::$s3->abortMultipartUpload([
                    'Bucket' => $bucket, 'Key' => 'part-destination.txt', 'UploadId' => $uploadId,
                ]);
            }
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $bucket, 'Key' => $version['Key'], 'VersionId' => $version['VersionId'],
                ]);
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    private static function isAccessDenied(callable $operation): bool
    {
        try {
            $operation();

            return false;
        } catch (S3Exception $exception) {
            self::assertSame('AccessDenied', $exception->getAwsErrorCode());

            return true;
        }
    }
}
