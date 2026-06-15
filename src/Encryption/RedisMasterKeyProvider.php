<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/**
 * Reads master keys from Redis.
 *
 * Requires amphp/redis (^2.0). Install via: composer require amphp/redis
 *
 * Multi-key format:
 * - HGETALL s3:master-keys → all key ID → base64 key mappings
 * - GET s3:active-key-id → active key ID
 *
 * Legacy single-key format (fallback):
 * - GET s3:master-key → base64 key (stored as key ID "default")
 *
 * Environment variables:
 * - S3_REDIS_MASTER_KEY_DSN: Redis DSN (e.g., 'redis://localhost:6379')
 * - S3_REDIS_MASTER_KEY_NAME: Redis key name for legacy single-key (default: 's3:master-key')
 */
final class RedisMasterKeyProvider implements MasterKeyProvider
{
    /** @var array<string, string> Key ID → raw 32-byte key */
    private readonly array $keys;

    private readonly string $activeKeyId;

    public function __construct(
        ?string $redisDsn = null,
        ?string $keyName = null,
    ) {
        if (!class_exists(\Amp\Redis\RedisClient::class)) {
            throw new \RuntimeException(
                'amphp/redis is required for RedisMasterKeyProvider. Install via: composer require amphp/redis',
            );
        }

        $dsn = $redisDsn ?? (getenv('S3_REDIS_MASTER_KEY_DSN') ?: false);
        if ($dsn === false || $dsn === '') {
            throw new \RuntimeException('S3_REDIS_MASTER_KEY_DSN environment variable or redisDsn parameter is required.');
        }

        /** @var \Amp\Redis\RedisClient $client */
        $client = new \Amp\Redis\RedisClient($dsn);

        // Try multi-key format first (HGETALL s3:master-keys).
        /** @var array<string, string> $allKeys */
        $allKeys = [];
        try {
            $rawKeys = $client->execute('HGETALL', 's3:master-keys');
            if (is_array($rawKeys) && $rawKeys !== []) {
                $assoc = [];
                $isList = array_is_list($rawKeys);
                if ($isList) {
                    for ($i = 0, $n = count($rawKeys); $i + 1 < $n; $i += 2) {
                        if (is_string($rawKeys[$i]) && is_string($rawKeys[$i + 1])) {
                            $assoc[$rawKeys[$i]] = $rawKeys[$i + 1];
                        }
                    }
                } else {
                    foreach ($rawKeys as $keyId => $b64) {
                        if (is_string($keyId) && is_string($b64)) {
                            $assoc[$keyId] = $b64;
                        }
                    }
                }
                $allKeys = $assoc;
            }
        } catch (\Throwable) {
            $allKeys = [];
        }

        if ($allKeys !== []) {
            $keys = [];
            foreach ($allKeys as $keyId => $b64) {
                $decoded = base64_decode($b64, true);
                if ($decoded === false || strlen($decoded) !== 32) {
                    throw new \RuntimeException("Master key '{$keyId}' from Redis must be exactly 32 bytes base64-encoded.");
                }
                $keys[(string) $keyId] = $decoded;
            }

            $activeId = $client->get('s3:active-key-id');
            if ($activeId === null || !isset($keys[$activeId])) {
                $activeId = array_key_first($keys);
            }

            $this->keys = $keys;
            $this->activeKeyId = $activeId;

            return;
        }

        // Fallback to legacy single-key.
        $name = $keyName ?? (getenv('S3_REDIS_MASTER_KEY_NAME') ?: 's3:master-key');
        $base64Key = $client->get($name);
        if ($base64Key === null) {
            throw new \RuntimeException("Master key not found in Redis at key '{$name}'.");
        }

        $decoded = base64_decode($base64Key, true);
        if ($decoded === false || strlen($decoded) !== 32) {
            throw new \RuntimeException('Master key from Redis must be exactly 32 bytes (256 bits) base64-encoded.');
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
