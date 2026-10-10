<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

use OpsFour\S3Server\Auth\Credential;

/** Delete only the credential identity previously observed by the caller. */
interface AtomicCredentialDeleter
{
    /** Return false if the key was removed or its identity changed. */
    public function deleteCredentialIfCurrent(Credential $expected): bool;
}
