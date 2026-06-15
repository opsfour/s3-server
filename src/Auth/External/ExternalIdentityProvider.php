<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

/**
 * Validates external IdP tokens and normalizes them to an identity.
 *
 * Implementations can wrap Keycloak/OIDC JWT verification, introspection, or
 * another trusted identity service. S3 data-plane requests still use SigV4.
 */
interface ExternalIdentityProvider
{
    public function authenticate(string $bearerToken): ExternalIdentity;
}
