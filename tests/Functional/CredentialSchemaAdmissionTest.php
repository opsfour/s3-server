<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Console\CredentialsMigrateCommand;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class CredentialSchemaAdmissionTest extends TestCase
{
    public function test_external_serving_waits_for_explicit_fresh_and_legacy_credential_migration(): void
    {
        $driver = getenv('S3_TEST_CREDENTIAL_SCHEMA_DRIVER');
        $dsn = getenv('S3_TEST_CREDENTIAL_SCHEMA_DSN');
        if (! in_array($driver, ['pgsql', 'mysql'], true)
            || ! is_string($dsn)
            || preg_match('/(?:^|;)dbname=s3audit_credentials_migration(?:;|$)/i', $dsn) !== 1
            || getenv('S3_TEST_DESTRUCTIVE_CREDENTIAL_MIGRATIONS') !== '1') {
            self::markTestSkipped('Requires a dedicated disposable s3audit_credentials_migration database and destructive opt-in.');
        }

        $username = getenv('S3_TEST_CREDENTIAL_SCHEMA_USERNAME') ?: null;
        $password = getenv('S3_TEST_CREDENTIAL_SCHEMA_PASSWORD') ?: null;
        $pdo = new \PDO($dsn, $username, $password, [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
        self::assertSame($driver, $pdo->getAttribute(\PDO::ATTR_DRIVER_NAME));
        $config = ['dsn' => $dsn, 'username' => $username, 'password' => $password];
        $migrator = new CommandTester(new CredentialsMigrateCommand());
        $options = [
            '--credentials-dsn' => $dsn,
            '--credentials-username' => $username,
            '--credentials-password' => $password,
        ];

        try {
            $pdo->exec('DROP TABLE IF EXISTS s3_credentials');
            $this->assertRejectedWithoutDdl($config);
            self::assertSame(Command::SUCCESS, $migrator->execute($options), $migrator->getDisplay());
            $provider = CredentialProviderFactory::create('database', $config);
            $provider->putCredential(new Credential('AKIAFRESH123456789', 'fresh-secret', 'owner'));
            self::assertSame('fresh-secret', CredentialProviderFactory::create('database', $config)
                ->getCredential('AKIAFRESH123456789')?->secretAccessKey);
            $this->assertDmlOnlyServing($pdo, $driver, $dsn);

            $pdo->exec('DROP TABLE s3_credentials');
            $pdo->exec(
                "CREATE TABLE s3_credentials (access_key_id VARCHAR(255) PRIMARY KEY, secret_access_key TEXT NOT NULL, "
                . "owner_id VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL DEFAULT '', "
                . 'is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, '
                . 'updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)',
            );
            $pdo->exec("INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id) VALUES ('legacy-key', 'legacy-secret', 'legacy-owner')");
            $this->assertRejectedWithoutDdl($config);
            self::assertSame(Command::SUCCESS, $migrator->execute($options), $migrator->getDisplay());
            self::assertSame('legacy-secret', CredentialProviderFactory::create('database', $config)
                ->getCredential('legacy-key')?->secretAccessKey);
            if ($driver === 'mysql') {
                $column = $pdo->query("SHOW COLUMNS FROM s3_credentials LIKE 'access_key_id'");
                self::assertNotFalse($column);
                self::assertSame('varbinary(255)', strtolower((string) $column->fetch(\PDO::FETCH_ASSOC)['Type']));
            }
        } finally {
            $pdo->exec('DROP TABLE IF EXISTS s3_credentials');
        }
    }

    /** @param array{dsn: string, username: string|null, password: string|null} $config */
    private function assertRejectedWithoutDdl(array $config): void
    {
        try {
            CredentialProviderFactory::create('database', $config);
            self::fail('Serving accepted a missing or legacy credential schema.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('credentials:migrate', $error->getMessage());
        }
    }

    private function assertDmlOnlyServing(\PDO $admin, string $driver, string $dsn): void
    {
        $user = 's3audit_credential_dml';
        if ($driver === 'pgsql') {
            $admin->exec("DROP ROLE IF EXISTS {$user}");
            $admin->exec("CREATE ROLE {$user} LOGIN PASSWORD 's3audit'");
            $admin->exec("GRANT CONNECT ON DATABASE s3audit_credentials_migration TO {$user}");
            $admin->exec("GRANT USAGE ON SCHEMA public TO {$user}");
            $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON s3_credentials TO {$user}");
        } else {
            $admin->exec("DROP USER IF EXISTS '{$user}'@'%'");
            $admin->exec("CREATE USER '{$user}'@'%' IDENTIFIED BY 's3audit'");
            $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON s3audit_credentials_migration.s3_credentials TO '{$user}'@'%'");
        }

        try {
            $dml = new \PDO($dsn, $user, 's3audit', [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION]);
            try {
                $dml->exec('CREATE TABLE s3audit_credential_ddl_forbidden (id INT)');
                self::fail('The serving role unexpectedly has DDL rights.');
            } catch (\PDOException) {
                // The role is intentionally unable to change the schema.
            }
            $provider = CredentialProviderFactory::create('database', [
                'dsn' => $dsn,
                'username' => $user,
                'password' => 's3audit',
            ]);
            $provider->putCredential(new Credential('AKIADMLONLY12345678', 'dml-secret', 'dml-owner'));
            self::assertSame('dml-secret', $provider->getCredential('AKIADMLONLY12345678')?->secretAccessKey);
            $provider->deleteCredential('AKIADMLONLY12345678');
            self::assertNull($provider->getCredential('AKIADMLONLY12345678'));
        } finally {
            $dml = null;
            if ($driver === 'pgsql') {
                $admin->exec("REVOKE ALL PRIVILEGES ON s3_credentials FROM {$user}");
                $admin->exec("REVOKE USAGE ON SCHEMA public FROM {$user}");
                $admin->exec("REVOKE CONNECT ON DATABASE s3audit_credentials_migration FROM {$user}");
                $admin->exec("DROP ROLE {$user}");
            } else {
                $admin->exec("DROP USER '{$user}'@'%'");
            }
        }
    }
}
