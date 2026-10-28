<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** PostgreSQL-only bounded aggregate view used by AWS operations telemetry. */
interface AwsS3OperationalSummaryReader
{
    /**
     * @return array{
     *   open: int,
     *   ambiguous: int,
     *   seal_reserved: int,
     *   sealed: int,
     *   multipart: int,
     *   part: int,
     *   cleanup: int,
     *   failures: int,
     *   oldest_age_seconds: float,
     *   max_owner_open: int,
     *   max_owner_sealed: int,
     *   max_owner_part: int
     * }
     */
    public function getAwsS3OperationalSummary(): array;
}
