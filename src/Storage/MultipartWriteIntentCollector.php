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
    ) {
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
        $awsFailure = false;
        $awsObserved = false;
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
                if ($hasAwsTier) {
                    $this->awsOperationalState?->markCollectorFailure('multipart');
                }
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
            $now = $this->metadata->multipartWriteIntentNow();
            try {
                $claimed = $this->metadata->claimDueMultipartWriteIntents(1, $now, $now + $this->claimLeaseSeconds);
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                if ($hasAwsTier) {
                    $this->awsOperationalState?->markCollectorFailure('multipart');
                }
                throw $error;
            }
            if ($claimed === []) {
                $queueExhausted = true;
                break;
            }
            $intent = $claimed[0];
            // A watcher trip during SQL claim leaves its lease for recovery.
            $leaseUntil = $intent->nextAttemptAt;
            $heartbeat = new QueueLeaseHeartbeat(function (float $_) use ($intent, &$leaseUntil, $claims): bool {
                $next = $this->metadata->multipartWriteIntentNow() + $this->claimLeaseSeconds;
                if (! $claims->renewMultipartCollectorClaim(
                    $intent->attemptId,
                    $next,
                    $leaseUntil,
                    $intent->claimGeneration,
                )) {
                    return false;
                }
                $leaseUntil = $next;

                return true;
            }, $this->renewIntervalSeconds);
            $failed = null;
            $localBackend = null;
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
                    $awsObserved = true;
                    $binding = $localBackend->validatedBinding()
                        ?? throw new \LogicException('AWS multipart collector lost its validated binding.');
                    if ($localBackend->operationalState() !== $this->awsOperationalState
                        || $this->awsOperationalState === null) {
                        throw new \LogicException('AWS multipart collector requires the shared operational state.');
                    }
                    $this->awsOperationalState->assertExecutionBinding(
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
                    $awsFailure = true;
                    $this->awsOperationalState?->markCollectorFailure('multipart');
                }
                $this->logger->warning('Multipart completion claim was lost during physical cleanup.', [
                    'component' => 'multipart_intent_gc',
                    'attempt_id' => $intent->attemptId,
                    'exception' => $error::class,
                ]);
                continue;
            }
            // Generic remote adapters cannot prove a late worker has ended.
            // Keep the tombstone and revisit it periodically.
            $nextAttemptAt = $failed === null && $localBackend instanceof FilesystemBackend
                ? 1.0e300
                : $this->metadata->multipartWriteIntentNow() + ($failed === null ? 3600 : 60);
            $retained = $this->metadata->retryMultipartCollectorClaim(
                $intent->attemptId,
                $nextAttemptAt,
                $leaseUntil,
                $intent->claimGeneration,
            );
            if ($failed === null) {
                if ($retained) {
                    $cleaned++;
                }
            } elseif ($retained) {
                if ($localBackend instanceof AwsS3StorageBackend) {
                    $awsFailure = true;
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

        if ($awsFailure) {
            $this->awsOperationalState?->markCollectorFailure('multipart');
        } elseif ($awsObserved || ($hasAwsTier && $queueExhausted)) {
            $this->awsOperationalState?->markCollectorHealthy('multipart');
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
