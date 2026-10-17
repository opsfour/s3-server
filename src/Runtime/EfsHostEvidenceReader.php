<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Read-only candidate for the isolated host watcher's root-owned evidence.
 *
 * Construction resolves and stats the storage root once. On hard NFS this can
 * block without a useful PHP timeout, so construct only during startup. Later
 * checks touch the local read-only evidence bind and /proc, never EFS. A
 * concurrent watcher trip can still occur after a fresh inode was opened;
 * production admission also needs exact-container termination and the SQL
 * write intent to remain open until publication is resolved. PHP hrtime(true)
 * and Python monotonic_ns must be compared on a real Linux host before use.
 * Server admission does not construct or inject this reader yet.
 */
final class EfsHostEvidenceReader implements EfsHostEvidence
{
    private const int MAX_RECORD_BYTES = 4096;

    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    private const int HEARTBEAT_NS = 20_000_000_000;

    private readonly string $evidencePath;

    private readonly string $storageRoot;

    private readonly string $storageDev;

    private readonly string $storageIno;

    private readonly string $directoryDev;

    private readonly string $directoryIno;

    /** @var Closure(): int */
    private readonly Closure $monotonicClock;

    /** @var Closure(): string */
    private readonly Closure $bootIdReader;

    /** @var Closure(string): (array<array-key, int>|false) */
    private readonly Closure $lstatReader;

    /** @var Closure(resource): (array<array-key, int>|false) */
    private readonly Closure $fstatReader;

    /** @var Closure(): (string|false) */
    private readonly Closure $mountInfoReader;

    /** @var array<string, int|string>|null */
    private ?array $pinnedIdentity = null;

    private bool $revoked = false;

    /**
     * @param Closure(): int|null $monotonicClock
     * @param Closure(): string|null $bootIdReader
     * @param (Closure(string): (array<array-key, int>|false))|null $lstatReader
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstatReader
     * @param (Closure(): (string|false))|null $mountInfoReader
     */
    public function __construct(
        private readonly string $evidenceDirectory,
        string $storageRoot,
        private readonly string $runId,
        private readonly string $efsId,
        private readonly string $node,
        private readonly string $driver,
        private readonly string $sourceSha256,
        ?Closure $monotonicClock = null,
        ?Closure $bootIdReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
        ?Closure $mountInfoReader = null,
        ?EfsWorkerEvidenceScope $workerScope = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1
            || preg_match('/^fs-[a-f0-9]{8,17}$/D', $efsId) !== 1
            || ! in_array($node, ['api-one', 'api-two'], true)
            || ! in_array($driver, ['postgres', 'mysql'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1) {
            throw new \InvalidArgumentException('Invalid exact EFS evidence scope.');
        }

        $this->monotonicClock = $monotonicClock ?? static fn(): int => hrtime(true);
        $this->bootIdReader = $bootIdReader ?? static function (): string {
            $value = @file_get_contents('/proc/sys/kernel/random/boot_id');

            return is_string($value) ? trim($value) : '';
        };
        $this->lstatReader = $lstatReader ?? static fn(string $path): array|false => @lstat($path);
        $this->fstatReader = $fstatReader ?? static fn($handle): array|false => @fstat($handle);
        $this->mountInfoReader = $mountInfoReader ?? self::nativeMountInfo(...);
        $this->evidencePath = $evidenceDirectory . '/' . $runId . '-' . $node . '-' . $driver . '.json';

        if (! str_starts_with($evidenceDirectory, '/') || str_ends_with($evidenceDirectory, '/')
            || $evidenceDirectory === '/') {
            throw new \RuntimeException('EFS evidence directory must be an existing canonical absolute path.');
        }
        $this->assertReadOnlyLocalBind();
        if (realpath($evidenceDirectory) !== $evidenceDirectory) {
            throw new \RuntimeException('EFS evidence directory must be symlink-free.');
        }
        $directory = $this->requireSafeEntry($evidenceDirectory, true);
        $this->directoryDev = self::positiveDecimal($directory['dev'] ?? null);
        $this->directoryIno = self::positiveDecimal($directory['ino'] ?? null);

        if ($workerScope !== null) {
            if ($workerScope->evidenceDirectory !== $evidenceDirectory
                || $workerScope->storageRoot !== $storageRoot
                || $workerScope->runId !== $runId || $workerScope->efsId !== $efsId
                || $workerScope->node !== $node || $workerScope->driver !== $driver
                || $workerScope->sourceSha256 !== $sourceSha256
                || $workerScope->directoryDev !== $this->directoryDev
                || $workerScope->directoryIno !== $this->directoryIno
                || ! str_starts_with($storageRoot, '/') || str_ends_with($storageRoot, '/')) {
                throw new \RuntimeException('EFS worker evidence scope does not match its local bind.');
            }
            $this->storageRoot = $storageRoot;
            $this->storageDev = self::positiveDecimal($workerScope->storageDev);
            $this->storageIno = self::positiveDecimal($workerScope->storageIno);
            $this->pinnedIdentity = $workerScope->pinnedIdentity;
            return;
        }

        $canonicalRoot = realpath($storageRoot);
        if ($canonicalRoot === false || $canonicalRoot !== $storageRoot) {
            throw new \RuntimeException('EFS storage root must be an existing canonical directory.');
        }
        // This is the only storage-root stat. A hard-NFS outage can block here.
        $storage = @stat($canonicalRoot);
        if ($storage === false) {
            throw new \RuntimeException('EFS storage root identity is unavailable.');
        }
        if (! is_int($storage['mode'] ?? null) || ($storage['mode'] & 0o170000) !== 0o040000) {
            throw new \RuntimeException('EFS storage root is not a directory.');
        }
        $this->storageDev = self::positiveDecimal($storage['dev'] ?? null);
        $this->storageIno = self::positiveDecimal($storage['ino'] ?? null);
        $this->storageRoot = $canonicalRoot;
    }

    /**
     * Construct on a process worker without touching its hard-NFS root.
     *
     * @param Closure(): int|null $monotonicClock
     * @param Closure(): string|null $bootIdReader
     * @param (Closure(string): (array<array-key, int>|false))|null $lstatReader
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstatReader
     * @param (Closure(): (string|false))|null $mountInfoReader
     */
    public static function fromWorkerScope(
        EfsWorkerEvidenceScope $scope,
        ?Closure $monotonicClock = null,
        ?Closure $bootIdReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
        ?Closure $mountInfoReader = null,
    ): self {
        return new self(
            $scope->evidenceDirectory,
            $scope->storageRoot,
            $scope->runId,
            $scope->efsId,
            $scope->node,
            $scope->driver,
            $scope->sourceSha256,
            $monotonicClock,
            $bootIdReader,
            $lstatReader,
            $fstatReader,
            $mountInfoReader,
            workerScope: $scope,
        );
    }

    /** Capture the exact watcher epoch only after a fresh host heartbeat. */
    public function workerScope(): EfsWorkerEvidenceScope
    {
        $this->assertFresh();
        if ($this->pinnedIdentity === null) {
            throw new \RuntimeException('EFS host evidence has no pinned identity.');
        }
        return new EfsWorkerEvidenceScope(
            $this->evidenceDirectory,
            $this->storageRoot,
            $this->runId,
            $this->efsId,
            $this->node,
            $this->driver,
            $this->sourceSha256,
            $this->storageDev,
            $this->storageIno,
            $this->directoryDev,
            $this->directoryIno,
            $this->pinnedIdentity,
        );
    }

    public function storageRoot(): string
    {
        return $this->storageRoot;
    }

    /** Reject and permanently latch any missing, stale, changed or malformed record. */
    public function assertFresh(): void
    {
        if ($this->revoked) {
            throw new \RuntimeException('EFS host evidence reader was revoked.');
        }

        try {
            $this->readAndValidate();
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('EFS host evidence is unavailable or invalid.');
        }
    }

    private function readAndValidate(): void
    {
        $this->assertReadOnlyLocalBind();
        if (realpath($this->evidenceDirectory) !== $this->evidenceDirectory) {
            throw new \RuntimeException('EFS evidence directory path changed.');
        }
        $directory = $this->requireSafeEntry($this->evidenceDirectory, true);
        if (self::positiveDecimal($directory['dev'] ?? null) !== $this->directoryDev
            || self::positiveDecimal($directory['ino'] ?? null) !== $this->directoryIno) {
            throw new \RuntimeException('EFS evidence directory changed.');
        }
        $before = $this->requireSafeEntry($this->evidencePath, false);
        if (($before['size'] ?? null) < 1 || ($before['size'] ?? null) > self::MAX_RECORD_BYTES) {
            throw new \RuntimeException('EFS evidence size is invalid.');
        }
        $file = @fopen($this->evidencePath, 'rb');
        if ($file === false) {
            throw new \RuntimeException('EFS evidence file is unavailable.');
        }
        try {
            $opened = ($this->fstatReader)($file);
            if (! is_array($opened) || ! self::safeEntry($opened, false)
                || ($opened['dev'] ?? null) !== ($before['dev'] ?? null)
                || ($opened['ino'] ?? null) !== ($before['ino'] ?? null)
                || ($opened['size'] ?? null) !== ($before['size'] ?? null)) {
                throw new \RuntimeException('EFS evidence file changed while opening.');
            }
            $raw = stream_get_contents($file, self::MAX_RECORD_BYTES + 1);
            if (! is_string($raw) || strlen($raw) !== $before['size'] || ! feof($file)) {
                throw new \RuntimeException('EFS evidence file changed while reading.');
            }
        } finally {
            fclose($file);
        }
        $after = $this->requireSafeEntry($this->evidencePath, false);
        if (($after['dev'] ?? null) !== ($before['dev'] ?? null)
            || ($after['ino'] ?? null) !== ($before['ino'] ?? null)
            || ($after['size'] ?? null) !== ($before['size'] ?? null)) {
            throw new \RuntimeException('EFS evidence file changed while reading.');
        }

        $record = self::decodeRecord($raw);
        $this->assertScopeAndTime($record);
        $identity = [
            'containerId' => $record['containerId'],
            'containerPid' => $record['containerPid'],
            'relayPort' => $record['relayPort'],
            'relayPid' => $record['relayPid'],
            'hostMountId' => $record['hostMountId'],
            'storageDev' => self::positiveDecimal($record['storageDev']),
            'storageIno' => self::positiveDecimal($record['storageIno']),
            'bootId' => $record['bootId'],
            'epoch' => $record['epoch'],
        ];
        if ($identity['storageDev'] !== $this->storageDev || $identity['storageIno'] !== $this->storageIno
            || ($this->pinnedIdentity !== null && $this->pinnedIdentity !== $identity)) {
            throw new \RuntimeException('EFS storage or watcher identity changed.');
        }
        $this->pinnedIdentity = $identity;
    }

    /** @return array<string, mixed> */
    private static function decodeRecord(string $raw): array
    {
        if (strlen($raw) > self::MAX_RECORD_BYTES || ! str_ends_with($raw, "\n")
            || str_contains($raw, '\\')) {
            throw new \RuntimeException('EFS evidence JSON framing is invalid.');
        }
        try {
            $record = json_decode($raw, true, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \RuntimeException('EFS evidence JSON is malformed.');
        }
        $fields = [
            'schemaVersion', 'runId', 'efsId', 'sourceSha256', 'node', 'driver',
            'containerId', 'containerPid', 'relayPort', 'relayPid', 'hostMountId',
            'storageDev', 'storageIno', 'bootId', 'epoch',
            'issuedMonotonicNs', 'expiresMonotonicNs',
        ];
        if (! is_array($record) || array_is_list($record) || count($record) !== count($fields)) {
            throw new \RuntimeException('EFS evidence fields are invalid.');
        }
        $keys = array_keys($record);
        sort($keys);
        sort($fields);
        preg_match_all('/"([A-Za-z][A-Za-z0-9]*)"\s*:/', $raw, $matches);
        $rawKeys = $matches[1];
        sort($rawKeys);
        if ($keys !== $fields || $rawKeys !== $fields) {
            throw new \RuntimeException('EFS evidence fields are missing or duplicated.');
        }

        return $record;
    }

    /** @param array<string, mixed> $record */
    private function assertScopeAndTime(array $record): void
    {
        $now = ($this->monotonicClock)();
        $boot = ($this->bootIdReader)();
        if ($record['schemaVersion'] !== 1
            || $record['runId'] !== $this->runId || $record['efsId'] !== $this->efsId
            || $record['node'] !== $this->node || $record['driver'] !== $this->driver
            || $record['sourceSha256'] !== $this->sourceSha256
            || ! is_string($boot) || preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $boot) !== 1
            || $record['bootId'] !== $boot
            || ! is_string($record['containerId']) || preg_match('/^[a-f0-9]{64}$/D', $record['containerId']) !== 1
            || ! is_int($record['containerPid']) || $record['containerPid'] < 2
            || ! is_int($record['relayPort']) || $record['relayPort'] < 1 || $record['relayPort'] > 65535
            || ! is_int($record['relayPid']) || $record['relayPid'] < 1
            || ! is_string($record['hostMountId']) || preg_match('/^[1-9][0-9]*$/D', $record['hostMountId']) !== 1
            || ! is_string($record['epoch']) || preg_match('/^[a-f0-9]{32}$/D', $record['epoch']) !== 1
            || ! is_int($now) || $now < 0
            || ! is_int($record['issuedMonotonicNs']) || ! is_int($record['expiresMonotonicNs'])
            || $record['issuedMonotonicNs'] > $now || $record['expiresMonotonicNs'] <= $now
            || $record['expiresMonotonicNs'] - $record['issuedMonotonicNs'] !== self::HEARTBEAT_NS
            || $record['expiresMonotonicNs'] - $now > self::HEARTBEAT_NS) {
            throw new \RuntimeException('EFS evidence scope or freshness is invalid.');
        }
    }

    /** @return array<array-key, int> */
    private function requireSafeEntry(string $path, bool $directory): array
    {
        $entry = ($this->lstatReader)($path);
        if (! is_array($entry) || ! self::safeEntry($entry, $directory)) {
            throw new \RuntimeException('EFS evidence ownership or file type is invalid.');
        }

        return $entry;
    }

    /** @param array<array-key, int> $entry */
    private static function safeEntry(array $entry, bool $directory): bool
    {
        $mode = $entry['mode'] ?? null;

        return is_int($mode) && is_int($entry['uid'] ?? null) && $entry['uid'] === 0
            && ($mode & 0o022) === 0
            && ($mode & 0o170000) === ($directory ? 0o040000 : 0o100000);
    }

    private function assertReadOnlyLocalBind(): void
    {
        $mountInfo = ($this->mountInfoReader)();
        if (! is_string($mountInfo) || strlen($mountInfo) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('EFS evidence mount information is unavailable.');
        }
        $bestLength = -1;
        $bestMount = null;
        foreach (explode("\n", $mountInfo) as $line) {
            if ($line === '') {
                continue;
            }
            $sections = explode(' - ', $line, 2);
            if (count($sections) !== 2) {
                throw new \RuntimeException('EFS evidence mount information is malformed.');
            }
            $before = preg_split('/\s+/', $sections[0]);
            $after = preg_split('/\s+/', $sections[1]);
            if ($before === false || $after === false || count($before) < 6 || count($after) < 3) {
                throw new \RuntimeException('EFS evidence mount information is incomplete.');
            }
            $point = self::decodeMountPath($before[4]);
            if ($point === null || ($point !== $this->evidencePath
                && ! str_starts_with($this->evidencePath, rtrim($point, '/') . '/'))) {
                continue;
            }
            if (strlen($point) === $bestLength) {
                throw new \RuntimeException('EFS evidence has ambiguous mount points.');
            }
            if (strlen($point) > $bestLength) {
                $bestLength = strlen($point);
                $bestMount = [$point, $before[5], $after[0]];
            }
        }
        if ($bestMount === null || $bestMount[0] !== $this->evidenceDirectory
            || $bestMount[2] !== 'tmpfs' || ! in_array('ro', explode(',', $bestMount[1]), true)) {
            throw new \RuntimeException('EFS evidence directory is not an exact read-only local bind.');
        }
    }

    private static function decodeMountPath(string $path): ?string
    {
        if (preg_match('/\\\\(?!040|011|012|134)/', $path) === 1) {
            return null;
        }

        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $path);
    }

    private static function positiveDecimal(mixed $value): string
    {
        if (is_int($value) && $value > 0) {
            return (string) $value;
        }
        if (is_string($value) && preg_match('/^[1-9][0-9]*$/D', $value) === 1) {
            return $value;
        }

        throw new \RuntimeException('EFS evidence identity is invalid.');
    }

    private static function nativeMountInfo(): string|false
    {
        $file = @fopen('/proc/self/mountinfo', 'rb');
        if ($file === false) {
            return false;
        }
        try {
            return stream_get_contents($file, self::MAX_MOUNTINFO_BYTES + 1);
        } finally {
            fclose($file);
        }
    }
}
