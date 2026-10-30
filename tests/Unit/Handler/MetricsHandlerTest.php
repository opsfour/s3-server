<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\Driver\Client;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Handler\MetricsHandler;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartCompletionCapacityReader;
use OpsFour\S3Server\Metadata\WriteIntentCapacityReader;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Runtime\ClusterMembershipStore;
use OpsFour\S3Server\Runtime\ClusterMembershipSnapshot;
use OpsFour\S3Server\Runtime\ClusterOperationalSnapshot;
use OpsFour\S3Server\Runtime\ClusterOperationalSnapshotStore;
use OpsFour\S3Server\Runtime\ClusterRoleController;
use OpsFour\S3Server\Runtime\ClusterRoleSet;
use OpsFour\S3Server\Runtime\ClusterWorkSnapshot;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use PHPUnit\Framework\TestCase;

final class MetricsHandlerTest extends TestCase
{
    public function test_authenticated_registered_cluster_endpoint_emits_representative_snapshot_samples(): void
    {
        $store = new MetricsClusterOperationalStore();
        $roles = new ClusterRoleController($store, self::clusterConfig());
        $roles->registerReady();
        $handler = new MetricsHandler(new MetricsCollector(), bearerToken: 'token', clusterRoles: $roles);

        self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
        $response = $handler->handleRequest($this->request('Bearer token'));
        $rendered = \Amp\ByteStream\buffer($response->getBody());

        self::assertSame(200, $response->getStatus());
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $rendered);
        self::assertStringContainsString('s3_server_cluster_role_processes{role="reconcile",state="ready"} 1', $rendered);
        self::assertStringContainsString('s3_server_cluster_work_items{queue="notification",state="pending"} 2', $rendered);
    }

    public function test_cluster_snapshot_failure_clears_previous_role_and_work_samples(): void
    {
        $store = new MetricsClusterOperationalStore();
        $roles = new ClusterRoleController($store, self::clusterConfig());
        $roles->registerReady();
        $handler = new MetricsHandler(new MetricsCollector(), clusterRoles: $roles);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $first);
        self::assertStringContainsString('s3_server_cluster_role_processes{role="reconcile",state="ready"} 1', $first);
        self::assertStringContainsString('s3_server_cluster_work_items{queue="notification",state="pending"} 2', $first);

        $store->snapshotFailure = true;
        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_up 1', $second);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_cluster_role_processes/m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_cluster_work_items/m', $second);
    }

    public function test_cluster_snapshot_timeout_preserves_process_metrics_and_clears_cluster_samples(): void
    {
        $store = new MetricsClusterOperationalStore();
        $store->snapshotDelaySeconds = 0.05;
        $roles = new ClusterRoleController($store, self::clusterConfig());
        $roles->registerReady();
        $metrics = new MetricsCollector();
        $handler = new MetricsHandler(
            $metrics,
            clusterRoles: $roles,
            clusterSnapshotTimeoutSeconds: 0.001,
        );

        $rendered = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());

        self::assertStringContainsString('s3_server_up 1', $rendered);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_cluster_role_processes/m', $rendered);

        $rendered = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $rendered);
        self::assertSame(1, $store->snapshotCalls);

        \Amp\delay(0.06);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $metrics->renderPrometheus());
        $rendered = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $rendered);
        self::assertSame(1, $store->snapshotCalls);
    }

    public function test_blocking_cluster_capacity_read_is_bounded_and_reuses_single_flight(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MultipartCompletionCapacityReader::class,
            WriteIntentCapacityReader::class,
            StorageCleanupCapacityReader::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $multipartCalls = 0;
        $gate = new MetricsBlockingCapacityGate();
        $metadata->method('getMultipartCompletionIntentCount')->willReturnCallback(
            static function () use (&$multipartCalls, $gate): int {
                ++$multipartCalls;
                if ($gate->blocked) {
                    \Amp\delay(0.05);
                }

                return 2;
            },
        );
        $metadata->method('getWriteIntentCapacityCounts')->willReturn(['object' => 3, 'object_seal' => 4, 'part' => 5]);
        $metadata->method('getStorageCleanupCount')->willReturn(6);
        $store = new MetricsClusterOperationalStore();
        $roles = new ClusterRoleController($store, self::clusterConfig());
        $roles->registerReady();
        $metrics = new MetricsCollector();
        $handler = new MetricsHandler(
            $metrics,
            $metadata,
            clusterRoles: $roles,
            clusterSnapshotTimeoutSeconds: 0.001,
        );

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_multipart_completion_intents 2', $first);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $first);

        $gate->blocked = true;
        $startedAt = hrtime(true);
        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertLessThan(0.04, (hrtime(true) - $startedAt) / 1e9);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_items /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_cluster_role_processes/m', $second);

        $third = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $third);
        self::assertSame(2, $multipartCalls);
        self::assertSame(1, $store->snapshotCalls);

        \Amp\delay(0.06);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $metrics->renderPrometheus());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $metrics->renderPrometheus());
        $fourth = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_multipart_completion_intents 2', $fourth);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $fourth);
        self::assertSame(2, $multipartCalls);
        self::assertSame(2, $store->snapshotCalls);
    }

    public function test_cluster_capacity_failures_clear_stale_samples_without_aborting_scrape(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MultipartCompletionCapacityReader::class,
            WriteIntentCapacityReader::class,
            StorageCleanupCapacityReader::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $multipartCalls = 0;
        $writeCalls = 0;
        $cleanupCalls = 0;
        $metadata->method('getMultipartCompletionIntentCount')->willReturnCallback(
            static function () use (&$multipartCalls): int {
                if (++$multipartCalls > 1) {
                    throw new \RuntimeException('multipart unavailable');
                }

                return 2;
            },
        );
        $metadata->method('getWriteIntentCapacityCounts')->willReturnCallback(
            static function () use (&$writeCalls): array {
                if (++$writeCalls > 1) {
                    throw new \RuntimeException('write unavailable');
                }

                return ['object' => 3, 'object_seal' => 4, 'part' => 5];
            },
        );
        $metadata->method('getStorageCleanupCount')->willReturnCallback(
            static function () use (&$cleanupCalls): int {
                if (++$cleanupCalls > 1) {
                    throw new \RuntimeException('cleanup unavailable');
                }

                return 6;
            },
        );
        $store = new MetricsClusterOperationalStore();
        $roles = new ClusterRoleController($store, self::clusterConfig());
        $roles->registerReady();
        $handler = new MetricsHandler(new MetricsCollector(), $metadata, clusterRoles: $roles);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_multipart_completion_intents 2', $first);
        self::assertStringContainsString('s3_server_object_write_intents 3', $first);
        self::assertStringContainsString('s3_server_storage_cleanup_items 6', $first);

        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_up 1', $second);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_items /m', $second);
    }

    public function test_standalone_scrape_omits_all_cluster_metric_families(): void
    {
        $rendered = \Amp\ByteStream\buffer((new MetricsHandler(new MetricsCollector()))
            ->handleRequest($this->request())->getBody());

        self::assertStringContainsString('s3_server_up 1', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_cluster_/m', $rendered);
    }

    public function test_cluster_scrape_keeps_capacity_samples_without_grouped_notification_stats(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MultipartCompletionCapacityReader::class,
            WriteIntentCapacityReader::class,
            StorageCleanupCapacityReader::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->expects(self::never())->method('getNotificationQueueStats');
        $metadata->method('getMultipartCompletionIntentCount')->willReturn(2);
        $metadata->method('getWriteIntentCapacityCounts')->willReturn(['object' => 3, 'object_seal' => 4, 'part' => 5]);
        $metadata->method('getStorageCleanupCount')->willReturn(6);
        $roles = new ClusterRoleController(new MetricsClusterOperationalStore(), self::clusterConfig());

        $rendered = \Amp\ByteStream\buffer((new MetricsHandler(new MetricsCollector(), $metadata, clusterRoles: $roles))
            ->handleRequest($this->request())->getBody());

        self::assertStringContainsString('s3_server_multipart_completion_intents 2', $rendered);
        self::assertStringContainsString('s3_server_object_write_intents 3', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_items 6', $rendered);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $rendered);
    }

    public function test_temp_snapshot_failure_preserves_prometheus_scrape(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-metrics-temp-' . bin2hex(random_bytes(8));
        $moved = $tempDir . '-moved';
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 10, 0);
        try {
            self::assertTrue(rename($tempDir, $moved));
            self::assertTrue(mkdir($tempDir, 0700));
            $body = \Amp\ByteStream\buffer((new MetricsHandler(
                new MetricsCollector(),
                tempBudget: $coordinator,
            ))->handleRequest($this->request())->getBody());
            self::assertStringContainsString('s3_server_up 1', $body);
            self::assertStringContainsString('s3_server_node_temp_snapshot_success 0', $body);
            self::assertDoesNotMatchRegularExpression('/^s3_server_node_temp_budget_bytes /m', $body);
        } finally {
            @rmdir($tempDir);
            @rename($moved, $tempDir);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_node_temp_capacity_scrape_exposes_fixed_cardinality_gauges(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-metrics-temp-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $coordinator = new StorageTempBudgetCoordinator($tempDir, 10, 0);
        $path = $tempDir . '/s3fw-put-metric';
        $reservation = $coordinator->start($path);
        $coordinator->reserve($reservation, 3);
        self::assertSame(3, file_put_contents($path, 'abc'));

        try {
            $rendered = \Amp\ByteStream\buffer((new MetricsHandler(
                new MetricsCollector(),
                tempBudget: $coordinator,
            ))->handleRequest($this->request())->getBody());
            self::assertStringContainsString('s3_server_node_temp_budget_bytes 10', $rendered);
            self::assertStringContainsString('s3_server_node_temp_reserved_bytes 3', $rendered);
            self::assertStringContainsString('s3_server_node_temp_active_reservations 1', $rendered);
            self::assertStringContainsString('s3_server_node_temp_unsafe_entries 0', $rendered);
            self::assertStringContainsString('s3_server_node_temp_orphaned_bytes 0', $rendered);
            self::assertStringContainsString('s3_server_node_temp_snapshot_success 1', $rendered);
            self::assertDoesNotMatchRegularExpression('/^s3_server_node_temp_[^ ]+\{/m', $rendered);
        } finally {
            @unlink($path);
            $coordinator->release($reservation);
            $coordinator->close();
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $entry) {
                @unlink($entry);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }

    public function test_cached_observed_store_records_each_scrape_read_once_with_the_real_driver(): void
    {
        $path = sys_get_temp_dir() . '/s3-metrics-wrapper-order-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $metrics = new MetricsCollector();
            $metadata = new CachedMetadataStoreDecorator(new ObservedMetadataStore($store, $metrics, 'sqlite'));
            $handler = new MetricsHandler($metrics, $metadata);

            $rendered = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());

            foreach ([
                'getNotificationQueueStats',
                'getMultipartCompletionIntentCount',
                'getWriteIntentCapacityCounts',
                'getStorageCleanupCount',
            ] as $operation) {
                self::assertStringContainsString(
                    's3_server_backend_operations_total{backend="metadata",driver="sqlite",operation="' . $operation . '"} 1',
                    $rendered,
                );
            }
            self::assertStringNotContainsString('driver="CachedMetadataStoreDecorator"', $rendered);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_storage_cleanup_reader_is_refreshed_and_clears_an_unavailable_sample(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, StorageCleanupCapacityReader::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $metadata->expects(self::exactly(2))->method('getStorageCleanupCount')
            ->willReturnOnConsecutiveCalls(899_999, null);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_storage_cleanup_items 899999', $first);
        self::assertStringContainsString('s3_server_storage_cleanup_capacity_remaining{admission="normal"} 1', $first);
        self::assertStringContainsString('s3_server_storage_cleanup_capacity_remaining{admission="recovery"} 100001', $first);

        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_items /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_capacity_remaining/m', $second);
    }

    public function test_completion_capacity_metric_reads_persisted_counter_on_each_authorized_scrape(): void
    {
        $path = sys_get_temp_dir() . '/s3-metrics-completion-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $pdo = new \PDO('sqlite:' . $path);
            $handler = new MetricsHandler(new MetricsCollector(), $store, 'token');

            $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_multipart_completion_intents 0', $first);
            self::assertStringContainsString('s3_server_multipart_completion_capacity_remaining 1000000', $first);

            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 12 WHERE id = 1');
            $next = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_multipart_completion_intents 12', $next);
            self::assertStringContainsString('s3_server_multipart_completion_capacity_remaining 999988', $next);

            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 0.5 WHERE id = 1');
            try {
                $handler->handleRequest($this->request('Bearer token'));
                self::fail('An authorized metrics scrape accepted a fractional completion counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is unavailable', $error->getMessage());
            }
            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 12 WHERE id = 1');

            $pdo->exec('DELETE FROM s3_multipart_completion_capacity WHERE id = 1');
            self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
            $this->expectException(\RuntimeException::class);
            $handler->handleRequest($this->request('Bearer token'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_custom_metadata_store_without_capacity_reader_omits_gauge(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $observed = new ObservedMetadataStore($metadata, new MetricsCollector(), 'custom');
        $handler = new MetricsHandler(new MetricsCollector(), $observed);

        $rendered = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_capacity_remaining /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_intents /m', $rendered);
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_items /m', $rendered);
    }

    public function test_write_intent_capacity_metrics_read_both_persisted_rows_and_fail_closed(): void
    {
        $path = sys_get_temp_dir() . '/s3-metrics-write-intents-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $pdo = new \PDO('sqlite:' . $path);
            $cached = new CachedMetadataStoreDecorator($store);
            $handler = new MetricsHandler(new MetricsCollector(), new ObservedMetadataStore($cached, new MetricsCollector(), 'sqlite'), 'token');

            $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_object_write_intents 0', $first);
            self::assertStringContainsString('s3_server_multipart_part_write_capacity_remaining 1000000', $first);

            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 19 WHERE kind = 'object'");
            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 23 WHERE kind = 'part'");
            $next = \Amp\ByteStream\buffer($handler->handleRequest($this->request('Bearer token'))->getBody());
            self::assertStringContainsString('s3_server_object_write_intents 19', $next);
            self::assertStringContainsString('s3_server_object_write_capacity_remaining 999981', $next);
            self::assertStringContainsString('s3_server_multipart_part_write_intents 23', $next);
            self::assertStringContainsString('s3_server_multipart_part_write_capacity_remaining 999977', $next);

            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 0.5 WHERE kind = 'object'");
            try {
                $handler->handleRequest($this->request('Bearer token'));
                self::fail('An authorized metrics scrape accepted a fractional counter.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('counter is unavailable', $error->getMessage());
            }
            $pdo->exec("UPDATE s3_write_intent_capacity SET retained_count = 19 WHERE kind = 'object'");

            $pdo->exec("DELETE FROM s3_write_intent_capacity WHERE kind = 'part'");
            self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
            $this->expectException(\RuntimeException::class);
            $handler->handleRequest($this->request('Bearer token'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_write_intent_reader_losing_its_sample_clears_both_gauges(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, WriteIntentCapacityReader::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $metadata->expects(self::exactly(2))->method('getWriteIntentCapacityCounts')
            ->willReturnOnConsecutiveCalls(['object' => 7, 'object_seal' => 3, 'part' => 9], null);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_object_write_intents 7', $first);
        self::assertStringContainsString('s3_server_aws_write_seals 3', $first);
        self::assertStringContainsString('s3_server_multipart_part_write_intents 9', $first);
        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_capacity_remaining /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_write_seals /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_write_seal_capacity_remaining /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_capacity_remaining /m', $second);
    }

    public function test_optional_reader_losing_its_sample_clears_prior_capacity_gauges(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, MultipartCompletionCapacityReader::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        $metadata->method('getNotificationQueueStats')->willReturn([]);
        $metadata->expects(self::exactly(2))->method('getMultipartCompletionIntentCount')->willReturnOnConsecutiveCalls(7, null);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        $first = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertStringContainsString('s3_server_multipart_completion_intents 7', $first);
        $second = \Amp\ByteStream\buffer($handler->handleRequest($this->request())->getBody());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $second);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_capacity_remaining /m', $second);
    }

    public function test_configured_bearer_token_is_required(): void
    {
        $handler = new MetricsHandler(new MetricsCollector(), bearerToken: 'secret-token');

        $unauthorized = $handler->handleRequest($this->request());
        self::assertSame(401, $unauthorized->getStatus());
        self::assertSame('no-store', $unauthorized->getHeader('cache-control'));
        self::assertSame(401, $handler->handleRequest($this->request('Bearer wrong'))->getStatus());
        $authorized = $handler->handleRequest($this->request('Bearer secret-token'));
        self::assertSame(200, $authorized->getStatus());
        self::assertSame('no-store', $authorized->getHeader('cache-control'));
    }

    public function test_whitespace_bearer_token_is_rejected_instead_of_publishing_metrics(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not be empty');

        new MetricsHandler(new MetricsCollector(), bearerToken: " \t ");
    }

    public function test_duplicate_authorization_headers_are_rejected(): void
    {
        $request = $this->request();
        $request->setHeader('authorization', ['Bearer secret-token', 'Bearer secret-token']);

        self::assertSame(401, (new MetricsHandler(
            new MetricsCollector(),
            bearerToken: 'secret-token',
        ))->handleRequest($request)->getStatus());
    }

    public function test_metadata_cancellation_is_propagated_unchanged(): void
    {
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getNotificationQueueStats')->willThrowException($cancellation);
        $handler = new MetricsHandler(new MetricsCollector(), $metadata);

        try {
            $handler->handleRequest($this->request());
            self::fail('Metrics scrape swallowed metadata cancellation.');
        } catch (\Amp\CancelledException $caught) {
            self::assertSame($cancellation, $caught);
        }
    }

    public function test_zero_string_bearer_token_does_not_open_metrics(): void
    {
        $handler = new MetricsHandler(new MetricsCollector(), bearerToken: '0');

        self::assertSame(401, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(401, $handler->handleRequest($this->request('Bearer wrong'))->getStatus());
        self::assertSame(200, $handler->handleRequest($this->request('Bearer 0'))->getStatus());
    }

    private function request(?string $authorization = null): Request
    {
        return new Request(
            new MetricsHandlerTestClient(),
            'GET',
            Http::new('http://127.0.0.1/metrics'),
            $authorization !== null ? ['authorization' => $authorization] : [],
        );
    }

    private static function clusterConfig(): S3ServerConfig
    {
        return new S3ServerConfig(
            storagePath: '/',
            clusterId: 'cluster-a',
            clusterNodeId: 'node-a',
            clusterDatabaseId: str_repeat('d', 32),
            clusterRoles: 'api,reconcile',
        );
    }
}

final class MetricsBlockingCapacityGate
{
    public bool $blocked = false;
}

final class MetricsClusterOperationalStore implements ClusterMembershipStore, ClusterOperationalSnapshotStore
{
    public bool $snapshotFailure = false;

    public float $snapshotDelaySeconds = 0.0;

    public int $snapshotCalls = 0;

    public function registerClusterMember(string $clusterId, string $nodeId, string $incarnationId, array $roles, bool $ready, bool $draining, float $ttlSeconds): void {}

    public function heartbeatClusterMember(string $clusterId, string $nodeId, string $incarnationId, bool $ready, bool $draining, float $ttlSeconds): bool
    {
        return true;
    }

    public function withdrawClusterMember(string $clusterId, string $nodeId, string $incarnationId): void {}

    public function isClusterMemberLive(string $clusterId, string $nodeId, string $incarnationId): bool
    {
        return true;
    }

    public function liveClusterRoles(string $clusterId): array
    {
        return [ClusterRoleSet::API, ClusterRoleSet::RECONCILE];
    }

    public function clusterMembershipSnapshot(string $clusterId, string $nodeId, string $incarnationId): ClusterMembershipSnapshot
    {
        return new ClusterMembershipSnapshot(1000.0, 'current', 999.0, 1.0, $this->roleCounts());
    }

    public function clusterOperationalSnapshot(string $clusterId, string $nodeId, string $incarnationId): ClusterOperationalSnapshot
    {
        ++$this->snapshotCalls;
        if ($this->snapshotDelaySeconds > 0.0) {
            \Amp\delay($this->snapshotDelaySeconds);
        }
        if ($this->snapshotFailure) {
            throw new \RuntimeException('snapshot failed');
        }
        $work = [];
        foreach (ClusterWorkSnapshot::ALL as $workClass) {
            $work[$workClass] = new ClusterWorkSnapshot(
                $workClass,
                $workClass === 'lifecycle' ? null : ($workClass === 'notification' ? 2 : 0),
                null,
                0,
                0,
                null,
                0,
                0,
                0,
                1,
            );
        }

        return new ClusterOperationalSnapshot(1000.0, 'current', 999.0, 1.0, $this->roleCounts(), $work);
    }

    /** @return array<string, array{ready: int, draining: int}> */
    private function roleCounts(): array
    {
        $counts = [];
        foreach (ClusterRoleSet::ALL as $role) {
            $counts[$role] = ['ready' => $role === ClusterRoleSet::RECONCILE ? 1 : 0, 'draining' => 0];
        }

        return $counts;
    }
}

final class MetricsHandlerTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return null;
    }

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}
