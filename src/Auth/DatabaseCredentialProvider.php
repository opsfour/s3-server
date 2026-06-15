<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\CredentialProvider;

/**
 * PDO-backed credential store with in-memory cache.
 *
 * Manages its own `s3_credentials` table. The cache is populated on
 * first access and invalidated on writes. PDO is used (not amphp)
 * because credential lookups are cached and DB hits are infrequent.
 *
 * To avoid any blocking during request handling, call listCredentials()
 * at startup to preload all credentials into the in-memory cache.
 */
final class DatabaseCredentialProvider implements CredentialProvider
{
    /** @var array<string, Credential> In-memory cache keyed by accessKeyId. */
    private array $cache = [];

    public function __construct(
        private readonly \PDO $pdo,
    ) {}

    public static function fromDsn(string $dsn): self
    {
        $pdo = new \PDO($dsn, null, null, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $provider = new self($pdo);
        $provider->initialize();

        return $provider;
    }

    public function initialize(): void
    {
        $this->pdo->exec(<<<'SQL'
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
        if (isset($this->cache[$accessKeyId])) {
            return $this->cache[$accessKeyId];
        }

        $stmt = $this->pdo->prepare(
            'SELECT access_key_id, secret_access_key, owner_id, display_name, is_active, session_token, expires_at, policy_names, allowed_prefixes FROM s3_credentials WHERE access_key_id = ?',
        );
        $stmt->execute([$accessKeyId]);
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);

        if ($row === false) {
            return null;
        }

        $credential = new Credential(
            accessKeyId: $row['access_key_id'],
            secretAccessKey: $row['secret_access_key'],
            ownerId: $row['owner_id'],
            displayName: $row['display_name'],
            isActive: (bool) $row['is_active'],
            sessionToken: $row['session_token'] !== null ? (string) $row['session_token'] : null,
            expiresAt: $row['expires_at'] !== null ? new \DateTimeImmutable((string) $row['expires_at']) : null,
            policyNames: self::jsonStringList($row['policy_names'] ?? null),
            allowedPrefixes: self::jsonStringList($row['allowed_prefixes'] ?? null),
        );

        $this->cache[$accessKeyId] = $credential;

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
            $credential = new Credential(
                accessKeyId: $row['access_key_id'],
                secretAccessKey: $row['secret_access_key'],
                ownerId: $row['owner_id'],
                displayName: $row['display_name'],
                isActive: (bool) $row['is_active'],
                sessionToken: $row['session_token'] !== null ? (string) $row['session_token'] : null,
                expiresAt: $row['expires_at'] !== null ? new \DateTimeImmutable((string) $row['expires_at']) : null,
                policyNames: self::jsonStringList($row['policy_names'] ?? null),
                allowedPrefixes: self::jsonStringList($row['allowed_prefixes'] ?? null),
            );
            $credentials[] = $credential;
            $this->cache[$credential->accessKeyId] = $credential;
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
                    allowed_prefixes = VALUES(allowed_prefixes)
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
                    allowed_prefixes = excluded.allowed_prefixes
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

        $this->cache[$credential->accessKeyId] = $credential;
    }

    public function deleteCredential(string $accessKeyId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM s3_credentials WHERE access_key_id = ?');
        $stmt->execute([$accessKeyId]);

        unset($this->cache[$accessKeyId]);
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
}
