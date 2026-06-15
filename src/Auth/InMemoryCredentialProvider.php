<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;

/**
 * In-memory credential store.
 *
 * Stores credentials in a PHP array keyed by access key ID.
 * Suitable for testing, development, and single-process deployments
 * where credentials are known at startup.
 */
final class InMemoryCredentialProvider implements CredentialProvider
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

    /**
     * {@inheritDoc}
     */
    public function deleteCredential(string $accessKeyId): void
    {
        unset($this->credentials[$accessKeyId]);
    }
}
