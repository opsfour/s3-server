<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Observability;

use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\ClusterOperationalSnapshot;
use OpsFour\S3Server\Runtime\ClusterRoleSet;
use OpsFour\S3Server\Runtime\ClusterWorkSnapshot;
use PHPUnit\Framework\TestCase;

final class MetricsCollectorTest extends TestCase
{
    public function test_cluster_snapshot_metrics_are_fixed_cardinality_and_clear_after_failure(): void
    {
        $roles = [];
        foreach (ClusterRoleSet::ALL as $role) {
            $roles[$role] = ['ready' => $role === ClusterRoleSet::API ? 2 : 0, 'draining' => $role === ClusterRoleSet::API ? 1 : 0];
        }
        $work = [];
        foreach (ClusterWorkSnapshot::ALL as $queue) {
            $work[$queue] = new ClusterWorkSnapshot(
                $queue,
                in_array($queue, ['lifecycle', 'transition'], true) ? null : 3,
                $queue === 'lifecycle' ? null : 12.5,
                $queue === 'transition' ? null : 1,
                $queue === 'transition' ? null : 2,
                in_array($queue, ['lifecycle', 'multipart_intent', 'object_intent', 'transition'], true) ? null : 1,
                4,
                5,
                6,
                7,
            );
        }
        $metrics = new MetricsCollector();
        $metrics->setClusterOperationalSnapshot(new ClusterOperationalSnapshot(
            1000.5,
            'current',
            999.5,
            1.0,
            $roles,
            $work,
        ));
        $metrics->setClusterSnapshotSuccess(true);

        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_cluster_snapshot_success 1', $rendered);
        self::assertStringContainsString('s3_server_cluster_snapshot_fresh 1', $rendered);
        self::assertStringContainsString('s3_server_cluster_role_processes{role="api",state="ready"} 2', $rendered);
        self::assertStringContainsString('s3_server_cluster_work_items{queue="notification",state="expired"} 2', $rendered);
        self::assertStringNotContainsString('s3_server_cluster_work_items{queue="transition",', $rendered);
        self::assertStringContainsString('s3_server_cluster_work_takeovers_total{outcome="stale_acknowledgement",queue="notification"} 6', $rendered);
        self::assertStringNotContainsString('node-a', $rendered);
        self::assertDoesNotMatchRegularExpression('/(?:bucket|owner|worker|work_id|object|credential)="/', $rendered);

        $metrics->setClusterOperationalSnapshot(null);
        $metrics->setClusterSnapshotSuccess(false);
        $failed = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_up 1', $failed);
        self::assertStringContainsString('s3_server_cluster_snapshot_success 0', $failed);
        self::assertStringContainsString('s3_server_cluster_snapshot_fresh 0', $failed);
        self::assertDoesNotMatchRegularExpression('/^s3_server_cluster_work_items\{/m', $failed);
    }

    public function test_storage_cleanup_metrics_bound_remaining_capacity_and_rejection_labels(): void
    {
        $metrics = new MetricsCollector();
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_items /m', $metrics->renderPrometheus());

        $metrics->setStorageCleanupCount(950_000);
        $metrics->recordStorageCleanupAdmissionRejection('normal');
        $metrics->recordStorageCleanupAdmissionRejection('recovery');
        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_storage_cleanup_items 950000', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_capacity_remaining{admission="normal"} 0', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_capacity_remaining{admission="recovery"} 50000', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="normal"} 1', $rendered);
        self::assertStringContainsString('s3_server_storage_cleanup_admission_rejections_total{admission="recovery"} 1', $rendered);

        $metrics->setStorageCleanupCount(null);
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_items /m', $metrics->renderPrometheus());
        self::assertDoesNotMatchRegularExpression('/^s3_server_storage_cleanup_capacity_remaining/m', $metrics->renderPrometheus());
    }

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
        $metrics->setWriteIntentCapacityCounts(['object' => 42, 'object_seal' => 7, 'part' => 17]);
        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('s3_server_object_write_intents 42', $rendered);
        self::assertStringContainsString('s3_server_object_write_capacity_remaining 999958', $rendered);
        self::assertStringContainsString('s3_server_aws_write_seals 7', $rendered);
        self::assertStringContainsString('s3_server_aws_write_seal_capacity_remaining 999993', $rendered);
        self::assertStringContainsString('s3_server_multipart_part_write_intents 17', $rendered);
        self::assertStringContainsString('s3_server_multipart_part_write_capacity_remaining 999983', $rendered);
        $metrics->setWriteIntentCapacityCounts(null);
        self::assertDoesNotMatchRegularExpression('/^s3_server_object_write_intents /m', $metrics->renderPrometheus());
        self::assertDoesNotMatchRegularExpression('/^s3_server_multipart_part_write_intents /m', $metrics->renderPrometheus());
        $this->expectException(\UnexpectedValueException::class);
        $metrics->setWriteIntentCapacityCounts(['object' => 0, 'object_seal' => 0, 'part' => 1_000_001]);
    }

    public function test_write_intent_capacity_sample_requires_permanent_seal_count(): void
    {
        $this->expectException(\UnexpectedValueException::class);
        (new MetricsCollector())->setWriteIntentCapacityCounts(['object' => 0, 'part' => 0]);
    }

    public function test_aws_reconciliation_metrics_have_only_fixed_status_labels(): void
    {
        $metrics = new MetricsCollector();
        $metrics->recordAwsWriteReconciliation('sealed');
        $metrics->recordAwsWriteReconciliation('retry');
        $metrics->recordAwsWriteReconciliation('integrity_conflict');
        $rendered = $metrics->renderPrometheus();
        foreach (['sealed', 'retry', 'integrity_conflict'] as $status) {
            self::assertStringContainsString(
                's3_server_aws_write_reconciliations_total{status="' . $status . '"} 1',
                $rendered,
            );
        }
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_write_reconciliations_total\{[^}]*,(?:owner|key|path)=/m', $rendered);
    }

    public function test_aws_publication_metrics_have_only_fixed_outcome_labels(): void
    {
        $metrics = new MetricsCollector();
        foreach (['confirmed_success', 'precondition_conflict', 'confirmed_rejection', 'ambiguous'] as $status) {
            $metrics->recordAwsPublicationOutcome($status);
        }
        $rendered = $metrics->renderPrometheus();
        foreach (['confirmed_success', 'precondition_conflict', 'confirmed_rejection', 'ambiguous'] as $status) {
            self::assertStringContainsString(
                's3_server_aws_publication_outcomes_total{status="' . $status . '"} 1',
                $rendered,
            );
        }
        self::assertDoesNotMatchRegularExpression('/^s3_server_aws_publication_outcomes_total\{[^}]*(?:owner|key|path|attempt)=/m', $rendered);
    }

    public function test_aws_operational_metrics_are_fixed_cardinality_and_identity_free(): void
    {
        $metrics = new MetricsCollector();
        $metrics->setAwsOperationalSnapshot([
            'provider_type' => 'aws-s3',
            'fingerprint' => str_repeat('a', 64),
            'configuration_valid' => true,
            'owner_check_passed' => true,
            'versioning_state' => 'disabled',
            'provider_admitted' => true,
            'fence_complete' => true,
            'writer_coverage_complete' => true,
            'drift_state' => 'clear',
            'production_qualified' => false,
            'mutation_ready' => true,
            'recovery_ready' => true,
            'reconciliation_state' => 'healthy',
            'reason' => 'healthy',
            'open' => 3,
            'ambiguous' => 2,
            'seal_reserved' => 5,
            'sealed' => 4,
            'multipart' => 1,
            'part' => 7,
            'cleanup' => 8,
            'failures' => 1,
            'oldest_age_seconds' => 12.5,
            'max_owner_open' => 2,
            'max_owner_sealed' => 3,
            'max_owner_multipart' => 6,
            'max_owner_part' => 4,
            'global_open' => 30,
            'global_seal_reserved' => 31,
            'global_multipart' => 32,
            'global_part' => 33,
            'global_cleanup' => 34,
        ]);

        $rendered = $metrics->renderPrometheus();
        self::assertSame(7, preg_match_all('/^s3_server_aws_profile_state\{/m', $rendered));
        self::assertStringContainsString('s3_server_aws_profile_qualification{state="production_qualified"} 0', $rendered);
        self::assertStringContainsString('s3_server_aws_recovery_oldest_age_seconds 12.5', $rendered);
        self::assertStringContainsString('s3_server_aws_recovery_max_owner_items{kind="open"} 2', $rendered);
        self::assertStringContainsString('s3_server_aws_recovery_max_owner_items{kind="multipart"} 6', $rendered);
        self::assertStringContainsString('s3_server_aws_recovery_global_capacity_items{kind="multipart"} 32', $rendered);
        self::assertStringContainsString('s3_server_aws_profile_versioning_state{state="disabled"} 1', $rendered);
        self::assertStringContainsString('s3_server_aws_profile_drift_state{state="clear"} 1', $rendered);
        self::assertStringContainsString('s3_server_aws_reconciliation_state{state="healthy"} 1', $rendered);
        self::assertDoesNotMatchRegularExpression('/(?:owner|account|bucket|key|path|attempt)="/', $rendered);
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
