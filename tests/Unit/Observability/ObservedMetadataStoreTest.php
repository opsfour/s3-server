<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Observability;

use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use PHPUnit\Framework\TestCase;

final class ObservedMetadataStoreTest extends TestCase
{
    private string $databasePath;

    protected function tearDown(): void
    {
        if (isset($this->databasePath) && is_file($this->databasePath)) {
            unlink($this->databasePath);
        }
    }

    public function test_records_successful_metadata_operations(): void
    {
        $metrics = new MetricsCollector();
        $metadata = $this->metadata($metrics, slowThresholdNs: 0);

        $metadata->initialize();
        $metadata->createBucket('owner', 'observed-metadata-success', 'us-east-1');
        $owner = $metadata->getBucketOwner('observed-metadata-success');

        self::assertSame('owner', $owner);

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="sqlite",operation="initialize"} 1', $rendered);
        self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="sqlite",operation="createBucket"} 1', $rendered);
        self::assertStringContainsString('s3_server_backend_operations_total{backend="metadata",driver="sqlite",operation="getBucketOwner"} 1', $rendered);
        self::assertStringContainsString('s3_server_backend_slow_operations_total{backend="metadata",driver="sqlite",operation="createBucket"} 1', $rendered);
    }

    public function test_records_failed_metadata_operations_and_rethrows(): void
    {
        $metrics = new MetricsCollector();
        $metadata = $this->metadata($metrics);

        $metadata->initialize();
        $metadata->createBucket('owner', 'observed-metadata-failure', 'us-east-1');

        $this->expectException(BucketAlreadyExistsException::class);

        try {
            $metadata->createBucket('owner', 'observed-metadata-failure', 'us-east-1');
        } finally {
            self::assertStringContainsString(
                's3_server_backend_errors_total{backend="metadata",driver="sqlite",exception="' . str_replace('\\', '\\\\', BucketAlreadyExistsException::class) . '",operation="createBucket"} 1',
                $metrics->renderPrometheus(),
            );
        }
    }

    private function metadata(MetricsCollector $metrics, int $slowThresholdNs = 250_000_000): ObservedMetadataStore
    {
        $databasePath = tempnam(sys_get_temp_dir(), 's3-observed-metadata-');
        self::assertIsString($databasePath);
        $this->databasePath = $databasePath;

        return new ObservedMetadataStore(
            new SqliteMetadataStore($this->databasePath),
            $metrics,
            'sqlite',
            $slowThresholdNs,
        );
    }
}
