<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\CommandPool;
use Aws\ResultInterface;
use Aws\S3\S3Client;
use Aws\S3\Exception\S3Exception;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;

final class QuotaFunctionalTest extends S3FunctionalTestCase
{
    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    private static string $bucket = '';

    private static string $credentialsPath = '';

    public static function setUpBeforeClass(): void
    {
        self::$credentialsPath = tempnam(sys_get_temp_dir(), 's3-quota-creds-') ?: '';
        file_put_contents(self::$credentialsPath, json_encode([
            [
                'accessKeyId' => self::$accessKey,
                'secretAccessKey' => self::$secretKey,
                'ownerId' => 'shared-account',
                'displayName' => 'Shared Account Primary',
            ],
            [
                'accessKeyId' => 'sameOwnerAccessKey',
                'secretAccessKey' => 'sameOwnerSecretKey',
                'ownerId' => 'shared-account',
                'displayName' => 'Shared Account Secondary',
            ],
            [
                'accessKeyId' => 'otherOwnerAccessKey',
                'secretAccessKey' => 'otherOwnerSecretKey',
                'ownerId' => 'other-account',
                'displayName' => 'Other Account',
            ],
        ], JSON_THROW_ON_ERROR));

        self::setQuotaEnv([
            'S3_CREDENTIALS_DRIVER' => 'file',
            'S3_CREDENTIALS_PATH' => self::$credentialsPath,
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '2',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '10',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '20',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '40',
        ]);

        parent::setUpBeforeClass();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        self::restoreQuotaEnv();

        if (self::$credentialsPath !== '') {
            @unlink(self::$credentialsPath);
            self::$credentialsPath = '';
        }
    }

    public function test_bucket_quota_is_enforced_per_owner(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '2',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ]);

        self::$bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$s3->createBucket(['Bucket' => self::$bucket . '-second']);

        try {
            self::$s3->createBucket(['Bucket' => self::$bucket . '-third']);
            $this->fail('Expected bucket quota to reject the third bucket.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_object_count_quota_allows_overwrites_but_rejects_new_keys(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '10',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ]);

        self::$bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);

        for ($i = 0; $i < 10; $i++) {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => "count/{$i}.txt",
                'Body' => 'x',
            ]);
        }

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'count/0.txt',
            'Body' => 'yy',
        ]);

        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => 'count/overflow.txt',
                'Body' => 'z',
            ]);
            $this->fail('Expected object-count quota to reject a new key.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }
    }

    public function test_bucket_byte_quota_counts_overwrite_delta(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '20',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);

        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'bytes/a.bin',
            'Body' => str_repeat('a', 8),
        ]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'bytes/b.bin',
            'Body' => str_repeat('b', 8),
        ]);

        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'bytes/a.bin',
            'Body' => str_repeat('a', 10),
        ]);

        try {
            self::$s3->putObject([
                'Bucket' => $bucket,
                'Key' => 'bytes/c.bin',
                'Body' => str_repeat('c', 3),
            ]);
            $this->fail('Expected bucket byte quota to reject an overflowing write.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }
    }

    public function test_versioning_counts_each_new_version_against_quota(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '2',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '100',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '100',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);

        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'same-key.txt', 'Body' => 'v1']);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'same-key.txt', 'Body' => 'v2']);

        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'same-key.txt', 'Body' => 'v3']);
            $this->fail('Expected versioning quota to count each object version.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }
    }

    public function test_delete_marker_does_not_count_against_versioned_object_quota(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '1',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '1',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '1',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putBucketVersioning([
            'Bucket' => $bucket,
            'VersioningConfiguration' => ['Status' => 'Enabled'],
        ]);

        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'versioned.txt', 'Body' => 'x']);
        self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'versioned.txt']);

        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'other.txt', 'Body' => 'y']);
            $this->fail('Expected real object version to keep object/byte quota full; delete marker must not be counted.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }

        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        $stats = $metadata->getBucketStorageStats($bucket);

        $this->assertSame(['objectCount' => 1, 'bytesUsed' => 1], $stats);
    }

    public function test_lifecycle_expiration_releases_bucket_and_owner_byte_quota(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '1',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '5',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '5',
            'S3_LIFECYCLE_INTERVAL_SECONDS' => '3600',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'expired.bin',
            'Body' => '12345',
        ]);

        try {
            self::$s3->putObject([
                'Bucket' => $bucket,
                'Key' => 'blocked.bin',
                'Body' => 'x',
            ]);
            $this->fail('Expected quota to be full before lifecycle expiration.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }

        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();
        $metadata->putBucketLifecycle($bucket, [[
            'id' => 'expire-all',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
        self::ageObjectForLifecycle($metadata, $bucket, 'expired.bin', 5);

        $executor = new LifecycleExecutor(
            $metadata,
            new FilesystemBackend(self::$storagePath),
            batchSize: 10,
            maxActionsPerRun: 10,
        );
        $executor->processBucket($bucket);

        $this->assertNull($metadata->getObjectMetadata($bucket, 'expired.bin'));
        $this->assertSame(['objectCount' => 0, 'bytesUsed' => 0], $metadata->getBucketStorageStats($bucket));
        unset($metadata);

        self::restartServer();

        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'replacement.bin',
            'Body' => 'abcde',
        ]);

        $this->assertSame('abcde', (string) self::$s3->getObject([
            'Bucket' => $bucket,
            'Key' => 'replacement.bin',
        ])['Body']);
    }

    public function test_copy_object_respects_bucket_byte_quota(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '5',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject([
            'Bucket' => $bucket,
            'Key' => 'source.txt',
            'Body' => 'abc',
        ]);

        try {
            self::$s3->copyObject([
                'Bucket' => $bucket,
                'Key' => 'copy.txt',
                'CopySource' => "/{$bucket}/source.txt",
            ]);
            $this->fail('Expected CopyObject to respect bucket byte quota.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }

        $list = self::$s3->listObjectsV2(['Bucket' => $bucket]);
        $this->assertSame(['source.txt'], array_map(
            static fn(array $object): string => $object['Key'],
            $list['Contents'] ?? [],
        ));
    }

    public function test_multipart_complete_respects_bucket_byte_quota(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '5',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        $create = self::$s3->createMultipartUpload([
            'Bucket' => $bucket,
            'Key' => 'multipart.bin',
        ]);
        $uploadId = $create['UploadId'];

        $partOne = self::$s3->uploadPart([
            'Bucket' => $bucket,
            'Key' => 'multipart.bin',
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'abc',
        ]);
        $partTwo = self::$s3->uploadPart([
            'Bucket' => $bucket,
            'Key' => 'multipart.bin',
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'Body' => 'def',
        ]);

        try {
            self::$s3->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => 'multipart.bin',
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => [
                        ['PartNumber' => 1, 'ETag' => $partOne['ETag']],
                        ['PartNumber' => 2, 'ETag' => $partTwo['ETag']],
                    ],
                ],
            ]);
            $this->fail('Expected CompleteMultipartUpload to respect bucket byte quota.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }

        self::$s3->abortMultipartUpload([
            'Bucket' => $bucket,
            'Key' => 'multipart.bin',
            'UploadId' => $uploadId,
        ]);

        $list = self::$s3->listObjectsV2(['Bucket' => $bucket]);
        $this->assertSame(0, count($list['Contents'] ?? []));
    }

    public function test_concurrent_puts_do_not_overbook_object_quota(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '5',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '100',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '100',
        ]);

        $bucket = 'quota-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);

        $commands = [];
        for ($i = 0; $i < 20; $i++) {
            $commands[] = self::$s3->getCommand('PutObject', [
                'Bucket' => $bucket,
                'Key' => "parallel/{$i}.txt",
                'Body' => 'x',
            ]);
        }

        $rejectedCodes = [];
        $fulfilled = 0;
        $pool = new CommandPool(self::$s3, $commands, [
            'concurrency' => 20,
            'fulfilled' => static function (ResultInterface $result) use (&$fulfilled): void {
                $fulfilled++;
            },
            'rejected' => static function ($reason) use (&$rejectedCodes): void {
                $rejectedCodes[] = $reason instanceof S3Exception
                    ? $reason->getAwsErrorCode()
                    : ($reason instanceof \Throwable ? $reason->getMessage() : (string) $reason);
            },
        ]);

        $pool->promise()->wait();

        $list = self::$s3->listObjectsV2([
            'Bucket' => $bucket,
            'Prefix' => 'parallel/',
        ]);
        $stored = count($list['Contents'] ?? []);

        $this->assertSame(5, $fulfilled, self::serverLogs());
        $this->assertSame(5, $stored, self::serverLogs());
        $this->assertCount(15, $rejectedCodes, self::serverLogs());
        $this->assertSame(
            [],
            array_values(array_filter($rejectedCodes, static fn(string $code): bool => $code !== 'QuotaExceeded')),
            self::serverLogs(),
        );
    }

    public function test_account_specific_quota_is_shared_by_credentials_with_same_owner_id(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '10',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ], [
            'shared-account' => new QuotaConfig(maxBucketsPerOwner: 1),
            'other-account' => new QuotaConfig(maxBucketsPerOwner: 2),
        ]);

        $primary = self::$s3;
        $sameAccount = self::makeClientFor('sameOwnerAccessKey', 'sameOwnerSecretKey');
        $otherAccount = self::makeClientFor('otherOwnerAccessKey', 'otherOwnerSecretKey');

        $sharedBucket = 'quota-' . bin2hex(random_bytes(4));
        self::createBucketOrFail($primary, $sharedBucket);

        try {
            $sameAccount->createBucket(['Bucket' => $sharedBucket . '-same-owner']);
            $this->fail('Expected credentials sharing the same ownerId to share the bucket quota.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }

        $otherBucketOne = 'quota-' . bin2hex(random_bytes(4));
        $otherBucketTwo = 'quota-' . bin2hex(random_bytes(4));
        self::createBucketOrFail($otherAccount, $otherBucketOne);
        self::createBucketOrFail($otherAccount, $otherBucketTwo);

        try {
            $otherAccount->createBucket(['Bucket' => $otherBucketTwo . '-overflow']);
            $this->fail('Expected account-specific quota to reject the third bucket for other-account.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }
    }

    public function test_account_specific_quota_overrides_global_fallback(): void
    {
        self::restartWithFreshStorageAndQuota([
            'S3_QUOTA_MAX_BUCKETS_PER_OWNER' => '1',
            'S3_QUOTA_MAX_OBJECTS_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_BUCKET' => '0',
            'S3_QUOTA_MAX_BYTES_PER_OWNER' => '0',
        ], [
            'other-account' => new QuotaConfig(maxBucketsPerOwner: 2),
        ]);

        $otherAccount = self::makeClientFor('otherOwnerAccessKey', 'otherOwnerSecretKey');
        self::createBucketOrFail($otherAccount, 'quota-' . bin2hex(random_bytes(4)));
        self::createBucketOrFail($otherAccount, 'quota-' . bin2hex(random_bytes(4)));

        self::createBucketOrFail(self::$s3, 'quota-' . bin2hex(random_bytes(4)));

        try {
            self::$s3->createBucket(['Bucket' => 'quota-' . bin2hex(random_bytes(4))]);
            $this->fail('Expected global fallback quota to apply when no account-specific quota exists.');
        } catch (S3Exception $e) {
            $this->assertSame('QuotaExceeded', $e->getAwsErrorCode());
        }
    }

    /**
     * @param array<string, string> $env
     * @param array<string, QuotaConfig> $accountQuotas
     */
    private static function restartWithFreshStorageAndQuota(array $env, array $accountQuotas = []): void
    {
        self::setQuotaEnv($env);
        $oldStoragePath = self::$storagePath;
        self::$storagePath = sys_get_temp_dir() . '/s3-test-' . uniqid();
        mkdir(self::$storagePath, 0o755, true);
        self::$bucket = '';

        if ($accountQuotas !== []) {
            self::putAccountQuotas($accountQuotas);
        }

        self::restartServer();

        if ($oldStoragePath !== '' && is_dir($oldStoragePath)) {
            self::recursiveDeleteLocal($oldStoragePath);
        }
    }

    private static function putAccountQuotas(array $quotas): void
    {
        $metadata = new SqliteMetadataStore(self::$storagePath . '/metadata.sqlite');
        $metadata->initialize();

        foreach ($quotas as $ownerId => $quota) {
            $metadata->putAccountQuota($ownerId, $quota);
        }

        unset($metadata);
    }

    private static function makeClientFor(string $accessKey, string $secretKey): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout' => 30,
            ],
        ]);
    }

    private static function ageObjectForLifecycle(SqliteMetadataStore $metadata, string $bucket, string $key, int $days): void
    {
        $reflection = new \ReflectionClass($metadata);
        $method = $reflection->getMethod('connection');
        $method->setAccessible(true);
        /** @var \PDO $pdo */
        $pdo = $method->invoke($metadata);
        $stmt = $pdo->prepare(
            'UPDATE s3_objects SET created_at = ?, updated_at = ? WHERE bucket = ? AND key_name = ?',
        );
        $past = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d\TH:i:s\Z');
        $stmt->execute([$past, $past, $bucket, $key]);
    }

    private static function createBucketOrFail(S3Client $client, string $bucket): void
    {
        try {
            $client->createBucket(['Bucket' => $bucket]);
        } catch (S3Exception $e) {
            self::fail($e->getMessage() . "\n\n" . self::serverLogs());
        }
    }

    private static function restoreQuotaEnv(): void
    {
        foreach (self::$previousEnv as $key => $value) {
            if ($value === false) {
                putenv($key);
                unset($_ENV[$key], $_SERVER[$key]);
            } else {
                putenv("{$key}={$value}");
                $_ENV[$key] = $value;
                $_SERVER[$key] = $value;
            }
        }

        self::$previousEnv = [];
    }

    /**
     * @param array<string, string> $env
     */
    private static function setQuotaEnv(array $env): void
    {
        foreach ($env as $key => $value) {
            if (! array_key_exists($key, self::$previousEnv)) {
                self::$previousEnv[$key] = getenv($key);
            }

            putenv("{$key}={$value}");
            $_ENV[$key] = $value;
            $_SERVER[$key] = $value;
        }
    }

    private static function recursiveDeleteLocal(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
