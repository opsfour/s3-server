<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Read-only keyset inventory for an offline SQLite storage audit. */
final class SqliteStorageIntegrityReferenceEnumerator implements StorageIntegrityReferenceEnumerator
{
    private const int MAX_PATH_BYTES = 4_096;

    private readonly \PDO $pdo;

    public function __construct(string $databasePath)
    {
        $canonicalPath = realpath($databasePath);
        if (! str_starts_with($databasePath, '/') || is_link($databasePath)
            || $canonicalPath === false || ! is_file($canonicalPath)) {
            throw new \InvalidArgumentException('Storage audit requires an existing SQLite database file.');
        }

        $uriPath = implode('/', array_map(rawurlencode(...), explode('/', $canonicalPath)));
        try {
            $this->pdo = new \PDO('sqlite:file:' . $uriPath . '?mode=ro', options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA query_only=ON');
        } catch (\PDOException) {
            throw new \RuntimeException('Storage audit could not open the SQLite database read-only.');
        }
    }

    public function page(string $source, ?string $afterId, int $limit): array
    {
        self::validatePage($source, $afterId, $limit);
        $cursor = $afterId === null ? '1=1' : match ($source) {
            self::MULTIPART_PART => 'p.id > :after',
            self::RESTORE_SOURCE, self::RESTORE_TARGET => 'j.id > :after',
            default => 'o.id > :after',
        };
        $objectSize = self::restoreObjectSizeSql();
        $sql = match ($source) {
            self::OBJECT => "SELECT o.id, o.storage_path AS path, o.size AS expected_size FROM s3_objects o WHERE {$cursor} AND NOT o.is_delete_marker ORDER BY o.id LIMIT :limit",
            self::RESTORED_OBJECT => "SELECT o.id, o.restored_storage_path AS path, o.size AS expected_size FROM s3_objects o WHERE {$cursor} AND o.restored_storage_path IS NOT NULL ORDER BY o.id LIMIT :limit",
            self::MULTIPART_PART => "SELECT p.id, p.storage_path AS path, CASE WHEN json_extract(u.user_metadata, '$.\"__mpu-part-format\"') = 'encrypted-v2' THEN NULL ELSE p.size END AS expected_size FROM s3_parts p LEFT JOIN s3_multipart_uploads u ON u.upload_id = p.upload_id COLLATE BINARY WHERE {$cursor} ORDER BY p.id LIMIT :limit",
            self::RESTORE_SOURCE => "SELECT j.id, j.source_storage_path AS path, {$objectSize} AS expected_size FROM s3_restore_jobs j WHERE {$cursor} AND j.status IN ('pending', 'processing') ORDER BY j.id LIMIT :limit",
            self::RESTORE_TARGET => "SELECT j.id, j.restored_storage_path AS path, {$objectSize} AS expected_size FROM s3_restore_jobs j WHERE {$cursor} AND j.status IN ('pending', 'processing') AND j.restored_storage_path IS NOT NULL ORDER BY j.id LIMIT :limit",
            default => throw new \InvalidArgumentException('Unknown storage audit source.'),
        };

        try {
            $statement = $this->pdo->prepare($sql);
            if ($afterId !== null) {
                $statement->bindValue(':after', $afterId, \PDO::PARAM_INT);
            }
            $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $statement->execute();

            return self::normalizeRows($statement->fetchAll(), $source);
        } catch (\Throwable $error) {
            if ($error instanceof \UnexpectedValueException) {
                throw $error;
            }
            throw new \RuntimeException('Storage audit inventory query failed.');
        }
    }

    public function close(): void {}

    private static function validatePage(string $source, ?string $afterId, int $limit): void
    {
        if (! in_array($source, self::SOURCES, true) || $limit < 1 || $limit > 500
            || ($afterId !== null && (preg_match('/^(?:0|[1-9][0-9]*)$/D', $afterId) !== 1
                || filter_var($afterId, FILTER_VALIDATE_INT) === false))) {
            throw new \InvalidArgumentException('Unknown storage audit source, cursor, or page size.');
        }
    }

    /** @param array<array-key, mixed> $rows
     *  @return list<array{source: string, id: string, path: string, expected_size: ?int}>
     */
    private static function normalizeRows(array $rows, string $source): array
    {
        $normalized = [];
        foreach ($rows as $row) {
            if (! is_array($row)) {
                throw new \UnexpectedValueException('Storage audit inventory row is invalid.');
            }
            $id = $row['id'] ?? null;
            $path = $row['path'] ?? null;
            $size = $row['expected_size'] ?? null;
            if ((! is_int($id) && ! is_string($id)) || ! is_string($path)
                || strlen($path) > self::MAX_PATH_BYTES || str_contains($path, "\0")) {
                throw new \UnexpectedValueException('Storage audit inventory row is invalid.');
            }
            $normalized[] = [
                'source' => $source,
                'id' => (string) $id,
                'path' => $path,
                'expected_size' => self::normalizeSize($size),
            ];
        }

        return $normalized;
    }

    private static function normalizeSize(mixed $size): ?int
    {
        if ($size === null) {
            return null;
        }
        if (is_int($size) && $size >= 0) {
            return $size;
        }
        if (is_string($size) && preg_match('/^(?:0|[1-9][0-9]*)$/D', $size) === 1
            && filter_var($size, FILTER_VALIDATE_INT) !== false) {
            return (int) $size;
        }
        throw new \UnexpectedValueException('Storage audit inventory size is invalid.');
    }

    private static function restoreObjectSizeSql(): string
    {
        return <<<'SQL'
            (SELECT o.size FROM s3_objects o
             WHERE o.bucket = j.bucket AND o.key_name = j.key_name
               AND ((j.version_id IS NULL AND o.is_latest = 1) OR o.version_id = j.version_id)
             ORDER BY o.is_latest DESC, o.id DESC LIMIT 1)
            SQL;
    }
}
