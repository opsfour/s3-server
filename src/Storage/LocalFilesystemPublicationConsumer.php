<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Runtime attestation for handlers that can publish bytes to a local filesystem tier. */
interface LocalFilesystemPublicationConsumer
{
    public function usesLocalFilesystemPublicationGraph(
        LocalFencedObjectWriter $objectWriter,
        LocalObjectWriteLeaseGuardFactory $leaseFactory,
        LocalFencedMultipartPartWriter $partWriter,
        string $storageTier,
    ): bool;
}
