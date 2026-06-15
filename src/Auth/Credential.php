<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

/**
 * Represents an S3 credential pair with owner identity.
 *
 * Uses PHP 8.4 asymmetric visibility: properties are publicly readable
 * but can only be set within the constructor (private(set)).
 */
final class Credential
{
    /**
     * @param  string  $accessKeyId  The access key identifier (e.g., "AKIAIOSFODNN7EXAMPLE").
     * @param  string  $secretAccessKey  The secret key for signing (e.g., "wJalrXUtnFEMI/K7MDENG/bPxRfiCYEXAMPLEKEY").
     * @param  string  $ownerId  The tenant/owner identifier scoping all operations.
     * @param  string  $displayName  Human-readable display name for the credential owner.
     * @param  bool  $isActive  Whether this credential is active and can be used for authentication.
     * @param  string|null  $sessionToken  Optional STS-style session token for temporary credentials.
     * @param  \DateTimeImmutable|null  $expiresAt  Optional UTC expiration timestamp for temporary credentials.
     * @param  list<string>  $policyNames  Optional external IAM policy names attached to this credential.
     * @param  list<string>  $allowedPrefixes  Optional key prefixes allowed for this credential.
     */
    public function __construct(
        public private(set) string $accessKeyId,
        public private(set) string $secretAccessKey,
        public private(set) string $ownerId,
        public private(set) string $displayName = '',
        public private(set) bool $isActive = true,
        public private(set) ?string $sessionToken = null,
        public private(set) ?\DateTimeImmutable $expiresAt = null,
        public private(set) array $policyNames = [],
        public private(set) array $allowedPrefixes = [],
    ) {}
}
