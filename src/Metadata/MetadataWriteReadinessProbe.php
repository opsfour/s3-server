<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Proves that the metadata connection may execute operational DML. */
interface MetadataWriteReadinessProbe
{
    /** Execute a no-row DML statement without changing stored metadata. */
    public function assertMetadataWritable(): void;
}
