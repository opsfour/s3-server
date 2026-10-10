<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Bootstrap an exact watcher epoch using only the root-owned local evidence
 * bind and /proc. The storage root is first accessed by assertStorageIdentity,
 * after EfsStorageStartupPreflight has verified the NFSv4 mount.
 *
 * @internal No public EFS serve profile constructs this reader yet.
 */
final class EfsLocalStartupEvidenceReader implements EfsLocalStartupEvidence
{
    private const int MAX_RECORD_BYTES = 4096;

    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    private readonly EfsHostEvidenceReader $pinnedReader;

    /** @var Closure(string): (array<array-key, int>|false) */
    private readonly Closure $storageStatReader;

    private bool $revoked = false;

    /**
     * @param Closure(): int|null $monotonicClock
     * @param Closure(): string|null $bootIdReader
     * @param (Closure(string): (array<array-key, int>|false))|null $lstatReader
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstatReader
     * @param (Closure(): (string|false))|null $mountInfoReader
     * @param (Closure(string): (array<array-key, int>|false))|null $storageStatReader
     */
    public function __construct(
        string $evidenceDirectory,
        private readonly string $storageRoot,
        string $runId,
        string $efsId,
        string $node,
        string $driver,
        string $sourceSha256,
        ?Closure $monotonicClock = null,
        ?Closure $bootIdReader = null,
        ?Closure $lstatReader = null,
        ?Closure $fstatReader = null,
        ?Closure $mountInfoReader = null,
        ?Closure $storageStatReader = null,
    ) {
        if (preg_match('/^[a-f0-9]{32}$/D', $runId) !== 1
            || preg_match('/^fs-[a-f0-9]{8,17}$/D', $efsId) !== 1
            || ! in_array($node, ['api-one', 'api-two'], true)
            || ! in_array($driver, ['postgres', 'mysql'], true)
            || preg_match('/^[a-f0-9]{64}$/D', $sourceSha256) !== 1
            || ! str_starts_with($storageRoot, '/') || str_ends_with($storageRoot, '/')) {
            throw new \InvalidArgumentException('Invalid exact EFS startup evidence scope.');
        }
        if (! str_starts_with($evidenceDirectory, '/') || str_ends_with($evidenceDirectory, '/')
            || $evidenceDirectory === '/') {
            throw new \RuntimeException('EFS evidence directory must be an existing canonical absolute path.');
        }

        $lstatReader ??= static fn(string $path): array|false => @lstat($path);
        $fstatReader ??= static fn($handle): array|false => @fstat($handle);
        $mountInfoReader ??= self::nativeMountInfo(...);
        $this->storageStatReader = $storageStatReader ?? static fn(string $path): array|false => @stat($path);
        $evidencePath = $evidenceDirectory . '/' . $runId . '-' . $node . '-' . $driver . '.json';

        self::assertReadOnlyLocalBind($evidenceDirectory, $evidencePath, $mountInfoReader);
        if (realpath($evidenceDirectory) !== $evidenceDirectory) {
            throw new \RuntimeException('EFS evidence directory must be symlink-free.');
        }
        $directory = self::safeLocalEntry($evidenceDirectory, true, $lstatReader);
        $before = self::safeLocalEntry($evidencePath, false, $lstatReader);
        $size = $before['size'] ?? null;
        if (! is_int($size) || $size < 1 || $size > self::MAX_RECORD_BYTES) {
            throw new \RuntimeException('EFS evidence size is invalid.');
        }
        $file = @fopen($evidencePath, 'rb');
        if ($file === false) {
            throw new \RuntimeException('EFS evidence file is unavailable.');
        }
        try {
            $opened = $fstatReader($file);
            if (! is_array($opened) || ! self::safeEntry($opened, false)
                || ($opened['dev'] ?? null) !== ($before['dev'] ?? null)
                || ($opened['ino'] ?? null) !== ($before['ino'] ?? null)
                || ($opened['size'] ?? null) !== $size) {
                throw new \RuntimeException('EFS evidence file changed while opening.');
            }
            $raw = stream_get_contents($file, self::MAX_RECORD_BYTES + 1);
            if (! is_string($raw) || strlen($raw) !== $size || ! feof($file)) {
                throw new \RuntimeException('EFS evidence file changed while reading.');
            }
        } finally {
            fclose($file);
        }
        $after = self::safeLocalEntry($evidencePath, false, $lstatReader);
        if (($after['dev'] ?? null) !== ($before['dev'] ?? null)
            || ($after['ino'] ?? null) !== ($before['ino'] ?? null)
            || ($after['size'] ?? null) !== $size) {
            throw new \RuntimeException('EFS evidence file changed while reading.');
        }

        // The candidate values never authorize anything on their own. The
        // existing reader reopens and fully validates the exact record below.
        try {
            $record = json_decode($raw, true, 4, JSON_THROW_ON_ERROR | JSON_BIGINT_AS_STRING);
        } catch (\JsonException) {
            throw new \RuntimeException('EFS evidence JSON is malformed.');
        }
        if (! is_array($record) || array_is_list($record)) {
            throw new \RuntimeException('EFS evidence JSON is malformed.');
        }
        $identity = self::candidateIdentity($record);
        $scope = new EfsWorkerEvidenceScope(
            $evidenceDirectory,
            $storageRoot,
            $runId,
            $efsId,
            $node,
            $driver,
            $sourceSha256,
            self::positiveDecimal($record['storageDev'] ?? null),
            self::positiveDecimal($record['storageIno'] ?? null),
            self::positiveDecimal($directory['dev'] ?? null),
            self::positiveDecimal($directory['ino'] ?? null),
            $identity,
        );
        $this->pinnedReader = EfsHostEvidenceReader::fromWorkerScope(
            $scope,
            $monotonicClock,
            $bootIdReader,
            $lstatReader,
            $fstatReader,
            $mountInfoReader,
        );
        $this->pinnedReader->assertFresh();
    }

    public function assertFresh(): void
    {
        if ($this->revoked) {
            throw new \RuntimeException('EFS startup evidence was revoked.');
        }
        try {
            $this->pinnedReader->assertFresh();
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('EFS startup evidence is unavailable or invalid.');
        }
    }

    public function assertStorageIdentity(string $canonicalRoot): void
    {
        $this->assertFresh();
        try {
            $scope = $this->pinnedReader->workerScope();
            if ($canonicalRoot !== $this->storageRoot) {
                throw new \RuntimeException('EFS storage root differs from the pinned scope.');
            }
            clearstatcache(true, $canonicalRoot);
            $storage = ($this->storageStatReader)($canonicalRoot);
            $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : false;
            if (! is_array($storage) || ! is_int($storage['mode'] ?? null)
                || ($storage['mode'] & 0o170000) !== 0o040000
                || ($storage['mode'] & 0o7777) !== 0o700
                || ! is_int($effectiveUid) || ! is_int($storage['uid'] ?? null)
                || $storage['uid'] !== $effectiveUid
                || self::positiveDecimal($storage['dev'] ?? null) !== $scope->storageDev
                || self::positiveDecimal($storage['ino'] ?? null) !== $scope->storageIno) {
                throw new \RuntimeException('EFS storage root identity differs from the watcher.');
            }
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('EFS storage root identity is unavailable or invalid.');
        }
        $this->assertFresh();
    }

    /** The returned scope retains the first validated watcher epoch. */
    public function workerScope(): EfsWorkerEvidenceScope
    {
        $this->assertFresh();

        return $this->pinnedReader->workerScope();
    }

    /** @internal Bind components to this exact validated watcher reader. */
    public function hostEvidenceReader(): EfsHostEvidenceReader
    {
        $this->assertFresh();

        return $this->pinnedReader;
    }

    /** @param array<string, mixed> $record
     *  @return array<string, int|string>
     */
    private static function candidateIdentity(array $record): array
    {
        foreach (['containerId', 'hostMountId', 'bootId', 'epoch'] as $name) {
            if (! is_string($record[$name] ?? null)) {
                throw new \RuntimeException('EFS evidence identity is malformed.');
            }
        }
        foreach (['containerPid', 'relayPort', 'relayPid'] as $name) {
            if (! is_int($record[$name] ?? null)) {
                throw new \RuntimeException('EFS evidence identity is malformed.');
            }
        }

        return [
            'containerId' => $record['containerId'],
            'containerPid' => $record['containerPid'],
            'relayPort' => $record['relayPort'],
            'relayPid' => $record['relayPid'],
            'hostMountId' => $record['hostMountId'],
            'storageDev' => self::positiveDecimal($record['storageDev'] ?? null),
            'storageIno' => self::positiveDecimal($record['storageIno'] ?? null),
            'bootId' => $record['bootId'],
            'epoch' => $record['epoch'],
        ];
    }

    /** @param Closure(string): (array<array-key, int>|false) $lstatReader
     *  @return array<array-key, int>
     */
    private static function safeLocalEntry(string $path, bool $directory, Closure $lstatReader): array
    {
        $entry = $lstatReader($path);
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

    /** @param Closure(): (string|false) $mountInfoReader */
    private static function assertReadOnlyLocalBind(string $directory, string $evidencePath, Closure $mountInfoReader): void
    {
        $mountInfo = $mountInfoReader();
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
                throw new \RuntimeException('EFS evidence mount information is malformed.');
            }
            $point = self::decodeMountPath($before[4]);
            if ($point === null || ($point !== $evidencePath
                && ! str_starts_with($evidencePath, rtrim($point, '/') . '/'))) {
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
        if ($bestMount === null || $bestMount[0] !== $directory
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
