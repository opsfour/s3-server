<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\SlowDownException;

/** Coordinates Flysystem staging bytes across API and worker processes on one node. */
final class StorageTempBudgetCoordinator
{
    private const int UNLEASED_STALE_AFTER_SECONDS = 86_400;

    private const int UNLEASED_SWEEP_INTERVAL_SECONDS = 300;

    private readonly string $tempDir;

    private readonly string $leaseDir;

    private int $lastUnleasedSweepAt = 0;

    public function __construct(string $tempDir, private readonly int $budgetBytes)
    {
        if ($budgetBytes < 1) {
            throw new \InvalidArgumentException('Storage staging budget must be greater than zero.');
        }
        $resolvedTempDir = realpath($tempDir);
        if ($resolvedTempDir === false || !is_dir($resolvedTempDir)) {
            throw new \InvalidArgumentException('Storage staging temporary directory must exist.');
        }
        $this->tempDir = rtrim($resolvedTempDir, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
        $this->leaseDir = $this->tempDir . DIRECTORY_SEPARATOR . '.opsfour-s3-storage-budget';
        if (!is_dir($this->leaseDir) && !@mkdir($this->leaseDir, 0700) && !is_dir($this->leaseDir)) {
            throw new \RuntimeException('Cannot create storage staging reservation directory.');
        }
        $permissions = fileperms($this->leaseDir);
        if (is_link($this->leaseDir) || $permissions === false || ($permissions & 0077) !== 0) {
            throw new \RuntimeException('Storage staging reservation directory must be private.');
        }
        $this->withLock(function (): void {
            $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'budget';
            $stored = @file_get_contents($path);
            if ($stored === (string) $this->budgetBytes) {
                return;
            }
            if ($stored !== false && ($this->hasActiveLeases() || self::quarantinePaths($this->leaseDir) !== [])) {
                throw new \RuntimeException('Processes sharing a storage staging directory must use the same budget.');
            }
            $payload = (string) $this->budgetBytes;
            if (file_put_contents($path . '.tmp', $payload) !== strlen($payload)
                || !@rename($path . '.tmp', $path)) {
                throw new \RuntimeException('Cannot write storage staging budget.');
            }
        });
    }

    public function start(string $tempPath): StorageTempReservation
    {
        $this->assertTempPath($tempPath);
        $tempPath = $this->canonicalTempPath($tempPath);

        return $this->withLock(function () use ($tempPath): StorageTempReservation {
            $this->reapAbandoned();
            if (time() - $this->lastUnleasedSweepAt >= self::UNLEASED_SWEEP_INTERVAL_SECONDS) {
                $this->reapUnleasedStale();
                $this->lastUnleasedSweepAt = time();
            }
            $this->reapQuarantines();
            $this->writeTotal($this->sumLeases());
            $leasePath = $this->leaseDir . DIRECTORY_SEPARATOR . 'lease-' . bin2hex(random_bytes(16));
            $handle = @fopen($leasePath, 'x+');
            if ($handle === false) {
                throw new \RuntimeException('Cannot create storage staging reservation.');
            }
            try {
                if (!flock($handle, LOCK_SH | LOCK_NB)) {
                    throw new \RuntimeException('Cannot lock storage staging reservation.');
                }
                $this->writeState($leasePath, $tempPath, 0);

                return new StorageTempReservation($leasePath, $tempPath, $handle);
            } catch (\Throwable $error) {
                fclose($handle);
                @unlink($leasePath);
                @unlink($this->statePath($leasePath));
                throw $error;
            }
        });
    }

    public function reserve(StorageTempReservation $reservation, int $bytes): void
    {
        $this->reserveExisting($reservation->leasePath, $reservation->tempPath, $bytes);
    }

    /** Called by a Flysystem worker while the API process holds the lease lock. */
    public function reserveExisting(string $leasePath, string $tempPath, int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Storage staging reservation bytes must be non-negative.');
        }
        if ($bytes === 0) {
            return;
        }
        $this->assertLeasePath($leasePath);
        $this->assertTempPath($tempPath);
        $tempPath = $this->canonicalTempPath($tempPath);

        $this->withLock(function () use ($leasePath, $tempPath, $bytes): void {
            if (!is_file($leasePath)) {
                throw new \RuntimeException('Storage staging reservation was released.');
            }
            $state = $this->readState($leasePath);
            if ($state['tempPath'] !== $tempPath) {
                throw new \RuntimeException('Storage staging reservation path changed.');
            }
            $total = $this->readTotal();
            if ($bytes > $this->budgetBytes - $total) {
                $this->reapAbandoned();
                $this->reapQuarantines();
                $total = $this->sumLeases();
                $this->writeTotal($total);
            }
            if ($bytes > $this->budgetBytes - $total) {
                throw new SlowDownException('Storage staging budget is full.');
            }

            $this->writeTotal($total + $bytes);
            try {
                $this->writeState($leasePath, $tempPath, $state['bytes'] + $bytes);
            } catch (\Throwable $error) {
                $this->writeTotal($total);
                throw $error;
            }
        });
    }

    public function release(StorageTempReservation $reservation): void
    {
        try {
            $this->withLock(function () use ($reservation): void {
                if (is_file($reservation->tempPath)) {
                    // The bytes remain reserved until a later crash-recovery pass
                    // can remove the file, including after an unlink failure.
                    return;
                }
                if (!is_file($reservation->leasePath)) {
                    return;
                }
                $bytes = $this->readState($reservation->leasePath)['bytes'];
                $total = $this->readTotal();
                if (@unlink($reservation->leasePath) || !file_exists($reservation->leasePath)) {
                    @unlink($this->statePath($reservation->leasePath));
                    @unlink($this->statePath($reservation->leasePath) . '.tmp');
                    $this->writeTotal(max(0, $total - $bytes));
                }
            });
        } finally {
            $reservation->close();
        }
    }

    private function reapAbandoned(): void
    {
        foreach ($this->leasePaths() as $leasePath) {
            $handle = @fopen($leasePath, 'r+');
            if ($handle === false) {
                continue;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                if (!is_file($this->statePath($leasePath))) {
                    // A process died between creating the lease and writing
                    // its first state. Its unleased file is swept by age.
                    @unlink($leasePath);
                    continue;
                }
                try {
                    $state = $this->readState($leasePath);
                } catch (\Throwable) {
                    // The exclusive lease lock proves no process still owns
                    // this reservation, but corrupt state cannot identify its
                    // path or byte count. Charge the full bounded budget until
                    // every unleased staging file is safely gone.
                    $this->quarantineCorruptLease($leasePath);
                    @unlink($this->statePath($leasePath));
                    @unlink($this->statePath($leasePath) . '.tmp');
                    @unlink($leasePath);
                    continue;
                }
                if (is_file($state['tempPath'])) {
                    @unlink($state['tempPath']);
                }
                if (!file_exists($state['tempPath'])) {
                    @unlink($leasePath);
                    @unlink($this->statePath($leasePath));
                    @unlink($this->statePath($leasePath) . '.tmp');
                }
            } finally {
                fclose($handle);
            }
        }

        foreach (glob($this->leaseDir . DIRECTORY_SEPARATOR . 'state-*') ?: [] as $statePath) {
            $base = str_ends_with($statePath, '.tmp') ? substr($statePath, 0, -4) : $statePath;
            $leasePath = $this->leaseDir . DIRECTORY_SEPARATOR . 'lease-' . substr(basename($base), 6);
            if (!file_exists($leasePath)) {
                @unlink($statePath);
            }
        }
    }

    private function reapUnleasedStale(): void
    {
        $leasedPaths = [];
        foreach ($this->leasePaths() as $leasePath) {
            $leasedPaths[$this->readState($leasePath)['tempPath']] = true;
        }
        $cutoff = time() - self::UNLEASED_STALE_AFTER_SECONDS;
        $deleted = 0;
        foreach (['s3fw-put-', 's3fw-part-', 's3fw-get-', 's3fw-mpu-'] as $prefix) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . $prefix . '*') ?: [] as $path) {
                if ($deleted >= 1_000) {
                    return;
                }
                if (isset($leasedPaths[$path]) || is_link($path) || !is_file($path)) {
                    continue;
                }
                $mtime = @filemtime($path);
                if ($mtime !== false && $mtime < $cutoff && @unlink($path)) {
                    $deleted++;
                }
            }
        }
    }

    private function sumLeases(): int
    {
        $total = 0;
        foreach ($this->leasePaths() as $leasePath) {
            $total += $this->readState($leasePath)['bytes'];
        }

        return self::quarantinePaths($this->leaseDir) === [] ? $total : $this->budgetBytes;
    }

    private function quarantineCorruptLease(string $leasePath): void
    {
        $path = $this->quarantinePath($leasePath);
        if (is_file($path)) {
            return;
        }
        $payload = (string) time();
        if (file_put_contents($path . '.tmp', $payload) !== strlen($payload)
            || !@rename($path . '.tmp', $path)) {
            @unlink($path . '.tmp');
            throw new \RuntimeException('Cannot quarantine corrupt storage staging reservation.');
        }
    }

    private function reapQuarantines(): void
    {
        $quarantines = self::quarantinePaths($this->leaseDir);
        if ($quarantines === []) {
            return;
        }
        $leasedPaths = [];
        foreach ($this->leasePaths() as $leasePath) {
            try {
                $leasedPaths[$this->readState($leasePath)['tempPath']] = true;
            } catch (\Throwable) {
                return;
            }
        }
        foreach (['s3fw-put-', 's3fw-part-', 's3fw-get-', 's3fw-mpu-'] as $prefix) {
            foreach (glob($this->tempDir . DIRECTORY_SEPARATOR . $prefix . '*') ?: [] as $path) {
                if (!isset($leasedPaths[$path]) && !is_link($path) && is_file($path)) {
                    return;
                }
            }
        }
        foreach ($quarantines as $path) {
            @unlink($path);
            @unlink($path . '.tmp');
        }
    }

    /** @return list<string> */
    private static function quarantinePaths(string $leaseDir): array
    {
        $paths = glob($leaseDir . DIRECTORY_SEPARATOR . 'quarantine-*');
        if ($paths === false) {
            throw new \RuntimeException('Cannot scan quarantined storage staging reservations.');
        }

        return array_values(array_filter($paths, static fn(string $path): bool => !str_ends_with($path, '.tmp')));
    }

    private function hasActiveLeases(): bool
    {
        foreach ($this->leasePaths() as $leasePath) {
            $handle = @fopen($leasePath, 'r+');
            if ($handle === false) {
                return true;
            }
            try {
                if (!flock($handle, LOCK_EX | LOCK_NB)) {
                    return true;
                }
            } finally {
                fclose($handle);
            }
        }

        return false;
    }

    /** @return list<string> */
    private function leasePaths(): array
    {
        $paths = glob($this->leaseDir . DIRECTORY_SEPARATOR . 'lease-*');
        if ($paths === false) {
            throw new \RuntimeException('Cannot scan storage staging reservations.');
        }

        return $paths;
    }

    /** @return array{tempPath: string, bytes: int} */
    private function readState(string $leasePath): array
    {
        $raw = @file_get_contents($this->statePath($leasePath));
        if ($raw === false) {
            throw new \RuntimeException('Cannot read storage staging reservation.');
        }
        try {
            $state = json_decode($raw, true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            throw new \RuntimeException('Storage staging reservation is corrupt.');
        }
        if (!is_array($state) || !isset($state['tempPath'], $state['bytes'])
            || !is_string($state['tempPath']) || !is_int($state['bytes']) || $state['bytes'] < 0) {
            throw new \RuntimeException('Storage staging reservation is invalid.');
        }
        $this->assertTempPath($state['tempPath']);

        return $state;
    }

    private function writeState(string $leasePath, string $tempPath, int $bytes): void
    {
        $payload = json_encode(['tempPath' => $tempPath, 'bytes' => $bytes], JSON_THROW_ON_ERROR);
        $path = $this->statePath($leasePath);
        if (file_put_contents($path . '.tmp', $payload) !== strlen($payload)
            || !@rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Cannot write storage staging reservation.');
        }
    }

    private function readTotal(): int
    {
        $raw = @file_get_contents($this->leaseDir . DIRECTORY_SEPARATOR . 'total');
        if ($raw !== false && preg_match('/^(0|[1-9][0-9]*)$/D', $raw) === 1) {
            $total = filter_var($raw, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($total !== false) {
                return $total;
            }
        }
        $total = $this->sumLeases();
        $this->writeTotal($total);

        return $total;
    }

    private function writeTotal(int $total): void
    {
        $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'total';
        $payload = (string) $total;
        if (file_put_contents($path . '.tmp', $payload) !== strlen($payload)
            || !@rename($path . '.tmp', $path)) {
            throw new \RuntimeException('Cannot write storage staging total.');
        }
    }

    private function assertTempPath(string $path): void
    {
        if (realpath(dirname($path)) !== $this->tempDir || preg_match('/^s3fw-(?:put|part|get|mpu)-[A-Za-z0-9]+$/D', basename($path)) !== 1) {
            throw new \InvalidArgumentException('Storage staging path is outside its temporary directory.');
        }
    }

    private function canonicalTempPath(string $path): string
    {
        return $this->tempDir . DIRECTORY_SEPARATOR . basename($path);
    }

    private function assertLeasePath(string $path): void
    {
        if (dirname($path) !== $this->leaseDir || preg_match('/^lease-[a-f0-9]{32}$/D', basename($path)) !== 1) {
            throw new \InvalidArgumentException('Storage staging lease path is invalid.');
        }
    }

    private function statePath(string $leasePath): string
    {
        return $this->leaseDir . DIRECTORY_SEPARATOR . 'state-' . substr(basename($leasePath), 6);
    }

    private function quarantinePath(string $leasePath): string
    {
        return $this->leaseDir . DIRECTORY_SEPARATOR . 'quarantine-' . substr(basename($leasePath), 6);
    }

    /**
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    private function withLock(\Closure $callback): mixed
    {
        $handle = @fopen($this->leaseDir . DIRECTORY_SEPARATOR . 'lock', 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Cannot open storage staging lock.');
        }
        try {
            if (!flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock storage staging reservations.');
            }

            return $callback();
        } finally {
            flock($handle, LOCK_UN);
            fclose($handle);
        }
    }
}
