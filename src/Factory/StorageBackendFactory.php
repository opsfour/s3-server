<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Factory;

use League\Flysystem\FilesystemOperator;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\LocalFlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;

/**
 * Creates StorageBackend instances from configuration.
 */
final class StorageBackendFactory
{
    /**
     * @param  string  $driver  One of: filesystem, flysystem, aws-s3, memory.
     * @param array<string, mixed> $config
     */
    public static function create(
        string $driver,
        array $config = [],
        ?MetricsCollector $metrics = null,
        ?string $metricsDriver = null,
        ?AwsS3ProviderValidator $awsS3ProviderValidator = null,
    ): StorageBackend {
        $backend = match ($driver) {
            'filesystem' => new FilesystemBackend(
                $config['path'] ?? throw new \InvalidArgumentException('Filesystem requires "path"'),
            ),
            'flysystem' => self::createFlysystem($config, $metrics),
            'aws-s3' => self::createAwsS3($config, $awsS3ProviderValidator),
            'memory' => new InMemoryBackend(),
            default => throw new \InvalidArgumentException("Unknown storage driver: {$driver}"),
        };

        if ($metrics === null) {
            return $backend;
        }

        // The admission-only AWS backend intentionally exposes no operational
        // capability. Generic observability would falsely add recoverable
        // write, multipart, and bounded-copy interfaces.
        return $backend instanceof AwsS3StorageBackend
            ? $backend
            : new ObservedStorageBackend($backend, $metrics, $metricsDriver ?? $driver);
    }

    /** @param array<string, mixed> $config */
    private static function createAwsS3(
        array $config,
        ?AwsS3ProviderValidator $validator = null,
    ): StorageBackend {
        $binding = AwsS3ProviderValidatedBinding::fromConfiguration(
            $config,
            $validator ?? new AwsS3ProviderValidator(),
        );
        return new AwsS3StorageBackend($binding->admission, $binding->admission->providerAdmitted ? $binding : null);
    }

    /**
     * @param array{filesystem?: FilesystemOperator, filesystem_factory?: FlysystemFilesystemFactory, temp_dir?: string, temp_budget_bytes?: int|string, worker_pool_size?: int|string, pool_name?: string, read_first_byte_timeout_seconds?: int|string} $config
     */
    private static function createFlysystem(array $config, ?MetricsCollector $metrics): StorageBackend
    {
        $tempDir = $config['temp_dir'] ?? sys_get_temp_dir();
        $tempBudgetBytes = filter_var(
            $config['temp_budget_bytes'] ?? 10_737_418_240,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($tempBudgetBytes === false) {
            throw new \InvalidArgumentException('Flysystem temp_budget_bytes must be a positive decimal integer.');
        }
        $workerPoolSize = filter_var(
            $config['worker_pool_size'] ?? 0,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        if ($workerPoolSize === false) {
            throw new \InvalidArgumentException('Flysystem worker_pool_size must be a decimal integer >= 0.');
        }
        $firstByteTimeoutSeconds = filter_var(
            $config['read_first_byte_timeout_seconds'] ?? 20,
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );
        if ($firstByteTimeoutSeconds === false) {
            throw new \InvalidArgumentException('Flysystem read_first_byte_timeout_seconds must be a positive decimal integer.');
        }
        if ($workerPoolSize > 0) {
            return new ParallelFlysystemBackend(
                $config['filesystem_factory'] ?? throw new \InvalidArgumentException(
                    'Flysystem worker mode requires a serializable "filesystem_factory".',
                ),
                $tempDir,
                $workerPoolSize,
                $metrics,
                poolName: (string) ($config['pool_name'] ?? 'flysystem'),
                tempBudgetBytes: $tempBudgetBytes,
                firstByteTimeoutSeconds: $firstByteTimeoutSeconds,
            );
        }

        return new FlysystemBackend(
            $config['filesystem'] ?? throw new \InvalidArgumentException(
                'Flysystem driver requires a "filesystem" key containing a League\Flysystem\FilesystemOperator instance. '
                . 'Register it in your service provider: $this->app->when(StorageBackendFactory::class)->give(["filesystem" => $operator]);',
            ),
            $tempDir,
            new StorageTempBudgetCoordinator($tempDir, $tempBudgetBytes),
        );
    }

    /**
     * @param array<string, mixed> $storageConfig
     */
    public static function createTierRegistry(
        array $storageConfig,
        ?MetricsCollector $metrics = null,
    ): StorageTierRegistry {
        $tiers = $storageConfig['tiers'] ?? null;
        if (is_array($tiers) && $tiers !== []) {
            $configured = [];
            $created = [];
            $physicalIdentities = [];
            $tierPlans = [];
            try {
                foreach ($tiers as $name => $tierConfig) {
                    if (! is_array($tierConfig)) {
                        throw new \InvalidArgumentException("Storage tier {$name} must be configured as an array.");
                    }

                    $tierName = is_string($name) ? $name : (string) ($tierConfig['name'] ?? '');
                    $driver = (string) ($tierConfig['driver'] ?? $storageConfig['driver'] ?? 'filesystem');
                    $physicalIdentity = self::configuredPhysicalIdentity($driver, $tierConfig);
                    if ($physicalIdentity !== null) {
                        foreach ($physicalIdentities as [$otherName, $otherIdentity]) {
                            if (self::sharesPhysicalNamespace($physicalIdentity, $otherIdentity)) {
                                throw new \InvalidArgumentException(
                                    "Storage tiers {$otherName} and {$tierName} must not share the same physical backend.",
                                );
                            }
                        }
                        $physicalIdentities[] = [$tierName, $physicalIdentity];
                    }
                    $tierPlans[] = [$tierName, $driver, $tierConfig];
                }

                foreach ($tierPlans as [$tierName, $driver, $tierConfig]) {
                    $tierConfig['pool_name'] ??= 'flysystem_' . strtolower($tierName);
                    $tierConfig['temp_budget_bytes'] ??= $storageConfig['temp_budget_bytes'] ?? 10_737_418_240;
                    $tierConfig['read_first_byte_timeout_seconds'] ??= $storageConfig['read_first_byte_timeout_seconds'] ?? 20;
                    $backend = self::create($driver, $tierConfig, $metrics, "{$driver}:{$tierName}");
                    $created[] = $backend;
                    $configured[] = new StorageTier(
                        name: $tierName,
                        backend: $backend,
                        restoreRequired: (bool) ($tierConfig['restore_required'] ?? false),
                        defaultWriteTier: (bool) ($tierConfig['default'] ?? false),
                    );
                }

                return new StorageTierRegistry($configured);
            } catch (\Throwable $error) {
                foreach (array_reverse($created) as $backend) {
                    if ($backend instanceof ShutdownAwareStorageBackend) {
                        try {
                            $backend->shutdown();
                        } catch (\Throwable) {
                        }
                    }
                }
                throw $error;
            }
        }

        $driver = (string) ($storageConfig['driver'] ?? 'filesystem');
        $backend = self::create($driver, $storageConfig, $metrics);

        try {
            return StorageTierRegistry::single(
                $backend,
                (string) ($storageConfig['tier'] ?? 'STANDARD'),
            );
        } catch (\Throwable $error) {
            if ($backend instanceof ShutdownAwareStorageBackend) {
                try {
                    $backend->shutdown();
                } catch (\Throwable) {
                }
            }
            throw $error;
        }
    }

    /**
     * @param array<string, mixed> $config
     * @return array{kind: 'local', root: string, existingAncestor: string}|array{kind: 'aws', endpoint: ?string, bucket: string, prefixes: list<string>}|array{kind: 'object', id: int}|null
     */
    private static function configuredPhysicalIdentity(string $driver, array $config): ?array
    {
        if ($driver === 'filesystem' && is_string($config['path'] ?? null)) {
            return self::localPhysicalIdentity($config['path']);
        }
        if ($driver === 'flysystem') {
            $workerPoolSize = filter_var(
                $config['worker_pool_size'] ?? 0,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 0]],
            );
            $operator = $workerPoolSize !== false && $workerPoolSize > 0
                ? ($config['filesystem_factory'] ?? null)
                : ($config['filesystem'] ?? null);
            if ($operator instanceof LocalFlysystemFilesystemFactory) {
                return self::localPhysicalIdentity($operator->physicalRootPath());
            }
            if ($operator instanceof AwsS3FlysystemFilesystemFactory) {
                $namespace = $operator->physicalNamespace();

                return [
                    'kind' => 'aws',
                    'endpoint' => self::canonicalEndpoint($namespace['endpoint']),
                    'bucket' => $namespace['bucket'],
                    'prefixes' => [$namespace['prefix']],
                ];
            }
            if (is_object($operator)) {
                return ['kind' => 'object', 'id' => spl_object_id($operator)];
            }
        }
        if ($driver === 'aws-s3') {
            try {
                $profile = AwsS3ProviderProfile::fromConfig($config);
            } catch (\InvalidArgumentException) {
                return null;
            }

            return [
                'kind' => 'aws',
                'endpoint' => null,
                'bucket' => $profile->bucket,
                'prefixes' => [$profile->dataPrefix, $profile->reservedPrefix],
            ];
        }

        return null;
    }

    /**
     * @param array{kind: 'local', root: string, existingAncestor: string}|array{kind: 'aws', endpoint: ?string, bucket: string, prefixes: list<string>}|array{kind: 'object', id: int} $first
     * @param array{kind: 'local', root: string, existingAncestor: string}|array{kind: 'aws', endpoint: ?string, bucket: string, prefixes: list<string>}|array{kind: 'object', id: int} $second
     */
    private static function sharesPhysicalNamespace(array $first, array $second): bool
    {
        if ($first['kind'] === 'local' && $second['kind'] === 'local') {
            if (self::pathContains($first['root'], $second['root'])
                || self::pathContains($second['root'], $first['root'])) {
                return true;
            }
            $firstFolded = strtolower($first['root']);
            $secondFolded = strtolower($second['root']);
            if (! self::pathContains($firstFolded, $secondFolded)
                && ! self::pathContains($secondFolded, $firstFolded)) {
                return false;
            }

            return self::isCaseInsensitiveDirectory($first['existingAncestor'])
                && self::isCaseInsensitiveDirectory($second['existingAncestor']);
        }
        if ($first['kind'] === 'aws' && $second['kind'] === 'aws') {
            if ($first['endpoint'] !== $second['endpoint'] || $first['bucket'] !== $second['bucket']) {
                return false;
            }
            foreach ($first['prefixes'] as $firstPrefix) {
                foreach ($second['prefixes'] as $secondPrefix) {
                    if (self::prefixContains($firstPrefix, $secondPrefix)
                        || self::prefixContains($secondPrefix, $firstPrefix)) {
                        return true;
                    }
                }
            }

            return false;
        }

        if ($first['kind'] !== 'object' || $second['kind'] !== 'object') {
            return false;
        }

        return $first['id'] === $second['id'];
    }

    private static function pathContains(string $parent, string $child): bool
    {
        return $parent === $child || $parent === '' || $parent === '/'
            || str_starts_with($child, rtrim($parent, '/') . '/');
    }

    private static function prefixContains(string $parent, string $child): bool
    {
        if ($parent === $child) {
            return true;
        }
        if ($parent === '') {
            return ! str_starts_with($child, '/');
        }
        if ($parent === '/') {
            return str_starts_with($child, '/');
        }

        return str_starts_with($child, $parent . '/');
    }

    /** @return array{kind: 'local', root: string, existingAncestor: string}|null */
    private static function localPhysicalIdentity(string $path): ?array
    {
        if ($path === '') {
            return null;
        }
        if (! str_starts_with($path, '/')) {
            $cwd = getcwd();
            if ($cwd === false) {
                return null;
            }
            $path = $cwd . '/' . $path;
        }
        $missing = [];
        while (($resolved = realpath($path)) === false) {
            if ($path === '/') {
                return null;
            }
            array_unshift($missing, basename($path));
            $path = dirname($path);
        }
        $segments = $resolved === '/' ? [] : explode('/', ltrim($resolved, '/'));
        foreach ($missing as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }
            if ($segment === '..') {
                array_pop($segments);
            } else {
                $segments[] = $segment;
            }
        }

        return ['kind' => 'local', 'root' => '/' . implode('/', $segments), 'existingAncestor' => $resolved];
    }

    /** An unpredictable file gives an unambiguous answer on the exact volume. */
    private static function isCaseInsensitiveDirectory(string $directory): bool
    {
        $probe = rtrim($directory, '/') . '/.opsfour-s3-case-probe-' . bin2hex(random_bytes(16));
        $handle = @fopen($probe, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException('Cannot determine storage root case sensitivity.');
        }
        try {
            $variant = dirname($probe) . '/' . strtoupper(basename($probe));
            clearstatcache(true, $variant);
            $probeStat = @lstat($probe);
            $variantStat = @lstat($variant);
            if ($probeStat === false) {
                throw new \RuntimeException('Cannot inspect storage root case probe.');
            }

            return $variantStat !== false
                && $probeStat['dev'] === $variantStat['dev']
                && $probeStat['ino'] === $variantStat['ino'];
        } finally {
            fclose($handle);
            if (! @unlink($probe)) {
                throw new \RuntimeException('Cannot clean up storage root case probe.');
            }
        }
    }

    private static function canonicalEndpoint(?string $endpoint): ?string
    {
        if ($endpoint === null || $endpoint === '') {
            return null;
        }
        $uri = parse_url($endpoint);
        if (! is_array($uri) || ! isset($uri['scheme'], $uri['host'])) {
            return $endpoint;
        }
        $scheme = strtolower($uri['scheme']);
        $port = $uri['port'] ?? null;
        $defaultPort = $scheme === 'https' ? 443 : 80;
        $host = strtolower($uri['host']);
        $path = rtrim($uri['path'] ?? '', '/');

        if (($port === null || $port === $defaultPort) && $path === ''
            && ($host === 's3.amazonaws.com'
                || preg_match('/^s3(?:-fips)?(?:[.-](?:dualstack\.)?[a-z0-9-]+)\.amazonaws\.com$/D', $host) === 1)) {
            return null;
        }

        return $scheme . '://' . $host
            . ($port === null || $port === $defaultPort ? '' : ':' . $port)
            . $path;
    }
}
