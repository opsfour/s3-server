<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;

/** Production lease admission restricted to the concrete Amp SQL adapters. */
final readonly class AmpLocalObjectWriteLeaseGuardFactory implements LocalObjectWriteLeaseGuardFactory
{
    public function __construct(
        private AmpLocalObjectWriteLeaseSql $sql,
        private DedicatedAmpObjectWriteLeaseRenewer $renewer,
        private LocalObjectWriteLeaseAdmission $admission,
    ) {}

    /** Prove that reserve/arm and renewal use the candidate's exact resources. */
    public function assertBoundTo(
        PostgresMetadataStore|MysqlMetadataStore $rawMetadata,
        DedicatedAmpObjectWriteLeaseRenewer $renewer,
    ): void {
        $this->sql->assertBoundTo($rawMetadata);
        if ($this->renewer !== $renewer) {
            throw new \RuntimeException('Local object lease renewer differs from the candidate resource.');
        }
    }

    public function reserve(ObjectWriteIntent $planned): LocalObjectWriteLeaseGuard
    {
        return LocalObjectWriteLeaseGuard::reserve($planned, $this->sql, $this->renewer, $this->admission);
    }
}
