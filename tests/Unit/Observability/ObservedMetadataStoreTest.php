<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Observability;

use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
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

    public function test_records_each_normal_and_recovery_cleanup_rejection_once(): void
    {
        $metrics = new MetricsCollector();
        $metadata = $this->metadata($metrics);
        $metadata->initialize();
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 900000 WHERE id = 1');
        try {
            $metadata->enqueueStorageGarbage('bucket', 'STANDARD', 'normal');
            self::fail('Normal cleanup capacity rejection was not propagated.');
        } catch (StorageCleanupCapacityException) {
        }

        $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 1000000 WHERE id = 1');
        try {
            $metadata->enqueueRecoveryStorageGarbage('bucket', 'STANDARD', 'recovery');
            self::fail('Recovery cleanup capacity rejection was not propagated.');
        } catch (StorageCleanupCapacityException) {
        }

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="normal"} 1', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="recovery"} 1', $rendered);
    }

    public function test_nested_observed_and_cached_chains_count_each_rejection_once(): void
    {
        $metrics = new MetricsCollector();
        $inner = $this->metadata($metrics);
        $metadata = new ObservedMetadataStore(
            new CachedMetadataStoreDecorator($inner),
            $metrics,
            'outer',
        );
        $metadata->initialize();
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        foreach ([
            'normal' => 900_000,
            'recovery' => 1_000_000,
        ] as $admission => $count) {
            $pdo->exec("UPDATE s3_storage_cleanup_capacity SET retained_count = {$count} WHERE id = 1");
            try {
                if ($admission === 'normal') {
                    $metadata->enqueueStorageGarbage('bucket', 'STANDARD', 'normal');
                } else {
                    $metadata->enqueueRecoveryStorageGarbage('bucket', 'STANDARD', 'recovery');
                }
                self::fail("{$admission} cleanup capacity rejection was not propagated.");
            } catch (StorageCleanupCapacityException) {
            }
        }

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="normal"} 1', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="recovery"} 1', $rendered);
        self::assertStringNotContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="normal"} 2', $rendered);
        self::assertStringNotContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="recovery"} 2', $rendered);
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
