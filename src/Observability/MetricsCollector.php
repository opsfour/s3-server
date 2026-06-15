<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Observability;

final class MetricsCollector
{
    private const array REQUEST_DURATION_BUCKETS = [
        0.005,
        0.01,
        0.025,
        0.05,
        0.1,
        0.25,
        0.5,
        1.0,
        2.5,
        5.0,
        10.0,
        30.0,
    ];

    /** @var array<string, int> */
    private array $requestTotals = [];

    /** @var array<string, int> */
    private array $requestDurationNs = [];

    /** @var array<string, int> */
    private array $requestDurationBuckets = [];

    /** @var array<string, int> */
    private array $backendOperationTotals = [];

    /** @var array<string, int> */
    private array $backendOperationDurationNs = [];

    /** @var array<string, int> */
    private array $backendErrorTotals = [];

    /** @var array<string, int> */
    private array $backendSlowOperationTotals = [];

    /** @var array<string, int> */
    private array $lifecycleSweepTotals = [];

    /** @var array<string, int> */
    private array $lifecycleSweepDurationNs = [];

    /** @var array<string, int> */
    private array $lifecycleActionTotals = [];

    /** @var array<string, int> */
    private array $tieringWorkerTotals = [];

    /** @var array<string, int> */
    private array $notificationQueueDepth = [];

    /** @var array<string, int> */
    private array $notificationDeliveryTotals = [];

    /** @var array<string, int> */
    private array $notificationEventTotals = [];

    /** @var array<string, int> */
    private array $workerPoolConfiguredWorkers = [];

    /** @var array<string, int> */
    private array $workerPoolTaskTotals = [];

    /** @var array<string, int> */
    private array $workerPoolTaskFailures = [];

    /** @var array<string, int> */
    private array $workerPoolShutdownFailures = [];

    private int $lifecycleRunning = 0;

    private ?int $lifecycleLastSuccessAt = null;

    private int $startedAt;

    public function __construct()
    {
        $this->startedAt = time();
    }

    public function recordRequest(string $method, ?string $operation, int $status, int $durationNs): void
    {
        $operation = $operation !== null && $operation !== '' ? $operation : 'unknown';
        $labels = [
            'method' => strtoupper($method),
            'operation' => $operation,
            'status' => (string) $status,
        ];
        $key = $this->key($labels);

        $this->requestTotals[$key] = ($this->requestTotals[$key] ?? 0) + 1;
        $this->requestDurationNs[$key] = ($this->requestDurationNs[$key] ?? 0) + $durationNs;

        $durationSeconds = $durationNs / 1_000_000_000;
        foreach (self::REQUEST_DURATION_BUCKETS as $bucket) {
            if ($durationSeconds <= $bucket) {
                $bucketKey = $this->key(['le' => self::formatFloat($bucket)] + $labels);
                $this->requestDurationBuckets[$bucketKey] = ($this->requestDurationBuckets[$bucketKey] ?? 0) + 1;
            }
        }
        $bucketKey = $this->key(['le' => '+Inf'] + $labels);
        $this->requestDurationBuckets[$bucketKey] = ($this->requestDurationBuckets[$bucketKey] ?? 0) + 1;
    }

    public function recordBackendOperation(
        string $backend,
        string $driver,
        string $operation,
        int $durationNs,
        bool $success = true,
        int $slowThresholdNs = 250_000_000,
        ?string $exception = null,
    ): void {
        $backend = $backend !== '' ? $backend : 'unknown';
        $driver = $driver !== '' ? $driver : 'unknown';
        $operation = $operation !== '' ? $operation : 'unknown';
        $key = $this->key([
            'backend' => $backend,
            'driver' => $driver,
            'operation' => $operation,
        ]);

        $this->backendOperationTotals[$key] = ($this->backendOperationTotals[$key] ?? 0) + 1;
        $this->backendOperationDurationNs[$key] = ($this->backendOperationDurationNs[$key] ?? 0) + max(0, $durationNs);

        if ($durationNs >= $slowThresholdNs) {
            $this->backendSlowOperationTotals[$key] = ($this->backendSlowOperationTotals[$key] ?? 0) + 1;
        }

        if (!$success) {
            $errorKey = $this->key([
                'backend' => $backend,
                'driver' => $driver,
                'exception' => $exception ?? 'Throwable',
                'operation' => $operation,
            ]);
            $this->backendErrorTotals[$errorKey] = ($this->backendErrorTotals[$errorKey] ?? 0) + 1;
        }
    }

    public function startLifecycleSweep(): void
    {
        $this->lifecycleRunning = 1;
    }

    public function finishLifecycleSweep(string $status, int $durationNs): void
    {
        $status = $status !== '' ? $status : 'unknown';
        $key = $this->key(['status' => $status]);

        $this->lifecycleRunning = 0;
        $this->lifecycleSweepTotals[$key] = ($this->lifecycleSweepTotals[$key] ?? 0) + 1;
        $this->lifecycleSweepDurationNs[$key] = ($this->lifecycleSweepDurationNs[$key] ?? 0) + $durationNs;

        if ($status === 'completed') {
            $this->lifecycleLastSuccessAt = time();
        }
    }

    public function recordLifecycleAction(string $action, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $action = $action !== '' ? $action : 'unknown';
        $key = $this->key(['action' => $action]);
        $this->lifecycleActionTotals[$key] = ($this->lifecycleActionTotals[$key] ?? 0) + $count;
    }

    public function recordTieringWorkerResult(string $worker, string $status, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $worker = $worker !== '' ? $worker : 'unknown';
        $status = $status !== '' ? $status : 'unknown';
        $key = $this->key([
            'status' => $status,
            'worker' => $worker,
        ]);
        $this->tieringWorkerTotals[$key] = ($this->tieringWorkerTotals[$key] ?? 0) + $count;
    }

    /**
     * @param array<string, int> $stats
     */
    public function setNotificationQueueStats(array $stats): void
    {
        $this->notificationQueueDepth = [];
        foreach ($stats as $status => $count) {
            $status = $status !== '' ? $status : 'unknown';
            $this->notificationQueueDepth[$this->key(['status' => $status])] = max(0, $count);
        }
    }

    public function recordNotificationDelivery(string $status, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $status = $status !== '' ? $status : 'unknown';
        $key = $this->key(['status' => $status]);
        $this->notificationDeliveryTotals[$key] = ($this->notificationDeliveryTotals[$key] ?? 0) + $count;
    }

    public function recordNotificationEvent(string $stage, string $status, int $count = 1): void
    {
        if ($count < 1) {
            return;
        }

        $stage = $stage !== '' ? $stage : 'unknown';
        $status = $status !== '' ? $status : 'unknown';
        $key = $this->key([
            'stage' => $stage,
            'status' => $status,
        ]);
        $this->notificationEventTotals[$key] = ($this->notificationEventTotals[$key] ?? 0) + $count;
    }

    public function registerWorkerPool(string $pool, int $configuredWorkers): void
    {
        $pool = $pool !== '' ? $pool : 'unknown';
        $this->workerPoolConfiguredWorkers[$this->key(['pool' => $pool])] = max(0, $configuredWorkers);
    }

    public function recordWorkerPoolTask(string $pool, string $operation, bool $success = true, ?string $exception = null): void
    {
        $pool = $pool !== '' ? $pool : 'unknown';
        $operation = $operation !== '' ? $operation : 'unknown';
        $key = $this->key([
            'operation' => $operation,
            'pool' => $pool,
            'status' => $success ? 'success' : 'failure',
        ]);
        $this->workerPoolTaskTotals[$key] = ($this->workerPoolTaskTotals[$key] ?? 0) + 1;

        if (!$success) {
            $failureKey = $this->key([
                'exception' => $exception ?? 'Throwable',
                'operation' => $operation,
                'pool' => $pool,
            ]);
            $this->workerPoolTaskFailures[$failureKey] = ($this->workerPoolTaskFailures[$failureKey] ?? 0) + 1;
        }
    }

    public function recordWorkerPoolShutdownFailure(string $pool, ?string $exception = null): void
    {
        $pool = $pool !== '' ? $pool : 'unknown';
        $key = $this->key([
            'exception' => $exception ?? 'Throwable',
            'pool' => $pool,
        ]);
        $this->workerPoolShutdownFailures[$key] = ($this->workerPoolShutdownFailures[$key] ?? 0) + 1;
    }

    public function renderPrometheus(): string
    {
        $lines = [
            '# HELP s3_server_up Whether the S3 server process is running.',
            '# TYPE s3_server_up gauge',
            's3_server_up 1',
            '# HELP s3_server_started_at_seconds Unix timestamp when this S3 server instance started.',
            '# TYPE s3_server_started_at_seconds gauge',
            's3_server_started_at_seconds '.$this->startedAt,
            '# HELP s3_server_requests_total Total S3 HTTP requests by method, operation, and status.',
            '# TYPE s3_server_requests_total counter',
        ];

        foreach ($this->requestTotals as $key => $value) {
            $lines[] = 's3_server_requests_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_request_duration_seconds Request duration histogram by method, operation, and status.';
        $lines[] = '# TYPE s3_server_request_duration_seconds histogram';
        foreach ($this->requestDurationBuckets as $key => $value) {
            $lines[] = 's3_server_request_duration_seconds_bucket{'.$key.'} '.$value;
        }
        foreach ($this->requestDurationNs as $key => $value) {
            $lines[] = 's3_server_request_duration_seconds_sum{'.$key.'} '.self::formatFloat($value / 1_000_000_000);
        }
        foreach ($this->requestTotals as $key => $value) {
            $lines[] = 's3_server_request_duration_seconds_count{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_backend_operations_total Total backend operations by backend type, driver, and operation.';
        $lines[] = '# TYPE s3_server_backend_operations_total counter';
        foreach ($this->backendOperationTotals as $key => $value) {
            $lines[] = 's3_server_backend_operations_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_backend_operation_duration_seconds_sum Total backend operation duration seconds by backend type, driver, and operation.';
        $lines[] = '# TYPE s3_server_backend_operation_duration_seconds_sum counter';
        foreach ($this->backendOperationDurationNs as $key => $value) {
            $lines[] = 's3_server_backend_operation_duration_seconds_sum{'.$key.'} '.self::formatFloat($value / 1_000_000_000);
        }

        $lines[] = '# HELP s3_server_backend_operation_duration_seconds_count Backend operation duration sample count by backend type, driver, and operation.';
        $lines[] = '# TYPE s3_server_backend_operation_duration_seconds_count counter';
        foreach ($this->backendOperationTotals as $key => $value) {
            $lines[] = 's3_server_backend_operation_duration_seconds_count{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_backend_errors_total Total backend operation failures by backend type, driver, operation, and exception.';
        $lines[] = '# TYPE s3_server_backend_errors_total counter';
        foreach ($this->backendErrorTotals as $key => $value) {
            $lines[] = 's3_server_backend_errors_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_backend_slow_operations_total Total backend operations exceeding the configured slow-operation threshold.';
        $lines[] = '# TYPE s3_server_backend_slow_operations_total counter';
        foreach ($this->backendSlowOperationTotals as $key => $value) {
            $lines[] = 's3_server_backend_slow_operations_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_lifecycle_running Whether a lifecycle sweep is currently running in this process.';
        $lines[] = '# TYPE s3_server_lifecycle_running gauge';
        $lines[] = 's3_server_lifecycle_running '.$this->lifecycleRunning;

        $lines[] = '# HELP s3_server_lifecycle_last_success_at_seconds Unix timestamp of the last completed lifecycle sweep.';
        $lines[] = '# TYPE s3_server_lifecycle_last_success_at_seconds gauge';
        $lines[] = 's3_server_lifecycle_last_success_at_seconds '.($this->lifecycleLastSuccessAt ?? 0);

        $lines[] = '# HELP s3_server_lifecycle_sweeps_total Total lifecycle sweeps by status.';
        $lines[] = '# TYPE s3_server_lifecycle_sweeps_total counter';
        foreach ($this->lifecycleSweepTotals as $key => $value) {
            $lines[] = 's3_server_lifecycle_sweeps_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_lifecycle_sweep_duration_seconds_sum Total lifecycle sweep duration seconds by status.';
        $lines[] = '# TYPE s3_server_lifecycle_sweep_duration_seconds_sum counter';
        foreach ($this->lifecycleSweepDurationNs as $key => $value) {
            $lines[] = 's3_server_lifecycle_sweep_duration_seconds_sum{'.$key.'} '.self::formatFloat($value / 1_000_000_000);
        }

        $lines[] = '# HELP s3_server_lifecycle_sweep_duration_seconds_count Lifecycle sweep duration sample count by status.';
        $lines[] = '# TYPE s3_server_lifecycle_sweep_duration_seconds_count counter';
        foreach ($this->lifecycleSweepTotals as $key => $value) {
            $lines[] = 's3_server_lifecycle_sweep_duration_seconds_count{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_lifecycle_actions_total Total lifecycle actions applied by action type.';
        $lines[] = '# TYPE s3_server_lifecycle_actions_total counter';
        foreach ($this->lifecycleActionTotals as $key => $value) {
            $lines[] = 's3_server_lifecycle_actions_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_tiering_worker_results_total Total physical tiering, restore, and restore-GC worker outcomes.';
        $lines[] = '# TYPE s3_server_tiering_worker_results_total counter';
        foreach ($this->tieringWorkerTotals as $key => $value) {
            $lines[] = 's3_server_tiering_worker_results_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_notification_queue_depth Current notification queue items by status.';
        $lines[] = '# TYPE s3_server_notification_queue_depth gauge';
        foreach ($this->notificationQueueDepth as $key => $value) {
            $lines[] = 's3_server_notification_queue_depth{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_notification_deliveries_total Total notification delivery outcomes by status.';
        $lines[] = '# TYPE s3_server_notification_deliveries_total counter';
        foreach ($this->notificationDeliveryTotals as $key => $value) {
            $lines[] = 's3_server_notification_deliveries_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_notification_events_total Total notification dispatcher and processor events by stage and status.';
        $lines[] = '# TYPE s3_server_notification_events_total counter';
        foreach ($this->notificationEventTotals as $key => $value) {
            $lines[] = 's3_server_notification_events_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_worker_pool_configured_workers Configured worker processes by pool.';
        $lines[] = '# TYPE s3_server_worker_pool_configured_workers gauge';
        foreach ($this->workerPoolConfiguredWorkers as $key => $value) {
            $lines[] = 's3_server_worker_pool_configured_workers{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_worker_pool_tasks_total Total worker pool tasks by pool, operation, and status.';
        $lines[] = '# TYPE s3_server_worker_pool_tasks_total counter';
        foreach ($this->workerPoolTaskTotals as $key => $value) {
            $lines[] = 's3_server_worker_pool_tasks_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_worker_pool_task_failures_total Total worker pool task failures by pool, operation, and exception.';
        $lines[] = '# TYPE s3_server_worker_pool_task_failures_total counter';
        foreach ($this->workerPoolTaskFailures as $key => $value) {
            $lines[] = 's3_server_worker_pool_task_failures_total{'.$key.'} '.$value;
        }

        $lines[] = '# HELP s3_server_worker_pool_shutdown_failures_total Total worker pool shutdown failures by pool and exception.';
        $lines[] = '# TYPE s3_server_worker_pool_shutdown_failures_total counter';
        foreach ($this->workerPoolShutdownFailures as $key => $value) {
            $lines[] = 's3_server_worker_pool_shutdown_failures_total{'.$key.'} '.$value;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private function key(array $labels): string
    {
        ksort($labels);

        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = $name.'="'.self::escapeLabelValue($value).'"';
        }

        return implode(',', $pairs);
    }

    private static function escapeLabelValue(string $value): string
    {
        return str_replace(
            ["\\", "\n", '"'],
            ["\\\\", "\\n", '\\"'],
            $value,
        );
    }

    private static function formatFloat(float $value): string
    {
        return rtrim(rtrim(sprintf('%.9F', $value), '0'), '.') ?: '0';
    }
}
