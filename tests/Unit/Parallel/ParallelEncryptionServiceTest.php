<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use PHPUnit\Framework\TestCase;

final class ParallelEncryptionServiceTest extends TestCase
{
    private ?string $originalEnv = null;

    private ?string $originalMultiEnv = null;

    private string $masterKeyB64;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('S3_ENCRYPTION_MASTER_KEY') ?: null;
        $this->originalMultiEnv = getenv('S3_ENCRYPTION_MASTER_KEYS') ?: null;
        putenv('S3_ENCRYPTION_MASTER_KEYS');

        // Generate a 32-byte master key.
        $masterKey = random_bytes(32);
        $this->masterKeyB64 = base64_encode($masterKey);
        putenv('S3_ENCRYPTION_MASTER_KEY=' . $this->masterKeyB64);
    }

    protected function tearDown(): void
    {
        if ($this->originalEnv !== null) {
            putenv('S3_ENCRYPTION_MASTER_KEY=' . $this->originalEnv);
        } else {
            putenv('S3_ENCRYPTION_MASTER_KEY');
        }
        if ($this->originalMultiEnv !== null) {
            putenv('S3_ENCRYPTION_MASTER_KEYS=' . $this->originalMultiEnv);
        } else {
            putenv('S3_ENCRYPTION_MASTER_KEYS');
        }
    }

    public function test_small_payload_runs_inline_sse_s3(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $service = new ParallelEncryptionService($provider, 2, 1024);
        self::assertTrue($service->supportsSseS3());
        self::assertSame($provider->keyringFingerprint(), $service->configWorkerKeyringFingerprint());
        self::assertSame($provider->keyringFingerprint(), (new EncryptionService($provider))->configWorkerKeyringFingerprint());

        $plaintext = 'small data';
        $result = $service->encryptSseS3($plaintext);

        $this->assertArrayHasKey('ciphertext', $result);
        $this->assertArrayHasKey('encryptedDataKey', $result);
        $this->assertArrayHasKey('iv', $result);
        $this->assertArrayHasKey('tag', $result);

        $decrypted = $service->decryptSseS3(
            $result['ciphertext'],
            $result['encryptedDataKey'],
            $result['iv'],
            $result['tag'],
        );

        $this->assertSame($plaintext, $decrypted);

        $service->shutdown();
    }

    public function test_large_payload_offloaded_sse_s3(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $metrics = new MetricsCollector();
        // Threshold of 64 bytes — anything larger goes to worker.
        $service = new ParallelEncryptionService($provider, 2, 64, $metrics);

        $plaintext = str_repeat('X', 256);
        $result = $service->encryptSseS3($plaintext);

        $this->assertArrayHasKey('ciphertext', $result);

        $decrypted = $service->decryptSseS3(
            $result['ciphertext'],
            $result['encryptedDataKey'],
            $result['iv'],
            $result['tag'],
        );

        $this->assertSame($plaintext, $decrypted);

        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('s3_server_worker_pool_configured_workers{pool="encryption"} 2', $rendered);
        $this->assertStringContainsString('s3_server_worker_pool_tasks_total{operation="encryptSseS3",pool="encryption",status="success"} 1', $rendered);
        $this->assertStringContainsString('s3_server_worker_pool_tasks_total{operation="decryptSseS3",pool="encryption",status="success"} 1', $rendered);

        $service->shutdown();
    }

    public function test_sse_c_round_trip(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $metrics = new MetricsCollector();
        $service = new ParallelEncryptionService($provider, 2, 64, $metrics);

        $customerKey = random_bytes(32);
        $plaintext = str_repeat('Y', 1_048_576);

        $result = $service->encryptSseC($plaintext, $customerKey);

        $this->assertArrayHasKey('ciphertext', $result);
        $this->assertArrayHasKey('iv', $result);
        $this->assertArrayHasKey('tag', $result);

        $decrypted = $service->decryptSseC(
            $result['ciphertext'],
            $customerKey,
            $result['iv'],
            $result['tag'],
        );

        $this->assertSame($plaintext, $decrypted);
        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('operation="encryptSseC",pool="encryption",status="success"} 1', $rendered);
        $this->assertStringContainsString('operation="decryptSseC",pool="encryption",status="success"} 1', $rendered);

        $service->shutdown();
    }

    public function test_direct_custom_provider_keeps_sse_s3_local_and_offloads_sse_c(): void
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
                return 'custom';
            }

            public function getMasterKeyById(string $keyId): string
            {
                return $keyId === 'custom' ? $this->key : throw new \RuntimeException('Unknown key ID.');
            }
        };

        $metrics = new MetricsCollector();
        $service = new ParallelEncryptionService($provider, 2, 64, $metrics);
        try {
            self::assertNull($service->configWorkerKeyringFingerprint());
            $body = str_repeat('custom-provider', 65_536);
            $encrypted = $service->encryptSseS3($body);
            self::assertSame($body, $service->decryptSseS3(
                $encrypted['ciphertext'],
                $encrypted['encryptedDataKey'],
                $encrypted['iv'],
                $encrypted['tag'],
            ));
            $customerKey = random_bytes(32);
            $customerEncrypted = $service->encryptSseC($body, $customerKey);
            self::assertSame($body, $service->decryptSseC(
                $customerEncrypted['ciphertext'],
                $customerKey,
                $customerEncrypted['iv'],
                $customerEncrypted['tag'],
            ));
            $rendered = $metrics->renderPrometheus();
            self::assertStringNotContainsString('operation="encryptSseS3",pool="encryption"', $rendered);
            self::assertStringContainsString('operation="encryptSseC",pool="encryption",status="success"} 1', $rendered);
        } finally {
            $service->shutdown();
        }
    }

    public function test_sse_c_worker_does_not_require_any_master_key_environment(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY');
        putenv('S3_ENCRYPTION_MASTER_KEYS');
        $metrics = new MetricsCollector();
        $service = new ParallelEncryptionService(null, 2, 64, $metrics);
        try {
            self::assertFalse($service->supportsSseS3());
            $body = str_repeat('C', 65_536);
            $customerKey = random_bytes(32);
            $encrypted = $service->encryptSseC($body, $customerKey);
            self::assertSame($body, $service->decryptSseC(
                $encrypted['ciphertext'],
                $customerKey,
                $encrypted['iv'],
                $encrypted['tag'],
            ));
            self::assertStringContainsString(
                'operation="encryptSseC",pool="encryption",status="success"} 1',
                $metrics->renderPrometheus(),
            );
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Master key provider required');
            $service->encryptSseS3($body);
        } finally {
            $service->shutdown();
        }
    }

    public function test_explicit_config_keyring_different_from_environment_remains_local_for_sse_s3(): void
    {
        $provider = new ConfigMasterKeyProvider(base64_encode(random_bytes(32)));
        $metrics = new MetricsCollector();
        $service = new ParallelEncryptionService($provider, 2, 64, $metrics);
        try {
            self::assertNull($service->configWorkerKeyringFingerprint());
            self::assertNull((new EncryptionService($provider))->configWorkerKeyringFingerprint());
            $body = str_repeat('X', 256);
            $encrypted = $service->encryptSseS3($body);
            self::assertSame($body, $service->decryptSseS3(
                $encrypted['ciphertext'],
                $encrypted['encryptedDataKey'],
                $encrypted['iv'],
                $encrypted['tag'],
            ));
            self::assertStringNotContainsString('operation="encryptSseS3",pool="encryption"', $metrics->renderPrometheus());
        } finally {
            $service->shutdown();
        }
    }

    public function test_different_historical_key_disables_sse_s3_offload_even_when_active_key_matches(): void
    {
        $active = base64_encode(random_bytes(32));
        $historical = base64_encode(random_bytes(32));
        putenv('S3_ENCRYPTION_MASTER_KEYS=' . json_encode([
            'active' => $active,
            'historical' => $historical,
        ], JSON_THROW_ON_ERROR));
        $provider = new ConfigMasterKeyProvider(keysJson: json_encode([
            'active' => $active,
            'historical' => base64_encode(random_bytes(32)),
        ], JSON_THROW_ON_ERROR));

        $metrics = new MetricsCollector();
        $service = new ParallelEncryptionService($provider, 2, 64, $metrics);
        try {
            $body = str_repeat('H', 256);
            $encrypted = $service->encryptSseS3($body);
            self::assertSame($body, $service->decryptSseS3(
                $encrypted['ciphertext'],
                $encrypted['encryptedDataKey'],
                $encrypted['iv'],
                $encrypted['tag'],
            ));
            self::assertStringNotContainsString('operation="encryptSseS3",pool="encryption"', $metrics->renderPrometheus());
        } finally {
            $service->shutdown();
        }
    }

    public function test_worker_rejects_environment_keyring_changed_after_service_construction(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $service = new ParallelEncryptionService($provider, 2, 64);
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
        try {
            self::assertNull($service->configWorkerKeyringFingerprint());
            self::assertNull((new EncryptionService($provider))->configWorkerKeyringFingerprint());
            $service->encryptSseS3(str_repeat('X', 256));
            self::fail('A worker with a different inherited keyring encrypted the object.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('keyring differs', $error->getMessage());
        } finally {
            $service->shutdown();
        }
    }

    public function test_inline_matches_direct_service(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $parallel = new ParallelEncryptionService($provider, 2, 1024);
        $direct = new EncryptionService($provider);

        // Encrypt with parallel (inline path), decrypt with direct service.
        $plaintext = 'cross-check';
        $encrypted = $parallel->encryptSseS3($plaintext);
        $decrypted = $direct->decryptSseS3(
            $encrypted['ciphertext'],
            $encrypted['encryptedDataKey'],
            $encrypted['iv'],
            $encrypted['tag'],
        );

        $this->assertSame($plaintext, $decrypted);

        $parallel->shutdown();
    }

    public function test_parallel_cluster_identity_matches_direct_service_and_detects_keyring_divergence(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $parallel = new ParallelEncryptionService($provider, 1, 1024);
        try {
            self::assertSame(
                (new EncryptionService($provider))->clusterEncryptionIdentity(),
                $parallel->clusterEncryptionIdentity(),
            );
            $different = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(str_repeat('q', 32))));
            self::assertNotSame($different->clusterEncryptionIdentity(), $parallel->clusterEncryptionIdentity());
        } finally {
            $parallel->shutdown();
        }
    }
}
