<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\SlowDownException;

/**
 * One node-local temporary-capacity authority shared by every PHP process.
 *
 * Reservation records contain no tenant or object identifiers. Recovery only
 * removes an exact regular file whose device, inode and owner still match the
 * identity captured when the reservation was created.
 */
final class StorageTempBudgetCoordinator
{
    public const int DEFAULT_BUDGET_BYTES = 10_737_418_240;

    public const int DEFAULT_FILESYSTEM_RESERVE_BYTES = 1_073_741_824;

    private const int REGULAR_FILE_MODE = 0o100000;

    private readonly string $tempDir;

    private readonly string $leaseDir;

    private readonly string $processLeasePath;

    /** @var array<string,string> Stable namespace name to canonical managed root. */
    private array $namespaces = [];

    /** @var array<string,array{device:int,inode:int,owner:int,mode:int}> */
    private array $namespaceIdentities = [];

    /** @var resource|null */
    private mixed $processLeaseHandle = null;

    public function __construct(
        string $tempDir,
        private readonly int $budgetBytes = self::DEFAULT_BUDGET_BYTES,
        private readonly int $filesystemReserveBytes = self::DEFAULT_FILESYSTEM_RESERVE_BYTES,
    ) {
        if ($budgetBytes < 1) {
            throw new \InvalidArgumentException('Storage temporary budget must be greater than zero.');
        }
        if ($filesystemReserveBytes < 0) {
            throw new \InvalidArgumentException('Storage temporary filesystem reserve must be non-negative.');
        }

        $resolved = realpath($tempDir);
        if ($resolved === false || ! is_dir($resolved) || ! is_writable($resolved)) {
            throw new \InvalidArgumentException('Storage temporary directory must exist and be writable.');
        }
        $root = @lstat($resolved);
        if ($root === false || ($root['mode'] & 0o170000) !== 0o040000
            || (($root['mode'] & 0o022) !== 0 && ($root['mode'] & 0o1000) === 0)) {
            throw new \RuntimeException('Storage temporary directory must be private or sticky.');
        }

        $this->tempDir = rtrim($resolved, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
        $this->leaseDir = $this->tempDir . DIRECTORY_SEPARATOR . '.opsfour-s3-storage-budget';
        $this->ensurePrivateControlDirectory();
        $this->processLeasePath = $this->leaseDir . DIRECTORY_SEPARATOR . 'process-lease-' . bin2hex(random_bytes(16));

        $this->withLock(function (): void {
            if (isset($this->namespaces['node']) && $this->namespaces['node'] !== $this->tempDir) {
                throw new \RuntimeException('Node temporary namespace root changed.');
            }
            $this->namespaces['node'] = $this->tempDir;
            $this->namespaceIdentities['node'] = $this->directoryIdentity($this->tempDir, false)
                ?? throw new \RuntimeException('Node temporary namespace root identity is unavailable.');
            $this->writeNamespaces();
            $this->reapAbandonedReservations();
            $this->reapDeadProcessesAndAssertConfiguration();
            $this->pruneInactiveNamespaces();
            $this->writeScalar('budget', $this->budgetBytes);
            $this->writeScalar('reserve', $this->filesystemReserveBytes);
            $this->writeTotal($this->sumLeases());
            $this->createProcessLease();
        });
    }

    public function tempDir(): string
    {
        return $this->tempDir;
    }

    public function budgetBytes(): int
    {
        return $this->budgetBytes;
    }

    public function filesystemReserveBytes(): int
    {
        return $this->filesystemReserveBytes;
    }

    /** Register a private staging namespace while retaining one aggregate budget. */
    public function registerNamespace(string $name, string $root): void
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1 || $name === 'node') {
            throw new \InvalidArgumentException('Storage temporary namespace name is invalid.');
        }
        if (! is_dir($root) && ! @mkdir($root, 0o700, true) && ! is_dir($root)) {
            throw new \RuntimeException('Cannot create storage temporary namespace root.');
        }
        $resolved = realpath($root);
        $identity = $resolved === false ? null : $this->directoryIdentity($resolved, true, false);
        if ($resolved === false || $identity === null) {
            throw new \RuntimeException('Storage temporary namespace root must be private and process-owned.');
        }

        $this->withLock(function () use ($name, $resolved, $identity): void {
            if (isset($this->namespaces[$name]) && $this->namespaces[$name] !== $resolved) {
                throw new \RuntimeException('Storage temporary namespace configuration changed.');
            }
            $this->namespaces[$name] = $resolved;
            $this->namespaceIdentities[$name] = $identity;
            $this->writeNamespaces();
            $this->writeCurrentProcessState();
        });
    }

    public function start(string $tempPath, string $purpose = 'storage'): StorageTempReservation
    {
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('Storage temporary reservation purpose is invalid.');
        }

        return $this->withLock(function () use ($tempPath, $purpose): StorageTempReservation {
            $managed = $this->resolveManagedPath($tempPath);
            $tempPath = $managed['path'];
            $this->reapAbandonedReservations();
            if ($this->pathExistsWithoutFollowing($tempPath)) {
                throw new SlowDownException('Storage staging path already exists and cannot be adopted.');
            }
            $this->assertSafeForAdmission();
            $this->writeTotal($this->sumLeases());

            $tempHandle = @fopen($tempPath, 'x+b');
            if ($tempHandle === false) {
                throw new SlowDownException('Storage staging path could not be created exclusively.');
            }
            try {
                if (! @chmod($tempPath, 0o600)) {
                    throw new \RuntimeException('Cannot protect storage temporary file.');
                }
            } catch (\Throwable $error) {
                @fclose($tempHandle);
                @unlink($tempPath);
                throw $error;
            }
            @fclose($tempHandle);
            $identity = $this->managedFileIdentity($tempPath);

            $leasePath = $this->leaseDir . DIRECTORY_SEPARATOR . 'lease-' . bin2hex(random_bytes(16));
            $handle = @fopen($leasePath, 'x+b');
            if ($handle === false) {
                if ($this->identityMatches($tempPath, $identity)) {
                    @unlink($tempPath);
                }
                throw new \RuntimeException('Cannot create storage temporary reservation.');
            }
            try {
                if (! @chmod($leasePath, 0o600) || ! flock($handle, LOCK_SH | LOCK_NB)) {
                    throw new \RuntimeException('Cannot protect storage temporary reservation.');
                }
                $nonce = bin2hex(random_bytes(16));
                $this->writeState(
                    $leasePath,
                    $managed['namespace'],
                    $tempPath,
                    $purpose,
                    0,
                    $nonce,
                    $identity,
                );

                return new StorageTempReservation($leasePath, $tempPath, $purpose, $nonce, $handle, $identity);
            } catch (\Throwable $error) {
                fclose($handle);
                @unlink($leasePath);
                @unlink($this->statePath($leasePath));
                if ($this->identityMatches($tempPath, $identity)) {
                    @unlink($tempPath);
                }
                throw $error;
            }
        });
    }

    public function reserveCapacity(int $bytes, string $purpose): StorageTempReservation
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Storage temporary capacity must be non-negative.');
        }
        $reservation = $this->start(
            $this->tempDir . DIRECTORY_SEPARATOR . 's3-capacity-' . bin2hex(random_bytes(16)),
            $purpose,
        );
        try {
            $this->reserve($reservation, $bytes);
        } catch (\Throwable $error) {
            try {
                $this->cleanup($reservation);
            } catch (\Throwable) {
            }
            throw $error;
        }

        return $reservation;
    }

    /** Move an admitted commitment onto the exact staging inode that will consume it. */
    public function retarget(StorageTempReservation $reservation, string $tempPath, string $purpose): void
    {
        if (! $reservation->isOpen()) {
            throw new \RuntimeException('Storage temporary reservation is closed.');
        }
        if (preg_match('/^[a-z][a-z0-9_-]{0,31}$/D', $purpose) !== 1) {
            throw new \InvalidArgumentException('Storage temporary reservation purpose is invalid.');
        }
        $this->assertLeasePath($reservation->leasePath);

        $this->withLock(function () use ($reservation, $tempPath, $purpose): void {
            $source = $this->resolveManagedPath($reservation->tempPath);
            $target = $this->resolveManagedPath($tempPath);
            $state = $this->readState($reservation->leasePath);
            $sourceInfo = @lstat($source['path']);
            if ($state['tempPath'] !== $source['path'] || $state['namespace'] !== $source['namespace']
                || ! hash_equals($state['nonce'], $reservation->nonce)
                || ! $this->identityMatches($source['path'], $state)
                || $sourceInfo === false || (int) $sourceInfo['size'] !== 0) {
                throw new \RuntimeException('Storage temporary capacity reservation changed before transfer.');
            }
            if ($this->pathExistsWithoutFollowing($target['path'])) {
                throw new SlowDownException('Storage staging path already exists and cannot receive capacity.');
            }
            $this->assertSafeForAdmission();
            $sourceDevice = $this->namespaceIdentities[$source['namespace']]['device'];
            $targetDevice = $this->namespaceIdentities[$target['namespace']]['device'];
            if ($sourceDevice !== $targetDevice
                && $state['bytes'] > $this->filesystemCapacity($target['namespace'])['minimumAvailable']) {
                throw new SlowDownException('Storage staging budget is full on the target filesystem.');
            }

            $targetHandle = @fopen($target['path'], 'x+b');
            if ($targetHandle === false) {
                throw new SlowDownException('Storage staging path could not be created exclusively.');
            }
            try {
                if (! @chmod($target['path'], 0o600)) {
                    throw new \RuntimeException('Cannot protect storage temporary file.');
                }
            } catch (\Throwable $error) {
                @fclose($targetHandle);
                @unlink($target['path']);
                throw $error;
            }
            @fclose($targetHandle);
            $identity = $this->managedFileIdentity($target['path']);
            try {
                $this->writeState(
                    $reservation->leasePath,
                    $target['namespace'],
                    $target['path'],
                    $purpose,
                    $state['bytes'],
                    $state['nonce'],
                    $identity,
                );
            } catch (\Throwable $error) {
                if ($this->identityMatches($target['path'], $identity)) {
                    @unlink($target['path']);
                }
                throw $error;
            }
            $reservation->retarget($target['path'], $purpose, $identity);
            if (! $this->identityMatches($source['path'], $state) || ! @unlink($source['path'])) {
                throw new \RuntimeException('Cannot remove transferred storage capacity placeholder.');
            }
        });
    }

    public function reserve(StorageTempReservation $reservation, int $bytes): void
    {
        if (! $reservation->isOpen()) {
            throw new \RuntimeException('Storage temporary reservation is closed.');
        }
        $this->reserveExisting($reservation->leasePath, $reservation->tempPath, $reservation->nonce, $bytes);
    }

    /** Release admitted tail bytes that were never written to the owned inode. */
    public function releaseUnused(StorageTempReservation $reservation, int $bytes): void
    {
        if ($bytes < 0 || ! $reservation->isOpen()) {
            throw new \InvalidArgumentException('Invalid unused storage temporary capacity.');
        }
        if ($bytes === 0) {
            return;
        }
        $this->withLock(function () use ($reservation, $bytes): void {
            $state = $this->readState($reservation->leasePath);
            $info = @lstat($reservation->tempPath);
            if (! hash_equals($state['nonce'], $reservation->nonce)
                || $state['tempPath'] !== $reservation->tempPath
                || ! $this->identityMatches($reservation->tempPath, $state)
                || $info === false || $bytes > $state['bytes']
                || (int) $info['size'] > $state['bytes'] - $bytes) {
                throw new \RuntimeException('Unused storage temporary capacity cannot be released safely.');
            }
            $this->writeState(
                $reservation->leasePath,
                $state['namespace'],
                $state['tempPath'],
                $state['purpose'],
                $state['bytes'] - $bytes,
                $state['nonce'],
                ['device' => $state['device'], 'inode' => $state['inode'], 'owner' => $state['owner']],
            );
            $this->writeTotal($this->sumLeases());
        });
    }

    /** Resume one live reservation in a worker process without changing ownership. */
    public function resumeExisting(string $leasePath, string $tempPath, string $nonce): StorageTempReservation
    {
        $this->assertLeasePath($leasePath);
        $managed = $this->resolveManagedPath($tempPath);
        $handle = $this->openRegularControlFile($leasePath, 'storage temporary reservation', 'r+b');
        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Storage temporary reservation cannot be resumed.');
            }
            $state = $this->withLock(function () use ($leasePath, $managed, $nonce): array {
                $state = $this->readState($leasePath);
                $info = @lstat($managed['path']);
                if ($state['namespace'] !== $managed['namespace']
                    || $state['tempPath'] !== $managed['path']
                    || ! $this->identityMatches($managed['path'], $state)
                    || ! hash_equals($state['nonce'], $nonce)
                    || $info === false || (int) $info['size'] > $state['bytes']) {
                    throw new \RuntimeException('Storage temporary reservation cannot be resumed safely.');
                }

                return $state;
            });

            return new StorageTempReservation(
                $leasePath,
                $managed['path'],
                $state['purpose'],
                $nonce,
                $handle,
                ['device' => $state['device'], 'inode' => $state['inode'], 'owner' => $state['owner']],
            );
        } catch (\Throwable $error) {
            @fclose($handle);
            throw $error;
        }
    }

    /** Called by a worker while another process holds the reservation lease. */
    public function reserveExisting(string $leasePath, string $tempPath, string $nonce, int $bytes): void
    {
        if ($bytes < 0) {
            throw new \InvalidArgumentException('Storage temporary reservation bytes must be non-negative.');
        }
        if ($bytes === 0) {
            return;
        }
        $this->assertLeasePath($leasePath);

        $this->withLock(function () use ($leasePath, $tempPath, $nonce, $bytes): void {
            $managed = $this->resolveManagedPath($tempPath);
            $tempPath = $managed['path'];
            if (! $this->pathExistsWithoutFollowing($leasePath)) {
                throw new \RuntimeException('Storage temporary reservation was released.');
            }
            $this->assertRegularControlFile($leasePath, 'reservation');
            $state = $this->readState($leasePath);
            if ($state['tempPath'] !== $tempPath || $state['namespace'] !== $managed['namespace']
                || ! hash_equals($state['nonce'], $nonce)
                || ! $this->identityMatches($tempPath, $state)) {
                throw new \RuntimeException('Storage temporary reservation file identity changed.');
            }

            $this->reapAbandonedReservations();
            $total = $this->sumLeases();
            if ($this->unsafeEntryCount() > 0
                || $bytes > $this->effectiveAvailableBytes($total, $managed['namespace'])) {
                $this->writeTotal($total);
                throw new SlowDownException('Storage staging budget is full.');
            }
            if ($bytes > PHP_INT_MAX - $state['bytes'] || $bytes > PHP_INT_MAX - $total) {
                throw new SlowDownException('Storage staging budget is full.');
            }

            $this->writeState(
                $leasePath,
                $state['namespace'],
                $tempPath,
                $state['purpose'],
                $state['bytes'] + $bytes,
                $state['nonce'],
                ['device' => $state['device'], 'inode' => $state['inode'], 'owner' => $state['owner']],
            );
            $this->writeTotal($total + $bytes);
        });
    }

    public function release(StorageTempReservation $reservation): void
    {
        try {
            $this->releaseExisting($reservation->leasePath, $reservation->tempPath, $reservation->nonce);
        } finally {
            $reservation->close();
        }
    }

    /**
     * Remove only the inode captured by this reservation, then release it.
     * A replacement is never deleted; its retained lease keeps readiness closed.
     */
    public function cleanup(StorageTempReservation $reservation): bool
    {
        if (! $reservation->isOpen()) {
            return false;
        }
        $this->assertLeasePath($reservation->leasePath);
        $removed = $this->withLock(function () use ($reservation): bool {
            $managed = $this->resolveManagedPath($reservation->tempPath);
            $state = $this->readState($reservation->leasePath);
            if ($state['namespace'] !== $managed['namespace'] || $state['tempPath'] !== $managed['path']
                || ! hash_equals($state['nonce'], $reservation->nonce)) {
                throw new \RuntimeException('Storage temporary reservation path changed before cleanup.');
            }
            if (! $this->pathExistsWithoutFollowing($managed['path'])) {
                return true;
            }
            if (! $this->identityMatches($managed['path'], $state)) {
                return false;
            }

            return @unlink($managed['path']) || ! $this->pathExistsWithoutFollowing($managed['path']);
        });

        if ($removed) {
            $this->release($reservation);
        } else {
            $reservation->close();
        }

        return $removed;
    }

    /** Remove a managed path only when a live reservation proves its inode identity. */
    public function cleanupPath(string $tempPath, string $nonce): bool
    {
        $managed = $this->resolveManagedPath($tempPath);
        $leasePath = $this->withLock(function () use ($managed): ?string {
            foreach ($this->leasePaths() as $candidate) {
                try {
                    $state = $this->readState($candidate);
                } catch (\Throwable) {
                    continue;
                }
                if ($state['namespace'] === $managed['namespace'] && $state['tempPath'] === $managed['path']) {
                    return $candidate;
                }
            }

            return null;
        });
        if ($leasePath === null) {
            return false;
        }

        return $this->cleanup($this->resumeExisting($leasePath, $managed['path'], $nonce));
    }

    /** Worker-side cleanup that validates identity inside cleanup itself. */
    public function cleanupExisting(string $leasePath, string $tempPath, string $nonce): bool
    {
        $this->assertLeasePath($leasePath);
        $managed = $this->resolveManagedPath($tempPath);
        $handle = $this->openRegularControlFile($leasePath, 'storage temporary reservation', 'r+b');
        try {
            if (! flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Storage temporary reservation cannot be held for cleanup.');
            }
            $state = $this->withLock(function () use ($leasePath, $managed, $nonce): array {
                $state = $this->readState($leasePath);
                if ($state['namespace'] !== $managed['namespace'] || $state['tempPath'] !== $managed['path']
                    || ! hash_equals($state['nonce'], $nonce)) {
                    throw new \RuntimeException('Storage temporary reservation path changed before cleanup.');
                }

                return $state;
            });
            $reservation = new StorageTempReservation(
                $leasePath,
                $managed['path'],
                $state['purpose'],
                $nonce,
                $handle,
                ['device' => $state['device'], 'inode' => $state['inode'], 'owner' => $state['owner']],
            );
        } catch (\Throwable $error) {
            @fclose($handle);
            throw $error;
        }

        return $this->cleanup($reservation);
    }

    /** Called by a worker while another process holds the reservation lease. */
    public function releaseExisting(string $leasePath, string $tempPath, string $nonce): void
    {
        $this->assertLeasePath($leasePath);
        $this->withLock(function () use ($leasePath, $tempPath, $nonce): void {
            $managed = $this->resolveManagedPath($tempPath);
            $tempPath = $managed['path'];
            if ($this->pathExistsWithoutFollowing($tempPath)
                || ! $this->pathExistsWithoutFollowing($leasePath)) {
                return;
            }
            $this->assertRegularControlFile($leasePath, 'reservation');
            $state = $this->readState($leasePath);
            if ($state['tempPath'] !== $tempPath || ! hash_equals($state['nonce'], $nonce)) {
                throw new \RuntimeException('Storage temporary reservation path changed.');
            }
            if (@unlink($leasePath) || ! $this->pathExistsWithoutFollowing($leasePath)) {
                @unlink($this->statePath($leasePath));
                @unlink($this->statePath($leasePath) . '.tmp');
                $this->writeTotal($this->sumLeases());
            } else {
                throw new \RuntimeException('Cannot release storage temporary reservation.');
            }
        });
    }

    public function snapshot(): StorageTempBudgetSnapshot
    {
        return $this->withLock(function (): StorageTempBudgetSnapshot {
            $this->reapAbandonedReservations();
            $unsafe = 0;
            $total = 0;
            $active = 0;
            $accounted = [];
            $ambiguousAccounting = false;

            foreach ($this->leasePaths() as $leasePath) {
                try {
                    $this->assertRegularControlFile($leasePath, 'reservation');
                    $state = $this->readState($leasePath);
                    $accounted[$state['tempPath']] = true;
                    $info = @lstat($state['tempPath']);
                    if (! $this->identityMatches($state['tempPath'], $state)
                        || $info === false || (int) $info['size'] > $state['bytes']) {
                        $unsafe++;
                    }
                    if ($state['bytes'] > PHP_INT_MAX - $total) {
                        throw new \RuntimeException('Storage temporary reservation total overflowed.');
                    }
                    $total += $state['bytes'];
                    $active++;
                } catch (\Throwable) {
                    $unsafe++;
                    $ambiguousAccounting = true;
                }
            }
            $unsafe += $this->countUnaccountedManagedFiles($accounted);
            $unsafe += $this->countUnknownControlEntries();
            $unsafe += $this->inspectProcessControls();
            if ($ambiguousAccounting) {
                $total = $this->budgetBytes;
            }

            $physical = $this->filesystemCapacity();
            $free = $physical['minimumFree'];
            $logical = max(0, $this->budgetBytes - $total);
            $effective = min($logical, $physical['minimumAvailable']);
            $this->writeTotal($total);

            return new StorageTempBudgetSnapshot(
                $this->budgetBytes,
                $total,
                $logical,
                $free,
                $this->filesystemReserveBytes,
                $effective,
                $active,
                $unsafe,
                $this->orphanedBytes($accounted, $ambiguousAccounting),
            );
        });
    }

    public function close(): void
    {
        if (! is_resource($this->processLeaseHandle)) {
            return;
        }
        $handle = $this->processLeaseHandle;
        $this->processLeaseHandle = null;
        try {
            $this->withLock(function (): void {
                @unlink($this->processStatePath($this->processLeasePath));
                @unlink($this->processStatePath($this->processLeasePath) . '.tmp');
                @unlink($this->processLeasePath);
            });
        } catch (\Throwable) {
            // A dead process lease is removed by the next coordinator startup.
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }

    public function __destruct()
    {
        $this->close();
    }

    private function ensurePrivateControlDirectory(): void
    {
        $existing = @lstat($this->leaseDir);
        if ($existing === false) {
            if (! @mkdir($this->leaseDir, 0o700) && @lstat($this->leaseDir) === false) {
                throw new \RuntimeException('Cannot create storage temporary reservation directory.');
            }
            $existing = @lstat($this->leaseDir);
        }
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($existing === false || ($existing['mode'] & 0o170000) !== 0o040000
            || ($existing['mode'] & 0o077) !== 0
            || ($owner !== null && $existing['uid'] !== $owner)) {
            throw new \RuntimeException('Storage temporary reservation directory must be private and process-owned.');
        }
    }

    private function createProcessLease(): void
    {
        $handle = @fopen($this->processLeasePath, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create storage temporary process lease.');
        }
        try {
            if (! @chmod($this->processLeasePath, 0o600) || ! flock($handle, LOCK_SH | LOCK_NB)) {
                throw new \RuntimeException('Cannot protect storage temporary process lease.');
            }
            $this->writeCurrentProcessState();
            $this->processLeaseHandle = $handle;
        } catch (\Throwable $error) {
            @fclose($handle);
            @unlink($this->processLeasePath);
            @unlink($this->processStatePath($this->processLeasePath));
            throw $error;
        }
    }

    private function reapDeadProcessesAndAssertConfiguration(): void
    {
        if ($this->inspectProcessControls(true) > 0) {
            // Unsafe controls are retained for bounded readiness diagnostics.
        }
    }

    private function inspectProcessControls(bool $throwOnConfigurationMismatch = false): int
    {
        $unsafe = 0;
        $pairedStates = [];
        foreach ($this->processLeasePaths() as $path) {
            $statePath = $this->processStatePath($path);
            try {
                $handle = $this->openRegularControlFile($path, 'storage temporary process lease', 'r+b');
                try {
                    $raw = $this->readRegularControlFile($statePath, 'storage temporary process state');
                    $state = json_decode($raw, true);
                    if (! is_array($state) || ! is_array($state['namespaces'] ?? null)
                        || ! is_int($state['budget'] ?? null) || ! is_int($state['reserve'] ?? null)) {
                        throw new \RuntimeException('Storage temporary process state is invalid.');
                    }
                    if (($state['namespaces']['node'] ?? null) !== $this->tempDir) {
                        throw new \RuntimeException('Storage temporary process node namespace is invalid.');
                    }
                    foreach ($state['namespaces'] as $name => $root) {
                        if (! is_string($name) || ! is_string($root) || ($this->namespaces[$name] ?? null) !== $root) {
                            throw new \RuntimeException('Storage temporary process namespace configuration is invalid.');
                        }
                    }
                    $pairedStates[$statePath] = true;
                    if (flock($handle, LOCK_EX | LOCK_NB)) {
                        @unlink($path);
                        @unlink($statePath);
                        continue;
                    }
                    if ($state['budget'] !== $this->budgetBytes || $state['reserve'] !== $this->filesystemReserveBytes) {
                        if ($throwOnConfigurationMismatch) {
                            throw new \RuntimeException('Processes sharing a node temporary directory must use the same budget and filesystem reserve.');
                        }
                        $unsafe++;
                    }
                } finally {
                    fclose($handle);
                }
            } catch (\Throwable $error) {
                if ($throwOnConfigurationMismatch && str_contains($error->getMessage(), 'same budget')) {
                    throw $error;
                }
                $unsafe++;
            }
        }
        foreach (glob($this->leaseDir . DIRECTORY_SEPARATOR . 'process-state-*') ?: [] as $statePath) {
            if (str_ends_with($statePath, '.tmp') || ! isset($pairedStates[$statePath])) {
                $unsafe++;
            }
        }

        return $unsafe;
    }

    private function writeCurrentProcessState(): void
    {
        $payload = json_encode([
            'budget' => $this->budgetBytes,
            'reserve' => $this->filesystemReserveBytes,
            'namespaces' => $this->namespaces,
        ], JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->processStatePath($this->processLeasePath), $payload);
    }

    /** Drop roots no live process or retained reservation can still use. */
    private function pruneInactiveNamespaces(): void
    {
        $active = ['node' => true];
        foreach ($this->processLeasePaths() as $path) {
            $raw = $this->readRegularControlFile($this->processStatePath($path), 'storage temporary process state');
            $state = json_decode($raw, true);
            foreach (is_array($state['namespaces'] ?? null) ? $state['namespaces'] : [] as $name => $root) {
                if (is_string($name) && is_string($root) && ($this->namespaces[$name] ?? null) === $root) {
                    $active[$name] = true;
                }
            }
        }
        foreach ($this->leasePaths() as $leasePath) {
            try {
                $active[$this->readState($leasePath)['namespace']] = true;
            } catch (\Throwable) {
                // Corrupt/ambiguous reservations stay visible to readiness;
                // retaining all roots is safer than guessing their namespace.
                return;
            }
        }
        $this->namespaces = array_intersect_key($this->namespaces, $active);
        $this->writeNamespaces();
    }

    private function reapAbandonedReservations(): void
    {
        foreach ($this->leasePaths() as $leasePath) {
            $leaseInfo = @lstat($leasePath);
            if ($leaseInfo === false || ($leaseInfo['mode'] & 0o170000) !== self::REGULAR_FILE_MODE) {
                continue;
            }
            try {
                $handle = $this->openRegularControlFile($leasePath, 'storage temporary reservation', 'r+b');
            } catch (\Throwable) {
                continue;
            }
            try {
                $opened = fstat($handle);
                if ($opened === false || $opened['dev'] !== $leaseInfo['dev'] || $opened['ino'] !== $leaseInfo['ino']
                    || ! flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                try {
                    $state = $this->readState($leasePath);
                } catch (\Throwable) {
                    continue;
                }

                if ($this->pathExistsWithoutFollowing($state['tempPath'])) {
                    if (! $this->identityMatches($state['tempPath'], $state)) {
                        continue;
                    }
                    @unlink($state['tempPath']);
                }
                if (! $this->pathExistsWithoutFollowing($state['tempPath'])) {
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
            if (! $this->pathExistsWithoutFollowing($leasePath)) {
                $info = @lstat($statePath);
                if ($info !== false && $this->isOwnedPrivateRegularFile($info)) {
                    @unlink($statePath);
                }
            }
        }
    }

    /** @return array{namespace:string,tempPath:string,purpose:string,bytes:int,nonce:string,device:int,inode:int,owner:int} */
    private function readState(string $leasePath): array
    {
        $raw = $this->readRegularControlFile($this->statePath($leasePath), 'storage temporary reservation state');
        try {
            $state = json_decode($raw, true, 16, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Storage staging reservation is corrupt.', 0, $error);
        }
        if (! is_array($state)) {
            throw new \RuntimeException('Storage temporary reservation is invalid.');
        }

        // Read the earlier absolute-path schema only to recover safely during upgrade.
        $namespace = $state['namespace'] ?? 'node';
        if (is_string($namespace) && ! isset($this->namespaces[$namespace])) {
            $this->loadNamespaces();
        }
        $relative = $state['relativePath'] ?? null;
        $root = is_string($namespace) ? ($this->namespaces[$namespace] ?? null) : null;
        $tempPath = is_string($relative) && is_string($root)
            ? $root . DIRECTORY_SEPARATOR . $relative
            : ($state['tempPath'] ?? null);
        $purpose = $state['purpose'] ?? 'storage';
        $nonce = $state['nonce'] ?? str_repeat('0', 32);
        if (! is_string($namespace) || ! isset($this->namespaces[$namespace])
            || ! is_string($tempPath) || ! is_string($purpose) || ! is_string($nonce)
            || ! is_int($state['bytes'] ?? null) || $state['bytes'] < 0) {
            throw new \RuntimeException('Storage temporary reservation is invalid.');
        }
        $managed = $this->resolveManagedPath($tempPath, $namespace);
        if ($managed['namespace'] !== $namespace) {
            throw new \RuntimeException('Storage temporary reservation namespace changed.');
        }
        $tempPath = $managed['path'];

        $device = $state['device'] ?? null;
        $inode = $state['inode'] ?? null;
        $owner = $state['owner'] ?? null;
        if (! is_int($device) || ! is_int($inode) || ! is_int($owner)) {
            // An older record without captured identity is not authority to
            // adopt and delete whichever inode happens to occupy the path.
            throw new \RuntimeException('Storage temporary reservation recovery identity is missing.');
        }

        return [
            'namespace' => $namespace,
            'tempPath' => $tempPath,
            'purpose' => $purpose,
            'bytes' => $state['bytes'],
            'nonce' => $nonce,
            'device' => $device,
            'inode' => $inode,
            'owner' => $owner,
        ];
    }

    /** @param array{device:int,inode:int,owner:int} $identity */
    private function writeState(
        string $leasePath,
        string $namespace,
        string $tempPath,
        string $purpose,
        int $bytes,
        string $nonce,
        array $identity,
    ): void {
        $payload = json_encode([
            'namespace' => $namespace,
            'purpose' => $purpose,
            'bytes' => $bytes,
            'nonce' => $nonce,
            'relativePath' => basename($tempPath),
            'device' => $identity['device'],
            'inode' => $identity['inode'],
            'owner' => $identity['owner'],
        ], JSON_THROW_ON_ERROR);
        $this->atomicWrite($this->statePath($leasePath), $payload);
    }

    /** @return array{device:int,inode:int,owner:int} */
    private function managedFileIdentity(string $path): array
    {
        $info = @lstat($path);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        if ($info === false || ($info['mode'] & 0o170000) !== self::REGULAR_FILE_MODE
            || ($owner !== null && $info['uid'] !== $owner)) {
            throw new \RuntimeException('Storage temporary file must be a process-owned regular file.');
        }

        return ['device' => $info['dev'], 'inode' => $info['ino'], 'owner' => $info['uid']];
    }

    /** @param array{device:int,inode:int,owner:int} $state */
    private function identityMatches(string $path, array $state): bool
    {
        $info = @lstat($path);

        return $info !== false
            && ($info['mode'] & 0o170000) === self::REGULAR_FILE_MODE
            && $info['dev'] === $state['device']
            && $info['ino'] === $state['inode']
            && $info['uid'] === $state['owner'];
    }

    /** @return array{namespace:string,path:string} */
    private function resolveManagedPath(string $path, ?string $expectedNamespace = null): array
    {
        $directory = realpath(dirname($path));
        if ($directory !== false) {
            foreach ($this->namespaces as $namespace => $root) {
                if ($expectedNamespace !== null && $namespace !== $expectedNamespace) {
                    continue;
                }
                if ($directory === $root && $this->namespaceIdentityMatches($namespace)
                    && $this->isManagedName(basename($path), $namespace)) {
                    return [
                        'namespace' => $namespace,
                        'path' => $root . DIRECTORY_SEPARATOR . basename($path),
                    ];
                }
            }
        }
        throw new \InvalidArgumentException('Storage temporary path is outside its managed namespaces.');
    }

    private function isManagedName(string $name, string $namespace): bool
    {
        return $namespace === 'node'
            ? preg_match('/^(?:s3fw-(?:put|part|get|mpu)-[A-Za-z0-9]+|s3-(?:post|md5|sha256|capacity)-[A-Za-z0-9._-]+)$/D', $name) === 1
            : preg_match('/^[A-Za-z0-9][A-Za-z0-9._-]{0,255}$/D', $name) === 1;
    }

    /** @param array<string,true> $accounted */
    private function countUnaccountedManagedFiles(array $accounted): int
    {
        $unsafe = 0;
        $scannedRoots = [];
        foreach ($this->namespaces as $namespace => $root) {
            if (! $this->namespaceIdentityMatches($namespace)) {
                $unsafe++;
                continue;
            }
            $rootKey = $this->namespaceIdentities[$namespace]['device'] . ':'
                . $this->namespaceIdentities[$namespace]['inode'];
            if (isset($scannedRoots[$rootKey])) {
                continue;
            }
            $scannedRoots[$rootKey] = true;
            $rootNamespaces = [];
            foreach ($this->namespaceIdentities as $candidate => $identity) {
                if ($identity['device'] === $this->namespaceIdentities[$namespace]['device']
                    && $identity['inode'] === $this->namespaceIdentities[$namespace]['inode']) {
                    $rootNamespaces[] = $candidate;
                }
            }
            try {
                $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable) {
                $unsafe++;
                continue;
            }
            foreach ($iterator as $entry) {
                if (! $entry instanceof \SplFileInfo) {
                    $unsafe++;
                    continue;
                }
                $path = $root . DIRECTORY_SEPARATOR . $entry->getFilename();
                if ($path === $this->leaseDir && in_array('node', $rootNamespaces, true)) {
                    continue;
                }
                if ($this->isRegisteredChildNamespaceRoot($path)) {
                    continue;
                }
                if ($entry->getFilename() === LocalObjectTestPublishPauseIo::CONTROL_DIRECTORY) {
                    $unsafe += $this->countUnsafePublishPauseControlEntries($path);
                    continue;
                }
                $managed = false;
                foreach ($rootNamespaces as $candidate) {
                    if ($this->isManagedName($entry->getFilename(), $candidate)) {
                        $managed = true;
                        break;
                    }
                }
                if (! $managed) {
                    $unsafe++;
                    continue;
                }
                if (! isset($accounted[$path])) {
                    $unsafe++;
                }
            }
        }

        return $unsafe;
    }

    /** @param array<string,true> $accounted */
    private function orphanedBytes(array $accounted, bool $ambiguousAccounting): int
    {
        if ($ambiguousAccounting) {
            return $this->budgetBytes;
        }
        $bytes = 0;
        $scannedRoots = [];
        foreach ($this->namespaces as $namespace => $root) {
            if (! $this->namespaceIdentityMatches($namespace)) {
                return $this->budgetBytes;
            }
            $rootKey = $this->namespaceIdentities[$namespace]['device'] . ':'
                . $this->namespaceIdentities[$namespace]['inode'];
            if (isset($scannedRoots[$rootKey])) {
                continue;
            }
            $scannedRoots[$rootKey] = true;
            try {
                $iterator = new \FilesystemIterator($root, \FilesystemIterator::SKIP_DOTS);
            } catch (\Throwable) {
                return $this->budgetBytes;
            }
            foreach ($iterator as $entry) {
                if (! $entry instanceof \SplFileInfo) {
                    return $this->budgetBytes;
                }
                $path = $root . DIRECTORY_SEPARATOR . $entry->getFilename();
                if (($namespace === 'node' && $path === $this->leaseDir)
                    || $this->isRegisteredChildNamespaceRoot($path) || isset($accounted[$path])) {
                    continue;
                }
                $info = @lstat($path);
                $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
                if ($info === false || ($info['mode'] & 0o170000) !== self::REGULAR_FILE_MODE
                    || ($owner !== null && $info['uid'] !== $owner)) {
                    return $this->budgetBytes;
                }
                $size = max(0, (int) $info['size']);
                $bytes = $size > $this->budgetBytes - $bytes ? $this->budgetBytes : $bytes + $size;
            }
        }

        return $bytes;
    }

    private function countUnsafePublishPauseControlEntries(string $path): int
    {
        $directory = $this->directoryIdentity($path, true, false);
        if ($directory === null || is_link($path)) {
            return 1;
        }
        try {
            $iterator = new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable) {
            return 1;
        }

        $unsafe = 0;
        foreach ($iterator as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                $unsafe++;
                continue;
            }
            $name = $entry->getFilename();
            $info = @lstat($path . DIRECTORY_SEPARATOR . $name);
            if ($info === false || ! $this->isOwnedPrivateRegularFile($info)) {
                $unsafe++;
                continue;
            }
            if ($name === 'arm') {
                $unsafe += $info['size'] === 0 ? 0 : 1;
                continue;
            }
            if (preg_match('/^(?:claimed|release|pending-release)-[a-f0-9]{64}$/D', $name) === 1) {
                $unsafe += $info['size'] === 0 ? 0 : 1;
                continue;
            }
            if (preg_match('/^ready-[a-f0-9]{64}$/D', $name) === 1) {
                $unsafe += $info['size'] === 6 ? 0 : 1;
                continue;
            }
            if (preg_match('/^result-[a-f0-9]{64}$/D', $name) === 1) {
                $unsafe += $info['size'] === 7 ? 0 : 1;
                continue;
            }
            $unsafe++;
        }

        return $unsafe;
    }

    private function countUnknownControlEntries(): int
    {
        $unsafe = 0;
        try {
            $iterator = new \FilesystemIterator($this->leaseDir, \FilesystemIterator::SKIP_DOTS);
        } catch (\Throwable) {
            return 1;
        }
        foreach ($iterator as $entry) {
            if (! $entry instanceof \SplFileInfo) {
                $unsafe++;
                continue;
            }
            $name = $entry->getFilename();
            if (str_ends_with($name, '.tmp')) {
                $unsafe++;
                continue;
            }
            if (in_array($name, ['lock', 'budget', 'reserve', 'total', 'namespaces'], true)
                || preg_match('/^(?:lease|state|process-lease|process-state)-[a-f0-9]{32}$/D', $name) === 1) {
                $info = @lstat($this->leaseDir . DIRECTORY_SEPARATOR . $name);
                if ($info !== false && $this->isOwnedPrivateRegularFile($info)) {
                    continue;
                }
            }
            $unsafe++;
        }

        return $unsafe;
    }

    private function effectiveAvailableBytes(int $reserved, ?string $targetNamespace = null): int
    {
        $physical = $this->filesystemCapacity($targetNamespace);

        return min(
            max(0, $this->budgetBytes - $reserved),
            $physical['minimumAvailable'],
        );
    }

    /** @return array{minimumFree:int,minimumAvailable:int} */
    private function filesystemCapacity(?string $targetNamespace = null): array
    {
        /** @var array<int,array{free:int,commitment:int}> $filesystems */
        $filesystems = [];
        foreach ($this->namespaces as $name => $root) {
            if (! $this->namespaceIdentityMatches($name)) {
                throw new \RuntimeException('Storage temporary namespace root identity changed.');
            }
            $device = $this->namespaceIdentities[$name]['device'];
            if (isset($filesystems[$device])) {
                continue;
            }
            $free = @disk_free_space($root);
            if ($free === false || ! is_finite($free) || $free < 0) {
                throw new \RuntimeException('Cannot determine storage temporary filesystem capacity.');
            }
            $filesystems[$device] = [
                'free' => (int) min((float) PHP_INT_MAX, floor($free)),
                'commitment' => 0,
            ];
        }

        foreach ($this->leasePaths() as $leasePath) {
            try {
                $state = $this->readState($leasePath);
                $device = $this->namespaceIdentities[$state['namespace']]['device'];
                if (! isset($filesystems[$device])) {
                    throw new \RuntimeException('Storage temporary reservation filesystem is unavailable.');
                }
                $info = @lstat($state['tempPath']);
                if ($info === false || ! $this->identityMatches($state['tempPath'], $state)) {
                    $filesystems[$device]['commitment'] = $this->budgetBytes;
                    continue;
                }
                $commitment = max(0, $state['bytes'] - max(0, (int) $info['size']));
                $current = $filesystems[$device]['commitment'];
                $filesystems[$device]['commitment'] = $commitment > PHP_INT_MAX - $current
                    ? PHP_INT_MAX
                    : $current + $commitment;
            } catch (\Throwable) {
                foreach ($filesystems as &$filesystem) {
                    $filesystem['commitment'] = $this->budgetBytes;
                }
                unset($filesystem);
                break;
            }
        }

        if ($targetNamespace !== null) {
            if (! isset($this->namespaceIdentities[$targetNamespace])) {
                throw new \RuntimeException('Storage temporary target namespace is unavailable.');
            }
            $targetDevice = $this->namespaceIdentities[$targetNamespace]['device'];
            $filesystems = [$targetDevice => $filesystems[$targetDevice]];
        }
        $minimumFree = PHP_INT_MAX;
        $minimumAvailable = PHP_INT_MAX;
        foreach ($filesystems as $filesystem) {
            $minimumFree = min($minimumFree, $filesystem['free']);
            $minimumAvailable = min(
                $minimumAvailable,
                max(0, $filesystem['free'] - $this->filesystemReserveBytes - $filesystem['commitment']),
            );
        }

        return [
            'minimumFree' => $minimumFree === PHP_INT_MAX ? 0 : $minimumFree,
            'minimumAvailable' => $minimumAvailable === PHP_INT_MAX ? 0 : $minimumAvailable,
        ];
    }

    private function loadNamespaces(): void
    {
        $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'namespaces';
        if (! $this->pathExistsWithoutFollowing($path)) {
            $this->namespaces = [];
            $this->namespaceIdentities = [];
            return;
        }
        try {
            $raw = $this->readRegularControlFile($path, 'storage temporary namespace registry');
        } catch (\Throwable $error) {
            if ($this->pathExistsWithoutFollowing($path)) {
                throw $error;
            }
            return;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \RuntimeException('Storage temporary namespaces are corrupt.', 0, $error);
        }
        if (! is_array($decoded)) {
            throw new \RuntimeException('Storage temporary namespaces are invalid.');
        }
        $namespaces = [];
        $identities = [];
        foreach ($decoded as $name => $entry) {
            $root = is_array($entry) ? ($entry['path'] ?? null) : $entry;
            if (! is_string($name) || ! is_string($root)
                || preg_match('/^[a-z][a-z0-9_-]{0,63}$/D', $name) !== 1) {
                throw new \RuntimeException('Storage temporary namespace entry is invalid.');
            }
            if (is_array($entry)) {
                $device = $entry['device'] ?? null;
                $inode = $entry['inode'] ?? null;
                $owner = $entry['owner'] ?? null;
                $mode = $entry['mode'] ?? null;
                if (! is_int($device) || ! is_int($inode) || ! is_int($owner) || ! is_int($mode)) {
                    throw new \RuntimeException('Storage temporary namespace identity is invalid.');
                }
                $identity = compact('device', 'inode', 'owner', 'mode');
            } else {
                $identity = $this->directoryIdentity($root, $name !== 'node', false);
            }
            if ($identity === null) {
                throw new \RuntimeException('Storage temporary namespace identity is invalid.');
            }
            $namespaces[$name] = $root;
            $identities[$name] = $identity;
        }
        $this->namespaces = $namespaces;
        $this->namespaceIdentities = $identities;
    }

    private function writeNamespaces(): void
    {
        ksort($this->namespaces);
        $persisted = [];
        foreach ($this->namespaces as $name => $root) {
            $persisted[$name] = ['path' => $root] + $this->namespaceIdentities[$name];
        }
        $payload = json_encode($persisted, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
        $this->atomicWrite($this->leaseDir . DIRECTORY_SEPARATOR . 'namespaces', $payload);
    }

    private function sumLeases(): int
    {
        $total = 0;
        foreach ($this->leasePaths() as $leasePath) {
            try {
                $bytes = $this->readState($leasePath)['bytes'];
            } catch (\Throwable) {
                // Ambiguous accounting is charged at the full bounded budget
                // until an operator removes the unsafe control entry.
                return $this->budgetBytes;
            }
            if ($bytes > PHP_INT_MAX - $total) {
                throw new \RuntimeException('Storage temporary reservation total overflowed.');
            }
            $total += $bytes;
        }

        return $total;
    }

    private function assertSafeForAdmission(): void
    {
        $accounted = [];
        foreach ($this->leasePaths() as $leasePath) {
            try {
                $state = $this->readState($leasePath);
                $accounted[$state['tempPath']] = true;
                if (! $this->identityMatches($state['tempPath'], $state)) {
                    throw new \RuntimeException('Storage temporary reservation identity changed.');
                }
                $info = @lstat($state['tempPath']);
                if ($info === false || (int) $info['size'] > $state['bytes']) {
                    throw new \RuntimeException('Storage temporary reservation contains unaccounted bytes.');
                }
            } catch (\Throwable) {
                throw new SlowDownException('Storage staging state requires operator attention.');
            }
        }
        if ($this->countUnaccountedManagedFiles($accounted) > 0 || $this->countUnknownControlEntries() > 0
            || $this->inspectProcessControls() > 0) {
            throw new SlowDownException('Storage staging state requires operator attention.');
        }
    }

    private function unsafeEntryCount(): int
    {
        $unsafe = 0;
        $accounted = [];
        foreach ($this->leasePaths() as $leasePath) {
            try {
                $state = $this->readState($leasePath);
                $accounted[$state['tempPath']] = true;
                $info = @lstat($state['tempPath']);
                $allowedBytes = $state['bytes'];
                if (! $this->identityMatches($state['tempPath'], $state)
                    || $info === false || (int) $info['size'] > $allowedBytes) {
                    $unsafe++;
                }
            } catch (\Throwable) {
                $unsafe++;
            }
        }

        return $unsafe + $this->countUnaccountedManagedFiles($accounted) + $this->countUnknownControlEntries()
            + $this->inspectProcessControls();
    }

    /** @return list<string> */
    private function leasePaths(): array
    {
        $paths = glob($this->leaseDir . DIRECTORY_SEPARATOR . 'lease-*');
        if ($paths === false) {
            throw new \RuntimeException('Cannot scan storage temporary reservations.');
        }

        return array_values(array_filter($paths, static fn(string $path): bool => preg_match('/^lease-[a-f0-9]{32}$/D', basename($path)) === 1));
    }

    /** @return list<string> */
    private function processLeasePaths(): array
    {
        $paths = glob($this->leaseDir . DIRECTORY_SEPARATOR . 'process-lease-*');
        if ($paths === false) {
            throw new \RuntimeException('Cannot scan storage temporary process leases.');
        }

        return array_values(array_filter($paths, static fn(string $path): bool => preg_match('/^process-lease-[a-f0-9]{32}$/D', basename($path)) === 1));
    }

    private function assertLeasePath(string $path): void
    {
        if (dirname($path) !== $this->leaseDir || preg_match('/^lease-[a-f0-9]{32}$/D', basename($path)) !== 1) {
            throw new \InvalidArgumentException('Storage temporary lease path is invalid.');
        }
    }

    private function assertRegularControlFile(string $path, string $purpose): void
    {
        $info = @lstat($path);
        if ($info === false || ! $this->isOwnedPrivateRegularFile($info)) {
            throw new \RuntimeException("Storage temporary {$purpose} is not a regular file.");
        }
    }

    /** @return array{device:int,inode:int,owner:int,mode:int}|null */
    private function directoryIdentity(string $path, bool $requirePrivate, bool $throw = true): ?array
    {
        $info = @lstat($path);
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
        $valid = $info !== false
            && ($info['mode'] & 0o170000) === 0o040000
            && (! $requirePrivate || (($info['mode'] & 0o077) === 0 && ($owner === null || $info['uid'] === $owner)));
        if (! $valid) {
            if ($throw) {
                throw new \RuntimeException('Storage temporary namespace root identity is unsafe.');
            }
            return null;
        }

        return [
            'device' => (int) $info['dev'],
            'inode' => (int) $info['ino'],
            'owner' => (int) $info['uid'],
            'mode' => (int) $info['mode'],
        ];
    }

    private function namespaceIdentityMatches(string $name): bool
    {
        $root = $this->namespaces[$name] ?? null;
        $expected = $this->namespaceIdentities[$name] ?? null;
        if (! is_string($root) || ! is_array($expected)) {
            return false;
        }
        $actual = $this->directoryIdentity($root, $name !== 'node', false);

        return $actual !== null
            && $actual['device'] === $expected['device']
            && $actual['inode'] === $expected['inode']
            && $actual['owner'] === $expected['owner']
            && $actual['mode'] === $expected['mode'];
    }

    private function isRegisteredChildNamespaceRoot(string $path): bool
    {
        foreach ($this->namespaces as $name => $root) {
            if ($path === $root && $this->namespaceIdentityMatches($name)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string|int,mixed> $info */
    private function isOwnedPrivateRegularFile(array $info): bool
    {
        $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;

        return ($info['mode'] & 0o170000) === self::REGULAR_FILE_MODE
            && ($info['mode'] & 0o077) === 0
            && ($owner === null || $info['uid'] === $owner);
    }

    /** @return resource */
    private function openRegularControlFile(string $path, string $purpose, string $mode)
    {
        clearstatcache(true, $path);
        $before = @lstat($path);
        if ($before === false || ! $this->isOwnedPrivateRegularFile($before)) {
            throw new \RuntimeException("Cannot open {$purpose} safely.");
        }
        $handle = @fopen($path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Cannot open {$purpose}.");
        }
        $opened = @fstat($handle);
        if ($opened === false || ! $this->isOwnedPrivateRegularFile($opened)
            || $opened['dev'] !== $before['dev'] || $opened['ino'] !== $before['ino']) {
            @fclose($handle);
            throw new \RuntimeException("Cannot open {$purpose} safely.");
        }

        return $handle;
    }

    private function readRegularControlFile(string $path, string $purpose): string
    {
        $handle = $this->openRegularControlFile($path, $purpose, 'rb');
        try {
            $contents = stream_get_contents($handle);
            if (! is_string($contents)) {
                throw new \RuntimeException("Cannot read {$purpose}.");
            }

            return $contents;
        } finally {
            @fclose($handle);
        }
    }

    private function statePath(string $leasePath): string
    {
        return $this->leaseDir . DIRECTORY_SEPARATOR . 'state-' . substr(basename($leasePath), 6);
    }

    private function processStatePath(string $leasePath): string
    {
        return $this->leaseDir . DIRECTORY_SEPARATOR . 'process-state-' . substr(basename($leasePath), 14);
    }

    /** @phpstan-impure */
    private function pathExistsWithoutFollowing(string $path): bool
    {
        return @lstat($path) !== false;
    }

    private function writeTotal(int $total): void
    {
        $this->writeScalar('total', $total);
    }

    private function writeScalar(string $name, int $value): void
    {
        $this->atomicWrite($this->leaseDir . DIRECTORY_SEPARATOR . $name, (string) $value);
    }

    private function atomicWrite(string $path, string $payload): void
    {
        $temporary = $path . '.tmp';
        if ($this->pathExistsWithoutFollowing($path)) {
            $this->assertRegularControlFile($path, 'capacity state');
        }
        if ($this->pathExistsWithoutFollowing($temporary)) {
            $this->assertRegularControlFile($temporary, 'capacity state temporary file');
            if (! @unlink($temporary)) {
                throw new \RuntimeException('Cannot clear stale storage temporary capacity state.');
            }
        }
        $handle = @fopen($temporary, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot create storage temporary capacity state.');
        }
        $renamed = false;
        try {
            if (! @chmod($temporary, 0o600)
                || fwrite($handle, $payload) !== strlen($payload)
                || ! fflush($handle)) {
                throw new \RuntimeException('Cannot persist storage temporary capacity state.');
            }
            $opened = @fstat($handle);
            $current = @lstat($temporary);
            if ($opened === false || $current === false || ! $this->isOwnedPrivateRegularFile($opened)
                || $opened['dev'] !== $current['dev'] || $opened['ino'] !== $current['ino']) {
                throw new \RuntimeException('Storage temporary capacity state identity changed.');
            }
            if (! @rename($temporary, $path)) {
                throw new \RuntimeException('Cannot publish storage temporary capacity state.');
            }
            $renamed = true;
        } finally {
            @fclose($handle);
            if (! $renamed) {
                $current = @lstat($temporary);
                if ($current !== false && $this->isOwnedPrivateRegularFile($current)) {
                    @unlink($temporary);
                }
            }
        }
    }

    /**
     * @template T
     * @param \Closure():T $callback
     * @return T
     */
    private function withLock(\Closure $callback): mixed
    {
        $path = $this->leaseDir . DIRECTORY_SEPARATOR . 'lock';
        if (! $this->pathExistsWithoutFollowing($path)) {
            $created = @fopen($path, 'x+b');
            if ($created === false) {
                if (! $this->pathExistsWithoutFollowing($path)) {
                    throw new \RuntimeException('Cannot create storage temporary capacity lock.');
                }
            } elseif (! @chmod($path, 0o600)) {
                if (is_resource($created)) {
                    @fclose($created);
                }
                throw new \RuntimeException('Cannot create storage temporary capacity lock.');
            }
            if (is_resource($created)) {
                @fclose($created);
            }
        }
        $handle = null;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            try {
                $handle = $this->openRegularControlFile($path, 'storage temporary capacity lock', 'r+b');
                break;
            } catch (\RuntimeException $error) {
                clearstatcache(true, $path);
                $entry = @lstat($path);
                $owner = function_exists('posix_geteuid') ? posix_geteuid() : null;
                if ($entry === false || ($entry['mode'] & 0o170000) !== self::REGULAR_FILE_MODE
                    || ($owner !== null && $entry['uid'] !== $owner) || $attempt === 99) {
                    throw $error;
                }
                // A peer may have won exclusive creation but not yet applied
                // the private mode, or the inode may have changed between the
                // lstat/open/fstat checks. Never chmod its inode; wait for the
                // owner to finish initialization, then re-open and revalidate.
                usleep(1_000);
            }
        }
        if (! is_resource($handle)) {
            throw new \RuntimeException('Cannot open storage temporary capacity lock safely.');
        }
        try {
            if (! flock($handle, LOCK_EX)) {
                throw new \RuntimeException('Cannot lock storage temporary capacity state.');
            }
            $this->loadNamespaces();

            return $callback();
        } finally {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
    }
}
