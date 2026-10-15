<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use Composer\InstalledVersions;
use OpsFour\S3Server\Console\MetadataMigrateCommand;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class MetadataMigrateCommandTest extends TestCase
{
    public function test_composer_manifest_exports_the_documented_binary(): void
    {
        $manifest = json_decode(
            (string) file_get_contents(__DIR__ . '/../../../composer.json'),
            true,
            16,
            JSON_THROW_ON_ERROR,
        );

        self::assertSame(['bin/s3-server'], $manifest['bin'] ?? null);
        foreach (['ext-dom', 'ext-libxml', 'ext-openssl', 'ext-simplexml'] as $extension) {
            self::assertSame('*', $manifest['require'][$extension] ?? null, $extension);
        }
    }

    public function test_binary_registers_migration_command_help_without_initializing_metadata(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../bin/s3-server', 'metadata:migrate', '--help']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('Upgrade the metadata schema without starting an API node', $process->getOutput());
        self::assertStringContainsString('--metadata-dsn', $process->getOutput());
    }

    public function test_binary_reports_the_composer_package_version(): void
    {
        $expected = InstalledVersions::getPrettyVersion('opsfour/s3-server');
        self::assertNotNull($expected);
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../bin/s3-server', '--version']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString($expected, $process->getOutput());
        self::assertStringNotContainsString('1.0.0', $process->getOutput());
    }

    public function test_binary_finds_the_project_autoloader_as_an_installed_dependency(): void
    {
        $root = sys_get_temp_dir() . '/s3-installed-binary-' . bin2hex(random_bytes(8));
        $packageBin = $root . '/vendor/opsfour/s3-server/bin';
        self::assertTrue(mkdir($packageBin, 0700, true));
        self::assertTrue(copy(__DIR__ . '/../../../bin/s3-server', $packageBin . '/s3-server'));
        $packageAutoload = realpath(__DIR__ . '/../../../vendor/autoload.php');
        self::assertNotFalse($packageAutoload);
        self::assertNotFalse(file_put_contents(
            $root . '/vendor/autoload.php',
            '<?php require ' . var_export($packageAutoload, true) . ';',
        ));

        try {
            $process = new Process([
                PHP_BINARY,
                $packageBin . '/s3-server',
                'metadata:migrate',
                '--help',
            ]);
            $process->run();

            self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
            self::assertStringContainsString(
                'Upgrade the metadata schema without starting an API node',
                $process->getOutput(),
            );
        } finally {
            @unlink($root . '/vendor/autoload.php');
            @unlink($packageBin . '/s3-server');
            @rmdir($packageBin);
            @rmdir($root . '/vendor/opsfour/s3-server');
            @rmdir($root . '/vendor/opsfour');
            @rmdir($root . '/vendor');
            @rmdir($root);
        }
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
