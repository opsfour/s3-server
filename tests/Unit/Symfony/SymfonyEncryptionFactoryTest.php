<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Symfony;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Symfony\Encryption\SymfonyEncryptionFactory;
use PHPUnit\Framework\TestCase;

final class SymfonyEncryptionFactoryTest extends TestCase
{
    public function test_large_payload_uses_the_injected_custom_provider_keyring(): void
    {
        $key = random_bytes(32);
        $provider = new class ($key) implements MasterKeyProvider {
            public function __construct(private readonly string $key) {}

            public function getMasterKey(): string
            {
                return $this->key;
            }

            public function getKeyId(): string
            {
                return 'external';
            }

            public function getMasterKeyById(string $keyId): string
            {
                return $keyId === 'external' ? $this->key : throw new \RuntimeException('Unknown key ID.');
            }
        };
        $service = (new SymfonyEncryptionFactory())->create(
            new S3ServerConfig(storagePath: sys_get_temp_dir(), encryptionWorkerPoolSize: 2, encryptionParallelThreshold: 1),
            new MetricsCollector(),
            masterKeyProvider: $provider,
        );
        self::assertInstanceOf(ParallelEncryptionService::class, $service);

        $body = str_repeat('custom-provider', 65_536);
        $result = $service->encryptSseS3($body);
        self::assertSame($body, $service->decryptSseS3(
            $result['ciphertext'],
            $result['encryptedDataKey'],
            $result['iv'],
            $result['tag'],
        ));
        $customerKey = random_bytes(32);
        $customerEncrypted = $service->encryptSseC($body, $customerKey);
        self::assertSame($body, $service->decryptSseC(
            $customerEncrypted['ciphertext'],
            $customerKey,
            $customerEncrypted['iv'],
            $customerEncrypted['tag'],
        ));
        $service->shutdown();
    }

    public function test_explicit_config_keyring_different_from_environment_stays_local(): void
    {
        $provider = new ConfigMasterKeyProvider(base64_encode(random_bytes(32)));
        $service = (new SymfonyEncryptionFactory())->create(
            new S3ServerConfig(storagePath: sys_get_temp_dir(), encryptionWorkerPoolSize: 2, encryptionParallelThreshold: 1),
            new MetricsCollector(),
            masterKeyProvider: $provider,
        );
        self::assertInstanceOf(ParallelEncryptionService::class, $service);

        $body = str_repeat('config-provider', 65_536);
        $result = $service->encryptSseS3($body);
        self::assertSame($body, $service->decryptSseS3(
            $result['ciphertext'],
            $result['encryptedDataKey'],
            $result['iv'],
            $result['tag'],
        ));
        $service->shutdown();
    }
}
