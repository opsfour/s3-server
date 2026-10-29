<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\File;
use Amp\File\Driver\ParallelFilesystemDriver;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\SlowDownException;
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

    private const int RESERVATION_QUANTUM_BYTES = 4_194_304;

    private readonly string $scratch;

    private readonly StorageTempBudgetCoordinator $budget;

    private readonly bool $ownsBudget;

    private readonly WorkerPool $stagePool;

    private readonly WorkerPool $budgetPool;

    private readonly bool $ownsBudgetPool;

    private readonly File\Filesystem $stageFilesystem;

    /** @var \WeakMap<LocalScratchStage, bool> */
    private \WeakMap $issuedStages;

    private bool $shuttingDown = false;

    public function __construct(
        string $scratchDirectory,
        private readonly int $budgetBytes = 10_737_418_240,
        ?WorkerPool $budgetPool = null,
        private readonly int $filesystemReserveBytes = StorageTempBudgetCoordinator::DEFAULT_FILESYSTEM_RESERVE_BYTES,
        ?StorageTempBudgetCoordinator $tempBudget = null,
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
        $this->ownsBudget = $tempBudget === null;
        $this->budget = $tempBudget ?? new StorageTempBudgetCoordinator($this->scratch, $budgetBytes, $filesystemReserveBytes);
        if ($this->budget->budgetBytes() !== $budgetBytes
            || $this->budget->filesystemReserveBytes() !== $filesystemReserveBytes) {
            throw new \InvalidArgumentException('Local scratch temporary capacity differs from the node contract.');
        }
        $namespace = 'scratch_' . substr(hash('sha256', $this->scratch), 0, 24);
        if ($this->budget->tempDir() !== $this->scratch) {
            $this->budget->registerNamespace($namespace, $this->scratch);
        }
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
        ?\Closure $heartbeat = null,
        float $heartbeatIntervalSeconds = 60.0,
        ?int $knownSize = null,
        ?StorageTempReservation $capacity = null,
    ): LocalScratchStage {
        if ($this->shuttingDown || ! preg_match('/^[a-f0-9]{32}$/D', $attemptId)
            || ! preg_match('/^s3fw-(?:put|part|get|mpu)-$/D', $prefix)
            || ($knownSize !== null && $knownSize < 0)
            || ($capacity !== null && $knownSize === null)
            || ($heartbeat !== null && (! is_finite($heartbeatIntervalSeconds)
                || $heartbeatIntervalSeconds <= 0.0 || $heartbeatIntervalSeconds > 86400.0))) {
            throw new \InvalidArgumentException('Invalid local scratch staging request.');
        }
        $cancellation?->throwIfRequested();
        $path = $this->scratch . '/' . $prefix . $attemptId . bin2hex(random_bytes(16));
        $file = null;
        $fileLease = null;
        $reservation = null;
        try {
            if ($capacity !== null) {
                $this->budget->retarget($capacity, $path, 'storage');
                $reservation = $capacity;
            } else {
                $reservation = $this->budget->start($path);
            }
            $fileLease = StorageTempFileLease::acquireExisting($path);
            $file = $this->stageFilesystem->openFile($path, 'c+');
            $fileLease->assertPath($path);
            if (! @chmod($path, 0600)) {
                throw new \RuntimeException('Failed to protect local object staging file.');
            }
            $reservedBytes = 0;
            if ($knownSize !== null && $knownSize > 0 && $capacity === null) {
                $this->reserveBytes($reservation, $knownSize, $cancellation);
            }
            $reservedBytes = $knownSize ?? 0;
            $hash = $fullChecksums ? new ChecksumCalculator() : hash_init('sha256');
            $size = 0;
            $pending = '';
            $heartbeatIntervalNanos = (int) min(PHP_INT_MAX, ceil($heartbeatIntervalSeconds * 1_000_000_000));
            $nextHeartbeat = hrtime(true) + $heartbeatIntervalNanos;
            $runHeartbeat = static function () use ($heartbeat, $heartbeatIntervalNanos, &$nextHeartbeat): void {
                if ($heartbeat === null || hrtime(true) < $nextHeartbeat) {
                    return;
                }
                $heartbeat();
                $nextHeartbeat = hrtime(true) + $heartbeatIntervalNanos;
            };
            while (true) {
                $runHeartbeat();
                $chunk = $body->read($cancellation);
                $runHeartbeat();
                if ($chunk === null) {
                    break;
                }
                $cancellation?->throwIfRequested();
                $offset = 0;
                $length = strlen($chunk);
                while ($offset < $length) {
                    $runHeartbeat();
                    $cancellation?->throwIfRequested();
                    if ($pending === '' && $length - $offset >= self::STAGE_BLOCK_BYTES) {
                        $block = substr($chunk, $offset, self::STAGE_BLOCK_BYTES);
                        $size += $this->writeBlock($file, $reservation, $hash, $block, $reservedBytes, $cancellation);
                        $offset += self::STAGE_BLOCK_BYTES;
                        continue;
                    }
                    $take = min(self::STAGE_BLOCK_BYTES - strlen($pending), $length - $offset);
                    $pending .= substr($chunk, $offset, $take);
                    $offset += $take;
                    if (strlen($pending) === self::STAGE_BLOCK_BYTES) {
                        $size += $this->writeBlock($file, $reservation, $hash, $pending, $reservedBytes, $cancellation);
                        $pending = '';
                    }
                }
            }
            if ($pending !== '') {
                $size += $this->writeBlock($file, $reservation, $hash, $pending, $reservedBytes, $cancellation);
            }
            $file->close();
            $file = null;
            $fileLease->release();
            $fileLease = null;
            clearstatcache(true, $path);
            if (@filesize($path) !== $size) {
                throw new \RuntimeException('Local object staging file is incomplete.');
            }
            if ($reservedBytes > 0) {
                $this->budget->releaseUnused($reservation, $reservedBytes);
                $reservedBytes = 0;
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
            $fileLease?->release();
            if ($reservation !== null) {
                try {
                    $this->budget->cleanup($reservation);
                } catch (\Throwable) {
                    // Preserve the staging failure. An unreleased or corrupt
                    // reservation remains charged and closes later admission.
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
            $this->budget->cleanup($stage->reservation);
        } finally {
            $stage->reservation->close();
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
        if ($this->ownsBudget) {
            $this->budget->close();
        }
    }

    private function writeBlock(
        File\File $file,
        StorageTempReservation $reservation,
        ChecksumCalculator|\HashContext $hash,
        string $block,
        int &$reservedBytes,
        ?Cancellation $cancellation,
    ): int {
        $bytes = strlen($block);
        if ($bytes > $reservedBytes) {
            $needed = $bytes - $reservedBytes;
            $admitted = max($needed, min($this->budgetBytes, self::RESERVATION_QUANTUM_BYTES));
            try {
                $this->reserveBytes($reservation, $admitted, $cancellation);
            } catch (SlowDownException $error) {
                if ($admitted === $needed) {
                    throw $error;
                }
                $admitted = $needed;
                $this->reserveBytes($reservation, $admitted, $cancellation);
            } catch (TaskFailureThrowable $error) {
                if ($error->getOriginalClassName() !== SlowDownException::class || $admitted === $needed) {
                    throw $error;
                }
                $admitted = $needed;
                $this->reserveBytes($reservation, $admitted, $cancellation);
            }
            $reservedBytes += $admitted;
        }
        $file->write($block);
        $reservedBytes -= $bytes;
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
            $this->budget->tempDir(),
            $this->budgetBytes,
            $reservation->leasePath,
            $reservation->tempPath,
            $reservation->nonce,
            $bytes,
            $this->filesystemReserveBytes,
        ))->getFuture();
        $reserveFuture->ignore();
        $reserveFuture->await($cancellation);
    }

}
