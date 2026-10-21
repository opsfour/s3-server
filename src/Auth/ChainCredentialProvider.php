<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;
use OpsFour\S3Server\Contracts\CredentialWorkerPoolOwner;

/**
 * Composite credential provider that tries multiple providers in order.
 *
 * The first provider is considered the "primary" and receives writes.
 * Reads try each provider until a match is found.
 */
final class ChainCredentialProvider implements AtomicCredentialCreator, AtomicCredentialDeleter, CredentialHealthProbe, CredentialProvider, AtomicCredentialStatusUpdater, CredentialWorkerPoolOwner
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

    /** Whether issuance and revocation can target one atomically mutable store. */
    public function supportsAtomicCredentialLifecycle(): bool
    {
        if (count($this->providers) !== 1) {
            return false;
        }

        $provider = $this->providers[0];

        return $provider instanceof AtomicCredentialCreator
            && $provider instanceof AtomicCredentialDeleter
            && (!$provider instanceof self || $provider->supportsAtomicCredentialLifecycle());
    }

    public function transferCredentialWorkerPools(\Closure $receiver): void
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof CredentialWorkerPoolOwner) {
                $provider->transferCredentialWorkerPools($receiver);
            }
        }
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

    public function assertCredentialStoreAvailable(): void
    {
        foreach ($this->providers as $provider) {
            if ($provider instanceof CredentialHealthProbe) {
                $provider->assertCredentialStoreAvailable();
            }
        }
    }

    public function putCredential(Credential $credential): void
    {
        // Delegate to the primary (first) provider.
        $this->providers[0]->putCredential($credential);
    }

    public function createCredentialIfAbsent(Credential $credential): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->getCredential($credential->accessKeyId) !== null) {
                return false;
            }
        }

        $primary = $this->providers[0];
        if (! $primary instanceof AtomicCredentialCreator) {
            throw new \RuntimeException('Primary credential provider does not support atomic creation.');
        }

        return $primary->createCredentialIfAbsent($credential);
    }

    public function deleteCredential(string $accessKeyId): void
    {
        // A partial delete can expose a same-ID credential from a fallback
        // provider. Independent stores cannot make this deletion atomic.
        if (count($this->providers) !== 1) {
            throw new \RuntimeException('Deletion is unavailable across multiple credential providers.');
        }

        $this->providers[0]->deleteCredential($accessKeyId);
    }

    public function deleteCredentialIfCurrent(Credential $expected): bool
    {
        // Independent stores cannot commit an all-or-none deletion. Deleting
        // only the primary could expose a different credential in a fallback.
        if (count($this->providers) !== 1) {
            throw new \RuntimeException('Atomic deletion is unavailable across multiple credential providers.');
        }

        $provider = $this->providers[0];
        if (! $provider instanceof AtomicCredentialDeleter) {
            throw new \RuntimeException('Primary credential provider does not support atomic deletion.');
        }

        return $provider->deleteCredentialIfCurrent($expected);
    }

    public function setActiveIfCurrent(Credential $expected, bool $active): bool
    {
        foreach ($this->providers as $provider) {
            if ($provider->getCredential($expected->accessKeyId) !== null) {
                return $provider instanceof AtomicCredentialStatusUpdater
                    && $provider->setActiveIfCurrent($expected, $active);
            }
        }

        return false;
    }
}
