<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

use OpsFour\S3Server\Auth\Credential;

/** Create a credential only when its access key ID is still unused. */
interface AtomicCredentialCreator
{
    /** Return false on an access-key collision without changing the existing credential. */
    public function createCredentialIfAbsent(Credential $credential): bool;
}
