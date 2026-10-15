<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Read one production heartbeat from its identity-derived local path.
 *
 * This listenerless reader never accesses EFS, SQL, Docker or a socket. Any
 * failed read permanently revokes the instance so repaired or replaced
 * evidence cannot silently readmit the same reader instance.
 */
final class ProductionEfsWatcherEvidenceFileReader implements EfsHostEvidence
{
    private const int MAX_RECORD_BYTES = 4096;

    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    private readonly string $path;

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

    private bool $revoked = false;

    private ?int $lastMonotonicNs = null;

    private ?int $lastIssuedMonotonicNs = null;

    private ?int $lastExpiresMonotonicNs = null;

    private ?string $pinnedEvidenceMountSha256 = null;

    /** @var array{int, int}|null */
    private ?array $pinnedEvidenceDirectoryIdentity = null;

    private ?ProductionEfsWorkerEvidenceScope $pinnedWorkerScope = null;

    /**
     * @param (Closure(): int)|null $monotonicClock
     * @param (Closure(): (string|false))|null $bootIdReader
     * @param (Closure(): (string|false))|null $mountInfoReader
     * @param (Closure(string): (array<array-key, int>|false))|null $lstatReader
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstatReader
     */
    public function __construct(
        private readonly ProductionEfsIdentity $identity,
        private readonly ProductionEfsHostPin $hostPin,
        ?Closure $monotonicClock = null,
        ?Closure $bootIdReader = null,
        ?Closure $mountInfoReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
    ) {
        if ($mountInfoReader === null && PHP_OS_FAMILY !== 'Linux') {
            throw new \RuntimeException('Production EFS heartbeat requires Linux mount information.');
        }
        $this->path = $identity->evidenceDirectory . '/' . self::fileNameFor($identity, $hostPin);
        $this->monotonicClock = $monotonicClock ?? static fn(): int => hrtime(true);
        $this->bootIdReader = $bootIdReader ?? self::nativeBootId(...);
        $this->mountInfoReader = $mountInfoReader ?? self::nativeMountInfo(...);
        $this->lstatReader = $lstatReader ?? static function (string $path): array|false {
            clearstatcache(true, $path);

            return @lstat($path);
        };
        $this->fstatReader = $fstatReader ?? static fn($handle): array|false => @fstat($handle);
    }

    public static function fileNameFor(ProductionEfsIdentity $identity, ProductionEfsHostPin $hostPin): string
    {
        $scope = implode("\n", [
            'opsfour-production-efs-heartbeat-v2',
            $identity->deploymentId,
            $identity->instanceId,
            $identity->efsId,
            $identity->sourceSha256,
            $identity->metadataDriver,
            $identity->mountPoint,
            $identity->storageRoot,
            $identity->scratchPath,
            $identity->evidenceDirectory,
            $hostPin->deploymentId,
            $hostPin->instanceId,
            $hostPin->containerId,
            $hostPin->bootId,
            $hostPin->hostMountId,
            $hostPin->storageDev,
            $hostPin->storageIno,
            $hostPin->epoch,
        ]);

        return hash('sha256', $scope) . '.json';
    }

    /**
     * Reconstruct a worker reader from the exact schema-v2 trust basis without
     * touching EFS, SQL, Docker, a socket, a listener or the evidence file.
     * The first worker check must still read a fresh matching heartbeat.
     */
    public static function fromWorkerScope(
        ProductionEfsWorkerEvidenceScope $scope,
        ?Closure $monotonicClock = null,
        ?Closure $bootIdReader = null,
        ?Closure $mountInfoReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
    ): self {
        $reader = new self(
            $scope->identity(),
            $scope->hostPin(),
            $monotonicClock,
            $bootIdReader,
            $mountInfoReader,
            $lstatReader,
            $fstatReader,
        );
        $reader->pinnedEvidenceMountSha256 = $scope->evidenceMountSha256;
        $reader->pinnedEvidenceDirectoryIdentity = [
            self::positiveDecimalInt($scope->evidenceDirectoryDev),
            self::positiveDecimalInt($scope->evidenceDirectoryIno),
        ];
        $reader->lastIssuedMonotonicNs = $scope->issuedMonotonicNs;
        $reader->lastExpiresMonotonicNs = $scope->expiresMonotonicNs;
        $reader->lastMonotonicNs = $scope->validatedMonotonicNs;
        $reader->pinnedWorkerScope = $scope;

        return $reader;
    }

    public function assertFresh(): void
    {
        $this->read();
    }

    public function read(): ProductionEfsWatcherEvidence
    {
        if ($this->revoked) {
            throw new \RuntimeException('Production EFS heartbeat reader was revoked.');
        }

        try {
            return $this->readAndValidate();
        } catch (\Throwable) {
            $this->revoked = true;

            throw new \RuntimeException('Production EFS heartbeat is unavailable or invalid.');
        }
    }

    /** Capture the exact validated schema-v2 basis for one process worker. */
    public function workerScope(): ProductionEfsWorkerEvidenceScope
    {
        $evidence = $this->read();
        if ($this->pinnedWorkerScope !== null) {
            return $this->pinnedWorkerScope;
        }
        if ($this->pinnedEvidenceMountSha256 === null
            || $this->pinnedEvidenceDirectoryIdentity === null
            || $this->lastMonotonicNs === null) {
            throw new \RuntimeException('Production EFS heartbeat has no pinned local identity.');
        }

        return $this->pinnedWorkerScope = ProductionEfsWorkerEvidenceScope::fromValidatedEvidence(
            $evidence,
            $this->pinnedEvidenceMountSha256,
            (string) $this->pinnedEvidenceDirectoryIdentity[0],
            (string) $this->pinnedEvidenceDirectoryIdentity[1],
            $this->lastMonotonicNs,
        );
    }

    private function readAndValidate(): ProductionEfsWatcherEvidence
    {
        $beforeMountInfo = $this->readMountInfo();
        $beforeEvidenceMountSha256 = hash('sha256', $this->exactEvidenceMount($beforeMountInfo));
        $beforeBootId = $this->readBootId();
        $beforeMonotonicNs = ($this->monotonicClock)();
        if ($beforeBootId !== $this->hostPin->bootId || $beforeMonotonicNs < 0
            || ($this->lastMonotonicNs !== null && $beforeMonotonicNs < $this->lastMonotonicNs)) {
            throw new \RuntimeException('Production EFS heartbeat local identity changed.');
        }

        $ancestors = [];
        $prefix = '/';
        foreach (['', ...explode('/', substr($this->identity->evidenceDirectory, 1))] as $segment) {
            if ($segment !== '') {
                $prefix = rtrim($prefix, '/') . '/' . $segment;
            }
            $this->assertLocalMount($prefix, $beforeMountInfo);
            $entry = self::requireSafeEntry(($this->lstatReader)($prefix), true);
            $ancestors[$prefix] = self::entryIdentity($entry);
        }
        $evidenceDirectoryIdentity = $ancestors[$this->identity->evidenceDirectory] ?? null;
        if (! is_array($evidenceDirectoryIdentity)
            || ($this->pinnedEvidenceMountSha256 !== null
                && $beforeEvidenceMountSha256 !== $this->pinnedEvidenceMountSha256)
            || ($this->pinnedEvidenceDirectoryIdentity !== null
                && $evidenceDirectoryIdentity !== $this->pinnedEvidenceDirectoryIdentity)) {
            throw new \RuntimeException('Production EFS heartbeat directory identity changed.');
        }

        $beforeFile = self::requireSafeEntry(($this->lstatReader)($this->path), false);
        $size = $beforeFile['size'] ?? null;
        if (! is_int($size) || $size < 1 || $size > self::MAX_RECORD_BYTES
            || ($beforeFile['nlink'] ?? null) !== 1) {
            throw new \RuntimeException('Production EFS heartbeat file is invalid.');
        }
        $fileIdentity = self::entryIdentity($beforeFile);

        $file = @fopen($this->path, 'rb');
        if ($file === false) {
            throw new \RuntimeException('Production EFS heartbeat file is unavailable.');
        }
        try {
            $opened = self::requireSafeEntry(($this->fstatReader)($file), false);
            if (self::entryIdentity($opened) !== $fileIdentity || ($opened['size'] ?? null) !== $size
                || ($opened['nlink'] ?? null) !== 1) {
                throw new \RuntimeException('Production EFS heartbeat changed while opening.');
            }
            $json = stream_get_contents($file, self::MAX_RECORD_BYTES + 1);
            if (! is_string($json) || strlen($json) !== $size || ! feof($file)) {
                throw new \RuntimeException('Production EFS heartbeat changed while reading.');
            }
        } finally {
            fclose($file);
        }

        $afterFile = self::requireSafeEntry(($this->lstatReader)($this->path), false);
        if (self::entryIdentity($afterFile) !== $fileIdentity || ($afterFile['size'] ?? null) !== $size
            || ($afterFile['nlink'] ?? null) !== 1) {
            throw new \RuntimeException('Production EFS heartbeat changed while reading.');
        }
        $afterEvidenceDirectoryIdentity = null;
        foreach ($ancestors as $ancestor => $before) {
            $this->assertLocalMount($ancestor, $beforeMountInfo);
            $entry = self::requireSafeEntry(($this->lstatReader)($ancestor), true);
            $after = self::entryIdentity($entry);
            if ($after !== $before) {
                throw new \RuntimeException('Production EFS heartbeat parent changed while reading.');
            }
            if ($ancestor === $this->identity->evidenceDirectory) {
                $afterEvidenceDirectoryIdentity = $after;
            }
        }
        $afterMountInfo = $this->readMountInfo();
        $afterEvidenceMountSha256 = hash('sha256', $this->exactEvidenceMount($afterMountInfo));
        if ($afterEvidenceMountSha256 !== $beforeEvidenceMountSha256
            || ($this->pinnedEvidenceMountSha256 !== null
                && $afterEvidenceMountSha256 !== $this->pinnedEvidenceMountSha256)
            || ! is_array($afterEvidenceDirectoryIdentity)
            || ($this->pinnedEvidenceDirectoryIdentity !== null
                && $afterEvidenceDirectoryIdentity !== $this->pinnedEvidenceDirectoryIdentity)) {
            throw new \RuntimeException('Production EFS heartbeat mount information changed.');
        }
        $afterBootId = $this->readBootId();
        $afterMonotonicNs = ($this->monotonicClock)();
        if ($afterBootId !== $beforeBootId || $afterBootId !== $this->hostPin->bootId
            || $afterMonotonicNs < $beforeMonotonicNs) {
            throw new \RuntimeException('Production EFS heartbeat local identity changed.');
        }

        $evidence = ProductionEfsWatcherEvidence::fromJson(
            $json,
            $this->identity,
            $this->hostPin,
            $afterMonotonicNs,
        );
        if ($this->lastIssuedMonotonicNs !== null && $this->lastExpiresMonotonicNs !== null) {
            $issued = $evidence->issuedMonotonicNs;
            $expires = $evidence->expiresMonotonicNs;
            if ($issued < $this->lastIssuedMonotonicNs || $expires < $this->lastExpiresMonotonicNs
                || ($issued === $this->lastIssuedMonotonicNs && $expires !== $this->lastExpiresMonotonicNs)
                || ($issued > $this->lastIssuedMonotonicNs && $expires <= $this->lastExpiresMonotonicNs)) {
                throw new \RuntimeException('Production EFS heartbeat time progression is invalid.');
            }
        }
        $this->lastMonotonicNs = $afterMonotonicNs;
        $this->lastIssuedMonotonicNs = $evidence->issuedMonotonicNs;
        $this->lastExpiresMonotonicNs = $evidence->expiresMonotonicNs;
        $this->pinnedEvidenceMountSha256 = $afterEvidenceMountSha256;
        $this->pinnedEvidenceDirectoryIdentity = $afterEvidenceDirectoryIdentity;

        return $evidence;
    }

    private function readMountInfo(): string
    {
        $value = ($this->mountInfoReader)();
        if (! is_string($value) || $value === '' || strlen($value) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('Production EFS heartbeat mount information is unavailable.');
        }

        return $value;
    }

    private function exactEvidenceMount(string $mountInfo): string
    {
        $bestLength = -1;
        $bestMount = null;
        $bestLine = null;
        $ambiguous = false;
        foreach (explode("\n", $mountInfo) as $line) {
            if ($line === '') {
                continue;
            }
            $sections = explode(' - ', $line, 2);
            if (count($sections) !== 2) {
                throw new \RuntimeException('Production EFS heartbeat mount information is malformed.');
            }
            $before = preg_split('/ +/', $sections[0]);
            $after = preg_split('/ +/', $sections[1]);
            if ($before === false || $after === false || count($before) < 6 || count($after) < 3) {
                throw new \RuntimeException('Production EFS heartbeat mount information is incomplete.');
            }
            $mountPoint = self::decodeMountPath($before[4]);
            if ($mountPoint === null || ! str_starts_with($mountPoint, '/')) {
                throw new \RuntimeException('Production EFS heartbeat mount information is malformed.');
            }
            $prefix = rtrim($mountPoint, '/') . '/';
            if ($this->path !== $mountPoint && ! str_starts_with($this->path, $prefix)) {
                continue;
            }
            if (strlen($mountPoint) > $bestLength) {
                $bestLength = strlen($mountPoint);
                $bestMount = [$mountPoint, $before[5], strtolower($after[0])];
                $bestLine = $line;
                $ambiguous = false;
            } elseif (strlen($mountPoint) === $bestLength) {
                $ambiguous = true;
            }
        }

        if ($ambiguous || $bestMount === null || $bestMount[0] !== $this->identity->evidenceDirectory
            || ! in_array('ro', explode(',', $bestMount[1]), true)
            || ($bestMount[2] !== 'tmpfs' && ! LinuxMountInfo::isQualifiedLocalFilesystem($bestMount[2]))) {
            throw new \RuntimeException('Production EFS heartbeat directory is not an exact read-only local bind.');
        }
        if (! is_string($bestLine)) {
            throw new \RuntimeException('Production EFS heartbeat mount information is incomplete.');
        }

        return $bestLine;
    }

    private function assertLocalMount(string $path, string $mountInfo): void
    {
        $type = LinuxMountInfo::filesystemTypeForPath($path, $mountInfo);
        if ($type !== 'tmpfs' && ($type === null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type))) {
            throw new \RuntimeException('Production EFS heartbeat path is not on a qualified local mount.');
        }
    }

    private static function decodeMountPath(string $path): ?string
    {
        if (preg_match('/\\\\(?!040|011|012|134)/', $path) === 1) {
            return null;
        }

        return str_replace(['\\040', '\\011', '\\012', '\\134'], [' ', "\t", "\n", '\\'], $path);
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

    private static function positiveDecimalInt(string $value): int
    {
        if (preg_match('/^[1-9][0-9]{0,18}$/D', $value) !== 1) {
            throw new \InvalidArgumentException('Production EFS worker local identity is invalid.');
        }
        $integer = (int) $value;
        if ((string) $integer !== $value) {
            throw new \InvalidArgumentException('Production EFS worker local identity is invalid.');
        }

        return $integer;
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

    private function readBootId(): string
    {
        $bootId = ($this->bootIdReader)();
        if (! is_string($bootId)) {
            throw new \RuntimeException('Production EFS heartbeat boot identity is unavailable.');
        }
        $bootId = trim($bootId);
        if (preg_match('/^[a-f0-9]{8}(-[a-f0-9]{4}){3}-[a-f0-9]{12}$/D', $bootId) !== 1) {
            throw new \RuntimeException('Production EFS heartbeat boot identity is invalid.');
        }

        return $bootId;
    }

    private static function nativeBootId(): string|false
    {
        $file = @fopen('/proc/sys/kernel/random/boot_id', 'rb');
        if ($file === false) {
            return false;
        }
        try {
            return stream_get_contents($file, 128);
        } finally {
            fclose($file);
        }
    }
}
