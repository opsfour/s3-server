<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use League\Flysystem\FilesystemOperator;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Runtime\BootstrapResourceCleanup;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\LocalFlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
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

    public function test_same_backend_cannot_be_registered_as_two_physical_tiers(): void
    {
        $backend = new InMemoryBackend();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same backend');

        new StorageTierRegistry([
            new StorageTier('STANDARD', $backend, defaultWriteTier: true),
            new StorageTier('GLACIER', $backend, restoreRequired: true),
        ]);
    }

    public function test_observed_wrappers_do_not_hide_a_shared_backend(): void
    {
        $backend = new InMemoryBackend();
        $metrics = new MetricsCollector();

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same backend');

        new StorageTierRegistry([
            new StorageTier('STANDARD', new ObservedStorageBackend($backend, $metrics, 'hot'), defaultWriteTier: true),
            new StorageTier('GLACIER', new ObservedStorageBackend($backend, $metrics, 'cold'), restoreRequired: true),
        ]);
    }

    public function test_factory_rejects_two_filesystem_tiers_with_the_same_path(): void
    {
        $path = sys_get_temp_dir() . '/opsfour-shared-tier-' . bin2hex(random_bytes(4));
        self::assertTrue(mkdir($path, 0700));

        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->expectExceptionMessage('must not share the same physical backend');

            StorageBackendFactory::createTierRegistry([
                'tiers' => [
                    'STANDARD' => ['driver' => 'filesystem', 'path' => $path, 'default' => true],
                    'GLACIER' => ['driver' => 'filesystem', 'path' => $path . '/', 'restore_required' => true],
                ],
            ]);
        } finally {
            rmdir($path);
        }
    }

    public function test_factory_rejects_two_flysystem_tiers_with_the_same_operator(): void
    {
        $operator = $this->createMock(FilesystemOperator::class);

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => ['driver' => 'flysystem', 'filesystem' => $operator, 'default' => true],
                'GLACIER' => ['driver' => 'flysystem', 'filesystem' => $operator, 'restore_required' => true],
            ],
        ]);
    }

    public function test_factory_rejects_overlapping_local_roots_even_when_the_leaf_does_not_exist(): void
    {
        $root = sys_get_temp_dir() . '/opsfour-tier-' . bin2hex(random_bytes(8));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => ['driver' => 'filesystem', 'path' => $root . '/data', 'default' => true],
                'GLACIER' => ['driver' => 'filesystem', 'path' => $root . '/data/../data/cold'],
            ],
        ]);
    }

    public function test_factory_rejects_case_aliases_on_case_insensitive_volume(): void
    {
        $base = sys_get_temp_dir() . '/opsfour-tier-case-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base . '/MiXeD', 0700, true));
        try {
            if (! self::sameInode($base . '/MiXeD', $base . '/mixed')) {
                self::markTestSkipped('The temporary volume is case-sensitive.');
            }

            try {
                StorageBackendFactory::createTierRegistry([
                    'tiers' => [
                        'STANDARD' => ['driver' => 'filesystem', 'path' => $base . '/MiXeD', 'default' => true],
                        'GLACIER' => ['driver' => 'filesystem', 'path' => $base . '/mixed/archive'],
                    ],
                ]);
                self::fail('Case aliases on an insensitive volume must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('must not share the same physical backend', $error->getMessage());
            }
        } finally {
            self::assertTrue(rmdir($base . '/MiXeD'));
            self::assertTrue(rmdir($base));
        }
    }

    public function test_factory_accepts_case_distinct_roots_on_case_sensitive_volume(): void
    {
        $base = sys_get_temp_dir() . '/opsfour-tier-case-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base . '/MiXeD', 0700, true));
        try {
            if (self::sameInode($base . '/MiXeD', $base . '/mixed')) {
                self::markTestSkipped('The temporary volume is case-insensitive.');
            }
            self::assertTrue(mkdir($base . '/mixed'));
            try {
                $registry = StorageBackendFactory::createTierRegistry([
                    'tiers' => [
                        'STANDARD' => ['driver' => 'filesystem', 'path' => $base . '/MiXeD', 'default' => true],
                        'GLACIER' => ['driver' => 'filesystem', 'path' => $base . '/mixed'],
                    ],
                ]);
                try {
                    self::assertCount(2, $registry->all());
                } finally {
                    foreach ($registry->all() as $tier) {
                        if ($tier->backend instanceof ShutdownAwareStorageBackend) {
                            $tier->backend->shutdown();
                        }
                    }
                }
            } finally {
                rmdir($base . '/mixed');
            }
        } finally {
            self::assertTrue(rmdir($base . '/MiXeD'));
            self::assertTrue(rmdir($base));
        }
    }

    public function test_factory_rejects_symlink_alias_of_local_root(): void
    {
        $base = sys_get_temp_dir() . '/opsfour-tier-link-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base . '/storage', 0700, true));
        self::assertTrue(symlink($base . '/storage', $base . '/alias'));
        try {
            try {
                StorageBackendFactory::createTierRegistry([
                    'tiers' => [
                        'STANDARD' => ['driver' => 'filesystem', 'path' => $base . '/storage', 'default' => true],
                        'GLACIER' => ['driver' => 'filesystem', 'path' => $base . '/alias'],
                    ],
                ]);
                self::fail('Symlink aliases must be rejected.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('must not share the same physical backend', $error->getMessage());
            }
        } finally {
            unlink($base . '/alias');
            rmdir($base . '/storage');
            rmdir($base);
        }
    }

    public function test_factory_rejects_local_flysystem_factory_alias_of_filesystem_root(): void
    {
        $root = getcwd() . '/storage-tier-alias-' . bin2hex(random_bytes(8));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => ['driver' => 'filesystem', 'path' => $root, 'default' => true],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new LocalFlysystemFilesystemFactory('./' . basename($root)),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);
    }

    public function test_factory_rejects_separate_local_flysystem_factories_for_overlapping_roots(): void
    {
        $root = sys_get_temp_dir() . '/opsfour-tier-' . bin2hex(random_bytes(8));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new LocalFlysystemFilesystemFactory($root . '/hot'),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new LocalFlysystemFilesystemFactory($root . '/hot/archive'),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);
    }

    public function test_worker_mode_checks_used_factory_not_ignored_operator(): void
    {
        $root = sys_get_temp_dir() . '/opsfour-tier-' . bin2hex(random_bytes(8));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem' => $this->createMock(FilesystemOperator::class),
                    'filesystem_factory' => new LocalFlysystemFilesystemFactory($root),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem' => $this->createMock(FilesystemOperator::class),
                    'filesystem_factory' => new LocalFlysystemFilesystemFactory($root),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);
    }

    public function test_factory_rejects_equal_aws_namespaces_despite_different_credentials_and_timeouts(): void
    {
        $first = new AwsS3FlysystemFilesystemFactory(
            'physical-bucket',
            'eu-west-1',
            'first-key',
            'first-secret',
            'https://S3.example.test:443/',
            prefix: 'objects/',
        );
        $second = new AwsS3FlysystemFilesystemFactory(
            'physical-bucket',
            'us-east-1',
            'second-key',
            'second-secret',
            'https://s3.example.test',
            prefix: 'objects',
            readTimeoutSeconds: 30,
        );

        try {
            StorageBackendFactory::createTierRegistry([
                'tiers' => [
                    'STANDARD' => ['driver' => 'flysystem', 'filesystem_factory' => $first, 'worker_pool_size' => 1, 'default' => true],
                    'GLACIER' => ['driver' => 'flysystem', 'filesystem_factory' => $second, 'worker_pool_size' => 1],
                ],
            ]);
            self::fail('Overlapping S3 namespaces must be rejected.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('must not share the same physical backend', $error->getMessage());
            self::assertStringNotContainsString('first-secret', $error->getMessage());
            self::assertStringNotContainsString('second-secret', $error->getMessage());
        }
    }

    public function test_factory_rejects_nested_aws_prefixes(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: 'objects'),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: 'objects/archive'),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);
    }

    public function test_factory_accepts_distinct_aws_prefixes(): void
    {
        $registry = StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: 'hot'),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: 'cold'),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);

        try {
            self::assertCount(2, $registry->all());
        } finally {
            foreach ($registry->all() as $tier) {
                if ($tier->backend instanceof ShutdownAwareStorageBackend) {
                    $tier->backend->shutdown();
                }
            }
        }
    }

    public function test_factory_rejects_empty_aws_prefix_overlapping_a_child(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret'),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: 'archive'),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);
    }

    public function test_factory_accepts_empty_and_slash_aws_prefixes_as_distinct(): void
    {
        $registry = StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret'),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: '/'),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);

        try {
            self::assertCount(2, $registry->all());
        } finally {
            foreach ($registry->all() as $tier) {
                if ($tier->backend instanceof ShutdownAwareStorageBackend) {
                    $tier->backend->shutdown();
                }
            }
        }
    }

    public function test_factory_rejects_slash_aws_prefix_overlapping_slash_child(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('must not share the same physical backend');

        StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: '/'),
                    'worker_pool_size' => 1,
                    'default' => true,
                ],
                'GLACIER' => [
                    'driver' => 'flysystem',
                    'filesystem_factory' => new AwsS3FlysystemFilesystemFactory('bucket', 'region', 'key', 'secret', prefix: '/archive'),
                    'worker_pool_size' => 1,
                ],
            ],
        ]);
    }

    public function test_collision_rolls_back_constructed_worker_tier_before_clean_retry(): void
    {
        $factory = new LocalFlysystemFilesystemFactory(sys_get_temp_dir() . '/opsfour-tier-rollback-' . bin2hex(random_bytes(8)));
        try {
            StorageBackendFactory::createTierRegistry([
                'tiers' => [
                    'STANDARD' => ['driver' => 'flysystem', 'filesystem_factory' => $factory, 'worker_pool_size' => 1, 'default' => true],
                    'GLACIER' => ['driver' => 'flysystem', 'filesystem_factory' => $factory, 'worker_pool_size' => 1],
                ],
            ]);
            self::fail('The second tier must collide after the first backend was constructed.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('must not share the same physical backend', $error->getMessage());
        }

        $registry = StorageBackendFactory::createTierRegistry([
            'driver' => 'flysystem',
            'filesystem_factory' => $factory,
            'worker_pool_size' => 1,
        ]);
        try {
            self::assertCount(1, $registry->all());
        } finally {
            BootstrapResourceCleanup::release(null, $registry);
        }
    }

    public function test_factory_accepts_distinct_sibling_local_roots(): void
    {
        $root = sys_get_temp_dir() . '/opsfour-tier-' . bin2hex(random_bytes(8));
        $registry = StorageBackendFactory::createTierRegistry([
            'tiers' => [
                'STANDARD' => ['driver' => 'filesystem', 'path' => $root . '/hot', 'default' => true],
                'GLACIER' => ['driver' => 'filesystem', 'path' => $root . '/cold'],
            ],
        ]);

        try {
            self::assertCount(2, $registry->all());
        } finally {
            foreach ($registry->all() as $tier) {
                if ($tier->backend instanceof ShutdownAwareStorageBackend) {
                    $tier->backend->shutdown();
                }
            }
        }
    }

    public function test_unknown_tier_is_rejected(): void
    {
        $registry = StorageTierRegistry::single(new InMemoryBackend());

        $this->expectException(\InvalidArgumentException::class);
        $registry->tier('MISSING');
    }

    public function test_tier_name_must_fit_persisted_metadata_columns(): void
    {
        $registry = StorageTierRegistry::single(new InMemoryBackend(), str_repeat('a', 64));
        self::assertSame(64, strlen($registry->defaultTier()->name));

        $this->expectException(\InvalidArgumentException::class);
        StorageTierRegistry::single(new InMemoryBackend(), str_repeat('a', 65));
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('numericTierNames')]
    public function test_numeric_string_tier_names_remain_exact_strings(string $name): void
    {
        $backend = new InMemoryBackend();
        $registry = new StorageTierRegistry([new StorageTier($name, $backend)]);

        self::assertSame($name, $registry->defaultTier()->name);
        self::assertTrue($registry->has($name));
        self::assertSame($backend, $registry->tier($name)->backend);
        self::assertSame([$name], array_map(static fn(StorageTier $tier): string => $tier->name, $registry->all()));
    }

    /** @return iterable<string, array{string}> */
    public static function numericTierNames(): iterable
    {
        yield 'positive integer' => ['123'];
        yield 'zero' => ['0'];
        yield 'negative integer' => ['-1'];
        yield 'leading zero' => ['01'];
    }

    private static function sameInode(string $first, string $second): bool
    {
        $one = @lstat($first);
        $two = @lstat($second);

        return $one !== false && $two !== false
            && $one['dev'] === $two['dev'] && $one['ino'] === $two['ino'];
    }
}
