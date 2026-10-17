<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/** Directory durability operation bracketed inside its compound syscall sequence. */
interface EfsAwareLocalObjectWriteIo extends LocalObjectWriteIo
{
    public function syncDirectoryWithEvidence(
        string $path,
        EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
    ): bool;
}
