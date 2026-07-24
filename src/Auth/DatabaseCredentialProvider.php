<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;

/**
 * PDO-backed credential store with a bounded-staleness in-memory cache.
 *
 * Manages its own `s3_credentials` table. Positive lookups may be cached for
 * a short, configurable period. Expired entries are always re-read so
 * revocation and rotation performed by another server node become visible.
 */
final class DatabaseCredentialProvider implements CredentialProvider
{
    private const int DEFAULT_MAX_CACHE_ENTRIES = 10_000;

    /** @var array<string, Credential> In-memory cache keyed by accessKeyId. */
    private array $cache = [];

    /** @var array<string, int> Monotonic expiry timestamps in nanoseconds. */
    private array $cacheExpiresAt = [];

    public function __construct(
        private readonly \PDO $pdo,
        private readonly float $cacheTtlSeconds = 1.0,
        private readonly int $maxCacheEntries = self::DEFAULT_MAX_CACHE_ENTRIES,
    ) {
        if ($cacheTtlSeconds < 0.0) {
            throw new \InvalidArgumentException('Credential cache TTL must be >= 0 seconds.');
        }
        if ($maxCacheEntries < 1) {
            throw new \InvalidArgumentException('Credential cache entry limit must be >= 1.');
        }
    }

    public static function fromDsn(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        float $cacheTtlSeconds = 1.0,
    ): self {
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $provider = new self($pdo, $cacheTtlSeconds);
        $provider->initialize();

        return $provider;
    }

    public function initialize(): void
    {
        $this->pdo->exec(
            <<<'SQL'
            CREATE TABLE IF NOT EXISTS s3_credentials (
                access_key_id VARCHAR(255) PRIMARY KEY,
                secret_access_key TEXT NOT NULL,
                owner_id VARCHAR(255) NOT NULL,
                display_name VARCHAR(255) NOT NULL DEFAULT '',
                is_active BOOLEAN NOT NULL DEFAULT TRUE,
                session_token TEXT NULL,
                expires_at TIMESTAMP NULL,
                policy_names TEXT NULL,
                allowed_prefixes TEXT NULL,
                created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP
            )
            SQL,
        );

        $this->addColumnIfMissing('session_token', 'TEXT NULL');
        $this->addColumnIfMissing('expires_at', 'TIMESTAMP NULL');
        $this->addColumnIfMissing('policy_names', 'TEXT NULL');
        $this->addColumnIfMissing('allowed_prefixes', 'TEXT NULL');
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        if (
            isset($this->cache[$accessKeyId], $this->cacheExpiresAt[$accessKeyId])
            && $this->cacheExpiresAt[$accessKeyId] > hrtime(true)
        ) {
            return $this->cache[$accessKeyId];
        }
        unset($this->cache[$accessKeyId], $this->cacheExpiresAt[$accessKeyId]);

        $stmt = $this->pdo->prepare(
            'SELECT access_key_id, secret_access_key, owner_id, display_name, is_active, session_token, expires_at, policy_names, allowed_prefixes FROM s3_credentials WHERE access_key_id = ?',
        );
        $stmt->execute([$accessKeyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $credential = self::credentialFromRow($row);
        $this->cacheCredential($credential);

        return $credential;
    }

    public function listCredentials(): array
    {
        $stmt = $this->pdo->query(
            'SELECT access_key_id, secret_access_key, owner_id, display_name, is_active, session_token, expires_at, policy_names, allowed_prefixes FROM s3_credentials ORDER BY access_key_id ASC',
        );

        if ($stmt === false) {
            return [];
        }

        $credentials = [];

        while ($row = $stmt->fetch(\PDO::FETCH_ASSOC)) {
            $credential = self::credentialFromRow($row);
            $credentials[] = $credential;
            $this->cacheCredential($credential);
        }

        return $credentials;
    }

    public function putCredential(Credential $credential): void
    {
        // Detect database driver for upsert syntax.
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            $sql = <<<'SQL'
                INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id, display_name, is_active, session_token, expires_at, policy_names, allowed_prefixes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    secret_access_key = VALUES(secret_access_key),
                    owner_id = VALUES(owner_id),
                    display_name = VALUES(display_name),
                    is_active = VALUES(is_active),
                    session_token = VALUES(session_token),
                    expires_at = VALUES(expires_at),
                    policy_names = VALUES(policy_names),
                    allowed_prefixes = VALUES(allowed_prefixes),
                    updated_at = CURRENT_TIMESTAMP
                SQL;
        } else {
            $sql = <<<'SQL'
                INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id, display_name, is_active, session_token, expires_at, policy_names, allowed_prefixes)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ON CONFLICT(access_key_id) DO UPDATE SET
                    secret_access_key = excluded.secret_access_key,
                    owner_id = excluded.owner_id,
                    display_name = excluded.display_name,
                    is_active = excluded.is_active,
                    session_token = excluded.session_token,
                    expires_at = excluded.expires_at,
                    policy_names = excluded.policy_names,
                    allowed_prefixes = excluded.allowed_prefixes,
                    updated_at = CURRENT_TIMESTAMP
                SQL;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            $credential->accessKeyId,
            $credential->secretAccessKey,
            $credential->ownerId,
            $credential->displayName,
            $credential->isActive ? 1 : 0,
            $credential->sessionToken,
            $credential->expiresAt?->format('Y-m-d H:i:s'),
            json_encode($credential->policyNames, JSON_THROW_ON_ERROR),
            json_encode($credential->allowedPrefixes, JSON_THROW_ON_ERROR),
        ]);

        $this->cacheCredential($credential);
    }

    public function deleteCredential(string $accessKeyId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM s3_credentials WHERE access_key_id = ?');
        $stmt->execute([$accessKeyId]);

        unset($this->cache[$accessKeyId], $this->cacheExpiresAt[$accessKeyId]);
    }

    private function addColumnIfMissing(string $column, string $definition): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $columns = [];

        if ($driver === 'mysql') {
            $stmt = $this->pdo->query('SHOW COLUMNS FROM s3_credentials');
            while ($stmt !== false && ($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $columns[] = (string) $row['Field'];
            }
        } elseif ($driver === 'pgsql') {
            $stmt = $this->pdo->query(
                "SELECT column_name FROM information_schema.columns WHERE table_name = 's3_credentials'",
            );
            while ($stmt !== false && ($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $columns[] = (string) $row['column_name'];
            }
        } else {
            $stmt = $this->pdo->query('PRAGMA table_info(s3_credentials)');
            while ($stmt !== false && ($row = $stmt->fetch(\PDO::FETCH_ASSOC))) {
                $columns[] = (string) $row['name'];
            }
        }

        if (!in_array($column, $columns, true)) {
            $this->pdo->exec("ALTER TABLE s3_credentials ADD COLUMN {$column} {$definition}");
        }
    }

    /**
     * @return list<string>
     */
    private static function jsonStringList(mixed $value): array
    {
        if (!is_string($value) || $value === '') {
            return [];
        }

        $decoded = json_decode($value, true);
        if (!is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, is_string(...)));
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function credentialFromRow(array $row): Credential
    {
        return new Credential(
            accessKeyId: (string) $row['access_key_id'],
            secretAccessKey: (string) $row['secret_access_key'],
            ownerId: (string) $row['owner_id'],
            displayName: (string) $row['display_name'],
            isActive: self::databaseBoolean($row['is_active']),
            sessionToken: $row['session_token'] !== null ? (string) $row['session_token'] : null,
            expiresAt: $row['expires_at'] !== null ? new \DateTimeImmutable((string) $row['expires_at']) : null,
            policyNames: self::jsonStringList($row['policy_names'] ?? null),
            allowedPrefixes: self::jsonStringList($row['allowed_prefixes'] ?? null),
        );
    }

    private static function databaseBoolean(mixed $value): bool
    {
        if (is_bool($value)) {
            return $value;
        }
        if (is_int($value)) {
            return $value !== 0;
        }
        if (is_string($value)) {
            return match (strtolower(trim($value))) {
                '1', 't', 'true', 'yes', 'on' => true,
                '0', 'f', 'false', 'no', 'off', '' => false,
                default => throw new \UnexpectedValueException('Credential is_active contains an invalid boolean value.'),
            };
        }

        throw new \UnexpectedValueException('Credential is_active contains an invalid boolean value.');
    }

    private function cacheCredential(Credential $credential): void
    {
        if ($this->cacheTtlSeconds <= 0.0) {
            return;
        }

        if (! isset($this->cache[$credential->accessKeyId]) && count($this->cache) >= $this->maxCacheEntries) {
            $this->evictExpiredCacheEntries(hrtime(true));
            if (count($this->cache) >= $this->maxCacheEntries) {
                $oldest = array_key_first($this->cache);
                if ($oldest !== null) {
                    unset($this->cache[$oldest], $this->cacheExpiresAt[$oldest]);
                }
            }
        }

        $this->cache[$credential->accessKeyId] = $credential;
        $this->cacheExpiresAt[$credential->accessKeyId] = hrtime(true)
            + (int) ceil($this->cacheTtlSeconds * 1_000_000_000);
    }

    private function evictExpiredCacheEntries(int $now): void
    {
        foreach ($this->cacheExpiresAt as $accessKeyId => $expiresAt) {
            if ($expiresAt <= $now) {
                unset($this->cache[$accessKeyId], $this->cacheExpiresAt[$accessKeyId]);
            }
        }
    }
}
