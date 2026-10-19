<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Exception\SlowDownException;

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

    public function __construct(?string $tempDir = null)
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
    }

    public function tempDir(): string
    {
        return $this->tempDir;
    }

    public function recoverAbandoned(): void
    {
        $this->withLock(function (): void {
            $this->reapAbandonedLeases();
            $this->writeTotal($this->sumLeases());
        });
    }

    public function start(string $tempPath): PostObjectSpoolReservation
    {
        if (realpath(dirname($tempPath)) !== $this->tempDir || ! self::isManagedTempPath($tempPath)) {
            throw new \InvalidArgumentException('Request temporary path is outside its spool directory.');
        }
        $tempPath = $this->tempDir . DIRECTORY_SEPARATOR . basename($tempPath);

        return $this->withLock(function () use ($tempPath): PostObjectSpoolReservation {
            // Crash leftovers must not accumulate indefinitely when traffic
            // stays below the byte limit (including zero-byte uploads).
            if ($this->reapAbandonedLeases()) {
                $this->writeTotal($this->sumLeases());
            } else {
                $this->readTotal();
            }
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
    }

    public function reserve(PostObjectSpoolReservation $reservation, int $bytes, int $budgetBytes): void
    {
        if ($bytes < 0 || $budgetBytes < 1 || ! $reservation->isOpen()) {
            throw new \InvalidArgumentException('Invalid browser POST reservation.');
        }
        if ($bytes === 0) {
            return;
        }

        $this->withLock(function () use ($reservation, $bytes, $budgetBytes): void {
            $this->assertSharedBudget($reservation->leasePath, $budgetBytes);
            $total = $this->readTotal();
            if ($bytes > $budgetBytes - $total) {
                $this->reapAbandonedLeases();
                $total = $this->sumLeases();
                $this->writeTotal($total);
            }
            if ($bytes > $budgetBytes - $total) {
                throw new SlowDownException('Browser upload temporary storage budget is full.');
            }

            $next = $reservation->bytes() + $bytes;
            $this->writeTotal($total + $bytes);
            try {
                $this->writeLease($reservation->leasePath, $reservation->tempPath, $next);
            } catch (\Throwable $error) {
                $this->writeTotal($total);
                throw $error;
            }
            $reservation->setBytes($next);
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
            $this->reapAbandonedLeases();
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
                $this->withLock(function () use ($reservation): void {
                    $total = $this->readTotal();
                    if (@unlink($reservation->leasePath) || ! file_exists($reservation->leasePath)) {
                        @unlink(self::statePath($reservation->leasePath));
                        @unlink(self::statePath($reservation->leasePath) . '.tmp');
                        $this->writeTotal(max(0, $total - $reservation->bytes()));
                    }
                });
            }
        } catch (\Throwable) {
            // A remaining lease is recovered after this process releases its lock.
        } finally {
            $reservation->close();
            unset($this->activeReservations[$reservation->leasePath]);
        }
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
