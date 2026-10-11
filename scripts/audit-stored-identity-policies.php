<?php

declare(strict_types=1);

require __DIR__ . '/../vendor/autoload.php';

use OpsFour\S3Server\Exception\MalformedPolicyException;
use OpsFour\S3Server\Policy\PolicyDocumentValidator;

/**
 * Read-only, bounded inventory of existing account and named IAM policies.
 * Use a SELECT-only database identity and an isolated snapshot or replica.
 */
function auditStoredIdentityPolicies(): int
{
    $dsn = getenv('S3_POLICY_AUDIT_DSN');
    if ($dsn === false || $dsn === '') {
        fwrite(STDERR, "Set S3_POLICY_AUDIT_DSN to a SQLite, PostgreSQL, or MySQL PDO DSN.\n");

        return 1;
    }

    try {
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, strlen('sqlite:'));
            if (! str_starts_with($path, '/') || ! is_file($path)) {
                throw new RuntimeException('SQLite inventory requires an existing absolute database path.');
            }
            $dsn = 'sqlite:file:' . str_replace('%2F', '/', rawurlencode($path)) . '?mode=ro';
        }

        $user = getenv('S3_POLICY_AUDIT_USER');
        $password = getenv('S3_POLICY_AUDIT_PASSWORD');
        $pdo = new PDO(
            $dsn,
            $user === false ? null : $user,
            $password === false ? null : $password,
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION],
        );
        $driver = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if (! in_array($driver, ['sqlite', 'pgsql', 'mysql'], true)) {
            throw new RuntimeException('Unsupported database driver.');
        }

        // This protects against accidental writes even if the supplied identity
        // has broader privileges. SELECT-only credentials remain required.
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA query_only = ON');
            $pdo->beginTransaction();
        } elseif ($driver === 'pgsql') {
            $pdo->exec('BEGIN ISOLATION LEVEL REPEATABLE READ READ ONLY');
        } else {
            $pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $pdo->exec('START TRANSACTION READ ONLY, WITH CONSISTENT SNAPSHOT');
        }

        $scanned = 0;
        $invalid = 0;
        foreach (['account' => ['s3_account_policies', 'owner_id'], 'named' => ['s3_named_policies', 'policy_name']] as $kind => [$table, $identifier]) {
            $after = null;
            do {
                if ($after === null) {
                    $query = "SELECT {$identifier} AS policy_id, policy_json FROM {$table} ORDER BY {$identifier} LIMIT 1000";
                    $parameters = [];
                } else {
                    $query = "SELECT {$identifier} AS policy_id, policy_json FROM {$table} WHERE {$identifier} > ? ORDER BY {$identifier} LIMIT 1000";
                    $parameters = [$after];
                }
                $statement = $pdo->prepare($query);
                $statement->execute($parameters);
                $pageSize = 0;

                while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
                    ++$pageSize;
                    $after = (string) $row['policy_id'];
                    ++$scanned;
                    try {
                        PolicyDocumentValidator::validateIdentityPolicy((string) $row['policy_json']);
                    } catch (MalformedPolicyException) {
                        ++$invalid;
                        $record = json_encode([
                            'kind' => $kind,
                            'id' => $after,
                            'error' => 'MalformedPolicy',
                        ], JSON_THROW_ON_ERROR | JSON_INVALID_UTF8_SUBSTITUTE);
                        fwrite(STDOUT, $record . "\n");
                    }
                }
                $statement->closeCursor();
            } while ($pageSize === 1000);
        }

        fwrite(STDERR, "Scanned {$scanned} identity policies; {$invalid} invalid. No rows changed.\n");

        return $invalid === 0 ? 0 : 2;
    } catch (Throwable) {
        // Connection errors can contain DSN details. Never print them here.
        fwrite(STDERR, "Policy inventory failed; check DSN, read-only privileges, and schema.\n");

        return 1;
    }
}

exit(auditStoredIdentityPolicies());
