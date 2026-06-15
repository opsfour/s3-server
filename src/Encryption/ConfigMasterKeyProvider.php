<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/**
 * Reads master keys from configuration or environment variables.
 *
 * Supports two formats:
 * - Multi-key (preferred): S3_ENCRYPTION_MASTER_KEYS={"key-2025":"base64...","key-2024":"base64..."}
 *   First key is active, others are for decrypting historical data.
 * - Legacy single-key: S3_ENCRYPTION_MASTER_KEY=base64...
 *   Stored internally with key ID "default".
 */
final class ConfigMasterKeyProvider implements MasterKeyProvider
{
    /** @var array<string, string> Key ID → raw 32-byte key */
    private readonly array $keys;

    private readonly string $activeKeyId;

    /**
     * @param string|null $base64Key Legacy single key (base64).
     * @param string|null $keysJson Multi-key JSON map (key ID → base64 key).
     */
    public function __construct(?string $base64Key = null, ?string $keysJson = null)
    {
        $envMulti = $keysJson ?? (getenv('S3_ENCRYPTION_MASTER_KEYS') ?: false);

        if ($envMulti !== false && $envMulti !== '') {
            $parsed = json_decode($envMulti, true, 4, JSON_THROW_ON_ERROR);
            if (!is_array($parsed) || $parsed === []) {
                throw new \RuntimeException('S3_ENCRYPTION_MASTER_KEYS must be a non-empty JSON object of {keyId: base64Key}.');
            }

            $keys = [];
            foreach ($parsed as $keyId => $b64) {
                $keyId = (string) $keyId;
                if (strlen($keyId) > 255) {
                    throw new \RuntimeException("Key ID '{$keyId}' exceeds 255 characters.");
                }
                $decoded = base64_decode($b64, true);
                if ($decoded === false || strlen($decoded) !== 32) {
                    throw new \RuntimeException("Master key '{$keyId}' must be exactly 32 bytes (256 bits) base64-encoded.");
                }
                $keys[$keyId] = $decoded;
            }

            $this->keys = $keys;
            $this->activeKeyId = array_key_first($keys);

            return;
        }

        // Legacy single-key format.
        $envKey = $base64Key ?? (getenv('S3_ENCRYPTION_MASTER_KEY') ?: false);
        if ($envKey === false || $envKey === '') {
            throw new \RuntimeException('S3_ENCRYPTION_MASTER_KEY or S3_ENCRYPTION_MASTER_KEYS environment variable is required.');
        }

        $decoded = base64_decode($envKey, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('Master key must be exactly 32 bytes (256 bits) base64-encoded.');
        }

        $this->keys = ['default' => $decoded];
        $this->activeKeyId = 'default';
    }

    public function getMasterKey(): string
    {
        return $this->keys[$this->activeKeyId];
    }

    public function getKeyId(): string
    {
        return $this->activeKeyId;
    }

    public function getMasterKeyById(string $keyId): string
    {
        return $this->keys[$keyId] ?? throw new \RuntimeException("Unknown key ID: {$keyId}");
    }
}
