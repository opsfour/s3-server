<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Health check endpoint for load balancers and Kubernetes probes.
 *
 * Returns 200 {"status":"ok"} when dependencies are available, or 503
 * {"status":"degraded"} otherwise.
 */
final class HealthCheckHandler implements RequestHandler
{
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
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function handleRequest(Request $request): Response
    {
        if ($this->metadata !== null) {
            try {
                // Lightweight probe: fetch a non-existent bucket.
                $this->metadata->getBucket('__health_check__');
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->degraded('metadata', $e);
            }
        }

        if ($this->credentialHealthProbe !== null) {
            try {
                $this->credentialHealthProbe->assertCredentialStoreAvailable();
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->degraded('credentials', $e);
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
            return $this->degradedResponse();
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
                    return $this->degraded('metadata', $e);
                }
            }
            if ($this->missingReferencedTier !== null) {
                return $this->degraded(
                    'storage:' . $this->missingReferencedTier,
                    new \RuntimeException('Persisted storage tier is not configured on this API node.'),
                );
            }
        }

        foreach ($this->storageTiers?->all() ?? [] as $tier) {
            try {
                // The result is irrelevant: a successful lookup proves that
                // the backend can execute a read operation.
                $tier->backend->bucketExists('__health_check__');
                $backend = $tier->backend;
                while ($backend instanceof ObservedStorageBackend) {
                    $backend = $backend->innerBackend();
                }
                if ($backend instanceof FilesystemBackend) {
                    // Ordinary local roots can become read-only after startup.
                    $root = $backend->getBasePath();
                    clearstatcache(true, $root);
                    $permissions = @fileperms($root);
                    if (! @is_dir($root) || $permissions === false
                        || ($permissions & 0222) === 0 || ! @is_writable($root)) {
                        throw new \RuntimeException('Local storage root is not writable.');
                    }
                }
            } catch (\Amp\CancelledException $e) {
                throw $e;
            } catch (\Throwable $e) {
                return $this->degraded('storage:' . $tier->name, $e);
            }
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: '{"status":"ok"}',
        );
    }

    private function degraded(string $component, \Throwable $error): Response
    {
        $this->logDegraded($component, $error);

        return $this->degradedResponse();
    }

    private function logDegraded(string $component, \Throwable $error): void
    {
        $this->logger->error('Health check failed for {component}.', [
            'component' => $component,
            'exception' => $error::class,
        ]);
    }

    private function degradedResponse(): Response
    {
        return new Response(
            status: 503,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: '{"status":"degraded"}',
        );
    }
}
