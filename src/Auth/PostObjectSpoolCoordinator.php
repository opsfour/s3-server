<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Cancellation;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Parallel\PostObjectSpoolTask;

use function Amp\Parallel\Worker\workerPool;

/**
 * Coordinates browser POST temporary bytes between API processes on one host.
 *
 * A short exclusive lock serializes reservations. Each live form keeps a
 * shared lock on its lease file; a process crash releases that lock so the
 * next reservation can remove its abandoned temporary file and lease.
 */
final class PostObjectSpoolCoordinator
{
    private readonly string $leaseDir;

    private readonly string $tempDir;

    /** @var array<string, PostObjectSpoolReservation> */
    private array $activeReservations = [];

    private readonly ?WorkerPool $pool;

    /** @internal $runInline is reserved for PostObjectSpoolTask. */
    public function __construct(?string $tempDir = null, ?WorkerPool $pool = null, bool $runInline = false)
    {
        $resolvedTempDir = realpath($tempDir ?? sys_get_temp_dir());
        if ($resolvedTempDir === false) {
            throw new \RuntimeException('Browser POST temporary directory must exist.');
        }
        $this->tempDir = rtrim($resolvedTempDir, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
        $tempPermissions = @fileperms($this->tempDir);
        if (! is_dir($this->tempDir) || $tempPermissions === false
            || (($tempPermissions & 0o022) !== 0 && ($tempPermissions & 0o1000) === 0)) {
            throw new \RuntimeException('Browser POST temporary directory must be private or sticky.');
        }
        $this->leaseDir = $this->tempDir . DIRECTORY_SEPARATOR . '.opsfour-s3-post-budget';
        if (! is_dir($this->leaseDir) && ! @mkdir($this->leaseDir, 0700) && ! is_dir($this->leaseDir)) {
            throw new \RuntimeException('Cannot create browser POST reservation directory.');
        }
        $permissions = fileperms($this->leaseDir);
        $owner = fileowner($this->leaseDir);
        if (is_link($this->leaseDir) || $permissions === false || ($permissions & 0077) !== 0
            || (function_exists('posix_geteuid') && ($owner === false || $owner !== posix_geteuid()))) {
            throw new \RuntimeException('Browser POST reservation directory must be private.');
        }
        $this->pool = $runInline ? null : ($pool ?? workerPool());
    }

    public function tempDir(): string
    {
        return $this->tempDir;
    }

    public function recoverAbandoned(): void
    {
        if ($this->pool !== null) {
            $this->taskValue($this->pool->submit(new PostObjectSpoolTask('recover', $this->tempDir))->await());

            return;
        }

        $this->recoverAbandonedInline();
    }

    /** @internal Executed only inside PostObjectSpoolTask. */
    public function recoverAbandonedInline(): void
    {
        $this->withLock(function (): void {
            $this->repairAccountingIfDirty();
            $this->markAccountingDirty();
            $this->reapAbandonedLeases();
            $this->writeTotal($this->sumLeases());
            $this->clearAccountingDirty();
        });
    }

    public function start(string $tempPath): PostObjectSpoolReservation
    {
        $tempPath = $this->validatedTempPath($tempPath);
        if ($this->pool === null) {
            $reservation = $this->startInline($tempPath);
            if (! $reservation instanceof PostObjectSpoolReservation) {
                throw new \LogicException('Inline browser POST reservation returned an invalid result.');
            }

            return $reservation;
        }

        $execution = $this->pool->submit(new PostObjectSpoolTask('start', $this->tempDir, $tempPath));
        $channel = $execution->getChannel();
        $signal = $channel->receive();
        if (! is_array($signal) || ($signal['ok'] ?? false) !== true || ! is_string($signal['value'] ?? null)) {
            // A worker that already announced a lease is waiting for this
            // acknowledgement while it still owns the global accounting lock.
            // Always reject malformed announcements before joining the task.
            try {
                $channel->send(false);
            } catch (\Throwable) {
                // A negative worker result may already have closed the channel.
            }
            $this->taskValue($execution->await());
            throw new \RuntimeException('Browser POST reservation worker returned an invalid lease.');
        }
        $leasePath = $signal['value'];

        $handle = @fopen($leasePath, 'r+');
        $locked = $handle !== false && flock($handle, LOCK_SH | LOCK_NB);
        try {
            $channel->send($locked);
            $completedPath = $this->taskValue($execution->await());
            if (! $locked || ! is_string($completedPath) || $completedPath !== $leasePath) {
                throw new \RuntimeException('Cannot lock browser POST reservation.');
            }
        } catch (\Throwable $error) {
            if (is_resource($handle)) {
                fclose($handle);
            }
            throw $error;
        }

        $reservation = new PostObjectSpoolReservation($leasePath, $tempPath, $handle);
        $this->activeReservations[$leasePath] = $reservation;

        return $reservation;
    }

    /**
     * @internal Executed only inside PostObjectSpoolTask.
     * @param \Amp\Sync\Channel<bool, array{ok: bool, value?: string, type?: class-string<\Throwable>, message?: string}>|null $channel
     */
    public function startInline(
        string $tempPath,
        ?\Amp\Sync\Channel $channel = null,
        ?Cancellation $cancellation = null,
    ): PostObjectSpoolReservation|string {
        $tempPath = $this->validatedTempPath($tempPath);
        $announced = false;

        try {
            return $this->withLock(function () use ($tempPath, $channel, $cancellation, &$announced): PostObjectSpoolReservation|string {
                $this->repairAccountingIfDirty();
                // Crash leftovers must not accumulate indefinitely when traffic
                // stays below the byte limit (including zero-byte uploads).
                $this->reapAndRecount();
                $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'lease-' . bin2hex(random_bytes(16));
                $this->writeLease($path, $tempPath, 0);
                $handle = @fopen($path, 'x+');
                if ($handle === false) {
                    @unlink($this->statePath($path));
                    throw new \RuntimeException('Cannot create browser POST reservation.');
                }
                try {
                    if (! flock($handle, LOCK_SH | LOCK_NB)) {
                        throw new \RuntimeException('Cannot lock browser POST reservation.');
                    }

                    if ($channel !== null) {
                        $announced = true;
                        $channel->send(['ok' => true, 'value' => $path]);
                        if ($channel->receive($cancellation) !== true) {
                            throw new \RuntimeException('Cannot transfer browser POST reservation.');
                        }
                        fclose($handle);

                        return $path;
                    }

                    $reservation = new PostObjectSpoolReservation($path, $tempPath, $handle);
                    $this->activeReservations[$path] = $reservation;

                    return $reservation;
                } catch (\Throwable $error) {
                    fclose($handle);
                    @unlink($path);
                    @unlink($this->statePath($path));
                    throw $error;
                }
            });
        } catch (\Throwable $error) {
            if ($channel !== null && ! $announced) {
                $channel->send([
                    'ok' => false,
                    'type' => $error::class,
                    'message' => $error->getMessage(),
                ]);
            }
            throw $error;
        }
    }

    public function reserve(PostObjectSpoolReservation $reservation, int $bytes, int $budgetBytes): void
    {
        if ($bytes < 0 || $budgetBytes < 1 || ! $reservation->isOpen()) {
            throw new \InvalidArgumentException('Invalid browser POST reservation.');
        }
        if ($bytes === 0) {
            return;
        }

        if ($this->pool !== null) {
            $next = $this->taskValue($this->pool->submit(new PostObjectSpoolTask(
                'reserve',
                $this->tempDir,
                $reservation->tempPath,
                $reservation->leasePath,
                $reservation->bytes(),
                $bytes,
                $budgetBytes,
            ))->await());
            if (! is_int($next)) {
                throw new \RuntimeException('Browser POST reservation worker returned an invalid size.');
            }
            $reservation->setBytes($next);

            return;
        }

        $reservation->setBytes($this->reserveInline(
            $reservation->leasePath,
            $reservation->tempPath,
            $reservation->bytes(),
            $bytes,
            $budgetBytes,
        ));
    }

    /** @internal Executed only inside PostObjectSpoolTask. */
    public function reserveInline(
        string $leasePath,
        string $tempPath,
        int $_reservedBytes,
        int $bytes,
        int $budgetBytes,
    ): int {
        $tempPath = $this->validatedTempPath($tempPath);
        $leasePath = $this->validatedLeasePath($leasePath);

        return $this->withLock(function () use ($leasePath, $tempPath, $bytes, $budgetBytes): int {
            $this->repairAccountingIfDirty();
            $this->assertSharedBudget($leasePath, $budgetBytes);
            $lease = $this->readLease($leasePath);
            if ($lease['tempPath'] !== $tempPath) {
                throw new \RuntimeException('Browser POST reservation does not match its temporary file.');
            }
            $total = $this->readTotal();
            if ($bytes > $budgetBytes - $total) {
                $total = $this->reapAndRecount();
            }
            if ($bytes > $budgetBytes - $total) {
                throw new SlowDownException('Browser upload temporary storage budget is full.');
            }

            $next = $lease['bytes'] + $bytes;
            $this->markAccountingDirty();
            try {
                $this->writeLease($leasePath, $tempPath, $next);
                $this->writeTotal($total + $bytes);
                $this->clearAccountingDirty();
            } catch (\Throwable $error) {
                // The marker intentionally remains. The next operation
                // reconstructs the total from the atomically replaced leases.
                throw $error;
            }

            return $next;
        });
    }

    private function assertSharedBudget(string $ownLeasePath, int $budgetBytes): void
    {
        $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'budget';
        $stored = @file_get_contents($path);
        if ($stored === (string) $budgetBytes) {
            return;
        }
        if ($stored === false && file_exists($path)) {
            throw new \RuntimeException('Cannot read browser POST shared budget.');
        }
        if ($stored !== false) {
            $this->reapAndRecount();
            foreach ($this->leasePaths() as $leasePath) {
                if ($leasePath !== $ownLeasePath) {
                    throw new \RuntimeException('Processes sharing a browser POST temporary directory must use the same budget.');
                }
            }
        }

        $payload = (string) $budgetBytes;
        if (file_put_contents($path . '.tmp', $payload) !== strlen($payload)
            || ! @rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Cannot write browser POST shared budget.');
        }
    }

    public function release(PostObjectSpoolReservation $reservation, bool $tempDeleted): void
    {
        if (! $reservation->isOpen()) {
            return;
        }
        try {
            if ($tempDeleted) {
                if ($this->pool !== null) {
                    $this->taskValue($this->pool->submit(new PostObjectSpoolTask(
                        'release',
                        $this->tempDir,
                        $reservation->tempPath,
                        $reservation->leasePath,
                        $reservation->bytes(),
                    ))->await());
                } else {
                    $this->releaseInline($reservation->leasePath, $reservation->bytes());
                }
            }
        } catch (\Throwable) {
            // A remaining lease is recovered after this process releases its lock.
        } finally {
            $reservation->close();
            unset($this->activeReservations[$reservation->leasePath]);
        }
    }

    /** @internal Executed only inside PostObjectSpoolTask. */
    public function releaseInline(string $leasePath, int $_reservedBytes): void
    {
        $leasePath = $this->validatedLeasePath($leasePath);
        $this->withLock(function () use ($leasePath): void {
            $this->repairAccountingIfDirty();
            $lease = $this->readLease($leasePath);
            $total = $this->readTotal();
            $this->markAccountingDirty();
            if (@unlink($leasePath) || ! file_exists($leasePath)) {
                @unlink(self::statePath($leasePath));
                @unlink(self::statePath($leasePath) . '.tmp');
                $this->writeTotal(max(0, $total - $lease['bytes']));
                $this->clearAccountingDirty();
            }
        });
    }

    private function repairAccountingIfDirty(): void
    {
        if (! file_exists($this->accountingDirtyPath())) {
            return;
        }

        $this->writeTotal($this->sumLeases());
        $this->clearAccountingDirty();
    }

    private function reapAndRecount(): int
    {
        $this->markAccountingDirty();
        try {
            $this->reapAbandonedLeases();
            $total = $this->sumLeases();
            $this->writeTotal($total);
            $this->clearAccountingDirty();

            return $total;
        } catch (\Throwable $error) {
            // The marker intentionally remains so the next lock owner repairs
            // from the per-lease state before trusting the cached total.
            throw $error;
        }
    }

    private function markAccountingDirty(): void
    {
        $path = $this->accountingDirtyPath();
        $temporaryPath = $path . '.tmp';
        if (file_put_contents($temporaryPath, '1') !== 1 || ! @rename($temporaryPath, $path)) {
            throw new \RuntimeException('Cannot mark browser POST accounting as incomplete.');
        }
    }

    private function clearAccountingDirty(): void
    {
        $path = $this->accountingDirtyPath();
        if (! @unlink($path) && file_exists($path)) {
            throw new \RuntimeException('Cannot complete browser POST accounting update.');
        }
        @unlink($path . '.tmp');
    }

    private function accountingDirtyPath(): string
    {
        return $this->leaseDir . DIRECTORY_SEPARATOR . 'accounting-dirty';
    }

    private function validatedTempPath(string $tempPath): string
    {
        if (realpath(dirname($tempPath)) !== $this->tempDir || ! self::isManagedTempPath($tempPath)) {
            throw new \InvalidArgumentException('Request temporary path is outside its spool directory.');
        }

        return $this->tempDir . DIRECTORY_SEPARATOR . basename($tempPath);
    }

    private function validatedLeasePath(string $leasePath): string
    {
        if (dirname($leasePath) !== $this->leaseDir
            || ! str_starts_with(basename($leasePath), 'lease-')
            || str_contains(substr(basename($leasePath), 6), DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('Browser POST reservation lease is invalid.');
        }

        return $this->leaseDir . DIRECTORY_SEPARATOR . basename($leasePath);
    }

    /**
     * @param array{ok: bool, value?: int|string|null, type?: class-string<\Throwable>, message?: string} $result
     */
    private function taskValue(array $result): int|string|null
    {
        if ($result['ok']) {
            return $result['value'] ?? null;
        }

        $message = $result['message'] ?? 'Browser POST reservation worker failed.';
        throw match ($result['type'] ?? '') {
            SlowDownException::class => new SlowDownException($message),
            \InvalidArgumentException::class => new \InvalidArgumentException($message),
            default => new \RuntimeException($message),
        };
    }

    private function sumLeases(): int
    {
        $total = 0;
        foreach ($this->leasePaths() as $path) {
            $lease = $this->readLease($path);
            $total += $lease['bytes'];
        }

        return $total;
    }

    private function reapAbandonedLeases(): bool
    {
        $changed = false;
        foreach (glob($this->leaseDir . DIRECTORY_SEPARATOR . 'state-*') ?: [] as $statePath) {
            if (str_ends_with($statePath, '.tmp')) {
                $basePath = substr($statePath, 0, -4);
            } else {
                $basePath = $statePath;
            }
            $leasePath = $this->leaseDir . DIRECTORY_SEPARATOR . 'lease-' . substr(basename($basePath), 6);
            if (! file_exists($leasePath)) {
                $changed = @unlink($statePath) || $changed;
            }
        }

        foreach ($this->leasePaths() as $path) {
            $handle = @fopen($path, 'r+');
            if ($handle === false) {
                continue;
            }
            try {
                if (! flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $lease = $this->readLease($path);
                if (is_file($lease['tempPath'])) {
                    @unlink($lease['tempPath']);
                }
                if (! file_exists($lease['tempPath'])) {
                    if (@unlink($path) || ! file_exists($path)) {
                        @unlink($this->statePath($path));
                        @unlink($this->statePath($path) . '.tmp');
                        $changed = true;
                    }
                }
            } finally {
                fclose($handle);
            }
        }

        return $changed;
    }

    private function readTotal(): int
    {
        $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'total';
        $raw = @file_get_contents($path);
        if ($raw !== false && preg_match('/^(0|[1-9][0-9]*)$/D', $raw) === 1) {
            $value = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($value !== false) {
                return $value;
            }
        }

        $total = $this->sumLeases();
        $this->writeTotal($total);

        return $total;
    }

    private function writeTotal(int $total): void
    {
        $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'total';
        $temporaryPath = $path . '.tmp';
        $payload = (string) $total;
        if (file_put_contents($temporaryPath, $payload) !== strlen($payload)
            || ! @rename($temporaryPath, $path)) {
            throw new \RuntimeException('Cannot write browser POST reservation total.');
        }
    }

    /** @return list<string> */
    private function leasePaths(): array
    {
        $paths = glob($this->leaseDir . DIRECTORY_SEPARATOR . 'lease-*');
        if ($paths === false) {
            throw new \RuntimeException('Cannot scan browser POST reservations.');
        }

        return $paths;
    }

    /** @return array{tempPath: string, bytes: int} */
    private function readLease(string $path): array
    {
        $contents = @file_get_contents(self::statePath($path));
        if ($contents === false) {
            throw new \RuntimeException('Cannot read browser POST reservation.');
        }
        try {
            $lease = json_decode($contents, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Browser POST reservation is corrupt.');
        }
        if (! is_array($lease) || ! isset($lease['tempPath'], $lease['bytes'])
            || ! is_string($lease['tempPath']) || realpath(dirname($lease['tempPath'])) !== $this->tempDir
            || ! self::isManagedTempPath($lease['tempPath'])
            || ! is_int($lease['bytes']) || $lease['bytes'] < 0) {
            throw new \RuntimeException('Browser POST reservation is invalid.');
        }

        return [
            'tempPath' => $this->tempDir . DIRECTORY_SEPARATOR . basename($lease['tempPath']),
            'bytes' => $lease['bytes'],
        ];
    }

    private function writeLease(string $leasePath, string $tempPath, int $bytes): void
    {
        $payload = json_encode(['tempPath' => $tempPath, 'bytes' => $bytes], JSON_THROW_ON_ERROR);
        $statePath = self::statePath($leasePath);
        $temporaryPath = $statePath . '.tmp';
        if (file_put_contents($temporaryPath, $payload) !== strlen($payload)
            || ! @rename($temporaryPath, $statePath)) {
            throw new \RuntimeException('Cannot write browser POST reservation.');
        }
    }

    private static function isManagedTempPath(string $tempPath): bool
    {
        $name = basename($tempPath);

        return str_starts_with($name, 's3-post-')
            || str_starts_with($name, 's3-md5-')
            || str_starts_with($name, 's3-sha256-');
    }

    private static function statePath(string $leasePath): string
    {
        return dirname($leasePath) . DIRECTORY_SEPARATOR . 'state-' . substr(basename($leasePath), 6);
    }

    /**
     * @template T
     *
     * @param  \Closure(): T  $callback
     * @return T
     */
    private function withLock(\Closure $callback): mixed
    {
        $lock = @fopen($this->leaseDir . DIRECTORY_SEPARATOR . 'lock', 'c+');
        if ($lock === false) {
            throw new \RuntimeException('Cannot open browser POST reservation lock.');
        }
        try {
            if (! flock($lock, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock browser POST reservations.');
            }

            return $callback();
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
}
