<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/** Admits and reserves one local object attempt before any physical staging. */
interface LocalObjectWriteLeaseGuardFactory
{
    public function reserve(ObjectWriteIntent $planned): LocalObjectWriteLeaseGuard;
}
