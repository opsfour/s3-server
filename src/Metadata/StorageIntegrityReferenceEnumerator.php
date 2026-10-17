<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Bounded, read-only physical references used by the offline storage audit. */
interface StorageIntegrityReferenceEnumerator
{
    public const string OBJECT = 'object';
    public const string RESTORED_OBJECT = 'restored_object';
    public const string MULTIPART_PART = 'multipart_part';
    public const string RESTORE_SOURCE = 'restore_source';
    public const string RESTORE_TARGET = 'restore_target';

    /** @var list<string> */
    public const array SOURCES = [
        self::OBJECT,
        self::RESTORED_OBJECT,
        self::MULTIPART_PART,
        self::RESTORE_SOURCE,
        self::RESTORE_TARGET,
    ];

    /**
     * @return list<array{source: string, id: string, path: string, expected_size: ?int}>
     */
    public function page(string $source, ?string $afterId, int $limit): array;

    public function close(): void;
}
