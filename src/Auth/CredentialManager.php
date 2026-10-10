<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;

/**
 * Shared service for managing S3 credentials.
 *
 * Wraps a CredentialProvider with key generation, activation toggling,
 * and secret masking. Used by both standalone and Laravel CLI commands.
 */
final class CredentialManager
{
    private const int MAX_GENERATED_KEY_ATTEMPTS = 5;

    public function __construct(
        private readonly CredentialProvider $provider,
    ) {}

    /**
     * Generate a 20-char access key ID matching AWS format: AKIA + 16 uppercase hex.
     */
    public function generateAccessKeyId(): string
    {
        return 'AKIA' . strtoupper(substr(bin2hex(random_bytes(8)), 0, 16));
    }

    /**
     * Generate a 40-char secret key matching AWS format: base64-encoded random bytes.
     */
    public function generateSecretAccessKey(): string
    {
        return base64_encode(random_bytes(30));
    }

    public function generateSessionToken(): string
    {
        return rtrim(strtr(base64_encode(random_bytes(48)), '+/', '-_'), '=');
    }

    /**
     * Create and persist a new credential. Auto-generates keys if not provided.
     *
     * Returns the credential with the plaintext secret key (only opportunity to read it).
     *
     * @param list<string> $policyNames
     * @param list<string> $allowedPrefixes
     */
    public function createCredential(
        string $ownerId,
        string $displayName = '',
        ?string $accessKeyId = null,
        ?string $secretAccessKey = null,
        ?string $sessionToken = null,
        ?\DateTimeImmutable $expiresAt = null,
        array $policyNames = [],
        array $allowedPrefixes = [],
    ): Credential {
        if (! $this->provider instanceof AtomicCredentialCreator) {
            throw new \RuntimeException('Credential provider does not support atomic creation.');
        }

        $secretAccessKey ??= $this->generateSecretAccessKey();
        $attempts = $accessKeyId === null ? self::MAX_GENERATED_KEY_ATTEMPTS : 1;
        for ($attempt = 0; $attempt < $attempts; $attempt++) {
            $credential = new Credential(
                accessKeyId: $accessKeyId ?? $this->generateAccessKeyId(),
                secretAccessKey: $secretAccessKey,
                ownerId: $ownerId,
                displayName: $displayName,
                isActive: true,
                sessionToken: $sessionToken,
                expiresAt: $expiresAt,
                policyNames: $policyNames,
                allowedPrefixes: $allowedPrefixes,
            );

            if ($this->provider->createCredentialIfAbsent($credential)) {
                return $credential;
            }
        }

        throw new \RuntimeException($accessKeyId !== null
            ? "Credential already exists: {$accessKeyId}"
            : 'Could not generate an unused credential access key ID.');
    }

    /**
     * @return list<Credential>
     */
    public function listCredentials(): array
    {
        return $this->provider->listCredentials();
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        return $this->provider->getCredential($accessKeyId);
    }

    /**
     * Set credential active status. Returns the updated credential.
     *
     * @throws \RuntimeException If credential not found.
     */
    public function setActive(string $accessKeyId, bool $active): Credential
    {
        $existing = $this->provider->getCredential($accessKeyId);

        if ($existing === null) {
            throw new \RuntimeException("Credential not found: {$accessKeyId}");
        }

        $updated = new Credential(
            accessKeyId: $existing->accessKeyId,
            secretAccessKey: $existing->secretAccessKey,
            ownerId: $existing->ownerId,
            displayName: $existing->displayName,
            isActive: $active,
            sessionToken: $existing->sessionToken,
            expiresAt: $existing->expiresAt,
            policyNames: $existing->policyNames,
            allowedPrefixes: $existing->allowedPrefixes,
        );

        if (! $this->provider instanceof AtomicCredentialStatusUpdater) {
            throw new \RuntimeException('Credential provider does not support atomic status updates.');
        }
        if (! $this->provider->setActiveIfCurrent($existing, $active)) {
            throw new \RuntimeException("Credential changed or was revoked: {$accessKeyId}");
        }

        return $updated;
    }

    /**
     * Delete a credential.
     *
     * @return bool True if the credential existed and was deleted, false if not found.
     */
    public function deleteCredential(string $accessKeyId): bool
    {
        $expected = $this->provider->getCredential($accessKeyId);
        if ($expected === null) {
            return false;
        }

        if (! $this->provider instanceof AtomicCredentialDeleter) {
            throw new \RuntimeException('Credential provider does not support atomic deletion.');
        }

        return $this->provider->deleteCredentialIfCurrent($expected);
    }

    /**
     * Mask a secret key for display: last 4 chars visible, rest replaced with asterisks.
     */
    public function maskSecretKey(string $secretKey): string
    {
        if (strlen($secretKey) <= 4) {
            return str_repeat('*', strlen($secretKey));
        }

        return str_repeat('*', strlen($secretKey) - 4) . substr($secretKey, -4);
    }
}
