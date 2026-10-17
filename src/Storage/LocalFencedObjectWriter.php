<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\Sync\Lock;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Parallel\LocalObjectWriteTask;
use OpsFour\S3Server\Parallel\LocalObjectWriteTaskResult;

/**
 * Isolated writer for a separately committed SQL intent. The scratch directory
 * must be node-local and private; SSE callers must supply ciphertext. Amp file
 * workers stage bytes, then a process worker performs filesystem writes.
 * A canceled dispatched task may still publish later: the caller must retain
 * its SQL intent until the collector seals the exact path.
 * Scratch requires ext-posix, private process ownership and trusted parent
 * directories; path checks cannot prevent a concurrent directory swap by a
 * local process that can mutate those parents.
 */
final class LocalFencedObjectWriter implements ShutdownAwareStorageBackend
{
    private readonly string $root;

    private readonly WorkerPool $writePool;

    private readonly LocalScratchStager $stager;

    private readonly LocalObjectWriteLeaseAdmission $workerAdmission;

    /** @var list<array{LocalScratchStage, Lock, Worker}> */
    private array $unknownSubmissions = [];

    private bool $shuttingDown = false;

    public function __construct(
        string $storageRoot,
        string $scratchDirectory,
        private readonly string $storageTier,
        int $budgetBytes = 10_737_418_240,
        private readonly ?LocalObjectWriteIo $workerIo = null,
        ?WorkerPool $budgetPool = null,
        ?LocalObjectWriteLeaseAdmission $workerAdmission = null,
    ) {
        $root = realpath($storageRoot);
        if ($root === false || ! is_dir($root)) {
            throw new \InvalidArgumentException('Local object storage directory must exist.');
        }
        $scratch = realpath($scratchDirectory);
        if ($scratch === false || ! is_dir($scratch) || ! is_writable($scratch)) {
            throw new \InvalidArgumentException('Local object scratch directory must exist and be writable.');
        }
        if ($scratch === $root || str_starts_with($scratch, rtrim($root, '/') . '/')) {
            throw new \InvalidArgumentException('Local object scratch must be outside the storage root.');
        }
        $scratchPermissions = @fileperms($scratch);
        if ($scratchPermissions === false || ($scratchPermissions & 0077) !== 0) {
            throw new \InvalidArgumentException('Local object scratch directory must be private.');
        }
        $scratchOwner = @fileowner($scratch);
        if (! function_exists('posix_geteuid') || $scratchOwner === false || $scratchOwner !== posix_geteuid()) {
            throw new \InvalidArgumentException('Local object scratch directory must belong to the API process user.');
        }
        if ($storageTier === '') {
            throw new \InvalidArgumentException('Local object storage tier cannot be empty.');
        }
        if ($workerAdmission !== null && $workerAdmission->capacity() > 2) {
            throw new \InvalidArgumentException('Local object worker admission cannot exceed the two-worker pool.');
        }

        $this->root = rtrim($root, '/');
        $this->workerAdmission = $workerAdmission ?? new LocalObjectWriteLeaseAdmission(2);
        $this->stager = new LocalScratchStager($scratch, $budgetBytes, $budgetPool);
        $this->writePool = new ContextWorkerPool(2);
    }

    /**
     * Runtime entry for a separately committed intent. The lease guard arms
     * SQL only after worker checkout and complete staging, directly before
     * worker submit. A failed arm can never dispatch a physical write.
     */
    public function publishWithLease(
        ObjectWriteIntent $reserved,
        string $key,
        ReadableStream $body,
        LocalObjectWriteLeaseGuard $lease,
        ?Cancellation $cancellation = null,
    ): LocalObjectWriteOutcome {
        return $this->publishPrepared($reserved, $key, $body, $lease, $cancellation);
    }

    public function storageRoot(): string
    {
        return $this->root;
    }

    public function storageTierName(): string
    {
        return $this->storageTier;
    }

    /** @internal Legacy isolated primitive tests; runtime must use publishWithLease(). */
    public function publish(
        ObjectWriteIntent $reserved,
        string $key,
        ReadableStream $body,
        ?Cancellation $cancellation = null,
    ): LocalObjectWriteOutcome {
        return $this->publishPrepared($reserved, $key, $body, null, $cancellation);
    }

    private function publishPrepared(
        ObjectWriteIntent $reserved,
        string $key,
        ReadableStream $body,
        ?LocalObjectWriteLeaseGuard $lease,
        ?Cancellation $cancellation,
    ): LocalObjectWriteOutcome {
        $permit = null;
        $writeWorker = null;
        $stage = null;
        $armedToken = null;
        try {
            $cancellation?->throwIfRequested();
            $this->assertActive();
            if ($reserved->state !== 'active' || $reserved->storageTier !== $this->storageTier
                || $reserved->storagePath !== StoragePath::forPlannedObject(
                    $this->root,
                    $reserved->bucket,
                    $key,
                    $reserved->bucketGeneration,
                    $reserved->attemptId,
                )) {
                throw new \InvalidArgumentException('Local object write does not match its reserved tier and path.');
            }
            $lease?->assertReadyForStaging($reserved);
            // Reserve one of the two physical worker slots before staging.
            // There is no unbounded pool queue retaining scratch files or
            // waiting for a filesystem syscall held by another upload.
            $permit = $this->workerAdmission->acquire();
            $writeWorker = $this->checkoutWriteWorker($cancellation);
            $stage = $this->stager->stage($reserved->attemptId, $body, $cancellation);
            $cancellation?->throwIfRequested();
            $this->assertActive();
            if ($lease !== null) {
                $token = $lease->arm();
                if ($token->attemptId !== $reserved->attemptId
                    || ! preg_match('/^[0-9a-f]{32}$/D', $token->dispatchId)
                    || $token->dispatchId === ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH) {
                    throw new \RuntimeException('Local write arm returned a different attempt or invalid dispatch token.');
                }
                $armedToken = $token;
                $cancellation?->throwIfRequested();
                $this->assertActive();
            }
        } catch (\Throwable $error) {
            if ($armedToken !== null && $lease !== null) {
                // This branch precedes submit entirely. The exact armed
                // dispatch therefore cannot have a later worker publication.
                try {
                    $lease->retireTerminalPreLink($armedToken);
                } catch (\Throwable) {
                    // A failed CAS leaves the row for collector recovery.
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
                unset($writeWorker);
                $permit?->release();
            }
            return LocalObjectWriteOutcome::beforeDispatch($reserved, $error);
        }

        try {
            // Do not pass request cancellation to the worker: after dispatch,
            // the task may still make an ambiguous link on another host.
            $future = $writeWorker->submit(new LocalObjectWriteTask(
                $this->root,
                $reserved,
                $key,
                $stage->path,
                $stage->reservation->leasePath,
                $stage->size,
                $stage->sha256,
                $this->workerIo,
            ))->getFuture();
        } catch (\Throwable $error) {
            // A transport error cannot prove the task was never accepted.
            // Retain the worker as well as the slot until shutdown proves it
            // terminal; reusing either could queue a second late task.
            $this->unknownSubmissions[] = [$stage, $permit, $writeWorker];
            return LocalObjectWriteOutcome::uncertain($reserved, $error);
        }
        // A resolved task result proves the worker has stopped using its
        // scratch input. An IPC error does not: a child process may still
        // hold the file open after Amp has sent it SIGKILL. Keep its byte
        // reservation and worker slot until shutdown joins the worker.
        $future->map(function (mixed $result) use ($stage, $permit): void {
            try {
                $this->stager->cleanup($stage);
            } finally {
                $permit->release();
            }
        })->ignore();
        $future->catch(function (\Throwable $error) use ($stage, $permit, $writeWorker): void {
            $this->unknownSubmissions[] = [$stage, $permit, $writeWorker];
        })->ignore();
        unset($writeWorker);

        try {
            $worker = $future->await($cancellation);
            if (! $worker instanceof LocalObjectWriteTaskResult) {
                throw new \RuntimeException('Local object worker returned an invalid result.');
            }
            if ($worker->result !== null) {
                return LocalObjectWriteOutcome::completed($reserved, $worker->result);
            }
            $error = new \RuntimeException(($worker->failureClass ?? 'Local worker failure') . ': '
                . ($worker->failureMessage ?? 'No result returned.'));
            if ($worker->publicationAttempted) {
                return LocalObjectWriteOutcome::uncertain($reserved, $error);
            }
            if ($armedToken !== null && $lease !== null) {
                // A returned task result, unlike an IPC failure or cancellation,
                // proves this exact dispatched worker cannot link later.
                try {
                    $lease->retireTerminalPreLink($armedToken);
                } catch (\Throwable) {
                    // The armed row remains for a collector on SQL uncertainty.
                }
            }
            return LocalObjectWriteOutcome::completed($reserved, null, $error);
        } catch (CancelledException $error) {
            return LocalObjectWriteOutcome::uncertain($reserved, $error);
        } catch (\Throwable $error) {
            return LocalObjectWriteOutcome::uncertain($reserved, $error);
        }
    }

    /** @phpstan-impure */
    private function checkoutWriteWorker(?Cancellation $cancellation): Worker
    {
        $checkout = \Amp\async(function () use ($cancellation): ?Worker {
            $worker = $this->writePool->getWorker();
            if ($cancellation?->isRequested() || $this->shuttingDown) {
                unset($worker);
                return null;
            }

            return $worker;
        });
        $checkout->ignore();
        $worker = $checkout->await($cancellation);
        $cancellation?->throwIfRequested();
        if ($worker === null) {
            throw new \RuntimeException('Local object worker checkout was canceled.');
        }

        return $worker;
    }

    private function assertActive(): void
    {
        if ($this->shuttingDown) {
            throw new \RuntimeException('Local object writer is shutting down.');
        }
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;
        $this->writePool->shutdown();
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
