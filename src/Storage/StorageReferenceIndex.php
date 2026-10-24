<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Exception\StorageInventoryScratchException;

/** Disk-backed exact membership index for the offline orphan inventory. */
final class StorageReferenceIndex
{
    public const int DEFAULT_MAX_BYTES = 1_073_741_824;

    public const int MIN_MAX_BYTES = 1_048_576;

    public const int MAX_MAX_BYTES = 1_099_511_627_776;

    private const int FREE_SPACE_RESERVE_BYTES = 67_108_864;

    private const int WRITE_HEADROOM_BYTES = 8_388_608;

    private const string LOCK_FILE = '.opsfour-s3-inventory.lock';

    private ?\PDO $pdo = null;

    private ?string $path = null;

    private ?string $directory = null;

    private ?\PDOStatement $insert = null;

    private ?\PDOStatement $contains = null;

    private ?\PDOStatement $insertCandidate = null;

    /** @var resource|null */
    private $scratchLock = null;

    /**
     * @param array<string, string> $tierRoots
     */
    public function __construct(string $scratchPath, int $maxBytes, array $tierRoots)
    {
        if ($maxBytes < self::MIN_MAX_BYTES || $maxBytes > self::MAX_MAX_BYTES) {
            throw new \InvalidArgumentException('Storage inventory scratch limit is outside the supported range.');
        }
        if (realpath($scratchPath) !== $scratchPath || ! is_dir($scratchPath)) {
            throw new \InvalidArgumentException('Storage inventory scratch path must be an existing canonical directory.');
        }
        foreach ($tierRoots as $root) {
            if ($scratchPath === $root || str_starts_with($scratchPath, $root . '/')) {
                throw new StorageInventoryScratchException(
                    'Storage inventory scratch path must be disjoint from every storage tier root.',
                );
            }
        }

        $this->scratchLock = self::acquireScratchLock($scratchPath);
        $createdDirectory = null;
        $directory = null;
        $path = null;
        try {
            $free = disk_free_space($scratchPath);
            if (! is_float($free) || $free < self::FREE_SPACE_RESERVE_BYTES
                || $maxBytes > $free - self::FREE_SPACE_RESERVE_BYTES) {
                throw new StorageInventoryScratchException(
                    'Storage inventory scratch space cannot reserve --scratch-max-bytes plus 64 MiB of free space.',
                );
            }

            $createdDirectory = rtrim($scratchPath, DIRECTORY_SEPARATOR)
                . DIRECTORY_SEPARATOR . 'opsfour-s3-inventory-' . bin2hex(random_bytes(16));
            if (! @mkdir($createdDirectory, 0o700)) {
                throw new StorageInventoryScratchException('Storage inventory scratch directory could not be created.');
            }
            $directory = realpath($createdDirectory);
            if (! is_string($directory)) {
                throw new StorageInventoryScratchException('Storage inventory scratch directory could not be resolved.');
            }
            foreach ($tierRoots as $root) {
                if ($directory === $root || str_starts_with($directory, $root . '/')
                    || str_starts_with($root, $directory . '/')) {
                    throw new StorageInventoryScratchException(
                        'Storage inventory scratch directory must be disjoint from every storage tier root.',
                    );
                }
            }
            $path = $directory . DIRECTORY_SEPARATOR . 'references.sqlite';
            $pdo = new \PDO('sqlite:' . $path, options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            ]);
            // This file is disposable scratch state. Keep SQLite's memory use
            // bounded and avoid an additional persistent journal.
            $pdo->exec('PRAGMA journal_mode=OFF');
            $pdo->exec('PRAGMA synchronous=OFF');
            $pdo->exec('PRAGMA temp_store=FILE');
            $pdo->exec('PRAGMA cache_size=-8192');
            $pdo->exec('PRAGMA mmap_size=0');
            $pageSizeResult = $pdo->query('PRAGMA page_size');
            $pageSize = $pageSizeResult === false ? 0 : (int) $pageSizeResult->fetchColumn();
            $maxPages = $pageSize > 0 ? intdiv($maxBytes, $pageSize) : 0;
            if ($maxPages < 1) {
                throw new StorageInventoryScratchException('--scratch-max-bytes cannot hold a SQLite page.');
            }
            $maxPagesResult = $pdo->query('PRAGMA max_page_count=' . $maxPages);
            $configuredMaxPages = $maxPagesResult === false ? 0 : (int) $maxPagesResult->fetchColumn();
            if ($configuredMaxPages < $maxPages) {
                throw new StorageInventoryScratchException('--scratch-max-bytes exceeds SQLite capacity.');
            }
            if (! @chmod($path, 0o600)) {
                throw new \RuntimeException('Storage inventory reference index permissions could not be secured.');
            }
            $pdo->exec(<<<'SQL'
                CREATE TABLE storage_references (
                    storage_tier TEXT NOT NULL,
                    reference_kind INTEGER NOT NULL,
                    digest TEXT NOT NULL,
                    PRIMARY KEY (storage_tier, reference_kind, digest)
                ) WITHOUT ROWID
                SQL);
            $pdo->exec(<<<'SQL'
                CREATE TABLE storage_candidates (
                    storage_tier TEXT COLLATE BINARY NOT NULL,
                    path_sha256 TEXT COLLATE BINARY NOT NULL,
                    size_bytes INTEGER,
                    format TEXT,
                    path TEXT,
                    PRIMARY KEY (storage_tier, path_sha256)
                ) WITHOUT ROWID
                SQL);
            $this->insert = $pdo->prepare(<<<'SQL'
                INSERT OR IGNORE INTO storage_references (storage_tier, reference_kind, digest)
                VALUES (?, ?, ?)
                SQL);
            $this->contains = $pdo->prepare(<<<'SQL'
                SELECT 1 FROM storage_references
                WHERE storage_tier = ? AND reference_kind = ? AND digest = ?
                LIMIT 1
                SQL);
            $this->insertCandidate = $pdo->prepare(<<<'SQL'
                INSERT OR IGNORE INTO storage_candidates
                    (storage_tier, path_sha256, size_bytes, format, path)
                VALUES (?, ?, ?, ?, ?)
                SQL);
            $this->path = $path;
            $this->directory = $directory;
            $this->pdo = $pdo;
        } catch (\Throwable $error) {
            if ($path !== null) {
                @unlink($path);
            }
            if ($createdDirectory !== null) {
                @rmdir($createdDirectory);
            }
            $this->releaseScratchLock();
            if ($error instanceof StorageInventoryScratchException) {
                throw $error;
            }
            throw new StorageInventoryScratchException(
                'Storage inventory scratch index could not be initialized within --scratch-max-bytes.',
            );
        }
    }

    /**
     * @param list<array{tier: string, path_sha256: ?string, relative_path_sha256: ?string}> $references
     */
    public function addPage(array $references): void
    {
        $pdo = $this->pdo ?? throw new \LogicException('Storage inventory reference index is closed.');
        $this->assertWriteCapacity();
        try {
            $pdo->beginTransaction();
            foreach ($references as $reference) {
                if ($reference['path_sha256'] !== null) {
                    $this->insert($reference['tier'], 0, $reference['path_sha256']);
                }
                if ($reference['relative_path_sha256'] !== null) {
                    $this->insert($reference['tier'], 1, $reference['relative_path_sha256']);
                }
            }
            $pdo->commit();
            $this->assertFreeReserve();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof StorageInventoryScratchException) {
                throw $error;
            }
            throw new StorageInventoryScratchException(
                'Storage inventory scratch index reached --scratch-max-bytes or an I/O limit.',
            );
        }
    }

    /**
     * @param list<array{storage_tier: string, path_sha256: string, size_bytes: ?int, format: ?string, path: ?string}> $candidates
     */
    public function addCandidatePage(array $candidates): void
    {
        if ($candidates === []) {
            return;
        }
        $pdo = $this->pdo ?? throw new \LogicException('Storage inventory reference index is closed.');
        $this->assertWriteCapacity();
        try {
            $pdo->beginTransaction();
            foreach ($candidates as $candidate) {
                self::assertDigest($candidate['path_sha256']);
                ($this->insertCandidate ?? throw new \LogicException('Storage inventory reference index is closed.'))
                    ->execute([
                        $candidate['storage_tier'],
                        $candidate['path_sha256'],
                        $candidate['size_bytes'],
                        $candidate['format'],
                        $candidate['path'],
                    ]);
            }
            $pdo->commit();
            $this->assertFreeReserve();
        } catch (\Throwable $error) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            if ($error instanceof StorageInventoryScratchException) {
                throw $error;
            }
            throw new StorageInventoryScratchException(
                'Storage inventory candidate index reached --scratch-max-bytes or an I/O limit.',
            );
        }
    }

    /**
     * @param array{storage_tier: string, path_sha256: string}|null $after
     * @return array{list<array{storage_tier: string, path_sha256: string, size_bytes: ?int, format: ?string, path?: string}>, array{storage_tier: string, path_sha256: string}|null}
     */
    public function candidatePage(?array $after, int $limit): array
    {
        if ($limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Storage inventory candidate page limit must be 1..500.');
        }
        $pdo = $this->pdo ?? throw new \LogicException('Storage inventory reference index is closed.');
        $sql = <<<'SQL'
            SELECT storage_tier, path_sha256, size_bytes, format, path
            FROM storage_candidates
            SQL;
        $parameters = [];
        if ($after !== null) {
            self::assertDigest($after['path_sha256']);
            $sql .= <<<'SQL'
                 WHERE storage_tier > ? COLLATE BINARY
                   OR (storage_tier = ? COLLATE BINARY AND path_sha256 > ? COLLATE BINARY)
                SQL;
            $parameters = [$after['storage_tier'], $after['storage_tier'], $after['path_sha256']];
        }
        $sql .= ' ORDER BY storage_tier COLLATE BINARY, path_sha256 COLLATE BINARY LIMIT ' . ($limit + 1);
        try {
            $statement = $pdo->prepare($sql);
            $statement->execute($parameters);
            $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);
        } catch (\Throwable) {
            throw new \RuntimeException('Storage inventory candidate index read failed.');
        }
        if (! is_array($rows)) {
            throw new \RuntimeException('Storage inventory candidate index read failed.');
        }
        $hasMore = count($rows) > $limit;
        if ($hasMore) {
            array_pop($rows);
        }
        $candidates = [];
        foreach ($rows as $row) {
            if (! is_array($row) || ! is_string($row['storage_tier'] ?? null)
                || ! is_string($row['path_sha256'] ?? null)) {
                throw new \RuntimeException('Storage inventory candidate index row is invalid.');
            }
            self::assertDigest($row['path_sha256']);
            $candidate = [
                'storage_tier' => $row['storage_tier'],
                'path_sha256' => $row['path_sha256'],
                'size_bytes' => is_int($row['size_bytes']) ? $row['size_bytes'] : (
                    is_string($row['size_bytes']) && preg_match('/\A(?:0|[1-9][0-9]*)\z/D', $row['size_bytes']) === 1
                        ? (int) $row['size_bytes']
                        : null
                ),
                'format' => is_string($row['format']) ? $row['format'] : null,
            ];
            if (is_string($row['path'])) {
                $candidate['path'] = $row['path'];
            }
            $candidates[] = $candidate;
        }
        $last = $candidates === [] ? null : $candidates[count($candidates) - 1];
        $next = $hasMore && is_array($last) ? [
            'storage_tier' => $last['storage_tier'],
            'path_sha256' => $last['path_sha256'],
        ] : null;

        return [$candidates, $next];
    }

    public function containsPath(string $tier, string $digest): bool
    {
        return $this->contains($tier, 0, $digest);
    }

    public function containsScope(string $tier, string $digest): bool
    {
        return $this->contains($tier, 1, $digest);
    }

    public function close(): void
    {
        [$path, $directory] = $this->releaseIndexResources();
        if ($path !== null) {
            @unlink($path);
        }
        if ($directory !== null) {
            @rmdir($directory);
        }
        $this->releaseScratchLock();
    }

    public function closeOrFail(): void
    {
        [$path, $directory] = $this->releaseIndexResources();
        $cleanupFailed = false;
        try {
            if ($path !== null && (file_exists($path) || is_link($path)) && ! @unlink($path)) {
                $cleanupFailed = true;
            }
            if ($directory !== null && (file_exists($directory) || is_link($directory)) && ! @rmdir($directory)) {
                $cleanupFailed = true;
            }
        } finally {
            $this->releaseScratchLock();
        }
        if ($cleanupFailed) {
            throw new StorageInventoryScratchException(
                'Storage inventory scratch index cleanup failed; remove the private index directory before retrying.',
            );
        }
    }

    /** @return array{?string, ?string} */
    private function releaseIndexResources(): array
    {
        $path = $this->path;
        $directory = $this->directory;
        $this->insert = null;
        $this->contains = null;
        $this->insertCandidate = null;
        $this->pdo = null;
        $this->path = null;
        $this->directory = null;

        return [$path, $directory];
    }

    public function __destruct()
    {
        $this->close();
    }

    private function insert(string $tier, int $kind, string $digest): void
    {
        self::assertDigest($digest);
        ($this->insert ?? throw new \LogicException('Storage inventory reference index is closed.'))
            ->execute([$tier, $kind, $digest]);
    }

    private function contains(string $tier, int $kind, string $digest): bool
    {
        self::assertDigest($digest);
        try {
            $statement = $this->contains ?? throw new \LogicException('Storage inventory reference index is closed.');
            $statement->execute([$tier, $kind, $digest]);
            return $statement->fetchColumn() !== false;
        } catch (\Throwable $error) {
            if ($error instanceof \LogicException || $error instanceof \UnexpectedValueException) {
                throw $error;
            }
            throw new \RuntimeException('Storage inventory reference index read failed.');
        }
    }

    private static function assertDigest(string $digest): void
    {
        if (preg_match('/\A[a-f0-9]{64}\z/D', $digest) !== 1) {
            throw new \UnexpectedValueException('Storage inventory reference digest is invalid.');
        }
    }

    private function assertWriteCapacity(): void
    {
        $free = $this->assertFreeReserve();
        if ($free < self::FREE_SPACE_RESERVE_BYTES + self::WRITE_HEADROOM_BYTES) {
            throw new StorageInventoryScratchException(
                'Storage inventory scratch write would consume the 64 MiB free-space safety reserve.',
            );
        }
    }

    private function assertFreeReserve(): float
    {
        $directory = $this->directory ?? throw new \LogicException('Storage inventory reference index is closed.');
        $free = disk_free_space($directory);
        if (! is_float($free) || $free < self::FREE_SPACE_RESERVE_BYTES) {
            throw new StorageInventoryScratchException(
                'Storage inventory scratch space has less than the required 64 MiB free-space safety reserve.',
            );
        }

        return $free;
    }

    /** @return resource */
    private static function acquireScratchLock(string $scratchPath)
    {
        $lockPath = $scratchPath . DIRECTORY_SEPARATOR . self::LOCK_FILE;
        if (is_link($lockPath)) {
            throw new StorageInventoryScratchException('Storage inventory scratch lock is unsafe.');
        }
        clearstatcache(true, $lockPath);
        $existing = @lstat($lockPath);
        if (is_array($existing)
            && (($existing['mode'] & 0o170000) !== 0o100000 || $existing['nlink'] !== 1)) {
            throw new StorageInventoryScratchException('Storage inventory scratch lock is unsafe.');
        }
        $handle = @fopen($lockPath, 'c+b');
        if ($handle === false) {
            throw new StorageInventoryScratchException('Storage inventory scratch lock could not be opened.');
        }
        try {
            $handleStat = @fstat($handle);
            if (! is_array($handleStat) || ($handleStat['mode'] & 0o170000) !== 0o100000
                || $handleStat['nlink'] !== 1 || ! @chmod($lockPath, 0o600)) {
                throw new StorageInventoryScratchException('Storage inventory scratch lock is unsafe.');
            }
            clearstatcache(true, $lockPath);
            $pathStat = @lstat($lockPath);
            if (! is_array($pathStat) || ($pathStat['mode'] & 0o170000) !== 0o100000
                || $pathStat['nlink'] !== 1 || $pathStat['dev'] !== $handleStat['dev']
                || $pathStat['ino'] !== $handleStat['ino']) {
                throw new StorageInventoryScratchException('Storage inventory scratch lock is unsafe.');
            }
            if (! flock($handle, LOCK_EX | LOCK_NB)) {
                throw new StorageInventoryScratchException(
                    'Another storage inventory is already using --scratch-path.',
                );
            }
        } catch (\Throwable $error) {
            fclose($handle);
            throw $error;
        }

        return $handle;
    }

    private function releaseScratchLock(): void
    {
        if (! is_resource($this->scratchLock)) {
            $this->scratchLock = null;
            return;
        }
        @flock($this->scratchLock, LOCK_UN);
        fclose($this->scratchLock);
        $this->scratchLock = null;
    }
}
