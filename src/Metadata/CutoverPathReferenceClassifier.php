<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use OpsFour\S3Server\Storage\StoragePath;

/** Derive root-relative physical scopes without exposing persisted upload IDs. */
final class CutoverPathReferenceClassifier
{
    /** A non-null result is a cutover blocker, even when no path digest exists. */
    public static function blockingPathAnomaly(
        string $source,
        ?string $path,
        ?string $operation,
        ?string $relativePathSha256,
    ): ?string {
        // Active uploads without parts have no persisted path. A validated
        // upload ID still yields the directory scope that might exist.
        if ($source === CutoverPathReferenceEnumerator::MULTIPART_UPLOAD_NO_PARTS) {
            return $relativePathSha256 === null ? 'uncorrelatable_active_upload_scope' : null;
        }
        // Abort cleanup ignores storage_path and acts on the upload directory.
        // A nonempty stored path is therefore conflicting evidence, not a
        // substitute for an invalid upload ID.
        if ($source === CutoverPathReferenceEnumerator::GARBAGE && $operation === 'abort_multipart') {
            if ($relativePathSha256 === null) {
                return 'uncorrelatable_abort_multipart_scope';
            }
            return $path === '' ? null : 'unexpected_abort_multipart_path';
        }
        if ($path !== '') {
            return null;
        }

        return match ($source) {
            CutoverPathReferenceEnumerator::OBJECT => 'empty_object_path',
            CutoverPathReferenceEnumerator::RESTORED_OBJECT => 'empty_restored_object_path',
            CutoverPathReferenceEnumerator::PART => 'empty_part_path',
            CutoverPathReferenceEnumerator::GARBAGE => 'empty_garbage_path',
            CutoverPathReferenceEnumerator::OBJECT_INTENT => 'empty_object_intent_path',
            CutoverPathReferenceEnumerator::MULTIPART_ASSEMBLY_INTENT => 'empty_multipart_assembly_intent_path',
            CutoverPathReferenceEnumerator::MULTIPART_FINAL_INTENT => 'empty_multipart_final_intent_path',
            CutoverPathReferenceEnumerator::TIER_TRANSITION_SOURCE => 'empty_tier_transition_source_path',
            CutoverPathReferenceEnumerator::TIER_TRANSITION_TARGET => 'empty_tier_transition_target_path',
            CutoverPathReferenceEnumerator::RESTORE_SOURCE => 'empty_restore_source_path',
            CutoverPathReferenceEnumerator::RESTORE_TARGET => 'empty_restore_target_path',
            default => 'empty_unknown_path',
        };
    }

    public static function abortMultipartRelativePathSha256(mixed $uploadId): ?string
    {
        if (! is_string($uploadId) && ! $uploadId instanceof \Stringable) {
            return null;
        }
        $uploadId = (string) $uploadId;
        try {
            StoragePath::assertSafeUploadIdSegment($uploadId);
        } catch (\InvalidArgumentException) {
            return null;
        }

        // Local and Flysystem abort both target this tier-root-relative directory.
        return hash('sha256', '.parts/' . $uploadId);
    }
}
