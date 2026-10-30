<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Auth\AuthType;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\BufferedSsePayload;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\KeyTooLongException;
use OpsFour\S3Server\Http\ObjectKeyValidator;
use OpsFour\S3Server\Http\WebsiteRedirectHost;
use OpsFour\S3Server\Metadata\BucketGenerationGuard;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Runtime\BufferedWorkLimiter;
use OpsFour\S3Server\Runtime\CancellationPreservingCleanup;
use OpsFour\S3Server\Policy\PolicyEvaluator;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Security\RequestNetworkContext;
use OpsFour\S3Server\Storage\CommittedObjectReader;
use OpsFour\S3Server\Storage\GenerationGuardedReadableStream;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use OpsFour\S3Server\Storage\StorageBackend;
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
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
    ) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        // Check if this is a website request.
        $bucket = $this->extractWebsiteBucket($request);
        if ($bucket === null) {
            return $requestHandler->handleRequest($request);
        }

        // Website hosts bypass normal API route parsing. Resolve the bucket
        // before CORS so both preflight and object responses use its rules.
        $request->setAttribute('s3.bucket', $bucket);
        $request->setAttribute('s3.website', true);

        if ($request->getMethod() === 'OPTIONS') {
            return (new CorsMiddleware($this->metadata))->handleRequest($request, $requestHandler);
        }

        // Only handle GET/HEAD for website hosting.
        if (!in_array($request->getMethod(), ['GET', 'HEAD'], true)) {
            return $requestHandler->handleRequest($request);
        }

        // Website authorization is always anonymous, even if an upstream
        // component supplied a request attribute with the reserved name.
        AuthType::clear($request);

        return (new CorsMiddleware($this->metadata))->handleRequest(
            $request,
            new class (fn(Request $innerRequest): Response => $this->serveWebsiteRequest($innerRequest, $bucket)) implements RequestHandler {
                /** @param \Closure(Request): Response $handler */
                public function __construct(private readonly \Closure $handler) {}

                public function handleRequest(Request $request): Response
                {
                    return ($this->handler)($request);
                }
            },
        );
    }

    private function serveWebsiteRequest(Request $request, string $bucket): Response
    {

        // Website paths contain only an object key; the bucket is in Host.
        // Set attributes here because website requests bypass API route parsing.
        $path = rawurldecode(ltrim($request->getUri()->getPath(), '/'));
        if ($path !== '') {
            ObjectKeyValidator::assertValid($path);
        }
        $request->setAttribute('s3.key', $path);
        $request->setAttribute('s3.operation', $request->getMethod() === 'HEAD' ? S3Operation::HeadObject : S3Operation::GetObject);
        $bucketInfo = $this->metadata->getBucket($bucket);
        $request->setAttribute('s3.cachedBucketOwner', $bucketInfo?->ownerId);
        $request->setAttribute('s3.cachedBucketGeneration', $bucketInfo?->generation);

        // Check website config.
        $websiteConfig = $this->metadata->getBucketWebsite($bucket);

        if ($websiteConfig === null || $bucketInfo === null) {
            return self::htmlError(404, 'NoSuchWebsiteConfiguration', 'The specified bucket does not have a website configuration.');
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        // Resolve the key.
        $key = $path;

        // Append index document for directory requests.
        if ($key === '' || str_ends_with($key, '/')) {
            $key .= $websiteConfig['indexDocument'];
        }
        ObjectKeyValidator::assertValid($key);
        $request->setAttribute('s3.key', $key);

        // Redirect-only buckets do not need an object at the requested key.
        if (isset($websiteConfig['redirectAllHost'])) {
            $protocol = $websiteConfig['redirectAllProtocol']
                ?? (RequestNetworkContext::secureTransport($request) ? 'https' : 'http');
            if (!in_array($protocol, ['http', 'https'], true)) {
                $protocol = 'http';
            }
            $host = self::validatedRedirectHost($websiteConfig['redirectAllHost']);
            if ($host === null) {
                return self::htmlError(500, 'InvalidWebsiteConfiguration', 'The website redirect host is invalid.');
            }
            $location = $protocol . '://' . $host . $request->getUri()->getPath();
            $query = $request->getUri()->getQuery();
            if ($query !== '') {
                $location .= '?' . $query;
            }

            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

            return new Response(
                status: 301,
                headers: ['Location' => $location],
            );
        }

        $routingRules = $websiteConfig['routingRules'] ?? null;
        if ($routingRules !== null) {
            foreach ($routingRules as $rule) {
                $condition = $rule['condition'] ?? [];
                if (isset($condition['httpErrorCodeReturnedEquals'])) {
                    continue;
                }
                if (! isset($condition['keyPrefixEquals']) || str_starts_with($key, $condition['keyPrefixEquals'])) {
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                    return self::websiteRuleRedirect($request, $key, $rule);
                }
            }
        }

        $objectInfo = $this->metadata->getObjectMetadata($bucket, $key);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        $publiclyReadable = $this->isPubliclyReadable($request, $bucket, $key, $objectInfo);
        // Policy, ACL and tag reads may yield after the object lookup. Do not
        // let a former bucket's authorization publish its stored bytes.
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        if (! $publiclyReadable) {
            if ($routingRules !== null) {
                foreach ($routingRules as $rule) {
                    $condition = $rule['condition'] ?? [];
                    if (($condition['httpErrorCodeReturnedEquals'] ?? null) === 403
                        && (! isset($condition['keyPrefixEquals']) || str_starts_with($key, $condition['keyPrefixEquals']))) {
                        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                        return self::websiteRuleRedirect($request, $key, $rule);
                    }
                }
            }

            $errorResponse = $this->websiteErrorDocumentResponse(
                $request,
                $bucketInfo,
                $bucket,
                $websiteConfig['errorDocument'] ?? null,
                403,
            );
            if ($errorResponse !== null) {
                return $errorResponse;
            }

            return self::htmlError(403, 'AccessDenied', 'The requested website object is not publicly readable.');
        }

        // Try to serve the object.
        if ($objectInfo !== null && !$objectInfo->isDeleteMarker) {
            // Serve the object.
            $storagePath = $objectInfo->systemMetadata['storagePath'] ?? null;
            if ($storagePath === null) {
                return self::htmlError(500, 'InternalError', 'Object storage path missing.');
            }

            $sseAlgo = $objectInfo->userMetadata['__sse-algorithm'] ?? null;
            if ($sseAlgo === EncryptionRequestResolver::SSE_C) {
                return self::htmlError(403, 'AccessDenied', 'SSE-C objects cannot be served via website hosting.');
            }

            try {
                [$storage, $storagePath, $physicalStorageTier] = $this->readLocation($objectInfo, $storagePath);
            } catch (\InvalidArgumentException) {
                return self::htmlError(500, 'InternalError', 'Object storage tier is not configured.');
            }

            if ($storage === null) {
                return self::htmlError(403, 'InvalidObjectState', 'The archived object must be restored before it can be served.');
            }

            $body = $this->websiteBody(
                $request,
                $bucketInfo,
                $storage,
                $storagePath,
                $physicalStorageTier,
                $objectInfo,
            );

            return new Response(
                status: 200,
                headers: self::websiteObjectHeaders($objectInfo),
                body: $body,
            );
        }

        // Object not found — check routing rules.
        if ($routingRules !== null) {
            foreach ($routingRules as $rule) {
                $condition = $rule['condition'] ?? [];

                // Prefix-only rules already redirected before object lookup.
                if (! isset($condition['httpErrorCodeReturnedEquals'])) {
                    continue;
                }

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
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                    return self::websiteRuleRedirect($request, $key, $rule);
                }
            }
        }

        $errorResponse = $this->websiteErrorDocumentResponse(
            $request,
            $bucketInfo,
            $bucket,
            $websiteConfig['errorDocument'] ?? null,
            404,
        );
        if ($errorResponse !== null) {
            return $errorResponse;
        }

        return self::htmlError(404, 'NoSuchKey', 'The specified key does not exist.');
    }

    private function websiteErrorDocumentResponse(
        Request $request,
        BucketInfo $bucketInfo,
        string $bucket,
        ?string $errorDocument,
        int $status,
    ): ?Response {
        if ($errorDocument === null) {
            return null;
        }

        $errorObjectInfo = $this->metadata->getObjectMetadata($bucket, $errorDocument);
        BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        $errorPubliclyReadable = $this->isPubliclyReadable($request, $bucket, $errorDocument, $errorObjectInfo);
        BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        if (! $errorPubliclyReadable) {
            return self::htmlError(403, 'AccessDenied', 'The website error document is not publicly readable.');
        }

        if ($errorObjectInfo === null || $errorObjectInfo->isDeleteMarker) {
            return null;
        }

        $sseAlgo = $errorObjectInfo->userMetadata['__sse-algorithm'] ?? null;
        if ($sseAlgo === EncryptionRequestResolver::SSE_C) {
            return self::htmlError(403, 'AccessDenied', 'SSE-C error documents cannot be served via website hosting.');
        }

        $storagePath = $errorObjectInfo->systemMetadata['storagePath'] ?? null;
        if ($storagePath === null) {
            return null;
        }

        try {
            [$storage, $storagePath, $physicalStorageTier] = $this->readLocation($errorObjectInfo, $storagePath);
        } catch (\InvalidArgumentException) {
            return self::htmlError(500, 'InternalError', 'Error document storage tier is not configured.');
        }

        if ($storage === null) {
            return self::htmlError(403, 'InvalidObjectState', 'The archived error document must be restored before it can be served.');
        }

        $body = $this->websiteBody(
            $request,
            $bucketInfo,
            $storage,
            $storagePath,
            $physicalStorageTier,
            $errorObjectInfo,
        );

        return new Response(
            status: $status,
            headers: self::websiteObjectHeaders($errorObjectInfo),
            body: $body,
        );
    }

    private function websiteBody(
        Request $request,
        BucketInfo $bucketInfo,
        StorageBackend $storage,
        string $path,
        string $physicalStorageTier,
        ObjectInfo $object,
    ): string|GenerationGuardedReadableStream {
        if ($request->getMethod() === 'HEAD') {
            return '';
        }

        if (($object->userMetadata['__sse-algorithm'] ?? null) === EncryptionRequestResolver::SSE_S3) {
            return $this->decryptWebsiteBody(
                $request,
                $bucketInfo,
                $storage,
                $path,
                $physicalStorageTier,
                $object,
            );
        }

        return $this->openWebsiteBody(
            $request,
            $bucketInfo,
            $storage,
            $path,
            $physicalStorageTier,
            $object,
        );
    }

    private function decryptWebsiteBody(
        Request $request,
        BucketInfo $bucketInfo,
        StorageBackend $storage,
        string $path,
        string $physicalStorageTier,
        ObjectInfo $object,
    ): string {
        EncryptionRequestResolver::requireEncryptionService(
            $this->encryption,
            EncryptionRequestResolver::SSE_S3,
        );
        \assert($this->encryption !== null);
        if ($object->size > $this->maxEncryptedObjectSize) {
            throw new EntityTooLargeException(
                'Encrypted object exceeds maximum size for decryption (' . $this->maxEncryptedObjectSize . ' bytes).',
            );
        }

        $bufferedWorkLock = BufferedWorkLimiter::acquire();
        $decryptionCancellation = null;
        try {
            [$ciphertext] = BufferedSsePayload::readExpected(
                CommittedObjectReader::read(
                    $storage,
                    $bucketInfo,
                    $object,
                    $path,
                    $physicalStorageTier,
                ),
                $object->size,
                $this->maxEncryptedObjectSize,
            );

            $plaintext = $this->encryption->decryptSseS3(
                $ciphertext,
                $object->userMetadata['__sse-key'],
                $object->userMetadata['__sse-iv'],
                $object->userMetadata['__sse-tag'],
            );
            if (strlen($plaintext) !== $object->size) {
                throw new InternalErrorException('Decrypted object size does not match metadata.');
            }
            BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

            return $plaintext;
        } catch (\Amp\CancelledException $error) {
            $decryptionCancellation = $error;
            throw $error;
        } finally {
            CancellationPreservingCleanup::inFinally(
                $decryptionCancellation,
                $bufferedWorkLock->release(...),
            );
        }
    }

    private function openWebsiteBody(
        Request $request,
        BucketInfo $bucketInfo,
        StorageBackend $storage,
        string $path,
        string $physicalStorageTier,
        ObjectInfo $object,
    ): GenerationGuardedReadableStream {
        $source = CommittedObjectReader::read(
            $storage,
            $bucketInfo,
            $object,
            $path,
            $physicalStorageTier,
        );
        try {
            // Opening a remote source can yield. Close it if this bucket name
            // changed while the backend was opening the old physical path.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            return new GenerationGuardedReadableStream(
                new LimitedReadableStream(
                    $source,
                    $object->size,
                    requireExactLength: true,
                    rejectExcess: true,
                ),
                function () use ($bucketInfo, $request): void {
                    BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
                },
            );
        } catch (\Throwable $error) {
            try {
                $source->close();
            } catch (\Throwable) {
            }
            throw $error;
        }
    }

    /** @param array<string, mixed> $rule */
    private static function websiteRuleRedirect(Request $request, string $key, array $rule): Response
    {
        $condition = $rule['condition'] ?? [];
        $redirect = $rule['redirect'] ?? [];
        $redirectKey = $key;
        if (isset($redirect['replaceKeyPrefixWith'])) {
            $redirectKey = $redirect['replaceKeyPrefixWith'] . substr($key, strlen($condition['keyPrefixEquals'] ?? ''));
        }
        if (isset($redirect['replaceKeyWith'])) {
            $redirectKey = $redirect['replaceKeyWith'];
        }

        try {
            ObjectKeyValidator::assertValid($redirectKey);
        } catch (InvalidArgumentException|KeyTooLongException) {
            return self::htmlError(500, 'InvalidWebsiteConfiguration', 'The website redirect key is invalid.');
        }

        $protocol = $redirect['protocol']
            ?? (RequestNetworkContext::secureTransport($request) ? 'https' : 'http');
        if (! in_array($protocol, ['http', 'https'], true)) {
            $protocol = 'http';
        }
        $host = self::validatedRedirectHost($redirect['hostName'] ?? $request->getHeader('host') ?? 'localhost');
        if ($host === null) {
            return self::htmlError(500, 'InvalidWebsiteConfiguration', 'The website redirect host is invalid.');
        }
        $statusCode = (int) ($redirect['httpRedirectCode'] ?? 301);
        if (! in_array($statusCode, [301, 302, 303, 307, 308], true)) {
            $statusCode = 301;
        }
        $redirectPath = implode('/', array_map('rawurlencode', explode('/', ltrim($redirectKey, '/'))));

        return new Response(
            status: $statusCode,
            headers: ['Location' => $protocol . '://' . $host . '/' . $redirectPath],
        );
    }

    private function isPubliclyReadable(Request $request, string $bucket, string $key, ?ObjectInfo $object): bool
    {
        $publicAccessBlock = $this->metadata->getPublicAccessBlock($bucket);
        $policy = $this->metadata->getBucketPolicy($bucket);

        if ($policy !== null) {
            $policyResult = PolicyEvaluator::evaluate(
                policyJson: $policy,
                action: 's3:GetObject',
                resource: "arn:aws:s3:::{$bucket}/{$key}",
                principal: '',
                conditions: $this->policyConditions($request, $object),
            );

            if ($policyResult === 'Deny') {
                return false;
            }

            if ($policyResult === 'Allow') {
                return ! ($publicAccessBlock['restrictPublicBuckets'] ?? false);
            }
        }

        // Object ACLs are disabled in BucketOwnerEnforced mode, including
        // grants left behind by an earlier ObjectWriter configuration.
        if (BucketOwnership::isEnforced($this->metadata, $bucket)) {
            return false;
        }

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
    private function policyConditions(Request $request, ?ObjectInfo $object): array
    {
        $conditions = [
            'aws:SecureTransport' => RequestNetworkContext::secureTransport($request) ? 'true' : 'false',
            'aws:CurrentTime' => gmdate(\DateTimeInterface::ATOM),
        ];

        $sourceIp = RequestNetworkContext::sourceIp($request);
        if ($sourceIp !== null) {
            $conditions['aws:SourceIp'] = $sourceIp;
        }

        $userAgent = $request->getHeader('user-agent');
        if ($userAgent !== null && $userAgent !== '') {
            $conditions['aws:UserAgent'] = $userAgent;
        }

        if ($object !== null) {
            foreach ($this->metadata->getObjectTagging($object->bucket, $object->key, $object->versionId) as $tag) {
                $conditions['s3:ExistingObjectTag/' . $tag['key']] = $tag['value'];
            }
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

        // DNS host names are case-insensitive, including bucket labels.
        $host = strtolower($host);
        $pattern = strtolower($this->websiteHostPattern);

        // Match against pattern (e.g., *.s3-website.example.com).
        if (!fnmatch($pattern, $host)) {
            return null;
        }

        // Extract bucket name from subdomain.
        $patternBase = ltrim($pattern, '*.');
        if (str_ends_with($host, '.' . $patternBase)) {
            return substr($host, 0, -(strlen($patternBase) + 1));
        }

        return null;
    }

    /**
     * @return array{0: \OpsFour\S3Server\Storage\StorageBackend|null, 1: string, 2: string}
     */
    private function readLocation(\OpsFour\S3Server\Dto\ObjectInfo $object, string $storagePath): array
    {
        $tier = $this->storageTiers->tier($object->storageTier);
        if (! $tier->restoreRequired) {
            return [$tier->backend, $storagePath, $tier->name];
        }

        if (
            $object->restoreStatus !== 'restored'
            || $object->restoredStoragePath === null
            || $object->restoredStoragePath === ''
            || $object->restoreExpiresAt === null
            || $object->restoreExpiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
        ) {
            return [null, $storagePath, $tier->name];
        }

        $restoreTier = \OpsFour\S3Server\Storage\RestoredCopyTierResolver::resolve($object, $this->storageTiers);

        return [$restoreTier->backend, $object->restoredStoragePath, $restoreTier->name];
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

    /** @return array<non-empty-string, string> */
    private static function websiteObjectHeaders(ObjectInfo $object): array
    {
        $headers = [
            'Content-Type' => $object->contentType,
            'Content-Length' => (string) $object->size,
            'ETag' => $object->etag,
            'Last-Modified' => $object->lastModified->format('D, d M Y H:i:s \G\M\T'),
        ];
        foreach ([
            'content-encoding' => 'Content-Encoding',
            'content-disposition' => 'Content-Disposition',
            'cache-control' => 'Cache-Control',
        ] as $metadataName => $headerName) {
            $value = $object->systemMetadata[$metadataName] ?? null;
            if ($value !== null && $value !== '') {
                $headers[$headerName] = $value;
            }
        }
        $expires = $object->userMetadata['__expires'] ?? null;
        if ($expires !== null && $expires !== '') {
            $headers['Expires'] = $expires;
        }
        $contentLanguage = $object->userMetadata['__content-language'] ?? null;
        if ($contentLanguage !== null && $contentLanguage !== '') {
            $headers['Content-Language'] = $contentLanguage;
        }

        return $headers;
    }

    private static function validatedRedirectHost(mixed $host): ?string
    {
        if (! is_string($host) || ! WebsiteRedirectHost::isValid($host)) {
            return null;
        }

        return $host;
    }
}
