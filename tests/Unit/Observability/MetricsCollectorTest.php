<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Observability;

use OpsFour\S3Server\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;

final class MetricsCollectorTest extends TestCase
{
    public function test_completion_capacity_gauges_require_a_persisted_sample(): void
    {
        $metrics = new MetricsCollector();
        self::assertStringNotContainsString('s3_server_multipart_completion_intents 0', $metrics->renderPrometheus());
        $metrics->setMultipartCompletionIntentCount(1_000_000);
        self::assertStringContainsString('s3_server_multipart_completion_intents 1000000', $metrics->renderPrometheus());
        self::assertStringContainsString('s3_server_multipart_completion_capacity_remaining 0', $metrics->renderPrometheus());
        $metrics->setMultipartCompletionIntentCount(null);
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_intents /m', $metrics->renderPrometheus());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_completion_capacity_remaining /m', $metrics->renderPrometheus());
        $this->expectException(\UnexpectedValueException::class);
        $metrics->setMultipartCompletionIntentCount(1_000_001);
    }

    public function test_object_and_part_capacity_gauges_require_a_persisted_sample(): void
    {
        $metrics = new MetricsCollector();
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $metrics->renderPrometheus());
        $metrics->setWriteIntentCapacityCounts(['object' => 1_000_000, 'part' => 17]);
        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_object_write_intents 1000000', $rendered);
        self::assertStringContainsString('s3_server_object_write_capacity_remaining 0', $rendered);
        self::assertStringContainsString('s3_server_multipart_part_write_intents 17', $rendered);
        self::assertStringContainsString('s3_server_multipart_part_write_capacity_remaining 999983', $rendered);
        $metrics->setWriteIntentCapacityCounts(null);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $metrics->renderPrometheus());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_intents /m', $metrics->renderPrometheus());
        $this->expectException(\UnexpectedValueException::class);
        $metrics->setWriteIntentCapacityCounts(['object' => 0, 'part' => 1_000_001]);
    }

    public function test_request_histogram_and_backend_metrics_render(): void
    {
        $metrics = new MetricsCollector();

        $metrics->recordRequest('get', 'GetObject', 200, 120_000_000);
        $metrics->recordBackendOperation('storage', 'filesystem', 'putObject', 300_000_000);
        $metrics->recordBackendOperation('storage', 'filesystem', 'putObject', 10_000_000, false, exception: \RuntimeException::class);

        $rendered = $metrics->renderPrometheus();

        self::assertStringContainsString('# TYPE s3_server_request_duration_seconds histogram', $rendered);
        self::assertStringContainsString('s3_server_request_duration_seconds_bucket{le="0.25",method="GET",operation="GetObject",status="200"} 1', $rendered);
        self::assertStringContainsString('s3_server_request_duration_seconds_bucket{le="+Inf",method="GET",operation="GetObject",status="200"} 1', $rendered);
        self::assertStringContainsString('s3_server_request_duration_seconds_sum{method="GET",operation="GetObject",status="200"} 0.12', $rendered);
        self::assertStringContainsString('s3_server_backend_operations_total{backend="storage",driver="filesystem",operation="putObject"} 2', $rendered);
        self::assertStringContainsString('s3_server_backend_operation_duration_seconds_sum{backend="storage",driver="filesystem",operation="putObject"} 0.31', $rendered);
        self::assertStringContainsString('s3_server_backend_errors_total{backend="storage",driver="filesystem",exception="RuntimeException",operation="putObject"} 1', $rendered);
        self::assertStringContainsString('s3_server_backend_slow_operations_total{backend="storage",driver="filesystem",operation="putObject"} 1', $rendered);
    }

    public function test_lifecycle_metrics_render_as_prometheus_counters_and_gauges(): void
    {
        $metrics = new MetricsCollector();

        $metrics->startLifecycleSweep();
        self::assertStringContainsString('s3_server_lifecycle_running 1', $metrics->renderPrometheus());

        $metrics->recordLifecycleAction('expire_current');
        $metrics->recordLifecycleAction('expire_current', 2);
        $metrics->recordLifecycleAction('abort_multipart');
        $metrics->finishLifecycleSweep('completed', 1_500_000_000);
        $metrics->finishLifecycleSweep('skipped_lock', 10_000_000);

        $rendered = $metrics->renderPrometheus();

        self::assertStringContainsString('# TYPE s3_server_lifecycle_running gauge', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_running 0', $rendered);
        self::assertStringContainsString('# TYPE s3_server_lifecycle_sweeps_total counter', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_sweeps_total{status="completed"} 1', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_sweeps_total{status="skipped_lock"} 1', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_sweep_duration_seconds_sum{status="completed"} 1.5', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_sweep_duration_seconds_count{status="completed"} 1', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_actions_total{action="expire_current"} 3', $rendered);
        self::assertStringContainsString('s3_server_lifecycle_actions_total{action="abort_multipart"} 1', $rendered);
        self::assertMatchesRegularExpression('/s3_server_lifecycle_last_success_at_seconds \d+/', $rendered);
    }

    public function test_tiering_worker_metrics_render(): void
    {
        $metrics = new MetricsCollector();

        $metrics->recordTieringWorkerResult('transition', 'completed');
        $metrics->recordTieringWorkerResult('transition', 'dead_letter', 2);
        $metrics->recordTieringWorkerResult('restore', 'retried');
        $metrics->recordTieringWorkerResult('restore_gc', 'expired', 3);

        $rendered = $metrics->renderPrometheus();

        self::assertStringContainsString('# TYPE s3_server_tiering_worker_results_total counter', $rendered);
        self::assertStringContainsString('s3_server_tiering_worker_results_total{status="completed",worker="transition"} 1', $rendered);
        self::assertStringContainsString('s3_server_tiering_worker_results_total{status="dead_letter",worker="transition"} 2', $rendered);
        self::assertStringContainsString('s3_server_tiering_worker_results_total{status="retried",worker="restore"} 1', $rendered);
        self::assertStringContainsString('s3_server_tiering_worker_results_total{status="expired",worker="restore_gc"} 3', $rendered);
    }

    public function test_notification_metrics_render_queue_depth_and_delivery_counters(): void
    {
        $metrics = new MetricsCollector();

        $metrics->setNotificationQueueStats([
            'pending' => 3,
            'processing' => 1,
            'dead_letter' => 2,
        ]);
        $metrics->recordNotificationDelivery('sent');
        $metrics->recordNotificationDelivery('retry', 2);
        $metrics->recordNotificationDelivery('dead_letter');

        $rendered = $metrics->renderPrometheus();

        self::assertStringContainsString('# TYPE s3_server_notification_queue_depth gauge', $rendered);
        self::assertStringContainsString('s3_server_notification_queue_depth{status="pending"} 3', $rendered);
        self::assertStringContainsString('s3_server_notification_queue_depth{status="processing"} 1', $rendered);
        self::assertStringContainsString('s3_server_notification_queue_depth{status="dead_letter"} 2', $rendered);
        self::assertStringContainsString('# TYPE s3_server_notification_deliveries_total counter', $rendered);
        self::assertStringContainsString('s3_server_notification_deliveries_total{status="sent"} 1', $rendered);
        self::assertStringContainsString('s3_server_notification_deliveries_total{status="retry"} 2', $rendered);
        self::assertStringContainsString('s3_server_notification_deliveries_total{status="dead_letter"} 1', $rendered);
    }

    public function test_notification_event_metrics_render(): void
    {
        $metrics = new MetricsCollector();

        $metrics->recordNotificationEvent('listener', 'queued');
        $metrics->recordNotificationEvent('listener', 'failed');
        $metrics->recordNotificationEvent('webhook_enqueue', 'failed', 2);

        $rendered = $metrics->renderPrometheus();

        self::assertStringContainsString('# TYPE s3_server_notification_events_total counter', $rendered);
        self::assertStringContainsString('s3_server_notification_events_total{stage="listener",status="queued"} 1', $rendered);
        self::assertStringContainsString('s3_server_notification_events_total{stage="listener",status="failed"} 1', $rendered);
        self::assertStringContainsString('s3_server_notification_events_total{stage="webhook_enqueue",status="failed"} 2', $rendered);
    }

    public function test_worker_pool_metrics_render(): void
    {
        $metrics = new MetricsCollector();

        $metrics->registerWorkerPool('select', 4);
        $metrics->recordWorkerPoolTask('select', 'SelectObjectContent');
        $metrics->recordWorkerPoolTask('select', 'SelectObjectContent', false, \RuntimeException::class);
        $metrics->recordWorkerPoolShutdownFailure('select', \RuntimeException::class);

        $rendered = $metrics->renderPrometheus();

        self::assertStringContainsString('# TYPE s3_server_worker_pool_configured_workers gauge', $rendered);
        self::assertStringContainsString('s3_server_worker_pool_configured_workers{pool="select"} 4', $rendered);
        self::assertStringContainsString('s3_server_worker_pool_tasks_total{operation="SelectObjectContent",pool="select",status="success"} 1', $rendered);
        self::assertStringContainsString('s3_server_worker_pool_tasks_total{operation="SelectObjectContent",pool="select",status="failure"} 1', $rendered);
        self::assertStringContainsString('s3_server_worker_pool_task_failures_total{exception="RuntimeException",operation="SelectObjectContent",pool="select"} 1', $rendered);
        self::assertStringContainsString('s3_server_worker_pool_shutdown_failures_total{exception="RuntimeException",pool="select"} 1', $rendered);
    }

    public function test_flysystem_read_first_byte_histogram_has_fixed_result_labels(): void
    {
        $metrics = new MetricsCollector();
        $metrics->recordFlysystemReadFirstByte('ready', 20_000_000);
        $metrics->recordFlysystemReadFirstByte('first_byte_timeout', 1_200_000_000);
        $metrics->recordFlysystemReadFirstByte('admission_timeout', 1_000_000_000);

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('# TYPE s3_server_flysystem_read_first_byte_seconds histogram', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_bucket{le="0.025",result="ready"} 1', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_bucket{le="0.5",result="ready"} 1', $rendered);
        self::assertStringNotContainsString('s3_server_flysystem_read_first_byte_seconds_bucket{le="1",result="first_byte_timeout"}', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_bucket{le="+Inf",result="first_byte_timeout"} 1', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_sum{result="ready"} 0.02', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="admission_timeout"} 1', $rendered);

        $this->expectException(\InvalidArgumentException::class);
        $metrics->recordFlysystemReadFirstByte('object-key', 1);
    }

    public function test_flysystem_read_stage_histogram_has_only_fixed_labels(): void
    {
        $metrics = new MetricsCollector();
        $metrics->recordFlysystemReadStage('admission', 'ready', 12_000_000);
        $metrics->recordFlysystemReadStage('worker', 'timeout', 1_200_000_000);

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="ready",stage="admission"} 1', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="timeout",stage="worker"} 1', $rendered);
        self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_bucket{le="0.025",result="ready",stage="admission"} 1', $rendered);

        $this->expectException(\InvalidArgumentException::class);
        $metrics->recordFlysystemReadStage('object-key', 'ready', 1);
    }
}
