<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use PHPUnit\Framework\TestCase;

final class ParallelEncryptionServiceTest extends TestCase
{
    private ?string $originalEnv = null;

    private string $masterKeyB64;

    protected function setUp(): void
    {
        $this->originalEnv = getenv('S3_ENCRYPTION_MASTER_KEY') ?: null;

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
    }

    public function test_small_payload_runs_inline_sse_s3(): void
    {
        $provider = new ConfigMasterKeyProvider($this->masterKeyB64);
        $service = new ParallelEncryptionService($provider, 2, 1024);

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
        $metrics = new MetricsCollector;
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
        $service = new ParallelEncryptionService($provider, 2, 64);

        $customerKey = random_bytes(32);
        $plaintext = str_repeat('Y', 128);

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

        $service->shutdown();
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
}
