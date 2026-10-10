<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Policy;

use PDO;
use PDOStatement;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class StoredIdentityPolicyInventoryTest extends TestCase
{
    public function test_database_failure_does_not_print_dsn_details(): void
    {
        $missingPath = sys_get_temp_dir() . '/s3-policy-audit-canary-' . bin2hex(random_bytes(8)) . '.sqlite';
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/audit-stored-identity-policies.php'],
            null,
            ['S3_POLICY_AUDIT_DSN' => 'sqlite:' . $missingPath],
        );
        $process->run();

        self::assertSame(1, $process->getExitCode());
        self::assertSame('', $process->getOutput());
        self::assertStringNotContainsString('canary', $process->getErrorOutput());
        self::assertFileDoesNotExist($missingPath);
    }

    public function test_inventory_pages_without_mutating_rows_and_reports_only_invalid_policy_identifiers(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-policy-audit-');
        self::assertIsString($path);

        try {
            $pdo = new PDO('sqlite:' . $path, options: [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
            $pdo->exec('CREATE TABLE s3_account_policies (owner_id TEXT PRIMARY KEY, policy_json TEXT NOT NULL)');
            $pdo->exec('CREATE TABLE s3_named_policies (policy_name TEXT PRIMARY KEY, policy_json TEXT NOT NULL)');

            $valid = json_encode(['Statement' => []], JSON_THROW_ON_ERROR);
            $insert = $pdo->prepare('INSERT INTO s3_account_policies (owner_id, policy_json) VALUES (?, ?)');
            for ($index = 0; $index < 1001; ++$index) {
                $insert->execute([sprintf('owner-%04d', $index), $valid]);
            }
            $invalid = '{"Statement":[],"secret-canary-policy-field":"value"}';
            $pdo->prepare('INSERT INTO s3_named_policies (policy_name, policy_json) VALUES (?, ?)')
                ->execute(['broken-policy', $invalid]);

            $process = new Process(
                [PHP_BINARY, dirname(__DIR__, 3) . '/scripts/audit-stored-identity-policies.php'],
                null,
                [
                    'S3_POLICY_AUDIT_DSN' => 'sqlite:' . $path,
                    'S3_POLICY_AUDIT_USER' => '',
                    'S3_POLICY_AUDIT_PASSWORD' => '',
                ],
            );
            $process->run();

            self::assertSame(2, $process->getExitCode(), $process->getErrorOutput());
            $record = json_decode(trim($process->getOutput()), true, 512, JSON_THROW_ON_ERROR);
            self::assertSame('named', $record['kind']);
            self::assertSame('broken-policy', $record['id']);
            self::assertSame('MalformedPolicy', $record['error']);
            self::assertStringNotContainsString($invalid, $process->getOutput());
            self::assertStringNotContainsString('secret-canary-policy-field', $process->getOutput());
            self::assertStringContainsString('Scanned 1002 identity policies; 1 invalid. No rows changed.', $process->getErrorOutput());
            $accountCount = $pdo->query('SELECT COUNT(*) FROM s3_account_policies');
            $namedPolicy = $pdo->query('SELECT policy_json FROM s3_named_policies');
            self::assertInstanceOf(PDOStatement::class, $accountCount);
            self::assertInstanceOf(PDOStatement::class, $namedPolicy);
            self::assertSame(1001, (int) $accountCount->fetchColumn());
            self::assertSame($invalid, $namedPolicy->fetchColumn());
        } finally {
            @unlink($path);
        }
    }
}
