<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderProbe;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3ReadExecutor;
use OpsFour\S3Server\Storage\AwsS3CopySourceStream;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\LimitedReadableStream;
use Amp\ByteStream\ReadableStream;
use Aws\S3\S3Client;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use Psr\Http\Message\StreamInterface;

/** Test-only exact-binding operational state with no provider or database I/O. */
final class AwsS3OperationalStateFixture
{
    public static function attach(
        AwsS3StorageBackend $backend,
        AwsS3ProviderValidatedBinding $binding,
        string $storageTier,
        ?AwsS3OperationalSummaryReader $metadata = null,
    ): AwsS3OperationalState {
        $metadata ??= new FixtureAwsS3OperationalSummary();
        $state = new AwsS3OperationalState(
            $binding,
            new FixtureAwsS3ProviderProbe($binding->admission),
            StorageMutatorInventory::forEnabledRuntime([
                ...HandlerRegistrar::storageMutatorRegistrations(),
                ...S3Server::storageMutatorRegistrations(),
                ...AwsS3ObjectWriteIntentReconciler::storageMutatorRegistrations(),
            ]),
            $metadata,
            $storageTier,
        );
        $backend->attachOperationalState($state, $storageTier, $metadata);
        $client = $binding->client ?? throw new \LogicException('Fixture AWS client is unavailable.');
        $backend->attachReadExecutor(new FixtureAwsS3ReadExecutor($client));
        $backend->commitOperationalStateAttachment($state, $storageTier, $metadata);

        return $state;
    }
}

/** Test-only inline equivalent; production wiring always uses the worker executor. */
final readonly class FixtureAwsS3ReadExecutor implements AwsS3ReadExecutor
{
    public function __construct(private S3Client $client) {}

    public function readCommittedObject(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        ObjectInfo $object,
        string $key,
        string $path,
        string $storageTier,
        ?int $offset,
        ?int $length,
    ): ReadableStream {
        $expectedLength = $object->size;
        $expectedRange = null;
        $status = 200;
        $request = $this->request($profile, $bucket, $key, $path, $storageTier);
        if ($offset !== null || $length !== null) {
            if ($offset === null || $length === null) {
                throw new \InvalidArgumentException('Fixture AWS range is incomplete.');
            }
            $end = $offset + $length - 1;
            $request['Range'] = "bytes={$offset}-{$end}";
            $expectedLength = $length;
            $expectedRange = "bytes {$offset}-{$end}/{$object->size}";
            $status = 206;
        }
        return $this->open($request, $object->ownerId, $object->size, $expectedLength, $expectedRange, $status);
    }

    public function readMultipartPart(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        string $logicalScope,
        string $path,
        string $storageTier,
        string $expectedOwnerId,
        int $expectedSize,
        int $maximumSize,
    ): ReadableStream {
        $request = $this->request($profile, $bucket, $logicalScope, $path, $storageTier);
        $result = $this->client->getObject($request);
        $length = $result->get('ContentLength');
        if (! is_int($length) || $length < $expectedSize || $length > $maximumSize) {
            throw new \RuntimeException('Fixture AWS multipart length is invalid.');
        }
        return $this->verified($result, $path, $expectedOwnerId, $length, $length, null, 200);
    }

    /** @return array<string, mixed> */
    private function request(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        string $scope,
        string $path,
        string $tier,
    ): array {
        $attempt = basename($path);
        AwsS3WriteAttemptPath::assertExactPath(
            $path,
            $bucket->name,
            $scope,
            $bucket->generation,
            $tier,
            $attempt,
            $profile->reservedPrefix,
        );
        return [
            'Bucket' => $profile->bucket,
            'Key' => $path,
            'ExpectedBucketOwner' => $profile->expectedOwnerId,
            '@retries' => 0,
            '@http' => ['stream' => true],
        ];
    }

    /** @param array<string, mixed> $request */
    private function open(
        array $request,
        string $owner,
        int $logicalSize,
        int $expectedLength,
        ?string $range,
        int $status,
    ): ReadableStream {
        return $this->verified(
            $this->client->getObject($request),
            (string) $request['Key'],
            $owner,
            $logicalSize,
            $expectedLength,
            $range,
            $status,
        );
    }

    private function verified(
        \Aws\ResultInterface $result,
        string $path,
        string $owner,
        int $logicalSize,
        int $expectedLength,
        ?string $range,
        int $status,
    ): ReadableStream {
        $body = $result->get('Body');
        $metadata = $result->get('@metadata');
        $markers = $result->get('Metadata');
        $actualStatus = is_array($metadata) ? ($metadata['statusCode'] ?? null) : null;
        if (! $body instanceof StreamInterface || $result->get('ContentLength') !== $expectedLength
            || $result->get('ContentRange') !== $range || $actualStatus !== $status || ! is_array($markers)
            || ($markers['opsfour-write-attempt'] ?? null) !== 'v2'
            || ($markers['opsfour-attempt-id'] ?? null) !== basename($path)
            || ($markers['opsfour-owner-digest'] ?? null) !== hash('sha256', $owner)
            || ($markers['opsfour-content-size'] ?? null) !== (string) $logicalSize) {
            $body instanceof StreamInterface && $body->close();
            throw new \RuntimeException('Fixture AWS response evidence is invalid.');
        }
        return new LimitedReadableStream(
            new AwsS3CopySourceStream($body),
            $expectedLength,
            requireExactLength: true,
            rejectExcess: true,
        );
    }
}

final readonly class FixtureAwsS3ProviderProbe implements AwsS3ProviderProbe
{
    public function __construct(private AwsS3ProviderAdmission $admission) {}

    public function revalidate(AwsS3ProviderProfile $profile): AwsS3ProviderAdmission
    {
        if ($profile->region !== $this->admission->configuredRegion) {
            throw new \LogicException('Fixture AWS profile changed.');
        }

        return $this->admission;
    }
}

final class FixtureAwsS3OperationalSummary implements AwsS3OperationalSummaryReader
{
    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        if ($storageTier === '') {
            throw new \LogicException('Fixture AWS storage tier is empty.');
        }

        return [
            'open' => 0,
            'ambiguous' => 0,
            'seal_reserved' => 0,
            'sealed' => 0,
            'multipart' => 0,
            'part' => 0,
            'cleanup' => 0,
            'failures' => 0,
            'oldest_age_seconds' => 0.0,
            'max_owner_open' => 0,
            'max_owner_sealed' => 0,
            'max_owner_multipart' => 0,
            'max_owner_part' => 0,
            'global_open' => 0,
            'global_seal_reserved' => 0,
            'global_multipart' => 0,
            'global_part' => 0,
            'global_cleanup' => 0,
        ];
    }
}
