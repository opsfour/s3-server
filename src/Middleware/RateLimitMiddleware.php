<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\ErrorResponseBuilder;

/**
 * Per-client token bucket rate limiter backed by the metadata store.
 *
 * Rate limit state is persisted in the database so it survives restarts
 * and works across multi-node deployments (Postgres/MySQL).
 * Returns S3 SlowDown (503) when the bucket is empty.
 * Fails open on database errors (allows the request through).
 */
final class RateLimitMiddleware implements Middleware
{
    public function __construct(
        private readonly ?MetadataStore $metadata,
        private readonly float $maxRequestsPerSecond = 1000.0,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if ($this->maxRequestsPerSecond <= 0 || $this->metadata === null) {
            return $requestHandler->handleRequest($request);
        }

        $remoteAddr = $request->getClient()->getRemoteAddress();
        $ip = $remoteAddr instanceof \Amp\Socket\InternetAddress
            ? $remoteAddr->getAddress()
            : $remoteAddr->toString();

        try {
            $allowed = $this->metadata->rateLimitCheck($ip, $this->maxRequestsPerSecond, $this->maxRequestsPerSecond);
        } catch (\Throwable) {
            // Fail open: allow the request if the database is unreachable.
            return $requestHandler->handleRequest($request);
        }

        if (!$allowed) {
            $requestId = $request->getAttribute('requestId') ?? '';
            $resource = $request->getUri()->getPath();

            $xml = ErrorResponseBuilder::build(
                'SlowDown',
                'Please reduce your request rate.',
                $resource,
                $requestId,
            );

            return new Response(
                status: 503,
                headers: [
                    'Content-Type' => 'application/xml',
                    'Retry-After' => '1',
                    'x-amz-request-id' => $requestId,
                ],
                body: $xml,
            );
        }

        return $requestHandler->handleRequest($request);
    }
}
