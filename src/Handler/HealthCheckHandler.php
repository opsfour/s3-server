<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Health check endpoint for load balancers and Kubernetes probes.
 *
 * Returns 200 {"status":"ok"} when the database is reachable,
 * or 503 {"status":"degraded"} when it is not.
 */
final class HealthCheckHandler implements RequestHandler
{
    public function __construct(
        private readonly ?MetadataStore $metadata = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function handleRequest(Request $request): Response
    {
        if ($this->metadata !== null) {
            try {
                // Lightweight probe: fetch a non-existent bucket.
                $this->metadata->getBucket('__health_check__');
            } catch (\Throwable $e) {
                $this->logger->error('Health check failed: {error}', ['error' => $e->getMessage()]);

                return new Response(
                    status: 503,
                    headers: ['Content-Type' => 'application/json'],
                    body: '{"status":"degraded"}',
                );
            }
        }

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/json'],
            body: '{"status":"ok"}',
        );
    }
}
