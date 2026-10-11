<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use OpsFour\S3Server\Security\RequestNetworkContext;
use OpsFour\S3Server\Xml\ErrorResponseBuilder;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Per-client token bucket rate limiter backed by the metadata store.
 *
 * Rate limit state is persisted in the database so it survives restarts
 * and works across multi-node deployments (Postgres/MySQL).
 * Returns S3 SlowDown (503) when the bucket is empty.
 * Fails closed with SlowDown when the configured limit cannot be checked.
 */
final class RateLimitMiddleware implements Middleware
{
    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly ?MetadataStore $metadata,
        private readonly float $maxRequestsPerSecond = 1000.0,
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if ($this->maxRequestsPerSecond <= 0 || $this->metadata === null) {
            return $requestHandler->handleRequest($request);
        }

        $ip = RequestNetworkContext::rateLimitIp($request);

        try {
            $allowed = $this->metadata->rateLimitCheck($ip, $this->maxRequestsPerSecond, $this->maxRequestsPerSecond);
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->logger->error('Rate-limit metadata check failed.', ['exception' => $error::class]);

            return $this->slowDownResponse($request);
        }

        if (!$allowed) {
            return $this->slowDownResponse($request);
        }

        return $requestHandler->handleRequest($request);
    }

    private function slowDownResponse(Request $request): Response
    {
        $requestId = $request->hasAttribute('requestId')
            ? (string) $request->getAttribute('requestId')
            : '';
        $xml = ErrorResponseBuilder::build(
            'SlowDown',
            'Please reduce your request rate.',
            $request->getUri()->getPath(),
            $requestId,
            $request->hasAttribute('amzId2') ? (string) $request->getAttribute('amzId2') : '',
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
}
