<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;

/**
 * PDO-backed credential store with a bounded-staleness in-memory cache.
 *
 * Manages its own `s3_credentials` table. Positive lookups may be cached for
 * a short, configurable period. Expired entries are always re-read so
 * revocation and rotation performed by another server node become visible.
 */
final class DatabaseCredentialProvider implements AtomicCredentialCreator, AtomicCredentialDeleter, AtomicCredentialStatusUpdater, CredentialHealthProbe, CredentialProvider
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
        if (! is_finite($cacheTtlSeconds) || $cacheTtlSeconds < 0.0
            || $cacheTtlSeconds > (PHP_INT_MAX - hrtime(true)) / 1_000_000_000) {
            throw new \InvalidArgumentException('Credential cache TTL must be finite, non-negative, and representable by a monotonic timestamp.');
        }
        if ($maxCacheEntries < 1) {
            throw new \InvalidArgumentException('Credential cache entry limit must be >= 1.');
        }
        // MySQL TIMESTAMP converts through the connection time zone. Keep the
        // stored value and the UTC string used by PHP stable across nodes.
        if ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $this->pdo->exec("SET time_zone = '+00:00'");
        }
    }

    public static function fromDsn(
        string $dsn,
        ?string $username = null,
        ?string $password = null,
        float $cacheTtlSeconds = 1.0,
        bool $bootstrap = false,
    ): self {
        $pdo = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        $provider = new self($pdo, $cacheTtlSeconds);
        if ($bootstrap) {
            $provider->initialize();
        } else {
            $provider->assertSchemaReady();
        }

        return $provider;
    }

    /** Verify serving can read the complete credential schema without applying DDL. */
    public function assertSchemaReady(): void
    {
        try {
            $this->pdo->query(
                'SELECT access_key_id, secret_access_key, owner_id, display_name, is_active, '
                . 'session_token, expires_at, policy_names, allowed_prefixes, created_at, updated_at '
                . 'FROM s3_credentials WHERE 1 = 0',
            );
            $this->assertAccessKeyIdentity();
        } catch (\Throwable $error) {
            throw new \RuntimeException('Credential schema is not ready. Run credentials:migrate before starting API nodes.', 0, $error);
        }
    }

    /** A key must identify one row by its exact bytes on every supported PDO driver. */
    private function assertAccessKeyIdentity(): void
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        if ($driver === 'mysql') {
            $column = $this->pdo->query("SHOW COLUMNS FROM s3_credentials LIKE 'access_key_id'");
            $row = $column !== false ? $column->fetch(\PDO::FETCH_ASSOC) : false;
            $index = $this->pdo->query("SHOW INDEX FROM s3_credentials WHERE Key_name = 'PRIMARY'");
            $keys = $index !== false ? $index->fetchAll(\PDO::FETCH_ASSOC) : [];
            if (! is_array($row) || strtolower((string) ($row['Type'] ?? '')) !== 'varbinary(255)'
                || count($keys) !== 1 || ($keys[0]['Column_name'] ?? null) !== 'access_key_id'
                || (int) ($keys[0]['Non_unique'] ?? 1) !== 0
                || (int) ($keys[0]['Seq_in_index'] ?? 0) !== 1
                || ($keys[0]['Sub_part'] ?? null) !== null) {
                throw new \RuntimeException('Credential access key identity is not byte-exact and unique.');
            }

            return;
        }

        if ($driver === 'pgsql') {
            $stmt = $this->pdo->query(<<<'SQL'
                SELECT a.attname, a.atttypid IN ('pg_catalog.varchar'::regtype, 'pg_catalog.text'::regtype) AS supported_type,
                       c.collisdeterministic, i.indisunique, i.indnkeyatts
                FROM pg_index i
                JOIN pg_attribute a ON a.attrelid = i.indrelid AND a.attnum = i.indkey[0]
                LEFT JOIN pg_collation c ON c.oid = i.indcollation[0]
                WHERE i.indrelid = 's3_credentials'::regclass AND i.indisprimary
                SQL);
            $row = $stmt !== false ? $stmt->fetch(\PDO::FETCH_ASSOC) : false;
            if (! is_array($row) || ($row['attname'] ?? null) !== 'access_key_id'
                || ! self::databaseBoolean($row['supported_type'] ?? null)
                || ! self::databaseBoolean($row['collisdeterministic'] ?? null)
                || ! self::databaseBoolean($row['indisunique'] ?? null)
                || (int) ($row['indnkeyatts'] ?? 0) !== 1) {
                throw new \RuntimeException('Credential access key identity is not byte-exact and unique.');
            }

            return;
        }

        if ($driver === 'sqlite') {
            $columns = $this->pdo->query('PRAGMA table_info(s3_credentials)');
            $rows = $columns !== false ? $columns->fetchAll(\PDO::FETCH_ASSOC) : [];
            $primary = array_values(array_filter($rows, static fn(array $row): bool => (int) $row['pk'] > 0));
            if (count($primary) !== 1 || ($primary[0]['name'] ?? null) !== 'access_key_id') {
                throw new \RuntimeException('Credential access key identity is not unique.');
            }
            $indexes = $this->pdo->query('PRAGMA index_list(s3_credentials)');
            $indexRows = $indexes !== false ? $indexes->fetchAll(\PDO::FETCH_ASSOC) : [];
            foreach ($indexRows as $indexRow) {
                if (($indexRow['origin'] ?? null) !== 'pk') {
                    continue;
                }
                $index = $this->pdo->prepare('SELECT name, coll, "key" FROM pragma_index_xinfo(?)');
                $index->execute([$indexRow['name']]);
                $parts = array_values(array_filter(
                    $index->fetchAll(\PDO::FETCH_ASSOC),
                    static fn(array $part): bool => (int) $part['key'] === 1,
                ));
                if (count($parts) === 1 && ($parts[0]['name'] ?? null) === 'access_key_id'
                    && ($parts[0]['coll'] ?? null) === 'BINARY') {
                    return;
                }
            }
            throw new \RuntimeException('Credential access key identity is not byte-exact.');
        }

        throw new \RuntimeException('Unsupported credential database driver.');
    }

    public function initialize(): void
    {
        // MySQL's default VARCHAR collation commonly ignores case. S3 access
        // keys are opaque identifiers, so the primary key must compare bytes.
        $mysql = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME) === 'mysql';
        $accessKeyType = $mysql ? 'VARBINARY(255)' : 'VARCHAR(255)';
        $this->pdo->exec(
            <<<SQL
            CREATE TABLE IF NOT EXISTS s3_credentials (
                access_key_id {$accessKeyType} PRIMARY KEY,
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
        if ($mysql) {
            $this->ensureMysqlBinaryAccessKey();
        }

        // CREATE TABLE IF NOT EXISTS preserves an existing table, including
        // unsupported partial schemas. Never report a successful migration
        // unless serving can read every required column.
        $this->assertSchemaReady();
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

    public function assertCredentialStoreAvailable(): void
    {
        // Exercise every column needed by authentication without inventing a
        // reserved access key or loading credential data.
        $statement = $this->pdo->query(
            'SELECT access_key_id, secret_access_key, owner_id, display_name, is_active, '
            . 'session_token, expires_at, policy_names, allowed_prefixes '
            . 'FROM s3_credentials WHERE 1 = 0',
        );
        if ($statement === false) {
            throw new \RuntimeException('Credential store readiness query failed.');
        }
        $statement->closeCursor();
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
            $credential->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            json_encode($credential->policyNames, JSON_THROW_ON_ERROR),
            json_encode($credential->allowedPrefixes, JSON_THROW_ON_ERROR),
        ]);

        $this->cacheCredential($credential);
    }

    public function createCredentialIfAbsent(Credential $credential): bool
    {
        $driver = $this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME);
        $sql = 'INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id, display_name, is_active, session_token, expires_at, policy_names, allowed_prefixes) '
            . 'VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)';
        if ($driver !== 'mysql') {
            $sql .= ' ON CONFLICT(access_key_id) DO NOTHING';
        }

        $stmt = $this->pdo->prepare($sql);
        try {
            $stmt->execute([
                $credential->accessKeyId,
                $credential->secretAccessKey,
                $credential->ownerId,
                $credential->displayName,
                $credential->isActive ? 1 : 0,
                $credential->sessionToken,
                $credential->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
                json_encode($credential->policyNames, JSON_THROW_ON_ERROR),
                json_encode($credential->allowedPrefixes, JSON_THROW_ON_ERROR),
            ]);
        } catch (\PDOException $error) {
            if ($driver !== 'mysql' || (int) ($error->errorInfo[1] ?? 0) !== 1062) {
                throw $error;
            }
            $existing = $this->pdo->prepare('SELECT 1 FROM s3_credentials WHERE access_key_id = ?');
            $existing->execute([$credential->accessKeyId]);
            if ($existing->fetchColumn() === false) {
                throw $error;
            }

            return false;
        }

        if ($stmt->rowCount() === 0) {
            return false;
        }

        $this->cacheCredential($credential);

        return true;
    }

    public function deleteCredential(string $accessKeyId): void
    {
        $stmt = $this->pdo->prepare('DELETE FROM s3_credentials WHERE access_key_id = ?');
        $stmt->execute([$accessKeyId]);

        unset($this->cache[$accessKeyId], $this->cacheExpiresAt[$accessKeyId]);
    }

    public function deleteCredentialIfCurrent(Credential $expected): bool
    {
        $snapshotComparison = $this->snapshotComparison();
        $stmt = $this->pdo->prepare("DELETE FROM s3_credentials WHERE access_key_id = ? AND {$snapshotComparison} AND is_active = ?");
        $stmt->execute(self::snapshotParameters($expected));
        unset($this->cache[$expected->accessKeyId], $this->cacheExpiresAt[$expected->accessKeyId]);

        return $stmt->rowCount() > 0;
    }

    public function setActiveIfCurrent(Credential $expected, bool $active): bool
    {
        $snapshotComparison = $this->snapshotComparison();
        $stmt = $this->pdo->prepare(
            "UPDATE s3_credentials SET is_active = ?, updated_at = CURRENT_TIMESTAMP WHERE access_key_id = ? AND {$snapshotComparison} AND is_active = ?",
        );
        $stmt->execute([$active ? 1 : 0, ...self::snapshotParameters($expected)]);
        unset($this->cache[$expected->accessKeyId], $this->cacheExpiresAt[$expected->accessKeyId]);
        if ($stmt->rowCount() > 0) {
            return true;
        }

        // MySQL may count only changed rows. Re-run the same full database
        // predicate so an already-set status succeeds without weakening the
        // comparison or depending on timestamp hydration precision.
        if ($expected->isActive !== $active) {
            return false;
        }
        $stmt = $this->pdo->prepare(
            "SELECT 1 FROM s3_credentials WHERE access_key_id = ? AND {$snapshotComparison} AND is_active = ?",
        );
        $stmt->execute(self::snapshotParameters($expected));

        return $stmt->fetchColumn() !== false;
    }

    private function snapshotComparison(): string
    {
        return match ($this->pdo->getAttribute(\PDO::ATTR_DRIVER_NAME)) {
            'mysql' => 'BINARY secret_access_key = BINARY ? AND BINARY owner_id = BINARY ? '
                . 'AND BINARY display_name = BINARY ? AND BINARY session_token <=> BINARY ? '
                . 'AND expires_at <=> ? AND BINARY COALESCE(policy_names, \'[]\') = BINARY ? '
                . 'AND BINARY COALESCE(allowed_prefixes, \'[]\') = BINARY ?',
            'pgsql' => 'secret_access_key COLLATE "C" = ? AND owner_id COLLATE "C" = ? '
                . 'AND display_name COLLATE "C" = ? AND session_token COLLATE "C" IS NOT DISTINCT FROM ? '
                . 'AND expires_at IS NOT DISTINCT FROM ? AND COALESCE(policy_names, \'[]\') COLLATE "C" = ? '
                . 'AND COALESCE(allowed_prefixes, \'[]\') COLLATE "C" = ?',
            default => 'secret_access_key COLLATE BINARY = ? AND owner_id COLLATE BINARY = ? '
                . 'AND display_name COLLATE BINARY = ? AND session_token COLLATE BINARY IS ? '
                . 'AND expires_at IS ? AND COALESCE(policy_names, \'[]\') COLLATE BINARY = ? '
                . 'AND COALESCE(allowed_prefixes, \'[]\') COLLATE BINARY = ?',
        };
    }

    /** @return array{string, string, string, string, ?string, ?string, string, string, int} */
    private static function snapshotParameters(Credential $credential): array
    {
        return [
            $credential->accessKeyId,
            $credential->secretAccessKey,
            $credential->ownerId,
            $credential->displayName,
            $credential->sessionToken,
            $credential->expiresAt?->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d H:i:s'),
            json_encode($credential->policyNames, JSON_THROW_ON_ERROR),
            json_encode($credential->allowedPrefixes, JSON_THROW_ON_ERROR),
            $credential->isActive ? 1 : 0,
        ];
    }

    private function addColumnIfMissing(string $column, string $definition): void
    {
        if ($this->hasColumn($column)) {
            return;
        }

        try {
            $this->pdo->exec("ALTER TABLE s3_credentials ADD COLUMN {$column} {$definition}");
        } catch (\PDOException $error) {
            // Two nodes can initialize an older credential table at once.
            // A duplicate-column error is harmless once the winning DDL is
            // visible, while every other failure must still abort startup.
            if (! $this->hasColumn($column)) {
                throw $error;
            }
        }
    }

    private function ensureMysqlBinaryAccessKey(): void
    {
        $stmt = $this->pdo->query("SHOW COLUMNS FROM s3_credentials LIKE 'access_key_id'");
        if ($stmt === false) {
            throw new \RuntimeException('Could not inspect credential access_key_id column.');
        }
        $row = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (! is_array($row) || ! isset($row['Type'])) {
            throw new \RuntimeException('Credential access_key_id column is missing.');
        }
        if (strtolower((string) $row['Type']) === 'varbinary(255)') {
            return;
        }

        // This upgrades tables created by older package versions. A failed
        // conversion aborts initialization instead of serving ambiguous keys.
        $this->pdo->exec('ALTER TABLE s3_credentials MODIFY access_key_id VARBINARY(255) NOT NULL');
    }

    /** @phpstan-impure */
    private function hasColumn(string $column): bool
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
                "SELECT column_name FROM information_schema.columns WHERE table_schema = current_schema() AND table_name = 's3_credentials'",
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

        return in_array($column, $columns, true);
    }

    /**
     * @return list<string>
     */
    private static function jsonStringList(mixed $value, string $column): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_string($value)) {
            throw new \UnexpectedValueException("Credential {$column} must contain a JSON string list.");
        }

        try {
            $decoded = json_decode($value, false, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \UnexpectedValueException("Credential {$column} contains invalid JSON.", 0, $error);
        }
        if (! is_array($decoded)) {
            throw new \UnexpectedValueException("Credential {$column} must contain a JSON string list.");
        }
        foreach ($decoded as $item) {
            if (! is_string($item) || $item === '') {
                throw new \UnexpectedValueException("Credential {$column} must contain only non-empty strings.");
            }
        }

        return array_values($decoded);
    }

    /**
     * @param  array<string, mixed>  $row
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
            expiresAt: $row['expires_at'] !== null
                ? new \DateTimeImmutable((string) $row['expires_at'], new \DateTimeZone('UTC'))
                : null,
            policyNames: self::jsonStringList($row['policy_names'] ?? null, 'policy_names'),
            allowedPrefixes: self::jsonStringList($row['allowed_prefixes'] ?? null, 'allowed_prefixes'),
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
