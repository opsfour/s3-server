<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;

/** Read-only keyset inventory for an offline PostgreSQL or MySQL storage audit. */
final class ExternalStorageIntegrityReferenceEnumerator implements StorageIntegrityReferenceEnumerator
{
    private const int MAX_PATH_BYTES = 4_096;

    private function __construct(
        private readonly PostgresConnectionPool|MysqlConnectionPool $pool,
        private readonly bool $postgres,
    ) {}

    public static function forPostgresDsn(string $dsn): self
    {
        if ($dsn === '') {
            throw new \InvalidArgumentException('PostgreSQL metadata DSN is required.');
        }

        return new self(new PostgresConnectionPool(PostgresConfig::fromString($dsn)), true);
    }

    public static function forMysqlDsn(string $dsn): self
    {
        if ($dsn === '') {
            throw new \InvalidArgumentException('MySQL metadata DSN is required.');
        }

        return new self(new MysqlConnectionPool(MysqlConfig::fromString($dsn)), false);
    }

    public function page(string $source, ?string $afterId, int $limit): array
    {
        self::validatePage($source, $afterId, $limit);
        $placeholder = $this->postgres ? '$1' : '?';
        $limitPlaceholder = $this->postgres ? ($afterId === null ? '$1' : '$2') : '?';
        $cursor = $afterId === null ? '1=1' : match ($source) {
            self::MULTIPART_PART => "p.id > {$placeholder}",
            self::RESTORE_SOURCE, self::RESTORE_TARGET => "j.id > {$placeholder}",
            default => "o.id > {$placeholder}",
        };
        $true = $this->postgres ? 'TRUE' : '1';
        $pathLength = $this->postgres ? 'octet_length(path)' : 'OCTET_LENGTH(path)';
        $partFormat = $this->postgres
            ? "u.user_metadata->>'__mpu-part-format'"
            : "JSON_UNQUOTE(JSON_EXTRACT(u.user_metadata, '$.\"__mpu-part-format\"'))";
        $partUploadMatch = $this->postgres
            ? 'u.upload_id = p.upload_id'
            : 'u.upload_id = p.upload_id AND BINARY u.upload_id = BINARY p.upload_id';
        $partExpectedSize = "CASE WHEN {$partFormat} = 'encrypted-v2' THEN NULL ELSE p.size END";
        $restoreObjectMatch = $this->restoreObjectMatchSql();
        $sourcePlacementMatch = $this->postgres
            ? 'o.storage_tier = j.source_tier AND o.storage_path = j.source_storage_path'
            : 'o.storage_tier = j.source_tier AND o.storage_path = j.source_storage_path '
                . 'AND BINARY o.storage_tier = BINARY j.source_tier AND BINARY o.storage_path = BINARY j.source_storage_path';
        $restoredPathMatch = $this->postgres
            ? 'o.restored_storage_path = j.restored_storage_path'
            : 'o.restored_storage_path = j.restored_storage_path '
                . 'AND BINARY o.restored_storage_path = BINARY j.restored_storage_path';
        $inner = match ($source) {
            self::OBJECT => "SELECT o.id, o.storage_tier, o.storage_path AS path, o.size AS expected_size FROM s3_objects o WHERE {$cursor} AND NOT o.is_delete_marker ORDER BY o.id LIMIT {$limitPlaceholder}",
            self::RESTORED_OBJECT => "SELECT o.id, o.restored_storage_tier AS storage_tier, o.restored_storage_path AS path, o.size AS expected_size FROM s3_objects o WHERE {$cursor} AND o.restored_storage_path IS NOT NULL ORDER BY o.id LIMIT {$limitPlaceholder}",
            self::MULTIPART_PART => "SELECT p.id, u.storage_tier, p.storage_path AS path, {$partExpectedSize} AS expected_size FROM s3_parts p LEFT JOIN s3_multipart_uploads u ON {$partUploadMatch} WHERE {$cursor} ORDER BY p.id LIMIT {$limitPlaceholder}",
            self::RESTORE_SOURCE => "SELECT j.id, o.storage_tier, j.source_storage_path AS path, o.size AS expected_size FROM s3_restore_jobs j JOIN s3_objects o ON {$restoreObjectMatch} AND {$sourcePlacementMatch} WHERE {$cursor} AND j.status IN ('pending', 'processing') ORDER BY j.id LIMIT {$limitPlaceholder}",
            self::RESTORE_TARGET => "SELECT j.id, o.restored_storage_tier AS storage_tier, j.restored_storage_path AS path, o.size AS expected_size FROM s3_restore_jobs j JOIN s3_objects o ON {$restoreObjectMatch} AND {$restoredPathMatch} WHERE {$cursor} AND j.status IN ('pending', 'processing') AND j.restored_storage_path IS NOT NULL ORDER BY j.id LIMIT {$limitPlaceholder}",
            default => throw new \InvalidArgumentException('Unknown storage audit source.'),
        };
        $sql = "SELECT id, storage_tier, CASE WHEN {$pathLength} <= " . self::MAX_PATH_BYTES
            . " THEN path ELSE NULL END AS path, expected_size, CASE WHEN {$pathLength} > "
            . self::MAX_PATH_BYTES . " THEN {$true} ELSE FALSE END AS oversized FROM ({$inner}) audit_page ORDER BY id";

        try {
            $result = $this->pool->execute($sql, $afterId === null ? [$limit] : [$afterId, $limit]);
            $rows = [];
            while (($row = $result->fetchRow()) !== null) {
                if (! in_array($row['oversized'] ?? null, [false, 0, '0'], true)) {
                    throw new \UnexpectedValueException('Storage audit inventory path exceeds its byte limit.');
                }
                $rows[] = self::normalizeRow($row, $source);
            }

            return $rows;
        } catch (\Throwable $error) {
            if ($error instanceof \UnexpectedValueException) {
                throw $error;
            }
            throw new \RuntimeException('Storage audit inventory query failed.');
        }
    }

    public function close(): void
    {
        $this->pool->close();
    }

    private static function validatePage(string $source, ?string $afterId, int $limit): void
    {
        if (! in_array($source, self::SOURCES, true) || $limit < 1 || $limit > 10_000
            || ($afterId !== null && (preg_match('/^(?:0|[1-9][0-9]*)$/D', $afterId) !== 1
                || filter_var($afterId, FILTER_VALIDATE_INT) === false))) {
            throw new \InvalidArgumentException('Unknown storage audit source, cursor, or page size.');
        }
    }

    /** @param array<string, mixed> $row
     *  @return array{source: string, id: string, storage_tier: ?string, path: string, expected_size: ?int}
     */
    private static function normalizeRow(array $row, string $source): array
    {
        $id = $row['id'] ?? null;
        $path = $row['path'] ?? null;
        $tier = $row['storage_tier'] ?? null;
        if ((! is_int($id) && ! is_string($id) && ! $id instanceof \Stringable)
            || (! is_string($path) && ! $path instanceof \Stringable)
            || ($tier !== null && ! is_string($tier) && ! $tier instanceof \Stringable)) {
            throw new \UnexpectedValueException('Storage audit inventory row is invalid.');
        }
        $path = (string) $path;
        $tier = $tier === null ? null : (string) $tier;
        if (str_contains($path, "\0") || ($tier !== null && (strlen($tier) > 64 || str_contains($tier, "\0")))) {
            throw new \UnexpectedValueException('Storage audit inventory row is invalid.');
        }

        return [
            'source' => $source,
            'id' => (string) $id,
            'storage_tier' => $tier,
            'path' => $path,
            'expected_size' => self::normalizeSize($row['expected_size'] ?? null),
        ];
    }

    private static function normalizeSize(mixed $size): ?int
    {
        if ($size === null) {
            return null;
        }
        if (is_int($size) && $size >= 0) {
            return $size;
        }
        if ((is_string($size) || $size instanceof \Stringable)
            && preg_match('/^(?:0|[1-9][0-9]*)$/D', (string) $size) === 1
            && filter_var((string) $size, FILTER_VALIDATE_INT) !== false) {
            return (int) (string) $size;
        }
        throw new \UnexpectedValueException('Storage audit inventory size is invalid.');
    }

    private function restoreObjectMatchSql(): string
    {
        $latest = $this->postgres ? 'TRUE' : '1';
        if ($this->postgres) {
            return "o.bucket = j.bucket AND o.key_name = j.key_name AND ((j.version_id IS NULL AND o.is_latest = {$latest}) OR o.version_id = j.version_id)";
        }

        return "o.bucket = j.bucket AND BINARY o.bucket = BINARY j.bucket "
            . 'AND o.key_name = j.key_name AND BINARY o.key_name = BINARY j.key_name '
            . "AND ((j.version_id IS NULL AND o.is_latest = {$latest}) OR "
            . '(o.version_id = j.version_id AND BINARY o.version_id = BINARY j.version_id))';
    }
}
