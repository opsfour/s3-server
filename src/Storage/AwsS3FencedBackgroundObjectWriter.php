<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/** Tier-bound AWS publication and source-read capability for background workers. */
final readonly class AwsS3FencedBackgroundObjectWriter
{
    public function __construct(
        private AwsS3ForegroundObjectWriter $writer,
        private AwsS3StorageBackend $backend,
        private string $storageTier,
    ) {
        if ($storageTier === '' || ! $writer->isBoundToBackend($backend, $storageTier)) {
            throw new \InvalidArgumentException('AWS background writer requires one exact backend and tier binding.');
        }
    }

    public function isBoundTier(StorageBackend $backend, string $storageTier): bool
    {
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        return $backend === $this->backend && hash_equals($this->storageTier, $storageTier);
    }

    public function isBoundTo(
        AwsS3ForegroundObjectWriter $writer,
        AwsS3StorageBackend $backend,
        string $storageTier,
    ): bool {
        return $this->writer === $writer
            && $this->backend === $backend
            && hash_equals($this->storageTier, $storageTier);
    }

    public function foregroundWriter(): AwsS3ForegroundObjectWriter
    {
        return $this->writer;
    }

    public function isBoundToGraph(
        AwsS3StorageBackend $backend,
        AwsS3ProviderProfile $profile,
        ObjectWriteIntentStore $metadata,
        string $storageTier,
        AwsS3OperationalState $operationalState,
    ): bool {
        return $this->backend === $backend
            && hash_equals($this->storageTier, $storageTier)
            && $this->writer->isBoundToOperationalGraph($backend, $profile, $metadata, $operationalState)
            && hash_equals($this->writer->storageTierName(), $storageTier);
    }

    public function reserve(
        ObjectInfo $object,
        BucketInfo $bucket,
        StorageBackend $backend,
        string $storageTier,
        string $purpose,
    ): AwsS3ForegroundObjectReservation {
        if (! $this->isBoundTier($backend, $storageTier)
            || $bucket->name !== $object->bucket
            || $bucket->generation === ''
            || $object->ownerId === '') {
            throw new \RuntimeException('AWS background target does not match its bound tier or object scope.');
        }

        return $this->writer->reserve($bucket, $object->key, $object->ownerId, $purpose);
    }

    /** @return array{StorageWriteResult, LocalObjectWriteDispatchToken} */
    public function publishReserved(
        AwsS3ForegroundObjectReservation $reservation,
        ObjectInfo $object,
        ReadableStream $body,
        ?int $knownSize = null,
    ): array {
        $stage = null;
        try {
            $stage = $this->writer->stage($reservation, $this->writer->renewingStream($reservation, $body), $knownSize);
            $outcome = $this->writer->dispatch($reservation, $object->key, $stage);

            return [$outcome->result, $outcome->dispatch];
        } catch (\Throwable $error) {
            try {
                $this->writer->retireBeforeDispatch($reservation);
            } catch (\Throwable) {
                // Armed or uncertain attempts remain durable for reconciliation.
            }
            throw $error;
        } finally {
            if ($stage !== null) {
                $this->writer->release($stage);
            }
        }
    }

    public function retireBeforeDispatch(AwsS3ForegroundObjectReservation $reservation): void
    {
        $this->writer->retireBeforeDispatch($reservation);
    }

    public function readCommittedSource(
        BucketInfo $bucket,
        ObjectInfo $object,
        StorageBackend $backend,
        string $storageTier,
        string $path,
    ): ReadableStream {
        if (! $this->isBoundTier($backend, $storageTier)) {
            throw new \RuntimeException('AWS background source does not match its bound tier.');
        }

        return $this->backend->readCommittedObject($bucket, $object, $path, $storageTier);
    }
}
