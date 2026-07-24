<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony\DependencyInjection;

use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Factory\CredentialProviderFactory;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Symfony\Command\S3ServerCredentialsCommand;
use OpsFour\S3Server\Symfony\Command\S3ServerQuotaCommand;
use OpsFour\S3Server\Symfony\Command\S3ServerServeCommand;
use OpsFour\S3Server\Symfony\Encryption\SymfonyEncryptionFactory;
use OpsFour\S3Server\Symfony\Event\SymfonyEventDispatcherListener;
use OpsFour\S3Server\Symfony\S3ServerConfigFactory;
use Symfony\Component\DependencyInjection\ContainerBuilder;
use Symfony\Component\DependencyInjection\Definition;
use Symfony\Component\DependencyInjection\Extension\Extension;
use Symfony\Component\DependencyInjection\Reference;

final class S3ServerExtension extends Extension
{
    /**
     * @param array<int, array<string, mixed>> $configs
     */
    public function load(array $configs, ContainerBuilder $container): void
    {
        $configuration = new Configuration();
        $config = $this->processConfiguration($configuration, $configs);

        $metadataConfig = $config['metadata'];
        if ($metadataConfig['driver'] === 'sqlite' && $metadataConfig['path'] === null) {
            $metadataConfig['path'] = rtrim((string) $config['storage']['path'], '/') . '/metadata.sqlite';
        }
        $metadataConfig['worker_pool_size'] = $config['parallel']['sqlite_workers'];

        $container->setParameter('opsfour_s3_server.config', $config);
        $container->setParameter('opsfour_s3_server.metadata_config', $metadataConfig);

        $storageConfig = $this->storageConfigWithServiceReferences($config['storage']);
        $notificationListeners = $this->notificationListeners($config['notifications'], $container);
        $encryptionReference = $this->registerEncryption($config['encryption'], $container);

        $container->setDefinition(MetricsCollector::class, (new Definition(MetricsCollector::class))
            ->setPublic(true));

        $container->setDefinition(S3ServerConfig::class, (new Definition(S3ServerConfig::class))
            ->setFactory([S3ServerConfigFactory::class, 'create'])
            ->setArguments([$config])
            ->setPublic(true));

        $container->setDefinition(StorageTierRegistry::class, (new Definition(StorageTierRegistry::class))
            ->setFactory([StorageBackendFactory::class, 'createTierRegistry'])
            ->setArguments([$storageConfig, new Reference(MetricsCollector::class)])
            ->setPublic(true));

        $container->setDefinition(StorageBackend::class, (new Definition(StorageBackend::class))
            ->setFactory([new Reference(StorageTierRegistry::class), 'defaultBackend'])
            ->setPublic(true));

        $metadataStoreServiceId = 'opsfour_s3_server.metadata.inner';
        $container->setDefinition($metadataStoreServiceId, (new Definition(MetadataStore::class))
            ->setFactory([MetadataStoreFactory::class, 'create'])
            ->setArguments([
                $metadataConfig['driver'],
                $metadataConfig,
                null,
                new Reference(MetricsCollector::class),
            ])
            ->setPublic(false));

        if ((float) $metadataConfig['cache_ttl'] > 0.0) {
            $container->setDefinition(MetadataStore::class, (new Definition(CachedMetadataStoreDecorator::class))
                ->setArguments([
                    new Reference($metadataStoreServiceId),
                    (float) $metadataConfig['cache_ttl'],
                ])
                ->setPublic(true));
        } else {
            $container->setAlias(MetadataStore::class, $metadataStoreServiceId)
                ->setPublic(true);
        }

        $container->setDefinition(CredentialProvider::class, (new Definition(CredentialProvider::class))
            ->setFactory([CredentialProviderFactory::class, 'create'])
            ->setArguments([
                $config['credentials']['driver'],
                $config['credentials'],
            ])
            ->setPublic(true));

        $container->setDefinition(S3ServerRuntimeFactory::class, (new Definition(S3ServerRuntimeFactory::class))
            ->setPublic(true));

        $container->setDefinition(S3ServerServeCommand::class, (new Definition(S3ServerServeCommand::class))
            ->setArguments([
                new Reference(S3ServerConfig::class),
                new Reference(MetadataStore::class),
                new Reference(StorageBackend::class),
                new Reference(CredentialProvider::class),
                new Reference(MetricsCollector::class),
                new Reference(StorageTierRegistry::class),
                new Reference(S3ServerRuntimeFactory::class),
                $config['external_iam'],
                $config['admin']['token'],
                $notificationListeners,
                $encryptionReference,
            ])
            ->addTag('console.command', ['command' => 'opsfour:s3:serve'])
            ->setPublic(true));

        $container->setDefinition(S3ServerCredentialsCommand::class, (new Definition(S3ServerCredentialsCommand::class))
            ->setArguments([
                new Reference(CredentialProvider::class),
            ])
            ->addTag('console.command', ['command' => 'opsfour:s3:credentials'])
            ->setPublic(true));

        $container->setDefinition(S3ServerQuotaCommand::class, (new Definition(S3ServerQuotaCommand::class))
            ->setArguments([
                new Reference(MetadataStore::class),
            ])
            ->addTag('console.command', ['command' => 'opsfour:s3:quotas'])
            ->setPublic(true));
    }

    public function getAlias(): string
    {
        return 'opsfour_s3_server';
    }

    /**
     * @param array<string, mixed> $storageConfig
     * @return array<string, mixed>
     */
    private function storageConfigWithServiceReferences(array $storageConfig): array
    {
        if (isset($storageConfig['filesystem_service']) && is_string($storageConfig['filesystem_service']) && $storageConfig['filesystem_service'] !== '') {
            $storageConfig['filesystem'] = new Reference($storageConfig['filesystem_service']);
        }
        if (isset($storageConfig['filesystem_factory_service']) && is_string($storageConfig['filesystem_factory_service']) && $storageConfig['filesystem_factory_service'] !== '') {
            $storageConfig['filesystem_factory'] = new Reference($storageConfig['filesystem_factory_service']);
        }

        if (isset($storageConfig['tiers']) && is_array($storageConfig['tiers'])) {
            foreach ($storageConfig['tiers'] as $name => $tierConfig) {
                if (! is_array($tierConfig)) {
                    continue;
                }

                if (isset($tierConfig['filesystem_service']) && is_string($tierConfig['filesystem_service']) && $tierConfig['filesystem_service'] !== '') {
                    $tierConfig['filesystem'] = new Reference($tierConfig['filesystem_service']);
                }
                if (isset($tierConfig['filesystem_factory_service']) && is_string($tierConfig['filesystem_factory_service']) && $tierConfig['filesystem_factory_service'] !== '') {
                    $tierConfig['filesystem_factory'] = new Reference($tierConfig['filesystem_factory_service']);
                }

                $storageConfig['tiers'][$name] = $tierConfig;
            }
        }

        return $storageConfig;
    }

    /**
     * @param array<string, mixed> $notificationsConfig
     * @return list<array{pattern: string, listener: Reference}>
     */
    private function notificationListeners(array $notificationsConfig, ContainerBuilder $container): array
    {
        $listeners = [];

        $symfonyEventDispatcher = $notificationsConfig['symfony_event_dispatcher'] ?? [];
        if (is_array($symfonyEventDispatcher)
            && isset($symfonyEventDispatcher['service'])
            && is_string($symfonyEventDispatcher['service'])
            && $symfonyEventDispatcher['service'] !== ''
        ) {
            $serviceId = 'opsfour_s3_server.event_listener.symfony_event_dispatcher';
            $container->setDefinition($serviceId, (new Definition(SymfonyEventDispatcherListener::class))
                ->setArguments([
                    new Reference($symfonyEventDispatcher['service']),
                    $symfonyEventDispatcher['event_name'] ?? null,
                ]));

            $listeners[] = [
                'pattern' => (string) ($symfonyEventDispatcher['pattern'] ?? 's3:*'),
                'listener' => new Reference($serviceId),
            ];
        }

        foreach ($notificationsConfig['listeners'] ?? [] as $listenerConfig) {
            if (! is_array($listenerConfig)) {
                continue;
            }

            $listeners[] = [
                'pattern' => (string) $listenerConfig['event'],
                'listener' => new Reference((string) $listenerConfig['service']),
            ];
        }

        return $listeners;
    }

    /**
     * @param array<string, mixed> $encryptionConfig
     */
    private function registerEncryption(array $encryptionConfig, ContainerBuilder $container): ?Reference
    {
        $serviceId = $encryptionConfig['service'] ?? null;
        if (is_string($serviceId) && $serviceId !== '') {
            $container->setAlias(EncryptionServiceInterface::class, $serviceId)
                ->setPublic(true);

            return new Reference($serviceId);
        }

        $masterKeyProviderService = $encryptionConfig['master_key_provider_service'] ?? null;
        if (! is_string($masterKeyProviderService) || $masterKeyProviderService === '') {
            return null;
        }

        $container->setDefinition('opsfour_s3_server.encryption', (new Definition(EncryptionServiceInterface::class))
            ->setFactory([new Definition(SymfonyEncryptionFactory::class), 'create'])
            ->setArguments([
                new Reference(S3ServerConfig::class),
                new Reference(MetricsCollector::class),
                null,
                new Reference($masterKeyProviderService),
            ])
            ->setPublic(true));
        $container->setAlias(EncryptionServiceInterface::class, 'opsfour_s3_server.encryption')
            ->setPublic(true);

        return new Reference('opsfour_s3_server.encryption');
    }
}
