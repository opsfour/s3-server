<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Observability\MetricsCollector;

final class MetricsMiddleware implements Middleware
{
    public function __construct(
        private readonly MetricsCollector $metrics,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $startedAt = hrtime(true);
        $response = $requestHandler->handleRequest($request);

        $operation = $request->getAttribute('s3.operation');
        if ($operation instanceof \BackedEnum) {
            $operation = (string) $operation->value;
        }

        $this->metrics->recordRequest(
            method: $request->getMethod(),
            operation: is_string($operation) ? $operation : null,
            status: $response->getStatus(),
            durationNs: hrtime(true) - $startedAt,
        );

        return $response;
    }
}
