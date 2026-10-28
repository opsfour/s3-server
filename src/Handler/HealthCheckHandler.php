<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use Amp\Future;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MetadataWriteReadinessProbe;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\WriteIntentCapacityReader;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;

/**
 * Health check endpoint for load balancers and Kubernetes probes.
 *
 * Returns 200 {"status":"ok"} when dependencies are available, or 503
 * {"status":"degraded"} otherwise.
 */
final class HealthCheckHandler implements RequestHandler
{
    private float $lastReadinessCheckAt = -INF;

    private bool $readinessHealthy = true;

    /** @var Future<void>|null */
    private ?Future $readinessCheck = null;

    private ?int $readinessCheckGeneration = null;

    private int $readinessGeneration = 0;

    private bool $readinessTimeoutLogged = false;

    private float $lastTierReferenceCheckAt = -INF;

    private float $lastRateLimitCheckAt = -INF;

    private bool $rateLimitHealthy = true;

    private ?string $missingReferencedTier = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?MetadataStore $metadata = null,
        LoggerInterface $logger = new NullLogger(),
        private readonly ?StorageTierRegistry $storageTiers = null,
        private readonly int $tierReferenceCheckIntervalSeconds = 30,
        private readonly bool $rateLimitEnabled = false,
        private readonly int $rateLimitCheckIntervalSeconds = 30,
        private readonly ?CredentialHealthProbe $credentialHealthProbe = null,
        private readonly int $readinessCheckIntervalSeconds = 30,
        private readonly float $readinessCheckTimeoutSeconds = 5.0,
        private readonly ?AwsS3OperationalState $awsOperationalState = null,
    ) {
        if ($readinessCheckIntervalSeconds < 0) {
            throw new \InvalidArgumentException('Readiness check interval must be non-negative.');
        }
        if (! is_finite($readinessCheckTimeoutSeconds) || $readinessCheckTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Readiness check timeout must be a finite number greater than zero.');
        }
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function handleRequest(Request $request): Response
    {
        $now = hrtime(true) / 1e9;
        if ($now - $this->lastReadinessCheckAt < $this->readinessCheckIntervalSeconds) {
            return $this->readinessResponse();
        }

        if ($this->readinessCheck === null || $this->readinessCheck->isComplete()) {
            $this->readinessTimeoutLogged = false;
            $generation = ++$this->readinessGeneration;
            $this->readinessCheck = async(function () use ($generation): void {
                $this->runReadinessCheck($generation);
                if ($this->readinessGeneration === $generation) {
                    $this->lastReadinessCheckAt = hrtime(true) / 1e9;
                }
            });
            $this->readinessCheckGeneration = $generation;
        }

        $readinessCheck = $this->readinessCheck;
        $generation = $this->readinessCheckGeneration
            ?? throw new \LogicException('Readiness check generation is missing.');
        try {
            $readinessCheck->await(new TimeoutCancellation($this->readinessCheckTimeoutSeconds));
        } catch (\Amp\CancelledException $e) {
            if (! $e->getPrevious() instanceof TimeoutException) {
                throw $e;
            }

            if ($this->readinessGeneration === $generation) {
                ++$this->readinessGeneration;
                $readinessCheck->ignore();
            }
            $this->readinessHealthy = false;
            $this->lastReadinessCheckAt = hrtime(true) / 1e9;
            if (! $this->readinessTimeoutLogged) {
                $this->readinessTimeoutLogged = true;
                $this->logDegraded('readiness', new \RuntimeException('Dependency readiness check timed out.'));
            }
        }

        return $this->readinessResponse();
    }

    private function runReadinessCheck(int $generation): void
    {
        if ($this->awsOperationalState !== null) {
            try {
                $this->awsOperationalState->assertMutationReady();
            } catch (\Throwable $e) {
                $this->recordDegraded($generation, 'aws', $e);
                return;
            }
        }

        if ($this->metadata !== null) {
            try {
                // The read proves query availability. Built-in stores also run
                // a no-row DML statement so readiness detects read-only roles
                // and databases without changing metadata.
                $this->metadata->getBucket('__health_check__');
                $writeProbe = $this->metadata;
                while ($writeProbe instanceof ObservedMetadataStore || $writeProbe instanceof CachedMetadataStoreDecorator) {
                    $writeProbe = $writeProbe->innerStore();
                }
                if ($writeProbe instanceof MetadataWriteReadinessProbe) {
                    $writeProbe->assertMetadataWritable();
                }
                if ($writeProbe instanceof WriteIntentCapacityReader) {
                    $capacity = $writeProbe->getWriteIntentCapacityCounts();
                    if ($capacity !== null
                        && ($capacity['object'] >= ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL
                            || $capacity['object_seal'] >= ObjectWriteIntentStore::MAX_SEALS_GLOBAL)) {
                        throw new \RuntimeException('Global object write recovery capacity is exhausted.');
                    }
                }
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->recordDegraded($generation, 'metadata', $e);

                return;
            }
        }

        if ($this->credentialHealthProbe !== null) {
            try {
                $this->credentialHealthProbe->assertCredentialStoreAvailable();
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->recordDegraded($generation, 'credentials', $e);

                return;
            }
        }

        if ($this->metadata !== null && $this->rateLimitEnabled
            && hrtime(true) / 1e9 - $this->lastRateLimitCheckAt >= max(0, $this->rateLimitCheckIntervalSeconds)) {
            $rateLimitError = null;
            try {
                // A dedicated key tests the write path without consuming a client's quota.
                // A normal "limit exhausted" result still proves the database call worked.
                $this->metadata->rateLimitCheck('__rate_limit_health__', 1.0, 1.0);
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $rateLimitError = $e;
            }
            if ($this->readinessGeneration !== $generation) {
                return;
            }
            $this->rateLimitHealthy = $rateLimitError === null;
            if ($rateLimitError !== null) {
                $this->logDegraded('rate_limit', $rateLimitError);
            }
            $this->lastRateLimitCheckAt = hrtime(true) / 1e9;
        }
        if ($this->rateLimitEnabled && ! $this->rateLimitHealthy) {
            if ($this->readinessGeneration === $generation) {
                $this->readinessHealthy = false;
            }

            return;
        }

        if ($this->metadata !== null && $this->storageTiers !== null) {
            if (hrtime(true) / 1e9 - $this->lastTierReferenceCheckAt >= max(0, $this->tierReferenceCheckIntervalSeconds)) {
                try {
                    $missingReferencedTier = null;
                    foreach ($this->metadata->listReferencedStorageTiers() as $name) {
                        if (!$this->storageTiers->has($name)) {
                            $missingReferencedTier = $name;
                            break;
                        }
                    }
                    if ($this->readinessGeneration !== $generation) {
                        return;
                    }
                    $this->missingReferencedTier = $missingReferencedTier;
                    $this->lastTierReferenceCheckAt = hrtime(true) / 1e9;
                } catch (\Amp\CancelledException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $this->recordDegraded($generation, 'metadata', $e);

                    return;
                }
            }
            if ($this->missingReferencedTier !== null) {
                $this->recordDegraded(
                    $generation,
                    'storage:' . $this->missingReferencedTier,
                    new \RuntimeException('Persisted storage tier is not configured on this API node.'),
                );

                return;
            }
        }

        foreach ($this->storageTiers?->all() ?? [] as $tier) {
            try {
                $backend = $tier->backend;
                while ($backend instanceof ObservedStorageBackend) {
                    $backend = $backend->innerBackend();
                }
                if ($backend instanceof FilesystemBackend) {
                    // Keep the complete local probe in its isolated worker;
                    // a preceding filesystem stat could otherwise strand a
                    // production metadata worker before the timeout applies.
                    $backend->assertWritable();
                } elseif ($backend instanceof AwsS3StorageBackend && $backend->operationalState() !== null) {
                    // The shared state already ran the exact owner-bound AWS
                    // probes above; generic backend probes stay deliberately closed.
                    continue;
                } else {
                    // The result is irrelevant: a successful lookup proves
                    // that the backend can execute a read operation.
                    $tier->backend->bucketExists('__health_check__');
                }
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->recordDegraded($generation, 'storage:' . $tier->name, $e);

                return;
            }
        }

        if ($this->readinessGeneration === $generation) {
            $this->readinessHealthy = true;
        }
    }

    private function recordDegraded(int $generation, string $component, \Throwable $error): void
    {
        if ($this->readinessGeneration !== $generation) {
            return;
        }
        $this->readinessHealthy = false;
        $this->logDegraded($component, $error);
    }

    private function logDegraded(string $component, \Throwable $error): void
    {
        $this->logger->error('Health check failed for {component}.', [
            'component' => $component,
            'exception' => $error::class,
        ]);
    }

    private function readinessResponse(): Response
    {
        if ($this->awsOperationalState !== null) {
            $snapshot = $this->awsOperationalState->snapshot();
            $body = json_encode([
                'status' => $this->readinessHealthy ? 'ok' : 'degraded',
                'aws' => [
                    'provider_type' => $snapshot['provider_type'],
                    'fingerprint' => $snapshot['fingerprint'],
                    'configuration_valid' => $snapshot['configuration_valid'],
                    'provider_admitted' => $snapshot['provider_admitted'],
                    'fence_complete' => $snapshot['fence_complete'],
                    'production_qualified' => $snapshot['production_qualified'],
                    'mutation_ready' => $snapshot['mutation_ready'],
                    'reason' => $snapshot['reason'],
                ],
            ], JSON_THROW_ON_ERROR);
        } else {
            $body = $this->readinessHealthy ? '{"status":"ok"}' : '{"status":"degraded"}';
        }

        return new Response(
            status: $this->readinessHealthy ? 200 : 503,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: $body,
        );
    }
}
