<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Website hosting middleware.
 *
 * Activates when the Host header matches a configurable pattern.
 * Serves index documents, error documents, and routing rules.
 * Runs before Auth. Website requests are anonymous and therefore require an
 * explicit public object ACL or bucket-policy allow.
 */
final class WebsiteHostingMiddleware implements Middleware
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $storageTiers,
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
        $websiteConfig = $this->metadata->getBucketWebsite($bucket);

        if ($websiteConfig === null) {
            return self::htmlError(404, 'NoSuchWebsiteConfiguration', 'The specified bucket does not have a website configuration.');
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

        if (! $this->isPubliclyReadable($request, $bucket, $key)) {
            return self::htmlError(403, 'AccessDenied', 'The requested website object is not publicly readable.');
        }

        // Handle redirectAllHost only after public access to the requested key
        // has been established.
        if (isset($websiteConfig['redirectAllHost'])) {
            $protocol = $websiteConfig['redirectAllProtocol'] ?? 'http';
            if (!in_array($protocol, ['http', 'https'], true)) {
                $protocol = 'http';
            }
            $host = self::validatedRedirectHost($websiteConfig['redirectAllHost']);
            if ($host === null) {
                return self::htmlError(500, 'InvalidWebsiteConfiguration', 'The website redirect host is invalid.');
            }
            $location = $protocol . '://' . $host . $request->getUri()->getPath();

            return new Response(
                status: 301,
                headers: ['Location' => $location],
            );
        }

        // Try to serve the object.
        $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);

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

            try {
                [$storage, $storagePath] = $this->readLocation($objectInfo, $storagePath);
            } catch (\InvalidArgumentException) {
                return self::htmlError(500, 'InternalError', 'Object storage tier is not configured.');
            }

            if ($storage === null) {
                return self::htmlError(403, 'InvalidObjectState', 'The archived object must be restored before it can be served.');
            }

            $body = $request->getMethod() === 'HEAD' ? '' : $storage->getObjectByPath($storagePath);

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
                    $host = self::validatedRedirectHost($redirect['hostName'] ?? $request->getHeader('host') ?? 'localhost');
                    if ($host === null) {
                        return self::htmlError(500, 'InvalidWebsiteConfiguration', 'The website redirect host is invalid.');
                    }
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
        if (isset($websiteConfig['errorDocument'])) {
            $errorDocument = $websiteConfig['errorDocument'];
            if (! $this->isPubliclyReadable($request, $bucket, $errorDocument)) {
                return self::htmlError(403, 'AccessDenied', 'The website error document is not publicly readable.');
            }

            $errorObjectInfo = $this->metadata->getObjectMetadata($bucket, $errorDocument);

            if ($errorObjectInfo !== null && !$errorObjectInfo->isDeleteMarker) {
                $sseAlgo = $errorObjectInfo->userMetadata['__sse-algorithm'] ?? null;
                if ($sseAlgo === 'SSE-C' || $sseAlgo === 'AES256') {
                    return self::htmlError(403, 'AccessDenied', 'Encrypted error documents cannot be served via website hosting.');
                }

                $storagePath = $errorObjectInfo->systemMetadata['storagePath'] ?? null;
                if ($storagePath !== null) {
                    try {
                        [$storage, $storagePath] = $this->readLocation($errorObjectInfo, $storagePath);
                    } catch (\InvalidArgumentException) {
                        return self::htmlError(500, 'InternalError', 'Error document storage tier is not configured.');
                    }

                    if ($storage === null) {
                        return self::htmlError(403, 'InvalidObjectState', 'The archived error document must be restored before it can be served.');
                    }

                    $body = $request->getMethod() === 'HEAD' ? '' : $storage->getObjectByPath($storagePath);

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

    private function isPubliclyReadable(Request $request, string $bucket, string $key): bool
    {
        $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
        $policy = $this->metadata->getBucketPolicy($bucket);

        if ($policy !== null) {
            $policyResult = PolicyEvaluator::evaluate(
                policyJson: $policy,
                action: 's3:GetObject',
                resource: "arn:aws:s3:::{$bucket}/{$key}",
                principal: 'anonymous',
                conditions: $this->policyConditions($request, $bucket, $key),
            );

            if ($policyResult === 'Deny') {
                return false;
            }

            if ($policyResult === 'Allow') {
                return ! ($publicAccessBlock['restrictPublicBuckets'] ?? false);
            }
        }

        $object = $this->metadata->getObjectMetadata($bucket, $key);
        $grants = $this->metadata->getAcl(
            'object',
            \OpsFour\S3Server\Http\ObjectVersionResolver::aclResourceName(
                $bucket,
                $key,
                $object?->versionId,
            ),
        );
        if ($grants === []) {
            $grants = $this->metadata->getAcl('object', "{$bucket}/{$key}");
        }

        return AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: '',
            ownerId: $this->metadata->getBucketOwner($bucket) ?? '',
            grants: $grants,
            isAuthenticated: false,
            ignorePublicAcls: $publicAccessBlock['ignorePublicAcls'] ?? false,
        );
    }

    /**
     * @return array<string, string>
     */
    private function policyConditions(Request $request, string $bucket, string $key): array
    {
        $conditions = [
            'aws:SecureTransport' => $request->getClient()->getTlsInfo() !== null ? 'true' : 'false',
            'aws:CurrentTime' => gmdate(\DateTimeInterface::ATOM),
        ];

        $remoteAddress = $request->getClient()->getRemoteAddress();
        if ($remoteAddress instanceof \Amp\Socket\InternetAddress) {
            $conditions['aws:SourceIp'] = $remoteAddress->getAddress();
        }

        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }

        foreach ($this->metadata->getObjectTagging($bucket, $key) as $tag) {
            $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
        }

        return $conditions;
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

    /**
     * @return array{0: \OpsFour\S3Server\Storage\StorageBackend|null, 1: string}
     */
    private function readLocation(\OpsFour\S3Server\Dto\ObjectInfo $object, string $storagePath): array
    {
        $tier = $this->storageTiers->tier($object->storageTier);
        if (! $tier->restoreRequired) {
            return [$tier->backend, $storagePath];
        }

        if (
            $object->restoreStatus !== 'restored'
            || $object->restoredStoragePath === null
            || $object->restoredStoragePath === ''
            || $object->restoreExpiresAt === null
            || $object->restoreExpiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        ) {
            return [null, $storagePath];
        }

        return [$this->storageTiers->defaultBackend(), $object->restoredStoragePath];
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

    private static function validatedRedirectHost(mixed $host): ?string
    {
        if (! is_string($host) || preg_match('/^[a-zA-Z0-9.-]+(?::[0-9]{1,5})?$/D', $host) !== 1) {
            return null;
        }

        return $host;
    }
}
