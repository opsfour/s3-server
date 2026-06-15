<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;

/**
 * Website hosting middleware.
 *
 * Activates when the Host header matches a configurable pattern.
 * Serves index documents, error documents, and routing rules.
 * Runs before Auth — website content is public.
 */
final class WebsiteHostingMiddleware implements Middleware
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?string $websiteHostPattern = null,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        // Check if this is a website request.
        $bucket = $this->extractWebsiteBucket($request);
        if ($bucket === null) {
            return $requestHandler->handleRequest($request);
        }

        // Only handle GET/HEAD for website hosting.
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $requestHandler->handleRequest($request);
        }

        // Check website config.
        try {
            $websiteConfig = $this->metadata->getBucketWebsite($bucket);
        } catch (\Throwable) {
            $websiteConfig = null;
        }

        if ($websiteConfig === null) {
            return self::htmlError(404, 'NoSuchWebsiteConfiguration', 'The specified bucket does not have a website configuration.');
        }

        // Handle redirectAllHost.
        if (isset($websiteConfig['redirectAllHost']) && $websiteConfig['redirectAllHost'] !== null) {
            $protocol = $websiteConfig['redirectAllProtocol'] ?? 'http';
            // Sanitize protocol to prevent arbitrary scheme injection (e.g., javascript:).
            if (!in_array($protocol, ['http', 'https'], true)) {
                $protocol = 'http';
            }
            // Sanitize host to prevent CRLF header injection.
            $host = preg_replace('/[^a-zA-Z0-9\-\.:]/', '', $websiteConfig['redirectAllHost']);
            $location = $protocol . '://' . $host . $request->getUri()->getPath();

            return new Response(
                status: 301,
                headers: ['Location' => $location],
            );
        }

        // Resolve the key.
        $path = ltrim($request->getUri()->getPath(), '/');
        // Remove bucket prefix if path-style.
        if (str_starts_with($path, $bucket . '/')) {
            $path = substr($path, strlen($bucket) + 1);
        }

        $key = $path;

        // Append index document for directory requests.
        if ($key === '' || str_ends_with($key, '/')) {
            $key .= $websiteConfig['indexDocument'];
        }

        // Try to serve the object.
        $objectInfo = null;
        try {
            $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);
        } catch (\Throwable) {}

        if ($objectInfo !== null && !$objectInfo->isDeleteMarker) {
            // Serve the object.
            $storagePath = $objectInfo->systemMetadata['storagePath'] ?? null;
            if ($storagePath === null) {
                return self::htmlError(500, 'InternalError', 'Object storage path missing.');
            }

            // Encrypted objects cannot be served via website hosting.
            // SSE-C requires a customer key (not available), SSE-S3 stores ciphertext on disk.
            $sseAlgo = $objectInfo->userMetadata['__sse-algorithm'] ?? null;
            if ($sseAlgo === 'SSE-C' || $sseAlgo === 'AES256') {
                return self::htmlError(403, 'AccessDenied', 'Encrypted objects cannot be served via website hosting.');
            }

            $body = $request->getMethod() === 'HEAD' ? '' : $this->storage->getObjectByPath($storagePath);

            return new Response(
                status: 200,
                headers: [
                    'Content-Type' => $objectInfo->contentType,
                    'Content-Length' => (string) $objectInfo->size,
                    'ETag' => $objectInfo->etag,
                    'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \G\M\T'),
                ],
                body: $body,
            );
        }

        // Object not found — check routing rules.
        $routingRules = $websiteConfig['routingRules'] ?? null;
        if ($routingRules !== null) {
            foreach ($routingRules as $rule) {
                $condition = $rule['condition'] ?? [];
                $redirect = $rule['redirect'] ?? [];

                // Match condition.
                $matchPrefix = true;
                if (isset($condition['keyPrefixEquals'])) {
                    $matchPrefix = str_starts_with($key, $condition['keyPrefixEquals']);
                }

                $matchError = true;
                if (isset($condition['httpErrorCodeReturnedEquals'])) {
                    $matchError = (int) $condition['httpErrorCodeReturnedEquals'] === 404;
                }

                if ($matchPrefix && $matchError) {
                    // Apply redirect.
                    $redirectKey = $key;
                    if (isset($redirect['replaceKeyPrefixWith']) && isset($condition['keyPrefixEquals'])) {
                        $redirectKey = $redirect['replaceKeyPrefixWith'] . substr($key, strlen($condition['keyPrefixEquals']));
                    }
                    if (isset($redirect['replaceKeyWith'])) {
                        $redirectKey = $redirect['replaceKeyWith'];
                    }

                    $protocol = $redirect['protocol'] ?? ($request->getUri()->getScheme() ?: 'http');
                    if (!in_array($protocol, ['http', 'https'], true)) {
                        $protocol = 'http';
                    }
                    $host = $redirect['hostName'] ?? $request->getHeader('host') ?? 'localhost';
                    // Sanitize host to prevent header injection.
                    $host = preg_replace('/[^a-zA-Z0-9\-\.:]/', '', $host);
                    $statusCode = (int) ($redirect['httpRedirectCode'] ?? 301);
                    if (!in_array($statusCode, [301, 302, 303, 307, 308], true)) {
                        $statusCode = 301;
                    }
                    // Sanitize redirect key to prevent CRLF header injection.
                    $redirectKey = str_replace(["\r", "\n"], '', $redirectKey);
                    $location = $protocol . '://' . $host . '/' . ltrim($redirectKey, '/');

                    return new Response(
                        status: (int) $statusCode,
                        headers: ['Location' => $location],
                    );
                }
            }
        }

        // Try error document.
        if (isset($websiteConfig['errorDocument']) && $websiteConfig['errorDocument'] !== null) {
            $errorObjectInfo = null;
            try {
                $errorObjectInfo = $this->metadata->getObjectMetadata($bucket, $websiteConfig['errorDocument']);
            } catch (\Throwable) {}

            if ($errorObjectInfo !== null && !$errorObjectInfo->isDeleteMarker) {
                $storagePath = $errorObjectInfo->systemMetadata['storagePath'] ?? null;
                if ($storagePath !== null) {
                    $body = $request->getMethod() === 'HEAD' ? '' : $this->storage->getObjectByPath($storagePath);

                    return new Response(
                        status: 404,
                        headers: [
                            'Content-Type' => $errorObjectInfo->contentType,
                            'Content-Length' => (string) $errorObjectInfo->size,
                        ],
                        body: $body,
                    );
                }
            }
        }

        return self::htmlError(404, 'NoSuchKey', 'The specified key does not exist.');
    }

    /**
     * Extract bucket name from Host header if it matches the website pattern.
     */
    private function extractWebsiteBucket(Request $request): ?string
    {
        if ($this->websiteHostPattern === null) {
            return null;
        }

        $host = $request->getHeader('host');
        if ($host === null) {
            return null;
        }

        // Strip port.
        $colonPos = strpos($host, ':');
        if ($colonPos !== false) {
            $host = substr($host, 0, $colonPos);
        }

        // Match against pattern (e.g., *.s3-website.example.com).
        if (!fnmatch($this->websiteHostPattern, $host)) {
            return null;
        }

        // Extract bucket name from subdomain.
        $patternBase = ltrim($this->websiteHostPattern, '*.');
        if (str_ends_with($host, '.' . $patternBase)) {
            return substr($host, 0, -(strlen($patternBase) + 1));
        }

        return null;
    }

    private static function htmlError(int $status, string $code, string $message): Response
    {
        $safeCode = htmlspecialchars($code, ENT_QUOTES, 'UTF-8');
        $safeMessage = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');
        $html = <<<HTML
        <html><head><title>{$status} {$safeCode}</title></head>
        <body><h1>{$status} {$safeCode}</h1><p>{$safeMessage}</p></body></html>
        HTML;

        return new Response(
            status: $status,
            headers: ['Content-Type' => 'text/html; charset=utf-8'],
            body: $html,
        );
    }
}
