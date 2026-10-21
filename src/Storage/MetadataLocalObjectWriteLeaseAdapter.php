<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/**
 * Uses an async-capable metadata store for local object lease operations.
 * This is used by worker-backed SQLite; PostgreSQL and MySQL keep their
 * dedicated renewal pools so a busy request pool cannot starve a heartbeat.
 */
final readonly class MetadataLocalObjectWriteLeaseAdapter implements LocalObjectWriteLeaseSql, LocalObjectWriteLeaseRenewer, LocalObjectWriteTerminalRetirementSql
{
    public function __construct(private ObjectWriteIntentStore $store) {}

    public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent
    {
        return $this->store->reserveObjectWriteIntent($planned, $leaseSeconds);
    }

    public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->store->armObjectWriteIntent(
            $current->attemptId,
            $current->leaseUntil,
            $dispatchId,
            $leaseSeconds,
        );
    }

    public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
    {
        return $this->store->renewObjectWriteIntent(
            $current->attemptId,
            $current->leaseUntil,
            $leaseSeconds,
        );
    }

    public function retireTerminalPreLink(string $attemptId, string $dispatchId): bool
    {
        return $this->store->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
    }
}
