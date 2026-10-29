<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Parallel\PostObjectSpoolTask;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageTempReservation;

use function Amp\Parallel\Worker\workerPool;

/** Browser POST/checksum compatibility facade over the single node budget. */
final class PostObjectSpoolCoordinator
{
    private readonly string $tempDir;

    private readonly ?WorkerPool $pool;

    private ?StorageTempBudgetCoordinator $coordinator = null;

    private ?int $boundBudgetBytes = null;

    /** @internal $runInline is retained for compatibility with synchronous tests. */
    public function __construct(
        ?string $tempDir = null,
        ?WorkerPool $pool = null,
        bool $runInline = false,
        ?int $budgetBytes = null,
        private readonly int $filesystemReserveBytes = StorageTempBudgetCoordinator::DEFAULT_FILESYSTEM_RESERVE_BYTES,
    ) {
        $directory = $tempDir ?? sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'opsfour-s3-server-temp';
        if ($tempDir === null && ! is_dir($directory)
            && ! @mkdir($directory, 0o700, true) && ! is_dir($directory)) {
            throw new \RuntimeException('Request temporary directory must exist.');
        }
        $resolved = realpath($directory);
        if ($resolved === false) {
            throw new \RuntimeException('Request temporary directory must exist.');
        }
        $this->tempDir = rtrim($resolved, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
        $identity = @lstat($this->tempDir);
        if ($identity === false || ($identity['mode'] & 0o170000) !== 0o040000
            || (($identity['mode'] & 0o022) !== 0 && ($identity['mode'] & 0o1000) === 0)) {
            throw new \RuntimeException('Request temporary directory must be private or sticky.');
        }
        $this->pool = $runInline ? null : ($pool ?? workerPool());
        if ($budgetBytes !== null) {
            $this->bind($budgetBytes);
        }
    }

    public function tempDir(): string
    {
        return $this->tempDir;
    }

    /** @internal Shared with the browser-upload sweeper bound to this coordinator. */
    public function workerPool(): ?WorkerPool
    {
        return $this->pool;
    }

    /** @internal Bind request-spool admission before its first managed file is created. */
    public function bindBudget(int $budgetBytes): void
    {
        $this->bind($budgetBytes);
    }

    public function recoverAbandoned(): void
    {
        if ($this->coordinator === null) {
            return;
        }
        if ($this->pool !== null) {
            $this->taskValue($this->pool->submit(new PostObjectSpoolTask(
                'recover',
                $this->tempDir,
                budgetBytes: $this->boundBudgetBytes ?? StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
                filesystemReserveBytes: $this->filesystemReserveBytes,
            ))->await());

            return;
        }
        $this->coordinator->snapshot();
    }

    public function start(string $tempPath): PostObjectSpoolReservation
    {
        if (realpath(dirname($tempPath)) !== $this->tempDir
            || preg_match('/^s3-(?:post|md5|sha256)-[A-Za-z0-9._-]+$/D', basename($tempPath)) !== 1) {
            throw new \InvalidArgumentException('Request temporary path is outside its managed directory.');
        }
        $reservation = new PostObjectSpoolReservation(
            $this->tempDir . DIRECTORY_SEPARATOR . basename($tempPath),
        );
        if ($this->coordinator !== null && $this->pool !== null) {
            $execution = $this->pool->submit(new PostObjectSpoolTask(
                'start',
                $this->tempDir,
                $reservation->tempPath,
                budgetBytes: $this->boundBudgetBytes ?? StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
                filesystemReserveBytes: $this->filesystemReserveBytes,
            ));
            $channel = $execution->getChannel();
            $signal = $channel->receive();
            if (! is_array($signal) || ($signal['ok'] ?? false) !== true || ! is_string($signal['value'] ?? null)) {
                try {
                    $channel->send(false);
                } catch (\Throwable) {
                }
                $this->taskValue($execution->await());
                throw new \RuntimeException('Request temporary reservation worker returned an invalid lease.');
            }
            $separator = strrpos($signal['value'], ':');
            $leasePath = $separator === false ? '' : substr($signal['value'], 0, $separator);
            $nonce = $separator === false ? '' : substr($signal['value'], $separator + 1);
            if (preg_match('/^[a-f0-9]{32}$/D', $nonce) !== 1) {
                $channel->send(false);
                $this->taskValue($execution->await());
                throw new \RuntimeException('Request temporary reservation worker returned an invalid capability.');
            }
            $handle = @fopen($leasePath, 'r+b');
            $locked = $handle !== false && flock($handle, LOCK_SH | LOCK_NB);
            try {
                $channel->send($locked);
                $completedPath = $this->taskValue($execution->await());
                if (! $locked || ! is_string($completedPath) || $completedPath !== $signal['value']) {
                    throw new \RuntimeException('Cannot lock request temporary reservation.');
                }
            } catch (\Throwable $error) {
                if (is_resource($handle)) {
                    fclose($handle);
                }
                throw $error;
            }
            $reservation->attach(new StorageTempReservation(
                $leasePath,
                $reservation->tempPath,
                'request_spool',
                $nonce,
                $handle,
            ));
        } elseif ($this->coordinator !== null) {
            $reservation->attach($this->coordinator->start($reservation->tempPath, 'request_spool'));
        }

        return $reservation;
    }

    public function reserve(PostObjectSpoolReservation $reservation, int $bytes, int $budgetBytes): void
    {
        if ($bytes < 0 || $budgetBytes < 1 || ! $reservation->isOpen()) {
            throw new \InvalidArgumentException('Invalid request temporary reservation.');
        }
        $coordinator = $this->bind($budgetBytes);
        $reservation->bindBudget($budgetBytes);
        if ($reservation->storageReservation() === null) {
            $reservation->attach($coordinator->start($reservation->tempPath, 'request_spool'));
        }
        $storageReservation = $reservation->storageReservation();
        if ($storageReservation === null) {
            throw new \LogicException('Request temporary reservation was not attached.');
        }
        if ($this->pool !== null) {
            $next = $this->taskValue($this->pool->submit(new PostObjectSpoolTask(
                'reserve',
                $this->tempDir,
                $reservation->tempPath,
                $storageReservation->leasePath,
                nonce: $storageReservation->nonce,
                reservedBytes: $reservation->bytes(),
                bytes: $bytes,
                budgetBytes: $budgetBytes,
                filesystemReserveBytes: $this->filesystemReserveBytes,
            ))->await());
            if (! is_int($next)) {
                throw new \RuntimeException('Request temporary reservation worker returned an invalid size.');
            }
            $reservation->addBytes($next - $reservation->bytes());
        } else {
            $coordinator->reserve($storageReservation, $bytes);
            $reservation->addBytes($bytes);
        }
    }

    public function release(PostObjectSpoolReservation $reservation, bool $tempDeleted): void
    {
        if (! $reservation->isOpen()) {
            return;
        }
        $storageReservation = $reservation->storageReservation();
        try {
            if ($storageReservation !== null && $tempDeleted) {
                if ($this->pool !== null) {
                    $this->taskValue($this->pool->submit(new PostObjectSpoolTask(
                        'release',
                        $this->tempDir,
                        $reservation->tempPath,
                        $storageReservation->leasePath,
                        nonce: $storageReservation->nonce,
                        reservedBytes: $reservation->bytes(),
                        budgetBytes: $reservation->budgetBytes()
                            ?? $this->boundBudgetBytes
                            ?? StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
                        filesystemReserveBytes: $this->filesystemReserveBytes,
                    ))->await());
                } else {
                    $this->coordinator?->release($storageReservation);
                }
            }
        } finally {
            $reservation->close();
        }
    }

    /** Remove the exact reserved inode and release its accounting. */
    public function cleanup(PostObjectSpoolReservation $reservation): bool
    {
        if (! $reservation->isOpen()) {
            return false;
        }
        $storageReservation = $reservation->storageReservation();
        try {
            if ($storageReservation === null) {
                return false;
            }
            if ($this->pool !== null) {
                $result = $this->taskValue($this->pool->submit(new PostObjectSpoolTask(
                    'cleanup',
                    $this->tempDir,
                    $reservation->tempPath,
                    $storageReservation->leasePath,
                    nonce: $storageReservation->nonce,
                    reservedBytes: $reservation->bytes(),
                    budgetBytes: $reservation->budgetBytes()
                        ?? $this->boundBudgetBytes
                        ?? StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
                    filesystemReserveBytes: $this->filesystemReserveBytes,
                ))->await());

                return $result === 1;
            }

            return $this->coordinator?->cleanup($storageReservation) ?? false;
        } finally {
            $reservation->close();
        }
    }

    public function close(): void
    {
        $this->coordinator?->close();
    }

    public function __destruct()
    {
        $this->close();
    }

    private function bind(int $budgetBytes): StorageTempBudgetCoordinator
    {
        if ($budgetBytes < 1) {
            throw new \InvalidArgumentException('Request temporary budget must be positive.');
        }
        if ($this->boundBudgetBytes !== null && $this->boundBudgetBytes !== $budgetBytes) {
            throw new \RuntimeException('Processes sharing a node temporary directory must use the same budget.');
        }
        $this->boundBudgetBytes = $budgetBytes;

        return $this->coordinator ??= new StorageTempBudgetCoordinator(
            $this->tempDir,
            $budgetBytes,
            $this->filesystemReserveBytes,
        );
    }

    /** @param array{ok: bool, value?: int|string|null, type?: class-string<\Throwable>, message?: string} $result */
    private function taskValue(array $result): int|string|null
    {
        if ($result['ok']) {
            return $result['value'] ?? null;
        }
        $message = $result['message'] ?? 'Request temporary reservation worker failed.';
        throw match ($result['type'] ?? '') {
            SlowDownException::class => new SlowDownException($message),
            \InvalidArgumentException::class => new \InvalidArgumentException($message),
            default => new \RuntimeException($message),
        };
    }
}
