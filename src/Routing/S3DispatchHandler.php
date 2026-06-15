<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;

/**
 * Reads the s3.operation attribute (set by S3AttributeMiddleware)
 * and dispatches to the registered handler via HandlerRegistry.
 *
 * This is the innermost handler in the middleware stack.
 */
final class S3DispatchHandler implements RequestHandler
{
    public function __construct(
        private readonly HandlerRegistry $handlerRegistry,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $operation = $request->getAttribute('s3.operation');
        if ($operation === null) {
            // OPTIONS preflight that wasn't handled by CORS middleware (no CORS config).
            return new Response(status: 200);
        }
        $handler = $this->handlerRegistry->get($operation);

        return $handler->handleRequest($request);
    }
}
