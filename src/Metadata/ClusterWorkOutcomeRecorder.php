<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Optional PostgreSQL telemetry capability kept separate from fencing itself. */
interface ClusterWorkOutcomeRecorder
{
    public const string FAILED_TAKEOVER = 'failed_takeover';
    public const string STALE_ACKNOWLEDGEMENT = 'stale_acknowledgement';

    public function recordClusterWorkOutcome(ClusterWorkToken $token, string $outcome): void;
}
