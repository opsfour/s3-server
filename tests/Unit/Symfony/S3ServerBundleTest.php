<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Symfony;

use League\Flysystem\Filesystem;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\LocalFlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use OpsFour\S3Server\Symfony\Command\S3ServerCredentialsCommand;
use OpsFour\S3Server\Symfony\Command\S3ServerQuotaCommand;
use OpsFour\S3Server\Symfony\Command\S3ServerServeCommand;
use OpsFour\S3Server\Symfony\DependencyInjection\S3ServerExtension;
use OpsFour\S3Server\Symfony\Event\SymfonyEventDispatcherListener;
use OpsFour\S3Server\Symfony\S3ServerBundle;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Config\Definition\Exception\InvalidConfigurationException;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\EventDispatcher\EventDispatcher;

final class S3ServerBundleTest extends TestCase
{
    /** @var list<string> */
    private array $cleanupPaths = [];

    protected function tearDown(): void
    {
        foreach (array_reverse($this->cleanupPaths) as $path) {
            if (is_file($path)) {
                @unlink($path);
            } elseif (is_dir($path)) {
                @rmdir($path);
            }
        }

        parent::tearDown();
    }

    public function test_bundle_exposes_container_extension(): void
    {
        self::assertInstanceOf(S3ServerExtension::class, (new S3ServerBundle())->getContainerExtension());
    }

    public function test_container_compiles_and_resolves_core_services(): void
    {
        $container = new ContainerBuilder();
        $extension = new S3ServerExtension();
        $extension->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'driver' => 'sqlite',
                'path' => ':memory:',
            ],
            'credentials' => [
                'driver' => 'memory',
                'access_key' => 'symfonyAccessKey',
                'secret_key' => 'symfonySecretKey',
                'owner_id' => 'symfony-owner',
                'display_name' => 'Symfony Owner',
            ],
        ]], $container);
        $container->compile();

        self::assertInstanceOf(S3ServerConfig::class, $this->service($container, S3ServerConfig::class));
        self::assertInstanceOf(MetricsCollector::class, $this->service($container, MetricsCollector::class));
        self::assertInstanceOf(StorageTierRegistry::class, $this->service($container, StorageTierRegistry::class));
        self::assertInstanceOf(StorageBackend::class, $this->service($container, StorageBackend::class));
        self::assertInstanceOf(MetadataStore::class, $this->service($container, MetadataStore::class));
        self::assertInstanceOf(CredentialProvider::class, $this->service($container, CredentialProvider::class));
        self::assertInstanceOf(S3ServerRuntimeFactory::class, $this->service($container, S3ServerRuntimeFactory::class));
        self::assertInstanceOf(S3ServerServeCommand::class, $this->service($container, S3ServerServeCommand::class));
        self::assertInstanceOf(S3ServerCredentialsCommand::class, $this->service($container, S3ServerCredentialsCommand::class));
        self::assertInstanceOf(S3ServerQuotaCommand::class, $this->service($container, S3ServerQuotaCommand::class));

        $config = $this->service($container, S3ServerConfig::class);
        self::assertSame('0.0.0.0', $config->host);
        self::assertSame(9000, $config->port);
        self::assertSame('us-east-1', $config->region);
        self::assertTrue($config->enforceMinPartSize);
    }

    public function test_default_configuration_does_not_expose_known_memory_credentials(): void
    {
        $container = new ContainerBuilder();
        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
        ]], $container);
        $container->compile();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Memory credentials requires "access_key"');

        $container->get(CredentialProvider::class);
    }

    public function test_config_overrides_are_applied_to_server_config(): void
    {
        $container = new ContainerBuilder();
        (new S3ServerExtension())->load([[
            'server' => [
                'host' => '127.0.0.1',
                'port' => 9444,
                'region' => 'eu-central-1',
                'max_connections' => 123,
                'body_size_limit' => 456789,
                'enforce_min_part_size' => false,
            ],
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
            'parallel' => [
                'select_workers' => 4,
                'request_body_spool_workers' => 3,
            ],
        ]], $container);
        $container->compile();

        $config = $this->service($container, S3ServerConfig::class);
        self::assertSame('127.0.0.1', $config->host);
        self::assertSame(9444, $config->port);
        self::assertSame('eu-central-1', $config->region);
        self::assertSame(123, $config->maxConcurrentConnections);
        self::assertSame(456789, $config->requestBodySizeLimit);
        self::assertFalse($config->enforceMinPartSize);
        self::assertSame(4, $config->selectWorkerPoolSize);
        self::assertSame(3, $config->requestBodySpoolWorkerPoolSize);
    }

    public function test_metadata_cache_ttl_wraps_metadata_store_by_default(): void
    {
        $container = $this->container();

        $metadata = $this->service($container, MetadataStore::class);
        self::assertInstanceOf(CachedMetadataStoreDecorator::class, $metadata);
        self::assertInstanceOf(SqliteMetadataStore::class, $metadata->innerStore());
    }

    public function test_metadata_cache_can_be_disabled(): void
    {
        $container = $this->container([
            'metadata' => [
                'path' => ':memory:',
                'cache_ttl' => 0.0,
            ],
        ]);

        self::assertInstanceOf(SqliteMetadataStore::class, $container->get(MetadataStore::class));
    }

    public function test_serve_command_is_registered_with_expected_name_and_options(): void
    {
        $container = new ContainerBuilder();
        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
            'credentials' => $this->memoryCredentials(),
        ]], $container);
        $container->compile();

        $definition = $container->getDefinition(S3ServerServeCommand::class);
        self::assertSame([
            ['command' => 'opsfour:s3:serve'],
        ], $definition->getTag('console.command'));

        $command = $this->service($container, S3ServerServeCommand::class);
        self::assertSame('opsfour:s3:serve', $command->getName());
        self::assertTrue($command->getDefinition()->hasOption('host'));
        self::assertTrue($command->getDefinition()->hasOption('port'));
        self::assertTrue($command->getDefinition()->hasOption('admin-token'));
    }

    public function test_serve_command_reports_invalid_overrides_as_failure(): void
    {
        $container = $this->container();
        $tester = new CommandTester($this->service($container, S3ServerServeCommand::class));

        $exit = $tester->execute(['--port' => '0']);

        self::assertSame(Command::FAILURE, $exit);
        self::assertStringContainsString('Port must be between 1 and 65535', $tester->getDisplay());
    }

    public function test_credentials_command_manages_configured_file_provider(): void
    {
        $credentialsPath = $this->tempPath('s3-symfony-credentials-', '.json');
        $container = $this->container([
            'credentials' => [
                'driver' => 'file',
                'path' => $credentialsPath,
            ],
        ]);

        $tester = new CommandTester($this->service($container, S3ServerCredentialsCommand::class));
        $exit = $tester->execute([
            'action' => 'create',
            '--owner-id' => 'account-a',
            '--display-name' => 'Account A',
            '--access-key' => 'symfony-key-a',
            '--secret-key' => 'symfony-secret-a',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('Credential created successfully', $tester->getDisplay());

        $tester = new CommandTester($this->service($container, S3ServerCredentialsCommand::class));
        $exit = $tester->execute(['action' => 'list']);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('symfony-key-a', $tester->getDisplay());
        self::assertStringContainsString('account-a', $tester->getDisplay());

        $tester = new CommandTester($this->service($container, S3ServerCredentialsCommand::class));
        $exit = $tester->execute([
            'action' => 'show',
            'access-key-id' => 'symfony-key-a',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('symfony-key-a', $tester->getDisplay());
        self::assertStringContainsString('************et-a', $tester->getDisplay());
    }

    public function test_quota_command_manages_configured_metadata_store(): void
    {
        $container = $this->container();

        $tester = new CommandTester($this->service($container, S3ServerQuotaCommand::class));
        $exit = $tester->execute([
            'action' => 'set',
            'owner-id' => 'account-a',
            '--max-buckets' => '2',
            '--max-objects-per-bucket' => '10',
            '--max-bytes-per-bucket' => '1024',
            '--max-bytes' => '4096',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('Quota saved for owner account-a', $tester->getDisplay());

        $tester = new CommandTester($this->service($container, S3ServerQuotaCommand::class));
        $exit = $tester->execute([
            'action' => 'show',
            'owner-id' => 'account-a',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('account-a', $tester->getDisplay());
        self::assertStringContainsString('4096', $tester->getDisplay());

        $tester = new CommandTester($this->service($container, S3ServerQuotaCommand::class));
        $exit = $tester->execute(['action' => 'list']);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('account-a', $tester->getDisplay());

        $tester = new CommandTester($this->service($container, S3ServerQuotaCommand::class));
        $exit = $tester->execute([
            'action' => 'delete',
            'owner-id' => 'account-a',
        ]);
        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
    }

    public function test_flysystem_storage_can_reference_symfony_service(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('test.flysystem.adapter', new Definition(InMemoryFlysystemAdapter::class));
        $container->setDefinition('test.flysystem', (new Definition(Filesystem::class))
            ->setArguments([
                new \Symfony\Component\DependencyInjection\Reference('test.flysystem.adapter'),
            ]));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'flysystem',
                'filesystem_service' => 'test.flysystem',
                'temp_dir' => sys_get_temp_dir(),
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
        ]], $container);
        $container->compile();

        $storage = $container->get(StorageBackend::class);
        self::assertInstanceOf(ObservedStorageBackend::class, $storage);
        self::assertInstanceOf(FlysystemBackend::class, $storage->innerBackend());
    }

    public function test_flysystem_worker_factory_can_reference_symfony_service(): void
    {
        $root = sys_get_temp_dir() . '/opsfour-s3-symfony-worker-' . bin2hex(random_bytes(4));
        mkdir($root, 0o755, true);
        $this->cleanupPaths[] = $root;
        $container = new ContainerBuilder();
        $container->setDefinition('test.flysystem.factory', (new Definition(LocalFlysystemFilesystemFactory::class))
            ->setArguments([$root]));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'flysystem',
                'filesystem_factory_service' => 'test.flysystem.factory',
                'worker_pool_size' => 1,
                'temp_dir' => sys_get_temp_dir(),
                'path' => $root,
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
        ]], $container);
        $container->compile();

        $storage = $container->get(StorageBackend::class);
        self::assertInstanceOf(ObservedStorageBackend::class, $storage);
        self::assertInstanceOf(ParallelFlysystemBackend::class, $storage->innerBackend());
        $storage->shutdown();
    }

    public function test_tiered_flysystem_storage_can_reference_symfony_services(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('test.standard.adapter', new Definition(InMemoryFlysystemAdapter::class));
        $container->setDefinition('test.standard.flysystem', (new Definition(Filesystem::class))
            ->setArguments([
                new \Symfony\Component\DependencyInjection\Reference('test.standard.adapter'),
            ]));
        $container->setDefinition('test.cold.adapter', new Definition(InMemoryFlysystemAdapter::class));
        $container->setDefinition('test.cold.flysystem', (new Definition(Filesystem::class))
            ->setArguments([
                new \Symfony\Component\DependencyInjection\Reference('test.cold.adapter'),
            ]));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'flysystem',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
                'tiers' => [
                    'STANDARD' => [
                        'driver' => 'flysystem',
                        'filesystem_service' => 'test.standard.flysystem',
                        'default' => true,
                    ],
                    'GLACIER' => [
                        'driver' => 'flysystem',
                        'filesystem_service' => 'test.cold.flysystem',
                        'restore_required' => true,
                    ],
                ],
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
        ]], $container);
        $container->compile();

        $tiers = $this->service($container, StorageTierRegistry::class);
        $standard = $tiers->tier('STANDARD')->backend;
        $glacier = $tiers->tier('GLACIER')->backend;
        self::assertInstanceOf(ObservedStorageBackend::class, $standard);
        self::assertInstanceOf(FlysystemBackend::class, $standard->innerBackend());
        self::assertInstanceOf(ObservedStorageBackend::class, $glacier);
        self::assertInstanceOf(FlysystemBackend::class, $glacier->innerBackend());
        self::assertTrue($tiers->tier('GLACIER')->restoreRequired);
    }

    public function test_notification_listener_services_are_injected_into_serve_command(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('test.s3_listener', (new Definition(RecordingSymfonyS3Listener::class))
            ->setPublic(true));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
            'credentials' => $this->memoryCredentials(),
            'notifications' => [
                'listeners' => [
                    [
                        'event' => 's3:ObjectCreated:*',
                        'service' => 'test.s3_listener',
                    ],
                ],
            ],
        ]], $container);
        $container->compile();

        $listeners = $this->notificationListeners($this->service($container, S3ServerServeCommand::class));
        self::assertCount(1, $listeners);
        self::assertSame('s3:ObjectCreated:*', $listeners[0]['pattern']);
        self::assertInstanceOf(RecordingSymfonyS3Listener::class, $listeners[0]['listener']);
    }

    public function test_symfony_event_dispatcher_can_be_registered_as_notification_listener(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('test.event_dispatcher', (new Definition(EventDispatcher::class))
            ->setPublic(true));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
            'credentials' => $this->memoryCredentials(),
            'notifications' => [
                'symfony_event_dispatcher' => [
                    'service' => 'test.event_dispatcher',
                    'pattern' => 's3:*',
                ],
            ],
        ]], $container);
        $container->compile();

        $listeners = $this->notificationListeners($this->service($container, S3ServerServeCommand::class));
        self::assertCount(1, $listeners);
        self::assertSame('s3:*', $listeners[0]['pattern']);
        self::assertInstanceOf(SymfonyEventDispatcherListener::class, $listeners[0]['listener']);

        $received = [];
        $eventDispatcher = $container->get('test.event_dispatcher');
        if (!$eventDispatcher instanceof EventDispatcher) {
            self::fail('Expected the configured Symfony event dispatcher service.');
        }
        $eventDispatcher->addListener(
            's3:ObjectCreated:Put',
            static function (S3Event $event) use (&$received): void {
                $received[] = $event;
            },
        );

        ($listeners[0]['listener'])(new S3Event('s3:ObjectCreated:Put', 'bucket', 'key.txt'));
        self::assertCount(1, $received);
        self::assertSame('key.txt', $received[0]->key);
    }

    public function test_encryption_service_can_be_referenced_from_symfony_service(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('test.encryption', (new Definition(RecordingEncryptionService::class))
            ->setPublic(true));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
            'credentials' => $this->memoryCredentials(),
            'encryption' => [
                'service' => 'test.encryption',
            ],
        ]], $container);
        $container->compile();

        $encryption = $container->get('test.encryption');
        self::assertInstanceOf(RecordingEncryptionService::class, $encryption);
        self::assertSame($encryption, $this->service($container, EncryptionServiceInterface::class));
        self::assertSame(
            $encryption,
            $this->serveCommandEncryption($this->service($container, S3ServerServeCommand::class)),
        );
    }

    public function test_master_key_provider_service_builds_symfony_encryption_service(): void
    {
        $container = new ContainerBuilder();
        $container->setDefinition('test.master_key_provider', (new Definition(ConfigMasterKeyProvider::class))
            ->setArguments([base64_encode(str_repeat('k', 32))])
            ->setPublic(true));

        (new S3ServerExtension())->load([[
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'path' => ':memory:',
            ],
            'credentials' => $this->memoryCredentials(),
            'encryption' => [
                'master_key_provider_service' => 'test.master_key_provider',
            ],
        ]], $container);
        $container->compile();

        self::assertInstanceOf(EncryptionService::class, $this->service($container, EncryptionServiceInterface::class));
        self::assertInstanceOf(EncryptionService::class, $this->serveCommandEncryption(
            $this->service($container, S3ServerServeCommand::class),
        ));
    }

    public function test_invalid_config_fails_during_extension_load(): void
    {
        $this->expectException(InvalidConfigurationException::class);

        (new S3ServerExtension())->load([[
            'server' => [
                'port' => 0,
            ],
        ]], new ContainerBuilder());
    }

    /**
     * @param array<string, mixed> $overrides
     */
    private function container(array $overrides = []): ContainerBuilder
    {
        $config = array_replace_recursive([
            'storage' => [
                'driver' => 'memory',
                'path' => sys_get_temp_dir() . '/opsfour-s3-symfony-test',
            ],
            'metadata' => [
                'driver' => 'sqlite',
                'path' => ':memory:',
            ],
            'credentials' => [
                'driver' => 'memory',
                'access_key' => 'symfonyAccessKey',
                'secret_key' => 'symfonySecretKey',
                'owner_id' => 'symfony-owner',
                'display_name' => 'Symfony Owner',
            ],
        ], $overrides);

        $container = new ContainerBuilder();
        (new S3ServerExtension())->load([$config], $container);
        $container->compile();

        return $container;
    }

    private function tempPath(string $prefix, string $suffix = ''): string
    {
        $path = tempnam(sys_get_temp_dir(), $prefix);
        self::assertIsString($path);
        if ($suffix !== '') {
            @unlink($path);
            $path .= $suffix;
        }
        $this->cleanupPaths[] = $path;

        return $path;
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private function service(ContainerBuilder $container, string $id): object
    {
        $service = $container->get($id);
        if (!$service instanceof $id) {
            self::fail("Container service {$id} did not resolve to the expected type.");
        }

        return $service;
    }

    /** @return array<string, string> */
    private function memoryCredentials(): array
    {
        return [
            'driver' => 'memory',
            'access_key' => 'symfonyAccessKey',
            'secret_key' => 'symfonySecretKey',
            'owner_id' => 'symfony-owner',
            'display_name' => 'Symfony Owner',
        ];
    }

    /**
     * @return list<array{pattern: string, listener: callable}>
     */
    private function notificationListeners(S3ServerServeCommand $command): array
    {
        $property = new \ReflectionProperty($command, 'notificationListeners');

        return $property->getValue($command);
    }

    private function serveCommandEncryption(S3ServerServeCommand $command): ?EncryptionServiceInterface
    {
        $property = new \ReflectionProperty($command, 'encryption');

        return $property->getValue($command);
    }
}

final class RecordingSymfonyS3Listener
{
    /** @var list<S3Event> */
    public array $events = [];

    public function __invoke(S3Event $event): void
    {
        $this->events[] = $event;
    }
}

final class RecordingEncryptionService implements EncryptionServiceInterface
{
    public function encryptSseS3(string $plaintext): array
    {
        return [
            'ciphertext' => $plaintext,
            'encryptedDataKey' => '',
            'iv' => '',
            'tag' => '',
        ];
    }

    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
    {
        return $ciphertext;
    }

    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        return [
            'ciphertext' => $plaintext,
            'iv' => '',
            'tag' => '',
        ];
    }

    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
    {
        return $ciphertext;
    }
}
