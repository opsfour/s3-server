<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\ObjectLock\ObjectLockConfigurationSnapshot;
use OpsFour\S3Server\Routing\S3Operation;

/** Marks object payloads that require an integrity checksum for Object Lock. */
final readonly class ObjectLockChecksumMiddleware implements Middleware
{
    public const string REQUIRED_ATTRIBUTE = 's3.objectLockChecksumRequired';

    public function __construct(private MetadataStore $metadata) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $operation = $request->hasAttribute('s3.operation')
            ? $request->getAttribute('s3.operation') : null;
        if (in_array($operation, [S3Operation::PutObject, S3Operation::PostObject], true)) {
            $this->markPutObject($request);
        } elseif ($operation === S3Operation::UploadPart) {
            $this->markUploadPart($request);
        }

        return $requestHandler->handleRequest($request);
    }

    private function markPutObject(Request $request): void
    {
        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;
        if (! is_string($bucket) || $bucket === '') {
            return;
        }
        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null || ! $this->matchesAuthorizedGeneration($request, $bucketInfo->generation)) {
            return;
        }

        $config = $this->metadata->getObjectLockConfig($bucket);
        ObjectLockConfigurationSnapshot::capture($request, $config);
        if ($this->hasExplicitObjectLockHeaders($request) || self::hasDefaultRetention($config)) {
            $request->setAttribute(self::REQUIRED_ATTRIBUTE, true);
        }
    }

    private function markUploadPart(Request $request): void
    {
        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;
        $key = $request->hasAttribute('s3.key') ? $request->getAttribute('s3.key') : null;
        $ownerId = $request->hasAttribute('ownerId') ? $request->getAttribute('ownerId') : null;
        if (! is_string($bucket) || $bucket === '' || ! is_string($key) || ! is_string($ownerId)) {
            return;
        }

        $query = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $query['uploadId'] ?? null;
        if (! is_string($uploadId) || $uploadId === '') {
            return;
        }
        $upload = $this->metadata->getMultipartUpload($uploadId);
        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($upload === null || $bucketInfo === null
            || $upload['bucket'] !== $bucket
            || $upload['key_name'] !== $key
            || $upload['owner_id'] !== $ownerId
            || $upload['bucket_generation'] !== $bucketInfo->generation
            || ! $this->matchesAuthorizedGeneration($request, $bucketInfo->generation)) {
            return;
        }

        $config = $this->metadata->getObjectLockConfig($bucket);
        $metadata = $upload['user_metadata'];
        $configurationFingerprint = $metadata[ObjectLockConfigurationSnapshot::MULTIPART_METADATA_KEY] ?? null;
        if (is_string($configurationFingerprint)) {
            ObjectLockConfigurationSnapshot::assertFingerprint($configurationFingerprint, $config);
        } elseif (isset($metadata['__object-lock-mode'])
            || isset($metadata['__object-lock-retain-until-date'])
            || isset($metadata['__object-lock-legal-hold'])
            || self::hasDefaultRetention($config)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException(
                'Object Lock multipart upload must be reinitiated with checksum protection.',
            );
        }
        ObjectLockConfigurationSnapshot::capture($request, $config);
        if (isset($metadata['__object-lock-mode'])
            || isset($metadata['__object-lock-retain-until-date'])
            || isset($metadata['__object-lock-legal-hold'])
            || self::hasDefaultRetention($config)) {
            $request->setAttribute(self::REQUIRED_ATTRIBUTE, true);
        }
    }

    private function matchesAuthorizedGeneration(Request $request, string $generation): bool
    {
        return ! $request->hasAttribute('s3.cachedBucketGeneration')
            || $request->getAttribute('s3.cachedBucketGeneration') === $generation;
    }

    private function hasExplicitObjectLockHeaders(Request $request): bool
    {
        return RequestHeaderValues::singleton($request, 'x-amz-object-lock-mode') !== null
            || RequestHeaderValues::singleton($request, 'x-amz-object-lock-retain-until-date') !== null
            || RequestHeaderValues::singleton($request, 'x-amz-object-lock-legal-hold') !== null;
    }

    /** @param array<string, mixed>|null $config */
    private static function hasDefaultRetention(?array $config): bool
    {
        return ($config['objectLockEnabled'] ?? null) === 'Enabled'
            && isset($config['rule']['defaultRetention']);
    }
}
