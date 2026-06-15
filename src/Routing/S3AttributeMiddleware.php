<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Router;

/**
 * Lightweight middleware that parses the request and sets s3.bucket,
 * s3.key, and s3.operation attributes for downstream middleware.
 *
 * This runs early in the middleware stack so that policy enforcement,
 * ACL enforcement, CORS, and other middleware can read these attributes.
 */
final class S3AttributeMiddleware implements Middleware
{
    public function __construct(
        private readonly ?string $baseDomain = null,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $routeArgs = $request->hasAttribute(Router::class)
            ? $request->getAttribute(Router::class)
            : [];

        $bucket = $this->extractBucket($request, $routeArgs);
        $key = $routeArgs['key'] ?? null;

        // Note: Amp Router already rawurldecode()s the path before matching.
        // Do NOT decode again here — double-decoding breaks keys with literal % characters.

        // Short-circuit OPTIONS requests before operation resolution.
        // OperationResolver does not recognize OPTIONS, so let CORS middleware handle it.
        if ($request->getMethod() === 'OPTIONS') {
            $request->setAttribute('s3.bucket', $bucket ?? '');
            $request->setAttribute('s3.key', $key ?? '');
            $request->setAttribute('s3.operation', null);

            return $requestHandler->handleRequest($request);
        }

        $scope = self::determineScope($bucket, $key);

        $queryParams = self::extractQueryParams($request);
        $headers = self::extractHeaders($request);

        $operation = OperationResolver::resolve(
            method: $request->getMethod(),
            scope: $scope,
            queryParams: $queryParams,
            headers: $headers,
        );

        $request->setAttribute('s3.bucket', $bucket ?? '');
        $request->setAttribute('s3.key', $key ?? '');
        $request->setAttribute('s3.operation', $operation);

        // Validate key length (S3 limit: 1024 bytes).
        // Set attributes BEFORE throwing so error handler can read them.
        if ($key !== null && strlen($key) > 1024) {
            throw new \OpsFour\S3Server\Exception\KeyTooLongException;
        }

        return $requestHandler->handleRequest($request);
    }

    private function extractBucket(Request $request, array $routeArgs): ?string
    {
        $virtualBucket = BucketNameExtractor::extract($request, $this->baseDomain);

        if ($virtualBucket !== null) {
            return $virtualBucket;
        }

        $bucket = $routeArgs['bucket'] ?? null;

        return ($bucket !== null && $bucket !== '') ? $bucket : null;
    }

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
     * @return array<string, string>
     */
    private static function extractQueryParams(Request $request): array
    {
        $rawQuery = $request->getUri()->getQuery();

        if ($rawQuery === '') {
            return [];
        }

        $result = [];
        $pairs = \explode('&', $rawQuery);

        foreach ($pairs as $pair) {
            if ($pair === '') {
                continue;
            }

            $eqPos = \strpos($pair, '=');

            if ($eqPos === false) {
                $result[\rawurldecode($pair)] = '';
            } else {
                $key = \rawurldecode(\substr($pair, 0, $eqPos));
                $value = \rawurldecode(\substr($pair, $eqPos + 1));
                if (!\array_key_exists($key, $result)) {
                    $result[$key] = $value;
                }
            }
        }

        return $result;
    }

    /**
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
