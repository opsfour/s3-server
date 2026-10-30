<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\FencedMultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\QueueLeaseHeartbeat;
use OpsFour\S3Server\Metadata\ClusterWorkFencingStore;
use OpsFour\S3Server\Metadata\ClusterWorkToken;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/** Reconciles every uncommitted completion path, including late remote writes. */
final readonly class MultipartWriteIntentCollector
{
    private LoggerInterface $logger;

    public function __construct(
        private MultipartWriteIntentStore $metadata,
        private StorageTierRegistry $tiers,
        LoggerInterface $logger = new NullLogger(),
        private float $claimLeaseSeconds = 300.0,
        private float $renewIntervalSeconds = 100.0,
        private ?AwsS3OperationalState $awsOperationalState = null,
        private ?ClusterWorkFencingStore $clusterFencing = null,
        private ?string $clusterWorkerId = null,
        private ?AwsS3TierRuntimeRegistry $awsTierRuntimes = null,
    ) {
        if ($this->awsOperationalState !== null && $this->awsTierRuntimes !== null) {
            throw new \InvalidArgumentException('Multipart collection accepts either one legacy AWS state or one exact tier registry, not both.');
        }
        if (($clusterFencing === null) !== ($clusterWorkerId === null)) {
            throw new \InvalidArgumentException('Cluster multipart fencing requires both store and worker identity.');
        }
        $this->logger = new FailureIsolatingLogger($logger);
        if (! is_finite($claimLeaseSeconds) || $claimLeaseSeconds <= 0
            || ! is_finite($renewIntervalSeconds) || $renewIntervalSeconds <= 0
            || $renewIntervalSeconds >= $claimLeaseSeconds / 2) {
            throw new \InvalidArgumentException('Multipart collector renewal interval must fit within its claim lease.');
        }
    }

    public function collect(int $limit = 100, ?Cancellation $cancellation = null): int
    {
        $cancellation?->throwIfRequested();
        if (! $this->metadata instanceof FencedMultipartWriteIntentStore) {
            throw new \LogicException('Multipart collection requires generation-fenced claim renewal.');
        }
        $claims = $this->metadata;
        $cleaned = 0;
        $processed = 0;
        /** @var array<int, AwsS3OperationalState> $awsObservedStates */
        $awsObservedStates = [];
        /** @var array<int, AwsS3OperationalState> $awsFailedStates */
        $awsFailedStates = [];
        $queueExhausted = false;
        $hasAwsTier = $this->hasAwsTier();
        $limit = max(1, $limit);
        foreach ($this->tiers->all() as $tier) {
            $backend = $tier->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if (! $backend instanceof FilesystemBackend) {
                continue;
            }
            if (! $this->metadata instanceof LocalMultipartCompletionIntentStore) {
                throw new \LogicException('Local multipart collection requires fenced local intent retirement.');
            }
            try {
                $retired = $this->metadata->retireDueUndispatchedLocalMultipartWriteIntents(
                    $tier->name,
                    $limit - $processed,
                );
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->markAllAwsCollectorFailure('multipart');
                throw $error;
            }
            $processed += $retired;
            $cleaned += $retired;
            if ($processed >= $limit) {
                return $cleaned;
            }
        }

        for (; $processed < $limit; $processed++) {
            $cancellation?->throwIfRequested();
            $now = $this->metadataNow();
            try {
                if ($this->clusterFencing !== null && $this->clusterWorkerId !== null) {
                    $clusterClaim = $this->clusterFencing->claimClusterMultipartIntent($this->clusterWorkerId, $this->claimLeaseSeconds);
                    $claimed = $clusterClaim === null ? [] : [$clusterClaim->work];
                } else {
                    $clusterClaim = null;
                    $claimed = $this->metadata->claimDueMultipartWriteIntents(1, $now, $now + $this->claimLeaseSeconds);
                }
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->markAllAwsCollectorFailure('multipart');
                throw $error;
            }
            if ($claimed === []) {
                $queueExhausted = true;
                break;
            }
            $intent = $claimed[0];
            if (! $intent instanceof MultipartWriteIntent) {
                throw new \LogicException('Multipart cluster claim returned an invalid payload.');
            }
            $clusterToken = $clusterClaim?->token;
            // A watcher trip during SQL claim leaves its lease for recovery.
            $leaseUntil = $intent->nextAttemptAt;
            $heartbeat = new QueueLeaseHeartbeat(function (float $_) use ($intent, &$leaseUntil, $claims, $clusterToken): bool {
                if ($clusterToken instanceof ClusterWorkToken && $this->clusterFencing !== null) {
                    $renewed = $this->clusterFencing->renewClusterWork($clusterToken, $this->claimLeaseSeconds);
                    if ($renewed === null) {
                        return false;
                    }
                    $leaseUntil = $renewed->leaseExpiresAt;

                    return true;
                }
                try {
                    $next = $this->metadataNow() + $this->claimLeaseSeconds;
                    if (! $claims->renewMultipartCollectorClaim(
                        $intent->attemptId,
                        $next,
                        $leaseUntil,
                        $intent->claimGeneration,
                    )) {
                        return false;
                    }
                } catch (CancelledException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    $this->markAllAwsCollectorFailure('multipart');
                    throw $error;
                }
                $leaseUntil = $next;

                return true;
            }, $this->renewIntervalSeconds);
            $failed = null;
            $localBackend = null;
            $awsState = null;
            try {
                $backend = $this->tiers->tier($intent->storageTier)->backend;
                $localBackend = $backend;
                while ($localBackend instanceof ObservedStorageBackend) {
                    $localBackend = $localBackend->innerBackend();
                }
                if ($localBackend instanceof FilesystemBackend) {
                    if ($intent->dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
                        throw new \RuntimeException('Local multipart completion dispatch history is unknown.');
                    }
                    $this->assertLocalCompletionPaths($localBackend, $intent);
                    foreach ([$intent->assemblyPath, $intent->finalPath] as $path) {
                        $localBackend->sealFencedMultipartPath($path, $intent->attemptId);
                    }
                } elseif ($localBackend instanceof AwsS3StorageBackend) {
                    $awsState = $this->awsStateFor($backend, $intent->storageTier);
                    $awsObservedStates[spl_object_id($awsState)] = $awsState;
                    $binding = $localBackend->validatedBinding()
                        ?? throw new \LogicException('AWS multipart collector lost its validated binding.');
                    $awsState->assertExecutionBinding(
                        $binding,
                        $intent->storageTier,
                        $this->metadata,
                    );
                    // No proof-safe AWS completion-path retirement capability is
                    // registered yet. Retain the tombstone as visible backlog;
                    // expected retention is not a transient collector failure.
                } else {
                    foreach (array_unique([$intent->assemblyPath, $intent->finalPath]) as $path) {
                        $backend->deleteObjectByPath($path, $intent->bucket);
                        try {
                            $stream = $backend->getObjectByPath($path);
                        } catch (NoSuchKeyException) {
                            continue;
                        }
                        $stream->close();
                        throw new \RuntimeException('Multipart completion path is still readable after delete.');
                    }
                }
            } catch (CancelledException $error) {
                try {
                    $heartbeat->stop();
                } catch (\Throwable) {
                    // Another collector may recover the expired claim.
                }
                throw $error;
            } catch (\Throwable $error) {
                $failed = $error;
            }
            try {
                $heartbeat->stop();
            } catch (\Throwable $error) {
                if ($localBackend instanceof AwsS3StorageBackend) {
                    if ($awsState !== null) {
                        $awsFailedStates[spl_object_id($awsState)] = $awsState;
                        $awsState->markCollectorFailure('multipart');
                    }
                }
                $this->logger->warning('Multipart completion claim was lost during physical cleanup.', [
                    'component' => 'multipart_intent_gc',
                    'attempt_id' => $intent->attemptId,
                    'exception' => $error::class,
                ]);
                continue;
            }
            if ($failed !== null && $localBackend instanceof AwsS3StorageBackend && $awsState !== null) {
                $awsFailedStates[spl_object_id($awsState)] = $awsState;
                $awsState->markCollectorFailure('multipart');
            }
            // Generic remote adapters cannot prove a late worker has ended.
            // Keep the tombstone and revisit it periodically.
            $nextAttemptAt = $failed === null && $localBackend instanceof FilesystemBackend
                ? 1.0e300
                : $this->metadataNow() + ($failed === null ? 3600 : 60);
            if ($clusterToken instanceof ClusterWorkToken && $this->clusterFencing !== null) {
                /** @var array{error: ?\Throwable} $metadataFailure */
                $metadataFailure = ['error' => null];
                try {
                    $retained = $this->clusterFencing->transactionWithClusterWork(
                        $clusterToken,
                        function () use ($intent, $nextAttemptAt, $leaseUntil, $clusterToken, $failed, &$metadataFailure): bool {
                            try {
                                $retained = $this->metadata->retryMultipartCollectorClaim(
                                    $intent->attemptId,
                                    $nextAttemptAt,
                                    $leaseUntil,
                                    $intent->claimGeneration,
                                );
                            } catch (\Throwable $error) {
                                $metadataFailure['error'] = $error;
                                throw $error;
                            }
                            if ($retained && $failed !== null
                                && $this->clusterFencing instanceof \OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder) {
                                $this->clusterFencing->recordClusterWorkOutcome(
                                    $clusterToken,
                                    \OpsFour\S3Server\Metadata\ClusterWorkOutcomeRecorder::FAILED_TAKEOVER,
                                );
                            }

                            return $retained;
                        },
                    );
                } catch (\Throwable $error) {
                    if ($metadataFailure['error'] !== null) {
                        $this->markAllAwsCollectorFailure('multipart');
                        throw $metadataFailure['error'];
                    }
                    if (! $error instanceof \RuntimeException) {
                        throw $error;
                    }
                    $retained = false;
                }
            } else {
                try {
                    $retained = $this->metadata->retryMultipartCollectorClaim(
                        $intent->attemptId,
                        $nextAttemptAt,
                        $leaseUntil,
                        $intent->claimGeneration,
                    );
                } catch (CancelledException $error) {
                    throw $error;
                } catch (\Throwable $error) {
                    $this->markAllAwsCollectorFailure('multipart');
                    throw $error;
                }
            }
            if ($failed === null) {
                if ($retained) {
                    $cleaned++;
                }
            } elseif ($retained) {
                if ($localBackend instanceof AwsS3StorageBackend) {
                    if ($awsState !== null) {
                        $awsFailedStates[spl_object_id($awsState)] = $awsState;
                    }
                }
                $this->logger->warning('Multipart completion path reconciliation failed.', [
                    'component' => 'multipart_intent_gc',
                    'attempt_id' => $intent->attemptId,
                    'bucket' => $intent->bucket,
                    'exception' => $failed::class,
                    'reason' => 'delete_or_verify_failed',
                ]);
            }
        }

        if ($queueExhausted && $hasAwsTier) {
            foreach ($this->allAwsStates() as $state) {
                $awsObservedStates[spl_object_id($state)] = $state;
            }
        }
        foreach ($awsObservedStates as $id => $state) {
            if (isset($awsFailedStates[$id])) {
                $state->markCollectorFailure('multipart');
            } else {
                $state->markCollectorHealthy('multipart');
            }
        }

        return $cleaned;
    }

    private function hasAwsTier(): bool
    {
        foreach ($this->tiers->all() as $tier) {
            $backend = $tier->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if ($backend instanceof AwsS3StorageBackend) {
                return true;
            }
        }

        return false;
    }

    private function awsStateFor(StorageBackend $backend, string $storageTier): AwsS3OperationalState
    {
        if ($this->awsTierRuntimes !== null) {
            try {
                return $this->awsTierRuntimes->operationalStateFor($backend, $storageTier);
            } catch (\Throwable $error) {
                $this->awsTierRuntimes->markAllCollectorFailure('multipart');
                throw $error;
            }
        }
        $physicalBackend = $backend;
        while ($physicalBackend instanceof ObservedStorageBackend) {
            $physicalBackend = $physicalBackend->innerBackend();
        }
        if (! $physicalBackend instanceof AwsS3StorageBackend) {
            throw new \LogicException('AWS multipart collector received a non-AWS claimed tier.');
        }
        $state = $this->awsOperationalState ?? $physicalBackend->operationalState()
            ?? throw new \LogicException('AWS multipart collector requires an exact operational state.');
        if ($physicalBackend->operationalState() !== $state) {
            throw new \LogicException('AWS multipart collector operational state does not match the claimed tier.');
        }
        $binding = $physicalBackend->validatedBinding()
            ?? throw new \LogicException('AWS multipart collector lost its validated binding.');
        $state->assertExecutionBinding($binding, $storageTier, $this->metadata);

        return $state;
    }

    /** @return list<AwsS3OperationalState> */
    private function allAwsStates(): array
    {
        if ($this->awsTierRuntimes !== null) {
            $states = [];
            foreach ($this->awsTierRuntimes->all() as $binding) {
                $states[spl_object_id($binding->operationalState)] = $binding->operationalState;
            }

            return array_values($states);
        }

        if ($this->awsOperationalState !== null) {
            return [$this->awsOperationalState];
        }
        $states = [];
        foreach ($this->tiers->all() as $tier) {
            $backend = $tier->backend;
            while ($backend instanceof ObservedStorageBackend) {
                $backend = $backend->innerBackend();
            }
            if ($backend instanceof AwsS3StorageBackend && $backend->operationalState() !== null) {
                $states[spl_object_id($backend->operationalState())] = $backend->operationalState();
            }
        }
        if (count($states) > 1) {
            throw new \LogicException('Multiple AWS collector states require an exact tier runtime registry.');
        }

        return array_values($states);
    }

    private function markAllAwsCollectorFailure(string $collector): void
    {
        foreach ($this->allAwsStates() as $state) {
            $state->markCollectorFailure($collector);
        }
    }

    private function metadataNow(): float
    {
        try {
            return $this->metadata->multipartWriteIntentNow();
        } catch (CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->markAllAwsCollectorFailure('multipart');
            throw $error;
        }
    }

    private function assertLocalCompletionPaths(FilesystemBackend $backend, \OpsFour\S3Server\Metadata\MultipartWriteIntent $intent): void
    {
        $root = rtrim($backend->getBasePath(), '/');
        $prefix = $root . '/.assemblies/' . $intent->bucket . '/' . $intent->bucketGeneration . '/';
        $assembly = $intent->assemblyPath;
        $final = $intent->finalPath;
        StoragePath::assertMultipartCompletionPath($root, $assembly);
        StoragePath::assertMultipartCompletionPath($root, $final);
        if (! str_starts_with($assembly, $prefix)
            || ! str_ends_with($assembly, '/' . $intent->attemptId . '/assembly')
            || $final !== substr($assembly, 0, -strlen('assembly')) . 'final') {
            throw new \RuntimeException('Local multipart completion paths do not match their persisted attempt.');
        }
    }
}
