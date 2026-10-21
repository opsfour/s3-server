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
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
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
            $this->readinessCheck = async(function (): void {
                $this->runReadinessCheck();
                $this->lastReadinessCheckAt = hrtime(true) / 1e9;
            });
        }

        try {
            $this->readinessCheck->await(new TimeoutCancellation($this->readinessCheckTimeoutSeconds));
        } catch (\Amp\CancelledException $e) {
            if (! $e->getPrevious() instanceof TimeoutException) {
                throw $e;
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

    private function runReadinessCheck(): void
    {
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
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->recordDegraded('metadata', $e);

                return;
            }
        }

        if ($this->credentialHealthProbe !== null) {
            try {
                $this->credentialHealthProbe->assertCredentialStoreAvailable();
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->recordDegraded('credentials', $e);

                return;
            }
        }

        if ($this->metadata !== null && $this->rateLimitEnabled
            && hrtime(true) / 1e9 - $this->lastRateLimitCheckAt >= max(0, $this->rateLimitCheckIntervalSeconds)) {
            try {
                // A dedicated key tests the write path without consuming a client's quota.
                // A normal "limit exhausted" result still proves the database call worked.
                $this->metadata->rateLimitCheck('__rate_limit_health__', 1.0, 1.0);
                $this->rateLimitHealthy = true;
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->rateLimitHealthy = false;
                $this->logDegraded('rate_limit', $e);
            }
            $this->lastRateLimitCheckAt = hrtime(true) / 1e9;
        }
        if ($this->rateLimitEnabled && ! $this->rateLimitHealthy) {
            $this->readinessHealthy = false;

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
                    $this->missingReferencedTier = $missingReferencedTier;
                    $this->lastTierReferenceCheckAt = hrtime(true) / 1e9;
                } catch (\Amp\CancelledException $e) {
                    throw $e;
                } catch (\Throwable $e) {
                    $this->recordDegraded('metadata', $e);

                    return;
                }
            }
            if ($this->missingReferencedTier !== null) {
                $this->recordDegraded(
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
                } else {
                    // The result is irrelevant: a successful lookup proves
                    // that the backend can execute a read operation.
                    $tier->backend->bucketExists('__health_check__');
                }
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                $this->recordDegraded('storage:' . $tier->name, $e);

                return;
            }
        }

        $this->readinessHealthy = true;
    }

    private function recordDegraded(string $component, \Throwable $error): void
    {
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
        return new Response(
            status: $this->readinessHealthy ? 200 : 503,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: $this->readinessHealthy ? '{"status":"ok"}' : '{"status":"degraded"}',
        );
    }
}
