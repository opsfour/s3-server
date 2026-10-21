<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Parallel\LocalStageBudgetReserveTask;

/**
 * Stages exactly the supplied bytes on private scratch. The caller must bind
 * this directory to trusted storage on the same local filesystem as its
 * publisher.
 * Retain a successful stage until its physical worker is terminal; SSE
 * callers must supply ciphertext before calling stage().
 */
final class LocalScratchStager
{
    private const int STAGE_BLOCK_BYTES = 65_536;

    private readonly string $scratch;

    private readonly StorageTempBudgetCoordinator $budget;

    private readonly WorkerPool $stagePool;

    private readonly WorkerPool $budgetPool;

    private readonly bool $ownsBudgetPool;

    private readonly File\Filesystem $stageFilesystem;

    /** @var \WeakMap<LocalScratchStage, bool> */
    private readonly \WeakMap $issuedStages;

    private bool $shuttingDown = false;

    public function __construct(
        string $scratchDirectory,
        private readonly int $budgetBytes = 10_737_418_240,
        ?WorkerPool $budgetPool = null,
    ) {
        $scratch = realpath($scratchDirectory);
        if ($scratch === false || ! is_dir($scratch) || ! is_writable($scratch)) {
            throw new \InvalidArgumentException('Local scratch directory must exist and be writable.');
        }
        $permissions = @fileperms($scratch);
        if ($permissions === false || ($permissions & 0077) !== 0
            || ! function_exists('posix_geteuid') || @fileowner($scratch) !== posix_geteuid()) {
            throw new \InvalidArgumentException('Local scratch directory must be private and process-owned.');
        }
        $this->scratch = rtrim($scratch, '/');
        $this->budget = new StorageTempBudgetCoordinator($this->scratch, $budgetBytes);
        $this->stagePool = new ContextWorkerPool(2);
        $this->ownsBudgetPool = $budgetPool === null;
        $this->budgetPool = $budgetPool ?? new ContextWorkerPool(2);
        $this->stageFilesystem = new File\Filesystem(new ParallelFilesystemDriver($this->stagePool));
        $this->issuedStages = new \WeakMap();
    }

    /** @phpstan-impure */
    public function stage(
        string $attemptId,
        ReadableStream $body,
        ?Cancellation $cancellation = null,
        string $prefix = 's3fw-put-',
        bool $fullChecksums = false,
    ): LocalScratchStage {
        if ($this->shuttingDown || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)
            || ! preg_match('/^s3fw-(?:put|part|get|mpu)-$/D', $prefix)) {
            throw new \InvalidArgumentException('Invalid local scratch staging request.');
        }
        $cancellation?->throwIfRequested();
        $path = $this->scratch . '/' . $prefix . $attemptId . bin2hex(random_bytes(16));
        $file = null;
        $reservation = null;
        try {
            $file = $this->stageFilesystem->openFile($path, 'x+b');
            if (! @chmod($path, 0600)) {
                throw new \RuntimeException('Failed to protect local object staging file.');
            }
            $reservation = $this->budget->start($path);
            $hash = $fullChecksums ? new ChecksumCalculator() : hash_init('sha256');
            $size = 0;
            $pending = '';
            while (($chunk = $body->read($cancellation)) !== null) {
                $cancellation?->throwIfRequested();
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $cancellation?->throwIfRequested();
                    if ($pending === '' && $length - $offset >= self::STAGE_BLOCK_BYTES) {
                        $block = substr($chunk, $offset, self::STAGE_BLOCK_BYTES);
                        $size += $this->writeBlock($file, $reservation, $hash, $block, $cancellation);
                        $offset += self::STAGE_BLOCK_BYTES;
                        continue;
                    }
                    $take = min(self::STAGE_BLOCK_BYTES - strlen($pending), $length - $offset);
                    $pending .= substr($chunk, $offset, $take);
                    $offset += $take;
                    if (strlen($pending) === self::STAGE_BLOCK_BYTES) {
                        $size += $this->writeBlock($file, $reservation, $hash, $pending, $cancellation);
                        $pending = '';
                    }
                }
            }
            if ($pending !== '') {
                $size += $this->writeBlock($file, $reservation, $hash, $pending, $cancellation);
            }
            $file->close();
            $file = null;
            clearstatcache(true, $path);
            if (@filesize($path) !== $size) {
                throw new \RuntimeException('Local object staging file is incomplete.');
            }
            $cancellation?->throwIfRequested();

            $result = $hash instanceof ChecksumCalculator ? $hash->finalize() : null;
            if ($result !== null) {
                $sha256 = hex2bin($result->sha256Hex);
                if ($result->size !== $size || $sha256 === false) {
                    throw new \RuntimeException('Local object staging checksum is incomplete.');
                }
            } else {
                $sha256 = hash_final($hash, true);
            }
            $stage = new LocalScratchStage($path, $reservation, $size, $sha256, $result);
            $this->issuedStages[$stage] = true;

            return $stage;
        } catch (\Throwable $error) {
            try {
                $file?->close();
            } catch (\Throwable) {
                // The original staging failure determines the safe outcome.
            }
            $this->deleteStage($path);
            if ($reservation !== null) {
                try {
                    $this->budget->release($reservation);
                } catch (\Throwable $releaseError) {
                    if (! $error instanceof CancelledException) {
                        throw $releaseError;
                    }
                }
            }
            throw $error;
        }
    }

    public function cleanup(LocalScratchStage $stage): void
    {
        if (! isset($this->issuedStages[$stage])
            || $stage->path !== $stage->reservation->tempPath
            || preg_match('~^' . preg_quote($this->scratch, '~') . '/s3fw-(?:put|part|get|mpu)-[a-f0-9]{64}$~D', $stage->path) !== 1) {
            throw new \InvalidArgumentException('Local scratch stage does not match its reservation.');
        }
        try {
            $this->deleteStage($stage->path);
        } finally {
            $this->budget->release($stage->reservation);
        }
        unset($this->issuedStages[$stage]);
    }

    public function shutdown(): void
    {
        $this->shuttingDown = true;
        // If stage shutdown fails, its pending operations may still reserve
        // bytes. Keep the budget pool available until they are terminal.
        $this->stagePool->shutdown();
        if ($this->ownsBudgetPool) {
            $this->budgetPool->shutdown();
        }
    }

    private function writeBlock(
        File\File $file,
        StorageTempReservation $reservation,
        ChecksumCalculator|\HashContext $hash,
        string $block,
        ?Cancellation $cancellation,
    ): int {
        $bytes = strlen($block);
        $this->reserveBytes($reservation, $bytes, $cancellation);
        $file->write($block);
        if ($hash instanceof ChecksumCalculator) {
            $hash->update($block);
        } else {
            hash_update($hash, $block);
        }

        return $bytes;
    }

    private function reserveBytes(StorageTempReservation $reservation, int $bytes, ?Cancellation $cancellation): void
    {
        $cancellation?->throwIfRequested();
        // Checkout separately: a canceled wait must not dispatch a late
        // reservation after the caller has released the lease.
        $checkout = \Amp\async(function () use ($cancellation): ?Worker {
            $worker = $this->budgetPool->getWorker();
            if ($cancellation?->isRequested()) {
                unset($worker);
                return null;
            }

            return $worker;
        });
        $checkout->ignore();
        $worker = $checkout->await($cancellation);
        $cancellation?->throwIfRequested();
        if ($worker === null) {
            throw new \RuntimeException('Local staging reservation worker checkout was canceled.');
        }
        $reserveFuture = $worker->submit(new LocalStageBudgetReserveTask(
            $this->scratch,
            $this->budgetBytes,
            $reservation->leasePath,
            $reservation->tempPath,
            $bytes,
        ))->getFuture();
        $reserveFuture->ignore();
        $reserveFuture->await($cancellation);
    }

    private function deleteStage(string $path): void
    {
        try {
            if ($this->stageFilesystem->exists($path)) {
                $this->stageFilesystem->deleteFile($path);
            }
        } catch (\Throwable) {
            @unlink($path);
        }
    }
}
