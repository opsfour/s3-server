<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Lock;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Checksum\ChecksumResult;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Parallel\LocalMultipartPartWriteTask;
use OpsFour\S3Server\Parallel\LocalMultipartPartWriteTaskResult;

/**
 * @internal Isolated one-shot publisher. Handlers may bind it explicitly.
 */
final class LocalFencedMultipartPartWriter implements ShutdownAwareStorageBackend
{
    private readonly string $root;

    private readonly LocalScratchStager $stager;

    private readonly WorkerPool $writePool;

    private readonly LocalObjectWriteLeaseAdmission $admission;

    /** @var list<array{LocalScratchStage, Lock, Worker}> */
    private array $unknownSubmissions = [];

    private bool $shuttingDown = false;

    /** Bind a handler's exact metadata store, immutable tier and backend root. */
    public function assertBoundTo(MetadataStore $metadata, StorageBackend $backend, string $storageTier): void
    {
        $inner = $backend;
        while ($inner instanceof ObservedStorageBackend) {
            $inner = $inner->innerBackend();
        }
        if ($metadata !== $this->intents || $storageTier !== $this->storageTier
            || ! $inner instanceof FilesystemBackend
            || $inner->getBasePath() !== $this->root) {
            throw new \InvalidArgumentException('Multipart writer is not bound to this exact metadata store, tier and filesystem.');
        }
    }

    public function __construct(
        string $storageRoot,
        string $scratchDirectory,
        private readonly string $storageTier,
        private readonly FencedMultipartPartWriteIntentStore $intents,
        int $budgetBytes = 10_737_418_240,
        ?WorkerPool $budgetPool = null,
        private readonly ?LocalObjectWriteIo $workerIo = null,
        ?WorkerPool $writePool = null,
    ) {
        $root = realpath($storageRoot);
        if ($root === false || ! is_dir($root)) {
            throw new \InvalidArgumentException('Multipart writer root does not exist.');
        }
        $scratch = realpath($scratchDirectory);
        if ($scratch === false || $scratch === $root || str_starts_with($scratch, rtrim($root, '/') . '/')
            || $storageTier === '') {
            throw new \InvalidArgumentException('Multipart scratch and tier must be separate from the storage root.');
        }
        $this->root = rtrim($root, '/');
        $this->stager = new LocalScratchStager($scratch, $budgetBytes, $budgetPool);
        $this->writePool = $writePool ?? new ContextWorkerPool(2);
        $this->admission = new LocalObjectWriteLeaseAdmission(2);
    }

    /**
     * Reserve and arm exactly one SQL part intent after complete staging.
     * The caller must consume a successful dispatch in its part metadata
     * transaction. Any uncertain result leaves the armed row for the sealer.
     * For encrypted-v2, the caller must supply validated ciphertext and the
     * measured plaintext size; envelope and client checksum validation belong
     * before this method, not after the SQL dispatch.
     *
     * @param (callable(ChecksumResult): void)|null $validateBeforeArm
     */
    public function publish(
        string $bucket,
        string $generation,
        string $uploadId,
        int $partNumber,
        ReadableStream $body,
        ?Cancellation $cancellation = null,
        string $partFormat = EncryptedMultipartPartCodec::PLAINTEXT_FORMAT,
        ?int $logicalSize = null,
        ?callable $validateBeforeArm = null,
    ): LocalMultipartPartWriteOutcome {
        $attemptId = bin2hex(random_bytes(16));
        $path = StoragePath::forPlannedPart($this->root, $bucket, $generation, $uploadId, $partNumber, $attemptId);
        if (! in_array($partFormat, [EncryptedMultipartPartCodec::PLAINTEXT_FORMAT, EncryptedMultipartPartCodec::FORMAT], true)
            || ($partFormat === EncryptedMultipartPartCodec::FORMAT && ($logicalSize === null || $logicalSize < 0 || $logicalSize > MultipartPartSize::MAX_BYTES))
            || ($partFormat === EncryptedMultipartPartCodec::PLAINTEXT_FORMAT && $logicalSize !== null)) {
            throw new \InvalidArgumentException('Multipart physical format or logical size is invalid.');
        }
        $permit = null;
        $worker = null;
        $stage = null;
        $dispatchId = null;
        $reserved = false;
        try {
            $cancellation?->throwIfRequested();
            $this->assertActive();
            $permit = $this->admission->acquire();
            $worker = $this->checkoutWorker($cancellation);
            $physicalLimit = MultipartPartSize::MAX_BYTES
                + ($partFormat === EncryptedMultipartPartCodec::FORMAT ? EncryptedMultipartPartCodec::MAX_OVERHEAD : 0);
            $stage = $this->stager->stage($attemptId, MultipartPartSize::boundedBody($body, $physicalLimit), $cancellation, 's3fw-part-', true);
            if ($partFormat === EncryptedMultipartPartCodec::FORMAT) {
                if ($stage->size < $logicalSize + EncryptedMultipartPartCodec::MIN_OVERHEAD
                    || $stage->size > $logicalSize + EncryptedMultipartPartCodec::MAX_OVERHEAD) {
                    throw new \InvalidArgumentException('Multipart ciphertext size does not match its validated plaintext.');
                }
            } else {
                MultipartPartSize::assertStoredSize($stage->size);
            }
            if ($stage->checksums === null) {
                throw new \RuntimeException('Multipart staging did not return physical checksums.');
            }
            if ($validateBeforeArm !== null) {
                $validateBeforeArm($stage->checksums);
            }
            $cancellation?->throwIfRequested();
            $this->assertActive();
            $this->intents->reserveLocalMultipartPartWriteIntent($bucket, $generation, $this->storageTier, $uploadId, $path);
            $reserved = true;
            $dispatchId = bin2hex(random_bytes(16));
            if (! $this->intents->armLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId)) {
                throw new \RuntimeException('Multipart part intent could not be armed.');
            }
            $cancellation?->throwIfRequested();
            $this->assertActive();
        } catch (\Throwable $error) {
            if ($reserved) {
                try {
                    // No submit call occurred. An arm may already have
                    // committed, so retire its exact dispatch before falling
                    // back to the unarmed reservation CAS.
                    $retired = $dispatchId !== null
                        && $this->intents->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId);
                    if (! $retired) {
                        $this->intents->retireUndispatchedLocalMultipartPartWriteIntent($bucket, $uploadId, $path);
                    }
                } catch (\Throwable) {
                    // Keep a row whose SQL retirement could not be proven.
                }
            }
            try {
                if ($stage !== null) {
                    $this->stager->cleanup($stage);
                }
            } catch (\Throwable) {
                // This call is still definitely before dispatch. Preserve its
                // original outcome; corrupt scratch accounting makes later
                // reservations fail closed independently.
            } finally {
                unset($worker);
                $permit?->release();
            }
            return new LocalMultipartPartWriteOutcome($path, LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, null, null, $error);
        }

        try {
            $future = $worker->submit(new LocalMultipartPartWriteTask(
                $this->root,
                $stage->path,
                $stage->reservation->leasePath,
                $path,
                $bucket,
                $generation,
                $uploadId,
                $partNumber,
                $attemptId,
                $stage->size,
                $stage->sha256,
                $this->workerIo,
            ))->getFuture();
        } catch (\Throwable $error) {
            // A failed submission may still have reached the worker.
            $this->unknownSubmissions[] = [$stage, $permit, $worker];
            return new LocalMultipartPartWriteOutcome($path, LocalMultipartPartWriteOutcome::UNCERTAIN, $dispatchId, null, $error);
        }
        // A Future can fail because IPC died while a child process is still
        // alive. Keep scratch, its byte reservation and this worker handle in
        // that case; only a returned task result proves the child finished.
        $future->catch(function (\Throwable $error) use ($stage, $permit, $worker): null {
            $this->unknownSubmissions[] = [$stage, $permit, $worker];
            return null;
        })->ignore();
        $completed = $future->map(function (mixed $result) use ($stage, $permit): mixed {
            try {
                $this->stager->cleanup($stage);
            } finally {
                $permit->release();
            }
            return $result;
        });
        $completed->ignore();
        unset($worker);

        try {
            $result = $completed->await($cancellation);
            if (! $result instanceof LocalMultipartPartWriteTaskResult) {
                throw new \RuntimeException('Multipart worker returned an invalid result.');
            }
            if ($result->result !== null) {
                return new LocalMultipartPartWriteOutcome($path, LocalMultipartPartWriteOutcome::COMPLETED, $dispatchId, $result->result, null);
            }
            $error = new \RuntimeException(($result->failureClass ?? 'Multipart worker failure') . ': '
                . ($result->failureMessage ?? 'No result returned.'));
            if (! $result->publicationAttempted) {
                // A returned task result proves the child is terminal and its
                // tracked create-only link was never attempted. Retire only
                // this exact dispatch; IPC/cancellation uncertainty keeps it.
                try {
                    $this->intents->retireTerminalLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId);
                } catch (\Throwable) {
                    // Retain the intent for a collector if SQL is unavailable.
                }
            }
            return new LocalMultipartPartWriteOutcome(
                $path,
                $result->publicationAttempted ? LocalMultipartPartWriteOutcome::UNCERTAIN : LocalMultipartPartWriteOutcome::COMPLETED,
                $dispatchId,
                null,
                $error,
            );
        } catch (CancelledException $error) {
            return new LocalMultipartPartWriteOutcome($path, LocalMultipartPartWriteOutcome::UNCERTAIN, $dispatchId, null, $error);
        } catch (\Throwable $error) {
            return new LocalMultipartPartWriteOutcome($path, LocalMultipartPartWriteOutcome::UNCERTAIN, $dispatchId, null, $error);
        }
    }

    /** @phpstan-impure */
    private function checkoutWorker(?Cancellation $cancellation): Worker
    {
        $checkout = \Amp\async(function () use ($cancellation): ?Worker {
            $worker = $this->writePool->getWorker();
            return $cancellation?->isRequested() || $this->shuttingDown ? null : $worker;
        });
        $checkout->ignore();
        $worker = $checkout->await($cancellation);
        $cancellation?->throwIfRequested();
        if ($worker === null) {
            throw new \RuntimeException('Multipart worker checkout was canceled.');
        }
        return $worker;
    }

    private function assertActive(): void
    {
        if ($this->shuttingDown) {
            throw new \RuntimeException('Multipart writer is shutting down.');
        }
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;
        try {
            $this->writePool->shutdown();
        } catch (\Throwable $error) {
            // Worker exit was not proven. Preserve scratch and reservations
            // rather than let a child read bytes after they are reclaimed.
            throw new \RuntimeException('Multipart worker shutdown is uncertain; staged bytes remain reserved.', 0, $error);
        }
        foreach ($this->unknownSubmissions as [$stage, $permit, $worker]) {
            try {
                $this->stager->cleanup($stage);
            } finally {
                unset($worker);
                $permit->release();
            }
        }
        $this->unknownSubmissions = [];
        $this->stager->shutdown();
    }
}
