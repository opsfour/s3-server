<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Observability;

use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Storage\StorageTempBudgetSnapshot;

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

    private const array FLYSYSTEM_READ_FIRST_BYTE_BUCKETS = [
        0.005,
        0.025,
        0.1,
        0.5,
        1.0,
        5.0,
        10.0,
        20.0,
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

    private ?int $multipartCompletionIntentCount = null;

    /** @var array{object: int, object_seal: int, part: int}|null */
    private ?array $writeIntentCapacityCounts = null;

    private ?int $storageCleanupCount = null;

    /** @var array<string, int> */
    private array $storageCleanupAdmissionRejections = [];

    /** @var array<string, int> */
    private array $awsWriteReconciliations = [];

    /** @var array<string, int> */
    private array $awsPublicationOutcomes = [];

    /** @var array<string, bool|float|int|string>|null */
    private ?array $awsOperationalSnapshot = null;

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

    /** @var array<string, int> */
    private array $flysystemReadFirstByteTotals = [];

    /** @var array<string, int> */
    private array $flysystemReadFirstByteDurationNs = [];

    /** @var array<string, int> */
    private array $flysystemReadFirstByteBuckets = [];

    /** @var array<string, int> */
    private array $flysystemReadStageTotals = [];

    /** @var array<string, int> */
    private array $flysystemReadStageDurationNs = [];

    /** @var array<string, int> */
    private array $flysystemReadStageBuckets = [];

    private int $lifecycleRunning = 0;

    private ?int $lifecycleLastSuccessAt = null;

    private int $startedAt;

    private ?StorageTempBudgetSnapshot $tempCapacity = null;

    private ?bool $tempCapacitySnapshotSuccess = null;

    public function __construct()
    {
        $this->startedAt = time();
    }

    public function setStorageTempCapacity(?StorageTempBudgetSnapshot $snapshot): void
    {
        $this->tempCapacity = $snapshot;
    }

    public function setStorageTempCapacitySnapshotSuccess(bool $success): void
    {
        $this->tempCapacitySnapshotSuccess = $success;
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

    public function setMultipartCompletionIntentCount(?int $count): void
    {
        if ($count === null) {
            $this->multipartCompletionIntentCount = null;

            return;
        }
        if ($count < 0 || $count > MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS_GLOBAL) {
            throw new \UnexpectedValueException('Multipart completion recovery capacity counter is out of range.');
        }

        $this->multipartCompletionIntentCount = $count;
    }

    /** @param array<string, mixed>|null $counts */
    public function setWriteIntentCapacityCounts(?array $counts): void
    {
        if ($counts !== null && (
            count($counts) !== 3
            || ! array_key_exists('object', $counts)
            || ! array_key_exists('object_seal', $counts)
            || ! array_key_exists('part', $counts)
            || ! is_int($counts['object'])
            || ! is_int($counts['object_seal'])
            || ! is_int($counts['part'])
        )) {
            throw new \UnexpectedValueException('Write intent recovery capacity sample is incomplete.');
        }
        if ($counts !== null && (
            $counts['object'] < 0 || $counts['object'] > ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL
            || $counts['object_seal'] < 0 || $counts['object_seal'] > ObjectWriteIntentStore::MAX_SEALS_GLOBAL
            || $counts['part'] < 0 || $counts['part'] > MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS
        )) {
            throw new \UnexpectedValueException('Write intent recovery capacity counter is out of range.');
        }

        /** @var array{object: int, object_seal: int, part: int}|null $counts */
        $this->writeIntentCapacityCounts = $counts;
    }

    public function setStorageCleanupCount(?int $count): void
    {
        if ($count !== null && $count < 0) {
            throw new \UnexpectedValueException('Storage cleanup capacity counter is out of range.');
        }
        $this->storageCleanupCount = $count;
    }

    public function recordStorageCleanupAdmissionRejection(string $admission): void
    {
        if (! in_array($admission, ['normal', 'recovery'], true)) {
            throw new \InvalidArgumentException('Unknown storage cleanup admission class.');
        }
        $key = $this->key(['admission' => $admission]);
        $this->storageCleanupAdmissionRejections[$key] = ($this->storageCleanupAdmissionRejections[$key] ?? 0) + 1;
    }

    public function recordAwsWriteReconciliation(string $status): void
    {
        if (! in_array($status, ['sealed', 'retry', 'integrity_conflict'], true)) {
            throw new \InvalidArgumentException('Unknown AWS write reconciliation status.');
        }
        $key = $this->key(['status' => $status]);
        $this->awsWriteReconciliations[$key] = ($this->awsWriteReconciliations[$key] ?? 0) + 1;
    }

    public function recordAwsPublicationOutcome(string $status): void
    {
        if (! in_array($status, ['confirmed_success', 'precondition_conflict', 'confirmed_rejection', 'ambiguous'], true)) {
            throw new \InvalidArgumentException('Unknown AWS publication outcome.');
        }
        $key = $this->key(['status' => $status]);
        $this->awsPublicationOutcomes[$key] = ($this->awsPublicationOutcomes[$key] ?? 0) + 1;
    }

    /** @param array<string, bool|float|int|string> $snapshot */
    public function setAwsOperationalSnapshot(array $snapshot): void
    {
        $reason = $snapshot['reason'] ?? null;
        $fingerprint = $snapshot['fingerprint'] ?? null;
        $versioningState = $snapshot['versioning_state'] ?? null;
        $driftState = $snapshot['drift_state'] ?? null;
        $reconciliationState = $snapshot['reconciliation_state'] ?? null;
        if (! is_string($reason) || ! in_array($reason, ['healthy', 'provider', 'credentials', 'configuration', 'capacity', 'collector', 'integrity'], true)
            || ! is_string($fingerprint) || preg_match('/^[a-f0-9]{64}$/D', $fingerprint) !== 1
            || ! is_string($versioningState) || ! in_array($versioningState, ['not-checked', 'disabled', 'enabled', 'suspended', 'unknown'], true)
            || ! is_string($driftState) || ! in_array($driftState, ['clear', 'detected', 'unknown'], true)
            || ! is_string($reconciliationState) || ! in_array($reconciliationState, ['healthy', 'failed', 'stale', 'capacity', 'integrity_conflict'], true)) {
            throw new \UnexpectedValueException('AWS operational snapshot is not bounded or opaque.');
        }
        $this->awsOperationalSnapshot = $snapshot;
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

    public function recordFlysystemReadFirstByte(string $result, int $durationNs): void
    {
        if (! in_array($result, ['ready', 'admission_timeout', 'admission_rejected', 'first_byte_timeout', 'error'], true)) {
            throw new \InvalidArgumentException('Invalid Flysystem read first-byte result.');
        }

        $key = $this->key(['result' => $result]);
        $durationNs = max(0, $durationNs);
        $this->flysystemReadFirstByteTotals[$key] = ($this->flysystemReadFirstByteTotals[$key] ?? 0) + 1;
        $this->flysystemReadFirstByteDurationNs[$key] = ($this->flysystemReadFirstByteDurationNs[$key] ?? 0) + $durationNs;

        $durationSeconds = $durationNs / 1_000_000_000;
        foreach (self::FLYSYSTEM_READ_FIRST_BYTE_BUCKETS as $bucket) {
            if ($durationSeconds <= $bucket) {
                $bucketKey = $this->key(['le' => self::formatFloat($bucket), 'result' => $result]);
                $this->flysystemReadFirstByteBuckets[$bucketKey] = ($this->flysystemReadFirstByteBuckets[$bucketKey] ?? 0) + 1;
            }
        }
        $bucketKey = $this->key(['le' => '+Inf', 'result' => $result]);
        $this->flysystemReadFirstByteBuckets[$bucketKey] = ($this->flysystemReadFirstByteBuckets[$bucketKey] ?? 0) + 1;
    }

    public function recordFlysystemReadStage(string $stage, string $result, int $durationNs): void
    {
        if (! in_array($stage, ['admission', 'worker'], true)
            || ! in_array($result, ['ready', 'timeout', 'rejected', 'error'], true)) {
            throw new \InvalidArgumentException('Invalid Flysystem read stage or result.');
        }

        $labels = ['result' => $result, 'stage' => $stage];
        $key = $this->key($labels);
        $durationNs = max(0, $durationNs);
        $this->flysystemReadStageTotals[$key] = ($this->flysystemReadStageTotals[$key] ?? 0) + 1;
        $this->flysystemReadStageDurationNs[$key] = ($this->flysystemReadStageDurationNs[$key] ?? 0) + $durationNs;

        $durationSeconds = $durationNs / 1_000_000_000;
        foreach (self::FLYSYSTEM_READ_FIRST_BYTE_BUCKETS as $bucket) {
            if ($durationSeconds <= $bucket) {
                $bucketKey = $this->key(['le' => self::formatFloat($bucket)] + $labels);
                $this->flysystemReadStageBuckets[$bucketKey] = ($this->flysystemReadStageBuckets[$bucketKey] ?? 0) + 1;
            }
        }
        $bucketKey = $this->key(['le' => '+Inf'] + $labels);
        $this->flysystemReadStageBuckets[$bucketKey] = ($this->flysystemReadStageBuckets[$bucketKey] ?? 0) + 1;
    }

    public function renderPrometheus(): string
    {
        $lines = [
            '# HELP s3_server_up Whether the S3 server process is running.',
            '# TYPE s3_server_up gauge',
            's3_server_up 1',
            '# HELP s3_server_started_at_seconds Unix timestamp when this S3 server instance started.',
            '# TYPE s3_server_started_at_seconds gauge',
            's3_server_started_at_seconds ' . $this->startedAt,
            '# HELP s3_server_requests_total Total S3 HTTP requests by method, operation, and status.',
            '# TYPE s3_server_requests_total counter',
        ];

        if ($this->tempCapacity !== null) {
            $lines[] = '# HELP s3_server_node_temp_budget_bytes Configured node-local temporary byte budget.';
            $lines[] = '# TYPE s3_server_node_temp_budget_bytes gauge';
            $lines[] = 's3_server_node_temp_budget_bytes ' . $this->tempCapacity->budgetBytes;
            $lines[] = '# HELP s3_server_node_temp_reserved_bytes Reserved node-local temporary bytes.';
            $lines[] = '# TYPE s3_server_node_temp_reserved_bytes gauge';
            $lines[] = 's3_server_node_temp_reserved_bytes ' . $this->tempCapacity->reservedBytes;
            $lines[] = '# HELP s3_server_node_temp_available_bytes Effective node-local temporary bytes after logical and physical limits.';
            $lines[] = '# TYPE s3_server_node_temp_available_bytes gauge';
            $lines[] = 's3_server_node_temp_available_bytes ' . $this->tempCapacity->effectiveAvailableBytes;
            $lines[] = '# HELP s3_server_node_temp_filesystem_free_bytes Physical free bytes across involved node filesystems.';
            $lines[] = '# TYPE s3_server_node_temp_filesystem_free_bytes gauge';
            $lines[] = 's3_server_node_temp_filesystem_free_bytes ' . $this->tempCapacity->filesystemFreeBytes;
            $lines[] = '# HELP s3_server_node_temp_filesystem_reserve_bytes Configured physical safety reserve.';
            $lines[] = '# TYPE s3_server_node_temp_filesystem_reserve_bytes gauge';
            $lines[] = 's3_server_node_temp_filesystem_reserve_bytes ' . $this->tempCapacity->filesystemReserveBytes;
            $lines[] = '# HELP s3_server_node_temp_active_reservations Active node-local temporary reservations.';
            $lines[] = '# TYPE s3_server_node_temp_active_reservations gauge';
            $lines[] = 's3_server_node_temp_active_reservations ' . $this->tempCapacity->activeReservations;
            $lines[] = '# HELP s3_server_node_temp_unsafe_entries Unsafe or unaccounted managed temporary entries.';
            $lines[] = '# TYPE s3_server_node_temp_unsafe_entries gauge';
            $lines[] = 's3_server_node_temp_unsafe_entries ' . $this->tempCapacity->unsafeEntries;
            $lines[] = '# HELP s3_server_node_temp_orphaned_bytes Safely measured or conservatively bounded unaccounted temporary bytes.';
            $lines[] = '# TYPE s3_server_node_temp_orphaned_bytes gauge';
            $lines[] = 's3_server_node_temp_orphaned_bytes ' . $this->tempCapacity->orphanedBytes;
        }
        if ($this->tempCapacitySnapshotSuccess !== null) {
            $lines[] = '# HELP s3_server_node_temp_snapshot_success Whether the current temporary-capacity snapshot succeeded.';
            $lines[] = '# TYPE s3_server_node_temp_snapshot_success gauge';
            $lines[] = 's3_server_node_temp_snapshot_success ' . ($this->tempCapacitySnapshotSuccess ? '1' : '0');
        }

        foreach ($this->requestTotals as $key => $value) {
            $lines[] = 's3_server_requests_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_request_duration_seconds Request duration histogram by method, operation, and status.';
        $lines[] = '# TYPE s3_server_request_duration_seconds histogram';
        foreach ($this->requestDurationBuckets as $key => $value) {
            $lines[] = 's3_server_request_duration_seconds_bucket{' . $key . '} ' . $value;
        }
        foreach ($this->requestDurationNs as $key => $value) {
            $lines[] = 's3_server_request_duration_seconds_sum{' . $key . '} ' . self::formatFloat($value / 1_000_000_000);
        }
        foreach ($this->requestTotals as $key => $value) {
            $lines[] = 's3_server_request_duration_seconds_count{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_backend_operations_total Total backend operations by backend type, driver, and operation.';
        $lines[] = '# TYPE s3_server_backend_operations_total counter';
        foreach ($this->backendOperationTotals as $key => $value) {
            $lines[] = 's3_server_backend_operations_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_backend_operation_duration_seconds_sum Total backend operation duration seconds by backend type, driver, and operation.';
        $lines[] = '# TYPE s3_server_backend_operation_duration_seconds_sum counter';
        foreach ($this->backendOperationDurationNs as $key => $value) {
            $lines[] = 's3_server_backend_operation_duration_seconds_sum{' . $key . '} ' . self::formatFloat($value / 1_000_000_000);
        }

        $lines[] = '# HELP s3_server_backend_operation_duration_seconds_count Backend operation duration sample count by backend type, driver, and operation.';
        $lines[] = '# TYPE s3_server_backend_operation_duration_seconds_count counter';
        foreach ($this->backendOperationTotals as $key => $value) {
            $lines[] = 's3_server_backend_operation_duration_seconds_count{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_backend_errors_total Total backend operation failures by backend type, driver, operation, and exception.';
        $lines[] = '# TYPE s3_server_backend_errors_total counter';
        foreach ($this->backendErrorTotals as $key => $value) {
            $lines[] = 's3_server_backend_errors_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_backend_slow_operations_total Total backend operations exceeding the configured slow-operation threshold.';
        $lines[] = '# TYPE s3_server_backend_slow_operations_total counter';
        foreach ($this->backendSlowOperationTotals as $key => $value) {
            $lines[] = 's3_server_backend_slow_operations_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_lifecycle_running Whether a lifecycle sweep is currently running in this process.';
        $lines[] = '# TYPE s3_server_lifecycle_running gauge';
        $lines[] = 's3_server_lifecycle_running ' . $this->lifecycleRunning;

        $lines[] = '# HELP s3_server_lifecycle_last_success_at_seconds Unix timestamp of the last completed lifecycle sweep.';
        $lines[] = '# TYPE s3_server_lifecycle_last_success_at_seconds gauge';
        $lines[] = 's3_server_lifecycle_last_success_at_seconds ' . ($this->lifecycleLastSuccessAt ?? 0);

        $lines[] = '# HELP s3_server_lifecycle_sweeps_total Total lifecycle sweeps by status.';
        $lines[] = '# TYPE s3_server_lifecycle_sweeps_total counter';
        foreach ($this->lifecycleSweepTotals as $key => $value) {
            $lines[] = 's3_server_lifecycle_sweeps_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_lifecycle_sweep_duration_seconds_sum Total lifecycle sweep duration seconds by status.';
        $lines[] = '# TYPE s3_server_lifecycle_sweep_duration_seconds_sum counter';
        foreach ($this->lifecycleSweepDurationNs as $key => $value) {
            $lines[] = 's3_server_lifecycle_sweep_duration_seconds_sum{' . $key . '} ' . self::formatFloat($value / 1_000_000_000);
        }

        $lines[] = '# HELP s3_server_lifecycle_sweep_duration_seconds_count Lifecycle sweep duration sample count by status.';
        $lines[] = '# TYPE s3_server_lifecycle_sweep_duration_seconds_count counter';
        foreach ($this->lifecycleSweepTotals as $key => $value) {
            $lines[] = 's3_server_lifecycle_sweep_duration_seconds_count{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_lifecycle_actions_total Total lifecycle actions applied by action type.';
        $lines[] = '# TYPE s3_server_lifecycle_actions_total counter';
        foreach ($this->lifecycleActionTotals as $key => $value) {
            $lines[] = 's3_server_lifecycle_actions_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_tiering_worker_results_total Total physical tiering, restore, and restore-GC worker outcomes.';
        $lines[] = '# TYPE s3_server_tiering_worker_results_total counter';
        foreach ($this->tieringWorkerTotals as $key => $value) {
            $lines[] = 's3_server_tiering_worker_results_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_notification_queue_depth Current notification queue items by status.';
        $lines[] = '# TYPE s3_server_notification_queue_depth gauge';
        foreach ($this->notificationQueueDepth as $key => $value) {
            $lines[] = 's3_server_notification_queue_depth{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_multipart_completion_intents Retained multipart completion recovery intents across all owners.';
        $lines[] = '# TYPE s3_server_multipart_completion_intents gauge';
        $lines[] = '# HELP s3_server_multipart_completion_capacity_remaining Unreserved global multipart completion recovery slots.';
        $lines[] = '# TYPE s3_server_multipart_completion_capacity_remaining gauge';
        if ($this->multipartCompletionIntentCount !== null) {
            $lines[] = 's3_server_multipart_completion_intents ' . $this->multipartCompletionIntentCount;
            $lines[] = 's3_server_multipart_completion_capacity_remaining '
                . (MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS_GLOBAL - $this->multipartCompletionIntentCount);
        }

        $lines[] = '# HELP s3_server_object_write_intents Retained object write recovery intents across all owners.';
        $lines[] = '# TYPE s3_server_object_write_intents gauge';
        $lines[] = '# HELP s3_server_object_write_capacity_remaining Unreserved global object write recovery slots.';
        $lines[] = '# TYPE s3_server_object_write_capacity_remaining gauge';
        $lines[] = '# HELP s3_server_aws_write_seals Permanently retained AWS same-key seals.';
        $lines[] = '# TYPE s3_server_aws_write_seals gauge';
        $lines[] = '# HELP s3_server_aws_write_seal_capacity_remaining Unreserved global permanent AWS seal slots.';
        $lines[] = '# TYPE s3_server_aws_write_seal_capacity_remaining gauge';
        $lines[] = '# HELP s3_server_multipart_part_write_intents Retained multipart part write recovery intents across all owners.';
        $lines[] = '# TYPE s3_server_multipart_part_write_intents gauge';
        $lines[] = '# HELP s3_server_multipart_part_write_capacity_remaining Unreserved global multipart part write recovery slots.';
        $lines[] = '# TYPE s3_server_multipart_part_write_capacity_remaining gauge';
        if ($this->writeIntentCapacityCounts !== null) {
            $lines[] = 's3_server_object_write_intents ' . $this->writeIntentCapacityCounts['object'];
            $lines[] = 's3_server_object_write_capacity_remaining '
                . (ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL - $this->writeIntentCapacityCounts['object']);
            $lines[] = 's3_server_aws_write_seals ' . $this->writeIntentCapacityCounts['object_seal'];
            $lines[] = 's3_server_aws_write_seal_capacity_remaining '
                . (ObjectWriteIntentStore::MAX_SEALS_GLOBAL - $this->writeIntentCapacityCounts['object_seal']);
            $lines[] = 's3_server_multipart_part_write_intents ' . $this->writeIntentCapacityCounts['part'];
            $lines[] = 's3_server_multipart_part_write_capacity_remaining '
                . (MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS - $this->writeIntentCapacityCounts['part']);
        }

        $lines[] = '# HELP s3_server_storage_cleanup_items Durable cleanup rows excluding multipart part write intents.';
        $lines[] = '# TYPE s3_server_storage_cleanup_items gauge';
        $lines[] = '# HELP s3_server_storage_cleanup_capacity_remaining Remaining cleanup admission slots by class.';
        $lines[] = '# TYPE s3_server_storage_cleanup_capacity_remaining gauge';
        if ($this->storageCleanupCount !== null) {
            $lines[] = 's3_server_storage_cleanup_items ' . $this->storageCleanupCount;
            $lines[] = 's3_server_storage_cleanup_capacity_remaining{admission="normal"} '
                . max(0, StorageCleanupCapacityReader::NORMAL_LIMIT - $this->storageCleanupCount);
            $lines[] = 's3_server_storage_cleanup_capacity_remaining{admission="recovery"} '
                . max(0, StorageCleanupCapacityReader::RECOVERY_LIMIT - $this->storageCleanupCount);
        }
        $lines[] = '# HELP s3_server_storage_cleanup_admission_rejections_total Rejected durable cleanup admissions by class.';
        $lines[] = '# TYPE s3_server_storage_cleanup_admission_rejections_total counter';
        foreach ($this->storageCleanupAdmissionRejections as $key => $value) {
            $lines[] = 's3_server_storage_cleanup_admission_rejections_total{' . $key . '} ' . $value;
        }
        $lines[] = '# HELP s3_server_aws_write_reconciliations_total Bounded AWS ambiguous-write reconciliation outcomes.';
        $lines[] = '# TYPE s3_server_aws_write_reconciliations_total counter';
        foreach ($this->awsWriteReconciliations as $key => $value) {
            $lines[] = 's3_server_aws_write_reconciliations_total{' . $key . '} ' . $value;
        }
        $lines[] = '# HELP s3_server_aws_publication_outcomes_total Bounded AWS create-only publication outcomes.';
        $lines[] = '# TYPE s3_server_aws_publication_outcomes_total counter';
        foreach ($this->awsPublicationOutcomes as $key => $value) {
            $lines[] = 's3_server_aws_publication_outcomes_total{' . $key . '} ' . $value;
        }
        $lines[] = '# HELP s3_server_aws_profile_state Secret-free AWS profile state by fixed reason category.';
        $lines[] = '# TYPE s3_server_aws_profile_state gauge';
        $lines[] = '# HELP s3_server_aws_profile_qualification AWS configuration, fence and production qualification states.';
        $lines[] = '# TYPE s3_server_aws_profile_qualification gauge';
        $lines[] = '# HELP s3_server_aws_profile_versioning_state Observed bucket versioning state using fixed values.';
        $lines[] = '# TYPE s3_server_aws_profile_versioning_state gauge';
        $lines[] = '# HELP s3_server_aws_profile_drift_state Profile drift assessment using fixed values.';
        $lines[] = '# TYPE s3_server_aws_profile_drift_state gauge';
        $lines[] = '# HELP s3_server_aws_recovery_items Bounded retained AWS recovery rows by fixed kind.';
        $lines[] = '# TYPE s3_server_aws_recovery_items gauge';
        $lines[] = '# HELP s3_server_aws_recovery_oldest_age_seconds Oldest retained AWS recovery row age using database time.';
        $lines[] = '# TYPE s3_server_aws_recovery_oldest_age_seconds gauge';
        $lines[] = '# HELP s3_server_aws_recovery_max_owner_items Maximum retained rows for any owner, without identity labels.';
        $lines[] = '# TYPE s3_server_aws_recovery_max_owner_items gauge';
        $lines[] = '# HELP s3_server_aws_recovery_global_capacity_items Database-global retained rows used by hard admission limits.';
        $lines[] = '# TYPE s3_server_aws_recovery_global_capacity_items gauge';
        $lines[] = '# HELP s3_server_aws_reconciliation_state Current reconciliation health using fixed states.';
        $lines[] = '# TYPE s3_server_aws_reconciliation_state gauge';
        $lines[] = '# HELP s3_server_aws_reconciliation_failures Retained recovery rows with one or more failed attempts.';
        $lines[] = '# TYPE s3_server_aws_reconciliation_failures gauge';
        if ($this->awsOperationalSnapshot !== null) {
            $snapshot = $this->awsOperationalSnapshot;
            $fingerprint = (string) $snapshot['fingerprint'];
            foreach (['healthy', 'provider', 'credentials', 'configuration', 'capacity', 'collector', 'integrity'] as $reason) {
                $lines[] = 's3_server_aws_profile_state{fingerprint="' . $fingerprint . '",reason="' . $reason . '"} '
                    . ($snapshot['reason'] === $reason ? '1' : '0');
            }
            foreach (['configuration_valid', 'owner_check_passed', 'provider_admitted', 'fence_complete', 'writer_coverage_complete', 'production_qualified', 'mutation_ready', 'recovery_ready'] as $state) {
                $lines[] = 's3_server_aws_profile_qualification{state="' . $state . '"} ' . ((bool) $snapshot[$state] ? '1' : '0');
            }
            foreach (['not-checked', 'disabled', 'enabled', 'suspended', 'unknown'] as $state) {
                $lines[] = 's3_server_aws_profile_versioning_state{state="' . $state . '"} '
                    . ($snapshot['versioning_state'] === $state ? '1' : '0');
            }
            foreach (['clear', 'detected', 'unknown'] as $state) {
                $lines[] = 's3_server_aws_profile_drift_state{state="' . $state . '"} '
                    . ($snapshot['drift_state'] === $state ? '1' : '0');
            }
            foreach (['healthy', 'failed', 'stale', 'capacity', 'integrity_conflict'] as $state) {
                $lines[] = 's3_server_aws_reconciliation_state{state="' . $state . '"} '
                    . ($snapshot['reconciliation_state'] === $state ? '1' : '0');
            }
            $lines[] = 's3_server_aws_reconciliation_failures ' . (int) $snapshot['failures'];
            foreach (['open', 'ambiguous', 'seal_reserved', 'sealed', 'multipart', 'part', 'cleanup', 'failures'] as $kind) {
                $lines[] = 's3_server_aws_recovery_items{kind="' . $kind . '"} ' . (int) $snapshot[$kind];
            }
            $lines[] = 's3_server_aws_recovery_oldest_age_seconds ' . self::formatFloat((float) $snapshot['oldest_age_seconds']);
            foreach (['open', 'sealed', 'multipart', 'part'] as $kind) {
                $lines[] = 's3_server_aws_recovery_max_owner_items{kind="' . $kind . '"} '
                    . (int) $snapshot['max_owner_' . $kind];
            }
            foreach (['open', 'seal_reserved', 'multipart', 'part', 'cleanup'] as $kind) {
                $lines[] = 's3_server_aws_recovery_global_capacity_items{kind="' . $kind . '"} '
                    . (int) $snapshot['global_' . $kind];
            }
        }

        $lines[] = '# HELP s3_server_notification_deliveries_total Total notification delivery outcomes by status.';
        $lines[] = '# TYPE s3_server_notification_deliveries_total counter';
        foreach ($this->notificationDeliveryTotals as $key => $value) {
            $lines[] = 's3_server_notification_deliveries_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_notification_events_total Total notification dispatcher and processor events by stage and status.';
        $lines[] = '# TYPE s3_server_notification_events_total counter';
        foreach ($this->notificationEventTotals as $key => $value) {
            $lines[] = 's3_server_notification_events_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_worker_pool_configured_workers Configured worker processes by pool.';
        $lines[] = '# TYPE s3_server_worker_pool_configured_workers gauge';
        foreach ($this->workerPoolConfiguredWorkers as $key => $value) {
            $lines[] = 's3_server_worker_pool_configured_workers{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_worker_pool_tasks_total Total worker pool tasks by pool, operation, and status.';
        $lines[] = '# TYPE s3_server_worker_pool_tasks_total counter';
        foreach ($this->workerPoolTaskTotals as $key => $value) {
            $lines[] = 's3_server_worker_pool_tasks_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_worker_pool_task_failures_total Total worker pool task failures by pool, operation, and exception.';
        $lines[] = '# TYPE s3_server_worker_pool_task_failures_total counter';
        foreach ($this->workerPoolTaskFailures as $key => $value) {
            $lines[] = 's3_server_worker_pool_task_failures_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_worker_pool_shutdown_failures_total Total worker pool shutdown failures by pool and exception.';
        $lines[] = '# TYPE s3_server_worker_pool_shutdown_failures_total counter';
        foreach ($this->workerPoolShutdownFailures as $key => $value) {
            $lines[] = 's3_server_worker_pool_shutdown_failures_total{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_flysystem_read_first_byte_seconds Time from GET storage read request to primed stream, including read worker admission.';
        $lines[] = '# TYPE s3_server_flysystem_read_first_byte_seconds histogram';
        foreach ($this->flysystemReadFirstByteBuckets as $key => $value) {
            $lines[] = 's3_server_flysystem_read_first_byte_seconds_bucket{' . $key . '} ' . $value;
        }
        foreach ($this->flysystemReadFirstByteDurationNs as $key => $value) {
            $lines[] = 's3_server_flysystem_read_first_byte_seconds_sum{' . $key . '} ' . self::formatFloat($value / 1_000_000_000);
        }
        foreach ($this->flysystemReadFirstByteTotals as $key => $value) {
            $lines[] = 's3_server_flysystem_read_first_byte_seconds_count{' . $key . '} ' . $value;
        }

        $lines[] = '# HELP s3_server_flysystem_read_stage_seconds GET read-worker admission or post-admission time to the primed first byte.';
        $lines[] = '# TYPE s3_server_flysystem_read_stage_seconds histogram';
        foreach ($this->flysystemReadStageBuckets as $key => $value) {
            $lines[] = 's3_server_flysystem_read_stage_seconds_bucket{' . $key . '} ' . $value;
        }
        foreach ($this->flysystemReadStageDurationNs as $key => $value) {
            $lines[] = 's3_server_flysystem_read_stage_seconds_sum{' . $key . '} ' . self::formatFloat($value / 1_000_000_000);
        }
        foreach ($this->flysystemReadStageTotals as $key => $value) {
            $lines[] = 's3_server_flysystem_read_stage_seconds_count{' . $key . '} ' . $value;
        }

        return implode("\n", $lines) . "\n";
    }

    /**
     * @param array<string, string> $labels
     */
    private function key(array $labels): string
    {
        ksort($labels);

        $pairs = [];
        foreach ($labels as $name => $value) {
            $pairs[] = $name . '="' . self::escapeLabelValue($value) . '"';
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
