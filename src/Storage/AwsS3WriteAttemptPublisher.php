<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use Psr\Http\Message\StreamInterface;

/** Durably reserve and arm one exact attempt before its sole AWS dispatch. */
final readonly class AwsS3WriteAttemptPublisher
{
    public function __construct(
        private AwsS3ProviderProfile $profile,
        private ObjectWriteIntentStore $intents,
        private AwsS3CreateOnlyWriter $writer,
        private float $leaseSeconds = 300.0,
    ) {
        if (! is_finite($leaseSeconds) || $leaseSeconds <= 0.0 || $leaseSeconds > 86400.0) {
            throw new \InvalidArgumentException('AWS write-attempt lease must be between 0 and 86400 seconds.');
        }
        if (! $writer->isBoundTo($profile, $intents, $leaseSeconds)) {
            throw new \InvalidArgumentException('AWS write-attempt publisher requires one exact profile and intent store binding.');
        }
    }

    /** @param resource|string|\Psr\Http\Message\StreamInterface $body */
    public function publish(ObjectWriteIntent $planned, string $logicalKey, mixed $body): AwsS3WriteAttemptOutcome
    {
        AwsS3WriteAttemptPath::assertProfileIntentPath($this->profile, $planned, $logicalKey);
        self::assertPlanned($planned);
        self::assertBody($body);

        try {
            $reserved = $this->intents->reserveObjectWriteIntent($planned, $this->leaseSeconds);
        } catch (SlowDownException $error) {
            throw $error;
        } catch (\Throwable) {
            throw new AwsS3WriteAttemptArmException();
        }
        if (! self::sameIdentity($planned, $reserved)
            || $reserved->state !== 'active'
            || $reserved->leaseUntil <= 0.0
            || $reserved->claimGeneration !== 0
            || $reserved->dispatchId !== null) {
            throw new AwsS3WriteAttemptArmException();
        }

        try {
            $dispatchId = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            throw new AwsS3WriteAttemptArmException();
        }
        try {
            $armed = $this->intents->armObjectWriteIntent(
                $reserved->attemptId,
                $reserved->leaseUntil,
                $dispatchId,
                $this->leaseSeconds,
            );
        } catch (\Throwable) {
            throw new AwsS3WriteAttemptArmException();
        }
        if ($armed === null
            || ! self::sameIdentity($planned, $armed)
            || $armed->state !== 'active'
            || $armed->leaseUntil < $reserved->leaseUntil
            || $armed->claimGeneration !== 0
            || $armed->dispatchId !== $dispatchId) {
            throw new AwsS3WriteAttemptArmException();
        }

        $provider = $this->writer->publish($armed, $logicalKey, $body);

        return AwsS3WriteAttemptOutcome::fromProvider($armed->attemptId, $dispatchId, $provider);
    }

    private static function assertPlanned(ObjectWriteIntent $intent): void
    {
        if ($intent->state !== 'active'
            || $intent->leaseUntil !== 0.0
            || $intent->claimGeneration !== 0
            || $intent->dispatchId !== null) {
            throw new \InvalidArgumentException('AWS write attempt must be an unreserved exact plan.');
        }
    }

    private static function assertBody(mixed $body): void
    {
        if (! is_string($body) && ! is_resource($body) && ! $body instanceof StreamInterface) {
            throw new \InvalidArgumentException('AWS write-attempt body must be a string, resource, or PSR-7 stream.');
        }
    }

    private static function sameIdentity(ObjectWriteIntent $expected, ObjectWriteIntent $actual): bool
    {
        return hash_equals($expected->attemptId, $actual->attemptId)
            && $expected->bucket === $actual->bucket
            && hash_equals($expected->bucketGeneration, $actual->bucketGeneration)
            && $expected->ownerId === $actual->ownerId
            && $expected->storageTier === $actual->storageTier
            && hash_equals($expected->storagePath, $actual->storagePath)
            && $expected->purpose === $actual->purpose;
    }
}
