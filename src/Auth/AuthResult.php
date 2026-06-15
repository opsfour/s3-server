<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/**
 * Result of a successful S3 authentication.
 *
 * Carries the resolved credential, owner identity, and the list
 * of headers that were included in the signature calculation.
 * Uses PHP 8.4 asymmetric visibility.
 */
final class AuthResult
{
    /**
     * @param  Credential  $credential  The authenticated credential.
     * @param  string  $ownerId  The tenant/owner ID from the credential.
     * @param  array<string>  $signedHeaders  The lowercase header names included in the signature.
     * @param  string  $signature  The hex signature from the Authorization header (for chunked streaming).
     * @param  string  $credentialDate  The date portion from the credential scope (YYYYMMDD).
     * @param  string  $credentialRegion  The region from the credential scope.
     */
    public function __construct(
        public private(set) Credential $credential,
        public private(set) string $ownerId,
        public private(set) array $signedHeaders,
        public private(set) string $signature = '',
        public private(set) string $credentialDate = '',
        public private(set) string $credentialRegion = '',
    ) {}
}
