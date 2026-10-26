<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\CredentialsCommand;
use OpsFour\S3Server\Console\CredentialsMigrateCommand;
use OpsFour\S3Server\Console\MetadataMigrateCommand;
use OpsFour\S3Server\Console\MultipartPartIntentCommand;
use OpsFour\S3Server\Console\QuotaCommand;
use OpsFour\S3Server\Console\ServeCommand;
use OpsFour\S3Server\Console\StorageAuditCommand;
use OpsFour\S3Server\Console\StorageInventoryCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class AdminCliEnvironmentContractTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $this->originalEnvironment = [];

        parent::tearDown();
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, array{string, mixed}> $options
     */
    #[DataProvider('standaloneCommands')]
    public function test_explicit_zero_and_empty_environment_values_are_preserved(
        string $commandClass,
        array $options,
    ): void {
        foreach (['0', ''] as $value) {
            foreach (array_keys($options) as $name) {
                $this->setEnvironment($name, $value);
            }

            $command = new $commandClass();
            foreach ($options as $name => [$option]) {
                self::assertSame(
                    $value,
                    $command->getDefinition()->getOption($option)->getDefault(),
                    $commandClass . ' replaced explicit ' . var_export($value, true) . " from {$name}.",
                );
            }
        }
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, array{string, mixed}> $options
     */
    #[DataProvider('standaloneCommands')]
    public function test_each_environment_value_reaches_its_own_option(
        string $commandClass,
        array $options,
    ): void {
        foreach (array_keys($options) as $name) {
            $this->setEnvironment($name, 'sentinel-for-' . $name);
        }

        $command = new $commandClass();
        foreach ($options as $name => [$option]) {
            self::assertSame(
                'sentinel-for-' . $name,
                $command->getDefinition()->getOption($option)->getDefault(),
                $commandClass . " cross-wired the environment value for {$name}.",
            );
        }
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, array{string, mixed}> $options
     */
    #[DataProvider('standaloneCommands')]
    public function test_absent_environment_values_keep_existing_defaults(
        string $commandClass,
        array $options,
    ): void {
        foreach (array_keys($options) as $name) {
            $this->setEnvironment($name, null);
        }

        $command = new $commandClass();
        foreach ($options as $name => [$option, $default]) {
            self::assertSame(
                $default,
                $command->getDefinition()->getOption($option)->getDefault(),
                $commandClass . " changed the absent-variable default for {$name}.",
            );
        }
    }

    /** @return iterable<string, array{class-string<Command>, array<string, array{string, mixed}>}> */
    public static function standaloneCommands(): iterable
    {
        yield 'credentials management' => [CredentialsCommand::class, [
            'S3_CREDENTIALS_DRIVER' => ['credentials-driver', 'file'],
            'S3_CREDENTIALS_DSN' => ['credentials-dsn', null],
            'S3_CREDENTIALS_USERNAME' => ['credentials-username', null],
            'S3_CREDENTIALS_PASSWORD' => ['credentials-password', null],
            'S3_CREDENTIALS_PATH' => ['credentials-file', null],
        ]];
        yield 'credentials migration' => [CredentialsMigrateCommand::class, [
            'S3_CREDENTIALS_DSN' => ['credentials-dsn', null],
            'S3_CREDENTIALS_USERNAME' => ['credentials-username', null],
            'S3_CREDENTIALS_PASSWORD' => ['credentials-password', null],
        ]];
        yield 'metadata migration' => [MetadataMigrateCommand::class, self::metadataOptions()];
        yield 'multipart-part intent inventory' => [MultipartPartIntentCommand::class, self::metadataOptions()];
        yield 'quota management' => [QuotaCommand::class, self::metadataOptions()];
        yield 'storage audit' => [StorageAuditCommand::class, self::metadataOptions()];
        yield 'storage inventory' => [StorageInventoryCommand::class, self::metadataOptions()];
        yield 'server' => [ServeCommand::class, [
            'S3_STORAGE_DRIVER' => ['storage-driver', 'filesystem'],
            'S3_STORAGE_PATH' => ['storage-path', null],
            'S3_METADATA_DRIVER' => ['metadata-driver', 'sqlite'],
            'S3_METADATA_PATH' => ['metadata-path', null],
            'S3_CREDENTIALS_DSN' => ['credentials-dsn', null],
            'S3_CREDENTIALS_PASSWORD' => ['credentials-password', null],
            'S3_CREDENTIALS_PATH' => ['credentials-file', null],
        ]];
    }

    /** @return array<string, array{string, mixed}> */
    private static function metadataOptions(): array
    {
        return [
            'S3_METADATA_DRIVER' => ['metadata-driver', 'sqlite'],
            'S3_METADATA_DSN' => ['metadata-dsn', ''],
            'S3_METADATA_PATH' => ['metadata-path', null],
            'S3_STORAGE_PATH' => ['storage-path', null],
        ];
    }

    /**
     * @param class-string<Command> $commandClass
     * @param array<string, string> $arguments
     */
    #[DataProvider('metadataCommands')]
    public function test_explicit_empty_metadata_path_is_not_replaced_by_storage_derived_path(
        string $commandClass,
        array $arguments,
    ): void {
        $storagePath = sys_get_temp_dir() . '/s3-admin-env-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($storagePath, 0o700));
        $storagePath = (string) realpath($storagePath);

        $this->setEnvironment('S3_METADATA_DRIVER', 'sqlite');
        $this->setEnvironment('S3_METADATA_PATH', '');
        $this->setEnvironment('S3_STORAGE_PATH', $storagePath);
        $this->setEnvironment('S3_STORAGE_DRIVER', 'filesystem');

        try {
            $tester = new CommandTester(new $commandClass());
            self::assertSame(Command::FAILURE, $tester->execute($arguments), $tester->getDisplay());
            self::assertStringContainsString(
                '--metadata-path must not be empty when configured.',
                $tester->getDisplay(),
            );
            self::assertFileDoesNotExist($storagePath . '/metadata.sqlite');
            self::assertFileDoesNotExist($storagePath . '/.opsfour/metadata.sqlite');
        } finally {
            $this->recursiveDelete($storagePath);
        }
    }

    /** @return iterable<string, array{class-string<Command>, array<string, string>}> */
    public static function metadataCommands(): iterable
    {
        yield 'metadata migration' => [MetadataMigrateCommand::class, []];
        yield 'multipart-part intent inventory' => [MultipartPartIntentCommand::class, ['owner-id' => 'owner']];
        yield 'quota management' => [QuotaCommand::class, ['action' => 'list']];
        yield 'storage audit' => [StorageAuditCommand::class, []];
        yield 'storage inventory' => [StorageInventoryCommand::class, []];
        yield 'server' => [ServeCommand::class, []];
    }

    public function test_credential_commands_reject_explicit_empty_required_values_clearly(): void
    {
        foreach ([
            ['database', 'S3_CREDENTIALS_DSN', '--credentials-dsn'],
            ['file', 'S3_CREDENTIALS_PATH', '--credentials-file'],
        ] as [$driver, $environmentName, $option]) {
            $this->setEnvironment('S3_CREDENTIALS_DRIVER', $driver);
            $this->setEnvironment($environmentName, '');
            $tester = new CommandTester(new CredentialsCommand());
            self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
            self::assertStringContainsString("{$option} is required", $tester->getDisplay());
        }

        $this->setEnvironment('S3_CREDENTIALS_DSN', '');
        $migrator = new CommandTester(new CredentialsMigrateCommand());
        self::assertSame(Command::FAILURE, $migrator->execute([]));
        self::assertStringContainsString('--credentials-dsn is required', $migrator->getDisplay());
    }

    public function test_server_rejects_explicit_empty_required_credential_values_clearly(): void
    {
        foreach ([
            ['database', 'S3_CREDENTIALS_DSN', '--credentials-dsn'],
            ['file', 'S3_CREDENTIALS_PATH', '--credentials-file'],
        ] as [$driver, $environmentName, $option]) {
            $storagePath = sys_get_temp_dir() . '/s3-serve-credentials-env-' . bin2hex(random_bytes(6));
            $this->setEnvironment('S3_STORAGE_DRIVER', 'filesystem');
            $this->setEnvironment('S3_STORAGE_PATH', $storagePath);
            $this->setEnvironment('S3_METADATA_DRIVER', 'sqlite');
            $this->setEnvironment('S3_METADATA_PATH', null);
            $this->setEnvironment('S3_CREDENTIALS_DRIVER', $driver);
            $this->setEnvironment($environmentName, '');

            try {
                $tester = new CommandTester(new ServeCommand());
                self::assertSame(Command::FAILURE, $tester->execute([]), $tester->getDisplay());
                self::assertStringContainsString("{$option} is required", $tester->getDisplay());
                self::assertDirectoryDoesNotExist($storagePath);
                self::assertFileDoesNotExist($storagePath . '/metadata.sqlite');
                self::assertFileDoesNotExist($storagePath . '/.opsfour/metadata.sqlite');
            } finally {
                $this->recursiveDelete($storagePath);
            }
        }
    }

    private function setEnvironment(string $name, ?string $value): void
    {
        if (! array_key_exists($name, $this->originalEnvironment)) {
            $this->originalEnvironment[$name] = getenv($name);
        }
        putenv($value === null ? $name : $name . '=' . $value);
    }

    private function recursiveDelete(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $file) {
            $file->isDir() ? @rmdir($file->getPathname()) : @unlink($file->getPathname());
        }
        @rmdir($path);
    }
}
