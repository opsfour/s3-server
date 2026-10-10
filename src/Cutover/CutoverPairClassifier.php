<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Cutover;

use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator as Source;

/**
 * Pure comparison of two already acquired inventory rows. A candidate is
 * only a path/type observation, never a release or ciphertext-authentication
 * result. This class does not prove the caller's snapshot identity or EOF.
 */
final class CutoverPairClassifier
{
    public const string EXACT_FILE_CANDIDATE = 'exact_file_candidate';

    public const string MULTIPART_DIRECTORY_OBSERVATION = 'multipart_directory_observation';

    /**
     * @param array<string, mixed> $reference One CutoverPathReferenceEnumerator row.
     * @param array<string, mixed> $physical One LocalTierPhysicalInventory row.
     */
    public static function classify(
        array $reference,
        ?CutoverInventoryIdentity $sqlIdentity,
        array $physical,
        ?CutoverInventoryIdentity $physicalIdentity,
    ): string {
        if ($sqlIdentity === null || $physicalIdentity === null) {
            return 'missing_context';
        }
        if ($sqlIdentity->snapshotToken !== $physicalIdentity->snapshotToken) {
            return 'snapshot_context_mismatch';
        }
        if ($sqlIdentity->rootPathSha256 !== $physicalIdentity->rootPathSha256) {
            return 'root_context_mismatch';
        }
        if ($sqlIdentity->storageTier !== $physicalIdentity->storageTier) {
            return 'tier_context_mismatch';
        }
        if (! self::referenceRowIsWellFormed($reference)) {
            return 'invalid_sql_row';
        }
        if (($reference['storage_tier'] ?? null) !== $sqlIdentity->storageTier) {
            return 'sql_tier_mismatch';
        }
        if (($physical['storage_tier'] ?? null) !== $physicalIdentity->storageTier) {
            return 'physical_tier_mismatch';
        }
        if (($reference['blocking_anomaly'] ?? null) !== null) {
            return 'sql_blocking_anomaly';
        }

        $source = $reference['source'] ?? null;
        if (! is_string($source) || ! in_array($source, Source::SOURCES, true)) {
            return 'unknown_sql_source';
        }
        if ($source === Source::GARBAGE) {
            return 'cleanup_reference';
        }
        if (in_array($source, [Source::OBJECT_INTENT, Source::MULTIPART_ASSEMBLY_INTENT,
            Source::MULTIPART_FINAL_INTENT], true)) {
            return 'intent_reference';
        }
        if (in_array($source, [Source::TIER_TRANSITION_SOURCE, Source::TIER_TRANSITION_TARGET,
            Source::RESTORE_SOURCE, Source::RESTORE_TARGET], true)) {
            return 'job_reference';
        }
        if (! self::physicalRowIsWellFormed($physical)) {
            return 'invalid_physical_row';
        }
        if ($source === Source::MULTIPART_UPLOAD_NO_PARTS) {
            if (($reference['state'] ?? null) !== 'active_no_parts'
                || ($reference['operation'] ?? null) !== null) {
                return 'sql_state_not_candidate';
            }
            if (($reference['path_sha256'] ?? null) !== null) {
                return 'unexpected_sql_path_digest';
            }
            $scope = $reference['relative_path_sha256'] ?? null;
            if (! self::validDigest($scope)) {
                return 'missing_sql_scope_digest';
            }
            if (($physical['relative_path_sha256'] ?? null) !== $scope) {
                return 'multipart_descendant_or_scope_mismatch';
            }
            if (($physical['type'] ?? null) !== 'directory') {
                return 'wrong_physical_type';
            }
            if (($physical['multipart_scope_sha256'] ?? null) !== $scope) {
                return 'multipart_scope_mismatch';
            }

            return self::MULTIPART_DIRECTORY_OBSERVATION;
        }
        if (($source === Source::RESTORED_OBJECT && ($reference['state'] ?? null) !== 'restored')
            || ($source !== Source::RESTORED_OBJECT && ($reference['state'] ?? null) !== null)
            || ($reference['operation'] ?? null) !== null) {
            return 'sql_state_not_candidate';
        }
        if (($reference['relative_path_sha256'] ?? null) !== null) {
            return 'unexpected_sql_scope_digest';
        }
        $pathDigest = $reference['path_sha256'] ?? null;
        if (! self::validDigest($pathDigest)) {
            return 'missing_sql_path_digest';
        }
        if (($physical['path_sha256'] ?? null) !== $pathDigest) {
            return 'path_digest_mismatch';
        }
        if (($physical['type'] ?? null) !== 'file') {
            return 'wrong_physical_type';
        }
        if (in_array($physical['format'] ?? null, ['object_seal_shape_unverified', 'multipart_seal_shape_unverified'], true)) {
            return 'seal_marker_not_payload';
        }
        if ($source === Source::PART
            && ($reference['part_format'] ?? null) === 'encrypted-v2'
            && ($physical['format'] ?? null) !== 'multipart_v2_header_unverified') {
            return 'encrypted_part_header_missing';
        }

        return self::EXACT_FILE_CANDIDATE;
    }

    /** @param array<string, mixed> $reference */
    private static function referenceRowIsWellFormed(array $reference): bool
    {
        foreach (['source', 'id', 'storage_tier', 'path_sha256', 'relative_path_sha256',
            'sse_mode', 'part_format', 'operation', 'state', 'blocking_anomaly'] as $field) {
            if (! array_key_exists($field, $reference)) {
                return false;
            }
        }
        if (! is_string($reference['source']) || ! is_string($reference['id']) || $reference['id'] === '') {
            return false;
        }
        foreach (['storage_tier', 'path_sha256', 'relative_path_sha256', 'sse_mode',
            'part_format', 'operation', 'state', 'blocking_anomaly'] as $field) {
            if ($reference[$field] !== null && ! is_string($reference[$field])) {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $physical */
    private static function physicalRowIsWellFormed(array $physical): bool
    {
        foreach (['storage_tier', 'path_sha256', 'relative_path_sha256', 'multipart_scope_sha256',
            'type', 'size_bytes', 'format'] as $field) {
            if (! array_key_exists($field, $physical)) {
                return false;
            }
        }
        if (! self::validDigest($physical['path_sha256'] ?? null)
            || ! self::validDigest($physical['relative_path_sha256'] ?? null)
            || (($physical['multipart_scope_sha256'] ?? null) !== null
                && ! self::validDigest($physical['multipart_scope_sha256']))) {
            return false;
        }
        if (($physical['type'] ?? null) === 'directory') {
            return ($physical['size_bytes'] ?? null) === null && ($physical['format'] ?? null) === null;
        }

        return ($physical['type'] ?? null) === 'file'
            && is_int($physical['size_bytes'] ?? null) && $physical['size_bytes'] >= 0
            && in_array($physical['format'] ?? null, [
                'opaque_unclassified',
                'multipart_v2_header_unverified',
                'object_seal_shape_unverified',
                'multipart_seal_shape_unverified',
            ], true);
    }

    private static function validDigest(mixed $digest): bool
    {
        return is_string($digest) && preg_match('/^[a-f0-9]{64}$/D', $digest) === 1;
    }
}
