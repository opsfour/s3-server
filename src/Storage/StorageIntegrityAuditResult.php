<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

final class StorageIntegrityAuditResult
{
    /** @param array<string, int> $sourceCounts
     *  @param list<array{source: string, reference_sha256: string, path_sha256: string, reason: string}> $reportedIssues
     */
    public function __construct(
        public readonly int $checked,
        public readonly int $failed,
        public readonly array $sourceCounts,
        public readonly array $reportedIssues,
    ) {}
}
