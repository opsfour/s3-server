<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Server\RequestHandler;
use League\Flysystem\FilesystemOperator;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\Crc64NvmeObjectMetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaAdmission;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\Runtime\MetadataDriverStartupGuard;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

final class StorageWriteFenceStartupGuardTest extends TestCase
{
    public function test_documented_raw_filesystem_bootstrap_fails_before_listener_or_storage_ownership(): void
    {
        $storageRoot = sys_get_temp_dir() . '/s3-raw-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($storageRoot, 0o700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = new FilesystemBackend($storageRoot);
        $config = new S3ServerConfig(storagePath: $storageRoot, perClientRateLimit: 0);
        $server = new S3Server($config, $metadata, $storage);
        HandlerRegistrar::registerAll($server->getHandlerRegistry(), $metadata, $storage, $config);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected unfenced local publication handlers to be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Local filesystem publication handler PutObject', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertSame([], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
            self::assertDirectoryDoesNotExist($storageRoot . '/.opsfour');
        } finally {
            $server->stop();
            $this->removeDirectory($storageRoot);
        }
    }

    public function test_direct_read_only_filesystem_graph_remains_available(): void
    {
        $storageRoot = sys_get_temp_dir() . '/s3-read-only-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($storageRoot, 0o700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $this->freePort(),
            storagePath: $storageRoot,
            perClientRateLimit: 0,
        ), $metadata, new FilesystemBackend($storageRoot));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::assertNotNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
            $this->removeDirectory($storageRoot);
        }
    }

    public function test_unsafe_test_override_allows_raw_publication_graph_and_logs_warning(): void
    {
        $storageRoot = sys_get_temp_dir() . '/s3-override-bootstrap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($storageRoot, 0o700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = new FilesystemBackend($storageRoot);
        $config = new S3ServerConfig(
            host: '127.0.0.1',
            port: $this->freePort(),
            storagePath: $storageRoot,
            allowUnfencedStorageForTests: true,
            perClientRateLimit: 0,
        );
        $unsafeWarning = false;
        $logger = $this->createMock(LoggerInterface::class);
        $logger->method('log')->willReturnCallback(
            static function (mixed $level, string|\Stringable $message) use (&$unsafeWarning): void {
                if (str_contains((string) $message, 'UNSAFE TEST-ONLY local publication graph override')) {
                    $unsafeWarning = true;
                }
            },
        );
        $server = new S3Server($config, $metadata, $storage, $logger);
        HandlerRegistrar::registerAll($server->getHandlerRegistry(), $metadata, $storage, $config);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::assertTrue($unsafeWarning);
            self::assertNotNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
            $this->removeDirectory($storageRoot);
        }
    }

    public function test_injected_sql_store_cannot_hide_behind_default_sqlite_configuration(): void
    {
        $postgres = (new \ReflectionClass(PostgresMetadataStore::class))->newInstanceWithoutConstructor();
        $wrapped = new CachedMetadataStoreDecorator(new \OpsFour\S3Server\Observability\ObservedMetadataStore(
            $postgres,
            new MetricsCollector(),
            'sqlite',
        ));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('configured metadata driver sqlite does not match injected postgres');
        MetadataDriverStartupGuard::assertMatches($wrapped, 'sqlite');
    }

    public function test_built_in_metadata_drivers_require_matching_configuration(): void
    {
        foreach ([
            [PostgresMetadataStore::class, 'postgres', 'sqlite'],
            [MysqlMetadataStore::class, 'mysql', 'sqlite'],
            [SqliteMetadataStore::class, 'sqlite', 'postgres'],
        ] as [$class, $actual, $wrong]) {
            $store = (new \ReflectionClass($class))->newInstanceWithoutConstructor();
            MetadataDriverStartupGuard::assertMatches($store, $actual);
            try {
                MetadataDriverStartupGuard::assertMatches($store, $wrong);
                self::fail('A mismatched built-in metadata driver was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString("injected {$actual}", $error->getMessage());
            }
        }
    }

    public function test_local_fence_requires_exact_raw_sql_store_and_configured_driver(): void
    {
        $postgres = PostgresMetadataStore::fromDsn('host=127.0.0.1 user=test db=test');
        MetadataDriverStartupGuard::assertRawFencedSqlStore($postgres, 'postgres', 'host=127.0.0.1 user=test db=test');
        MetadataDriverStartupGuard::assertRawFencedSqlStore(
            new \OpsFour\S3Server\Observability\ObservedMetadataStore($postgres, new MetricsCollector(), 'postgres'),
            'postgres',
            'host=127.0.0.1 user=test db=test',
        );
        MetadataDriverStartupGuard::assertRawFencedSqlStore(
            new CachedMetadataStoreDecorator($postgres),
            'postgres',
            'host=127.0.0.1 user=test db=test',
        );

        foreach ([
            [$postgres, 'mysql', 'host=127.0.0.1 user=test db=test'],
            [$postgres, 'postgres', ''],
            [new SqliteMetadataStore(':memory:'), 'sqlite', ''],
        ] as [$metadata, $driver, $dsn]) {
            try {
                MetadataDriverStartupGuard::assertRawFencedSqlStore($metadata, $driver, $dsn);
                self::fail('An unqualified local fence metadata store was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('raw PostgreSQL/MySQL metadata', $error->getMessage());
            }
        }
    }

    public function test_local_fence_rejects_a_store_created_for_a_different_dsn(): void
    {
        foreach ([
            ['postgres', PostgresMetadataStore::fromDsn('host=127.0.0.1 user=test db=first')],
            ['mysql', MysqlMetadataStore::fromDsn('host=127.0.0.1 user=test db=first')],
        ] as [$driver, $store]) {
            MetadataDriverStartupGuard::assertRawFencedSqlStore(
                $store,
                $driver,
                'host=127.0.0.1 user=test db=first',
            );

            try {
                MetadataDriverStartupGuard::assertRawFencedSqlStore(
                    $store,
                    $driver,
                    'host=127.0.0.1 user=test db=second',
                );
                self::fail('A separate metadata database was admitted for local write fencing.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('created from the configured DSN', $error->getMessage());
            }
        }

        $manuallyInjected = new PostgresMetadataStore(new \Amp\Postgres\PostgresConnectionPool(
            \Amp\Postgres\PostgresConfig::fromString('host=127.0.0.1 user=test db=first'),
        ));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('created from the configured DSN');
        MetadataDriverStartupGuard::assertRawFencedSqlStore(
            $manuallyInjected,
            'postgres',
            'host=127.0.0.1 user=test db=first',
        );
    }

    public function test_api_start_rejects_misconfigured_sql_metadata_before_memory_admission_or_bind(): void
    {
        $postgres = (new \ReflectionClass(PostgresMetadataStore::class))->newInstanceWithoutConstructor();
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), $postgres, new InMemoryBackend());
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('A false sqlite configuration admitted PostgreSQL metadata with volatile object bytes.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('does not match injected postgres', $error->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_api_start_checks_injected_schema_before_binding_even_through_decorators(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            MetadataSchemaAdmission::class,
        ]);
        /** @var MetadataStore&MetadataSchemaAdmission&\PHPUnit\Framework\MockObject\MockObject $metadata */
        $metadata->expects(self::once())->method('assertCurrentSchema')
            ->willThrowException(new \RuntimeException('stale injected metadata schema'));
        $wrapped = new CachedMetadataStoreDecorator(new \OpsFour\S3Server\Observability\ObservedMetadataStore(
            $metadata,
            new MetricsCollector(),
            'custom',
        ));
        $server = new S3Server(
            new S3ServerConfig(storagePath: sys_get_temp_dir()),
            $wrapped,
            new InMemoryBackend(),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('A stale injected metadata schema was admitted.');
        } catch (\RuntimeException $error) {
            self::assertSame('stale injected metadata schema', $error->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_builtin_local_backends_are_admitted(): void
    {
        // Only the backend type is inspected; avoid creating unused worker pools.
        $filesystem = $this->filesystemPlaceholder();
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', new InMemoryBackend(), defaultWriteTier: true),
            new StorageTier('LOCAL', $filesystem),
        ]);

        self::assertSame([], StorageWriteFenceStartupGuard::assertReady($tiers, false, 'sqlite', new SqliteMetadataStore(':memory:')));
    }

    public function test_volatile_filesystem_admission_requires_builtin_sqlite_on_the_same_device(): void
    {
        $directory = sys_get_temp_dir() . '/s3-volatile-admission-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($directory, 0700));
        $databaseFile = $directory . '/metadata.sqlite';
        file_put_contents($databaseFile, 'fixture');
        try {
            $metadata = new SqliteMetadataStore($databaseFile);
            $wrapped = new CachedMetadataStoreDecorator(new \OpsFour\S3Server\Observability\ObservedMetadataStore(
                $metadata,
                new MetricsCollector(),
                'sqlite',
            ));
            self::assertTrue(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata($wrapped, $directory));
            self::assertTrue(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata(new SqliteMetadataStore(':memory:'), $directory));
            self::assertFalse(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata(new SqliteMetadataStore($directory . '/missing.sqlite'), $directory));
            self::assertFalse(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata($this->createMock(MetadataStore::class), $directory));
            self::assertFalse(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata(
                (new \ReflectionClass(PostgresMetadataStore::class))->newInstanceWithoutConstructor(),
                $directory,
            ));
            self::assertFalse(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata(
                (new \ReflectionClass(MysqlMetadataStore::class))->newInstanceWithoutConstructor(),
                $directory,
            ));

            if (PHP_OS_FAMILY === 'Linux') {
                $mountInfo = @file_get_contents('/proc/self/mountinfo');
                $type = $mountInfo === false ? null : \OpsFour\S3Server\Runtime\LinuxMountInfo::filesystemTypeForPath($directory, $mountInfo);
                if (in_array($type, ['tmpfs', 'ramfs'], true)) {
                    $tiers = StorageTierRegistry::single($this->filesystemPlaceholder($directory));
                    self::assertSame([], StorageWriteFenceStartupGuard::assertReady($tiers, false, 'sqlite', $wrapped));
                    foreach ([
                        ['postgres', (new \ReflectionClass(PostgresMetadataStore::class))->newInstanceWithoutConstructor()],
                        ['mysql', (new \ReflectionClass(MysqlMetadataStore::class))->newInstanceWithoutConstructor()],
                        ['sqlite', $this->createMock(MetadataStore::class)],
                        ['sqlite', new SqliteMetadataStore($directory . '/missing.sqlite')],
                    ] as [$driver, $unqualifiedMetadata]) {
                        try {
                            StorageWriteFenceStartupGuard::assertReady($tiers, false, $driver, $unqualifiedMetadata);
                            self::fail('Volatile storage was admitted without co-located SQLite metadata.');
                        } catch (\RuntimeException $error) {
                            self::assertStringContainsString('unqualified Linux mount', $error->getMessage());
                        }
                    }
                    $packageManifest = dirname(__DIR__, 3) . '/composer.json';
                    $manifestStat = @stat($packageManifest);
                    $storageStat = @stat($directory);
                    if ($manifestStat !== false && $storageStat !== false && $manifestStat['dev'] !== $storageStat['dev']) {
                        try {
                            StorageWriteFenceStartupGuard::assertReady($tiers, false, 'sqlite', new SqliteMetadataStore($packageManifest));
                            self::fail('Volatile storage was admitted with a metadata file on another device.');
                        } catch (\RuntimeException $error) {
                            self::assertStringContainsString('unqualified Linux mount', $error->getMessage());
                        }
                    }
                }
            }

            $rootDevice = @stat('/');
            $fixtureDevice = @stat($databaseFile);
            if ($rootDevice !== false && $fixtureDevice !== false && $rootDevice['dev'] !== $fixtureDevice['dev']) {
                self::assertFalse(MetadataDriverStartupGuard::hasColocatedVolatileSqliteMetadata($metadata, '/'));
            }
        } finally {
            unlink($databaseFile);
            rmdir($directory);
        }
    }

    public function test_api_start_rejects_in_memory_objects_with_persistent_sqlite_metadata(): void
    {
        $metadata = new SqliteMetadataStore(sys_get_temp_dir() . '/s3-persistent-metadata-' . bin2hex(random_bytes(8)) . '.sqlite');
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), $metadata, new InMemoryBackend());
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Persistent SQLite metadata was admitted with volatile object bytes.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ephemeral in-memory object storage', $error->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_memory_tier_requires_a_test_override_with_postgres_or_mysql_metadata(): void
    {
        $metrics = new MetricsCollector();
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->filesystemPlaceholder(), defaultWriteTier: true),
            new StorageTier('EPHEMERAL', new ObservedStorageBackend(new InMemoryBackend(), $metrics, 'memory')),
        ]);

        foreach (['postgres', 'mysql'] as $driver) {
            try {
                StorageWriteFenceStartupGuard::assertReady($tiers, false, $driver);
                self::fail("Ephemeral tier was admitted with {$driver} metadata.");
            } catch (\RuntimeException $exception) {
                self::assertStringContainsString('storage tier EPHEMERAL', $exception->getMessage());
                self::assertStringContainsString($driver, $exception->getMessage());
            }
            self::assertSame(['EPHEMERAL'], StorageWriteFenceStartupGuard::assertReady($tiers, true, $driver));
        }
    }

    public function test_api_start_refuses_memory_with_mysql_metadata_before_metadata_query_or_bind(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('listReferencedStorageTiers');
        $server = new S3Server(
            new S3ServerConfig(storagePath: sys_get_temp_dir(), metadataDriver: 'mysql'),
            $metadata,
            new InMemoryBackend(),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected memory storage with MySQL metadata to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('ephemeral in-memory', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertSame([], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_every_tier_and_nested_observability_wrapper_is_checked(): void
    {
        $flysystem = new FlysystemBackend($this->createMock(FilesystemOperator::class));
        $metrics = new MetricsCollector();
        $wrapped = new ObservedStorageBackend(
            new ObservedStorageBackend($flysystem, $metrics, 'first'),
            $metrics,
            'second',
        );
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', new InMemoryBackend(), defaultWriteTier: true),
            new StorageTier('UNREFERENCED_COLD', $wrapped),
        ]);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('storage tier UNREFERENCED_COLD');
        StorageWriteFenceStartupGuard::assertReady($tiers, false, 'sqlite', new SqliteMetadataStore(':memory:'));
    }

    public function test_parallel_and_unknown_custom_backends_require_explicit_test_override(): void
    {
        $parallel = (new \ReflectionClass(ParallelFlysystemBackend::class))->newInstanceWithoutConstructor();
        $custom = $this->createMock(StorageBackend::class);
        $tiers = new StorageTierRegistry([
            new StorageTier('REMOTE', $parallel, defaultWriteTier: true),
            new StorageTier('CUSTOM', $custom),
        ]);

        self::assertSame(['REMOTE', 'CUSTOM'], StorageWriteFenceStartupGuard::assertReady($tiers, true, 'sqlite'));
        try {
            StorageWriteFenceStartupGuard::assertReady($tiers, false, 'sqlite');
            self::fail('Expected unfenced storage to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unsafe test-only', $exception->getMessage());
        }
    }

    public function test_api_start_rejects_unreferenced_remote_tier_before_metadata_query_or_bind(): void
    {
        $storageRoot = sys_get_temp_dir() . '/s3-write-fence-preflight-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($storageRoot, 0o700));
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('listReferencedStorageTiers');
        $storage = $this->filesystemPlaceholder($storageRoot);
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), $metadata, $storage);
        $server->setStorageTierRegistry(new StorageTierRegistry([
            new StorageTier('STANDARD', $storage, defaultWriteTier: true),
            new StorageTier('COLD', new FlysystemBackend($this->createMock(FilesystemOperator::class))),
        ]));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected the unfenced cold tier to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('storage tier COLD', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertSame([], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
            self::assertDirectoryDoesNotExist($storageRoot . '/.opsfour');
        } finally {
            $server->stop();
            rmdir($storageRoot);
        }
    }

    public function test_api_start_also_checks_primary_storage_outside_assigned_tier_registry(): void
    {
        $storage = new FlysystemBackend($this->createMock(FilesystemOperator::class));
        $server = new S3Server(new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
        ), storage: $storage);
        $server->setStorageTierRegistry(StorageTierRegistry::single($this->filesystemPlaceholder()));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected the primary storage backend to be checked.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('without a verified late-write fence', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_api_start_checks_storage_captured_by_registered_handlers(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('listReferencedStorageTiers');
        $safeStorage = $this->filesystemPlaceholder();
        $handlerStorage = new FlysystemBackend($this->createMock(FilesystemOperator::class));
        $config = new S3ServerConfig(storagePath: sys_get_temp_dir());
        $server = new S3Server($config, $metadata, $safeStorage);
        HandlerRegistrar::registerAll($server->getHandlerRegistry(), $metadata, $handlerStorage, $config);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected handler storage to be checked.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('without a verified late-write fence', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_api_start_checks_nondefault_tier_captured_by_registered_handlers(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('listReferencedStorageTiers');
        $storage = $this->filesystemPlaceholder();
        $config = new S3ServerConfig(storagePath: sys_get_temp_dir());
        $server = new S3Server($config, $metadata, $storage);
        HandlerRegistrar::registerAll(
            $server->getHandlerRegistry(),
            $metadata,
            $storage,
            $config,
            storageTiers: new StorageTierRegistry([
                new StorageTier('STANDARD', $storage, defaultWriteTier: true),
                new StorageTier('ARCHIVE', new FlysystemBackend($this->createMock(FilesystemOperator::class))),
            ]),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected handler tier storage to be checked.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('storage tier ARCHIVE', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_handler_registry_cannot_be_hot_swapped_after_start(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            allowUnfencedStorageForTests: true,
            perClientRateLimit: 0,
        ), storage: new InMemoryBackend());
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            $this->expectException(\LogicException::class);
            $this->expectExceptionMessage('cannot be changed after the server starts');
            $server->getHandlerRegistry()->register(
                S3Operation::PutObject,
                $this->createMock(RequestHandler::class),
            );
        } finally {
            $server->stop();
        }
    }

    public function test_failed_start_shuts_down_separate_handler_storage_once(): void
    {
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $handlerStorage */
        $handlerStorage = $this->createMockForIntersectionOfInterfaces([
            StorageBackend::class,
            ShutdownAwareStorageBackend::class,
        ]);
        $handlerStorage->expects(self::once())->method('shutdown');
        $config = new S3ServerConfig(storagePath: sys_get_temp_dir());
        $metadata = $this->createMock(MetadataStore::class);
        $server = new S3Server($config, $metadata, $this->filesystemPlaceholder());
        HandlerRegistrar::registerAll($server->getHandlerRegistry(), $metadata, $handlerStorage, $config);
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected custom handler storage to be rejected.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('without a verified late-write fence', $exception->getMessage());
        } finally {
            $server->stop();
        }
    }

    public function test_reentrant_handler_registration_during_metadata_preflight_is_rejected_and_retryable(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $config = new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            notificationWorkerEnabled: false,
            allowUnfencedStorageForTests: true,
        );
        $metadata = $this->capableMetadataMock();
        $storage = new InMemoryBackend();
        $server = new S3Server($config, $metadata, $storage);
        $registry = $server->getHandlerRegistry();
        $firstQuery = true;
        $metadata->method('listReferencedStorageTiers')->willReturnCallback(
            function () use (&$firstQuery, $registry, $metadata, $config): array {
                if ($firstQuery) {
                    $firstQuery = false;
                    HandlerRegistrar::registerAll(
                        $registry,
                        $metadata,
                        new FlysystemBackend($this->createMock(FilesystemOperator::class)),
                        $config,
                    );
                }

                return [];
            },
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            try {
                $server->start();
                self::fail('Expected reentrant unsafe handler registration to be rejected.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('cannot be changed after the server starts', $exception->getMessage());
                self::assertFalse($registry->has(S3Operation::PutObject));
            }

            HandlerRegistrar::registerAll($registry, $metadata, $storage, $config);
            $server->start();
            self::assertTrue($registry->has(S3Operation::PutObject));
        } finally {
            $server->stop();
        }
    }

    public function test_reentrant_tier_swap_during_metadata_preflight_is_rejected(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $config = new S3ServerConfig(host: '127.0.0.1', port: $port, storagePath: sys_get_temp_dir(), allowUnfencedStorageForTests: true);
        $metadata = $this->createMock(MetadataStore::class);
        $storage = new InMemoryBackend();
        $server = new S3Server($config, $metadata, $storage);
        $metadata->method('listReferencedStorageTiers')->willReturnCallback(
            function () use ($server): array {
                $server->setStorageTierRegistry(StorageTierRegistry::single(
                    new FlysystemBackend($this->createMock(FilesystemOperator::class)),
                ));

                return [];
            },
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected reentrant storage tier replacement to be rejected.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('Storage tier registry must be configured before', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_reentrant_stop_during_metadata_preflight_cannot_leave_a_listener_running(): void
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($socket);

        $metadata = $this->capableMetadataMock();
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            allowUnfencedStorageForTests: true,
        ), $metadata, new InMemoryBackend());
        $metadata->method('listReferencedStorageTiers')->willReturnCallback(
            static function () use ($server): array {
                $server->stop();

                return [];
            },
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected shutdown during startup to prevent binding.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('shutdown during startup', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    private function filesystemPlaceholder(string $basePath = '/'): FilesystemBackend
    {
        $filesystem = (new \ReflectionClass(FilesystemBackend::class))->newInstanceWithoutConstructor();
        (new \ReflectionProperty(FilesystemBackend::class, 'basePath'))->setValue($filesystem, $basePath);

        return $filesystem;
    }

    private function capableMetadataMock(): MetadataStore&ObjectPartLayoutStore&Crc64NvmeObjectMetadataStore&MockObject
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            ObjectPartLayoutStore::class,
            Crc64NvmeObjectMetadataStore::class,
        ]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore
            || ! $metadata instanceof Crc64NvmeObjectMetadataStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(true);
        $metadata->method('supportsCrc64NvmeObjectMetadata')->willReturn(true);

        return $metadata;
    }

    private function freePort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
