<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class StorageTierRegistryTest extends TestCase
{
    public function test_single_backend_registry_uses_standard_default_tier(): void
    {
        $backend = new InMemoryBackend();
        $registry = StorageTierRegistry::single($backend);

        self::assertSame($backend, $registry->defaultBackend());
        self::assertSame('STANDARD', $registry->defaultTier()->name);
        self::assertFalse($registry->defaultTier()->restoreRequired);
    }

    public function test_factory_builds_configured_physical_tiers(): void
    {
        $registry = StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'memory',
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'memory',
                    'restore_required' => true,
                ],
            ],
        ]);

        self::assertSame('STANDARD', $registry->defaultTier()->name);
        self::assertTrue($registry->has('GLACIER'));
        self::assertTrue($registry->tier('GLACIER')->restoreRequired);
        self::assertInstanceOf(InMemoryBackend::class, $registry->tier('STANDARD')->backend);
    }

    public function test_factory_observes_every_configured_tier(): void
    {
        $metrics = new MetricsCollector();
        $registry = StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'memory',
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'memory',
                    'restore_required' => true,
                ],
            ],
        ], $metrics);

        $standard = $registry->tier('STANDARD')->backend;
        $glacier = $registry->tier('GLACIER')->backend;
        self::assertInstanceOf(ObservedStorageBackend::class, $standard);
        self::assertInstanceOf(ObservedStorageBackend::class, $glacier);

        $standard->createBucket('hot');
        $glacier->createBucket('cold');
        $rendered = $metrics->renderPrometheus();
        self::assertStringContainsString('driver="memory:STANDARD",operation="createBucket"} 1', $rendered);
        self::assertStringContainsString('driver="memory:GLACIER",operation="createBucket"} 1', $rendered);
    }

    public function test_observability_decorator_preserves_tier_configuration_and_is_idempotent(): void
    {
        $registry = new StorageTierRegistry([
            new StorageTier('STANDARD', new InMemoryBackend(), defaultWriteTier: true),
            new StorageTier('GLACIER', new InMemoryBackend(), restoreRequired: true),
        ]);
        $metrics = new MetricsCollector();

        $observed = $registry->withObservability($metrics)->withObservability($metrics);

        self::assertSame('STANDARD', $observed->defaultTier()->name);
        self::assertTrue($observed->tier('GLACIER')->restoreRequired);
        $standard = $observed->defaultBackend();
        self::assertInstanceOf(ObservedStorageBackend::class, $standard);
        self::assertInstanceOf(InMemoryBackend::class, $standard->innerBackend());
    }

    public function test_duplicate_default_tiers_are_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new StorageTierRegistry([
            new StorageTier('STANDARD', new InMemoryBackend(), defaultWriteTier: true),
            new StorageTier('STANDARD_IA', new InMemoryBackend(), defaultWriteTier: true),
        ]);
    }

    public function test_unknown_tier_is_rejected(): void
    {
        $registry = StorageTierRegistry::single(new InMemoryBackend());

        $this->expectException(\InvalidArgumentException::class);
        $registry->tier('MISSING');
    }
}
