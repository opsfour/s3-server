<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Sync\LocalMutex;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use Amp\Parallel\Worker\WorkerPool;

/** Shared, secret-free source of AWS readiness, mutation admission and diagnostics. */
final class AwsS3OperationalState
{
    public const string REASON_HEALTHY = 'healthy';
    public const string REASON_PROVIDER = 'provider';
    public const string REASON_CREDENTIALS = 'credentials';
    public const string REASON_CONFIGURATION = 'configuration';
    public const string REASON_CAPACITY = 'capacity';
    public const string REASON_COLLECTOR = 'collector';
    public const string REASON_INTEGRITY = 'integrity';

    public const int STALE_BACKLOG_SECONDS = 900;

    private AwsS3ProviderAdmission $admission;

    private LocalMutex $refreshMutex;

    private float $lastValidationAt = -INF;

    /** @var array<string, true> */
    private array $collectorFailures = [];

    /** @var array<string, array{attempt_id: string, dispatch_id: string}> */
    private array $objectRecoveryPending = [];

    private bool $summaryHealthy = true;

    private bool $integrityHealthy = true;

    private bool $startupValidated = false;

    private bool $runtimeCommitted = false;

    /** @var array<string, int|float>|null */
    private ?array $summary = null;

    public function __construct(
        private readonly AwsS3ProviderValidatedBinding $binding,
        private readonly AwsS3ProviderProbe $probe,
        private readonly StorageMutatorInventory $inventory,
        private readonly AwsS3OperationalSummaryReader $metadata,
        private readonly string $storageTier,
        private readonly float $revalidationIntervalSeconds = 30.0,
    ) {
        if ($binding->profile === null || $binding->client === null || ! $binding->admission->providerAdmitted) {
            throw new \InvalidArgumentException('AWS operational state requires an admitted exact provider binding.');
        }
        if (! $inventory->isComplete()) {
            throw new \InvalidArgumentException('AWS operational state requires the frozen complete writer inventory.');
        }
        if ($storageTier === '') {
            throw new \InvalidArgumentException('AWS operational state requires the exact admitted storage tier.');
        }
        if (! is_finite($revalidationIntervalSeconds) || $revalidationIntervalSeconds < 0.0 || $revalidationIntervalSeconds > 300.0) {
            throw new \InvalidArgumentException('AWS operational revalidation interval must be between 0 and 300 seconds.');
        }
        $this->admission = $binding->admission;
        $this->refreshMutex = new LocalMutex();
    }

    public function fingerprint(): string
    {
        $profile = $this->profile();
        return hash('sha256', json_encode([
            'provider' => AwsS3ProviderAdmission::PROVIDER_TYPE,
            'bucket' => $profile->bucket,
            'region' => $profile->region,
            'owner' => $profile->expectedOwnerId,
            'data' => $profile->dataPrefix,
            'reserved' => $profile->reservedPrefix,
            'timeouts' => [$profile->connectTimeoutSeconds, $profile->readTimeoutSeconds, $profile->requestTimeoutSeconds],
        ], JSON_THROW_ON_ERROR));
    }

    public function refresh(bool $force = false): void
    {
        $lock = $this->refreshMutex->acquire();
        try {
            $this->refreshLocked($force);
        } finally {
            $lock->release();
        }
    }

    private function refreshLocked(bool $force): void
    {
        $now = hrtime(true) / 1e9;
        if (! $force && $now - $this->lastValidationAt < $this->revalidationIntervalSeconds) {
            return;
        }
        try {
            $this->admission = $this->probe->revalidate($this->profile());
        } catch (\Throwable) {
            $this->admission = AwsS3ProviderAdmission::denied(
                $this->profile()->region,
                true,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED,
            );
        }
        $this->lastValidationAt = $now;
        try {
            $this->summary = $this->metadata->getAwsS3OperationalSummary($this->storageTier);
            if ($this->metadata instanceof ObjectWriteIntentStore) {
                foreach ($this->objectRecoveryPending as $token => $pending) {
                    $intent = $this->metadata->getObjectWriteIntent($pending['attempt_id']);
                    if ($intent !== null && $intent->state === 'sealed'
                        && $intent->dispatchId === $pending['dispatch_id']) {
                        unset($this->objectRecoveryPending[$token]);
                    }
                }
            }
            $this->summaryHealthy = true;
        } catch (\Throwable) {
            // Preserve the last durable sample, but close foreground admission
            // until the bounded aggregate is readable again.
            $this->summaryHealthy = false;
        }
    }

    public function assertStartupReady(): void
    {
        if (! $this->startupValidated) {
            $this->refresh(true);
        }
        if (! $this->admission->providerAdmitted || ! $this->inventory->isComplete()) {
            throw new \RuntimeException('AWS provider startup state is not fence-complete.');
        }
        $this->startupValidated = true;
    }

    /** Require a fresh provider check before a later startup attempt. */
    public function invalidateStartupValidation(): void
    {
        $this->startupValidated = false;
    }

    public function usesProviderProbePool(WorkerPool $pool): bool
    {
        return $this->probe instanceof AwsS3ProviderWorkerProbe && $this->probe->usesPool($pool);
    }

    public function providerProbe(): AwsS3ProviderProbe
    {
        return $this->probe;
    }

    public function commitRuntimeAttachment(
        AwsS3ProviderValidatedBinding $binding,
        string $storageTier,
        object $metadata,
    ): void {
        $this->assertQualifiedExecutionBinding($binding, $storageTier, $metadata);
        $this->runtimeCommitted = true;
    }

    public function rollbackRuntimeAttachment(
        AwsS3ProviderValidatedBinding $binding,
        string $storageTier,
        object $metadata,
    ): void {
        $this->assertQualifiedExecutionBinding($binding, $storageTier, $metadata);
        $this->runtimeCommitted = false;
    }

    public function assertMutationReady(): void
    {
        $this->refresh();
        if (! $this->mutationReady()) {
            throw new \RuntimeException('AWS provider mutation admission is closed: ' . $this->reason() . '.');
        }
    }

    /** Recovery remains admitted when recovery pressure itself closes publication. */
    public function assertRecoveryReady(): void
    {
        $this->refresh();
        if (! $this->recoveryReady()) {
            throw new \RuntimeException('AWS provider recovery admission is closed: ' . $this->reason() . '.');
        }
    }

    public function markCollectorFailure(string $collector = 'object_intent'): void
    {
        if ($collector === '') {
            throw new \InvalidArgumentException('AWS collector health source must not be empty.');
        }
        $this->collectorFailures[$collector] = true;
    }

    /** Close publication until the object collector observes the durable attempt. */
    public function markObjectRecoveryPending(string $attemptId, string $dispatchId): void
    {
        $this->objectRecoveryPending[$this->recoveryToken($attemptId, $dispatchId)] = [
            'attempt_id' => $attemptId,
            'dispatch_id' => $dispatchId,
        ];
    }

    public function resolveObjectRecoveryPending(string $attemptId, string $dispatchId): void
    {
        unset($this->objectRecoveryPending[$this->recoveryToken($attemptId, $dispatchId)]);
    }

    public function markCollectorHealthy(string $collector = 'object_intent'): void
    {
        unset($this->collectorFailures[$collector]);
    }

    public function markIntegrityConflict(): void
    {
        $this->integrityHealthy = false;
    }

    public function assertExecutionBinding(
        AwsS3ProviderValidatedBinding $binding,
        string $storageTier,
        object $metadata,
    ): void {
        if ($binding !== $this->binding || $storageTier === '' || ! hash_equals($this->storageTier, $storageTier)
            || ($this->metadata instanceof MetadataStore && $metadata !== $this->metadata)) {
            throw new \LogicException('AWS operational state is bound to another validated client, storage tier, or metadata graph.');
        }
    }

    public function assertQualifiedExecutionBinding(
        AwsS3ProviderValidatedBinding $binding,
        string $storageTier,
        object $metadata,
    ): void {
        $this->assertExecutionBinding($binding, $storageTier, $metadata);
        if ($metadata !== $this->metadata) {
            throw new \LogicException('Qualified AWS operational state requires the exact metadata graph.');
        }
    }

    public function isBoundTo(AwsS3ProviderValidatedBinding $binding, string $storageTier, object $metadata): bool
    {
        return $binding === $this->binding
            && hash_equals($this->storageTier, $storageTier)
            && $metadata === $this->metadata;
    }

    public function mutationReady(): bool
    {
        return $this->recoveryReady()
            && $this->runtimeCommitted
            && $this->collectorFailures === []
            && $this->objectRecoveryPending === []
            && $this->summaryHealthy
            && $this->integrityHealthy
            && ! $this->durablePublicationFailure()
            && ! $this->backlogStale()
            && ! $this->capacityExhausted();
    }

    public function recoveryReady(): bool
    {
        return $this->admission->providerAdmitted && $this->inventory->isComplete();
    }

    public function reason(): string
    {
        if (! $this->admission->configurationValid) {
            return self::REASON_CONFIGURATION;
        }
        if (in_array($this->admission->reason, [
            AwsS3ProviderAdmission::REASON_CLIENT_UNAVAILABLE,
            AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED,
        ], true)) {
            return self::REASON_CREDENTIALS;
        }
        if (! $this->admission->providerAdmitted) {
            return self::REASON_PROVIDER;
        }
        if (! $this->integrityHealthy || $this->objectRecoveryPending !== []
            || (int) ($this->summary['ambiguous'] ?? 0) > 0) {
            return self::REASON_INTEGRITY;
        }
        if ($this->collectorFailures !== [] || ! $this->summaryHealthy || $this->backlogStale()
            || (int) ($this->summary['failures'] ?? 0) > 0) {
            return self::REASON_COLLECTOR;
        }
        if ($this->capacityExhausted()) {
            return self::REASON_CAPACITY;
        }
        return self::REASON_HEALTHY;
    }

    /** @return array<string, bool|float|int|string> */
    public function snapshot(): array
    {
        $summary = $this->summary ?? [];
        return [
            'provider_type' => AwsS3ProviderAdmission::PROVIDER_TYPE,
            'fingerprint' => $this->fingerprint(),
            'configuration_valid' => $this->admission->configurationValid,
            'owner_check_passed' => $this->admission->ownerCheckPassed,
            'versioning_state' => $this->admission->versioningState,
            'provider_admitted' => $this->admission->providerAdmitted,
            'fence_complete' => $this->runtimeCommitted && $this->inventory->isComplete(),
            'writer_coverage_complete' => $this->inventory->isComplete(),
            'drift_state' => $this->driftState(),
            'production_qualified' => false,
            'mutation_ready' => $this->mutationReady(),
            'recovery_ready' => $this->recoveryReady(),
            'reconciliation_state' => $this->reconciliationState(),
            'reason' => $this->reason(),
            'open' => (int) ($summary['open'] ?? 0),
            'ambiguous' => (int) ($summary['ambiguous'] ?? 0),
            'seal_reserved' => (int) ($summary['seal_reserved'] ?? 0),
            'sealed' => (int) ($summary['sealed'] ?? 0),
            'multipart' => (int) ($summary['multipart'] ?? 0),
            'part' => (int) ($summary['part'] ?? 0),
            'cleanup' => (int) ($summary['cleanup'] ?? 0),
            'failures' => (int) ($summary['failures'] ?? 0),
            'oldest_age_seconds' => (float) ($summary['oldest_age_seconds'] ?? 0.0),
            'max_owner_open' => (int) ($summary['max_owner_open'] ?? 0),
            'max_owner_sealed' => (int) ($summary['max_owner_sealed'] ?? 0),
            'max_owner_multipart' => (int) ($summary['max_owner_multipart'] ?? 0),
            'max_owner_part' => (int) ($summary['max_owner_part'] ?? 0),
            'global_open' => (int) ($summary['global_open'] ?? 0),
            'global_seal_reserved' => (int) ($summary['global_seal_reserved'] ?? 0),
            'global_multipart' => (int) ($summary['global_multipart'] ?? 0),
            'global_part' => (int) ($summary['global_part'] ?? 0),
            'global_cleanup' => (int) ($summary['global_cleanup'] ?? 0),
        ];
    }

    private function profile(): AwsS3ProviderProfile
    {
        return $this->binding->profile
            ?? throw new \LogicException('AWS operational profile binding disappeared.');
    }

    private function backlogStale(): bool
    {
        return (float) ($this->summary['oldest_age_seconds'] ?? 0.0) >= self::STALE_BACKLOG_SECONDS;
    }

    private function capacityExhausted(): bool
    {
        $summary = $this->summary;
        return $summary !== null && (
            (int) $summary['global_open'] >= ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL
            || (int) $summary['global_seal_reserved'] >= ObjectWriteIntentStore::MAX_SEALS_GLOBAL
            || (int) $summary['global_multipart'] >= MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS_GLOBAL
            || (int) $summary['global_part'] >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS
            || (int) $summary['global_cleanup'] >= StorageCleanupCapacityReader::RECOVERY_LIMIT
        );
    }

    private function durablePublicationFailure(): bool
    {
        return (int) ($this->summary['ambiguous'] ?? 0) > 0
            || (int) ($this->summary['failures'] ?? 0) > 0;
    }

    private function reconciliationState(): string
    {
        if (! $this->integrityHealthy || $this->objectRecoveryPending !== []
            || (int) ($this->summary['ambiguous'] ?? 0) > 0) {
            return 'integrity_conflict';
        }
        if ($this->collectorFailures !== [] || ! $this->summaryHealthy
            || (int) ($this->summary['failures'] ?? 0) > 0) {
            return 'failed';
        }
        if ($this->backlogStale()) {
            return 'stale';
        }
        if ($this->capacityExhausted()) {
            return 'capacity';
        }
        return 'healthy';
    }

    private function recoveryToken(string $attemptId, string $dispatchId): string
    {
        if (preg_match('/^[a-f0-9]{32}$/D', $attemptId) !== 1
            || preg_match('/^[a-f0-9]{32}$/D', $dispatchId) !== 1
            || $dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
            throw new \InvalidArgumentException('AWS recovery pending state requires one exact dispatch token.');
        }

        return $attemptId . ':' . $dispatchId;
    }

    private function driftState(): string
    {
        if ($this->admission->providerAdmitted) {
            return 'clear';
        }
        if (in_array($this->admission->reason, [
            AwsS3ProviderAdmission::REASON_REGION_MISMATCH,
            AwsS3ProviderAdmission::REASON_BUCKET_TYPE_UNSUPPORTED,
            AwsS3ProviderAdmission::REASON_VERSIONING_ENABLED,
            AwsS3ProviderAdmission::REASON_VERSIONING_SUSPENDED,
            AwsS3ProviderAdmission::REASON_VERSIONING_UNKNOWN,
        ], true)) {
            return 'detected';
        }
        return 'unknown';
    }
}
