<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Host evidence bound to one exact physical storage root. */
interface EfsStorageEvidence extends EfsHostEvidence
{
    public function storageRoot(): string;
}
