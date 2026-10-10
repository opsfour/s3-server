<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConnectionPool;

/**
 * Read-only PostgreSQL/MySQL path references over an existing Amp pool.
 * The caller owns the pool and must stop writers before cross-page inventory.
 */
final class ExternalCutoverPathReferenceEnumerator implements CutoverPathReferenceEnumerator
{
    private const int MAX_ID_BYTES = 255;

    private const int MAX_FIELD_BYTES = 4_096;

    private const int MAX_JSON_BYTES = 16_384;

    private function __construct(
        private readonly PostgresConnectionPool|MysqlConnectionPool $pool,
        private readonly bool $postgres,
    ) {}

    public static function forPostgres(PostgresConnectionPool $pool): self
    {
        return new self($pool, true);
    }

    public static function forMysql(MysqlConnectionPool $pool): self
    {
        return new self($pool, false);
    }

    /**
     * @return list<array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string}>
     */
    public function page(string $source, ?string $afterId = null, int $limit = 100): array
    {
        if (! in_array($source, self::SOURCES, true) || $limit < 1 || $limit > 500) {
            throw new \InvalidArgumentException('Unknown cutover inventory source or invalid page size.');
        }
        $integerId = in_array($source, [self::OBJECT, self::RESTORED_OBJECT, self::PART, self::GARBAGE,
            self::TIER_TRANSITION_SOURCE, self::TIER_TRANSITION_TARGET, self::RESTORE_SOURCE, self::RESTORE_TARGET], true);
        if ($integerId && $afterId !== null && (preg_match('/^-?(?:0|[1-9][0-9]*)$/D', $afterId) !== 1
            || filter_var($afterId, FILTER_VALIDATE_INT) === false)) {
            throw new \InvalidArgumentException('Invalid integer cutover inventory cursor.');
        }
        if (! $integerId && $afterId !== null && (strlen($afterId) > 255 || str_contains($afterId, "\0"))) {
            throw new \InvalidArgumentException('Invalid attempt-ID cutover inventory cursor.');
        }

        $sseJson = $this->jsonText('user_metadata', '__sse-algorithm');
        $partSseJson = $this->jsonText('u.user_metadata', '__sse-algorithm');
        $partFormatJson = $this->jsonText('u.user_metadata', '__mpu-part-format');
        $attemptIdExpr = $this->postgres ? 'attempt_id COLLATE "C"' : 'BINARY attempt_id';
        $uploadIdExpr = $this->postgres ? 'u.upload_id COLLATE "C"' : 'BINARY u.upload_id';
        $partUploadMatch = $this->postgres
            ? 'p.upload_id = u.upload_id'
            : 'p.upload_id = u.upload_id AND BINARY p.upload_id = BINARY u.upload_id';
        $afterPlaceholder = $this->postgres ? '$1' : '?';
        $limitPlaceholder = $this->postgres ? ($afterId === null ? '$1' : '$2') : '?';
        $cursorPredicate = $afterId === null ? '1=1' : match ($source) {
            self::PART => 'p.id > ' . $afterPlaceholder,
            self::MULTIPART_UPLOAD_NO_PARTS => $uploadIdExpr . ' > ' . $afterPlaceholder,
            self::OBJECT_INTENT, self::MULTIPART_ASSEMBLY_INTENT, self::MULTIPART_FINAL_INTENT => $attemptIdExpr . ' > ' . $afterPlaceholder,
            default => 'id > ' . $afterPlaceholder,
        };
        // Keep the keyset predicate and ORDER BY on their original indexed
        // expressions. Only projected values are capped before they cross the
        // SQL transport; an oversized value is never truncated into a digest.
        [$id, $tier, $path, $sse, $format, $operation, $state, $upload, $json, $tail] = match ($source) {
            self::OBJECT => ['id', 'storage_tier', 'storage_path', $sseJson, 'NULL', 'NULL',
                "CASE WHEN is_delete_marker THEN 'delete_marker_with_path' WHEN storage_path = '' THEN 'missing_object_path' ELSE NULL END",
                'NULL', 'user_metadata', "FROM s3_objects WHERE {$cursorPredicate} AND (NOT is_delete_marker OR storage_path <> '') ORDER BY id LIMIT {$limitPlaceholder}"],
            self::RESTORED_OBJECT => ['id', 'restored_storage_tier', 'restored_storage_path', $sseJson, 'NULL', 'NULL', 'restore_status',
                'NULL', 'user_metadata', "FROM s3_objects WHERE {$cursorPredicate} AND restored_storage_path IS NOT NULL ORDER BY id LIMIT {$limitPlaceholder}"],
            self::PART => ['p.id', 'u.storage_tier', 'p.storage_path', $partSseJson, $partFormatJson, 'NULL', 'NULL',
                'NULL', 'u.user_metadata', "FROM s3_parts AS p LEFT JOIN s3_multipart_uploads AS u ON {$partUploadMatch} WHERE {$cursorPredicate} ORDER BY p.id LIMIT {$limitPlaceholder}"],
            self::MULTIPART_UPLOAD_NO_PARTS => ['u.upload_id', 'u.storage_tier', 'NULL', $partSseJson, $partFormatJson, 'NULL', "'active_no_parts'",
                'NULL', 'u.user_metadata', "FROM s3_multipart_uploads AS u WHERE {$cursorPredicate} AND NOT EXISTS (SELECT 1 FROM s3_parts AS p WHERE {$partUploadMatch}) ORDER BY {$uploadIdExpr} LIMIT {$limitPlaceholder}"],
            self::GARBAGE => ['id', 'storage_tier', 'storage_path', 'NULL', 'NULL', 'operation', 'status', 'upload_id',
                'NULL', "FROM s3_storage_garbage WHERE {$cursorPredicate} ORDER BY id LIMIT {$limitPlaceholder}"],
            self::OBJECT_INTENT => ['attempt_id', 'storage_tier', 'storage_path', 'NULL', 'NULL', 'purpose', 'state', 'NULL',
                'NULL', "FROM s3_object_write_intents WHERE {$cursorPredicate} ORDER BY {$attemptIdExpr} LIMIT {$limitPlaceholder}"],
            self::MULTIPART_ASSEMBLY_INTENT => ['attempt_id', 'storage_tier', 'assembly_path', 'sse_mode', 'NULL', "'assembly'", 'state',
                'NULL', 'NULL', "FROM s3_multipart_write_intents WHERE {$cursorPredicate} ORDER BY {$attemptIdExpr} LIMIT {$limitPlaceholder}"],
            self::MULTIPART_FINAL_INTENT => ['attempt_id', 'storage_tier', 'final_path', 'sse_mode', 'NULL', "'final'", 'state',
                'NULL', 'NULL', "FROM s3_multipart_write_intents WHERE {$cursorPredicate} ORDER BY {$attemptIdExpr} LIMIT {$limitPlaceholder}"],
            self::TIER_TRANSITION_SOURCE => ['id', 'source_tier', 'source_storage_path', 'NULL', 'NULL', "'transition_source'", 'status',
                'NULL', 'NULL', "FROM s3_tier_transition_jobs WHERE {$cursorPredicate} ORDER BY id LIMIT {$limitPlaceholder}"],
            self::TIER_TRANSITION_TARGET => ['id', 'target_tier', 'target_storage_path', 'NULL', 'NULL', "'transition_target'", 'status',
                'NULL', 'NULL', "FROM s3_tier_transition_jobs WHERE {$cursorPredicate} AND target_storage_path IS NOT NULL ORDER BY id LIMIT {$limitPlaceholder}"],
            self::RESTORE_SOURCE => ['id', 'source_tier', 'source_storage_path', 'NULL', 'NULL', "'restore_source'", 'status',
                'NULL', 'NULL', "FROM s3_restore_jobs WHERE {$cursorPredicate} ORDER BY id LIMIT {$limitPlaceholder}"],
            self::RESTORE_TARGET => ['id', 'NULL', 'restored_storage_path', 'NULL', 'NULL', "'restore_target'", 'status',
                'NULL', 'NULL', "FROM s3_restore_jobs WHERE {$cursorPredicate} AND restored_storage_path IS NOT NULL ORDER BY id LIMIT {$limitPlaceholder}"],
        };
        $textFields = [$tier, $path, $sse, $format, $operation, $state];
        $oversized = [];
        foreach ($textFields as $field) {
            $oversized[] = $this->isOversized($field, self::MAX_FIELD_BYTES);
        }
        if (! $integerId) {
            $oversized[] = $this->isOversized($id, self::MAX_ID_BYTES);
        }
        $oversized[] = $this->isOversized($upload, self::MAX_ID_BYTES);
        if ($json !== 'NULL') {
            $oversized[] = $this->jsonByteLength($json) . ' > ' . self::MAX_JSON_BYTES;
        }
        $boundedId = $integerId ? $id : $this->boundedText($id, self::MAX_ID_BYTES);
        $sql = 'SELECT ' . $boundedId . ' AS id, '
            . $this->boundedText($tier, self::MAX_FIELD_BYTES) . ' AS storage_tier, '
            . $this->boundedText($path, self::MAX_FIELD_BYTES) . ' AS path, '
            . $this->boundedText($sse, self::MAX_FIELD_BYTES) . ' AS sse_mode, '
            . $this->boundedText($format, self::MAX_FIELD_BYTES) . ' AS part_format, '
            . $this->boundedText($operation, self::MAX_FIELD_BYTES) . ' AS operation, '
            . $this->boundedText($state, self::MAX_FIELD_BYTES) . ' AS state, '
            . $this->boundedText($upload, self::MAX_ID_BYTES) . ' AS upload_id, '
            . 'CASE WHEN ' . implode(' OR ', $oversized) . ' THEN 1 ELSE 0 END AS cutover_oversized '
            . $tail;

        try {
            $result = $this->pool->execute($sql, $afterId === null ? [$limit] : [$afterId, $limit]);
            $references = [];
            while (($row = $result->fetchRow()) !== null) {
                if (($row['cutover_oversized'] ?? null) !== 0 && ($row['cutover_oversized'] ?? null) !== '0') {
                    throw new \UnexpectedValueException('Cutover inventory row exceeds its byte limit.');
                }
                $path = $row['path'] ?? null;
                $id = $row['id'] ?? null;
                if ((! is_string($path) && ! $path instanceof \Stringable
                    && ! ($source === self::MULTIPART_UPLOAD_NO_PARTS && $path === null))
                    || (! is_string($id) && ! is_int($id) && ! $id instanceof \Stringable)) {
                    throw new \UnexpectedValueException('Cutover inventory row is invalid.');
                }
                $path = $path === null ? null : (string) $path;
                $id = (string) $id;
                $uploadId = self::nullableText($row['upload_id'] ?? null);
                if (str_contains($id, "\0") || ($path !== null && str_contains($path, "\0"))) {
                    throw new \UnexpectedValueException('Cutover inventory row contains an invalid byte.');
                }
                $relativePathSha256 = match (true) {
                    $source === self::GARBAGE && $row['operation'] === 'abort_multipart'
                        => CutoverPathReferenceClassifier::abortMultipartRelativePathSha256($uploadId),
                    $source === self::MULTIPART_UPLOAD_NO_PARTS
                        => CutoverPathReferenceClassifier::abortMultipartRelativePathSha256($id),
                    default => null,
                };
                $references[] = [
                    'source' => $source,
                    'id' => $id,
                    'storage_tier' => self::nullableText($row['storage_tier']),
                    'path_sha256' => $path !== null && $path !== '' ? hash('sha256', $path) : null,
                    'relative_path_sha256' => $relativePathSha256,
                    'sse_mode' => self::nullableText($row['sse_mode']),
                    'part_format' => self::nullableText($row['part_format']),
                    'operation' => self::nullableText($row['operation']),
                    'state' => self::nullableText($row['state']),
                    'blocking_anomaly' => CutoverPathReferenceClassifier::blockingPathAnomaly(
                        $source,
                        $path,
                        self::nullableText($row['operation']),
                        $relativePathSha256,
                    ),
                ];
            }

            return $references;
        } catch (\Throwable $error) {
            if ($error instanceof \UnexpectedValueException) {
                throw $error;
            }
            throw new \RuntimeException('Cutover inventory query failed.');
        }
    }

    private static function nullableText(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }
        if (! is_string($value) && ! $value instanceof \Stringable) {
            throw new \UnexpectedValueException('Cutover inventory metadata value is invalid.');
        }

        $text = (string) $value;
        if (str_contains($text, "\0")) {
            throw new \UnexpectedValueException('Cutover inventory row contains an invalid byte.');
        }

        return $text;
    }

    private function byteLength(string $expression): string
    {
        return $this->postgres ? "octet_length({$expression})" : "OCTET_LENGTH({$expression})";
    }

    private function jsonByteLength(string $expression): string
    {
        // MySQL converts JSON to its textual representation here; MariaDB's
        // JSON alias is LONGTEXT. Both implement OCTET_LENGTH, unlike
        // JSON_STORAGE_SIZE which MariaDB does not provide.
        return $this->postgres ? "octet_length({$expression}::text)" : "OCTET_LENGTH({$expression})";
    }

    private function jsonText(string $column, string $key): string
    {
        $extracted = $this->postgres
            ? "{$column}->>'{$key}'"
            : "JSON_UNQUOTE(JSON_EXTRACT({$column}, '$.\"{$key}\"'))";

        return 'CASE WHEN ' . $this->jsonByteLength($column) . ' <= ' . self::MAX_JSON_BYTES
            . " THEN {$extracted} ELSE NULL END";
    }

    private function boundedText(string $expression, int $maxBytes): string
    {
        if ($expression === 'NULL') {
            return 'NULL';
        }

        return 'CASE WHEN ' . $this->byteLength($expression) . " <= {$maxBytes}"
            . " THEN {$expression} ELSE NULL END";
    }

    private function isOversized(string $expression, int $maxBytes): string
    {
        return $expression === 'NULL' ? 'FALSE' : $this->byteLength($expression) . " > {$maxBytes}";
    }
}
