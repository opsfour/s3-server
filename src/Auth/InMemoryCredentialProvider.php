<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;

/**
 * In-memory credential store.
 *
 * Stores credentials in a PHP array keyed by access key ID.
 * Suitable for testing, development, and single-process deployments
 * where credentials are known at startup.
 */
final class InMemoryCredentialProvider implements AtomicCredentialCreator, AtomicCredentialDeleter, CredentialProvider, AtomicCredentialStatusUpdater
{
    /**
     * @var array<string, Credential> Credentials keyed by accessKeyId.
     */
    private array $credentials = [];

    /**
     * @param  Credential  ...$credentials  Initial credentials to populate the store.
     */
    public function __construct(Credential ...$credentials)
    {
        foreach ($credentials as $credential) {
            $this->credentials[$credential->accessKeyId] = $credential;
        }
    }

    /**
     * {@inheritDoc}
     */
    public function getCredential(string $accessKeyId): ?Credential
    {
        return $this->credentials[$accessKeyId] ?? null;
    }

    /**
     * {@inheritDoc}
     *
     * @return array<Credential>
     */
    public function listCredentials(): array
    {
        return array_values($this->credentials);
    }

    /**
     * {@inheritDoc}
     */
    public function putCredential(Credential $credential): void
    {
        $this->credentials[$credential->accessKeyId] = $credential;
    }

    public function createCredentialIfAbsent(Credential $credential): bool
    {
        if (isset($this->credentials[$credential->accessKeyId])) {
            return false;
        }

        $this->credentials[$credential->accessKeyId] = $credential;

        return true;
    }

    /**
     * {@inheritDoc}
     */
    public function deleteCredential(string $accessKeyId): void
    {
        unset($this->credentials[$accessKeyId]);
    }

    public function deleteCredentialIfCurrent(Credential $expected): bool
    {
        $current = $this->credentials[$expected->accessKeyId] ?? null;
        if ($current === null || !$expected->matchesSnapshot($current)) {
            return false;
        }

        unset($this->credentials[$expected->accessKeyId]);

        return true;
    }

    public function setActiveIfCurrent(Credential $expected, bool $active): bool
    {
        $current = $this->credentials[$expected->accessKeyId] ?? null;
        if ($current === null || !$expected->matchesSnapshot($current)) {
            return false;
        }

        $this->credentials[$expected->accessKeyId] = new Credential(
            accessKeyId: $current->accessKeyId,
            secretAccessKey: $current->secretAccessKey,
            ownerId: $current->ownerId,
            displayName: $current->displayName,
            isActive: $active,
            sessionToken: $current->sessionToken,
            expiresAt: $current->expiresAt,
            policyNames: $current->policyNames,
            allowedPrefixes: $current->allowedPrefixes,
        );

        return true;
    }
}
