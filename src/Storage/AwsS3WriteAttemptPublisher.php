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
        self::assertBody($body);
        $reserved = $this->reserve($planned, $logicalKey);
        try {
            [$expectedSize, $expectedSha256] = self::bodyEvidence($body);
        } catch (\Throwable $error) {
            try {
                $this->intents->retireUndispatchedObjectWriteIntent($reserved->attemptId);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return $this->dispatch($reserved, $logicalKey, $body, $expectedSize, $expectedSha256);
    }

    /** Reserve capacity before the foreground caller reads its body. */
    public function reserve(ObjectWriteIntent $planned, string $logicalKey): ObjectWriteIntent
    {
        AwsS3WriteAttemptPath::assertProfileIntentPath($this->profile, $planned, $logicalKey);
        self::assertPlanned($planned);
        $planned = new ObjectWriteIntent(
            attemptId: $planned->attemptId,
            bucket: $planned->bucket,
            bucketGeneration: $planned->bucketGeneration,
            ownerId: $planned->ownerId,
            storageTier: $planned->storageTier,
            storagePath: $planned->storagePath,
            purpose: $planned->purpose,
            sealReserved: true,
        );

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

        return $reserved;
    }

    /** @param resource|string|\Psr\Http\Message\StreamInterface $body */
    public function dispatch(
        ObjectWriteIntent $reserved,
        string $logicalKey,
        mixed $body,
        ?int $expectedSize = null,
        ?string $expectedSha256 = null,
        ?\Closure $beforeRecoverableExpiry = null,
    ): AwsS3WriteAttemptOutcome {
        AwsS3WriteAttemptPath::assertProfileIntentPath($this->profile, $reserved, $logicalKey);
        self::assertBody($body);
        if ($reserved->state !== 'active' || $reserved->leaseUntil <= 0.0
            || $reserved->claimGeneration !== 0 || $reserved->dispatchId !== null || ! $reserved->sealReserved) {
            throw new AwsS3WriteAttemptArmException();
        }
        if ($expectedSize === null || $expectedSha256 === null) {
            [$expectedSize, $expectedSha256] = self::bodyEvidence($body);
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
                $expectedSize,
                $expectedSha256,
            );
        } catch (\Throwable) {
            throw new AwsS3WriteAttemptArmException();
        }
        if ($armed === null
            || ! self::sameIdentity($reserved, $armed)
            || $armed->state !== 'active'
            || $armed->leaseUntil < $reserved->leaseUntil
            || $armed->claimGeneration !== 0
            || $armed->dispatchId !== $dispatchId
            || $armed->expectedSize !== $expectedSize
            || $armed->expectedSha256 !== $expectedSha256
            || ! $armed->sealReserved) {
            throw new AwsS3WriteAttemptArmException();
        }

        $provider = $this->writer->publish($armed, $logicalKey, $body);
        $outcome = AwsS3WriteAttemptOutcome::fromProvider($armed->attemptId, $dispatchId, $provider);
        if (in_array($outcome->state, [
            AwsS3CreateOnlyWriteOutcome::AMBIGUOUS,
            AwsS3CreateOnlyWriteOutcome::PRECONDITION_CONFLICT,
        ], true)) {
            $beforeRecoverableExpiry?->__invoke($outcome);
            try {
                $this->intents->expireArmedObjectWriteIntent($outcome->attemptId, $outcome->dispatchId);
            } catch (\Throwable) {
                // Immediate local integrity closure still prevents publication;
                // the exact durable lease remains recoverable when it expires.
            }
        }

        return $outcome;
    }

    /** Renew only the exact reservation while foreground staging is still undispatched. */
    public function renewUndispatched(ObjectWriteIntent $reserved): ObjectWriteIntent
    {
        if ($reserved->state !== 'active' || $reserved->leaseUntil <= 0.0
            || $reserved->claimGeneration !== 0 || $reserved->dispatchId !== null) {
            throw new AwsS3WriteAttemptArmException();
        }
        try {
            $renewed = $this->intents->renewObjectWriteIntent(
                $reserved->attemptId,
                $reserved->leaseUntil,
                $this->leaseSeconds,
            );
        } catch (\Throwable) {
            throw new AwsS3WriteAttemptArmException();
        }
        if ($renewed === null
            || ! self::sameIdentity($reserved, $renewed)
            || $renewed->state !== 'active'
            || $renewed->leaseUntil <= $reserved->leaseUntil
            || $renewed->claimGeneration !== 0
            || $renewed->dispatchId !== null) {
            throw new AwsS3WriteAttemptArmException();
        }

        return $renewed;
    }

    public function leaseSeconds(): float
    {
        return $this->leaseSeconds;
    }

    /** An unarmed exact reservation can be retired after definite pre-dispatch failure. */
    public function retireUndispatched(ObjectWriteIntent $reserved): void
    {
        try {
            $current = $this->intents->getObjectWriteIntent($reserved->attemptId);
            if ($current !== null && self::sameIdentity($reserved, $current)
                && $current->dispatchId === null && $current->state === 'active') {
                $this->intents->retireUndispatchedObjectWriteIntent($reserved->attemptId);
            }
        } catch (\Throwable) {
            // Failed retirement leaves durable evidence for the collector.
        }
    }

    /** Exact terminal refusal is the only armed request-path retirement. */
    public function retireConfirmedRejection(AwsS3WriteAttemptOutcome $outcome): void
    {
        if ($outcome->state !== AwsS3CreateOnlyWriteOutcome::CONFIRMED_REJECTION) {
            return;
        }
        try {
            $this->intents->retireTerminalPreLinkObjectWriteIntent($outcome->attemptId, $outcome->dispatchId);
        } catch (\Throwable) {
            // Preserve the row if CAS or metadata is uncertain.
        }
    }

    public function isBoundTo(AwsS3ProviderProfile $profile, ObjectWriteIntentStore $intents): bool
    {
        return $this->profile === $profile && $this->intents === $intents;
    }

    private static function assertPlanned(ObjectWriteIntent $intent): void
    {
        if ($intent->state !== 'active'
            || $intent->leaseUntil !== 0.0
            || $intent->claimGeneration !== 0
            || $intent->dispatchId !== null
            || $intent->expectedSize !== null
            || $intent->expectedSha256 !== null) {
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
            && $expected->purpose === $actual->purpose
            && $expected->sealReserved === $actual->sealReserved;
    }

    /** @return array{int, string} */
    private static function bodyEvidence(mixed $body): array
    {
        if (is_string($body)) {
            return [strlen($body), hash('sha256', $body)];
        }
        if (is_resource($body)) {
            $position = ftell($body);
            if ($position === false) {
                throw new \InvalidArgumentException('AWS write-attempt body must be seekable for durable evidence.');
            }
            $context = hash_init('sha256');
            $size = hash_update_stream($context, $body);
            if (fseek($body, $position) !== 0) {
                throw new \InvalidArgumentException('AWS write-attempt body could not produce durable evidence.');
            }
            return [$size, hash_final($context)];
        }
        if ($body instanceof StreamInterface && $body->isSeekable()) {
            $position = $body->tell();
            $context = hash_init('sha256');
            $size = 0;
            while (! $body->eof()) {
                $chunk = $body->read(8192);
                if ($chunk === '') {
                    throw new \InvalidArgumentException('AWS write-attempt body made no progress while producing durable evidence.');
                }
                $size += strlen($chunk);
                hash_update($context, $chunk);
            }
            $body->seek($position);
            return [$size, hash_final($context)];
        }
        throw new \InvalidArgumentException('AWS write-attempt body requires precomputed size and SHA-256 evidence.');
    }
}
