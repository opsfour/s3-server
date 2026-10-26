<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use Illuminate\Foundation\Application;
use OpsFour\S3Server\Console\CredentialsCommand;
use OpsFour\S3Server\Console\QuotaCommand;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Laravel\S3CredentialsCommand;
use OpsFour\S3Server\Laravel\S3QuotaCommand;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Symfony\Command\S3ServerCredentialsCommand;
use OpsFour\S3Server\Symfony\Command\S3ServerQuotaCommand;
use OpsFour\S3Server\Symfony\DependencyInjection\S3ServerExtension;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;

final class ManagementCommandFailureDisclosureTest extends TestCase
{
    private const string SECRET = 'database-password-secret-sentinel';

    public function test_standalone_quota_factory_error_omits_untrusted_driver_value(): void
    {
        $tester = new CommandTester(new QuotaCommand());

        self::assertSame(Command::FAILURE, $tester->execute([
            'action' => 'list',
            '--metadata-driver' => self::SECRET,
        ]));
        $this->assertRedacted($tester, 'S3 metadata initialization failed (InvalidArgumentException).');
    }

    public function test_standalone_credentials_rejects_untrusted_driver_value_without_echo(): void
    {
        $tester = new CommandTester(new CredentialsCommand());

        self::assertSame(Command::FAILURE, $tester->execute([
            'action' => 'list',
            '--credentials-driver' => self::SECRET,
        ]));
        $this->assertRedacted($tester, 'Unsupported credentials driver for management.');
    }

    public function test_standalone_credentials_factory_error_omits_raw_dependency_details(): void
    {
        $path = sys_get_temp_dir() . '/s3-credentials-invalid-' . bin2hex(random_bytes(6)) . '.json';
        file_put_contents($path, '{invalid json');
        chmod($path, 0o600);
        try {
            $tester = new CommandTester(new CredentialsCommand());
            self::assertSame(Command::FAILURE, $tester->execute([
                'action' => 'list',
                '--credentials-driver' => 'file',
                '--credentials-file' => $path,
            ]));
            self::assertStringContainsString('S3 credential provider initialization failed (JsonException).', $tester->getDisplay());
            self::assertStringNotContainsString('Syntax error', $tester->getDisplay());
        } finally {
            @unlink($path);
        }
    }

    public function test_standalone_credentials_keeps_safe_required_option_error(): void
    {
        $tester = new CommandTester(new CredentialsCommand());

        self::assertSame(Command::FAILURE, $tester->execute([
            'action' => 'list',
            '--credentials-driver' => 'database',
        ]));
        self::assertStringContainsString('--credentials-dsn is required for the database driver.', $tester->getDisplay());
    }

    public function test_laravel_quota_dependency_error_omits_secret(): void
    {
        $app = new Application(sys_get_temp_dir());
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('listAccountQuotas')->willThrowException(new \RuntimeException(self::SECRET));
        $app->instance(MetadataStore::class, $metadata);
        $command = new S3QuotaCommand();
        $command->setLaravel($app);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 quota operation failed (RuntimeException).');
    }

    public function test_laravel_quota_factory_error_omits_secret(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->singleton(MetadataStore::class, static function (): never {
            throw new \RuntimeException(self::SECRET);
        });
        $command = $app->make(S3QuotaCommand::class);
        $command->setLaravel($app);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 quota operation failed (RuntimeException).');
    }

    public function test_laravel_credentials_dependency_error_omits_secret(): void
    {
        $app = new Application(sys_get_temp_dir());
        $provider = $this->createMock(CredentialProvider::class);
        $provider->method('listCredentials')->willThrowException(new \RuntimeException(self::SECRET));
        $app->instance(CredentialProvider::class, $provider);
        $command = new S3CredentialsCommand();
        $command->setLaravel($app);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 credential operation failed (RuntimeException).');
    }

    public function test_laravel_credentials_factory_error_omits_secret(): void
    {
        $app = new Application(sys_get_temp_dir());
        $app->singleton(CredentialProvider::class, static function (): never {
            throw new \RuntimeException(self::SECRET);
        });
        $command = $app->make(S3CredentialsCommand::class);
        $command->setLaravel($app);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 credential provider initialization failed (RuntimeException).');
    }

    public function test_laravel_credentials_toggle_error_omits_secret(): void
    {
        $app = new Application(sys_get_temp_dir());
        $provider = $this->createMock(CredentialProvider::class);
        $provider->method('getCredential')->willThrowException(new \RuntimeException(self::SECRET));
        $app->instance(CredentialProvider::class, $provider);
        $command = new S3CredentialsCommand();
        $command->setLaravel($app);
        $tester = new CommandTester($command);

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'activate', 'access-key-id' => 'key']));
        $this->assertRedacted($tester, 'S3 credential operation failed (RuntimeException).');
    }

    public function test_symfony_quota_dependency_error_omits_secret(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('listAccountQuotas')->willThrowException(new \RuntimeException(self::SECRET));
        $tester = new CommandTester(new S3ServerQuotaCommand($metadata));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 quota operation failed (RuntimeException).');
    }

    public function test_symfony_credentials_dependency_error_omits_secret(): void
    {
        $provider = $this->createMock(CredentialProvider::class);
        $provider->method('listCredentials')->willThrowException(new \RuntimeException(self::SECRET));
        $tester = new CommandTester(new S3ServerCredentialsCommand($provider));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 credential operation failed (RuntimeException).');
    }

    public function test_symfony_credentials_toggle_error_omits_secret(): void
    {
        $provider = $this->createMock(CredentialProvider::class);
        $provider->method('getCredential')->willThrowException(new \RuntimeException(self::SECRET));
        $tester = new CommandTester(new S3ServerCredentialsCommand($provider));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'activate', 'access-key-id' => 'key']));
        $this->assertRedacted($tester, 'S3 credential operation failed (RuntimeException).');
    }

    public function test_symfony_credentials_keeps_safe_not_found_error(): void
    {
        $provider = $this->createMock(CredentialProvider::class);
        $provider->method('getCredential')->willReturn(null);
        $tester = new CommandTester(new S3ServerCredentialsCommand($provider));

        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'activate', 'access-key-id' => 'missing-key']));
        self::assertStringContainsString('Credential not found.', $tester->getDisplay());
    }

    public function test_symfony_bundle_defers_metadata_factory_failure_until_quota_command_can_redact_it(): void
    {
        $container = $this->symfonyContainer();
        $container->setDefinition(MetadataStore::class, (new Definition(MetadataStore::class))
            ->setFactory([ManagementCommandFailureFactory::class, 'metadata'])
            ->setPublic(true));
        $container->compile();

        $command = $container->get(S3ServerQuotaCommand::class);
        self::assertInstanceOf(S3ServerQuotaCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 quota operation failed (RuntimeException).');
    }

    public function test_symfony_bundle_defers_credentials_factory_failure_until_command_can_redact_it(): void
    {
        $container = $this->symfonyContainer();
        $container->setDefinition(CredentialProvider::class, (new Definition(CredentialProvider::class))
            ->setFactory([ManagementCommandFailureFactory::class, 'credentials'])
            ->setPublic(true));
        $container->compile();

        $command = $container->get(S3ServerCredentialsCommand::class);
        self::assertInstanceOf(S3ServerCredentialsCommand::class, $command);
        $tester = new CommandTester($command);
        self::assertSame(Command::FAILURE, $tester->execute(['action' => 'list']));
        $this->assertRedacted($tester, 'S3 credential provider initialization failed (RuntimeException).');
    }

    private function symfonyContainer(): ContainerBuilder
    {
        $container = new ContainerBuilder();
        (new S3ServerExtension())->load([[
            'storage' => ['driver' => 'memory', 'path' => sys_get_temp_dir()],
            'metadata' => ['driver' => 'sqlite', 'path' => ':memory:'],
            'credentials' => [
                'driver' => 'memory',
                'access_key' => 'key',
                'secret_key' => 'secret',
                'owner_id' => 'owner',
                'display_name' => 'Owner',
            ],
        ]], $container);

        return $container;
    }

    private function assertRedacted(CommandTester $tester, string $message): void
    {
        $display = $tester->getDisplay();
        self::assertStringContainsString($message, $display);
        self::assertStringNotContainsString(self::SECRET, $display);
    }
}

final class ManagementCommandFailureFactory
{
    public static function metadata(): never
    {
        throw new \RuntimeException('database-password-secret-sentinel');
    }

    public static function credentials(): never
    {
        throw new \RuntimeException('database-password-secret-sentinel');
    }
}
