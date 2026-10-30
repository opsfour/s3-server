<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;

final class FixtureAwsS3OperationalSummary implements AwsS3OperationalSummaryReader
{
    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        if ($storageTier === '') {
            throw new \LogicException('Fixture AWS storage tier is empty.');
        }

        return [
            'open' => 0,
            'ambiguous' => 0,
            'seal_reserved' => 0,
            'sealed' => 0,
            'multipart' => 0,
            'part' => 0,
            'cleanup' => 0,
            'failures' => 0,
            'oldest_age_seconds' => 0.0,
            'max_owner_open' => 0,
            'max_owner_sealed' => 0,
            'max_owner_multipart' => 0,
            'max_owner_part' => 0,
            'global_open' => 0,
            'global_seal_reserved' => 0,
            'global_multipart' => 0,
            'global_part' => 0,
            'global_cleanup' => 0,
        ];
    }
}
