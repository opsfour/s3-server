<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;

/**
 * Composite credential provider that tries multiple providers in order.
 *
 * The first provider is considered the "primary" and receives writes.
 * Reads try each provider until a match is found.
 */
final class ChainCredentialProvider implements CredentialProvider
{
    /** @var list<CredentialProvider> */
    private readonly array $providers;

    public function __construct(CredentialProvider ...$providers)
    {
        if (count($providers) === 0) {
            throw new \InvalidArgumentException('ChainCredentialProvider requires at least one provider.');
        }

        $this->providers = array_values($providers);
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        foreach ($this->providers as $provider) {
            $credential = $provider->getCredential($accessKeyId);
            if ($credential !== null) {
                return $credential;
            }
        }

        return null;
    }

    public function listCredentials(): array
    {
        $seen = [];
        $credentials = [];

        foreach ($this->providers as $provider) {
            foreach ($provider->listCredentials() as $credential) {
                if (! isset($seen[$credential->accessKeyId])) {
                    $seen[$credential->accessKeyId] = true;
                    $credentials[] = $credential;
                }
            }
        }

        return $credentials;
    }

    public function putCredential(Credential $credential): void
    {
        // Delegate to the primary (first) provider.
        $this->providers[0]->putCredential($credential);
    }

    public function deleteCredential(string $accessKeyId): void
    {
        // Delete from all providers.
        foreach ($this->providers as $provider) {
            $provider->deleteCredential($accessKeyId);
        }
    }
}
