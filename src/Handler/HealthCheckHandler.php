<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Health check endpoint for load balancers and Kubernetes probes.
 *
 * Returns 200 {"status":"ok"} when metadata and every storage tier are
 * reachable, or 503 {"status":"degraded"} when a dependency is unavailable.
 */
final class HealthCheckHandler implements RequestHandler
{
    public function __construct(
        private readonly ?MetadataStore $metadata = null,
        private readonly LoggerInterface $logger = new NullLogger(),
        private readonly ?StorageTierRegistry $storageTiers = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($this->metadata !== null) {
            try {
                // Lightweight probe: fetch a non-existent bucket.
                $this->metadata->getBucket('__health_check__');
            } catch (\Throwable $e) {
                return $this->degraded('metadata', $e);
            }
        }

        foreach ($this->storageTiers?->all() ?? [] as $tier) {
            try {
                // The result is irrelevant: a successful lookup proves that
                // the backend can execute an operation without writing data.
                $tier->backend->bucketExists('__health_check__');
            } catch (\Throwable $e) {
                return $this->degraded('storage:' . $tier->name, $e);
            }
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: '{"status":"ok"}',
        );
    }

    private function degraded(string $component, \Throwable $error): Response
    {
        $this->logger->error('Health check failed for {component}: {error}', [
            'component' => $component,
            'error' => $error->getMessage(),
            'exception' => $error::class,
        ]);

        return new Response(
            status: 503,
            headers: ['Content-Type' => 'application/json'],
            body: '{"status":"degraded"}',
        );
    }
}
