<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Cancellation;
use Amp\CompositeCancellation;
use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use OpsFour\S3Server\S3ServerConfig;

/** Fail-closed single-flight state; only the newest complete live comparison can reopen it. */
final class ClusterAdmissionController
{
    public const float DEFAULT_COMPARISON_TIMEOUT_SECONDS = 5.0;

    private bool $admitted = false;
    private ?string $category = 'database';
    private string $status = 'unavailable';
    private float $lastCheckedAt = -INF;
    private int $generation = 0;
    private bool $shutdown = false;

    /** @var Future<array{ClusterCapabilityFingerprint, ClusterAdmissionResult}>|null */
    private ?Future $inFlight = null;
    private ?int $inFlightGeneration = null;

    /** @var \Closure(): ClusterCapabilityFingerprint */
    private readonly \Closure $fingerprintFactory;

    public function __construct(
        private readonly ClusterAdmissionStore $store,
        private ?ClusterCapabilityFingerprint $fingerprint,
        private readonly float $revalidationIntervalSeconds = 5.0,
        private readonly float $comparisonTimeoutSeconds = self::DEFAULT_COMPARISON_TIMEOUT_SECONDS,
        ?\Closure $fingerprintFactory = null,
    ) {
        if (! is_finite($revalidationIntervalSeconds) || $revalidationIntervalSeconds < 0.0) {
            throw new \InvalidArgumentException('Cluster revalidation interval must be finite and non-negative.');
        }
        if (! is_finite($comparisonTimeoutSeconds) || $comparisonTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Cluster comparison timeout must be finite and positive.');
        }
        if ($fingerprintFactory === null) {
            if ($fingerprint === null) {
                throw new \InvalidArgumentException('Deferred cluster admission requires a fingerprint factory.');
            }
            $fingerprintFactory = static fn(): ClusterCapabilityFingerprint => $fingerprint;
        }
        $this->fingerprintFactory = $fingerprintFactory;
    }

    public function admitInitial(): void
    {
        if ($this->shutdown) {
            throw new \LogicException('Cluster admission controller is shut down.');
        }
        $this->closeAdmission();
        try {
            $this->compare(initial: true, cancellation: null);
        } catch (\Amp\CancelledException $error) {
            if ($error->getPrevious() instanceof TimeoutException) {
                throw new \RuntimeException('Initial cluster admission comparison timed out.', 0, $error);
            }
            throw $error;
        }
    }

    public function revalidate(bool $force = false, ?Cancellation $cancellation = null): bool
    {
        if ($this->shutdown) {
            return false;
        }
        $now = hrtime(true) / 1e9;
        if (! $force && $now - $this->lastCheckedAt < $this->revalidationIntervalSeconds) {
            return $this->admitted;
        }

        $this->closeAdmission();
        try {
            $this->compare(initial: false, cancellation: $cancellation);
        } catch (\Amp\CancelledException $error) {
            if (! $error->getPrevious() instanceof TimeoutException
                && $cancellation?->isRequested() === true) {
                throw $error;
            }
        } catch (\Throwable) {
        }

        return $this->admitted;
    }

    public function admitNewClaim(?Cancellation $cancellation = null): bool
    {
        return $this->revalidate(force: true, cancellation: $cancellation);
    }

    public function isAdmitted(): bool
    {
        return $this->admitted;
    }

    public function category(): ?string
    {
        return $this->category;
    }

    /** Permanently close admission and its lifecycle-owned resources. */
    public function shutdown(): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->shutdown = true;
        ++$this->generation;
        $this->inFlight?->ignore();
        $this->inFlight = null;
        $this->inFlightGeneration = null;
        $this->closeAdmission();
        if ($this->store instanceof RecoverableClusterAdmissionStore) {
            $this->store->close();
        }
    }

    public function assertBoundToConfig(S3ServerConfig $config): void
    {
        $fingerprint = $this->currentFingerprint();
        if (! $config->clusterEnabled()
            || $config->clusterId === null
            || $config->clusterNodeId === null
            || $config->clusterIncarnationId === null
            || $config->clusterDatabaseId === null
            || ! hash_equals($fingerprint->clusterId, $config->clusterId)
            || ! hash_equals($fingerprint->nodeId, $config->clusterNodeId)
            || ! hash_equals($fingerprint->incarnationId, $config->clusterIncarnationId)
            || ! hash_equals($fingerprint->databaseIdentity, $config->clusterDatabaseId)
            || $fingerprint->roles !== $config->clusterRoles
            || $fingerprint->requiredRoles !== $config->clusterRequiredRoles) {
            throw new \LogicException('Cluster admission controller is not bound to this server configuration.');
        }
    }

    /** @return array{cluster_id: string, node_id: string, incarnation_id: string, protocol: string, roles: list<string>, admission: string, category?: string} */
    public function diagnostics(): array
    {
        $fingerprint = $this->currentFingerprint();
        $diagnostics = [
            'cluster_id' => $fingerprint->clusterId,
            'node_id' => $fingerprint->nodeId,
            'incarnation_id' => $fingerprint->incarnationId,
            'protocol' => ClusterCapabilityFingerprint::PROTOCOL,
            'roles' => $fingerprint->roles,
            'admission' => $this->status,
        ];
        if ($this->status === 'mismatched' && $this->category !== null) {
            $diagnostics['category'] = $this->category;
        }

        return $diagnostics;
    }

    private function compare(bool $initial, ?Cancellation $cancellation): void
    {
        if ($this->shutdown) {
            return;
        }
        $this->clearCompletedInFlight();
        if ($this->inFlight === null) {
            $generation = ++$this->generation;
            $factory = $this->fingerprintFactory;
            $store = $this->store;
            $this->inFlight = \Amp\async(static function () use ($factory, $store, $initial): array {
                $fingerprint = $factory();
                $result = $initial
                    ? $store->initializeOrCompareClusterAdmission($fingerprint)
                    : $store->compareClusterAdmission($fingerprint);

                return [$fingerprint, $result];
            });
            $this->inFlightGeneration = $generation;
        }

        $future = $this->inFlight;
        $generation = $this->inFlightGeneration
            ?? throw new \LogicException('Cluster comparison generation is missing.');
        $timeout = new TimeoutCancellation($this->comparisonTimeoutSeconds);
        $bounded = $cancellation === null ? $timeout : new CompositeCancellation($cancellation, $timeout);
        try {
            [$fingerprint, $result] = $future->await($bounded);
        } catch (\Throwable $error) {
            $this->invalidateInFlight($future, $generation);
            throw $error;
        } finally {
            if ($future->isComplete() && $this->inFlight === $future) {
                $this->inFlight = null;
                $this->inFlightGeneration = null;
            }
        }

        if ($this->shutdown || $this->generation !== $generation) {
            return;
        }
        $this->fingerprint = $fingerprint;
        $this->apply($result);
        $this->lastCheckedAt = hrtime(true) / 1e9;
    }

    private function closeAdmission(): void
    {
        $this->admitted = false;
        $this->category = 'database';
        $this->status = 'unavailable';
    }

    /** @param Future<array{ClusterCapabilityFingerprint, ClusterAdmissionResult}> $future */
    private function invalidateInFlight(Future $future, int $generation): void
    {
        if ($this->inFlight !== $future || $this->inFlightGeneration !== $generation) {
            return;
        }
        ++$this->generation;
        $future->ignore();
        if ($this->store instanceof RecoverableClusterAdmissionStore) {
            $this->store->retireConnection();
            $this->inFlight = null;
            $this->inFlightGeneration = null;
        } else {
            $this->clearCompletedInFlight();
        }
        $this->lastCheckedAt = hrtime(true) / 1e9;
        $this->closeAdmission();
    }

    private function clearCompletedInFlight(): void
    {
        if ($this->inFlight === null || ! $this->inFlight->isComplete()) {
            return;
        }
        $this->inFlight->ignore();
        $this->inFlight = null;
        $this->inFlightGeneration = null;
    }

    private function apply(ClusterAdmissionResult $result): void
    {
        $this->admitted = $result->matched;
        $this->category = $result->category;
        $this->status = $result->matched ? 'matched' : 'mismatched';
    }

    private function currentFingerprint(): ClusterCapabilityFingerprint
    {
        return $this->fingerprint
            ?? throw new \LogicException('Cluster admission has not completed its initial fingerprint.');
    }
}
