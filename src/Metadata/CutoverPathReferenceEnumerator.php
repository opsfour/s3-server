<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Read-only metadata references; physical reconciliation is a separate gate. */
interface CutoverPathReferenceEnumerator
{
    public const string OBJECT = 'object';
    public const string RESTORED_OBJECT = 'restored_object';
    public const string PART = 'part';
    public const string GARBAGE = 'garbage';
    public const string OBJECT_INTENT = 'object_intent';
    public const string MULTIPART_ASSEMBLY_INTENT = 'multipart_assembly_intent';
    public const string MULTIPART_FINAL_INTENT = 'multipart_final_intent';
    public const string MULTIPART_UPLOAD_NO_PARTS = 'multipart_upload_no_parts';
    public const string TIER_TRANSITION_SOURCE = 'tier_transition_source';
    public const string TIER_TRANSITION_TARGET = 'tier_transition_target';
    public const string RESTORE_SOURCE = 'restore_source';
    public const string RESTORE_TARGET = 'restore_target';

    /** @var list<string> */
    public const array SOURCES = [
        self::OBJECT,
        self::RESTORED_OBJECT,
        self::PART,
        self::GARBAGE,
        self::OBJECT_INTENT,
        self::MULTIPART_ASSEMBLY_INTENT,
        self::MULTIPART_FINAL_INTENT,
        self::MULTIPART_UPLOAD_NO_PARTS,
        self::TIER_TRANSITION_SOURCE,
        self::TIER_TRANSITION_TARGET,
        self::RESTORE_SOURCE,
        self::RESTORE_TARGET,
    ];

    /**
     * relative_path_sha256 hashes a tier-root-relative multipart directory for
     * legacy aborts and active uploads without parts when the ID is path-safe.
     *
     * A non-null blocking_anomaly must prevent cutover even when path_sha256 is null.
     *
     * @return list<array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string}>
     */
    public function page(string $source, ?string $afterId = null, int $limit = 100): array;
}
