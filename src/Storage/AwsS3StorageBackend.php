<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use Psr\Log\LoggerInterface;
use Psr\Http\Message\StreamInterface;

/** Provider identity plus narrowly scoped ordinary-object fence capabilities. */
final readonly class AwsS3StorageBackend implements StorageBackend, AwsS3ProviderAdmissionCapability
{
    public function __construct(
        private AwsS3ProviderAdmission $admission,
        private ?AwsS3ProviderValidatedBinding $binding = null,
    ) {
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

    /** Narrow ordinary-write capability; StorageBackend operations remain closed. */
    public function createForegroundObjectWriter(
        ObjectWriteIntentStore $intents,
        string $scratch,
        string $storageTier,
        int $budgetBytes = 10_737_418_240,
        float $leaseSeconds = 300.0,
        float $renewIntervalSeconds = 60.0,
    ): AwsS3ForegroundObjectWriter {
        $profile = $this->binding?->profile;
        $client = $this->binding?->client;
        if ($profile === null || $client === null || ! $this->admission->providerAdmitted) {
            throw $this->unavailable();
        }
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
        return new AwsS3ObjectWriteIntentCollector(
            $intents,
            $storageTier,
            new AwsS3SameKeySealWriter($client, $profile->bucket, $profile->expectedOwnerId),
            $logger,
            $metrics,
            $claimLeaseSeconds,
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
                $source->storageTier,
                $attemptId,
                $profile->reservedPrefix,
            );
            $request = [
                'Bucket' => $profile->bucket,
                'Key' => $path,
                'ExpectedBucketOwner' => $profile->expectedOwnerId,
                '@retries' => 0,
            ];
            $expectedLength = $source->size;
            $expectedContentRange = null;
            if ($offset !== null || $length !== null) {
                if ($offset === null || $length === null || $offset < 0 || $length < 1
                    || $offset > PHP_INT_MAX - $length) {
                    throw new \InvalidArgumentException('Invalid AWS copy source range.');
                }
                $request['Range'] = 'bytes=' . $offset . '-' . ($offset + $length - 1);
                $expectedLength = $length;
                $expectedContentRange = 'bytes ' . $offset . '-' . ($offset + $length - 1) . '/' . $source->size;
            }
            $result = $client->getObject($request);
            $body = $result->get('Body');
            $responseLength = $result->get('ContentLength');
            $contentRange = $result->get('ContentRange');
            $markers = $result->get('Metadata');
            if (! $body instanceof StreamInterface || ! is_int($responseLength) || $responseLength !== $expectedLength
                || ($expectedContentRange !== null && $contentRange !== $expectedContentRange)
                || ! is_array($markers)
                || ($markers['opsfour-write-attempt'] ?? null) !== 'v1'
                || ($markers['opsfour-attempt-id'] ?? null) !== $attemptId) {
                throw new \RuntimeException('Unverified AWS copy source response.');
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
            throw new InternalErrorException('AWS copy source could not be verified.');
        }
    }

    /** Exact owner- and marker-bound read of one immutable multipart part attempt. */
    public function readMultipartPart(
        BucketInfo $bucket,
        string $logicalScope,
        string $path,
        string $storageTier,
        int $expectedSize,
        int $maximumSize,
    ): ReadableStream {
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
                || ($markers['opsfour-write-attempt'] ?? null) !== 'v1'
                || ($markers['opsfour-attempt-id'] ?? null) !== $attemptId) {
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
        throw $this->unavailable();
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
}
