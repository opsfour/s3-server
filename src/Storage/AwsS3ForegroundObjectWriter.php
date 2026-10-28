<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/** The sole ordinary-object AWS destination coordinator. */
final readonly class AwsS3ForegroundObjectWriter
{
    private object $authority;

    public function __construct(
        private AwsS3StorageBackend $backend,
        private AwsS3ProviderProfile $profile,
        private ObjectWriteIntentStore $intents,
        private AwsS3WriteAttemptPublisher $publisher,
        private LocalScratchStager $stager,
        private string $storageTier,
        private float $renewIntervalSeconds = 60.0,
    ) {
        if ($storageTier === ''
            || ! is_finite($renewIntervalSeconds) || $renewIntervalSeconds <= 0.0
            || $renewIntervalSeconds >= $publisher->leaseSeconds() / 2
            || ! $backend->isBoundToProfile($profile)
            || ! $publisher->isBoundTo($profile, $intents)) {
            throw new \InvalidArgumentException('AWS foreground writer requires its exact profile, tier, and intent store.');
        }
        $this->authority = new \stdClass();
    }

    public function storageTierName(): string
    {
        return $this->storageTier;
    }

    public function profile(): AwsS3ProviderProfile
    {
        return $this->profile;
    }

    public function isImmutableAttemptPath(string $storageTier, string $path): bool
    {
        return hash_equals($this->storageTier, $storageTier)
            && str_starts_with($path, $this->profile->reservedPrefix . '/');
    }

    public function isBoundTo(
        AwsS3StorageBackend $backend,
        AwsS3ProviderProfile $profile,
        ObjectWriteIntentStore $intents,
    ): bool {
        return $this->backend === $backend
            && $this->profile === $profile
            && $this->intents === $intents;
    }

    /** Exact backend/tier binding used by the background publication adapter. */
    public function isBoundToBackend(AwsS3StorageBackend $backend, string $storageTier): bool
    {
        return $this->backend === $backend && hash_equals($this->storageTier, $storageTier);
    }

    public function shutdown(): void
    {
        $this->stager->shutdown();
    }

    public function reserve(
        BucketInfo $bucket,
        string $key,
        string $ownerId,
        string $purpose = 'put_object',
    ): AwsS3ForegroundObjectReservation {
        if (! in_array($purpose, ['put_object', 'copy_object', 'upload_part', 'upload_part_copy', 'complete_multipart', 'tier_transition', 'restore_copy'], true)) {
            throw new \InvalidArgumentException('AWS foreground publication purpose is unsupported.');
        }
        $attemptId = bin2hex(random_bytes(16));
        $reserved = $this->publisher->reserve(new ObjectWriteIntent(
            attemptId: $attemptId,
            bucket: $bucket->name,
            bucketGeneration: $bucket->generation,
            ownerId: $ownerId,
            storageTier: $this->storageTier,
            storagePath: AwsS3WriteAttemptPath::forProfileObject(
                $this->profile,
                $bucket->name,
                $key,
                $bucket->generation,
                $this->storageTier,
                $attemptId,
            ),
            purpose: $purpose,
        ), $key);

        return new AwsS3ForegroundObjectReservation(
            $this->authority,
            $reserved,
            (int) min(PHP_INT_MAX, ceil($this->renewIntervalSeconds * 1_000_000_000)),
        );
    }

    public function reserveMultipartPart(
        BucketInfo $bucket,
        string $key,
        string $ownerId,
        string $uploadId,
        int $partNumber,
        bool $copy = false,
    ): AwsS3ForegroundObjectReservation {
        return $this->reserve(
            $bucket,
            self::multipartPartLogicalKey($key, $uploadId, $partNumber),
            $ownerId,
            $copy ? 'upload_part_copy' : 'upload_part',
        );
    }

    public function dispatchMultipartPart(
        AwsS3ForegroundObjectReservation $reservation,
        string $key,
        string $uploadId,
        int $partNumber,
        AwsS3ForegroundObjectStage $stage,
    ): AwsS3ForegroundObjectOutcome {
        return $this->dispatch(
            $reservation,
            self::multipartPartLogicalKey($key, $uploadId, $partNumber),
            $stage,
        );
    }

    public function reserveMultipartCompletion(
        BucketInfo $bucket,
        string $key,
        string $ownerId,
        string $uploadId,
    ): AwsS3ForegroundObjectReservation {
        self::assertMultipartCompletionScope($key, $uploadId);

        return $this->reserve(
            $bucket,
            $key,
            $ownerId,
            'complete_multipart',
        );
    }

    public function dispatchMultipartCompletion(
        AwsS3ForegroundObjectReservation $reservation,
        string $key,
        string $uploadId,
        AwsS3ForegroundObjectStage $stage,
    ): AwsS3ForegroundObjectOutcome {
        self::assertMultipartCompletionScope($key, $uploadId);

        return $this->dispatch(
            $reservation,
            $key,
            $stage,
        );
    }

    public function dispatchMultipartCompletionStream(
        AwsS3ForegroundObjectReservation $reservation,
        string $key,
        string $uploadId,
        \Psr\Http\Message\StreamInterface $body,
        StorageWriteResult $result,
    ): AwsS3ForegroundObjectOutcome {
        self::assertMultipartCompletionScope($key, $uploadId);
        $this->heartbeat($reservation, true);
        $current = $reservation->current($this->authority);
        if (! hash_equals($current->storagePath, $result->path)) {
            throw new \InvalidArgumentException('AWS multipart completion result does not match its reservation.');
        }
        $outcome = $this->publisher->dispatch(
            $current,
            $key,
            $body,
            $result->size,
            $body instanceof AwsS3MultipartPsrStream
                ? $body->sha256Hex()
                : self::sha256Hex($result),
        );
        if ($outcome->state !== AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS) {
            $this->publisher->retireConfirmedRejection($outcome);
            throw new InternalErrorException('AWS multipart completion publication was not confirmed.');
        }

        return new AwsS3ForegroundObjectOutcome(
            $result,
            new LocalObjectWriteDispatchToken($outcome->attemptId, $outcome->dispatchId),
        );
    }

    public function readMultipartPart(
        BucketInfo $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        string $expectedOwnerId,
        string $path,
        int $expectedSize,
        int $maximumSize,
    ): ReadableStream {
        return $this->backend->readMultipartPart(
            $bucket,
            self::multipartPartLogicalKey($key, $uploadId, $partNumber),
            $path,
            $this->storageTier,
            $expectedOwnerId,
            $expectedSize,
            $maximumSize,
        );
    }

    private static function multipartPartLogicalKey(string $key, string $uploadId, int $partNumber): string
    {
        if ($key === '' || $uploadId === '' || $partNumber < 1 || $partNumber > 10000) {
            throw new \InvalidArgumentException('Invalid AWS multipart part scope.');
        }

        return '@multipart-part/' . hash('sha256', $key) . '/' . hash('sha256', $uploadId) . '/' . $partNumber;
    }

    private static function assertMultipartCompletionScope(string $key, string $uploadId): void
    {
        if ($key === '' || $uploadId === '') {
            throw new \InvalidArgumentException('Invalid AWS multipart completion scope.');
        }
    }

    public function retireBeforeDispatch(AwsS3ForegroundObjectReservation $reservation): void
    {
        $this->publisher->retireUndispatched($reservation->current($this->authority));
    }

    public function plannedPath(AwsS3ForegroundObjectReservation $reservation): string
    {
        return $reservation->current($this->authority)->storagePath;
    }

    public function heartbeat(AwsS3ForegroundObjectReservation $reservation, bool $force = false): void
    {
        if (! $reservation->renewalDue($this->authority, $force)) {
            return;
        }
        $reservation->replace(
            $this->authority,
            $this->publisher->renewUndispatched($reservation->current($this->authority)),
        );
    }

    public function renewingStream(
        AwsS3ForegroundObjectReservation $reservation,
        ReadableStream $body,
    ): ReadableStream {
        return new AwsS3ReservationReadableStream(
            $body,
            function () use ($reservation): void {
                $this->heartbeat($reservation);
            },
        );
    }

    /**
     * Keep one reservation alive while an encryption worker may yield control.
     *
     * @template T
     * @param \Closure(): T $transform
     * @return T
     */
    public function transform(
        AwsS3ForegroundObjectReservation $reservation,
        \Closure $transform,
    ): mixed {
        $this->heartbeat($reservation);
        $stop = new \Amp\DeferredCancellation();
        $heartbeat = \Amp\async(function () use ($reservation, $stop): void {
            $cancellation = $stop->getCancellation();
            while (! $cancellation->isRequested()) {
                \Amp\delay(
                    $this->renewIntervalSeconds,
                    cancellation: $cancellation,
                );
                if (! $cancellation->isRequested()) {
                    $this->heartbeat($reservation, true);
                }
            }
        });
        $heartbeat->ignore();
        $result = null;
        $transformFailure = null;
        try {
            $result = $transform();
        } catch (\Throwable $error) {
            $transformFailure = $error;
        } finally {
            $stop->cancel();
        }

        $heartbeatFailure = null;
        try {
            $heartbeat->await();
        } catch (\Amp\CancelledException) {
            // Expected after the transform reaches a terminal state.
        } catch (\Throwable $error) {
            $heartbeatFailure = $error;
        }
        if ($transformFailure !== null) {
            throw $transformFailure;
        }
        if ($heartbeatFailure !== null) {
            throw $heartbeatFailure;
        }
        $this->heartbeat($reservation);

        return $result;
    }

    public function stage(
        AwsS3ForegroundObjectReservation $reservation,
        ReadableStream $body,
    ): AwsS3ForegroundObjectStage {
        $current = $reservation->current($this->authority);
        $scratch = $this->stager->stage(
            $current->attemptId,
            $body,
            fullChecksums: true,
            heartbeat: function () use ($reservation): void {
                $this->heartbeat($reservation);
            },
            heartbeatIntervalSeconds: $this->renewIntervalSeconds,
        );
        $checksums = $scratch->checksums;
        if ($checksums === null || $checksums->size !== $scratch->size) {
            try {
                $this->stager->cleanup($scratch);
            } catch (\Throwable) {
            }
            throw new InternalErrorException('AWS object staging did not produce complete checksums.');
        }

        return new AwsS3ForegroundObjectStage(
            $this->authority,
            $reservation,
            $scratch,
            new StorageWriteResult(
                path: $current->storagePath,
                size: $checksums->size,
                md5Hex: $checksums->md5Hex,
                crc32Base64: $checksums->crc32Base64(),
                crc32cBase64: $checksums->crc32cBase64(),
                sha1Base64: $checksums->sha1Base64(),
                sha256Base64: $checksums->sha256Base64(),
            ),
        );
    }

    public function dispatch(
        AwsS3ForegroundObjectReservation $reservation,
        string $key,
        AwsS3ForegroundObjectStage $stage,
    ): AwsS3ForegroundObjectOutcome {
        if ($stage->reservation($this->authority) !== $reservation) {
            throw new \InvalidArgumentException('AWS foreground stage does not match its reservation.');
        }
        $this->heartbeat($reservation, true);
        $current = $reservation->current($this->authority);
        $input = null;
        $dispatchFailure = null;
        $closeFailure = null;
        $result = null;
        try {
            $input = @fopen($stage->scratch($this->authority)->path, 'rb');
            if ($input === false) {
                throw new InternalErrorException('AWS object staging could not be opened.');
            }
            $outcome = $this->publisher->dispatch(
                $current,
                $key,
                $input,
                $stage->result->size,
                self::sha256Hex($stage->result),
            );
            if ($outcome->state !== AwsS3CreateOnlyWriteOutcome::CONFIRMED_SUCCESS) {
                $this->publisher->retireConfirmedRejection($outcome);
                throw new InternalErrorException('AWS object publication was not confirmed.');
            }
            $result = new AwsS3ForegroundObjectOutcome(
                $stage->result,
                new LocalObjectWriteDispatchToken($outcome->attemptId, $outcome->dispatchId),
            );
        } catch (\Throwable $error) {
            $dispatchFailure = $error;
            $this->publisher->retireUndispatched($current);
        } finally {
            try {
                if (is_resource($input)) {
                    fclose($input);
                }
            } catch (\Throwable $cleanupError) {
                $closeFailure = $cleanupError;
            }
        }
        if ($dispatchFailure !== null) {
            throw $dispatchFailure;
        }
        if ($closeFailure !== null) {
            throw $closeFailure;
        }

        return $result ?? throw new \LogicException('AWS object publication completed without an outcome.');
    }

    public function release(AwsS3ForegroundObjectStage $stage): void
    {
        $scratch = $stage->release($this->authority);
        if ($scratch !== null) {
            $this->stager->cleanup($scratch);
        }
    }

    private static function sha256Hex(StorageWriteResult $result): string
    {
        $raw = $result->sha256Base64 === null ? false : base64_decode($result->sha256Base64, true);
        if ($raw === false || strlen($raw) !== 32) {
            throw new InternalErrorException('AWS object staging produced invalid SHA-256 evidence.');
        }
        return bin2hex($raw);
    }
}
