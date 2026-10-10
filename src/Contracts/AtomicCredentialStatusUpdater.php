<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

use OpsFour\S3Server\Auth\Credential;

/** Prevent a concurrent revocation or key replacement from being undone by a status change. */
interface AtomicCredentialStatusUpdater
{
    /** Return false if the credential was removed or its secret changed. */
    public function setActiveIfCurrent(Credential $expected, bool $active): bool;
}
