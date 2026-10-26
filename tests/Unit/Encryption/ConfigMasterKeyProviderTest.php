<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use PHPUnit\Framework\TestCase;

final class ConfigMasterKeyProviderTest extends TestCase
{
    /** @var array<string, string|false> */
    private array $originalEnvironment = [];

    protected function setUp(): void
    {
        parent::setUp();

        foreach (['S3_ENCRYPTION_MASTER_KEY', 'S3_ENCRYPTION_MASTER_KEYS'] as $name) {
            $this->originalEnvironment[$name] = getenv($name);
            putenv($name);
        }
    }

    protected function tearDown(): void
    {
        foreach ($this->originalEnvironment as $name => $value) {
            putenv($value === false ? $name : "{$name}={$value}");
        }

        parent::tearDown();
    }

    public function test_empty_multi_key_environment_does_not_fall_back_to_legacy_key(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
        putenv('S3_ENCRYPTION_MASTER_KEYS=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_ENCRYPTION_MASTER_KEYS must be a non-empty JSON object');

        new ConfigMasterKeyProvider();
    }

    public function test_zero_multi_key_environment_reaches_format_validation(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(random_bytes(32)));
        putenv('S3_ENCRYPTION_MASTER_KEYS=0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_ENCRYPTION_MASTER_KEYS must be a non-empty JSON object');

        new ConfigMasterKeyProvider();
    }

    public function test_zero_legacy_key_environment_reaches_key_validation(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY=0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Master key must be exactly 32 bytes');

        new ConfigMasterKeyProvider();
    }
}
