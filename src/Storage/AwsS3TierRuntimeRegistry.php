<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

/**
 * Immutable, bounded graph of independently fenced AWS tiers.
 *
 * Tier and backend identity must both match. Callers cannot obtain a writer
 * by tier name alone and accidentally publish through another backend.
 */
final class AwsS3TierRuntimeRegistry
{
    public const int MAX_TIERS = 2;

    /** @var array<string, AwsS3TierRuntimeBinding> */
    private array $bindings = [];

    /** @var array<int, string> */
    private array $backendTiers = [];

    /** @var list<AwsS3TierRuntimeBinding> */
    private array $ordered;

    private ?AwsS3OperationalSummaryReader $summaryReader;

    private AwsS3ProviderProbe $providerProbe;

    private AwsS3ReadExecutor $readExecutor;

    private StorageTempBudgetCoordinator $tempBudget;

    /**
     * @param iterable<AwsS3TierRuntimeBinding> $bindings
     */
    public function __construct(
        iterable $bindings,
        private readonly string $defaultTier,
        ?AwsS3OperationalSummaryReader $summaryReader = null,
    ) {
        $profiles = [];
        $metadata = null;
        foreach ($bindings as $binding) {
            $key = self::key($binding->tier);
            if (isset($this->bindings[$key])) {
                throw new \InvalidArgumentException("Duplicate AWS runtime tier: {$binding->tier}");
            }
            $backendId = spl_object_id($binding->backend);
            if (isset($this->backendTiers[$backendId])) {
                throw new \InvalidArgumentException('One AWS backend cannot serve multiple runtime tiers.');
            }
            $profile = $binding->foregroundWriter->profile();
            if ($metadata !== null && $binding->metadata !== $metadata) {
                throw new \InvalidArgumentException('AWS runtime tiers must share one exact metadata graph.');
            }
            $metadata = $binding->metadata;
            $profiles[] = $profile;
            $this->bindings[$key] = $binding;
            $this->backendTiers[$backendId] = $binding->tier;
            if (count($this->bindings) > self::MAX_TIERS) {
                throw new \InvalidArgumentException('Qualified AWS runtime supports at most two exact tiers.');
            }
        }
        if ($this->bindings === [] || ! isset($this->bindings[self::key($defaultTier)])) {
            throw new \InvalidArgumentException('AWS tier runtime registry requires its exact default tier.');
        }
        $this->assertProviderGraph($profiles);
        ksort($this->bindings, SORT_STRING);
        $this->ordered = array_values($this->bindings);
        $defaultBinding = $this->defaultBinding();
        $this->providerProbe = $defaultBinding->operationalState->providerProbe();
        $this->readExecutor = $defaultBinding->backend->readExecutor()
            ?? throw new \InvalidArgumentException('AWS runtime tiers require one exact shared read executor.');
        $this->tempBudget = $defaultBinding->foregroundWriter->tempBudgetCoordinator();
        foreach ($this->ordered as $binding) {
            if ($binding->operationalState->providerProbe() !== $this->providerProbe
                || $binding->backend->readExecutor() !== $this->readExecutor
                || $binding->foregroundWriter->tempBudgetCoordinator() !== $this->tempBudget) {
                throw new \InvalidArgumentException(
                    'AWS runtime tiers must share one exact provider probe, read executor, and temporary-budget coordinator.',
                );
            }
        }
        $defaultMetadata = $this->defaultBinding()->metadata;
        if ($summaryReader !== null && $summaryReader !== $defaultMetadata) {
            throw new \InvalidArgumentException('AWS runtime aggregate summary reader must be the exact tier metadata graph.');
        }
        $this->summaryReader = $summaryReader
            ?? ($defaultMetadata instanceof AwsS3OperationalSummaryReader ? $defaultMetadata : null);
    }

    /** @return list<AwsS3TierRuntimeBinding> */
    public function all(): array
    {
        return $this->ordered;
    }

    public function defaultBinding(): AwsS3TierRuntimeBinding
    {
        return $this->bindings[self::key($this->defaultTier)];
    }

    public function providerProbe(): AwsS3ProviderProbe
    {
        return $this->providerProbe;
    }

    public function readExecutor(): AwsS3ReadExecutor
    {
        return $this->readExecutor;
    }

    public function tempBudgetCoordinator(): StorageTempBudgetCoordinator
    {
        return $this->tempBudget;
    }

    public function hasBoundTier(StorageBackend $backend, string $tier): bool
    {
        $backend = self::unwrap($backend);
        $binding = $this->bindings[self::key($tier)] ?? null;

        return $binding !== null && $binding->backend === $backend;
    }

    public function bindingFor(StorageBackend $backend, string $tier): AwsS3TierRuntimeBinding
    {
        if (! $this->hasBoundTier($backend, $tier)) {
            throw new \RuntimeException('AWS runtime lookup does not match an exact admitted backend and tier.');
        }

        return $this->bindings[self::key($tier)];
    }

    public function backgroundWriterFor(StorageBackend $backend, string $tier): AwsS3FencedBackgroundObjectWriter
    {
        return $this->bindingFor($backend, $tier)->backgroundWriter;
    }

    public function operationalStateFor(StorageBackend $backend, string $tier): AwsS3OperationalState
    {
        return $this->bindingFor($backend, $tier)->operationalState;
    }

    public function assertMutationReady(): void
    {
        foreach ($this->ordered as $binding) {
            $binding->operationalState->assertMutationReady();
        }
        $snapshot = $this->snapshot();
        if ($snapshot['mutation_ready'] !== true) {
            throw new \RuntimeException('AWS tier runtime aggregate mutation admission is closed: ' . $snapshot['reason'] . '.');
        }
    }

    public function markAllCollectorFailure(string $collector): void
    {
        foreach ($this->ordered as $binding) {
            $binding->operationalState->markCollectorFailure($collector);
        }
    }

    public function invalidateStartupValidation(): void
    {
        foreach ($this->ordered as $binding) {
            $binding->operationalState->invalidateStartupValidation();
        }
    }

    /** @return array<string, bool|float|int|string> */
    public function snapshot(): array
    {
        $snapshots = array_map(
            static fn(AwsS3TierRuntimeBinding $binding): array => $binding->operationalState->snapshot(),
            $this->ordered,
        );
        $first = $snapshots[0];
        $summaryHealthy = true;
        try {
            // Every binding is required to share this exact metadata graph.
            // Read database-global counters and per-owner maxima once so the
            // aggregate cannot combine tier snapshots taken at different times.
            $globalSummary = $this->summaryReader?->getAwsS3OperationalSummary($this->defaultTier)
                ?? throw new \RuntimeException('AWS tier runtime aggregate summary reader is unavailable.');
        } catch (\Throwable) {
            $summaryHealthy = false;
            $globalSummary = [];
            foreach (['max_owner_open', 'max_owner_sealed', 'max_owner_multipart', 'max_owner_part', 'global_open', 'global_seal_reserved', 'global_multipart', 'global_part', 'global_cleanup'] as $field) {
                $globalSummary[$field] = $this->maximumSnapshotInt($snapshots, $field);
            }
        }
        $capacityExhausted = (int) $globalSummary['global_open'] >= ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL
            || (int) $globalSummary['global_seal_reserved'] >= ObjectWriteIntentStore::MAX_SEALS_GLOBAL
            || (int) $globalSummary['global_multipart'] >= MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS_GLOBAL
            || (int) $globalSummary['global_part'] >= MultipartPartWriteIntentStore::MAX_OUTSTANDING_GLOBAL_PART_INTENTS
            || (int) $globalSummary['global_cleanup'] >= StorageCleanupCapacityReader::RECOVERY_LIMIT;
        $ready = $summaryHealthy && ! $capacityExhausted;
        $reason = AwsS3OperationalState::REASON_HEALTHY;
        $reasonPriority = [
            AwsS3OperationalState::REASON_CONFIGURATION => 0,
            AwsS3OperationalState::REASON_CREDENTIALS => 1,
            AwsS3OperationalState::REASON_PROVIDER => 2,
            AwsS3OperationalState::REASON_INTEGRITY => 3,
            AwsS3OperationalState::REASON_COLLECTOR => 4,
            AwsS3OperationalState::REASON_CAPACITY => 5,
            AwsS3OperationalState::REASON_HEALTHY => 6,
        ];
        foreach ($snapshots as $snapshot) {
            if ($snapshot['mutation_ready'] !== true) {
                $ready = false;
            }
            if ($reasonPriority[(string) $snapshot['reason']] < $reasonPriority[$reason]) {
                $reason = (string) $snapshot['reason'];
            }
        }
        if (! $summaryHealthy && $reasonPriority[AwsS3OperationalState::REASON_COLLECTOR] < $reasonPriority[$reason]) {
            $reason = AwsS3OperationalState::REASON_COLLECTOR;
        } elseif ($capacityExhausted && $reasonPriority[AwsS3OperationalState::REASON_CAPACITY] < $reasonPriority[$reason]) {
            $reason = AwsS3OperationalState::REASON_CAPACITY;
        }

        $sum = static fn(string $field): int => array_sum(array_map(
            static fn(array $snapshot): int => (int) $snapshot[$field],
            $snapshots,
        ));
        $all = static fn(string $field): bool => array_all(
            $snapshots,
            static fn(array $snapshot): bool => $snapshot[$field] === true,
        );
        $versioningStates = array_values(array_unique(array_map(
            static fn(array $snapshot): string => (string) $snapshot['versioning_state'],
            $snapshots,
        )));
        $versioningAgrees = count($versioningStates) === 1;
        if (! $versioningAgrees) {
            $ready = false;
            if ($reasonPriority[AwsS3OperationalState::REASON_PROVIDER] < $reasonPriority[$reason]) {
                $reason = AwsS3OperationalState::REASON_PROVIDER;
            }
        }
        $driftStates = array_map(
            static fn(array $snapshot): string => (string) $snapshot['drift_state'],
            $snapshots,
        );
        $reconciliationPriority = [
            'integrity_conflict' => 0,
            'failed' => 1,
            'stale' => 2,
            'capacity' => 3,
            'healthy' => 4,
        ];
        $reconciliationState = 'healthy';
        foreach ($snapshots as $snapshot) {
            $candidate = (string) $snapshot['reconciliation_state'];
            if ($reconciliationPriority[$candidate] < $reconciliationPriority[$reconciliationState]) {
                $reconciliationState = $candidate;
            }
        }
        if (! $summaryHealthy && $reconciliationPriority['failed'] < $reconciliationPriority[$reconciliationState]) {
            $reconciliationState = 'failed';
        } elseif ($capacityExhausted && $reconciliationPriority['capacity'] < $reconciliationPriority[$reconciliationState]) {
            $reconciliationState = 'capacity';
        }
        if (! $versioningAgrees && $reconciliationPriority['failed'] < $reconciliationPriority[$reconciliationState]) {
            $reconciliationState = 'failed';
        }

        return [
            'provider_type' => $first['provider_type'],
            'fingerprint' => hash('sha256', json_encode(array_map(
                static fn(AwsS3TierRuntimeBinding $binding): array => [
                    'tier' => $binding->tier,
                    'fingerprint' => $binding->operationalState->fingerprint(),
                ],
                $this->ordered,
            ), JSON_THROW_ON_ERROR)),
            'configuration_valid' => $all('configuration_valid'),
            'owner_check_passed' => $all('owner_check_passed'),
            'versioning_state' => $versioningAgrees ? $versioningStates[0] : AwsS3ProviderAdmission::VERSIONING_UNKNOWN,
            'provider_admitted' => $all('provider_admitted'),
            'fence_complete' => $all('fence_complete'),
            'writer_coverage_complete' => $all('writer_coverage_complete'),
            'drift_state' => in_array('detected', $driftStates, true)
                ? 'detected'
                : (in_array('unknown', $driftStates, true) ? 'unknown' : 'clear'),
            'production_qualified' => $all('production_qualified'),
            'mutation_ready' => $ready,
            'recovery_ready' => $all('recovery_ready'),
            'reconciliation_state' => $reconciliationState,
            'reason' => $reason,
            'open' => $sum('open'),
            'ambiguous' => $sum('ambiguous'),
            'seal_reserved' => $sum('seal_reserved'),
            'sealed' => $sum('sealed'),
            'multipart' => $sum('multipart'),
            'part' => $sum('part'),
            'cleanup' => $sum('cleanup'),
            'failures' => $sum('failures'),
            'oldest_age_seconds' => $this->maximumSnapshotFloat($snapshots, 'oldest_age_seconds'),
            'max_owner_open' => (int) $globalSummary['max_owner_open'],
            'max_owner_sealed' => (int) $globalSummary['max_owner_sealed'],
            'max_owner_multipart' => (int) $globalSummary['max_owner_multipart'],
            'max_owner_part' => (int) $globalSummary['max_owner_part'],
            'global_open' => (int) $globalSummary['global_open'],
            'global_seal_reserved' => (int) $globalSummary['global_seal_reserved'],
            'global_multipart' => (int) $globalSummary['global_multipart'],
            'global_part' => (int) $globalSummary['global_part'],
            'global_cleanup' => (int) $globalSummary['global_cleanup'],
        ];
    }

    /** @param list<AwsS3ProviderProfile> $profiles */
    private function assertProviderGraph(array $profiles): void
    {
        $first = $profiles[0];
        $prefixes = [];
        foreach ($profiles as $profile) {
            if ($profile->bucket !== $first->bucket
                || $profile->region !== $first->region
                || $profile->expectedOwnerId !== $first->expectedOwnerId
                || $profile->connectTimeoutSeconds !== $first->connectTimeoutSeconds
                || $profile->readTimeoutSeconds !== $first->readTimeoutSeconds
                || $profile->requestTimeoutSeconds !== $first->requestTimeoutSeconds) {
                throw new \InvalidArgumentException('AWS runtime tiers must share one exact provider identity and timeout contract.');
            }
            foreach ([$profile->dataPrefix, $profile->reservedPrefix] as $prefix) {
                foreach ($prefixes as $existing) {
                    if (AwsS3ProviderProfile::prefixContains($existing, $prefix)
                        || AwsS3ProviderProfile::prefixContains($prefix, $existing)) {
                        throw new \InvalidArgumentException('AWS runtime tier prefixes must be pairwise disjoint.');
                    }
                }
                $prefixes[] = $prefix;
            }
        }
    }

    private static function unwrap(StorageBackend $backend): StorageBackend
    {
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        return $backend;
    }

    /** @param list<array<string, bool|float|int|string>> $snapshots */
    private function maximumSnapshotInt(array $snapshots, string $field): int
    {
        if ($snapshots === []) {
            throw new \LogicException('AWS tier runtime snapshot set must not be empty.');
        }
        $maximum = (int) $snapshots[0][$field];
        foreach (array_slice($snapshots, 1) as $snapshot) {
            $maximum = max($maximum, (int) $snapshot[$field]);
        }

        return $maximum;
    }

    /** @param list<array<string, bool|float|int|string>> $snapshots */
    private function maximumSnapshotFloat(array $snapshots, string $field): float
    {
        if ($snapshots === []) {
            throw new \LogicException('AWS tier runtime snapshot set must not be empty.');
        }
        $maximum = (float) $snapshots[0][$field];
        foreach (array_slice($snapshots, 1) as $snapshot) {
            $maximum = max($maximum, (float) $snapshot[$field]);
        }

        return $maximum;
    }

    private static function key(string $tier): string
    {
        return "tier\0{$tier}";
    }
}
