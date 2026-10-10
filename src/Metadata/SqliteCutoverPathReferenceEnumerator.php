<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/**
 * Read-only, per-source keyset inventory for an offline local-storage cutover.
 * This is a metadata reference list, not a physical inventory or release gate.
 * SQL projections bound values fetched into PHP. SQLite VM RSS is not bounded
 * by those projections; a hard process limit requires separate isolation.
 */
final class SqliteCutoverPathReferenceEnumerator implements CutoverPathReferenceEnumerator
{
    private const int MAX_PROJECTED_FIELD_BYTES = 4_096;

    private const int MAX_METADATA_JSON_BYTES = 8_192;

    private readonly \PDO $pdo;

    public function __construct(string $databasePath)
    {
        $canonicalPath = realpath($databasePath);
        if (! str_starts_with($databasePath, '/') || is_link($databasePath)
            || $canonicalPath === false || ! is_file($canonicalPath)) {
            throw new \InvalidArgumentException('Cutover inventory requires an existing SQLite database file.');
        }

        // URI mode=ro refuses a missing database and prevents accidental writes.
        // Percent-encode URI metacharacters without encoding path separators.
        $uriPath = implode('/', array_map(rawurlencode(...), explode('/', $canonicalPath)));
        try {
            $this->pdo = new \PDO('sqlite:file:' . $uriPath . '?mode=ro', options: [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            ]);
            $this->pdo->exec('PRAGMA query_only=ON');
        } catch (\PDOException) {
            throw new \RuntimeException('Cutover inventory could not open the SQLite database read-only.');
        }
    }

    /**
     * Pages one source by its immutable integer ID, upload ID or attempt ID.
     * The next cursor is the final row's `id`; an empty page ends that source.
     * Run only after every API and storage worker has stopped: pages do not
     * themselves provide a database/filesystem snapshot.
     *
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

        $cursorPredicate = $afterId === null ? '1=1' : match ($source) {
            self::PART => 'p.id > :after',
            self::MULTIPART_UPLOAD_NO_PARTS => 'u.upload_id COLLATE BINARY > :after',
            self::OBJECT_INTENT, self::MULTIPART_ASSEMBLY_INTENT, self::MULTIPART_FINAL_INTENT => 'attempt_id COLLATE BINARY > :after',
            default => 'id > :after',
        };
        $tier = self::probe('storage_tier');
        $path = self::probe('storage_path');
        $objectSse = self::jsonProbe('user_metadata', '$."__sse-algorithm"');
        $objectJsonOversize = self::metadataOversize('user_metadata', false);
        $restoredTier = self::probe('restored_storage_tier');
        $restoredPath = self::probe('restored_storage_path');
        $restoreStatus = self::probe('restore_status');
        $partTier = self::probe('u.storage_tier');
        $partPath = self::probe('p.storage_path');
        $partSse = self::jsonProbe('u.user_metadata', '$."__sse-algorithm"');
        $partFormat = self::jsonProbe('u.user_metadata', '$."__mpu-part-format"');
        $partJsonOversize = self::metadataOversize('u.user_metadata', true);
        $uploadJsonOversize = self::metadataOversize('u.user_metadata', false);
        $uploadId = self::probe('u.upload_id');
        $garbageUploadId = self::probe('upload_id');
        $attemptId = self::probe('attempt_id');
        $operation = self::probe('operation');
        $purpose = self::probe('purpose');
        $state = self::probe('state');
        $status = self::probe('status');
        $assemblyPath = self::probe('assembly_path');
        $finalPath = self::probe('final_path');
        $sseMode = self::probe('sse_mode');
        $sourceTier = self::probe('source_tier');
        $sourcePath = self::probe('source_storage_path');
        $targetTier = self::probe('target_tier');
        $targetPath = self::probe('target_storage_path');
        $sql = match ($source) {
            self::OBJECT => <<<SQL
                SELECT id, {$tier} AS storage_tier, {$path} AS path,
                       {$objectSse} AS sse_mode,
                       NULL AS part_format, NULL AS operation,
                       CASE WHEN is_delete_marker THEN 'delete_marker_with_path'
                            WHEN storage_path = '' THEN 'missing_object_path'
                            ELSE NULL END AS state,
                       {$objectJsonOversize} AS metadata_oversize
                FROM s3_objects WHERE /*cursor*/ AND (NOT is_delete_marker OR storage_path <> '')
                ORDER BY id LIMIT :limit
                SQL,
            self::RESTORED_OBJECT => <<<SQL
                SELECT id, {$restoredTier} AS storage_tier,
                       {$restoredPath} AS path,
                       {$objectSse} AS sse_mode,
                       NULL AS part_format, NULL AS operation,
                       {$restoreStatus} AS state,
                       {$objectJsonOversize} AS metadata_oversize
                FROM s3_objects WHERE /*cursor*/ AND restored_storage_path IS NOT NULL
                ORDER BY id LIMIT :limit
                SQL,
            self::PART => <<<SQL
                SELECT p.id, {$partTier} AS storage_tier, {$partPath} AS path,
                       {$partSse} AS sse_mode, {$partFormat} AS part_format,
                       NULL AS operation, NULL AS state,
                       {$partJsonOversize} AS metadata_oversize
                FROM s3_parts AS p LEFT JOIN s3_multipart_uploads AS u ON u.upload_id = p.upload_id
                WHERE /*cursor*/ ORDER BY p.id LIMIT :limit
                SQL,
            self::MULTIPART_UPLOAD_NO_PARTS => <<<SQL
                SELECT {$uploadId} AS id, {$partTier} AS storage_tier, NULL AS path,
                       {$partSse} AS sse_mode, {$partFormat} AS part_format,
                       NULL AS operation, 'active_no_parts' AS state,
                       {$uploadJsonOversize} AS metadata_oversize
                FROM s3_multipart_uploads AS u
                WHERE /*cursor*/ AND NOT EXISTS (
                    SELECT 1 FROM s3_parts AS p WHERE p.upload_id = u.upload_id COLLATE BINARY
                ) ORDER BY u.upload_id COLLATE BINARY LIMIT :limit
                SQL,
            self::GARBAGE => <<<SQL
                SELECT id, {$tier} AS storage_tier, {$path} AS path,
                       NULL AS sse_mode, NULL AS part_format,
                       {$operation} AS operation, {$status} AS state,
                       {$garbageUploadId} AS upload_id, 0 AS metadata_oversize
                FROM s3_storage_garbage WHERE /*cursor*/ ORDER BY id LIMIT :limit
                SQL,
            self::OBJECT_INTENT => <<<SQL
                SELECT {$attemptId} AS id, {$tier} AS storage_tier, {$path} AS path,
                       NULL AS sse_mode, NULL AS part_format,
                       {$purpose} AS operation, {$state} AS state,
                       0 AS metadata_oversize
                FROM s3_object_write_intents WHERE /*cursor*/
                ORDER BY attempt_id COLLATE BINARY LIMIT :limit
                SQL,
            self::MULTIPART_ASSEMBLY_INTENT => <<<SQL
                SELECT {$attemptId} AS id, {$tier} AS storage_tier,
                       {$assemblyPath} AS path,
                       {$sseMode} AS sse_mode, NULL AS part_format,
                       'assembly' AS operation, {$state} AS state, 0 AS metadata_oversize
                FROM s3_multipart_write_intents WHERE /*cursor*/
                ORDER BY attempt_id COLLATE BINARY LIMIT :limit
                SQL,
            self::MULTIPART_FINAL_INTENT => <<<SQL
                SELECT {$attemptId} AS id, {$tier} AS storage_tier,
                       {$finalPath} AS path,
                       {$sseMode} AS sse_mode, NULL AS part_format,
                       'final' AS operation, {$state} AS state, 0 AS metadata_oversize
                FROM s3_multipart_write_intents WHERE /*cursor*/
                ORDER BY attempt_id COLLATE BINARY LIMIT :limit
                SQL,
            self::TIER_TRANSITION_SOURCE => <<<SQL
                SELECT id, {$sourceTier} AS storage_tier,
                       {$sourcePath} AS path,
                       NULL AS sse_mode, NULL AS part_format,
                       'transition_source' AS operation, {$status} AS state,
                       0 AS metadata_oversize
                FROM s3_tier_transition_jobs WHERE /*cursor*/ ORDER BY id LIMIT :limit
                SQL,
            self::TIER_TRANSITION_TARGET => <<<SQL
                SELECT id, {$targetTier} AS storage_tier,
                       {$targetPath} AS path,
                       NULL AS sse_mode, NULL AS part_format,
                       'transition_target' AS operation, {$status} AS state,
                       0 AS metadata_oversize
                FROM s3_tier_transition_jobs WHERE /*cursor*/ AND target_storage_path IS NOT NULL
                ORDER BY id LIMIT :limit
                SQL,
            self::RESTORE_SOURCE => <<<SQL
                SELECT id, {$sourceTier} AS storage_tier,
                       {$sourcePath} AS path,
                       NULL AS sse_mode, NULL AS part_format,
                       'restore_source' AS operation, {$status} AS state,
                       0 AS metadata_oversize
                FROM s3_restore_jobs WHERE /*cursor*/ ORDER BY id LIMIT :limit
                SQL,
            self::RESTORE_TARGET => <<<SQL
                SELECT id, NULL AS storage_tier,
                       {$restoredPath} AS path,
                       NULL AS sse_mode, NULL AS part_format,
                       'restore_target' AS operation, {$status} AS state,
                       0 AS metadata_oversize
                FROM s3_restore_jobs WHERE /*cursor*/ AND restored_storage_path IS NOT NULL
                ORDER BY id LIMIT :limit
                SQL,
        };

        $sql = str_replace('/*cursor*/', $cursorPredicate, $sql);
        try {
            $statement = $this->pdo->prepare($sql);
            if ($afterId !== null) {
                $statement->bindValue(':after', $afterId, $integerId ? \PDO::PARAM_INT : \PDO::PARAM_STR);
            }
            $statement->bindValue(':limit', $limit, \PDO::PARAM_INT);
            $statement->execute();
            $references = [];
            while (($row = $statement->fetch()) !== false) {
                if (! in_array($row['metadata_oversize'] ?? null, [0, '0'], true)) {
                    throw new \UnexpectedValueException('Cutover inventory metadata exceeds its safe projection limit.');
                }
                foreach (['id', 'storage_tier', 'path', 'sse_mode', 'part_format', 'operation', 'state', 'upload_id'] as $field) {
                    $value = $row[$field] ?? null;
                    if ($value !== null && ! is_string($value) && ! ($field === 'id' && $integerId && is_int($value))) {
                        throw new \UnexpectedValueException('Cutover inventory projected value is invalid.');
                    }
                    if (is_string($value) && (strlen($value) > self::MAX_PROJECTED_FIELD_BYTES || str_contains($value, "\0"))) {
                        throw new \UnexpectedValueException('Cutover inventory projected value exceeds its safe limit.');
                    }
                }
                if (! $integerId && strlen($row['id']) > 255) {
                    throw new \UnexpectedValueException('Cutover inventory text ID exceeds its cursor limit.');
                }
                $path = $row['path'];
                if (! is_string($path) && ! ($source === self::MULTIPART_UPLOAD_NO_PARTS && $path === null)) {
                    throw new \UnexpectedValueException('Cutover inventory path is invalid.');
                }
                $relativePathSha256 = match (true) {
                    $source === self::GARBAGE && $row['operation'] === 'abort_multipart'
                        => CutoverPathReferenceClassifier::abortMultipartRelativePathSha256($row['upload_id'] ?? null),
                    $source === self::MULTIPART_UPLOAD_NO_PARTS
                        => CutoverPathReferenceClassifier::abortMultipartRelativePathSha256($row['id']),
                    default => null,
                };
                $references[] = [
                    'source' => $source,
                    'id' => (string) $row['id'],
                    'storage_tier' => $row['storage_tier'] !== null ? (string) $row['storage_tier'] : null,
                    'path_sha256' => is_string($path) && $path !== '' ? hash('sha256', $path) : null,
                    'relative_path_sha256' => $relativePathSha256,
                    'sse_mode' => $row['sse_mode'] !== null ? (string) $row['sse_mode'] : null,
                    'part_format' => $row['part_format'] !== null ? (string) $row['part_format'] : null,
                    'operation' => $row['operation'] !== null ? (string) $row['operation'] : null,
                    'state' => $row['state'] !== null ? (string) $row['state'] : null,
                    'blocking_anomaly' => CutoverPathReferenceClassifier::blockingPathAnomaly(
                        $source,
                        $path,
                        $row['operation'] !== null ? (string) $row['operation'] : null,
                        $relativePathSha256,
                    ),
                ];
            }

            return $references;
        } catch (\PDOException) {
            throw new \RuntimeException('Cutover inventory query failed.');
        }
    }

    /** Exact byte prefix; WHERE and ORDER BY keep their original columns. */
    private static function probe(string $column): string
    {
        // SQLite returns NULL for substr() of a zero-length BLOB. Preserve
        // empty paths so the existing blocking anomaly remains visible.
        return 'CASE WHEN ' . $column . " = '' THEN '' ELSE substr(CAST(" . $column
            . ' AS BLOB), 1, ' . (self::MAX_PROJECTED_FIELD_BYTES + 1) . ') END';
    }

    private static function jsonProbe(string $column, string $jsonPath): string
    {
        $extracted = self::probe("json_extract({$column}, '{$jsonPath}')");
        return "CASE WHEN {$column} IS NOT NULL AND octet_length({$column}) <= "
            . self::MAX_METADATA_JSON_BYTES . " THEN {$extracted} ELSE NULL END";
    }

    private static function metadataOversize(string $column, bool $allowNull): string
    {
        $nullCheck = $allowNull ? '' : "{$column} IS NULL OR ";
        return "CASE WHEN {$nullCheck}octet_length({$column}) > " . self::MAX_METADATA_JSON_BYTES
            . ' THEN 1 ELSE 0 END';
    }
}
