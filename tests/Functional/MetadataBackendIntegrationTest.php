<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Quota\QuotaConfig;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MetadataBackendIntegrationTest extends TestCase
{
    /**
     * @return iterable<string, array{string, string}>
     */
    public static function externalMetadataDrivers(): iterable
    {
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
    }

    #[DataProvider('externalMetadataDrivers')]
    public function test_external_metadata_backend_round_trip_and_observability(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run {$driver} metadata backend integration tests.");
        }

        $metrics = new MetricsCollector();
        $metadata = new ObservedMetadataStore(
            MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]),
            $metrics,
            $driver,
            slowThresholdNs: 0,
        );

        $ownerId = 'metadata-it-' . bin2hex(random_bytes(4));
        $bucket = 'metadata-it-' . bin2hex(random_bytes(8));
        $namedPolicy = 'metadata-it-' . bin2hex(random_bytes(4));

        try {
            $this->cleanup($metadata, $ownerId, $bucket, $namedPolicy);

            $metadata->createBucket($ownerId, $bucket, 'us-east-1');
            self::assertSame($ownerId, $metadata->getBucketOwner($bucket));
            self::assertTrue($metadata->bucketExists($bucket));

            $metadata->putObjectMetadata(
                bucket: $bucket,
                key: 'object.txt',
                ownerId: $ownerId,
                size: 12,
                etag: '"etag"',
                contentType: 'text/plain',
                storagePath: $bucket . '/object.txt',
                userMetadata: ['source' => 'integration'],
            );
            self::assertSame(1, $metadata->countObjects($bucket));
            self::assertSame(12, $metadata->getObjectMetadata($bucket, 'object.txt')?->size);

            $quota = new QuotaConfig(maxBucketsPerOwner: 2, maxObjectsPerBucket: 3, maxBytesPerBucket: 1024, maxBytesPerOwner: 2048);
            $metadata->putAccountQuota($ownerId, $quota);
            self::assertSame($quota->maxBytesPerOwner, $metadata->getAccountQuota($ownerId)?->maxBytesPerOwner);

            $policy = '{"Version":"2012-10-17","Statement":[]}';
            $metadata->putNamedPolicy($namedPolicy, $policy);
            self::assertSame($policy, $metadata->getNamedPolicy($namedPolicy));

            try {
                $metadata->createBucket($ownerId, $bucket, 'us-east-1');
                self::fail('Expected duplicate bucket creation to fail.');
            } catch (BucketAlreadyExistsException) {
                self::assertTrue(true);
            }

            $rendered = $metrics->renderPrometheus();
            self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="' . $driver . '",operation="createBucket"} 2', $rendered);
            self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="' . $driver . '",operation="putObjectMetadata"} 1', $rendered);
            self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="' . $driver . '",operation="putAccountQuota"} 1', $rendered);
            self::assertStringContainsString('s3_server_backend_slow_operations_total{backend="metadata",driver="' . $driver . '",operation="createBucket"} 2', $rendered);
            self::assertStringContainsString(
                's3_server_backend_errors_total{backend="metadata",driver="' . $driver . '",exception="' . str_replace('\\', '\\\\', BucketAlreadyExistsException::class) . '",operation="createBucket"} 1',
                $rendered,
            );
        } finally {
            $this->cleanup($metadata, $ownerId, $bucket, $namedPolicy);
        }
    }

    private function cleanup(MetadataStore $metadata, string $ownerId, string $bucket, string $namedPolicy): void
    {
        try {
            $metadata->deleteObjectMetadata($bucket, 'object.txt');
        } catch (\Throwable) {
        }

        try {
            $metadata->deleteBucket($ownerId, $bucket);
        } catch (\Throwable) {
        }

        try {
            $metadata->deleteAccountQuota($ownerId);
        } catch (\Throwable) {
        }

        try {
            $metadata->deleteNamedPolicy($namedPolicy);
        } catch (\Throwable) {
        }
    }
}
