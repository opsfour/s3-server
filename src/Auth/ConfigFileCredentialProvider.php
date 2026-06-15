<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;

use function Amp\File\changePermissions;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\isDirectory;
use function Amp\File\write;

/**
 * Credential provider that loads credentials from a JSON file.
 *
 * Expected JSON format:
 * [
 *   {"accessKeyId": "...", "secretAccessKey": "...", "ownerId": "...", "displayName": "..."},
 *   ...
 * ]
 *
 * Credentials are loaded into memory on construction using blocking PHP I/O
 * (safe for pre-event-loop contexts). Write operations (put/delete) update
 * both the in-memory store and the file using Amp\File async I/O.
 */
final class ConfigFileCredentialProvider implements CredentialProvider
{
    /** @var array<string, Credential> Credentials keyed by accessKeyId. */
    private array $credentials = [];

    public function __construct(
        private readonly string $filePath,
    ) {
        $this->loadFromFile();
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        return $this->credentials[$accessKeyId] ?? null;
    }

    public function listCredentials(): array
    {
        return array_values($this->credentials);
    }

    public function putCredential(Credential $credential): void
    {
        $this->credentials[$credential->accessKeyId] = $credential;
        $this->saveToFile();
    }

    public function deleteCredential(string $accessKeyId): void
    {
        unset($this->credentials[$accessKeyId]);
        $this->saveToFile();
    }

    private function loadFromFile(): void
    {
        // Use blocking PHP file functions here because this runs in the
        // constructor, which may execute before the Amp event loop starts
        // (e.g., during Laravel service provider registration).
        if (!file_exists($this->filePath)) {
            return;
        }

        $content = file_get_contents($this->filePath);
        if ($content === false || $content === '') {
            return;
        }

        /** @var list<array{accessKeyId: string, secretAccessKey: string, ownerId: string, displayName?: string}> $entries */
        $entries = json_decode($content, true, 512, JSON_THROW_ON_ERROR);

        foreach ($entries as $entry) {
            $credential = new Credential(
                accessKeyId: $entry['accessKeyId'],
                secretAccessKey: $entry['secretAccessKey'],
                ownerId: $entry['ownerId'],
                displayName: $entry['displayName'] ?? '',
                isActive: $entry['isActive'] ?? true,
                sessionToken: $entry['sessionToken'] ?? null,
                expiresAt: isset($entry['expiresAt']) && is_string($entry['expiresAt'])
                    ? new \DateTimeImmutable($entry['expiresAt'])
                    : null,
                policyNames: isset($entry['policyNames']) && is_array($entry['policyNames'])
                    ? array_values(array_filter($entry['policyNames'], is_string(...)))
                    : [],
                allowedPrefixes: isset($entry['allowedPrefixes']) && is_array($entry['allowedPrefixes'])
                    ? array_values(array_filter($entry['allowedPrefixes'], is_string(...)))
                    : [],
            );
            $this->credentials[$credential->accessKeyId] = $credential;
        }
    }

    private function saveToFile(): void
    {
        $entries = [];

        foreach ($this->credentials as $credential) {
            $entries[] = [
                'accessKeyId' => $credential->accessKeyId,
                'secretAccessKey' => $credential->secretAccessKey,
                'ownerId' => $credential->ownerId,
                'displayName' => $credential->displayName,
                'isActive' => $credential->isActive,
                'sessionToken' => $credential->sessionToken,
                'expiresAt' => $credential->expiresAt?->format(\DateTimeInterface::ATOM),
                'policyNames' => $credential->policyNames,
                'allowedPrefixes' => $credential->allowedPrefixes,
            ];
        }

        $dir = dirname($this->filePath);
        if (!isDirectory($dir)) {
            createDirectoryRecursively($dir, 0o700);
        }

        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        write($this->filePath, $json);
        changePermissions($this->filePath, 0o600);
    }
}
