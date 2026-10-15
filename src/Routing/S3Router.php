<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Http\QueryStringParser;

/**
 * S3-specific request handler implementing the second tier of two-tier routing.
 *
 * This class implements Amp's RequestHandler interface. It is NOT an Amp Router
 * itself -- it IS the handler that the Amp Router delegates to. The Amp Router
 * dispatches requests by path pattern to this S3Router, which then:
 *
 * 1. Extracts the bucket name (from Amp Router args or virtual-hosted Host header)
 * 2. Determines the scope: service / bucket / object
 * 3. Resolves the exact S3 operation via OperationResolver
 * 4. Sets request attributes (bucket, key, operation) for downstream handlers
 * 5. Dispatches to the registered handler via HandlerRegistry
 *
 * The Amp Router should configure three catch-all routes:
 * - GET /                                -> S3Router (service-level)
 * - {METHOD} /{bucket}                   -> S3Router (bucket-level)
 * - {METHOD} /{bucket}/{key:.+}          -> S3Router (object-level)
 *
 * Route args are accessed via $request->getAttribute(Router::class).
 */
final class S3Router implements RequestHandler
{
    /**
     * @param  HandlerRegistry  $handlerRegistry  Registry mapping operations to handlers.
     * @param  string|null  $baseDomain  Base domain for virtual-hosted style bucket
     *                                   addressing (e.g., "s3.example.com").
     *                                   Null for path-style only.
     */
    public function __construct(
        private readonly HandlerRegistry $handlerRegistry,
        private readonly ?string $baseDomain = null,
    ) {}

    public function handleRequest(Request $request): Response
    {
        // 1. Determine bucket and key. Virtual-hosted style takes precedence.
        $virtualBucket = BucketNameExtractor::extractVirtualHosted($request, $this->baseDomain);
        if ($virtualBucket !== null) {
            $bucket = $virtualBucket;
            $key = rawurldecode(ltrim($request->getUri()->getPath(), '/'));
        } else {
            [$bucket, $key] = BucketNameExtractor::extractPathStyle($request);
        }

        // 2. Determine scope.
        $scope = self::determineScope($bucket, $key);

        // 3. Build query params and headers maps for operation resolution.
        $queryParams = self::extractQueryParams($request);
        $headers = self::extractHeaders($request);

        // 4. Resolve the S3 operation.
        $operation = OperationResolver::resolve(
            method: $request->getMethod(),
            scope: $scope,
            queryParams: $queryParams,
            headers: $headers,
        );

        // 5. Set request attributes for downstream handlers and middleware.
        $request->setAttribute('s3.bucket', $bucket ?? '');
        $request->setAttribute('s3.key', $key ?? '');
        $request->setAttribute('s3.operation', $operation);

        // 6. Dispatch to the registered handler.
        $handler = $this->handlerRegistry->get($operation);

        return $handler->handleRequest($request);
    }

    /**
     * Determine the request scope based on bucket and key presence.
     *
     * @param  string|null  $bucket  The bucket name, or null.
     * @param  string|null  $key  The object key, or null.
     * @return string One of 'service', 'bucket', or 'object'.
     */
    private static function determineScope(?string $bucket, ?string $key): string
    {
        if ($bucket === null || $bucket === '') {
            return 'service';
        }

        if ($key === null || $key === '') {
            return 'bucket';
        }

        return 'object';
    }

    /**
     * Extract query parameters as a flat key => value map.
     *
     * We parse the raw query string ourselves to preserve keys with no value
     * (e.g., ?acl, ?uploads). PHP's parse_str handles "?acl" by creating
     * ['acl' => ''], but we parse manually to ensure consistent behavior
     * with S3 semantics where key existence is what matters.
     *
     * @return array<string, string>
     */
    private static function extractQueryParams(Request $request): array
    {
        return QueryStringParser::parse($request->getUri()->getQuery());
    }

    /**
     * Extract all headers as a lowercase-name => first-value map.
     *
     * @return array<string, string>
     */
    private static function extractHeaders(Request $request): array
    {
        $headers = [];

        foreach ($request->getHeaders() as $name => $values) {
            $lower = \strtolower($name);
            $headers[$lower] = $values[0] ?? '';
        }

        return $headers;
    }
}
