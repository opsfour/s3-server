<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;

final class AccessLogDeliveryFunctionalTest extends S3FunctionalTestCase
{
    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    public static function setUpBeforeClass(): void
    {
        foreach ([
            'S3_ENCRYPTION_MASTER_KEY' => base64_encode(random_bytes(32)),
            'S3_NOTIFICATION_WORKER' => 'false',
        ] as $name => $value) {
            self::$previousEnv[$name] = getenv($name);
            putenv("{$name}={$value}");
        }
        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        foreach (self::$previousEnv as $name => $value) {
            if ($value === false) {
                putenv($name);
            } else {
                putenv("{$name}={$value}");
            }
        }
        self::$previousEnv = [];
    }

    public function test_signed_cross_bucket_copy_logs_each_side_once_including_source_failure(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $source = 'copy-log-source-' . $suffix;
        $destination = 'copy-log-destination-' . $suffix;
        $sourceLogs = 'copy-source-logs-' . $suffix;
        $destinationLogs = 'copy-destination-logs-' . $suffix;
        foreach ([$source, $destination, $sourceLogs, $destinationLogs] as $bucket) {
            self::$s3->createBucket(['Bucket' => $bucket]);
        }

        try {
            self::$s3->putObject(['Bucket' => $source, 'Key' => 'original.txt', 'Body' => 'source payload']);
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $sourceLogs,
                    'TargetPrefix' => 'source/',
                ]],
            ]);
            self::$s3->putBucketLogging([
                'Bucket' => $destination,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $destinationLogs,
                    'TargetPrefix' => 'destination/',
                ]],
            ]);

            self::$s3->copyObject([
                'Bucket' => $destination,
                'Key' => 'copied.txt',
                'CopySource' => "{$source}/original.txt",
            ]);
            try {
                self::$s3->copyObject([
                    'Bucket' => $destination,
                    'Key' => 'failed.txt',
                    'CopySource' => "{$source}/missing.txt",
                ]);
                self::fail('A missing source must fail the signed copy request.');
            } catch (S3Exception $e) {
                self::assertSame(404, $e->getStatusCode());
                self::assertSame('NoSuchKey', $e->getAwsErrorCode());
            }

            self::restartServer();
            $sourceEntries = $this->copyLogEntries($sourceLogs, 'source/');
            $destinationEntries = $this->copyLogEntries($destinationLogs, 'destination/');

            self::assertCount(2, $sourceEntries);
            self::assertCount(2, $destinationEntries);
            self::assertCount(1, array_filter($sourceEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.OBJECT_GET original.txt ')
                && str_contains($line, '"GET /' . $source . '/original.txt HTTP/1.1" 200 - 14 14 ')));
            self::assertCount(1, array_filter($sourceEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.OBJECT_GET missing.txt ')
                && str_contains($line, '"GET /' . $source . '/missing.txt HTTP/1.1" 404 NoSuchKey ')));
            self::assertCount(1, array_filter($destinationEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.OBJECT copied.txt ')
                && str_contains($line, ' 200 - ')));
            self::assertCount(1, array_filter($destinationEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.OBJECT failed.txt ')
                && str_contains($line, ' 404 NoSuchKey ')));
        } finally {
            foreach ([$source, $destination] as $bucket) {
                self::$s3->putBucketLogging(['Bucket' => $bucket, 'BucketLoggingStatus' => []]);
            }
            foreach ([$source, $destination, $sourceLogs, $destinationLogs] as $bucket) {
                foreach (self::$s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [] as $object) {
                    self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
                }
                self::$s3->deleteBucket(['Bucket' => $bucket]);
            }
        }
    }

    public function test_signed_cross_bucket_part_copy_logs_each_side_once_including_source_failure(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $source = 'part-log-source-' . $suffix;
        $destination = 'part-log-destination-' . $suffix;
        $sourceLogs = 'part-source-logs-' . $suffix;
        $destinationLogs = 'part-destination-logs-' . $suffix;
        foreach ([$source, $destination, $sourceLogs, $destinationLogs] as $bucket) {
            self::$s3->createBucket(['Bucket' => $bucket]);
        }

        $uploadId = null;
        try {
            self::$s3->putObject(['Bucket' => $source, 'Key' => 'original.txt', 'Body' => 'source payload']);
            self::$s3->putObject(['Bucket' => $source, 'Key' => 'range.bin', 'Body' => str_repeat('r', 5 * 1024 * 1024 + 1)]);
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $sourceLogs,
                    'TargetPrefix' => 'source/',
                ]],
            ]);
            self::$s3->putBucketLogging([
                'Bucket' => $destination,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $destinationLogs,
                    'TargetPrefix' => 'destination/',
                ]],
            ]);
            $uploadId = self::$s3->createMultipartUpload(['Bucket' => $destination, 'Key' => 'copied.txt'])['UploadId'];

            self::$s3->uploadPartCopy([
                'Bucket' => $destination,
                'Key' => 'copied.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'CopySource' => "{$source}/original.txt",
            ]);
            self::$s3->uploadPartCopy([
                'Bucket' => $destination,
                'Key' => 'copied.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 3,
                'CopySource' => "{$source}/range.bin",
                'CopySourceRange' => 'bytes=2-11',
            ]);
            try {
                self::$s3->uploadPartCopy([
                    'Bucket' => $destination,
                    'Key' => 'copied.txt',
                    'UploadId' => $uploadId,
                    'PartNumber' => 2,
                    'CopySource' => "{$source}/missing.txt",
                ]);
                self::fail('A missing source must fail the signed part-copy request.');
            } catch (S3Exception $e) {
                self::assertSame(404, $e->getStatusCode());
                self::assertSame('NoSuchKey', $e->getAwsErrorCode());
            }

            self::restartServer();
            $sourceEntries = $this->copyLogEntries($sourceLogs, 'source/');
            $destinationEntries = $this->copyLogEntries($destinationLogs, 'destination/');
            self::assertCount(3, $sourceEntries);
            self::assertCount(3, $destinationEntries);
            self::assertCount(1, array_filter($sourceEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.PART_GET original.txt ')
                && str_contains($line, '"GET /' . $source . '/original.txt HTTP/1.1" 200 - 14 14 ')));
            self::assertCount(1, array_filter($sourceEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.PART_GET missing.txt ')
                && str_contains($line, '"GET /' . $source . '/missing.txt HTTP/1.1" 404 NoSuchKey ')));
            self::assertCount(1, array_filter($sourceEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.PART_GET range.bin ')
                && str_contains($line, '"GET /' . $source . '/range.bin HTTP/1.1" 200 - 10 5242881 ')));
            self::assertCount(2, array_filter($destinationEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.PART copied.txt ')
                && str_contains($line, ' 200 - ')));
            self::assertCount(1, array_filter($destinationEntries, static fn(string $line): bool => str_contains($line, ' REST.COPY.PART copied.txt ')
                && str_contains($line, ' 404 NoSuchKey ')));
        } finally {
            if (is_string($uploadId)) {
                self::$s3->abortMultipartUpload([
                    'Bucket' => $destination,
                    'Key' => 'copied.txt',
                    'UploadId' => $uploadId,
                ]);
            }
            foreach ([$source, $destination] as $bucket) {
                self::$s3->putBucketLogging(['Bucket' => $bucket, 'BucketLoggingStatus' => []]);
            }
            foreach ([$source, $destination, $sourceLogs, $destinationLogs] as $bucket) {
                foreach (self::$s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [] as $object) {
                    self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
                }
                self::$s3->deleteBucket(['Bucket' => $bucket]);
            }
        }
    }

    public function test_signed_part_copy_does_not_deliver_source_log_after_bucket_name_reuse(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $source = 'part-reuse-source-' . $suffix;
        $destination = 'part-reuse-destination-' . $suffix;
        $oldLogs = 'part-reuse-old-logs-' . $suffix;
        $newLogs = 'part-reuse-new-logs-' . $suffix;
        foreach ([$source, $destination, $oldLogs, $newLogs] as $bucket) {
            self::$s3->createBucket(['Bucket' => $bucket]);
        }

        $uploadId = null;
        try {
            self::$s3->putObject(['Bucket' => $source, 'Key' => 'original.txt', 'Body' => 'source payload']);
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $oldLogs,
                    'TargetPrefix' => 'old/',
                ]],
            ]);
            $uploadId = self::$s3->createMultipartUpload(['Bucket' => $destination, 'Key' => 'copied.txt'])['UploadId'];
            self::$s3->uploadPartCopy([
                'Bucket' => $destination,
                'Key' => 'copied.txt',
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'CopySource' => "{$source}/original.txt",
            ]);

            self::$s3->deleteObject(['Bucket' => $source, 'Key' => 'original.txt']);
            self::$s3->deleteBucket(['Bucket' => $source]);
            self::$s3->createBucket(['Bucket' => $source]);
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $newLogs,
                    'TargetPrefix' => 'new/',
                ]],
            ]);

            self::restartServer();
            self::assertSame([], $this->copyLogEntries($oldLogs, 'old/'));
            self::assertSame([], $this->copyLogEntries($newLogs, 'new/'));
        } finally {
            if (is_string($uploadId)) {
                self::$s3->abortMultipartUpload([
                    'Bucket' => $destination,
                    'Key' => 'copied.txt',
                    'UploadId' => $uploadId,
                ]);
            }
            self::$s3->putBucketLogging(['Bucket' => $source, 'BucketLoggingStatus' => []]);
            foreach ([$source, $destination, $oldLogs, $newLogs] as $bucket) {
                foreach (self::$s3->listObjectsV2(['Bucket' => $bucket])['Contents'] ?? [] as $object) {
                    self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $object['Key']]);
                }
                self::$s3->deleteBucket(['Bucket' => $bucket]);
            }
        }
    }

    /** @return list<string> */
    private function copyLogEntries(string $bucket, string $prefix): array
    {
        $entries = [];
        foreach (self::$s3->listObjectsV2(['Bucket' => $bucket, 'Prefix' => $prefix])['Contents'] ?? [] as $object) {
            $content = (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => $object['Key']])['Body'];
            foreach (explode("\n", $content) as $line) {
                if ($line !== '' && str_contains($line, ' REST.COPY.')) {
                    $entries[] = $line;
                }
            }
        }

        return $entries;
    }

    public function test_signed_access_log_delivery_persists_a_versioned_log_object_on_shutdown(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $source = 'log-source-' . $suffix;
        $target = 'log-target-' . $suffix;
        self::$s3->createBucket(['Bucket' => $source]);
        self::$s3->createBucket(['Bucket' => $target]);

        try {
            self::$s3->putBucketVersioning([
                'Bucket' => $target,
                'VersioningConfiguration' => ['Status' => 'Enabled'],
            ]);
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $target,
                    'TargetPrefix' => 'access/',
                ]],
            ]);
            self::$s3->putObject(['Bucket' => $source, 'Key' => 'read-me.txt', 'Body' => 'payload']);
            $read = self::$s3->getObject(['Bucket' => $source, 'Key' => 'read-me.txt']);
            self::assertSame('payload', (string) $read['Body']);

            // The server flushes its buffered access logs during graceful shutdown.
            self::restartServer();

            $listing = self::$s3->listObjectsV2(['Bucket' => $target, 'Prefix' => 'access/']);
            $objects = $listing['Contents'] ?? [];
            self::assertNotEmpty($objects);
            $keys = array_column($objects, 'Key');
            foreach ($keys as $key) {
                self::assertMatchesRegularExpression('/^access\/\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-[A-Za-z0-9]+$/', $key);
            }

            $allLogs = '';
            foreach ($keys as $key) {
                $object = self::$s3->getObject(['Bucket' => $target, 'Key' => $key]);
                $allLogs .= (string) $object['Body'];
            }
            self::assertStringContainsString($source, $allLogs);
            self::assertStringContainsString('read-me.txt', $allLogs);

            $versions = self::$s3->listObjectVersions(['Bucket' => $target, 'Prefix' => 'access/']);
            self::assertNotEmpty($versions['Versions'] ?? []);
            self::assertNotSame('null', $versions['Versions'][0]['VersionId']);
        } finally {
            self::$s3->putBucketLogging(['Bucket' => $source, 'BucketLoggingStatus' => []]);
            $sourceObjects = self::$s3->listObjectsV2(['Bucket' => $source]);
            foreach ($sourceObjects['Contents'] ?? [] as $object) {
                self::$s3->deleteObject(['Bucket' => $source, 'Key' => $object['Key']]);
            }
            self::$s3->deleteBucket(['Bucket' => $source]);

            $versions = self::$s3->listObjectVersions(['Bucket' => $target]);
            foreach ($versions['Versions'] ?? [] as $version) {
                self::$s3->deleteObject([
                    'Bucket' => $target,
                    'Key' => $version['Key'],
                    'VersionId' => $version['VersionId'],
                ]);
            }
            self::$s3->deleteBucket(['Bucket' => $target]);
        }
    }

    public function test_log_delivery_obeys_target_encryption_quota_and_notification(): void
    {
        $suffix = bin2hex(random_bytes(4));
        $source = 'log-source-secure-' . $suffix;
        $target = 'log-target-secure-' . $suffix;
        self::$s3->createBucket(['Bucket' => $source]);
        self::$s3->createBucket(['Bucket' => $target]);
        $metadata = new SqliteMetadataStore(self::sqliteMetadataPath());
        $metadata->initialize();
        $ownerId = $metadata->getBucketOwner($target);
        self::assertNotNull($ownerId);
        $beforeBytes = $metadata->getOwnerStorageStats($ownerId)['bytesUsed'];

        try {
            self::$s3->putBucketEncryption([
                'Bucket' => $target,
                'ServerSideEncryptionConfiguration' => ['Rules' => [[
                    'ApplyServerSideEncryptionByDefault' => ['SSEAlgorithm' => 'AES256'],
                ]]],
            ]);
            self::$s3->putBucketNotificationConfiguration([
                'Bucket' => $target,
                'NotificationConfiguration' => ['TopicConfigurations' => [[
                    'Id' => 'log-delivery',
                    'TopicArn' => 'https://93.184.216.34/webhook',
                    'Events' => ['s3:ObjectCreated:Put'],
                ]]],
            ]);
            $metadata->putAccountQuota($ownerId, new QuotaConfig(maxBytesPerOwner: $beforeBytes + 4096));
            self::$s3->putBucketLogging([
                'Bucket' => $source,
                'BucketLoggingStatus' => ['LoggingEnabled' => [
                    'TargetBucket' => $target,
                    'TargetPrefix' => 'secure/',
                ]],
            ]);
            self::$s3->putObject(['Bucket' => $source, 'Key' => 'logged.txt', 'Body' => 'payload']);

            self::restartServer();
            $afterRestart = new SqliteMetadataStore(self::sqliteMetadataPath());
            $afterRestart->initialize();
            $keys = array_column(self::$s3->listObjectsV2(['Bucket' => $target, 'Prefix' => 'secure/'])['Contents'] ?? [], 'Key');
            self::assertNotEmpty($keys);
            $log = $afterRestart->getObjectMetadata($target, $keys[0]);
            self::assertNotNull($log);
            self::assertSame('AES256', $log->userMetadata['__sse-algorithm'] ?? null);
            $storagePath = $log->systemMetadata['storagePath'] ?? null;
            self::assertIsString($storagePath);
            self::assertNotSame('', $storagePath);
            $ciphertext = file_get_contents($storagePath);
            self::assertIsString($ciphertext);
            self::assertStringNotContainsString('logged.txt', $ciphertext);
            $response = self::$s3->getObject(['Bucket' => $target, 'Key' => $keys[0]]);
            self::assertStringContainsString('logged.txt', (string) $response['Body']);
            self::assertSame(['pending' => 1], $afterRestart->getNotificationQueueStats());
            self::assertGreaterThan($beforeBytes, $afterRestart->getOwnerStorageStats($ownerId)['bytesUsed']);
            self::assertLessThanOrEqual($beforeBytes + 4096, $afterRestart->getOwnerStorageStats($ownerId)['bytesUsed']);
        } finally {
            $metadata->deleteAccountQuota($ownerId);
            self::$s3->putBucketLogging(['Bucket' => $source, 'BucketLoggingStatus' => []]);
            foreach (self::$s3->listObjectsV2(['Bucket' => $source])['Contents'] ?? [] as $object) {
                self::$s3->deleteObject(['Bucket' => $source, 'Key' => $object['Key']]);
            }
            self::$s3->deleteBucket(['Bucket' => $source]);
            foreach (self::$s3->listObjectsV2(['Bucket' => $target])['Contents'] ?? [] as $object) {
                self::$s3->deleteObject(['Bucket' => $target, 'Key' => $object['Key']]);
            }
            self::$s3->deleteBucket(['Bucket' => $target]);
        }
    }
}
