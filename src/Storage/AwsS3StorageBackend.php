<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Aws\Exception\AwsException;
use Aws\ResultInterface;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\AwsS3GarbageClaimStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\StreamInterface;

/** Provider identity plus narrowly scoped ordinary-object fence capabilities. */
final class AwsS3StorageBackend implements StorageBackend, AwsS3ProviderAdmissionCapability
{
    private object $garbageDeletionAuthority;

    private ?AwsS3OperationalState $operationalState = null;

    private ?string $operationalTier = null;

    private bool $operationalAttachmentCommitted = false;

    public function __construct(
        private AwsS3ProviderAdmission $admission,
        private ?AwsS3ProviderValidatedBinding $binding = null,
    ) {
        $this->garbageDeletionAuthority = new \stdClass();
        if ($binding !== null && ($binding->admission !== $admission
            || ! $admission->providerAdmitted
            || $binding->profile === null || $binding->client === null
            || $admission->configuredRegion !== $binding->profile->region
            || $binding->client->getRegion() !== $binding->profile->region)) {
            throw new \InvalidArgumentException('AWS foreground binding requires an admitted exact profile and client.');
        }
    }

    public function awsS3ProviderAdmission(): AwsS3ProviderAdmission
    {
        return $this->admission;
    }

    public function admittedProfile(): ?AwsS3ProviderProfile
    {
        return $this->binding?->profile;
    }

    public function isBoundToProfile(AwsS3ProviderProfile $profile): bool
    {
        return $this->binding?->profile === $profile;
    }

    public function validatedBinding(): ?AwsS3ProviderValidatedBinding
    {
        return $this->binding;
    }

    public function attachOperationalState(AwsS3OperationalState $state, string $storageTier, object $metadata): void
    {
        if ($this->operationalState !== null) {
            throw new \LogicException('AWS operational state is already attached.');
        }
        $binding = $this->binding ?? throw $this->unavailable();
        $state->assertExecutionBinding($binding, $storageTier, $metadata);
        $this->operationalState = $state;
        $this->operationalTier = $storageTier;
        $this->operationalAttachmentCommitted = false;
    }

    public function commitOperationalStateAttachment(
        AwsS3OperationalState $state,
        string $storageTier,
        object $metadata,
    ): void {
        $this->assertExactOperationalAttachment($state, $storageTier, $metadata);
        $binding = $this->binding ?? throw $this->unavailable();
        $state->commitRuntimeAttachment($binding, $storageTier, $metadata);
        $this->operationalAttachmentCommitted = true;
        $this->admission = $this->admission->withQualification(true);
    }

    public function rollbackOperationalStateAttachment(
        AwsS3OperationalState $state,
        string $storageTier,
        object $metadata,
    ): void {
        $this->assertExactOperationalAttachment($state, $storageTier, $metadata);
        if ($this->operationalAttachmentCommitted) {
            throw new \LogicException('A committed AWS operational state attachment cannot be rolled back.');
        }
        $binding = $this->binding ?? throw $this->unavailable();
        $state->rollbackRuntimeAttachment($binding, $storageTier, $metadata);
        $this->operationalState = null;
        $this->operationalTier = null;
        $this->operationalAttachmentCommitted = false;
        $this->admission = $this->admission->withQualification(false);
    }

    public function operationalState(): ?AwsS3OperationalState
    {
        return $this->operationalState;
    }

    public function operationalAttachmentCommitted(): bool
    {
        return $this->operationalAttachmentCommitted;
    }

    /**
     * Narrow ordinary-write capability. Generic data-path operations remain
     * closed; logical bucket namespace creation is a guarded no-op.
     */
    public function createForegroundObjectWriter(
        ObjectWriteIntentStore $intents,
        string $scratch,
        string $storageTier,
        int $budgetBytes = 10_737_418_240,
        float $leaseSeconds = 300.0,
        float $renewIntervalSeconds = 60.0,
        ?MetricsCollector $metrics = null,
    ): AwsS3ForegroundObjectWriter {
        $profile = $this->binding?->profile;
        $client = $this->binding?->client;
        if ($profile === null || $client === null || ! $this->admission->providerAdmitted) {
            throw $this->unavailable();
        }
        $this->assertOperationalBinding($storageTier, $intents);
        $publisher = new AwsS3WriteAttemptPublisher(
            $profile,
            $intents,
            new AwsS3CreateOnlyWriter($client, $profile, $intents, leaseSeconds: $leaseSeconds),
            $leaseSeconds,
        );
        return new AwsS3ForegroundObjectWriter(
            $this,
            $profile,
            $intents,
            $publisher,
            new LocalScratchStager($scratch, $budgetBytes),
            $storageTier,
            $renewIntervalSeconds,
            $this->operationalState,
            $metrics,
        );
    }

    public function createObjectWriteIntentCollector(
        ObjectWriteIntentStore $intents,
        string $storageTier,
        LoggerInterface $logger,
        MetricsCollector $metrics,
        float $claimLeaseSeconds = 300.0,
    ): AwsS3ObjectWriteIntentCollector {
        $profile = $this->binding?->profile;
        $client = $this->binding?->client;
        if ($profile === null || $client === null || ! $this->admission->providerAdmitted) {
            throw $this->unavailable();
        }
        $this->assertOperationalBinding($storageTier, $intents);
        return new AwsS3ObjectWriteIntentCollector(
            $intents,
            $storageTier,
            new AwsS3SameKeySealWriter($client, $profile->bucket, $profile->expectedOwnerId),
            $logger,
            $metrics,
            $claimLeaseSeconds,
            $this->operationalState,
        );
    }

    /** Exact, owner-bound read used only by CopyObject source transformation. */
    public function readCopySource(
        BucketInfo $bucket,
        ObjectInfo $source,
        string $key,
        string $path,
        ?int $offset = null,
        ?int $length = null,
    ): ReadableStream {
        $storageTier = $this->exactObjectReferenceTier($source, $path);

        return $this->readExactCommittedObject(
            $bucket,
            $source,
            $key,
            $path,
            $storageTier,
            $offset,
            $length,
        );
    }

    /** Exact owner-bound read of one committed current-v2 object attempt. */
    public function readCommittedObject(
        BucketInfo $bucket,
        ObjectInfo $object,
        string $path,
        string $storageTier,
        ?int $offset = null,
        ?int $length = null,
    ): ReadableStream {
        if ($object->bucket !== $bucket->name || $object->key === '' || $object->ownerId === ''
            || $storageTier === '' || $object->size < 0
            || $this->exactObjectReferenceTier($object, $path) !== $storageTier) {
            throw new InternalErrorException('AWS committed source scope is invalid.');
        }

        return $this->readExactCommittedObject(
            $bucket,
            $object,
            $object->key,
            $path,
            $storageTier,
            $offset,
            $length,
        );
    }

    private function readExactCommittedObject(
        BucketInfo $bucket,
        ObjectInfo $source,
        string $key,
        string $path,
        string $storageTier,
        ?int $offset,
        ?int $length,
    ): ReadableStream {
        $state = $this->operationalState;
        if ($state === null || ! $this->operationalAttachmentCommitted) {
            throw $this->unavailable();
        }
        $state->assertRecoveryReady();
        $profile = $this->binding?->profile;
        $client = $this->binding?->client;
        if ($profile === null || $client === null || ! $this->admission->providerAdmitted) {
            throw $this->unavailable();
        }
        $body = null;
        try {
            $lastSeparator = strrpos($path, '/');
            $attemptId = substr($path, $lastSeparator === false ? 0 : $lastSeparator + 1);
            AwsS3WriteAttemptPath::assertExactPath(
                $path,
                $bucket->name,
                $key,
                $bucket->generation,
                $storageTier,
                $attemptId,
                $profile->reservedPrefix,
            );
            $request = [
                'Bucket' => $profile->bucket,
                'Key' => $path,
                'ExpectedBucketOwner' => $profile->expectedOwnerId,
                '@retries' => 0,
                '@http' => ['stream' => true],
            ];
            $expectedLength = $source->size;
            $expectedContentRange = null;
            $expectedStatusCode = 200;
            if ($offset !== null || $length !== null) {
                if ($offset === null || $length === null || $offset < 0 || $length < 1
                    || $offset > PHP_INT_MAX - $length || $offset >= $source->size
                    || $offset + $length > $source->size) {
                    throw new \InvalidArgumentException('Invalid AWS committed-object range.');
                }
                $request['Range'] = 'bytes=' . $offset . '-' . ($offset + $length - 1);
                $expectedLength = $length;
                $expectedContentRange = 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $source->size;
                $expectedStatusCode = 206;
            }
            $result = $client->getObject($request);
            $body = $result->get('Body');
            $responseLength = $result->get('ContentLength');
            $contentRange = $result->get('ContentRange');
            $markers = $result->get('Metadata');
            $responseMetadata = $result->get('@metadata');
            $statusCode = is_array($responseMetadata) ? ($responseMetadata['statusCode'] ?? null) : null;
            if (! $body instanceof StreamInterface || ! is_int($responseLength) || $responseLength !== $expectedLength
                || $statusCode !== $expectedStatusCode
                || ($expectedContentRange === null ? $contentRange !== null : $contentRange !== $expectedContentRange)
                || ! is_array($markers)
                || ($markers['opsfour-write-attempt'] ?? null) !== 'v2'
                || ($markers['opsfour-attempt-id'] ?? null) !== $attemptId
                || ($markers['opsfour-owner-digest'] ?? null) !== hash('sha256', $source->ownerId)
                || ($markers['opsfour-content-size'] ?? null) !== (string) $source->size) {
                throw new \RuntimeException('Unverified AWS committed-object response.');
            }
            return new LimitedReadableStream(
                new AwsS3CopySourceStream($body),
                $expectedLength,
                requireExactLength: true,
                rejectExcess: true,
            );
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            try {
                $body?->close();
            } catch (\Throwable) {
            }
            throw new InternalErrorException('AWS committed object could not be verified.');
        }
    }

    private function exactObjectReferenceTier(ObjectInfo $object, string $path): string
    {
        $primaryPath = $object->systemMetadata['storagePath'] ?? null;
        if ($primaryPath !== null && $primaryPath !== '' && hash_equals($primaryPath, $path)) {
            return $object->storageTier;
        }
        if ($object->restoredStoragePath !== null && $object->restoredStoragePath !== ''
            && hash_equals($object->restoredStoragePath, $path)
            && $object->restoredStorageTier !== null && $object->restoredStorageTier !== '') {
            return $object->restoredStorageTier;
        }

        throw new InternalErrorException('AWS committed source does not match an exact metadata reference.');
    }

    /** Exact owner- and marker-bound read of one immutable multipart part attempt. */
    public function readMultipartPart(
        BucketInfo $bucket,
        string $logicalScope,
        string $path,
        string $storageTier,
        string $expectedOwnerId,
        int $expectedSize,
        int $maximumSize,
    ): ReadableStream {
        $this->operationalState?->assertRecoveryReady();
        $profile = $this->binding?->profile;
        $client = $this->binding?->client;
        if ($profile === null || $client === null || ! $this->admission->providerAdmitted) {
            throw $this->unavailable();
        }
        $body = null;
        try {
            $lastSeparator = strrpos($path, '/');
            $attemptId = substr($path, $lastSeparator === false ? 0 : $lastSeparator + 1);
            AwsS3WriteAttemptPath::assertExactPath(
                $path,
                $bucket->name,
                $logicalScope,
                $bucket->generation,
                $storageTier,
                $attemptId,
                $profile->reservedPrefix,
            );
            $result = $client->getObject([
                'Bucket' => $profile->bucket,
                'Key' => $path,
                'ExpectedBucketOwner' => $profile->expectedOwnerId,
                '@retries' => 0,
            ]);
            $body = $result->get('Body');
            $length = $result->get('ContentLength');
            $markers = $result->get('Metadata');
            if (! $body instanceof StreamInterface || ! is_int($length)
                || $length < $expectedSize || $length > $maximumSize
                || ! is_array($markers)
                || ($markers['opsfour-write-attempt'] ?? null) !== 'v2'
                || ($markers['opsfour-attempt-id'] ?? null) !== $attemptId
                || ($markers['opsfour-owner-digest'] ?? null) !== hash('sha256', $expectedOwnerId)
                || ($markers['opsfour-content-size'] ?? null) !== (string) $length) {
                throw new \RuntimeException('Unverified AWS multipart part response.');
            }

            return new LimitedReadableStream(
                new AwsS3CopySourceStream($body),
                $length,
                requireExactLength: true,
                rejectExcess: true,
            );
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            try {
                $body?->close();
            } catch (\Throwable) {
            }
            throw new InternalErrorException('AWS multipart part could not be verified.');
        }
    }

    /**
     * Delete one proven v2 attempt only while the metadata store owns the
     * reference lock, then atomically acknowledge the live claim.
     *
     * @param \Closure(): float $currentLeaseUntil
     * @param \Closure(): void $stopRenewal
     */
    public function deleteClaimedGarbage(
        AwsS3GarbageClaimStore $claims,
        int $claimId,
        string $bucket,
        string $tier,
        string $path,
        string $ownerId,
        int $leaseGeneration,
        \Closure $currentLeaseUntil,
        \Closure $stopRenewal,
    ): bool {
        $this->assertOperationalBinding($tier, $claims);
        $deletion = new AwsS3GarbageDeletion(
            $this,
            $this->garbageDeletionAuthority,
            $bucket,
            $tier,
            $path,
            $ownerId,
        );

        try {
            return $claims->deleteAwsGarbageClaim(
                $claimId,
                $bucket,
                $tier,
                $path,
                $ownerId,
                $leaseGeneration,
                $currentLeaseUntil,
                $stopRenewal,
                $deletion,
            );
        } finally {
            $deletion->revoke();
        }
    }

    /** @internal Invoked only by a provider-bound AwsS3GarbageDeletion. */
    public function executeGarbageDeletion(
        object $authority,
        string $bucket,
        string $tier,
        string $path,
        string $ownerId,
    ): void {
        if ($authority !== $this->garbageDeletionAuthority) {
            throw new \LogicException('AWS garbage deletion authority is invalid.');
        }
        $this->deleteClaimedGarbagePath($path, $bucket, $tier, $ownerId);
    }

    /** Provider mutation is private so it cannot run outside claim coordination. */
    private function deleteClaimedGarbagePath(string $path, string $bucket, string $tier, string $ownerId): void
    {
        $this->operationalState?->assertRecoveryReady();
        $profile = $this->binding?->profile;
        $client = $this->binding?->client;
        if ($profile === null || $client === null || ! $this->admission->providerAdmitted || $ownerId === '') {
            throw $this->unavailable();
        }
        $identity = AwsS3WriteAttemptPath::parseGarbagePath($path, $bucket, $tier, $profile->reservedPrefix);
        $request = [
            'Bucket' => $profile->bucket,
            'Key' => $path,
            'ExpectedBucketOwner' => $profile->expectedOwnerId,
            '@retries' => 0,
        ];
        try {
            $head = $client->headObject($request);
        } catch (AwsException $error) {
            if (! $error->isConnectionError() && $error->getStatusCode() === 404
                && in_array($error->getAwsErrorCode(), ['NoSuchKey', 'NotFound'], true)
                && $this->garbageAbsent($request)) {
                return;
            }
            throw $error;
        }
        if (self::headStatus($head) === 404) {
            if ($this->confirmGarbageAbsentWithGet($request)) {
                return;
            }
            throw new \RuntimeException('AWS garbage absence is not confirmed.');
        }
        if (self::headStatus($head) !== 200) {
            throw new \RuntimeException('AWS garbage HEAD response is not conclusive.');
        }
        $markers = $head->get('Metadata');
        $contentLength = $head->get('ContentLength');
        if (! is_array($markers)
            || ($markers['opsfour-write-attempt'] ?? null) !== 'v2'
            || ($markers['opsfour-attempt-id'] ?? null) !== $identity['attemptId']
            || ($markers['opsfour-owner-digest'] ?? null) !== hash('sha256', $ownerId)
            || ! isset($markers['opsfour-content-size'])
            || ! ctype_digit((string) $markers['opsfour-content-size'])
            || ! is_int($contentLength)
            || (string) $contentLength !== (string) $markers['opsfour-content-size']
            || isset($markers['opsfour-write-seal'])) {
            throw new \RuntimeException('AWS garbage path has conflicting provider evidence.');
        }
        $client->deleteObject($request);
        $this->assertGarbageAbsent($request);
    }

    /** @param array{Bucket: string, Key: string, ExpectedBucketOwner: string, '@retries': int} $request */
    private function assertGarbageAbsent(array $request): void
    {
        if (! $this->garbageAbsent($request)) {
            throw new \RuntimeException('AWS garbage path is still present after deletion.');
        }
    }

    /** @param array{Bucket: string, Key: string, ExpectedBucketOwner: string, '@retries': int} $request */
    private function garbageAbsent(array $request): bool
    {
        $binding = $this->binding;
        if ($binding === null || $binding->client === null) {
            throw $this->unavailable();
        }
        $client = $binding->client;
        try {
            $head = $client->headObject($request);
            if (self::headStatus($head) === 404) {
                return $this->confirmGarbageAbsentWithGet($request);
            }
            if (self::headStatus($head) !== 200) {
                throw new \RuntimeException('AWS garbage HEAD response is not conclusive.');
            }
            return false;
        } catch (AwsException $error) {
            if ($error->isConnectionError() || $error->getStatusCode() !== 404) {
                throw $error;
            }
            if ($error->getAwsErrorCode() === 'NoSuchKey') {
                return true;
            }
            if ($error->getAwsErrorCode() !== 'NotFound') {
                throw $error;
            }
        }
        return $this->confirmGarbageAbsentWithGet($request);
    }

    /** @param array{Bucket: string, Key: string, ExpectedBucketOwner: string, '@retries': int} $request */
    private function confirmGarbageAbsentWithGet(array $request): bool
    {
        $binding = $this->binding;
        if ($binding === null || $binding->client === null) {
            throw $this->unavailable();
        }
        $client = $binding->client;
        try {
            $result = $client->getObject($request + ['Range' => 'bytes=0-0']);
            $body = $result->get('Body');
            $notFound = self::headStatus($result) === 404;
            if ($body instanceof StreamInterface) {
                $body->close();
            }
            return $notFound;
        } catch (AwsException $error) {
            if (! $error->isConnectionError() && $error->getStatusCode() === 404 && $error->getAwsErrorCode() === 'NoSuchKey') {
                return true;
            }
            throw $error;
        }
    }

    private static function headStatus(ResultInterface $head): ?int
    {
        $metadata = $head->get('@metadata');
        return is_array($metadata) && isset($metadata['statusCode']) && is_int($metadata['statusCode'])
            ? $metadata['statusCode'] : null;
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        throw $this->unavailable();
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        throw $this->unavailable();
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        throw $this->unavailable();
    }

    public function createBucket(string $bucket): void
    {
        if ($this->operationalState === null || ! $this->operationalAttachmentCommitted) {
            throw $this->unavailable();
        }

        // One admitted physical provider bucket contains every logical S3
        // bucket. The logical namespace is published by metadata only, so no
        // provider CreateBucket or object mutation belongs here.
        // Deliberately do not require mutation readiness: provider health,
        // recovery pressure, and intent capacity do not protect this
        // metadata-only operation.
    }

    public function deleteBucket(string $bucket): void
    {
        throw $this->unavailable();
    }

    public function bucketExists(string $bucket): bool
    {
        throw $this->unavailable();
    }

    public function putPart(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data): StorageWriteResult
    {
        throw $this->unavailable();
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        throw $this->unavailable();
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        throw $this->unavailable();
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        throw $this->unavailable();
    }

    private function unavailable(): \LogicException
    {
        return new \LogicException('AWS S3 storage operations are unavailable until the provider write fence is complete.');
    }

    private function assertOperationalBinding(string $storageTier, object $metadata): void
    {
        $state = $this->operationalState;
        $binding = $this->binding;
        if ($state === null || $binding === null || $this->operationalTier === null) {
            throw new \LogicException('AWS operational state is not attached.');
        }
        if (! hash_equals($this->operationalTier, $storageTier)) {
            throw new \LogicException('AWS writer requested a different operational storage tier.');
        }
        $state->assertExecutionBinding($binding, $storageTier, $metadata);
    }

    private function assertExactOperationalAttachment(
        AwsS3OperationalState $state,
        string $storageTier,
        object $metadata,
    ): void {
        if ($this->operationalState !== $state || $this->operationalTier === null
            || ! hash_equals($this->operationalTier, $storageTier)) {
            throw new \LogicException('Only the exact attached AWS operational state can be managed.');
        }
        $binding = $this->binding ?? throw $this->unavailable();
        $state->assertExecutionBinding($binding, $storageTier, $metadata);
    }
}
