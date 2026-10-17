<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Atomically publishes one short-lived schema-v2 heartbeat on local storage.
 *
 * Apart from Linux boot and mount metadata under /proc, the writer only opens
 * paths below the configured evidence directory. It is deliberately inert
 * until publish() is called and has no EFS, SQL, socket or serving-admission
 * dependency.
 */
final class ProductionEfsWatcherEvidenceFileWriter
{
    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    private const int WRITE_CHUNK_BYTES = 64;

    private const int MAX_DIRECTORY_ENTRIES = 1024;

    private const int MAX_DIRECTORY_NAME_BYTES = 262_144;

    private readonly string $path;

    private readonly string $lockPath;

    /** @var Closure(): int */
    private readonly Closure $monotonicClock;

    /** @var Closure(): (string|false) */
    private readonly Closure $bootIdReader;

    /** @var Closure(): (string|false) */
    private readonly Closure $mountInfoReader;

    /** @var Closure(string): (array<array-key, int>|false) */
    private readonly Closure $lstatReader;

    /** @var Closure(resource): (array<array-key, int>|false) */
    private readonly Closure $fstatReader;

    /** @var Closure(resource): bool */
    private readonly Closure $sync;

    /** @var Closure(string, string): bool */
    private readonly Closure $rename;

    /** @var Closure(string): bool */
    private readonly Closure $unlink;

    /** @var Closure(string, string): void */
    private readonly Closure $fault;

    private bool $terminal = false;

    private ?int $lastIssuedMonotonicNs = null;

    private bool $publicationMayBeVisible = false;

    private ?string $pinnedMountSha256 = null;

    /** @var array{int, int}|null */
    private ?array $pinnedDirectoryIdentity = null;

    /** @var array<string, array{int, int}>|null */
    private ?array $pinnedAncestors = null;

    /** @var resource|null */
    private $lockFile = null;

    /** @var array{int, int}|null */
    private ?array $lockIdentity = null;

    private function __construct(
        private readonly ProductionEfsIdentity $identity,
        private readonly ProductionEfsHostPin $hostPin,
        private readonly int $leaseNs,
        Closure $monotonicClock,
        Closure $bootIdReader,
        Closure $mountInfoReader,
        Closure $lstatReader,
        Closure $fstatReader,
        Closure $sync,
        Closure $rename,
        Closure $unlink,
        Closure $fault,
    ) {
        // Validate the fixed lease and the independently supplied identities
        // before retaining any native I/O capability.
        ProductionEfsWatcherEvidence::issue($identity, $hostPin, 0, $leaseNs);
        $this->path = $identity->evidenceDirectory . '/'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($identity, $hostPin);
        $this->lockPath = $this->path . '.lock';
        $this->monotonicClock = $monotonicClock;
        $this->bootIdReader = $bootIdReader;
        $this->mountInfoReader = $mountInfoReader;
        $this->lstatReader = $lstatReader;
        $this->fstatReader = $fstatReader;
        $this->sync = $sync;
        $this->rename = $rename;
        $this->unlink = $unlink;
        $this->fault = $fault;
    }

    public function __destruct()
    {
        if (is_resource($this->lockFile)) {
            @flock($this->lockFile, LOCK_UN);
            fclose($this->lockFile);
        }
    }

    private function __clone() {}

    public static function native(
        ProductionEfsIdentity $identity,
        ProductionEfsHostPin $hostPin,
        int $leaseNs = 10_000_000_000,
    ): self {
        if (PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('Production EFS heartbeat writer requires Linux.');
        }

        return self::withIoForTests(
            $identity,
            $hostPin,
            $leaseNs,
            static fn(): int => hrtime(true),
            self::nativeBootId(...),
            self::nativeMountInfo(...),
            static function (string $path): array|false {
                clearstatcache(true, $path);

                return @lstat($path);
            },
            static fn($handle): array|false => @fstat($handle),
            static fn($handle): bool => @fsync($handle),
            static fn(string $source, string $target): bool => @rename($source, $target),
            static fn(string $path): bool => @unlink($path),
            static function (string $_event, string $_path): void {},
        );
    }

    /**
     * Test-only construction hook kept private so production callers cannot
     * replace filesystem security operations.
     *
     * @param Closure(): int $monotonicClock
     * @param Closure(): (string|false) $bootIdReader
     * @param Closure(): (string|false) $mountInfoReader
     * @param Closure(string): (array<array-key, int>|false) $lstatReader
     * @param Closure(resource): (array<array-key, int>|false) $fstatReader
     * @param Closure(resource): bool $sync
     * @param Closure(string, string): bool $rename
     * @param Closure(string): bool $unlink
     * @param Closure(string, string): void $fault
     */
    private static function withIoForTests(
        ProductionEfsIdentity $identity,
        ProductionEfsHostPin $hostPin,
        int $leaseNs,
        Closure $monotonicClock,
        Closure $bootIdReader,
        Closure $mountInfoReader,
        Closure $lstatReader,
        Closure $fstatReader,
        Closure $sync,
        Closure $rename,
        Closure $unlink,
        Closure $fault,
    ): self {
        return new self(
            $identity,
            $hostPin,
            $leaseNs,
            $monotonicClock,
            $bootIdReader,
            $mountInfoReader,
            $lstatReader,
            $fstatReader,
            $sync,
            $rename,
            $unlink,
            $fault,
        );
    }

    public function publish(): ProductionEfsWatcherEvidence
    {
        if ($this->terminal) {
            throw new \RuntimeException('Production EFS heartbeat writer is unavailable.');
        }

        try {
            return $this->publishAtomically();
        } catch (\Throwable) {
            $this->terminal = true;
            if ($this->publicationMayBeVisible) {
                $this->invalidateVisibleRecord();
            }

            throw new \RuntimeException('Production EFS heartbeat publish failed.');
        }
    }

    /** Remove the current heartbeat durably. The writer is terminal afterwards. */
    public function revoke(): void
    {
        if ($this->terminal) {
            throw new \RuntimeException('Production EFS heartbeat writer is unavailable.');
        }
        $this->terminal = true;

        try {
            $before = $this->environment();
            $this->acquireLock();
            $this->assertSameEnvironment($before);
            $current = ($this->lstatReader)($this->path);
            if ($current === false) {
                $this->assertProvenAbsent($this->path, $before);
                $this->syncDirectory($before['directoryIdentity']);
                $this->assertSameEnvironment($before);

                return;
            }
            $record = self::requireSafeEntry($current, false);
            if (($record['nlink'] ?? null) !== 1) {
                throw new \RuntimeException('Production EFS heartbeat link count is invalid.');
            }
            $recordIdentity = self::entryIdentity($record);
            $revokedPath = $this->temporaryPath('revoked');
            ($this->fault)('before-revoke-rename', $this->path);
            if (! ($this->rename)($this->path, $revokedPath)) {
                throw new \RuntimeException('Production EFS heartbeat revoke rename failed.');
            }
            ($this->fault)('after-revoke-rename', $revokedPath);
            $moved = self::requireSafeEntry(($this->lstatReader)($revokedPath), false);
            if (self::entryIdentity($moved) !== $recordIdentity || ($moved['nlink'] ?? null) !== 1
                || is_array(($this->lstatReader)($this->path))) {
                throw new \RuntimeException('Production EFS heartbeat revoke identity changed.');
            }
            if (! ($this->unlink)($revokedPath)) {
                throw new \RuntimeException('Production EFS heartbeat revoke removal failed.');
            }
            ($this->fault)('after-revoke-unlink', $revokedPath);
            $this->assertProvenAbsent($this->path, $before);
            $this->assertProvenAbsent($revokedPath, $before);
            $this->assertSameEnvironment($before);
            $this->syncDirectory($before['directoryIdentity']);
            $this->assertSameEnvironment($before);
        } catch (\Throwable) {
            throw new \RuntimeException('Production EFS heartbeat revoke failed.');
        }
    }

    private function publishAtomically(): ProductionEfsWatcherEvidence
    {
        $before = $this->environment();
        $this->acquireLock();
        $this->assertSameEnvironment($before);
        $this->assertNoAbandonedTemporaryFile($before);
        $issued = ($this->monotonicClock)();
        if ($issued < 0 || ($this->lastIssuedMonotonicNs !== null && $issued <= $this->lastIssuedMonotonicNs)) {
            throw new \RuntimeException('Production EFS heartbeat clock did not progress.');
        }
        $evidence = ProductionEfsWatcherEvidence::issue($this->identity, $this->hostPin, $issued, $this->leaseNs);
        $baseline = $this->baseline($before);
        if ($baseline !== null && ($evidence->issuedMonotonicNs <= $baseline->issuedMonotonicNs
            || $evidence->expiresMonotonicNs <= $baseline->expiresMonotonicNs)) {
            throw new \RuntimeException('Production EFS heartbeat would regress stored time.');
        }
        $json = $evidence->toJson();
        if (strlen($json) > 4096) {
            throw new \RuntimeException('Production EFS heartbeat record is too large.');
        }
        $temporaryPath = $this->temporaryPath('tmp');
        $temporaryExists = false;
        $renamed = false;

        try {
            $file = @fopen($temporaryPath, 'x+b');
            if ($file === false) {
                throw new \RuntimeException('Production EFS heartbeat temporary file is unavailable.');
            }
            $temporaryExists = true;
            try {
                if (! @chmod($temporaryPath, 0o644)) {
                    throw new \RuntimeException('Production EFS heartbeat temporary mode failed.');
                }
                ($this->fault)('after-temp-chmod', $temporaryPath);
                $opened = self::requireSafeEntry(($this->fstatReader)($file), false);
                if (($opened['nlink'] ?? null) !== 1 || (($opened['mode'] ?? 0) & 0o777) !== 0o644) {
                    throw new \RuntimeException('Production EFS heartbeat temporary file is unsafe.');
                }
                $identity = self::entryIdentity($opened);
                $offset = 0;
                while ($offset < strlen($json)) {
                    $chunk = substr($json, $offset, self::WRITE_CHUNK_BYTES);
                    $written = @fwrite($file, $chunk);
                    if (! is_int($written) || $written !== strlen($chunk)) {
                        throw new \RuntimeException('Production EFS heartbeat write was incomplete.');
                    }
                    $offset += $written;
                    ($this->fault)('after-write-chunk', $temporaryPath);
                }
                if (! @fflush($file)) {
                    throw new \RuntimeException('Production EFS heartbeat flush failed.');
                }
                ($this->fault)('before-file-fsync', $temporaryPath);
                if (! ($this->sync)($file)) {
                    throw new \RuntimeException('Production EFS heartbeat sync failed.');
                }
                ($this->fault)('after-file-fsync', $temporaryPath);
                $afterWrite = self::requireSafeEntry(($this->fstatReader)($file), false);
                if (self::entryIdentity($afterWrite) !== $identity || ($afterWrite['nlink'] ?? null) !== 1
                    || ($afterWrite['size'] ?? null) !== strlen($json)) {
                    throw new \RuntimeException('Production EFS heartbeat temporary file changed.');
                }
            } finally {
                if (is_resource($file)) {
                    fclose($file);
                }
            }

            $staged = self::requireSafeEntry(($this->lstatReader)($temporaryPath), false);
            if (self::entryIdentity($staged) !== $identity || ($staged['nlink'] ?? null) !== 1
                || ($staged['size'] ?? null) !== strlen($json)) {
                throw new \RuntimeException('Production EFS heartbeat stage changed before publish.');
            }
            $this->assertSameEnvironment($before);
            $this->assertHeartbeatStillLive($evidence, $issued);
            ($this->fault)('before-publish-rename', $temporaryPath);
            $this->publicationMayBeVisible = true;
            if (! ($this->rename)($temporaryPath, $this->path)) {
                throw new \RuntimeException('Production EFS heartbeat rename failed.');
            }
            $renamed = true;
            $temporaryExists = false;
            ($this->fault)('after-publish-rename', $this->path);
            $this->assertPublished($identity, $json);
            $this->assertSameEnvironment($before);
            $this->syncDirectory($before['directoryIdentity']);
            $this->assertSameEnvironment($before);
            $this->assertHeartbeatStillLive($evidence, $issued);
        } finally {
            if ($temporaryExists && ! $renamed) {
                ($this->unlink)($temporaryPath);
            }
        }

        $this->lastIssuedMonotonicNs = $issued;
        $this->publicationMayBeVisible = false;

        return $evidence;
    }

    private function acquireLock(): void
    {
        if (is_resource($this->lockFile)) {
            $this->assertLockIdentity();

            return;
        }

        $file = @fopen($this->lockPath, 'x+b');
        if ($file !== false) {
            if (! @chmod($this->lockPath, 0o600)) {
                fclose($file);
                throw new \RuntimeException('Production EFS heartbeat lock mode failed.');
            }
        } else {
            $before = self::requireSafeEntry(($this->lstatReader)($this->lockPath), false);
            if (($before['nlink'] ?? null) !== 1 || (($before['mode'] ?? 0) & 0o777) !== 0o600) {
                throw new \RuntimeException('Production EFS heartbeat lock is unsafe.');
            }
            $file = @fopen($this->lockPath, 'r+b');
        }
        if ($file === false) {
            throw new \RuntimeException('Production EFS heartbeat lock is unavailable.');
        }

        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($file), false);
            $path = self::requireSafeEntry(($this->lstatReader)($this->lockPath), false);
            $identity = self::entryIdentity($opened);
            if (self::entryIdentity($path) !== $identity || ($opened['nlink'] ?? null) !== 1
                || ($path['nlink'] ?? null) !== 1 || (($opened['mode'] ?? 0) & 0o777) !== 0o600
                || ! @flock($file, LOCK_EX | LOCK_NB)) {
                throw new \RuntimeException('Production EFS heartbeat writer lock is held or changed.');
            }
            $this->lockFile = $file;
            $this->lockIdentity = $identity;
        } catch (\Throwable $error) {
            fclose($file);

            throw $error;
        }
    }

    private function assertLockIdentity(): void
    {
        if ($this->lockFile === null && $this->lockIdentity === null) {
            return;
        }
        if (! is_resource($this->lockFile) || $this->lockIdentity === null) {
            throw new \RuntimeException('Production EFS heartbeat writer lock was lost.');
        }
        $opened = self::requireSafeEntry(($this->fstatReader)($this->lockFile), false);
        $path = self::requireSafeEntry(($this->lstatReader)($this->lockPath), false);
        if (self::entryIdentity($opened) !== $this->lockIdentity
            || self::entryIdentity($path) !== $this->lockIdentity
            || ($opened['nlink'] ?? null) !== 1 || ($path['nlink'] ?? null) !== 1
            || (($opened['mode'] ?? 0) & 0o777) !== 0o600) {
            throw new \RuntimeException('Production EFS heartbeat writer lock changed.');
        }
    }

    /**
     * @param array{
     *   mountSha256: string,
     *   directoryIdentity: array{int, int},
     *   ancestors: array<string, array{int, int}>
     * } $environment
     */
    private function baseline(array $environment): ?ProductionEfsWatcherEvidence
    {
        $entry = ($this->lstatReader)($this->path);
        if ($entry === false) {
            $this->assertProvenAbsent($this->path, $environment);

            return null;
        }
        $entry = self::requireSafeEntry($entry, false);
        if (($entry['nlink'] ?? null) !== 1 || ! is_int($entry['size'] ?? null)
            || $entry['size'] < 1 || $entry['size'] > 4096) {
            throw new \RuntimeException('Production EFS heartbeat baseline is unsafe.');
        }
        $json = $this->readExact($this->path, self::entryIdentity($entry), $entry['size']);
        try {
            $record = json_decode($json, true, 2, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \RuntimeException('Production EFS heartbeat baseline is invalid.');
        }
        $issued = is_array($record) ? ($record['issuedMonotonicNs'] ?? null) : null;
        if (! is_int($issued)) {
            throw new \RuntimeException('Production EFS heartbeat baseline is invalid.');
        }

        return ProductionEfsWatcherEvidence::fromJson($json, $this->identity, $this->hostPin, $issued);
    }

    private function assertHeartbeatStillLive(ProductionEfsWatcherEvidence $evidence, int $minimumClock): void
    {
        $bootId = ($this->bootIdReader)();
        $now = ($this->monotonicClock)();
        if (! is_string($bootId) || trim($bootId) !== $this->hostPin->bootId
            || $now < $minimumClock || $now >= $evidence->expiresMonotonicNs) {
            throw new \RuntimeException('Production EFS heartbeat expired while publishing.');
        }
    }

    /**
     * @param array{
     *   mountSha256: string,
     *   directoryIdentity: array{int, int},
     *   ancestors: array<string, array{int, int}>
     * } $environment
     */
    private function assertNoAbandonedTemporaryFile(array $environment): void
    {
        $prefix = '.' . ProductionEfsWatcherEvidenceFileReader::fileNameFor($this->identity, $this->hostPin) . '.';
        foreach ($this->directoryEntries($environment['directoryIdentity']) as $entry) {
            if (str_starts_with($entry, $prefix)) {
                throw new \RuntimeException('Production EFS heartbeat has an abandoned temporary file.');
            }
        }
        $this->assertSameEnvironment($environment);
    }

    /** Best effort only; publish() still reports failure and poisons this writer. */
    private function invalidateVisibleRecord(): void
    {
        try {
            $before = $this->environment();
            $entry = ($this->lstatReader)($this->path);
            if ($entry !== false) {
                $entry = self::requireSafeEntry($entry, false);
                if (($entry['nlink'] ?? null) !== 1 || ! ($this->unlink)($this->path)) {
                    return;
                }
            }
            $this->assertProvenAbsent($this->path, $before);
            $this->syncDirectory($before['directoryIdentity']);
            $this->assertSameEnvironment($before);
        } catch (\Throwable) {
            // The public result remains failure; the short lease is the final
            // fail-safe when local durable invalidation itself is ambiguous.
        }
    }

    /**
     * @param array{
     *   mountSha256: string,
     *   directoryIdentity: array{int, int},
     *   ancestors: array<string, array{int, int}>
     * } $environment
     */
    private function assertProvenAbsent(string $path, array $environment): void
    {
        $name = basename($path);
        foreach ($this->directoryEntries($environment['directoryIdentity']) as $entry) {
            if ($entry === $name) {
                throw new \RuntimeException('Production EFS heartbeat path absence is ambiguous.');
            }
        }
        if (($this->lstatReader)($path) !== false) {
            throw new \RuntimeException('Production EFS heartbeat path is still present.');
        }
        $this->assertSameEnvironment($environment);
    }

    /**
     * @param array{int, int} $directoryIdentity
     * @return list<string>
     */
    private function directoryEntries(array $directoryIdentity): array
    {
        $identityHandle = @fopen($this->identity->evidenceDirectory, 'rb');
        if ($identityHandle === false) {
            throw new \RuntimeException('Production EFS heartbeat directory cannot be opened.');
        }
        $directory = @opendir($this->identity->evidenceDirectory);
        if ($directory === false) {
            fclose($identityHandle);
            throw new \RuntimeException('Production EFS heartbeat directory cannot be listed.');
        }
        $entries = [];
        $bytes = 0;
        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($identityHandle), true);
            if (self::entryIdentity($opened) !== $directoryIdentity) {
                throw new \RuntimeException('Production EFS heartbeat directory changed while listing.');
            }
            while (($entry = readdir($directory)) !== false) {
                if ($entry === '.' || $entry === '..') {
                    continue;
                }
                $bytes += strlen($entry);
                if (count($entries) >= self::MAX_DIRECTORY_ENTRIES || $bytes > self::MAX_DIRECTORY_NAME_BYTES) {
                    throw new \RuntimeException('Production EFS heartbeat directory exceeds its safe bound.');
                }
                $entries[] = $entry;
            }
        } finally {
            closedir($directory);
            fclose($identityHandle);
        }

        return $entries;
    }

    /** @param array{int, int} $identity */
    private function readExact(string $path, array $identity, int $size): string
    {
        $file = @fopen($path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Production EFS heartbeat file is unavailable.');
        }
        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($file), false);
            $json = stream_get_contents($file, $size + 1);
            if (self::entryIdentity($opened) !== $identity || ($opened['nlink'] ?? null) !== 1
                || ($opened['size'] ?? null) !== $size || ! is_string($json)
                || strlen($json) !== $size || ! feof($file)) {
                throw new \RuntimeException('Production EFS heartbeat file changed while reading.');
            }
        } finally {
            fclose($file);
        }
        $after = self::requireSafeEntry(($this->lstatReader)($path), false);
        if (self::entryIdentity($after) !== $identity || ($after['nlink'] ?? null) !== 1
            || ($after['size'] ?? null) !== $size) {
            throw new \RuntimeException('Production EFS heartbeat file changed while reading.');
        }

        return $json;
    }

    /**
     * @param array{
     *   mountSha256: string,
     *   directoryIdentity: array{int, int},
     *   ancestors: array<string, array{int, int}>
     * } $expected
     */
    private function assertSameEnvironment(array $expected): void
    {
        if ($this->environment() !== $expected) {
            throw new \RuntimeException('Production EFS heartbeat environment changed.');
        }
    }

    /**
     * @return array{
     *   mountSha256: string,
     *   directoryIdentity: array{int, int},
     *   ancestors: array<string, array{int, int}>
     * }
     */
    private function environment(): array
    {
        $mountInfo = ($this->mountInfoReader)();
        if (! is_string($mountInfo) || $mountInfo === '' || strlen($mountInfo) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('Production EFS heartbeat mount information is unavailable.');
        }
        $mountSha256 = hash('sha256', $this->exactWritableMount($mountInfo));
        $bootId = ($this->bootIdReader)();
        if (! is_string($bootId) || trim($bootId) !== $this->hostPin->bootId) {
            throw new \RuntimeException('Production EFS heartbeat boot identity changed.');
        }

        $directoryIdentity = null;
        $ancestors = [];
        $prefix = '/';
        foreach (['', ...explode('/', substr($this->identity->evidenceDirectory, 1))] as $segment) {
            if ($segment !== '') {
                $prefix = rtrim($prefix, '/') . '/' . $segment;
            }
            $type = LinuxMountInfo::filesystemTypeForPath($prefix, $mountInfo);
            if ($type !== 'tmpfs' && ($type === null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type))) {
                throw new \RuntimeException('Production EFS heartbeat path is not local.');
            }
            $entry = self::requireSafeEntry(($this->lstatReader)($prefix), true);
            $ancestors[$prefix] = self::entryIdentity($entry);
            if ($prefix === $this->identity->evidenceDirectory) {
                $directoryIdentity = self::entryIdentity($entry);
            }
        }
        if ($directoryIdentity === null
            || ($this->pinnedMountSha256 !== null && $mountSha256 !== $this->pinnedMountSha256)
            || ($this->pinnedDirectoryIdentity !== null && $directoryIdentity !== $this->pinnedDirectoryIdentity)
            || ($this->pinnedAncestors !== null && $ancestors !== $this->pinnedAncestors)) {
            throw new \RuntimeException('Production EFS heartbeat directory identity changed.');
        }
        $this->pinnedMountSha256 ??= $mountSha256;
        $this->pinnedDirectoryIdentity ??= $directoryIdentity;
        $this->pinnedAncestors ??= $ancestors;

        $this->assertLockIdentity();

        return [
            'mountSha256' => $mountSha256,
            'directoryIdentity' => $directoryIdentity,
            'ancestors' => $ancestors,
        ];
    }

    private function exactWritableMount(string $mountInfo): string
    {
        $matching = [];
        foreach (explode("\n", $mountInfo) as $line) {
            if ($line === '') {
                continue;
            }
            $sections = explode(' - ', $line, 2);
            $before = isset($sections[0]) ? preg_split('/ +/', $sections[0]) : false;
            $after = isset($sections[1]) ? preg_split('/ +/', $sections[1]) : false;
            if (count($sections) !== 2 || $before === false || $after === false
                || count($before) < 6 || count($after) < 3) {
                throw new \RuntimeException('Production EFS heartbeat mount information is malformed.');
            }
            $point = self::decodeMountPath($before[4]);
            if ($point === null) {
                throw new \RuntimeException('Production EFS heartbeat mount information is malformed.');
            }
            if ($point === $this->identity->evidenceDirectory) {
                $matching[] = [$line, $before[5], strtolower($after[0])];
            }
        }
        if (count($matching) !== 1 || ! in_array('rw', explode(',', $matching[0][1]), true)
            || ($matching[0][2] !== 'tmpfs' && ! LinuxMountInfo::isQualifiedLocalFilesystem($matching[0][2]))) {
            throw new \RuntimeException('Production EFS heartbeat directory is not an exact writable local mount.');
        }

        return $matching[0][0];
    }

    /** @param array{int, int} $directoryIdentity */
    private function syncDirectory(array $directoryIdentity): void
    {
        ($this->fault)('before-directory-fsync', $this->identity->evidenceDirectory);
        $directory = @fopen($this->identity->evidenceDirectory, 'rb');
        if ($directory === false) {
            throw new \RuntimeException('Production EFS heartbeat directory is unavailable.');
        }
        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($directory), true);
            if (self::entryIdentity($opened) !== $directoryIdentity || ! ($this->sync)($directory)) {
                throw new \RuntimeException('Production EFS heartbeat directory sync failed.');
            }
        } finally {
            fclose($directory);
        }
        ($this->fault)('after-directory-fsync', $this->identity->evidenceDirectory);
    }

    /** @param array{int, int} $identity */
    private function assertPublished(array $identity, string $expected): void
    {
        $entry = self::requireSafeEntry(($this->lstatReader)($this->path), false);
        if (self::entryIdentity($entry) !== $identity || ($entry['nlink'] ?? null) !== 1
            || ($entry['size'] ?? null) !== strlen($expected)) {
            throw new \RuntimeException('Production EFS heartbeat publish identity changed.');
        }
        $file = @fopen($this->path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Production EFS heartbeat publish cannot be verified.');
        }
        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($file), false);
            $actual = stream_get_contents($file, strlen($expected) + 1);
            if (self::entryIdentity($opened) !== $identity || ($opened['nlink'] ?? null) !== 1
                || $actual !== $expected || ! feof($file)) {
                throw new \RuntimeException('Production EFS heartbeat publish cannot be verified.');
            }
        } finally {
            fclose($file);
        }
        $after = self::requireSafeEntry(($this->lstatReader)($this->path), false);
        if (self::entryIdentity($after) !== $identity || ($after['nlink'] ?? null) !== 1) {
            throw new \RuntimeException('Production EFS heartbeat publish identity changed.');
        }
    }

    private function temporaryPath(string $purpose): string
    {
        return $this->identity->evidenceDirectory . '/.'
            . ProductionEfsWatcherEvidenceFileReader::fileNameFor($this->identity, $this->hostPin)
            . '.' . $purpose . '-' . bin2hex(random_bytes(16));
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>
     */
    private static function requireSafeEntry(array|false $entry, bool $directory): array
    {
        if (! is_array($entry) || ! is_int($entry['mode'] ?? null)
            || ($entry['mode'] & 0o170000) !== ($directory ? 0o040000 : 0o100000)
            || ($entry['mode'] & 0o022) !== 0 || ($entry['uid'] ?? null) !== 0) {
            throw new \RuntimeException('Production EFS heartbeat ownership or file type is invalid.');
        }

        return $entry;
    }

    /** @param array<array-key, int> $entry
     *  @return array{int, int}
     */
    private static function entryIdentity(array $entry): array
    {
        $dev = $entry['dev'] ?? null;
        $ino = $entry['ino'] ?? null;
        if (! is_int($dev) || $dev < 1 || ! is_int($ino) || $ino < 1) {
            throw new \RuntimeException('Production EFS heartbeat inode is invalid.');
        }

        return [$dev, $ino];
    }

    private static function decodeMountPath(string $path): ?string
    {
        if (preg_match('/\\\\(?!040|011|012|134)/', $path) === 1) {
            return null;
        }

        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $path);
    }

    private static function nativeMountInfo(): string|false
    {
        return @file_get_contents('/proc/self/mountinfo', false, null, 0, self::MAX_MOUNTINFO_BYTES + 1);
    }

    private static function nativeBootId(): string|false
    {
        return @file_get_contents('/proc/sys/kernel/random/boot_id', false, null, 0, 128);
    }
}
