<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Console\CredentialsMigrateCommand;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class CredentialsMigrateCommandTest extends TestCase
{
    public function test_binary_registers_explicit_credential_migration(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../bin/s3-server', 'credentials:migrate', '--help']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('Upgrade the database credential schema', $process->getOutput());
    }

    public function test_serving_and_management_factory_never_bootstrap_sqlite_credentials(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-migrate-' . bin2hex(random_bytes(8)) . '.sqlite';
        $dsn = 'sqlite:' . $path;

        try {
            try {
                CredentialProviderFactory::create('database', ['dsn' => $dsn]);
                self::fail('Serving accepted a missing credential schema.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('credentials:migrate', $error->getMessage());
            }

            $pdo = new \PDO($dsn);
            $tables = $pdo->query("SELECT name FROM sqlite_master WHERE name = 's3_credentials'");
            self::assertNotFalse($tables);
            self::assertFalse($tables->fetchColumn());

            $migrator = new CommandTester(new CredentialsMigrateCommand());
            self::assertSame(Command::SUCCESS, $migrator->execute(['--credentials-dsn' => $dsn]));
            $writer = CredentialProviderFactory::create('database', ['dsn' => $dsn]);
            $writer->putCredential(new Credential('AKIAEXISTING1234567', 'existing-secret', 'owner'));
            $retry = new CommandTester(new CredentialsMigrateCommand());
            self::assertSame(Command::SUCCESS, $retry->execute(['--credentials-dsn' => $dsn]));
            self::assertSame('existing-secret', CredentialProviderFactory::create('database', ['dsn' => $dsn])
                ->getCredential('AKIAEXISTING1234567')?->secretAccessKey);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_legacy_credentials_require_explicit_upgrade_and_preserve_rows(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-legacy-' . bin2hex(random_bytes(8)) . '.sqlite';
        $dsn = 'sqlite:' . $path;
        try {
            $pdo = new \PDO($dsn);
            $pdo->exec("CREATE TABLE s3_credentials (access_key_id VARCHAR(255) PRIMARY KEY, secret_access_key TEXT NOT NULL, owner_id VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL DEFAULT '', is_active BOOLEAN NOT NULL DEFAULT TRUE, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id) VALUES ('legacy-key', 'legacy-secret', 'legacy-owner')");

            try {
                CredentialProviderFactory::create('database', ['dsn' => $dsn]);
                self::fail('Serving accepted a legacy credential schema.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('credentials:migrate', $error->getMessage());
            }
            self::assertSame(false, in_array('session_token', $this->sqliteColumns($pdo), true));

            $migrator = new CommandTester(new CredentialsMigrateCommand());
            self::assertSame(Command::SUCCESS, $migrator->execute(['--credentials-dsn' => $dsn]));
            self::assertSame('legacy-secret', CredentialProviderFactory::create('database', ['dsn' => $dsn])
                ->getCredential('legacy-key')?->secretAccessKey);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_migration_rejects_partial_core_schema_without_changing_existing_credential(): void
    {
        $path = sys_get_temp_dir() . '/s3-credential-partial-' . bin2hex(random_bytes(8)) . '.sqlite';
        $dsn = 'sqlite:' . $path;
        try {
            $pdo = new \PDO($dsn);
            $pdo->exec("CREATE TABLE s3_credentials (access_key_id VARCHAR(255) PRIMARY KEY, secret_access_key TEXT NOT NULL, owner_id VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL DEFAULT '', is_active BOOLEAN NOT NULL DEFAULT TRUE, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
            $pdo->exec("INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id) VALUES ('legacy-key', 'legacy-secret', 'legacy-owner')");
            $beforeRows = $pdo->query('SELECT access_key_id, secret_access_key, owner_id, is_active FROM s3_credentials');
            self::assertNotFalse($beforeRows);
            $before = $beforeRows->fetchAll(\PDO::FETCH_ASSOC);

            $migrator = new CommandTester(new CredentialsMigrateCommand());
            self::assertSame(Command::FAILURE, $migrator->execute(['--credentials-dsn' => $dsn]));
            self::assertStringContainsString('Credential migration failed', $migrator->getDisplay());
            self::assertNotContains('created_at', $this->sqliteColumns($pdo));

            try {
                CredentialProviderFactory::create('database', ['dsn' => $dsn]);
                self::fail('Serving accepted a credential table missing a core column.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('credentials:migrate', $error->getMessage());
            }

            $afterRows = $pdo->query('SELECT access_key_id, secret_access_key, owner_id, is_active FROM s3_credentials');
            self::assertNotFalse($afterRows);
            self::assertSame($before, $afterRows->fetchAll(\PDO::FETCH_ASSOC));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_migration_and_serving_reject_ambiguous_access_key_identity(): void
    {
        foreach (['VARCHAR(255) COLLATE NOCASE PRIMARY KEY', 'VARCHAR(255)'] as $identityColumn) {
            $path = sys_get_temp_dir() . '/s3-credential-identity-' . bin2hex(random_bytes(8)) . '.sqlite';
            $dsn = 'sqlite:' . $path;
            try {
                $pdo = new \PDO($dsn);
                $pdo->exec("CREATE TABLE s3_credentials (access_key_id {$identityColumn}, secret_access_key TEXT NOT NULL, owner_id VARCHAR(255) NOT NULL, display_name VARCHAR(255) NOT NULL DEFAULT '', is_active BOOLEAN NOT NULL DEFAULT TRUE, session_token TEXT NULL, expires_at TIMESTAMP NULL, policy_names TEXT NULL, allowed_prefixes TEXT NULL, created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP, updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP)");
                $pdo->exec("INSERT INTO s3_credentials (access_key_id, secret_access_key, owner_id) VALUES ('CASEKEY', 'legacy-secret', 'owner-a')");

                $migrator = new CommandTester(new CredentialsMigrateCommand());
                self::assertSame(Command::FAILURE, $migrator->execute(['--credentials-dsn' => $dsn]));
                try {
                    CredentialProviderFactory::create('database', ['dsn' => $dsn]);
                    self::fail('Serving accepted an ambiguous credential access key.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('credentials:migrate', $error->getMessage());
                }

                $rows = $pdo->query('SELECT access_key_id, secret_access_key, owner_id FROM s3_credentials');
                self::assertNotFalse($rows);
                self::assertSame([[
                    'access_key_id' => 'CASEKEY',
                    'secret_access_key' => 'legacy-secret',
                    'owner_id' => 'owner-a',
                ]], $rows->fetchAll(\PDO::FETCH_ASSOC));
            } finally {
                @unlink($path);
                @unlink($path . '-wal');
                @unlink($path . '-shm');
            }
        }
    }

    /** @return list<string> */
    private function sqliteColumns(\PDO $pdo): array
    {
        $statement = $pdo->query('PRAGMA table_info(s3_credentials)');
        self::assertNotFalse($statement);
        $rows = $statement->fetchAll(\PDO::FETCH_ASSOC);

        return array_values(array_map(static fn(array $row): string => (string) $row['name'], $rows));
    }
}
