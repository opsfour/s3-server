<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use Psr\Log\LoggerInterface;

/**
 * Handles CORS preflight requests and adds CORS headers to responses.
 *
 * Evaluates per-bucket CORS rules from MetadataStore. If a bucket has
 * CORS rules configured, the first matching rule is used. If no rules
 * are configured, no CORS headers are added (S3 default).
 *
 * If no MetadataStore is injected, no CORS headers are added.
 */
final class CorsMiddleware implements Middleware
{
    public function __construct(
        private readonly ?MetadataStore $metadata = null,
        private readonly ?LoggerInterface $logger = null,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $origin = $request->getHeader('Origin');

        // No Origin header — not a CORS request, pass through.
        if ($origin === null) {
            return $requestHandler->handleRequest($request);
        }

        // Extract bucket name from request.
        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;

        // Try to match CORS rules for this bucket.
        $corsHeaders = $this->matchCorsRules($bucket, $origin, $request);

        // OPTIONS preflight request.
        if ($request->getMethod() === 'OPTIONS') {
            if ($corsHeaders !== null) {
                return new Response(
                    status: 200,
                    headers: $corsHeaders,
                    body: '',
                );
            }

            // No matching CORS rule — return 403 for preflight.
            return new Response(status: 403, body: '');
        }

        // Regular request with Origin — process then add CORS headers.
        $response = $requestHandler->handleRequest($request);

        if ($corsHeaders !== null) {
            foreach ($corsHeaders as $name => $value) {
                $response->setHeader($name, $value);
            }
        }

        return $response;
    }

    /**
     * Match request against bucket CORS rules.
     *
     * @return array<non-empty-string, string>|null Matched CORS headers, or null if no match.
     */
    private function matchCorsRules(?string $bucket, string $origin, Request $request): ?array
    {
        if ($this->metadata === null || $bucket === null) {
            // No metadata store or bucket — no CORS rules, no CORS headers (S3 default).
            return null;
        }

        try {
            $rules = $this->metadata->getBucketCors($bucket);
        } catch (\Throwable $e) {
            $this->logger?->warning("CORS: failed to fetch rules for bucket '{$bucket}': {$e->getMessage()}");

            return null;
        }

        if ($rules === []) {
            // No CORS config — no CORS headers (S3 default).
            return null;
        }

        $method = $request->getMethod();
        $requestedHeaders = $request->getHeader('access-control-request-headers');

        foreach ($rules as $rule) {
            // 1. Match Origin.
            if (! self::originMatches($origin, $rule['allowedOrigins'])) {
                continue;
            }

            // 2. Match HTTP method.
            $effectiveMethod = $method === 'OPTIONS'
                ? ($request->getHeader('access-control-request-method') ?? $method)
                : $method;

            if (! in_array($effectiveMethod, $rule['allowedMethods'], true) && ! in_array('*', $rule['allowedMethods'], true)) {
                continue;
            }

            // 3. For preflight: match Access-Control-Request-Headers.
            if ($method === 'OPTIONS' && $requestedHeaders !== null) {
                $reqHeaders = array_map('trim', explode(',', strtolower($requestedHeaders)));
                $allowedLower = array_map('strtolower', $rule['allowedHeaders']);

                if (! in_array('*', $allowedLower, true)) {
                    $allMatch = true;
                    foreach ($reqHeaders as $rh) {
                        if ($rh !== '' && ! in_array($rh, $allowedLower, true)) {
                            $allMatch = false;
                            break;
                        }
                    }
                    if (! $allMatch) {
                        continue;
                    }
                }
            }

            // First matching rule wins.
            return self::buildCorsHeaders($origin, $rule);
        }

        return null;
    }

    /**
     * Check if an origin matches against allowed origins (supports * wildcard).
     *
     * @param  list<string>  $allowedOrigins
     */
    private static function originMatches(string $origin, array $allowedOrigins): bool
    {
        foreach ($allowedOrigins as $allowed) {
            if ($allowed === '*') {
                return true;
            }
            if (fnmatch($allowed, $origin)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Build CORS response headers from a matched rule.
     *
     * @param  array{allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null}  $rule
     * @return array<non-empty-string, string>
     */
    private static function buildCorsHeaders(string $origin, array $rule): array
    {
        // Sanitize origin to prevent CRLF injection in response headers.
        $safeOrigin = str_replace(["\r", "\n"], '', $origin);

        $isWildcard = in_array('*', $rule['allowedOrigins'], true);

        $headers = [
            'Access-Control-Allow-Origin' => $isWildcard ? '*' : $safeOrigin,
            'Access-Control-Allow-Methods' => implode(', ', $rule['allowedMethods']),
            'Vary' => 'Origin',
        ];

        // Credentialed cross-origin requests require this header when origin is specific.
        if (!$isWildcard) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if (! empty($rule['allowedHeaders'])) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', $rule['allowedHeaders']);
        }

        if (! empty($rule['exposeHeaders'])) {
            $headers['Access-Control-Expose-Headers'] = implode(', ', $rule['exposeHeaders']);
        }

        if ($rule['maxAgeSeconds'] !== null) {
            $headers['Access-Control-Max-Age'] = (string) $rule['maxAgeSeconds'];
        }

        return $headers;
    }

}
