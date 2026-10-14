<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use OpsFour\S3Server\Auth\CredentialManager;
use OpsFour\S3Server\Auth\CredentialDeletionResult;

/**
 * Issues regular SigV4 credentials for identities authenticated by an external IdP.
 *
 * Revocation is enforced through the backing CredentialProvider. Other nodes
 * observe it after their configured positive-lookup cache TTL; TTL 0 checks
 * the shared store on every authentication.
 * Issued credentials always include an STS-style session token and an
 * expiration timestamp that SigV4 authentication enforces.
 */
final readonly class ExternalCredentialIssuer
{
    public const int DEFAULT_TTL_SECONDS = 3_600;

    public const int MAX_TTL_SECONDS = 43_200;

    public function __construct(
        private CredentialManager $credentials,
    ) {}

    public function issue(ExternalIdentity $identity, ?int $ttlSeconds = null): IssuedS3Credential
    {
        $ttlSeconds ??= self::DEFAULT_TTL_SECONDS;
        if ($ttlSeconds < self::DEFAULT_TTL_SECONDS || $ttlSeconds > self::MAX_TTL_SECONDS) {
            throw new \InvalidArgumentException('Credential TTL must be between 3600 and 43200 seconds.');
        }

        $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("+{$ttlSeconds} seconds");
        $sessionToken = $this->credentials->generateSessionToken();

        $credential = $this->credentials->createCredential(
            ownerId: $identity->ownerId,
            displayName: $identity->displayName !== '' ? $identity->displayName : $identity->subject,
            sessionToken: $sessionToken,
            expiresAt: $expiresAt,
            policyNames: $identity->policyNames,
            allowedPrefixes: $identity->allowedPrefixes,
        );

        return new IssuedS3Credential(
            credential: $credential,
            identity: $identity,
            expiresAt: $expiresAt,
            policyNames: $identity->policyNames,
            allowedPrefixes: $identity->allowedPrefixes,
        );
    }

    public function revoke(string $accessKeyId): bool
    {
        return $this->revokeResult($accessKeyId) === CredentialDeletionResult::Deleted;
    }

    public function revokeResult(string $accessKeyId): CredentialDeletionResult
    {
        return $this->credentials->deleteCredentialResult($accessKeyId);
    }
}
