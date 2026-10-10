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
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Health check endpoint for load balancers and Kubernetes probes.
 *
 * Returns 200 {"status":"ok"} when dependencies are available, or 503
 * {"status":"degraded"} otherwise. Optional API admission evidence shares
 * its revocation latch with readiness. A separate EFS evidence check only
 * substitutes the potentially blocking bucketExists() probe for one exact
 * filesystem tier. Neither check enables production EFS serving.
 */
final class HealthCheckHandler implements RequestHandler
{
    private float $lastTierReferenceCheckAt = -INF;

    private float $lastRateLimitCheckAt = -INF;

    private bool $rateLimitHealthy = true;

    private ?string $missingReferencedTier = null;

    private bool $efsEvidenceLost = false;

    private bool $admissionEvidenceLost = false;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?MetadataStore $metadata = null,
        LoggerInterface $logger = new NullLogger(),
        private readonly ?StorageTierRegistry $storageTiers = null,
        private readonly int $tierReferenceCheckIntervalSeconds = 30,
        private readonly bool $rateLimitEnabled = false,
        private readonly int $rateLimitCheckIntervalSeconds = 30,
        private readonly ?EfsHostEvidence $efsEvidence = null,
        private readonly ?EfsHostEvidence $admissionEvidence = null,
        private readonly ?CredentialHealthProbe $credentialHealthProbe = null,
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        if ($efsEvidence === null) {
            return;
        }
        if ($storageTiers === null || count($storageTiers->all()) !== 1) {
            throw new \InvalidArgumentException('EFS readiness evidence requires exactly one filesystem tier.');
        }
        $backend = $storageTiers->defaultBackend();
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if (! $backend instanceof FilesystemBackend) {
            throw new \InvalidArgumentException('EFS readiness evidence requires a filesystem tier.');
        }
        $backendEvidence = $backend->hostEvidenceReader();
        if (! $efsEvidence instanceof EfsHostEvidenceReader || $backendEvidence !== $efsEvidence) {
            throw new \InvalidArgumentException('EFS readiness evidence does not match its filesystem tier.');
        }
    }

    public function handleRequest(Request $request): Response
    {
        if (! $this->checkAdmissionEvidence() || ! $this->checkEfsEvidence()) {
            return $this->degradedResponse();
        }

        if ($this->metadata !== null) {
            try {
                // Lightweight probe: fetch a non-existent bucket.
                $this->metadata->getBucket('__health_check__');
            } catch (\Throwable $e) {
                return $this->degraded('metadata', $e);
            }
        }

        if ($this->credentialHealthProbe !== null) {
            try {
                $this->credentialHealthProbe->assertCredentialStoreAvailable();
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
            } catch (\Throwable $e) {
                $this->rateLimitHealthy = false;
                $this->logDegraded('rate_limit', $e);
            } finally {
                $this->lastRateLimitCheckAt = hrtime(true) / 1e9;
            }
        }
        if ($this->rateLimitEnabled && ! $this->rateLimitHealthy) {
            return $this->degradedResponse();
        }

        if ($this->metadata !== null && $this->storageTiers !== null) {
            if (hrtime(true) / 1e9 - $this->lastTierReferenceCheckAt >= max(0, $this->tierReferenceCheckIntervalSeconds)) {
                try {
                    $this->missingReferencedTier = null;
                    foreach ($this->metadata->listReferencedStorageTiers() as $name) {
                        if (!$this->storageTiers->has($name)) {
                            $this->missingReferencedTier = $name;
                            break;
                        }
                    }
                    $this->lastTierReferenceCheckAt = hrtime(true) / 1e9;
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

        if (! $this->checkAdmissionEvidence() || ! $this->checkEfsEvidence()) {
            return $this->degradedResponse();
        }

        if ($this->efsEvidence === null) {
            foreach ($this->storageTiers?->all() ?? [] as $tier) {
                try {
                    // The result is irrelevant: a successful lookup proves that
                    // the backend can execute a read operation.
                    $tier->backend->bucketExists('__health_check__');
                    $backend = $tier->backend;
                    while ($backend instanceof ObservedStorageBackend) {
                        $backend = $backend->innerBackend();
                    }
                    if ($backend instanceof FilesystemBackend && $backend->hostEvidenceReader() === null) {
                        // Ordinary local roots can become read-only after startup.
                        // Guarded EFS roots must use host evidence instead of
                        // adding a potentially blocking NFS stat to this fiber.
                        $root = $backend->getBasePath();
                        clearstatcache(true, $root);
                        $permissions = @fileperms($root);
                        if (! @is_dir($root) || $permissions === false
                            || ($permissions & 0222) === 0 || ! @is_writable($root)) {
                            throw new \RuntimeException('Local storage root is not writable.');
                        }
                    }
                } catch (\Throwable $e) {
                    return $this->degraded('storage:' . $tier->name, $e);
                }
            }
        }

        // A dependency probe may yield while the watcher revokes admission.
        // Never return a healthy result based on an earlier evidence check.
        if (! $this->checkAdmissionEvidence() || ! $this->checkEfsEvidence()) {
            return $this->degradedResponse();
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json', 'Cache-Control' => 'no-store'],
            body: '{"status":"ok"}',
        );
    }

    /** @phpstan-impure The watcher may revoke evidence between calls. */
    private function checkEfsEvidence(): bool
    {
        if ($this->efsEvidence === null) {
            return true;
        }
        if ($this->efsEvidenceLost) {
            return false;
        }
        try {
            $this->efsEvidence->assertFresh();
            return true;
        } catch (\Throwable $error) {
            $this->efsEvidenceLost = true;
            $this->logDegraded('efs_evidence', $error);
            return false;
        }
    }

    /** @phpstan-impure The admission latch may revoke evidence between calls. */
    private function checkAdmissionEvidence(): bool
    {
        if ($this->admissionEvidence === null) {
            return true;
        }
        if ($this->admissionEvidenceLost) {
            return false;
        }
        try {
            $this->admissionEvidence->assertFresh();
            return true;
        } catch (\Throwable $error) {
            $this->admissionEvidenceLost = true;
            $this->logDegraded('efs_admission', $error);
            return false;
        }
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
