<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Sync\LocalMutex;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;

use function Amp\File\changePermissions;
use function Amp\File\createDirectory;
use function Amp\File\createDirectoryRecursively;
use function Amp\File\deleteDirectory;
use function Amp\File\deleteFile;
use function Amp\File\getSize;
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
final class ConfigFileCredentialProvider implements AtomicCredentialCreator, AtomicCredentialDeleter, CredentialProvider, AtomicCredentialStatusUpdater
{
    private const int MAX_FILE_BYTES = 16_777_216;

    private const array CREDENTIAL_FIELDS = [
        'accessKeyId',
        'secretAccessKey',
        'ownerId',
        'displayName',
        'isActive',
        'sessionToken',
        'expiresAt',
        'policyNames',
        'allowedPrefixes',
    ];

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
        $lock = $this->writeMutex->acquire();

        try {
            $credentials = $this->credentials;
            $credentials[$credential->accessKeyId] = $credential;
            $this->saveToFile($credentials);
            $this->credentials = $credentials;
        } finally {
            $lock->release();
        }
    }

    public function createCredentialIfAbsent(Credential $credential): bool
    {
        $lock = $this->writeMutex->acquire();

        try {
            if (isset($this->credentials[$credential->accessKeyId])) {
                return false;
            }

            $credentials = $this->credentials;
            $credentials[$credential->accessKeyId] = $credential;
            $this->saveToFile($credentials);
            $this->credentials = $credentials;

            return true;
        } finally {
            $lock->release();
        }
    }

    public function deleteCredential(string $accessKeyId): void
    {
        $lock = $this->writeMutex->acquire();

        try {
            $credentials = $this->credentials;
            unset($credentials[$accessKeyId]);
            $this->saveToFile($credentials);
            $this->credentials = $credentials;
        } finally {
            $lock->release();
        }
    }

    public function deleteCredentialIfCurrent(Credential $expected): bool
    {
        $lock = $this->writeMutex->acquire();

        try {
            $current = $this->credentials[$expected->accessKeyId] ?? null;
            if ($current === null || !$expected->matchesSnapshot($current)) {
                return false;
            }

            $credentials = $this->credentials;
            unset($credentials[$expected->accessKeyId]);
            $this->saveToFile($credentials);
            $this->credentials = $credentials;

            return true;
        } finally {
            $lock->release();
        }
    }

    public function setActiveIfCurrent(Credential $expected, bool $active): bool
    {
        $lock = $this->writeMutex->acquire();

        try {
            $current = $this->credentials[$expected->accessKeyId] ?? null;
            if ($current === null || !$expected->matchesSnapshot($current)) {
                return false;
            }

            $credentials = $this->credentials;
            $credentials[$expected->accessKeyId] = new Credential(
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
            $this->saveToFile($credentials);
            $this->credentials = $credentials;

            return true;
        } finally {
            $lock->release();
        }
    }

    private function loadFromFile(): void
    {
        // Use blocking PHP file functions here because this runs in the
        // constructor, which may execute before the Amp event loop starts
        // (e.g., during Laravel service provider registration).
        $this->assertTrustedExistingCredentialDirectoryAncestor(dirname($this->filePath));
        clearstatcache(true, $this->filePath);
        $initialPathStatus = @lstat($this->filePath);
        if ($initialPathStatus === false) {
            return;
        }

        $directory = $this->trustedCredentialDirectory(dirname($this->filePath));
        $targetPath = rtrim($directory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($this->filePath);
        clearstatcache(true, $targetPath);
        $initialPathStatus = @lstat($targetPath);
        if ($initialPathStatus === false) {
            throw new \RuntimeException('Credentials file became unavailable before it could be opened.');
        }

        $effectiveUid = posix_geteuid();
        self::assertSecureCredentialFile($initialPathStatus, $effectiveUid);

        $handle = @fopen($targetPath, 'rb');
        if ($handle === false) {
            throw new \RuntimeException('Credentials file could not be opened for reading.');
        }

        try {
            $openedStatus = fstat($handle);
            if ($openedStatus === false) {
                throw new \RuntimeException('Credentials file metadata could not be inspected.');
            }
            self::assertSecureCredentialFile($openedStatus, $effectiveUid);
            self::assertSameCredentialFile($initialPathStatus, $openedStatus);

            $content = stream_get_contents($handle, self::MAX_FILE_BYTES + 1);
            if ($content === false) {
                throw new \RuntimeException('Credentials file could not be read.');
            }

            $openedAfterRead = fstat($handle);
            clearstatcache(true, $targetPath);
            $pathAfterRead = @lstat($targetPath);
            if ($openedAfterRead === false || $pathAfterRead === false) {
                throw new \RuntimeException('Credentials file changed while it was being read.');
            }
            self::assertSecureCredentialFile($openedAfterRead, $effectiveUid);
            self::assertSecureCredentialFile($pathAfterRead, $effectiveUid);
            self::assertSameCredentialFile($openedStatus, $openedAfterRead);
            self::assertSameCredentialFile($openedStatus, $pathAfterRead);
            if ($openedAfterRead['size'] !== $openedStatus['size']) {
                throw new \RuntimeException('Credentials file changed while it was being read.');
            }
        } finally {
            fclose($handle);
        }

        if ($content === '') {
            throw new \RuntimeException('Credentials file must not be empty.');
        }
        if (strlen($content) > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Credentials file exceeds the maximum size of ' . self::MAX_FILE_BYTES . ' bytes.');
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
            foreach (array_keys($entry) as $field) {
                if (! is_string($field) || ! in_array($field, self::CREDENTIAL_FIELDS, true)) {
                    throw new \RuntimeException("Credentials file entry {$index} contains an unknown field.");
                }
            }
            foreach (['accessKeyId', 'secretAccessKey', 'ownerId'] as $required) {
                if (! isset($entry[$required]) || ! is_string($entry[$required]) || $entry[$required] === '') {
                    throw new \RuntimeException(
                        "Credentials file entry {$index} requires a non-empty string field \"{$required}\".",
                    );
                }
            }

            if (array_key_exists('displayName', $entry) && ! is_string($entry['displayName'])) {
                throw new \RuntimeException("Credentials file entry {$index} displayName must be a string.");
            }
            if (array_key_exists('isActive', $entry) && ! is_bool($entry['isActive'])) {
                throw new \RuntimeException("Credentials file entry {$index} isActive must be a boolean.");
            }
            if (array_key_exists('sessionToken', $entry)
                && $entry['sessionToken'] !== null
                && ! is_string($entry['sessionToken'])) {
                throw new \RuntimeException("Credentials file entry {$index} sessionToken must be a string or null.");
            }
            if (array_key_exists('expiresAt', $entry)
                && $entry['expiresAt'] !== null
                && ! is_string($entry['expiresAt'])) {
                throw new \RuntimeException("Credentials file entry {$index} expiresAt must be a timestamp or null.");
            }
            $policyNames = self::stringListField($entry, 'policyNames', $index);
            $allowedPrefixes = self::stringListField($entry, 'allowedPrefixes', $index);
            try {
                $expiresAt = isset($entry['expiresAt'])
                    ? new \DateTimeImmutable($entry['expiresAt'])
                    : null;
            } catch (\Exception $error) {
                throw new \RuntimeException("Credentials file entry {$index} expiresAt is invalid.", 0, $error);
            }

            $credential = new Credential(
                accessKeyId: $entry['accessKeyId'],
                secretAccessKey: $entry['secretAccessKey'],
                ownerId: $entry['ownerId'],
                displayName: $entry['displayName'] ?? '',
                isActive: $entry['isActive'] ?? true,
                sessionToken: $entry['sessionToken'] ?? null,
                expiresAt: $expiresAt,
                policyNames: $policyNames,
                allowedPrefixes: $allowedPrefixes,
            );
            if (isset($this->credentials[$credential->accessKeyId])) {
                throw new \RuntimeException("Credentials file contains duplicate accessKeyId at entry {$index}.");
            }
            $this->credentials[$credential->accessKeyId] = $credential;
        }
    }

    private function assertTrustedExistingCredentialDirectoryAncestor(string $directory): void
    {
        for ($candidate = $directory; ; $candidate = dirname($candidate)) {
            clearstatcache(true, $candidate);
            if (@lstat($candidate) !== false) {
                if (! is_dir($candidate)) {
                    throw new \RuntimeException('Credentials directory is unavailable.');
                }

                $canonical = $this->trustedCredentialDirectory($candidate);
                if (! is_executable($canonical)) {
                    throw new \RuntimeException('Credentials directory is unavailable.');
                }

                return;
            }

            if ($candidate === dirname($candidate)) {
                throw new \RuntimeException('Credentials directory is unavailable.');
            }
        }
    }

    /** @param array<string|int, int> $status */
    private static function assertSecureCredentialFile(array $status, int $effectiveUid): void
    {
        if (($status['mode'] & 0o170000) !== 0o100000) {
            throw new \RuntimeException('Credentials file must be a regular file and must not be a symbolic link.');
        }
        if ($status['uid'] !== 0 && $status['uid'] !== $effectiveUid) {
            throw new \RuntimeException('Credentials file must be owned by root or the effective user.');
        }
        if (($status['mode'] & 0o077) !== 0) {
            throw new \RuntimeException('Credentials file permissions must deny all group and other access.');
        }
        if (($status['mode'] & 0o400) === 0) {
            throw new \RuntimeException('Credentials file must be readable by its owner.');
        }
        if ($status['size'] === 0) {
            throw new \RuntimeException('Credentials file must not be empty.');
        }
        if ($status['size'] > self::MAX_FILE_BYTES) {
            throw new \RuntimeException('Credentials file exceeds the maximum size of ' . self::MAX_FILE_BYTES . ' bytes.');
        }
    }

    /**
     * @param array<string|int, int> $expected
     * @param array<string|int, int> $actual
     */
    private static function assertSameCredentialFile(array $expected, array $actual): void
    {
        if ($expected['dev'] !== $actual['dev'] || $expected['ino'] !== $actual['ino']) {
            throw new \RuntimeException('Credentials file changed while it was being opened or read.');
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @return list<string>
     */
    private static function stringListField(array $entry, string $name, int $index): array
    {
        if (! array_key_exists($name, $entry)) {
            return [];
        }

        $value = $entry[$name];
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \RuntimeException("Credentials file entry {$index} {$name} must be a string list.");
        }
        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new \RuntimeException("Credentials file entry {$index} {$name} must contain only non-empty strings.");
            }
        }

        return $value;
    }

    /** @param array<string, Credential> $credentials */
    private function saveToFile(array $credentials): void
    {
        $entries = [];

        foreach ($credentials as $credential) {
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

        $dir = $this->trustedCredentialDirectory($dir);
        $targetPath = rtrim($dir, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . basename($this->filePath);

        $json = json_encode($entries, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR);
        $tempDir = $targetPath . '.tmp.' . bin2hex(random_bytes(16));
        createDirectory($tempDir, 0o700);
        $tempPath = $tempDir . '/credentials.json';

        try {
            // Amp\File\write() creates with the process umask. Keep every
            // secret byte inside an exclusively created private directory
            // until the file has its final owner-only permissions.
            write($tempPath, $json);
            if (getSize($tempPath) !== strlen($json)) {
                throw new \RuntimeException('Credential file write was incomplete.');
            }
            changePermissions($tempPath, 0o600);
            clearstatcache(true, $tempPath);
            $tempPermissions = @fileperms($tempPath);
            if ($tempPermissions === false || ($tempPermissions & 0o077) !== 0) {
                throw new \RuntimeException('Credential file permissions are not private.');
            }
            move($tempPath, $targetPath);
        } catch (\Throwable $e) {
            try {
                deleteFile($tempPath);
            } catch (\Throwable) {
            }
            throw $e;
        } finally {
            try {
                deleteDirectory($tempDir);
            } catch (\Throwable) {
                // A failed cleanup retains only a private empty directory.
            }
        }
    }

    private function trustedCredentialDirectory(string $directory): string
    {
        $canonical = realpath($directory);
        if ($canonical === false) {
            throw new \RuntimeException('Credentials directory is unavailable.');
        }
        if (DIRECTORY_SEPARATOR === '/' && ! function_exists('posix_geteuid')) {
            throw new \RuntimeException('Credential file writes require POSIX ownership verification.');
        }
        $effectiveUid = function_exists('posix_geteuid') ? posix_geteuid() : null;

        // An attacker who owns any ancestor can rename a private child and
        // replace it between the permission check and the secret write.
        for ($path = $canonical; ; $path = dirname($path)) {
            clearstatcache(true, $path);
            $permissions = @fileperms($path);
            $owner = @fileowner($path);
            if ($permissions === false
                || (($permissions & 0o022) !== 0 && ($permissions & 0o1000) === 0)
                || ($effectiveUid !== null && ($owner === false || ($owner !== 0 && $owner !== $effectiveUid)))) {
                throw new \RuntimeException('Credentials directory must have trusted, private or sticky ancestors.');
            }
            if ($path === dirname($path)) {
                break;
            }
        }

        return $canonical;
    }
}
