<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Sync\LocalMutex;
use OpsFour\S3Server\Contracts\CredentialProvider;

use function Amp\File\changePermissions;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteFile;
use function Amp\File\isDirectory;
use function Amp\File\move;
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
    private const int MAX_FILE_BYTES = 16_777_216;

    /** @var array<string, Credential> Credentials keyed by accessKeyId. */
    private array $credentials = [];

    private readonly LocalMutex $writeMutex;

    public function __construct(
        private readonly string $filePath,
    ) {
        $this->writeMutex = new LocalMutex();
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

        $content = file_get_contents($this->filePath, false, null, 0, self::MAX_FILE_BYTES + 1);
        if ($content === false || $content === '') {
            return;
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException(
                'Credentials file exceeds the maximum size of ' . self::MAX_FILE_BYTES . ' bytes.',
            );
        }

        /** @var mixed $entries */
        $entries = json_decode($content, true, 512, JSON_THROW_ON_ERROR);
        if (! is_array($entries) || ! array_is_list($entries)) {
            throw new \RuntimeException('Credentials file must contain a JSON array.');
        }

        foreach ($entries as $index => $entry) {
            if (! is_array($entry)) {
                throw new \RuntimeException("Credentials file entry {$index} must be a JSON object.");
            }
            foreach (['accessKeyId', 'secretAccessKey', 'ownerId'] as $required) {
                if (! isset($entry[$required]) || ! is_string($entry[$required]) || $entry[$required] === '') {
                    throw new \RuntimeException(
                        "Credentials file entry {$index} requires a non-empty string field \"{$required}\".",
                    );
                }
            }

            $credential = new Credential(
                accessKeyId: $entry['accessKeyId'],
                secretAccessKey: $entry['secretAccessKey'],
                ownerId: $entry['ownerId'],
                displayName: is_string($entry['displayName'] ?? null) ? $entry['displayName'] : '',
                isActive: is_bool($entry['isActive'] ?? null) ? $entry['isActive'] : true,
                sessionToken: is_string($entry['sessionToken'] ?? null) ? $entry['sessionToken'] : null,
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
        $lock = $this->writeMutex->acquire();

        try {
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
            if (! isDirectory($dir)) {
                createDirectoryRecursively($dir, 0o700);
            }

            $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
            $tempPath = $this->filePath . '.tmp.' . bin2hex(random_bytes(8));

            try {
                write($tempPath, $json);
                changePermissions($tempPath, 0o600);
                move($tempPath, $this->filePath);
            } catch (\Throwable $e) {
                try {
                    deleteFile($tempPath);
                } catch (\Throwable) {
                }
                throw $e;
            }
        } finally {
            $lock->release();
        }
    }
}
