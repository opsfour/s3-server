<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Observability\ObservedStorageBackend;

/** Fences a background object's physical publication before its metadata commit. */
final readonly class LocalFencedBackgroundObjectWriter
{
    public function __construct(
        private LocalFencedObjectWriter $writer,
        private LocalObjectWriteLeaseGuardFactory $leases,
    ) {}

    public function assertBoundTo(LocalFencedObjectWriter $writer, LocalObjectWriteLeaseGuardFactory $leases): void
    {
        if ($this->writer !== $writer || $this->leases !== $leases) {
            throw new \RuntimeException('Background local writer differs from the configured publisher or lease factory.');
        }
    }

    public function isFilesystemTarget(StorageBackend $backend): bool
    {
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }

        return $backend instanceof FilesystemBackend;
    }

    /** @return array{StorageWriteResult, LocalObjectWriteDispatchToken} */
    public function publish(
        ObjectInfo $object,
        BucketInfo $bucket,
        StorageBackend $backend,
        string $tier,
        ReadableStream $body,
        string $purpose,
        ?int $knownSize = null,
    ): array {
        while ($backend instanceof ObservedStorageBackend) {
            $backend = $backend->innerBackend();
        }
        if (! $backend instanceof FilesystemBackend
            || realpath($backend->getBasePath()) !== $this->writer->storageRoot()
            || $tier !== $this->writer->storageTierName()
            || $bucket->name !== $object->bucket
            || $bucket->generation === '') {
            throw new \RuntimeException('Background local write target does not match its fenced writer or bucket generation.');
        }

        $attemptId = bin2hex(random_bytes(16));
        $planned = new ObjectWriteIntent(
            attemptId: $attemptId,
            bucket: $bucket->name,
            bucketGeneration: $bucket->generation,
            ownerId: $bucket->ownerId,
            storageTier: $tier,
            storagePath: StoragePath::forPlannedObject($this->writer->storageRoot(), $object->bucket, $object->key, $bucket->generation, $attemptId),
            purpose: $purpose,
        );
        $lease = $this->leases->reserve($planned);
        try {
            $outcome = $this->writer->publishWithLease($lease->reservedIntent(), $object->key, $body, $lease, knownSize: $knownSize);
            if ($outcome->state !== LocalObjectWriteOutcome::COMPLETED || $outcome->result === null) {
                throw $outcome->error ?? new \RuntimeException('Background local write did not return a definite result.');
            }

            return [$outcome->result, $lease->stopForCommit()];
        } finally {
            $lease->close();
        }
    }
}
