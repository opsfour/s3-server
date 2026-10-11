<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\MetadataMigrateCommand;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class MetadataMigrateCommandTest extends TestCase
{
    public function test_binary_registers_migration_command_help_without_initializing_metadata(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../bin/s3-server', 'metadata:migrate', '--help']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('Upgrade the metadata schema without starting an API node', $process->getOutput());
        self::assertStringContainsString('--metadata-dsn', $process->getOutput());
    }

    public function test_sqlite_migration_runs_without_starting_an_api_and_can_be_retried(): void
    {
        $path = sys_get_temp_dir() . '/s3-metadata-migrate-' . bin2hex(random_bytes(8)) . '.sqlite';

        try {
            $tester = new CommandTester(new MetadataMigrateCommand());
            self::assertSame(Command::SUCCESS, $tester->execute([
                '--metadata-driver' => 'sqlite',
                '--metadata-path' => $path,
            ]));
            $retry = new CommandTester(new MetadataMigrateCommand());
            self::assertSame(Command::SUCCESS, $retry->execute([
                '--metadata-driver' => 'sqlite',
                '--metadata-path' => $path,
            ]));
            self::assertSame(SqliteSchema::VERSION, (new SchemaManager(new \PDO('sqlite:' . $path)))->getCurrentVersion());
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_missing_connection_configuration_fails_without_exposing_dsn(): void
    {
        $tester = new CommandTester(new MetadataMigrateCommand());
        self::assertSame(Command::FAILURE, $tester->execute([
            '--metadata-driver' => 'postgres',
            '--metadata-dsn' => '',
        ]));
        self::assertStringContainsString('--metadata-dsn is required', $tester->getDisplay());
    }
}
