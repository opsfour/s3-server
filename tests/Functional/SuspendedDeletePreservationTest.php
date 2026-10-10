<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

final class SuspendedDeletePreservationTest extends S3FunctionalTestCase
{
    public function test_single_delete_preserves_a_numbered_version_after_suspension(): void
    {
        $this->assertNumberedVersionSurvivesSuspendedDelete(batch: false);
    }

    public function test_batch_delete_preserves_a_numbered_version_after_suspension(): void
    {
        $this->assertNumberedVersionSurvivesSuspendedDelete(batch: true);
    }

    public function test_single_delete_replaces_only_an_older_null_version(): void
    {
        $this->assertNumberedVersionSurvivesSuspendedDelete(batch: false, withOlderNullVersion: true);
    }

    public function test_batch_delete_replaces_only_an_older_null_version(): void
    {
        $this->assertNumberedVersionSurvivesSuspendedDelete(batch: true, withOlderNullVersion: true);
    }

    private function assertNumberedVersionSurvivesSuspendedDelete(bool $batch, bool $withOlderNullVersion = false): void
    {
        $bucket = 'suspended-delete-' . bin2hex(random_bytes(6));
        $key = 'retained.txt';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::$s3->putBucketVersioning([
                'Bucket' => $bucket,
                'VersioningConfiguration' => ['Status' => 'Enabled'],
            ]);
            $original = self::$s3->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => 'numbered original',
            ]);
            $versionId = (string) $original['VersionId'];
            self::assertNotSame('', $versionId);

            self::$s3->putBucketVersioning([
                'Bucket' => $bucket,
                'VersioningConfiguration' => ['Status' => 'Suspended'],
            ]);
            $newerVersionId = null;
            if ($withOlderNullVersion) {
                self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'older null payload']);
                self::$s3->putBucketVersioning([
                    'Bucket' => $bucket,
                    'VersioningConfiguration' => ['Status' => 'Enabled'],
                ]);
                $newer = self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'newer numbered payload']);
                $newerVersionId = (string) $newer['VersionId'];
                self::$s3->putBucketVersioning([
                    'Bucket' => $bucket,
                    'VersioningConfiguration' => ['Status' => 'Suspended'],
                ]);
            }
            if ($batch) {
                $result = self::$s3->deleteObjects([
                    'Bucket' => $bucket,
                    'Delete' => ['Objects' => [['Key' => $key]]],
                ]);
                self::assertEmpty($result['Errors'] ?? []);
            } else {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            }

            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            self::assertContains($versionId, array_column($versions['Versions'] ?? [], 'VersionId'));
            self::assertSame('numbered original', (string) self::$s3->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'VersionId' => $versionId,
            ])['Body']);
            if ($newerVersionId !== null) {
                self::assertSame('newer numbered payload', (string) self::$s3->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'VersionId' => $newerVersionId,
                ])['Body']);
                if ((getenv('S3_TEST_SERVER_STORAGE_DRIVER') ?: 'filesystem') === 'filesystem') {
                    $contents = [];
                    $files = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(self::$storagePath . '/' . $bucket));
                    foreach ($files as $file) {
                        if ($file->isFile()) {
                            $contents[] = file_get_contents($file->getPathname());
                        }
                    }
                    self::assertNotContains('older null payload', $contents);
                }
            }
        } finally {
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
}
