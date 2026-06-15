<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use OpsFour\S3Server\Auth\CredentialManager;

/**
 * Issues regular SigV4 credentials for identities authenticated by an external IdP.
 *
 * Revocation is enforced through the backing CredentialProvider immediately.
 * When a TTL is provided the issued credential includes an STS-style session
 * token and expiration timestamp that SigV4 authentication enforces.
 */
final readonly class ExternalCredentialIssuer
{
    public function __construct(
        private CredentialManager $credentials,
    ) {}

    public function issue(ExternalIdentity $identity, ?int $ttlSeconds = null): IssuedS3Credential
    {
        if ($ttlSeconds !== null && $ttlSeconds < 1) {
            throw new \InvalidArgumentException('Credential TTL must be at least one second.');
        }

        $expiresAt = null;
        $sessionToken = null;
        if ($ttlSeconds !== null) {
            $expiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                ->modify("+{$ttlSeconds} seconds");
            $sessionToken = $this->credentials->generateSessionToken();
        }

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
        return $this->credentials->deleteCredential($accessKeyId);
    }
}
