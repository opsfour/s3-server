<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
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
            return new Response(
                status: 403,
                headers: ['Vary' => 'Origin, Access-Control-Request-Headers, Access-Control-Request-Method'],
                body: '',
            );
        }

        // Regular request with Origin — process then add CORS headers.
        $response = $requestHandler->handleRequest($request);

        if ($corsHeaders !== null) {
            $existingVary = $response->getHeader('Vary');
            foreach ($corsHeaders as $name => $value) {
                if ($name === 'Vary' && $existingVary !== null && trim($existingVary) !== '*') {
                    $varyNames = array_map('trim', explode(',', $existingVary));
                    foreach (explode(',', $value) as $newName) {
                        $newName = trim($newName);
                        if (! in_array(strtolower($newName), array_map('strtolower', $varyNames), true)) {
                            $varyNames[] = $newName;
                        }
                    }
                    $value = implode(', ', $varyNames);
                } elseif ($name === 'Vary' && $existingVary !== null) {
                    $value = '*';
                }
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
        if ($this->metadata === null || $bucket === null || $bucket === '') {
            // No metadata store or bucket — no CORS rules, no CORS headers (S3 default).
            return null;
        }

        try {
            // Browser permissions must not outlive a bucket deletion on another
            // node. The general CORS cache is keyed by name, not generation.
            $metadata = $this->metadata;
            while ($metadata instanceof CachedMetadataStoreDecorator) {
                $metadata = $metadata->innerStore();
            }
            $observedBucket = $metadata->getBucket($bucket);
            if ($observedBucket === null) {
                return null;
            }
            $rules = $metadata->getBucketCors($bucket);
            if ($metadata->getBucket($bucket)?->generation !== $observedBucket->generation) {
                return null;
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('CORS rule lookup failed.', [
                'exception' => $e::class,
            ]);

            return null;
        }

        if ($rules === []) {
            // No CORS config — no CORS headers (S3 default).
            return null;
        }

        $method = $request->getMethod();
        $requestedHeaders = $request->getHeader('access-control-request-headers');
        $requestedHeaderNames = $requestedHeaders === null ? [] : array_map('trim', explode(',', strtolower($requestedHeaders)));
        if ($method === 'OPTIONS' && ($request->getHeader('access-control-request-method') === null
            || ($requestedHeaders !== null && in_array('', $requestedHeaderNames, true)))) {
            return null;
        }

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
            if ($method === 'OPTIONS') {
                $allMatch = true;
                foreach ($requestedHeaderNames as $headerName) {
                    if (! self::headerMatches($headerName, $rule['allowedHeaders'])) {
                        $allMatch = false;
                        break;
                    }
                }
                if (! $allMatch) {
                    continue;
                }
            }

            // First matching rule wins.
            return self::buildCorsHeaders($origin, $rule, $method === 'OPTIONS' ? $requestedHeaderNames : null);
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
            if (self::singleWildcardMatches($origin, $allowed)) {
                return true;
            }
        }

        return false;
    }

    /** @param list<string> $allowedHeaders */
    private static function headerMatches(string $header, array $allowedHeaders): bool
    {
        foreach ($allowedHeaders as $allowed) {
            if (self::singleWildcardMatches($header, strtolower($allowed))) {
                return true;
            }
        }

        return false;
    }

    private static function singleWildcardMatches(string $value, string $pattern): bool
    {
        $star = strpos($pattern, '*');
        if ($star === false) {
            return $value === $pattern;
        }

        return strpos($pattern, '*', $star + 1) === false
            && strlen($value) >= strlen($pattern) - 1
            && str_starts_with($value, substr($pattern, 0, $star))
            && str_ends_with($value, substr($pattern, $star + 1));
    }

    /**
     * Build CORS response headers from a matched rule.
     *
     * @param  array{id?: string, allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null}  $rule
     * @param list<string>|null $requestedHeaders
     * @return array<non-empty-string, string>
     */
    private static function buildCorsHeaders(string $origin, array $rule, ?array $requestedHeaders): array
    {
        // Sanitize origin to prevent CRLF injection in response headers.
        $safeOrigin = str_replace(["\r", "\n"], '', $origin);

        $isWildcard = in_array('*', $rule['allowedOrigins'], true);

        $headers = [
            'Access-Control-Allow-Origin' => $isWildcard ? '*' : $safeOrigin,
            'Access-Control-Allow-Methods' => implode(', ', $rule['allowedMethods']),
            'Vary' => $requestedHeaders === null ? 'Origin' : 'Origin, Access-Control-Request-Headers, Access-Control-Request-Method',
        ];

        // Credentialed cross-origin requests require this header when origin is specific.
        if (!$isWildcard) {
            $headers['Access-Control-Allow-Credentials'] = 'true';
        }

        if ($requestedHeaders !== null && $requestedHeaders !== []) {
            $headers['Access-Control-Allow-Headers'] = implode(', ', $requestedHeaders);
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
