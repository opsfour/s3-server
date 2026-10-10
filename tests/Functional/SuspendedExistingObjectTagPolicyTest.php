<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class SuspendedExistingObjectTagPolicyTest extends S3FunctionalTestCase
{
    public function test_suspended_mutations_deny_using_replaced_null_version_tags(): void
    {
        $bucket = 'suspended-tags-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            foreach (['put', 'copy', 'complete', 'single-delete', 'bulk-delete'] as $operation) {
                $this->putNullBehindNumberedVersion($bucket, $operation, 'protected', 'ordinary');
            }
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'source', 'Body' => 'copy source']);
            $uploadId = (string) self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'complete'])['UploadId'];
            $part = self::$s3->uploadPart([
                'Bucket' => $bucket, 'Key' => 'complete', 'UploadId' => $uploadId,
                'PartNumber' => 1, 'Body' => 'new multipart data',
            ]);
            $this->putTagDenyPolicy($bucket);

            self::assertAccessDenied(fn() => self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'put', 'Body' => 'new put data',
            ]));
            self::assertAccessDenied(fn() => self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'copy', 'CopySource' => "{$bucket}/source",
            ]));
            self::assertAccessDenied(fn() => self::$s3->completeMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'complete', 'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
            ]));
            self::assertAccessDenied(fn() => self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'single-delete']));
            $batch = self::$s3->deleteObjects([
                'Bucket' => $bucket, 'Delete' => ['Objects' => [['Key' => 'bulk-delete']]],
            ]);
            self::assertSame('AccessDenied', $batch['Errors'][0]['Code'] ?? null);

            foreach (['put', 'copy', 'complete', 'single-delete', 'bulk-delete'] as $operation) {
                self::assertSame('protected null', (string) self::$s3->getObject([
                    'Bucket' => $bucket, 'Key' => $operation, 'VersionId' => 'null',
                ])['Body']);
                self::assertSame('ordinary numbered', (string) self::$s3->getObject([
                    'Bucket' => $bucket, 'Key' => $operation,
                ])['Body']);
            }
        } finally {
            $this->clearBucket($bucket);
        }
    }

    public function test_suspended_mutations_ignore_tags_on_numbered_version_when_null_is_unprotected(): void
    {
        $bucket = 'suspended-tags-control-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $this->putNullBehindNumberedVersion($bucket, 'put', 'ordinary', 'protected');
            $this->putNullBehindNumberedVersion($bucket, 'delete', 'ordinary', 'protected');
            $this->putTagDenyPolicy($bucket);

            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'put', 'Body' => 'replacement']);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'delete']);
            self::assertSame('replacement', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'put', 'VersionId' => 'null',
            ])['Body']);
        } finally {
            $this->clearBucket($bucket);
        }
    }

    public function test_enabled_and_explicit_version_policies_keep_their_existing_tag_scope(): void
    {
        $bucket = 'version-tags-control-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            $protectedVersion = (string) self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'key', 'Body' => 'protected numbered',
                'Tagging' => 'classification=protected',
            ])['VersionId'];
            $this->putTagDenyPolicy($bucket);
            self::assertAccessDenied(fn() => self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'key', 'Body' => 'new enabled version',
            ]));
            self::assertAccessDenied(fn() => self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']));
            self::assertSame('protected numbered', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'key', 'VersionId' => $protectedVersion,
            ])['Body']);

            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            $ordinaryVersion = (string) self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'key', 'Body' => 'ordinary numbered',
                'Tagging' => 'classification=ordinary',
            ])['VersionId'];
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
            self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:DeleteObjectVersion',
                    'Resource' => "arn:aws:s3:::{$bucket}/key",
                    'Condition' => ['StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected']],
                ]],
            ], JSON_THROW_ON_ERROR)]);
            self::assertAccessDenied(fn() => self::$s3->deleteObject([
                'Bucket' => $bucket, 'Key' => 'key', 'VersionId' => $protectedVersion,
            ]));
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key', 'VersionId' => $ordinaryVersion]);
            self::assertSame('protected numbered', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'key', 'VersionId' => $protectedVersion,
            ])['Body']);
        } finally {
            $this->clearBucket($bucket);
        }
    }

    public function test_suspended_mutation_with_no_null_version_ignores_numbered_tags(): void
    {
        $bucket = 'no-null-tags-control-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            $numberedVersion = (string) self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'put', 'Body' => 'protected numbered',
                'Tagging' => 'classification=protected',
            ])['VersionId'];
            self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'delete', 'Body' => 'protected numbered',
                'Tagging' => 'classification=protected',
            ]);
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
            $this->putTagDenyPolicy($bucket);

            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'put', 'Body' => 'new null']);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'delete']);
            self::assertSame('new null', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => 'put'])['Body']);
            self::assertSame('protected numbered', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'put', 'VersionId' => $numberedVersion,
            ])['Body']);
        } finally {
            $this->clearBucket($bucket);
        }
    }

    public function test_secondary_tagging_deny_uses_existing_null_version_tags(): void
    {
        $bucket = 'secondary-tags-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            foreach (['put', 'copy', 'multipart'] as $key) {
                $this->putNullBehindNumberedVersion($bucket, $key, 'protected', 'ordinary');
            }
            $this->putNullBehindNumberedVersion($bucket, 'allowed-put', 'ordinary', 'protected');
            $this->putNullBehindNumberedVersion($bucket, 'allowed-copy', 'ordinary', 'protected');
            self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'source', 'Body' => 'copy source',
                'Tagging' => 'classification=ordinary',
            ]);
            self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:PutObjectTagging',
                    'Resource' => "arn:aws:s3:::{$bucket}/*",
                    'Condition' => ['StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected']],
                ]],
            ], JSON_THROW_ON_ERROR)]);

            self::assertAccessDenied(fn() => self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'put', 'Body' => 'new tagged data',
                'Tagging' => 'classification=ordinary',
            ]));
            self::assertAccessDenied(fn() => self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'copy', 'CopySource' => "{$bucket}/source",
            ]));
            self::assertAccessDenied(fn() => self::$s3->createMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'multipart', 'Tagging' => 'classification=ordinary',
            ]));
            self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'allowed-put', 'Body' => 'allowed replacement',
                'Tagging' => 'classification=ordinary',
            ]);
            self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'allowed-copy', 'CopySource' => "{$bucket}/source",
            ]);
            self::assertSame('allowed replacement', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'allowed-put', 'VersionId' => 'null',
            ])['Body']);
            self::assertSame('copy source', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'allowed-copy', 'VersionId' => 'null',
            ])['Body']);
            foreach (['put', 'copy', 'multipart'] as $key) {
                self::assertSame('protected null', (string) self::$s3->getObject([
                    'Bucket' => $bucket, 'Key' => $key, 'VersionId' => 'null',
                ])['Body']);
            }
        } finally {
            $this->clearBucket($bucket);
        }
    }

    public function test_copy_source_tags_do_not_satisfy_secondary_request_tag_condition(): void
    {
        $bucket = 'copy-request-tags-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putObject([
                'Bucket' => $bucket, 'Key' => 'source', 'Body' => 'source bytes',
                'Tagging' => 'project=approved',
            ]);
            self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Deny', 'Principal' => '*', 'Action' => 's3:PutObjectTagging',
                    'Resource' => "arn:aws:s3:::{$bucket}/destination",
                    'Condition' => ['StringNotEquals' => ['s3:RequestObjectTag/project' => 'approved']],
                ]],
            ], JSON_THROW_ON_ERROR)]);

            self::assertAccessDenied(fn() => self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'destination',
                'CopySource' => "{$bucket}/source", 'TaggingDirective' => 'COPY',
                'Tagging' => 'project=approved',
            ]));
            self::assertSame([], self::$s3->listObjectsV2([
                'Bucket' => $bucket, 'Prefix' => 'destination',
            ])['Contents'] ?? []);

            self::$s3->copyObject([
                'Bucket' => $bucket, 'Key' => 'destination',
                'CopySource' => "{$bucket}/source", 'TaggingDirective' => 'REPLACE',
                'Tagging' => 'project=approved',
            ]);
            self::assertSame('source bytes', (string) self::$s3->getObject([
                'Bucket' => $bucket, 'Key' => 'destination',
            ])['Body']);
        } finally {
            $this->clearBucket($bucket);
        }
    }

    private function putNullBehindNumberedVersion(string $bucket, string $key, string $nullTag, string $numberedTag): void
    {
        self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
        self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
        self::$s3->putObject([
            'Bucket' => $bucket, 'Key' => $key, 'Body' => "{$nullTag} null",
            'Tagging' => "classification={$nullTag}",
        ]);
        self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
        self::$s3->putObject([
            'Bucket' => $bucket, 'Key' => $key, 'Body' => "{$numberedTag} numbered",
            'Tagging' => "classification={$numberedTag}",
        ]);
        self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
    }

    private function putTagDenyPolicy(string $bucket): void
    {
        self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny', 'Principal' => '*',
                'Action' => ['s3:PutObject', 's3:DeleteObject'],
                'Resource' => "arn:aws:s3:::{$bucket}/*",
                'Condition' => ['StringEquals' => ['s3:ExistingObjectTag/classification' => 'protected']],
            ]],
        ], JSON_THROW_ON_ERROR)]);
    }

    private function clearBucket(string $bucket): void
    {
        self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
        $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
        foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
            self::$s3->deleteObject([
                'Bucket' => $bucket, 'Key' => $version['Key'], 'VersionId' => $version['VersionId'],
            ]);
        }
        self::deleteBucketWithDiagnostics($bucket);
    }

    private static function assertAccessDenied(callable $operation): void
    {
        try {
            $operation();
            self::fail('Expected AccessDenied.');
        } catch (S3Exception $exception) {
            self::assertSame('AccessDenied', $exception->getAwsErrorCode());
        }
    }
}
