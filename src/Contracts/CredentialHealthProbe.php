<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

/**
 * Optional readiness probe for credential providers with external dependencies.
 */
interface CredentialHealthProbe
{
    /** Throw when the credential source cannot currently serve authentication reads. */
    public function assertCredentialStoreAvailable(): void;
}
