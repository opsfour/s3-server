<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;

/** Only the Amp SQL stores are admitted for API-loop reserve and arm calls. */
final readonly class AmpLocalObjectWriteLeaseSql implements LocalObjectWriteLeaseSql, LocalObjectWriteTerminalRetirementSql
{
    public function __construct(private PostgresMetadataStore|MysqlMetadataStore $store) {}

    /** A lease factory must reserve and arm through the candidate's raw SQL store. */
    public function assertBoundTo(PostgresMetadataStore|MysqlMetadataStore $store): void
    {
        if ($this->store !== $store) {
            throw new \RuntimeException('Local object lease SQL differs from the candidate metadata store.');
        }
    }

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

    public function retireTerminalPreLink(string $attemptId, string $dispatchId): bool
    {
        return $this->store->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
    }
}
