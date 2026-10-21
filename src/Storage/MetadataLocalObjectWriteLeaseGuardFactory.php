<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/** Local object lease admission backed by one async metadata adapter. */
final readonly class MetadataLocalObjectWriteLeaseGuardFactory implements LocalObjectWriteLeaseGuardFactory
{
    public function __construct(
        private MetadataLocalObjectWriteLeaseAdapter $adapter,
        private LocalObjectWriteLeaseAdmission $admission,
    ) {}

    public function reserve(ObjectWriteIntent $planned): LocalObjectWriteLeaseGuard
    {
        return LocalObjectWriteLeaseGuard::reserve(
            $planned,
            $this->adapter,
            $this->adapter,
            $this->admission,
        );
    }
}
