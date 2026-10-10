<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream\ReadableBuffer;
use Amp\DeferredFuture;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnection;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\MysqlResult;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\WorkerPool;
use Amp\TimeoutCancellation;
use Amp\Sql\SqlConnector;
use Closure;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\RecursiveCleanupAdmissionStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Routing\HandlerRegistry;
use OpsFour\S3Server\Routing\S3DispatchHandler;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\EfsCandidateBootstrapException;
use OpsFour\S3Server\Runtime\EfsCandidateIngressResources;
use OpsFour\S3Server\Runtime\EfsCandidateManagementResources;
use OpsFour\S3Server\Runtime\EfsCandidateRecoveryCapabilities;
use OpsFour\S3Server\Runtime\EfsCandidateRuntimeResources;
use OpsFour\S3Server\Runtime\EfsLocalStartupEvidenceReader;
use OpsFour\S3Server\Runtime\EfsNodeLocalScratch;
use OpsFour\S3Server\Runtime\EfsQualificationContext;
use OpsFour\S3Server\Runtime\EfsRecursiveCleanupAdmission;
use OpsFour\S3Server\Runtime\EfsStorageStartupPreflight;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\AmpLocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\AmpLocalObjectWriteLeaseSql;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

final class EfsLocalStartupEvidenceReaderTest extends TestCase
{
    private const string RUN_ID = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    private const string SOURCE_SHA = 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb';

    private const string BOOT_ID = '12345678-1234-1234-1234-123456789abc';

    private const string POSTGRES_DSN = 'host=127.0.0.1 user=test db=qualified';

    private const int NOW_NS = 10_000_000_000_000;

    private string $base;

    private string $evidenceDirectory;

    private string $storageRoot;

    private string $evidencePath;

    private int $nowNs = self::NOW_NS;

    private string $bootId = self::BOOT_ID;

    private int $storageStats = 0;

    private ?int $simulatedStorageDevice = null;

    private ?int $simulatedStorageUid = null;

    private ?int $simulatedStorageMode = null;

    protected function setUp(): void
    {
        parent::setUp();
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->base = $temporaryRoot . '/s3-local-evidence-' . bin2hex(random_bytes(6));
        $this->evidenceDirectory = $this->base . '/evidence';
        $this->storageRoot = $this->base . '/storage';
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-postgres.json';
        mkdir($this->base, 0o700);
        mkdir($this->evidenceDirectory, 0o755);
        mkdir($this->storageRoot, 0o700);
        $this->writeRecord($this->record());
    }

    protected function tearDown(): void
    {
        $scratch = $this->base . '/scratch';
        $budget = $scratch . '/.opsfour-s3-storage-budget';
        @unlink($budget . '/budget');
        @unlink($budget . '/lock');
        @rmdir($budget);
        $ingressBudget = $scratch . '/.opsfour-s3-post-budget';
        @unlink($ingressBudget . '/budget');
        @unlink($ingressBudget . '/lock');
        @rmdir($ingressBudget);
        @rmdir($scratch);
        @unlink($this->base . '/scratch-link');
        @unlink($this->evidencePath);
        @rmdir($this->evidenceDirectory);
        @rmdir($this->storageRoot);
        @rmdir($this->base);
        parent::tearDown();
    }

    public function test_local_startup_pins_epoch_without_touching_storage_root(): void
    {
        rmdir($this->storageRoot);
        $reader = $this->reader();
        $reader->assertFresh();
        $scope = $reader->workerScope();

        self::assertSame(str_repeat('e', 32), $scope->pinnedIdentity['epoch']);
        self::assertSame($this->storageRoot, $scope->storageRoot);
        self::assertSame(0, $this->storageStats);
    }

    public function test_preflight_binds_root_dev_inode_to_the_first_epoch(): void
    {
        $reader = $this->reader();
        $firstScope = $reader->workerScope();
        $preflight = new EfsStorageStartupPreflight($reader, fn(): string => $this->mountInfo());

        self::assertSame($this->storageRoot, $preflight->assertExistingRoot($this->storageRoot));
        self::assertSame(1, $this->storageStats);
        self::assertSame($firstScope->pinnedIdentity, $reader->workerScope()->pinnedIdentity);
    }

    public function test_preflight_rejects_root_with_wrong_owner_or_public_permissions(): void
    {
        $uid = posix_geteuid();
        foreach ([
            ['uid' => $uid + 1, 'mode' => 0o040700],
            ['uid' => $uid, 'mode' => 0o040600],
            ['uid' => $uid, 'mode' => 0o041700],
            ['uid' => $uid, 'mode' => 0o040755],
            ['uid' => $uid, 'mode' => 0o040777],
        ] as $case) {
            $this->simulatedStorageUid = $case['uid'];
            $this->simulatedStorageMode = $case['mode'];
            $reader = $this->reader();
            $preflight = new EfsStorageStartupPreflight($reader, fn(): string => $this->mountInfo());
            try {
                $preflight->assertExistingRoot($this->storageRoot);
                self::fail('An EFS root with unsafe ownership or permissions was qualified.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('identity', $error->getMessage());
            }
        }
        self::assertSame(5, $this->storageStats);
    }

    public function test_preflight_rejects_actual_public_storage_root(): void
    {
        self::assertTrue(chmod($this->storageRoot, 0o755));
        try {
            $reader = $this->reader();
            $preflight = new EfsStorageStartupPreflight($reader, fn(): string => $this->mountInfo());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('identity');
            $preflight->assertExistingRoot($this->storageRoot);
        } finally {
            self::assertTrue(chmod($this->storageRoot, 0o700));
        }
    }

    public function test_qualification_context_retains_one_scope_without_later_root_access(): void
    {
        $reader = $this->reader();
        $initial = $reader->workerScope();
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());

        self::assertSame(1, $this->storageStats);
        self::assertSame($this->storageRoot, $context->storageRoot());
        self::assertSame(get_object_vars($initial), get_object_vars($context->workerScope()));
        $context->assertHostEvidenceReader($reader->hostEvidenceReader());
        $other = $this->reader();
        self::assertSame(get_object_vars($initial), get_object_vars($other->workerScope()));
        try {
            $context->assertHostEvidenceReader($other->hostEvidenceReader());
            self::fail('A second watcher with matching scope was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('reader differs', $error->getMessage());
        }
        $context->assertFresh();
        self::assertSame(1, $this->storageStats);
    }

    public function test_candidate_metadata_binding_accepts_only_the_pinned_driver_root_and_dsn(): void
    {
        $context = EfsQualificationContext::qualify($this->reader(), $this->storageRoot, fn(): string => $this->mountInfo());
        $config = $this->candidateConfig();
        $metadata = new ObservedMetadataStore(
            PostgresMetadataStore::fromDsn(self::POSTGRES_DSN),
            new MetricsCollector(),
            'postgres',
        );

        $context->assertCandidateMetadataBinding($config, $metadata);
        self::assertSame(1, $this->storageStats);

        foreach ([
            $config->with(['storagePath' => $this->base]),
            $config->with(['metadataDriver' => 'mysql']),
            $config->with(['metadataDsn' => 'host=127.0.0.1 user=test db=other']),
            $config->with(['allowUnfencedStorageForTests' => true]),
        ] as $wrongConfig) {
            try {
                $context->assertCandidateMetadataBinding($wrongConfig, $metadata);
                self::fail('An EFS candidate with a mismatched profile was accepted.');
            } catch (\RuntimeException) {
                self::assertSame(1, $this->storageStats);
            }
        }
    }

    public function test_candidate_metadata_binding_rejects_wrong_or_cached_store(): void
    {
        $context = EfsQualificationContext::qualify($this->reader(), $this->storageRoot, fn(): string => $this->mountInfo());
        $config = $this->candidateConfig();

        foreach ([
            MysqlMetadataStore::fromDsn('host=127.0.0.1 user=test db=qualified'),
            PostgresMetadataStore::fromDsn('host=127.0.0.1 user=test db=other'),
            new CachedMetadataStoreDecorator(PostgresMetadataStore::fromDsn(self::POSTGRES_DSN), 0.0),
        ] as $wrongMetadata) {
            try {
                $context->assertCandidateMetadataBinding($config, $wrongMetadata);
                self::fail('An EFS candidate with an unbound metadata store was accepted.');
            } catch (\RuntimeException) {
                self::assertSame(1, $this->storageStats);
            }
        }
    }

    public function test_candidate_metadata_binding_rejects_stale_evidence(): void
    {
        $context = EfsQualificationContext::qualify($this->reader(), $this->storageRoot, fn(): string => $this->mountInfo());
        $this->nowNs += 21_000_000_000;

        $this->expectException(\RuntimeException::class);
        $context->assertCandidateMetadataBinding(
            $this->candidateConfig(),
            PostgresMetadataStore::fromDsn(self::POSTGRES_DSN),
        );
    }

    public function test_candidate_storage_binding_requires_same_guarded_backend_and_single_handler_tier(): void
    {
        $reader = $this->reader();
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $readPool = new ContextWorkerPool(2);
        $guarded = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $reader->hostEvidenceReader(),
            efsReadPool: $readPool,
            isolatedEfsRead: true,
            requireIsolatedReadSession: true,
        );
        $observed = new ObservedStorageBackend($guarded, new MetricsCollector(), 'filesystem');
        $otherGuarded = new FilesystemBackend($this->storageRoot, efsEvidence: $this->reader()->hostEvidenceReader());
        $unisolated = new FilesystemBackend($this->storageRoot, efsEvidence: $reader->hostEvidenceReader());
        $isolatedButUnfenced = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $reader->hostEvidenceReader(),
            efsReadPool: $readPool,
            isolatedEfsRead: true,
        );
        $stoppedPool = new ContextWorkerPool(2);
        $stoppedBackend = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $reader->hostEvidenceReader(),
            efsReadPool: $stoppedPool,
            isolatedEfsRead: true,
            requireIsolatedReadSession: true,
        );
        $stoppedPool->shutdown();
        $unguarded = new FilesystemBackend($this->storageRoot);
        $wrongRoot = new FilesystemBackend($this->base);

        try {
            $context->assertCandidateStorageBinding($observed, StorageTierRegistry::single($observed));
            self::assertSame(1, $this->storageStats);

            foreach ([
                [$unguarded, StorageTierRegistry::single($unguarded)],
                [$otherGuarded, StorageTierRegistry::single($otherGuarded)],
                [$unisolated, StorageTierRegistry::single($unisolated)],
                [$isolatedButUnfenced, StorageTierRegistry::single($isolatedButUnfenced)],
                [$stoppedBackend, StorageTierRegistry::single($stoppedBackend)],
                [$wrongRoot, StorageTierRegistry::single($wrongRoot)],
                [$observed, StorageTierRegistry::single($guarded)],
                [$observed, new StorageTierRegistry([
                    new StorageTier('STANDARD', $observed, defaultWriteTier: true),
                    new StorageTier('ARCHIVE', new InMemoryBackend()),
                ])],
                [new InMemoryBackend(), StorageTierRegistry::single(new InMemoryBackend())],
            ] as [$backend, $tiers]) {
                try {
                    $context->assertCandidateStorageBinding($backend, $tiers);
                    self::fail('An unbound EFS candidate backend or tier was accepted.');
                } catch (\RuntimeException) {
                    self::assertSame(1, $this->storageStats);
                }
            }
        } finally {
            $guarded->shutdown();
            $otherGuarded->shutdown();
            $unisolated->shutdown();
            $isolatedButUnfenced->shutdown();
            $stoppedBackend->shutdown();
            $unguarded->shutdown();
            $wrongRoot->shutdown();
            $readPool->shutdown();
        }
    }

    public function test_candidate_storage_binding_rejects_stale_watcher_before_storage_access(): void
    {
        $reader = $this->reader();
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $readPool = new ContextWorkerPool(2);
        $backend = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $reader->hostEvidenceReader(),
            efsReadPool: $readPool,
            isolatedEfsRead: true,
            requireIsolatedReadSession: true,
        );
        $this->nowNs += 21_000_000_000;

        try {
            $this->expectException(\RuntimeException::class);
            $context->assertCandidateStorageBinding($backend, StorageTierRegistry::single($backend));
        } finally {
            $backend->shutdown();
            $readPool->shutdown();
            self::assertSame(1, $this->storageStats);
        }
    }

    public function test_candidate_runtime_inputs_bind_one_context_across_sql_backend_and_scratch(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        $this->writeRecord($this->record());
        $reader = $this->reader();
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $otherContext = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $readPool = new ContextWorkerPool(2);
        $backend = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $reader->hostEvidenceReader(),
            efsReadPool: $readPool,
            isolatedEfsRead: true,
            requireIsolatedReadSession: true,
        );
        $tiers = StorageTierRegistry::single($backend);
        $metadata = PostgresMetadataStore::fromDsn(self::POSTGRES_DSN);
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());

        try {
            $context->assertCandidateRuntimeInputs($this->candidateConfig(), $metadata, $backend, $tiers, $scratch);
            self::assertSame(2, $this->storageStats);

            foreach ([
                fn() => $otherContext->assertCandidateRuntimeInputs($this->candidateConfig(), $metadata, $backend, $tiers, $scratch),
                fn() => $context->assertCandidateRuntimeInputs(
                    $this->candidateConfig()->with(['allowUnfencedStorageForTests' => true]),
                    $metadata,
                    $backend,
                    $tiers,
                    $scratch,
                ),
                fn() => $context->assertCandidateRuntimeInputs(
                    $this->candidateConfig(),
                    PostgresMetadataStore::fromDsn('host=127.0.0.1 user=test db=other'),
                    $backend,
                    $tiers,
                    $scratch,
                ),
                fn() => $context->assertCandidateRuntimeInputs(
                    $this->candidateConfig(),
                    $metadata,
                    $backend,
                    StorageTierRegistry::single(new InMemoryBackend()),
                    $scratch,
                ),
            ] as $invalid) {
                try {
                    $invalid();
                    self::fail('Mixed EFS candidate inputs were accepted.');
                } catch (\RuntimeException) {
                    self::assertSame(2, $this->storageStats);
                }
            }
        } finally {
            $backend->shutdown();
            $readPool->shutdown();
        }
    }

    public function test_node_local_scratch_qualification_pins_private_path_and_shared_budget(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0o700));
        $capability = EfsNodeLocalScratch::qualify($context, $scratch, fn(): string => $this->mountInfo());

        self::assertSame($scratch, $capability->path());
        self::assertSame(10_737_418_240, $capability->budgetBytes());
        self::assertSame('10737418240', file_get_contents($scratch . '/.opsfour-s3-storage-budget/budget'));
        self::assertSame(1, $this->storageStats);

        $this->nowNs += 21_000_000_000;
        $this->expectException(\RuntimeException::class);
        $capability->path();
    }

    public function test_candidate_ingress_binds_all_spools_to_one_qualified_scratch_and_owner(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $path = $this->base . '/scratch';
        self::assertTrue(mkdir($path, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $path, fn(): string => $this->mountInfo());
        $metadata = PostgresMetadataStore::fromDsn(self::POSTGRES_DSN);
        $provider = new InMemoryCredentialProvider();
        $resources = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
            $context,
            $scratch,
            $this->candidateConfig(),
            $metadata,
            $provider,
        );

        try {
            $resources->assertBoundTo($context, $scratch);
            self::assertSame($path, $resources->tempDir);
            self::assertSame(EfsNodeLocalScratch::BUDGET_BYTES, $resources->budgetBytes);
            self::assertSame($path, $resources->coordinator->tempDir());
            self::assertTrue($resources->workerPool->isRunning());
            $resources->assertCredentialProviderBinding($provider);
            $resources->auth->assertQualifiedCredentialProviderBinding($provider);

            $foreignProvider = new InMemoryCredentialProvider();
            foreach ([
                static fn() => $resources->assertCredentialProviderBinding($foreignProvider),
                static fn() => $resources->auth->assertQualifiedCredentialProviderBinding($foreignProvider),
            ] as $foreignBinding) {
                try {
                    $foreignBinding();
                    self::fail('A foreign EFS credential provider was accepted.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('credential provider differs', $error->getMessage());
                }
            }
            $formParser = (new \ReflectionProperty($resources->auth, 'postObjectFormParser'))->getValue($resources->auth);
            self::assertInstanceOf(\OpsFour\S3Server\Auth\PostObjectFormParser::class, $formParser);
            $formParser->assertQualifiedCredentialProviderBinding($provider);
            try {
                $formParser->assertQualifiedCredentialProviderBinding($foreignProvider);
                self::fail('Browser POST accepted a foreign credential provider.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('credential provider differs', $error->getMessage());
            }

            $otherContext = $this->contextWithSeparateStorageDevice();
            try {
                $resources->assertBoundTo($otherContext, $scratch);
                self::fail('Foreign EFS ingress qualification was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('different qualification', $error->getMessage());
            }

            try {
                $resources->requestBodySpool->assertQualifiedBinding(
                    $this->base . '/other-scratch',
                    $resources->budgetBytes,
                    $resources->coordinator,
                    $resources->workerPool,
                );
                self::fail('An ingress spool on another path was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('request-body spool', $error->getMessage());
            }
        } finally {
            $resources->shutdown();
            $resources->shutdown();
        }
        self::assertFalse($resources->workerPool->isRunning());
        $this->expectException(\RuntimeException::class);
        $resources->assertCredentialProviderBinding($provider);
    }

    public function test_candidate_ingress_rejects_budget_and_revoked_scratch_before_pool_creation(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $path = $this->base . '/scratch';
        self::assertTrue(mkdir($path, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $path, fn(): string => $this->mountInfo());
        $metadata = PostgresMetadataStore::fromDsn(self::POSTGRES_DSN);
        $factory = new S3ServerRuntimeFactory();

        try {
            $factory->createQualifiedEfsCandidateIngressResources(
                $context,
                $scratch,
                $this->candidateConfig()->with(['postSpoolBudgetBytes' => 1]),
                $metadata,
                new InMemoryCredentialProvider(),
            );
            self::fail('A different ingress budget was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('budget', $error->getMessage());
        }
        $this->nowNs += 21_000_000_000;
        try {
            $factory->createQualifiedEfsCandidateIngressResources(
                $context,
                $scratch,
                $this->candidateConfig(),
                $metadata,
                new InMemoryCredentialProvider(),
            );
            self::fail('Revoked EFS scratch was accepted.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->storageStats);
        }
    }

    public function test_candidate_ingress_partial_construction_rejects_unsafe_budget_directory(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $path = $this->base . '/scratch';
        self::assertTrue(mkdir($path, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $path, fn(): string => $this->mountInfo());
        $metadata = PostgresMetadataStore::fromDsn(self::POSTGRES_DSN);
        $unsafeBudget = $path . '/.opsfour-s3-post-budget';
        self::assertTrue(symlink($this->base, $unsafeBudget));

        try {
            (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
                $context,
                $scratch,
                $this->candidateConfig(),
                $metadata,
                new InMemoryCredentialProvider(),
            );
            self::fail('An unsafe ingress reservation directory was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('private', $error->getMessage());
        } finally {
            self::assertTrue(unlink($unsafeBudget));
        }
    }

    public function test_candidate_ingress_refuses_pool_shutdown_with_active_spools(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $path = $this->base . '/scratch';
        self::assertTrue(mkdir($path, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $path, fn(): string => $this->mountInfo());
        $resources = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
            $context,
            $scratch,
            $this->candidateConfig(),
            PostgresMetadataStore::fromDsn(self::POSTGRES_DSN),
            new InMemoryCredentialProvider(),
        );
        $tempPath = tempnam($path, 's3-post-');
        self::assertIsString($tempPath);
        $reservation = $resources->coordinator->start($tempPath);
        try {
            try {
                $resources->shutdown();
                self::fail('An active browser POST reservation was closed with its pool.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('active temporary reservations', $error->getMessage());
            }
            self::assertTrue($resources->workerPool->isRunning());
        } finally {
            self::assertTrue(unlink($tempPath));
            $resources->coordinator->release($reservation, true);
        }

        $keys = new \ReflectionProperty($resources->requestBodySpool, 'keys');
        $keys->setValue($resources->requestBodySpool, ['synthetic-active-spool' => str_repeat('a', 32)]);
        try {
            $resources->shutdown();
            self::fail('An active request-body spool was closed with its pool.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('active files or keys', $error->getMessage());
        } finally {
            $keys->setValue($resources->requestBodySpool, []);
        }
        $resources->shutdown();
        self::assertFalse($resources->workerPool->isRunning());
    }

    public function test_candidate_ingress_attach_seals_empty_server_and_retains_one_pool_owner(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $server = new S3Server($config);
        try {
            try {
                $ingress->transferOwnershipTo($server);
                self::fail('A direct ownership transfer without server attachment was accepted.');
            } catch (\LogicException) {
                self::assertTrue($ingress->workerPool->isRunning());
            }

            $server->attachQualifiedEfsCandidateIngress($graph, $ingress);
            self::assertTrue($server->ownsQualifiedEfsCandidateIngress($ingress));
            self::assertTrue($server->isConfigurationSealedForQualifiedEfsCandidate());
            try {
                $ingress->markServerPoolClosed($server);
                self::fail('A live EFS ingress worker pool lost its owner.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('still running', $error->getMessage());
                self::assertTrue($ingress->workerPool->isRunning());
            }
            self::assertSame(
                $ingress->requestBodySpool,
                (new \ReflectionMethod($server, 'requestBodySpoolForStack'))->invoke($server),
            );
            (new \ReflectionMethod($server, 'buildMiddlewareStack'))->invoke(
                $server,
                new S3DispatchHandler($server->getHandlerRegistry()),
            );
            self::assertCount(1, (new \ReflectionProperty($server, 'workerPools'))->getValue($server));

            foreach ([
                static fn() => $ingress->shutdown(),
                static fn() => $server->attachQualifiedEfsCandidateIngress($graph, $ingress),
                static fn() => $server->addMiddleware($ingress->auth),
            ] as $invalid) {
                try {
                    $invalid();
                    self::fail('An EFS ingress owner or sealed pipeline was changed.');
                } catch (\LogicException) {
                    self::assertTrue($ingress->workerPool->isRunning());
                }
            }

            try {
                $server->start();
                self::fail('Attached EFS ingress opened a listener.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('not yet admitted', $error->getMessage());
            }
        } finally {
            $server->stop();
            $graph->shutdown();
        }
        self::assertFalse($ingress->workerPool->isRunning());
        $ingress->shutdown();
    }

    public function test_candidate_ingress_server_stop_retains_pool_until_active_spool_drains(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $server = new S3Server($config);
        $server->attachQualifiedEfsCandidateIngress($graph, $ingress);
        $keys = new \ReflectionProperty($ingress->requestBodySpool, 'keys');
        $keys->setValue($ingress->requestBodySpool, ['synthetic-active-spool' => str_repeat('a', 32)]);
        try {
            try {
                $server->stop();
                self::fail('Server closed an active EFS ingress worker pool.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('shutdown incomplete', $error->getMessage());
            }
            self::assertTrue($ingress->workerPool->isRunning());
            $keys->setValue($ingress->requestBodySpool, []);
            $server->stop();
            self::assertFalse($ingress->workerPool->isRunning());
        } finally {
            if ($ingress->workerPool->isRunning()) {
                $keys->setValue($ingress->requestBodySpool, []);
                $server->stop();
            }
            $graph->shutdown();
        }
    }

    public function test_candidate_ingress_attach_rejects_foreign_bundle_and_partial_server_configuration(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $foreignContext = $this->contextWithSeparateStorageDevice('mysql');
        $foreignScratch = EfsNodeLocalScratch::qualify(
            $foreignContext,
            $this->base . '/scratch',
            fn(): string => $this->mountInfo(),
        );
        $foreignIngress = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
            $foreignContext,
            $foreignScratch,
            $config,
            $graph->metadata,
            new InMemoryCredentialProvider(),
        );
        $server = new S3Server($config);
        try {
            try {
                $server->attachQualifiedEfsCandidateIngress($graph, $foreignIngress);
                self::fail('An ingress bundle from another qualification was attached.');
            } catch (\RuntimeException) {
                self::assertFalse($server->isConfigurationSealedForQualifiedEfsCandidate());
                self::assertSame([], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
            }
            $foreignIngress->shutdown();

            $server->addMiddleware($ingress->auth);
            try {
                $server->attachQualifiedEfsCandidateIngress($graph, $ingress);
                self::fail('A partially configured server accepted an ingress owner.');
            } catch (\LogicException) {
                self::assertFalse($server->isConfigurationSealedForQualifiedEfsCandidate());
                self::assertTrue($ingress->workerPool->isRunning());
            }
        } finally {
            $server->stop();
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_ingress_attach_rejects_same_dsn_different_metadata_instance(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $otherMetadata = MysqlMetadataStore::fromDsn(self::POSTGRES_DSN);
        $otherIngress = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
            $graph->qualification,
            $graph->scratch,
            $config,
            $otherMetadata,
            new InMemoryCredentialProvider(),
        );
        $server = new S3Server($config);
        try {
            try {
                $server->attachQualifiedEfsCandidateIngress($graph, $otherIngress);
                self::fail('An ingress bound to another SQL store instance was attached.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('metadata differs', $error->getMessage());
                self::assertFalse($server->isConfigurationSealedForQualifiedEfsCandidate());
                self::assertTrue($otherIngress->workerPool->isRunning());
            }
        } finally {
            $server->stop();
            $otherIngress->shutdown();
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_ingress_attach_rejects_graph_built_with_different_config_object(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $otherConfig = $config->with(['lifecycleIntervalSeconds' => $config->lifecycleIntervalSeconds + 1.0]);
        $otherIngress = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
            $graph->qualification,
            $graph->scratch,
            $otherConfig,
            $graph->metadata,
            new InMemoryCredentialProvider(),
        );
        $server = new S3Server($otherConfig);
        try {
            try {
                $server->attachQualifiedEfsCandidateIngress($graph, $otherIngress);
                self::fail('An ingress paired with a graph built for another configuration was attached.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('another server configuration', $error->getMessage());
                self::assertFalse($server->isConfigurationSealedForQualifiedEfsCandidate());
                self::assertTrue($otherIngress->workerPool->isRunning());
            }
        } finally {
            $server->stop();
            $otherIngress->shutdown();
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_graph_attach_uses_one_access_log_registry_and_backend_owner_without_serving(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        try {
            self::assertSame($graph->accessLogs, (new \ReflectionProperty($server, 'accessLogs'))->getValue($server));
            try {
                new \OpsFour\S3Server\Runtime\S3ServerRuntime($server, $graph->notifications, $graph->accessLogEncryption);
                self::fail('The generic runtime became a second EFS graph owner.');
            } catch (\LogicException) {
                self::assertTrue($graph->readPool->isRunning());
            }
            try {
                $graph->transferOwnershipTo($server);
                self::fail('A graph transferred before server attachment.');
            } catch (\LogicException) {
                self::assertTrue($graph->readPool->isRunning());
            }

            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            self::assertTrue($server->ownsQualifiedEfsCandidateGraph($graph));
            self::assertTrue($server->ownsQualifiedEfsCandidateIngress($ingress));
            self::assertSame($graph->metadata, (new \ReflectionProperty($server, 'metadata'))->getValue($server));
            self::assertSame($graph->backend, (new \ReflectionProperty($server, 'storage'))->getValue($server));
            self::assertSame([['storage' => $graph->backend, 'tiers' => $graph->tiers]], $server->getHandlerRegistry()->storageRegistrations());
            self::assertTrue($server->getHandlerRegistry()->has(S3Operation::PutObject));
            self::assertSame($ingress->requestBodySpool, (new \ReflectionMethod($server, 'requestBodySpoolForStack'))->invoke($server));

            foreach ([
                static fn() => $graph->shutdown(),
                static fn() => $server->attachQualifiedEfsCandidateGraph($graph, $ingress),
                static fn() => $server->start(),
            ] as $invalid) {
                try {
                    $invalid();
                    self::fail('An attached candidate graph changed ownership or started serving.');
                } catch (\LogicException|\RuntimeException) {
                    self::assertTrue($graph->readPool->isRunning());
                }
            }
        } finally {
            $server->stop();
        }
        self::assertFalse($graph->readPool->isRunning());
        self::assertFalse($ingress->workerPool->isRunning());
        $server->stop();
    }

    public function test_attached_candidate_prepares_exact_background_graph_without_start_or_second_owner(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph(['notificationWorkerEnabled' => false]);
        $server = S3Server::forQualifiedEfsCandidate($graph);
        try {
            try {
                (new \ReflectionMethod($server, 'prepareBackgroundComponents'))->invoke($server);
                self::fail('A pending candidate shell prepared background work.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('attached candidate graph', $error->getMessage());
            }

            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $prepared = (new \ReflectionMethod($server, 'prepareBackgroundComponents'))->invoke($server);
            self::assertIsArray($prepared);
            self::assertSame($graph->lifecycleRunner, $prepared['lifecycleRunner']);
            self::assertNull($prepared['notificationProcessor']);
            self::assertSame($graph->objectCollector, $prepared['objectCollector']);
            self::assertSame($graph->transitionExecutor, $prepared['transitionExecutor']);
            self::assertSame($graph->restoreExecutor, $prepared['restoreExecutor']);
            self::assertSame($graph->restoreGarbageCollector, $prepared['restoreGarbageCollector']);
            self::assertSame($graph->storageGarbageCollector, $prepared['storageGarbageCollector']);
            self::assertSame($graph->multipartIntentCollector, $prepared['multipartIntentCollector']);
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertNull((new \ReflectionProperty($server, 'lifecycleRunner'))->getValue($server));
            self::assertNull((new \ReflectionProperty($server, 'localObjectIntentCollector'))->getValue($server));
            self::assertSame([], (new \ReflectionProperty($server, 'backgroundFutures'))->getValue($server));
            self::assertNull((new \ReflectionProperty($graph->lifecycleRunner, 'callbackId'))->getValue($graph->lifecycleRunner));
            self::assertTrue($graph->readPool->isRunning());
            try {
                $server->start();
                self::fail('Background selection admitted candidate serving.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('not yet admitted', $error->getMessage());
            }
        } finally {
            $server->stop();
        }
        self::assertTrue((new \ReflectionProperty($graph, 'lifecycleClosed'))->getValue($graph));
        self::assertTrue((new \ReflectionProperty($graph, 'collectorClosed'))->getValue($graph));
        self::assertFalse($graph->readPool->isRunning());
        self::assertFalse($ingress->workerPool->isRunning());
    }

    public function test_candidate_background_selection_rejects_unqualified_notification_worker(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
        try {
            try {
                (new \ReflectionMethod($server, 'prepareBackgroundComponents'))->invoke($server);
                self::fail('A generic notification processor was prepared for EFS.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('notification background processing is not qualified', $error->getMessage());
            }
            self::assertNull((new \ReflectionProperty($server, 'notificationProcessor'))->getValue($server));
            self::assertTrue($graph->readPool->isRunning());
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_graph_rejects_foreign_ingress_before_registry_seal_and_retains_caller_owner(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $otherMetadata = MysqlMetadataStore::fromDsn(self::POSTGRES_DSN);
        $foreignIngress = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateIngressResources(
            $graph->qualification,
            $graph->scratch,
            $config,
            $otherMetadata,
            new InMemoryCredentialProvider(),
        );
        $server = S3Server::forQualifiedEfsCandidate($graph);
        try {
            try {
                $server->attachQualifiedEfsCandidateGraph($graph, $foreignIngress);
                self::fail('A foreign ingress transferred its owner.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('metadata differs', $error->getMessage());
            }
            self::assertSame([], $this->candidateRegistry($server)->storageRegistrations());
            self::assertFalse($server->isConfigurationSealedForQualifiedEfsCandidate());
            $server->stop();
            self::assertTrue($graph->readPool->isRunning());
            self::assertTrue($ingress->workerPool->isRunning());
        } finally {
            $foreignIngress->shutdown();
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_graph_constructor_rejects_foreign_metadata_backend_and_config(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $otherMetadata = MysqlMetadataStore::fromDsn(self::POSTGRES_DSN);
        $otherBackend = new InMemoryBackend();
        $otherConfig = $config->with(['lifecycleIntervalSeconds' => $config->lifecycleIntervalSeconds + 1.0]);
        try {
            foreach ([
                [$config, $otherMetadata, $graph->backend],
                [$config, $graph->metadata, $otherBackend],
                [$otherConfig, $graph->metadata, $graph->backend],
            ] as [$candidateConfig, $candidateMetadata, $candidateBackend]) {
                try {
                    new S3Server($candidateConfig, $candidateMetadata, $candidateBackend, candidateGraph: $graph);
                    self::fail('A candidate server accepted a foreign dependency.');
                } catch (\LogicException $error) {
                    self::assertStringContainsString('exact resource graph', $error->getMessage());
                }
            }
            self::assertTrue($graph->readPool->isRunning());
        } finally {
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_graph_stop_keeps_backend_and_ingress_owned_for_retry(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
        $calls = 0;
        $failingPool = $this->createMock(WorkerPool::class);
        $failingPool->expects(self::exactly(2))->method('shutdown')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('Injected backend worker stop failure.');
            }
        });
        $poolProperty = new \ReflectionProperty($graph->backend, 'ownedWorkerPools');
        $ownedPools = $poolProperty->getValue($graph->backend);
        self::assertIsArray($ownedPools);
        $poolProperty->setValue($graph->backend, [$failingPool, ...$ownedPools]);

        try {
            try {
                $server->stop();
                self::fail('A failed backend stop released dependent resources.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('shutdown incomplete', $error->getMessage());
            }
            self::assertSame(1, $calls);
            self::assertTrue($graph->readPool->isRunning());
            self::assertFalse($ingress->workerPool->isRunning());
            $server->stop();
            self::assertSame(2, $calls);
            self::assertFalse($graph->readPool->isRunning());
            self::assertFalse($ingress->workerPool->isRunning());
        } finally {
            if ($graph->readPool->isRunning() || $ingress->workerPool->isRunning()) {
                $server->stop();
            }
        }
    }

    public function test_candidate_graph_rejects_existing_registry_handlers_without_transfer(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $this->candidateRegistry($server)->register(S3Operation::GetObject, new S3DispatchHandler(new HandlerRegistry()));
        try {
            try {
                $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
                self::fail('A preconfigured registry was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('empty handler registry', $error->getMessage());
            }
            self::assertSame([], $this->candidateRegistry($server)->storageRegistrations());
            $server->stop();
            self::assertTrue($graph->readPool->isRunning());
            self::assertTrue($ingress->workerPool->isRunning());
        } finally {
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_pending_candidate_shell_rejects_generic_mutators_before_they_take_ownership(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $foreignPool = new ContextWorkerPool(1);
        try {
            foreach ([
                static fn() => $server->getHandlerRegistry(),
                static fn() => $server->addWorkerPool($foreignPool),
                static fn() => $server->setNotificationDispatcher($graph->notifications),
                static fn() => $server->setEncryptionService(null),
                static fn() => $server->setStorageTierRegistry($graph->tiers),
                static fn() => $server->addMiddleware($ingress->auth),
            ] as $mutation) {
                try {
                    $mutation();
                    self::fail('A reserved candidate shell accepted a generic mutation.');
                } catch (\LogicException) {
                    self::assertSame([], (new \ReflectionProperty($server, 'workerPools'))->getValue($server));
                    self::assertNull((new \ReflectionProperty($server, 'notifications'))->getValue($server));
                    self::assertNull((new \ReflectionProperty($server, 'encryption'))->getValue($server));
                    self::assertSame($graph->accessLogs, (new \ReflectionProperty($server, 'accessLogs'))->getValue($server));
                }
            }
            $server->stop();
            self::assertTrue($foreignPool->isRunning());
            self::assertTrue($graph->readPool->isRunning());
        } finally {
            $foreignPool->shutdown();
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_pool_stop_failure_keeps_graph_open_until_retry(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
        $calls = 0;
        $failingPool = $this->createMock(WorkerPool::class);
        $failingPool->expects(self::exactly(2))->method('shutdown')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('Injected registered pool stop failure.');
            }
        });
        $pools = new \ReflectionProperty($server, 'workerPools');
        $registered = $pools->getValue($server);
        self::assertIsArray($registered);
        $pools->setValue($server, [...$registered, ['pool' => $failingPool, 'name' => 'injected_pool']]);
        try {
            try {
                $server->stop();
                self::fail('Graph closed after a registered pool stop failure.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('shutdown incomplete', $error->getMessage());
            }
            self::assertSame(1, $calls);
            self::assertFalse($ingress->workerPool->isRunning());
            self::assertTrue($graph->readPool->isRunning());
            self::assertFalse((new \ReflectionProperty($graph, 'backendClosed'))->getValue($graph));
            $server->stop();
            self::assertSame(2, $calls);
            self::assertFalse($graph->readPool->isRunning());
        } finally {
            if ($graph->readPool->isRunning()) {
                $server->stop();
            }
        }
    }

    public function test_candidate_graph_requires_configured_select_pool_and_server_closes_it(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph(['selectWorkerPoolSize' => 1]);
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $pool = new ContextWorkerPool(1);
        try {
            try {
                $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
                self::fail('An in-process Select fallback was accepted.');
            } catch (\LogicException) {
                self::assertSame([], $this->candidateRegistry($server)->storageRegistrations());
            }
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress, $pool);
            self::assertTrue($server->ownsQualifiedEfsCandidateGraph($graph));
            $server->stop();
            self::assertFalse($pool->isRunning());
        } finally {
            if ($graph->readPool->isRunning() || $ingress->workerPool->isRunning()) {
                $server->stop();
            }
            if ($pool->isRunning()) {
                $pool->shutdown();
            }
        }
    }

    public function test_candidate_graph_stop_waits_for_ingress_drain_before_closing_graph(): void
    {
        [$graph, $ingress] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
        $keys = new \ReflectionProperty($ingress->requestBodySpool, 'keys');
        $keys->setValue($ingress->requestBodySpool, ['synthetic-active-spool' => str_repeat('a', 32)]);
        try {
            try {
                $server->stop();
                self::fail('The graph closed while ingress retained an active request spool.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('shutdown incomplete', $error->getMessage());
            }
            self::assertTrue($graph->readPool->isRunning());
            self::assertTrue($ingress->workerPool->isRunning());
            $keys->setValue($ingress->requestBodySpool, []);
            $server->stop();
            self::assertFalse($graph->readPool->isRunning());
            self::assertFalse($ingress->workerPool->isRunning());
        } finally {
            $keys->setValue($ingress->requestBodySpool, []);
            if ($graph->readPool->isRunning() || $ingress->workerPool->isRunning()) {
                $server->stop();
            }
        }
    }

    public function test_management_candidate_binds_exact_graph_and_shared_latch_without_starting(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        try {
            $management = EfsCandidateManagementResources::create($graph, $config);
            $management->assertBoundTo($graph, $config);
            $management->admission->assertFresh();
            self::assertSame($graph->metadata, (new \ReflectionProperty($management->readiness, 'metadata'))->getValue($management->readiness));
            self::assertSame($graph->tiers, (new \ReflectionProperty($management->readiness, 'storageTiers'))->getValue($management->readiness));
            self::assertSame($graph->qualification->hostEvidenceReader(), (new \ReflectionProperty($management->readiness, 'efsEvidence'))->getValue($management->readiness));
            self::assertSame($management->admission, (new \ReflectionProperty($management->readiness, 'admissionEvidence'))->getValue($management->readiness));

            $wrongConfig = $config->with(['lifecycleIntervalSeconds' => $config->lifecycleIntervalSeconds + 1.0]);
            try {
                EfsCandidateManagementResources::create($graph, $wrongConfig);
                self::fail('A management candidate accepted another configuration.');
            } catch (\LogicException) {
                self::assertTrue($graph->readPool->isRunning());
            }
            $this->expectException(\RuntimeException::class);
            $management->assertBoundTo($graph, $wrongConfig);
        } finally {
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_attached_candidate_server_owns_one_management_bundle_without_serving(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config);
        $otherManagement = EfsCandidateManagementResources::create($graph, $config);
        $attached = false;
        try {
            try {
                $server->attachQualifiedEfsCandidateManagement($management);
                self::fail('A pending candidate shell accepted management before graph attachment.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('attached, open candidate graph', $error->getMessage());
            }

            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $attached = true;
            $server->attachQualifiedEfsCandidateManagement($management);
            self::assertSame($management, $server->qualifiedEfsCandidateManagement());
            self::assertSame($management->readiness, $server->qualifiedEfsCandidateManagement()->readiness);
            self::assertSame($management->admission, $server->qualifiedEfsCandidateManagement()->admission);
            self::assertTrue($server->ownsQualifiedEfsCandidateManagement($management));
            self::assertFalse($server->ownsQualifiedEfsCandidateManagement($otherManagement));
            self::assertNotSame($management->readiness, $otherManagement->readiness);
            self::assertNotSame($management->admission, $otherManagement->admission);
            try {
                $server->attachQualifiedEfsCandidateManagement($otherManagement);
                self::fail('A second admission and readiness pair replaced the graph-owned bundle.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('attached, open candidate graph', $error->getMessage());
            }

            try {
                $server->start();
                self::fail('Management attachment admitted production serving.');
            } catch (\RuntimeException $error) {
                self::assertSame('Qualified EFS candidate serving is not yet admitted.', $error->getMessage());
            }
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertTrue($graph->readPool->isRunning());

            $server->stop();
            $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/.health'));
            self::assertSame(503, $management->readiness->handleRequest($request)->getStatus());
            self::assertFalse($server->ownsQualifiedEfsCandidateManagement($management));
            $this->expectException(\LogicException::class);
            $server->qualifiedEfsCandidateManagement();
        } finally {
            if ($attached) {
                $server->stop();
            } else {
                $ingress->shutdown();
                $graph->shutdown();
            }
        }
    }

    public function test_management_candidate_readiness_latches_revocation_before_and_during_sql_probe(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        try {
            $management = EfsCandidateManagementResources::create($graph, $config);
            $reader = $graph->qualification->hostEvidenceReader();
            $metadata = $this->createMock(MetadataStore::class);
            $calls = 0;
            $metadata->method('getBucket')->willReturnCallback(function () use (&$calls): null {
                if (++$calls === 2) {
                    $record = $this->record();
                    $record['epoch'] = str_repeat('c', 32);
                    $this->writeRecord($record);
                }
                return null;
            });
            $metadata->method('listReferencedStorageTiers')->willReturn([]);
            $probe = new HealthCheckHandler(
                metadata: $metadata,
                storageTiers: $graph->tiers,
                efsEvidence: $reader,
                admissionEvidence: $management->admission,
            );
            $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/.health'));
            self::assertSame(200, $probe->handleRequest($request)->getStatus());
            self::assertSame(503, $probe->handleRequest($request)->getStatus());
            self::assertSame(2, $calls);
            self::assertSame(503, $management->readiness->handleRequest($request)->getStatus());
            self::assertSame(2, $calls, 'Revoked evidence must reject before the graph SQL probe.');
            try {
                $management->admission->assertFresh();
                self::fail('A revoked admission latch recovered.');
            } catch (\RuntimeException) {
                self::assertSame(2, $calls);
            }
        } finally {
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_management_candidate_rejects_revocation_before_sql_probe(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        try {
            $management = EfsCandidateManagementResources::create($graph, $config);
            $record = $this->record();
            $record['epoch'] = str_repeat('c', 32);
            $this->writeRecord($record);
            $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/.health'));
            self::assertSame(503, $management->readiness->handleRequest($request)->getStatus());
        } finally {
            $ingress->shutdown();
            $graph->shutdown();
        }
    }

    public function test_management_candidate_latches_graph_shutdown_before_any_sql_probe(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $management = EfsCandidateManagementResources::create($graph, $config);
        $ingress->shutdown();
        $graph->shutdown();

        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/.health'));
        self::assertSame(503, $management->readiness->handleRequest($request)->getStatus());
        try {
            $management->admission->assertFresh();
            self::fail('Admission stayed open after its graph shut down.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unavailable', $error->getMessage());
        }
        try {
            $management->assertBoundTo($graph, $config);
            self::fail('A stopped graph remained bound to management.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unavailable', $error->getMessage());
        }
        $this->expectException(\RuntimeException::class);
        EfsCandidateManagementResources::create($graph, $config);
    }

    public function test_candidate_pre_bind_admission_binds_exact_probe_and_remains_inert(): void
    {
        [$graph, $ingress, $config, $probe] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            $server->prepareQualifiedEfsCandidatePreBindAdmission();
            self::assertTrue($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertSame(1, $probe->healthChecks);

            $server->assertQualifiedEfsCandidatePreBindAdmissionFresh();
            self::assertSame(2, $probe->healthChecks);
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));

            try {
                $server->start();
                self::fail('An inert pre-bind admission opened production serving.');
            } catch (\RuntimeException $error) {
                self::assertSame('Qualified EFS candidate serving is not yet admitted.', $error->getMessage());
            }
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_admission_rejects_foreign_probe_without_committing_state(): void
    {
        [$graph, $ingress, $config] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $foreignProbe = new EfsProbeCredentialProvider();
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $foreignProbe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            try {
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('A management bundle with another credential probe was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('credential probe differs', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertSame(0, $foreignProbe->healthChecks);
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
            self::assertTrue($server->ownsQualifiedEfsCandidateGraph($graph));
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_admission_requires_management_and_commits_only_after_retry(): void
    {
        [$graph, $ingress, $config, $probe] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            try {
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('A candidate without its management bundle was admitted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('exact, open candidate graph', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertSame(0, $probe->healthChecks);

            $server->attachQualifiedEfsCandidateManagement($management);
            $server->prepareQualifiedEfsCandidatePreBindAdmission();
            self::assertTrue($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertSame(1, $probe->healthChecks);
        } finally {
            $server->stop();
        }
        self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
    }

    public function test_candidate_pre_bind_credential_failure_rolls_back_and_can_be_retried(): void
    {
        [$graph, $ingress, $config, $probe] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            $probe->available = false;
            try {
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('An unavailable credential source was admitted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Credential store unavailable.', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));

            $probe->available = true;
            $server->prepareQualifiedEfsCandidatePreBindAdmission();
            self::assertTrue($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertSame(2, $probe->healthChecks);
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_latches_evidence_loss_during_credential_probe(): void
    {
        [$graph, $ingress, $config, $probe] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        $probe->onHealthCheck = function (): void {
            $record = $this->record();
            $record['epoch'] = str_repeat('d', 32);
            $this->writeRecord($record);
        };
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            try {
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('Evidence revoked during the credential probe was admitted.');
            } catch (\RuntimeException) {
                self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            }
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));

            $probe->onHealthCheck = null;
            $this->writeRecord($this->record());
            $this->expectException(\LogicException::class);
            $server->prepareQualifiedEfsCandidatePreBindAdmission();
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_ignores_only_non_referencing_foreign_cleanup_data(): void
    {
        [$graph, $ingress, $config, $probe, $cleanup] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            $cleanup->operationByTier = [
                'STANDARD' => 'delete_path',
                'private-cold-tier' => 'abort_multipart',
            ];
            $server->prepareQualifiedEfsCandidatePreBindAdmission();
            self::assertSame(['STANDARD'], $cleanup->queriedTiers);
            self::assertTrue($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_rejects_referenced_unconfigured_tier_before_cleanup(): void
    {
        [$graph, $ingress, $config, $probe, $cleanup] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            $cleanup->referencedTiers = ['STANDARD', 'private-cold-tier'];
            $cleanup->operationByTier = ['private-cold-tier' => 'abort_multipart'];
            try {
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('A candidate missing a referenced storage tier was admitted.');
            } catch (\RuntimeException $error) {
                self::assertSame('EFS candidate metadata references an unconfigured storage tier.', $error->getMessage());
                self::assertStringNotContainsString('private-cold-tier', $error->getMessage());
            }
            self::assertSame([], $cleanup->queriedTiers);
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_rechecks_identity_after_tier_inventory_query(): void
    {
        [$graph, $ingress, $config, $probe, $cleanup] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        $cleanup->duringTierInventory = function (): void {
            $record = $this->record();
            $record['epoch'] = str_repeat('d', 32);
            $this->writeRecord($record);
        };
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            try {
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('Evidence lost during tier inventory was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('identity changed', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_recursive_cleanup_blocks_legacy_abort_without_mutation(): void
    {
        [$graph, $ingress, $config, $probe, $cleanup] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            try {
                $cleanup->operationByTier = ['STANDARD' => 'abort_multipart'];
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('Legacy recursive cleanup was admitted by the candidate.');
            } catch (\RuntimeException $error) {
                self::assertSame('Legacy recursive multipart cleanup blocks local storage admission.', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_recursive_cleanup_blocks_unknown_operation(): void
    {
        [$graph, $ingress, $config, $probe, $cleanup] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            try {
                $cleanup->operationByTier = ['STANDARD' => 'unexpected_recursive_operation'];
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('Unclassified recursive cleanup was admitted by the candidate.');
            } catch (\RuntimeException $error) {
                self::assertSame('Unclassified storage cleanup blocks local storage admission.', $error->getMessage());
                self::assertStringNotContainsString('unexpected_recursive_operation', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
        } finally {
            $server->stop();
        }
    }

    public function test_candidate_pre_bind_rechecks_identity_after_recursive_cleanup_query(): void
    {
        [$graph, $ingress, $config, $probe, $cleanup] = $this->candidateIngressGraph();
        $server = S3Server::forQualifiedEfsCandidate($graph);
        $management = EfsCandidateManagementResources::create($graph, $config, credentialHealthProbe: $probe);
        try {
            $server->attachQualifiedEfsCandidateGraph($graph, $ingress);
            $server->attachQualifiedEfsCandidateManagement($management);
            try {
                $cleanup->duringQuery = function (): void {
                    $record = $this->record();
                    $record['epoch'] = str_repeat('d', 32);
                    $this->writeRecord($record);
                };
                $server->prepareQualifiedEfsCandidatePreBindAdmission();
                self::fail('Evidence lost during recursive cleanup admission was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('identity changed', $error->getMessage());
            }
            self::assertFalse($server->isQualifiedEfsCandidatePreBindAdmissionPrepared());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_recursive_cleanup_admission_rejects_missing_capability_and_wrapper(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $tiers = StorageTierRegistry::single(new InMemoryBackend());
        foreach ([
            $metadata,
            new ObservedMetadataStore($metadata, new MetricsCollector(), 'missing-capability'),
        ] as $candidateMetadata) {
            try {
                EfsRecursiveCleanupAdmission::assertReady($candidateMetadata, $tiers);
                self::fail($candidateMetadata::class . ' admitted metadata without recursive cleanup capability.');
            } catch (\RuntimeException|\LogicException $error) {
                self::assertStringContainsString('recursive cleanup admission', $error->getMessage());
            }
        }
    }

    public function test_recursive_cleanup_admission_checks_every_configured_tier_name(): void
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            RecursiveCleanupAdmissionStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        self::assertInstanceOf(RecursiveCleanupAdmissionStore::class, $metadata);
        $queriedTiers = [];
        $metadata->expects(self::exactly(2))
            ->method('assertNoUnsafeRecursiveCleanup')
            ->willReturnCallback(static function (string $tier) use (&$queriedTiers): void {
                $queriedTiers[] = $tier;
            });
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', new InMemoryBackend(), defaultWriteTier: true),
            new StorageTier('ARCHIVE', new InMemoryBackend()),
        ]);

        EfsRecursiveCleanupAdmission::assertReady($metadata, $tiers);

        self::assertSame(['STANDARD', 'ARCHIVE'], $queriedTiers);
    }

    public function test_evidence_bound_collector_never_executes_late_recursive_abort(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = $this->createMock(StorageBackend::class);
        $tiers = StorageTierRegistry::single($storage);
        EfsRecursiveCleanupAdmission::assertReady($metadata, $tiers);
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $connection->exec("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, key_name, upload_id, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '', 'abort_multipart', 'key', 'late-upload', 0)");

        $storage->expects(self::never())->method('abortMultipartUpload');
        $storage->expects(self::never())->method('deleteObjectByPath');
        $collector = new StorageGarbageCollector(
            $metadata,
            $tiers,
            efsEvidence: $this->reader()->hostEvidenceReader(),
        );

        self::assertSame(0, $collector->collect(1));
        $row = $connection->query('SELECT operation, key_name, upload_id, status, attempts FROM s3_storage_garbage');
        self::assertInstanceOf(\PDOStatement::class, $row);
        self::assertSame([
            'operation' => 'abort_multipart',
            'key_name' => 'key',
            'upload_id' => 'late-upload',
            'status' => 'processing',
            'attempts' => 0,
        ], $row->fetch(\PDO::FETCH_ASSOC));

        $connection->exec('UPDATE s3_storage_garbage SET next_attempt_at = 0');
        $genericStorage = $this->createMock(StorageBackend::class);
        $genericStorage->expects(self::once())
            ->method('abortMultipartUpload')
            ->with('bucket', 'key', 'late-upload');
        self::assertSame(1, (new StorageGarbageCollector(
            $metadata,
            StorageTierRegistry::single($genericStorage),
        ))->collect(1));
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
    }

    public function test_evidence_bound_collector_still_executes_exact_path_cleanup(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $tiers = StorageTierRegistry::single($storage);
        EfsRecursiveCleanupAdmission::assertReady($metadata, $tiers);
        $deleted = $storage->putObject('bucket', 'deleted', new ReadableBuffer('delete-path'));
        $retained = $storage->putObject('bucket', 'retained', new ReadableBuffer('part-intent'));
        $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $deleted->path);
        $connection = (new \ReflectionClass($metadata))->getMethod('connection')->invoke($metadata);
        self::assertInstanceOf(\PDO::class, $connection);
        $insert = $connection->prepare("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
            VALUES ('bucket', 'STANDARD', ?, 'part_write_intent', 'retained', 'upload', 'owner', 0, ?)");
        self::assertTrue($insert->execute([$retained->path, str_repeat('a', 32)]));
        EfsRecursiveCleanupAdmission::assertReady($metadata, $tiers);

        $collector = new StorageGarbageCollector(
            $metadata,
            $tiers,
            efsEvidence: $this->reader()->hostEvidenceReader(),
        );

        self::assertSame(2, $collector->collect(2));
        foreach ([$deleted->path, $retained->path] as $path) {
            try {
                $storage->getObjectByPath($path);
                self::fail('Evidence-bound exact-path cleanup left physical data behind.');
            } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
                // Expected: both operations stay exact-path capable under EFS.
            }
        }
        $rows = $connection->query('SELECT operation, status FROM s3_storage_garbage ORDER BY id');
        self::assertInstanceOf(\PDOStatement::class, $rows);
        self::assertSame(
            [['operation' => 'part_write_intent', 'status' => 'pending']],
            $rows->fetchAll(\PDO::FETCH_ASSOC),
        );
    }

    /**
     * @param array{selectWorkerPoolSize?: int, notificationWorkerEnabled?: bool} $configOverrides
     * @return array{EfsCandidateRuntimeResources, EfsCandidateIngressResources, S3ServerConfig, EfsProbeCredentialProvider, EfsRecursiveCleanupQueryState}
     */
    private function candidateIngressGraph(array $configOverrides = []): array
    {
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $context = $this->contextWithSeparateStorageDevice('mysql');
        $path = $this->base . '/scratch';
        self::assertTrue(mkdir($path, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $path, fn(): string => $this->mountInfo());
        $config = (new S3ServerConfig(
            storagePath: $this->storageRoot,
            metadataDriver: 'mysql',
            metadataDsn: self::POSTGRES_DSN,
        ))->with($configOverrides);
        $cleanup = new EfsRecursiveCleanupQueryState();
        $connection = $this->createMock(MysqlConnection::class);
        $connection->method('isClosed')->willReturn(false);
        $connection->method('execute')->willReturnCallback(function (string $sql, array $params) use ($cleanup): MysqlResult {
            if (str_contains($sql, 'SELECT retained_count')) {
                self::assertCount(1, $params);

                return new EfsRecursiveCleanupMysqlResult([[
                    'retained_count' => 0,
                    'actual_count' => 0,
                ]]);
            }
            self::assertStringContainsString('FROM s3_storage_garbage', $sql);
            self::assertStringContainsString('recursive_cleanup_unsafe = 1', $sql);
            self::assertStringContainsString('BINARY storage_tier = BINARY ?', $sql);
            self::assertCount(2, $params);
            self::assertSame($params[0], $params[1]);
            $tier = (string) $params[0];
            $cleanup->queriedTiers[] = $tier;
            $cleanup->duringQuery?->__invoke();
            $operation = $cleanup->operationByTier[$tier] ?? null;
            if (in_array($operation, ['delete_path', 'part_write_intent'], true)) {
                $operation = null;
            }

            return new EfsRecursiveCleanupMysqlResult($operation === null ? [] : [['operation' => $operation]]);
        });
        $connection->method('query')->willReturnCallback(function (string $sql) use ($cleanup): MysqlResult {
            if (str_contains($sql, 'SELECT MAX(version) AS version FROM s3_schema_version')) {
                return new EfsRecursiveCleanupMysqlResult([['version' => MysqlSchema::VERSION]]);
            }
            if (str_contains($sql, 'FROM s3_encryption_configs')) {
                return new EfsRecursiveCleanupMysqlResult([]);
            }
            if (str_contains($sql, 'SELECT DISTINCT BINARY storage_tier AS tier FROM s3_objects')) {
                $cleanup->duringTierInventory?->__invoke();

                return new EfsRecursiveCleanupMysqlResult(array_map(
                    static fn(string $tier): array => ['tier' => $tier],
                    $cleanup->referencedTiers,
                ));
            }
            if (str_contains($sql, 'FROM s3_lifecycle_rules')) {
                return new EfsRecursiveCleanupMysqlResult([]);
            }

            throw new \LogicException('Unexpected EFS candidate metadata query.');
        });
        $connector = $this->createMock(SqlConnector::class);
        $connector->method('connect')->willReturn($connection);
        $pool = new MysqlConnectionPool(MysqlConfig::fromString(self::POSTGRES_DSN), connector: $connector);
        $metadata = new MysqlMetadataStore($pool);
        (new \ReflectionProperty($metadata, 'creationDsnFingerprint'))->setValue($metadata, hash('sha256', self::POSTGRES_DSN));
        $factory = new S3ServerRuntimeFactory();
        $graph = $factory->createQualifiedEfsCandidateResources($context, $scratch, $config, $metadata, new NullLogger());
        $credentialProvider = new EfsProbeCredentialProvider();
        try {
            $ingress = $factory->createQualifiedEfsCandidateIngressResources(
                $context,
                $scratch,
                $config,
                $metadata,
                $credentialProvider,
            );
        } catch (\Throwable $error) {
            $graph->shutdown();
            throw $error;
        }

        return [$graph, $ingress, $config, $credentialProvider, $cleanup];
    }

    private function candidateRegistry(S3Server $server): HandlerRegistry
    {
        $registry = (new \ReflectionProperty($server, 'handlerRegistry'))->getValue($server);
        self::assertInstanceOf(HandlerRegistry::class, $registry);
        return $registry;
    }

    public function test_node_local_scratch_rejects_nfs_without_accessing_its_path(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $scratch = $this->base . '/scratch';
        $mountInfo = fn(): string => $this->mountInfo() . "4 1 0:44 / {$scratch} rw - nfs4 127.0.0.1:/ rw\n";

        try {
            EfsNodeLocalScratch::qualify($context, $scratch, $mountInfo);
            self::fail('An NFS scratch path was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('node-local', $error->getMessage());
            self::assertDirectoryDoesNotExist($scratch);
            self::assertSame(1, $this->storageStats);
        }
    }

    public function test_node_local_scratch_rejects_unsafe_paths_and_mount_replacement(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0o700));
        self::assertTrue(symlink($scratch, $this->base . '/scratch-link'));

        foreach ([$this->storageRoot, $scratch . '/../scratch', $this->base . '/scratch-link'] as $path) {
            try {
                EfsNodeLocalScratch::qualify($context, $path, fn(): string => $this->mountInfo());
                self::fail('An unsafe scratch path was accepted.');
            } catch (\RuntimeException) {
                self::assertSame(1, $this->storageStats);
            }
        }
        self::assertTrue(chmod($scratch, 0o777));
        try {
            EfsNodeLocalScratch::qualify($context, $scratch, fn(): string => $this->mountInfo());
            self::fail('Public scratch was accepted.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->storageStats);
        }
        self::assertTrue(chmod($scratch, 0o700));

        $reads = 0;
        $changingMount = function () use (&$reads, $scratch): string {
            return ++$reads === 1
                ? $this->mountInfo()
                : $this->mountInfo() . "4 1 0:44 / {$scratch} rw - nfs4 127.0.0.1:/ rw\n";
        };
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mount changed');
        EfsNodeLocalScratch::qualify($context, $scratch, $changingMount);
    }

    public function test_node_local_scratch_rejects_same_storage_device(): void
    {
        $context = EfsQualificationContext::qualify($this->reader(), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0o700));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('separate node-local device');
        EfsNodeLocalScratch::qualify($context, $scratch, fn(): string => $this->mountInfo());
    }

    public function test_node_local_scratch_never_inspects_paths_from_a_fiber(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0o700));
        $mountReads = 0;
        $mountInfo = function () use (&$mountReads): string {
            $mountReads++;

            return $this->mountInfo();
        };
        $capability = EfsNodeLocalScratch::qualify($context, $scratch, $mountInfo);
        $before = $mountReads;
        $fiber = new \Fiber(static function () use ($context, $scratch, $mountInfo, $capability): void {
            foreach ([
                static fn(): EfsNodeLocalScratch => EfsNodeLocalScratch::qualify($context, $scratch, $mountInfo),
                static fn(): string => $capability->path(),
            ] as $operation) {
                try {
                    $operation();
                    self::fail('An API fiber inspected scratch.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('fiber', $error->getMessage());
                }
            }
        });
        $fiber->start();
        self::assertSame($before, $mountReads);
    }

    public function test_node_local_scratch_latches_mount_identity_after_qualification(): void
    {
        $context = $this->contextWithSeparateStorageDevice();
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0o700));
        $originalMount = $this->mountInfo();
        $mountInfo = $originalMount;
        $capability = EfsNodeLocalScratch::qualify($context, $scratch, static function () use (&$mountInfo): string {
            return $mountInfo;
        });
        self::assertSame($scratch, $capability->path());

        $mountInfo .= "4 1 0:44 / {$scratch} rw - nfs4 127.0.0.1:/ rw\n";
        try {
            $capability->path();
            self::fail('A replaced scratch mount was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('unavailable or invalid', $error->getMessage());
        }
        $mountInfo = $originalMount;
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('revoked');
        $capability->path();
    }

    private function contextWithSeparateStorageDevice(string $driver = 'postgres'): EfsQualificationContext
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        $record = $this->record();
        $record['driver'] = $driver;
        $this->writeRecord($record);

        return EfsQualificationContext::qualify($this->reader(driver: $driver), $this->storageRoot, fn(): string => $this->mountInfo());
    }

    public function test_guarded_writer_and_collector_construction_does_not_revisit_hard_nfs_root(): void
    {
        $reader = $this->reader();
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $evidence = $reader->hostEvidenceReader();
        $readPool = new ContextWorkerPool(2);
        $backend = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $evidence,
            efsReadPool: $readPool,
            isolatedEfsRead: true,
            requireIsolatedReadSession: true,
        );
        $tiers = StorageTierRegistry::single($backend);
        $context->assertCandidateStorageBinding($backend, $tiers);
        $scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($scratch, 0o700));
        self::assertTrue(rmdir($this->storageRoot));

        $writer = null;
        $collector = null;
        try {
            $writer = new LocalFencedObjectWriter($this->storageRoot, $scratch, 'STANDARD', efsEvidence: $evidence);
            $collector = new LocalObjectWriteIntentCollector(
                PostgresMetadataStore::fromDsn(self::POSTGRES_DSN),
                $tiers,
                'STANDARD',
                efsEvidence: $evidence,
            );
            self::assertSame($this->storageRoot, $writer->storageRoot());
            self::assertSame(1, $this->storageStats);
            self::assertDirectoryDoesNotExist($this->storageRoot);

            foreach ([$this->reader()->hostEvidenceReader(), null] as $wrongEvidence) {
                try {
                    new LocalObjectWriteIntentCollector(
                        PostgresMetadataStore::fromDsn(self::POSTGRES_DSN),
                        $tiers,
                        'STANDARD',
                        efsEvidence: $wrongEvidence,
                    );
                    self::fail('A collector with a different or missing watcher was accepted.');
                } catch (\InvalidArgumentException) {
                    self::assertDirectoryDoesNotExist($this->storageRoot);
                }
            }
        } finally {
            $collector?->shutdown();
            $writer?->shutdown();
            $backend->shutdown();
            $readPool->shutdown();
            self::assertTrue(mkdir($this->storageRoot, 0o700));
            $budget = $scratch . '/.opsfour-s3-storage-budget';
            foreach (['lock', 'budget'] as $name) {
                if (is_file($budget . '/' . $name)) {
                    self::assertTrue(unlink($budget . '/' . $name));
                }
            }
            if (is_dir($budget)) {
                self::assertTrue(rmdir($budget));
            }
            self::assertTrue(rmdir($scratch));
        }
    }

    public function test_factory_candidate_object_write_preflight_constructs_and_closes_without_revisiting_root(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        $this->writeRecord($this->record());
        $reader = $this->reader();
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $readPool = new ContextWorkerPool(2);
        $backend = new FilesystemBackend(
            $this->storageRoot,
            efsEvidence: $reader->hostEvidenceReader(),
            efsReadPool: $readPool,
            isolatedEfsRead: true,
            requireIsolatedReadSession: true,
        );
        $tiers = StorageTierRegistry::single($backend);
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $factory = new S3ServerRuntimeFactory();
        $metadata = PostgresMetadataStore::fromDsn(self::POSTGRES_DSN);
        $logger = new NullLogger();
        self::assertTrue(rmdir($this->storageRoot));

        try {
            $factory->assertQualifiedEfsObjectWriteConstruction(
                $context,
                $scratch,
                $this->candidateConfig(),
                $metadata,
                $backend,
                $tiers,
                $logger,
            );
            self::assertSame(1, $this->storageStats);
            self::assertDirectoryDoesNotExist($this->storageRoot);

            try {
                $factory->assertQualifiedEfsObjectWriteConstruction(
                    $context,
                    $scratch,
                    $this->candidateConfig(),
                    $metadata,
                    $backend,
                    StorageTierRegistry::single(new InMemoryBackend()),
                    $logger,
                );
                self::fail('A different handler storage tier passed factory preflight.');
            } catch (\RuntimeException) {
                self::assertSame(1, $this->storageStats);
            }
        } finally {
            $backend->shutdown();
            $readPool->shutdown();
            self::assertTrue(mkdir($this->storageRoot, 0o700));
        }
    }

    public function test_candidate_runtime_resource_graph_retains_exact_mysql_workers_until_explicit_shutdown(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $reader = $this->reader(driver: 'mysql');
        $context = EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $metadata = MysqlMetadataStore::fromDsn($dsn);
        $config = new S3ServerConfig(storagePath: $this->storageRoot, metadataDriver: 'mysql', metadataDsn: $dsn);
        try {
            (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
                $context,
                $scratch,
                $config->with(['metadataDsn' => 'host=127.0.0.1 user=test db=other']),
                $metadata,
                new NullLogger(),
            );
            self::fail('A candidate graph with the wrong SQL identity was constructed.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->storageStats);
        }
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $graph = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
            $context,
            $scratch,
            $config,
            $metadata,
            new NullLogger(),
            encryption: $encryption,
        );

        try {
            self::assertSame($context, $graph->qualification);
            self::assertSame($scratch, $graph->scratch);
            self::assertSame($metadata, $graph->metadata);
            self::assertSame($metadata, $graph->rawMetadata);
            self::assertSame($encryption, $graph->accessLogEncryption);
            foreach ([null, $this->createMock(EncryptionServiceInterface::class)] as $wrongEncryption) {
                $wrongEncryptionRegistry = new HandlerRegistry();
                try {
                    HandlerRegistrar::registerQualifiedEfsCandidate(
                        $wrongEncryptionRegistry,
                        $graph,
                        $config,
                        encryption: $wrongEncryption,
                    );
                    self::fail('Candidate handlers accepted different access-log encryption key material.');
                } catch (\InvalidArgumentException $error) {
                    self::assertStringContainsString('exact qualified encryption service', $error->getMessage());
                    self::assertSame([], $wrongEncryptionRegistry->storageRegistrations());
                    self::assertFalse($wrongEncryptionRegistry->has(S3Operation::PutObject));
                }
            }
            $matchingEncryptionRegistry = new HandlerRegistry();
            HandlerRegistrar::registerQualifiedEfsCandidate($matchingEncryptionRegistry, $graph, $config, encryption: $encryption);
            self::assertTrue($matchingEncryptionRegistry->has(S3Operation::PutObject));
            self::assertSame($this->storageRoot, $graph->backend->getBasePath());
            self::assertSame($reader->hostEvidenceReader(), $graph->backend->hostEvidenceReader());
            self::assertSame(2, $graph->readPool->getWorkerLimit());
            self::assertSame($graph->readPool, (new \ReflectionProperty($graph->backend, 'efsReadPool'))->getValue($graph->backend));
            self::assertTrue($graph->readPool->isRunning());
            $wrongReadPool = new ContextWorkerPool(2);
            try {
                new EfsCandidateRuntimeResources(
                    $graph->qualification,
                    $graph->scratch,
                    $graph->metadata,
                    $graph->rawMetadata,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $graph->objectLeaseFactory,
                    $wrongReadPool,
                    $graph->backgroundWriter,
                    $graph->transitionExecutor,
                    $graph->restoreExecutor,
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $graph->accessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                );
                self::fail('A candidate graph accepted a foreign EFS read worker pool.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('exact qualified two-worker read pool', $error->getMessage());
            } finally {
                $wrongReadPool->shutdown();
            }
            $graph->partWriter->assertBoundTo($metadata, $graph->backend, 'STANDARD');
            $graph->objectCollector->assertBoundTo($metadata, $graph->objectWriter);
            $reader = $context->hostEvidenceReader();
            $graph->storageGarbageCollector->assertBoundTo($metadata, $graph->tiers, $reader);
            $graph->multipartIntentCollector->assertBoundTo($metadata, $graph->tiers, $reader);
            $graph->backgroundWriter->assertBoundTo($graph->objectWriter, $graph->objectLeaseFactory);
            $graph->transitionExecutor->assertBoundTo($metadata, $graph->tiers, $reader, $graph->backgroundWriter);
            $graph->notifications->assertBoundTo($metadata, $reader);
            $graph->accessLogs->assertBoundTo(
                $metadata,
                $graph->backend,
                'STANDARD',
                $graph->objectWriter,
                $graph->objectLeaseFactory,
                $reader,
                $graph->notifications,
                $graph->accessLogQuotas,
                $graph->accessLogEncryption,
            );
            $graph->restoreExecutor->assertBoundTo($metadata, $graph->tiers, $reader, $graph->backgroundWriter, $graph->notifications);
            $otherNotifications = new NotificationDispatcher($metadata, efsEvidence: $reader);
            $wrongAccessLogs = new AccessLogWriter(
                $metadata,
                $graph->backend,
                storageTier: 'STANDARD',
                encryption: $graph->accessLogEncryption,
                quotas: $graph->accessLogQuotas,
                notifications: $otherNotifications,
                localFencedWriter: $graph->objectWriter,
                localLeaseFactory: $graph->objectLeaseFactory,
                efsEvidence: $reader,
            );
            foreach ([
                [$otherNotifications, $graph->accessLogQuotas, $reader],
                [$graph->notifications, \OpsFour\S3Server\Quota\QuotaManager::fromGlobalConfig($metadata, $config->quota), $reader],
                [$graph->notifications, $graph->accessLogQuotas, $this->reader(driver: 'mysql')->hostEvidenceReader()],
            ] as [$candidateNotifications, $candidateQuotas, $candidateReader]) {
                try {
                    $graph->accessLogs->assertBoundTo(
                        $metadata,
                        $graph->backend,
                        'STANDARD',
                        $graph->objectWriter,
                        $graph->objectLeaseFactory,
                        $candidateReader,
                        $candidateNotifications,
                        $candidateQuotas,
                        $graph->accessLogEncryption,
                    );
                    self::fail('Access logs accepted a different graph dependency.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('Access log delivery differs', $error->getMessage());
                }
            }
            try {
                $graph->restoreExecutor->assertBoundTo($metadata, $graph->tiers, $reader, $graph->backgroundWriter, $otherNotifications);
                self::fail('Restore accepted a different notification dispatcher.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Restore executor differs', $error->getMessage());
            }
            try {
                new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $metadata,
                    $metadata,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $graph->objectLeaseFactory,
                    $graph->readPool,
                    $graph->backgroundWriter,
                    $graph->transitionExecutor,
                    $graph->restoreExecutor,
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $wrongAccessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                );
                self::fail('A candidate graph accepted access logs with another notification dispatcher.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Access log delivery differs', $error->getMessage());
            }
            try {
                new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $metadata,
                    $metadata,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $graph->objectLeaseFactory,
                    $graph->readPool,
                    $graph->backgroundWriter,
                    $graph->transitionExecutor,
                    $graph->restoreExecutor,
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $otherNotifications,
                    $graph->accessLogQuotas,
                    $wrongAccessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                );
                self::fail('A candidate graph accepted a second notification dispatcher.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Restore executor differs', $error->getMessage());
            }
            $graph->restoreGarbageCollector->assertBoundTo($metadata, $graph->tiers, $reader);
            $graph->lifecycleExecutor->assertBoundTo($metadata, $graph->backend, $graph->tiers, $reader);
            $graph->lifecycleExecutor->assertNotificationDispatcher($graph->notifications);
            try {
                $graph->lifecycleExecutor->assertNotificationDispatcher($otherNotifications);
                self::fail('Lifecycle accepted a different notification dispatcher.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Lifecycle notifications differ', $error->getMessage());
            }
            self::assertSame($graph->lifecycleExecutor, (new \ReflectionProperty($graph->lifecycleRunner, 'executor'))->getValue($graph->lifecycleRunner));
            self::assertNull((new \ReflectionProperty($graph->lifecycleRunner, 'callbackId'))->getValue($graph->lifecycleRunner));
            try {
                new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $metadata,
                    $metadata,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $graph->objectLeaseFactory,
                    $graph->readPool,
                    $graph->backgroundWriter,
                    $graph->transitionExecutor,
                    $graph->restoreExecutor,
                    $graph->restoreGarbageCollector,
                    new LifecycleExecutor($metadata, new InMemoryBackend(), storageTiers: $graph->tiers, efsEvidence: $reader),
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $graph->accessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                );
                self::fail('A lifecycle executor using a different backend entered the graph.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Lifecycle executor differs', $error->getMessage());
            }
            $differentAdapter = new LocalFencedBackgroundObjectWriter($graph->objectWriter, $graph->objectLeaseFactory);
            foreach ([$graph->transitionExecutor, $graph->restoreExecutor] as $executor) {
                try {
                    $executor->assertBoundTo($metadata, $graph->tiers, $reader, $differentAdapter);
                    self::fail('A background executor accepted a different publisher adapter.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('differs', $error->getMessage());
                }
            }
            self::assertSame(1, $this->storageStats);
            self::assertTrue(unlink($this->evidencePath));
            try {
                $graph->notifications->enqueueWebhooks(new S3Event('s3:ObjectCreated:Put', 'bucket', 'key', bucketGeneration: 'generation'));
                self::fail('Candidate notification dispatcher accepted a tripped watcher.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('evidence', strtolower($error->getMessage()));
            }
            foreach ([$graph->storageGarbageCollector, $graph->multipartIntentCollector, $graph->restoreGarbageCollector] as $recovery) {
                try {
                    $recovery->collect(1);
                    self::fail('Candidate recovery claimed SQL work after the watcher disappeared.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('evidence', strtolower($error->getMessage()));
                }
            }
            foreach ([$graph->transitionExecutor, $graph->restoreExecutor] as $executor) {
                try {
                    $executor->processNext(1);
                    self::fail('Candidate executor claimed SQL work after the watcher disappeared.');
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('evidence', strtolower($error->getMessage()));
                }
            }
            try {
                $graph->lifecycleRunner->start();
                self::fail('Candidate lifecycle timer started after the watcher disappeared.');
            } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
            }
            try {
                $graph->lifecycleExecutor->execute();
                self::fail('Candidate lifecycle sweep reached SQL after the watcher disappeared.');
            } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
            }
        } finally {
            $graph->shutdown();
            $graph->shutdown();
        }
        self::assertFalse($graph->readPool->isRunning());
        self::assertNull((new \ReflectionProperty($graph->backend, 'efsReadPool'))->getValue($graph->backend));
    }

    public function test_candidate_shutdown_keeps_resources_owned_until_lifecycle_sweep_finishes(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $context = EfsQualificationContext::qualify($this->reader(driver: 'mysql'), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $metadata = MysqlMetadataStore::fromDsn($dsn);
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $stopLogged = new DeferredFuture();
        $logger = new class ($entered, $release, $stopLogged) extends AbstractLogger {
            /**
             * @param  DeferredFuture<void>  $entered
             * @param  DeferredFuture<void>  $release
             * @param  DeferredFuture<void>  $stopLogged
             */
            public function __construct(
                private readonly DeferredFuture $entered,
                private readonly DeferredFuture $release,
                private readonly DeferredFuture $stopLogged,
            ) {}

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if (($context['event'] ?? null) === 'sweep_started' && ! $this->entered->getFuture()->isComplete()) {
                    $this->entered->complete();
                    $this->release->getFuture()->await();
                }
                if (($context['event'] ?? null) === 'runner_stopped' && ! $this->stopLogged->getFuture()->isComplete()) {
                    $this->stopLogged->complete();
                }
            }
        };
        $config = new S3ServerConfig(
            storagePath: $this->storageRoot,
            metadataDriver: 'mysql',
            metadataDsn: $dsn,
            lifecycleIntervalSeconds: 0.01,
        );
        $graph = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
            $context,
            $scratch,
            $config,
            $metadata,
            $logger,
        );
        $shutdown = null;
        // The production lifecycle timer is intentionally unreferenced. Keep
        // the loop alive while the test waits for its first callback.
        $keepAlive = EventLoop::repeat(0.1, static function (): void {});
        try {
            $graph->lifecycleRunner->start();
            $entered->getFuture()->await(new TimeoutCancellation(2));
            $shutdown = \Amp\async(static fn() => $graph->shutdown());
            $stopLogged->getFuture()->await(new TimeoutCancellation(2));

            self::assertFalse($shutdown->isComplete());
            self::assertTrue($graph->readPool->isRunning());
            $graph->objectWriter->assertBoundTo($graph->backend, 'STANDARD', $context->hostEvidenceReader());
            $graph->partWriter->assertBoundTo($metadata, $graph->backend, 'STANDARD');
        } finally {
            EventLoop::cancel($keepAlive);
            if (! $release->getFuture()->isComplete()) {
                $release->complete();
            }
            $shutdown?->await(new TimeoutCancellation(5));
            $graph->shutdown();
        }

        self::assertFalse($graph->readPool->isRunning());
    }

    public function test_candidate_handler_registration_uses_exact_graph_and_seals_inert_registry(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $context = EfsQualificationContext::qualify($this->reader(driver: 'mysql'), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $metadata = MysqlMetadataStore::fromDsn($dsn);
        $config = new S3ServerConfig(storagePath: $this->storageRoot, metadataDriver: 'mysql', metadataDsn: $dsn);
        $graph = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
            $context,
            $scratch,
            $config,
            $metadata,
            new NullLogger(),
        );
        $wrongPart = null;
        $wrongObject = null;
        try {
            $wrongConfigRegistry = new HandlerRegistry();
            try {
                HandlerRegistrar::registerQualifiedEfsCandidate(
                    $wrongConfigRegistry,
                    $graph,
                    $config->with(['metadataDsn' => 'host=127.0.0.1 user=test db=other']),
                );
                self::fail('A candidate with the wrong SQL identity was registered.');
            } catch (\RuntimeException) {
                self::assertSame([], $wrongConfigRegistry->storageRegistrations());
                self::assertFalse($wrongConfigRegistry->has(S3Operation::PutObject));
            }

            $sameScopeDifferentConfig = $config->with([
                'lifecycleIntervalSeconds' => $config->lifecycleIntervalSeconds + 1.0,
            ]);
            $driftRegistry = new HandlerRegistry();
            try {
                HandlerRegistrar::registerQualifiedEfsCandidate($driftRegistry, $graph, $sameScopeDifferentConfig);
                self::fail('A candidate handler graph accepted another configuration object.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('exact resource-graph configuration', $error->getMessage());
                self::assertSame([], $driftRegistry->storageRegistrations());
                self::assertFalse($driftRegistry->has(S3Operation::PutObject));
                $driftRegistry->register(S3Operation::PutObject, $this->createMock(\Amp\Http\Server\RequestHandler::class));
                self::assertTrue($driftRegistry->has(S3Operation::PutObject));
            }

            $manualGraph = new EfsCandidateRuntimeResources(
                $graph->qualification,
                $graph->scratch,
                $graph->metadata,
                $graph->rawMetadata,
                $graph->backend,
                $graph->tiers,
                $graph->objectWriter,
                $graph->partWriter,
                $graph->objectCollector,
                $graph->storageGarbageCollector,
                $graph->multipartIntentCollector,
                $graph->objectRenewer,
                $graph->objectLeaseFactory,
                $graph->readPool,
                $graph->backgroundWriter,
                $graph->transitionExecutor,
                $graph->restoreExecutor,
                $graph->restoreGarbageCollector,
                $graph->lifecycleExecutor,
                $graph->notifications,
                $graph->accessLogQuotas,
                $graph->accessLogs,
                $graph->accessLogEncryption,
                $config->lifecycleIntervalSeconds,
                new NullLogger(),
            );
            $manualRegistry = new HandlerRegistry();
            try {
                HandlerRegistrar::registerQualifiedEfsCandidate($manualRegistry, $manualGraph, $config);
                self::fail('A manually composed graph without a build configuration was registered.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('exact resource-graph configuration', $error->getMessage());
                self::assertSame([], $manualRegistry->storageRegistrations());
                $manualRegistry->register(S3Operation::PutObject, $this->createMock(\Amp\Http\Server\RequestHandler::class));
                self::assertTrue($manualRegistry->has(S3Operation::PutObject));
            }

            $wrongNotificationRegistry = new HandlerRegistry();
            try {
                HandlerRegistrar::registerQualifiedEfsCandidate(
                    $wrongNotificationRegistry,
                    $graph,
                    $config,
                    notifications: new NotificationDispatcher($metadata, efsEvidence: $context->hostEvidenceReader()),
                );
                self::fail('A second notification dispatcher reached candidate handlers.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('exact qualified notification dispatcher', $error->getMessage());
                self::assertSame([], $wrongNotificationRegistry->storageRegistrations());
                self::assertFalse($wrongNotificationRegistry->has(S3Operation::PutObject));
            }

            $wrongEncryptionRegistry = new HandlerRegistry();
            try {
                HandlerRegistrar::registerQualifiedEfsCandidate(
                    $wrongEncryptionRegistry,
                    $graph,
                    $config,
                    encryption: $this->createMock(EncryptionServiceInterface::class),
                );
                self::fail('Candidate handlers accepted encryption while access logs had none.');
            } catch (\InvalidArgumentException $error) {
                self::assertStringContainsString('exact qualified encryption service', $error->getMessage());
                self::assertSame([], $wrongEncryptionRegistry->storageRegistrations());
                self::assertFalse($wrongEncryptionRegistry->has(S3Operation::PutObject));
            }

            $wrongPart = new LocalFencedMultipartPartWriter(
                $this->storageRoot,
                $scratchPath,
                'OTHER',
                $metadata,
            );
            $mismatchedGraph = new EfsCandidateRuntimeResources(
                $context,
                $scratch,
                $metadata,
                $metadata,
                $graph->backend,
                $graph->tiers,
                $graph->objectWriter,
                $wrongPart,
                $graph->objectCollector,
                $graph->storageGarbageCollector,
                $graph->multipartIntentCollector,
                $graph->objectRenewer,
                $graph->objectLeaseFactory,
                $graph->readPool,
                $graph->backgroundWriter,
                $graph->transitionExecutor,
                $graph->restoreExecutor,
                $graph->restoreGarbageCollector,
                $graph->lifecycleExecutor,
                $graph->notifications,
                $graph->accessLogQuotas,
                $graph->accessLogs,
                $graph->accessLogEncryption,
                $config->lifecycleIntervalSeconds,
                new NullLogger(),
                $config,
            );
            $wrongPartRegistry = new HandlerRegistry();
            try {
                HandlerRegistrar::registerQualifiedEfsCandidate($wrongPartRegistry, $mismatchedGraph, $config);
                self::fail('A candidate with a mismatched part writer was registered.');
            } catch (\InvalidArgumentException) {
                self::assertSame([], $wrongPartRegistry->storageRegistrations());
                self::assertFalse($wrongPartRegistry->has(S3Operation::UploadPart));
            }

            $wrongObject = new LocalFencedObjectWriter(
                $this->storageRoot,
                $scratchPath,
                'STANDARD',
                budgetBytes: $scratch->budgetBytes(),
                efsEvidence: $this->reader(driver: 'mysql')->hostEvidenceReader(),
            );
            $wrongBackground = new LocalFencedBackgroundObjectWriter($wrongObject, $graph->objectLeaseFactory);
            $wrongTransition = new \OpsFour\S3Server\Storage\TierTransitionExecutor(
                $metadata,
                $graph->tiers,
                localFencedWriter: $wrongBackground,
                efsEvidence: $context->hostEvidenceReader(),
            );
            $wrongRestore = new \OpsFour\S3Server\Storage\RestoreExecutor(
                $metadata,
                $graph->tiers,
                localFencedWriter: $wrongBackground,
                efsEvidence: $context->hostEvidenceReader(),
            );
            $wrongObjectRegistry = new HandlerRegistry();
            try {
                $wrongObjectGraph = new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $metadata,
                    $metadata,
                    $graph->backend,
                    $graph->tiers,
                    $wrongObject,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $graph->objectLeaseFactory,
                    $graph->readPool,
                    $wrongBackground,
                    $wrongTransition,
                    $wrongRestore,
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $graph->accessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                    $config,
                );
                HandlerRegistrar::registerQualifiedEfsCandidate($wrongObjectRegistry, $wrongObjectGraph, $config);
                self::fail('A candidate with another host reader was admitted.');
            } catch (\RuntimeException) {
                self::assertSame([], $wrongObjectRegistry->storageRegistrations());
                self::assertFalse($wrongObjectRegistry->has(S3Operation::PutObject));
            }

            $wrongLease = new AmpLocalObjectWriteLeaseGuardFactory(
                new AmpLocalObjectWriteLeaseSql(MysqlMetadataStore::fromDsn($dsn)),
                $graph->objectRenewer,
                new LocalObjectWriteLeaseAdmission(),
            );
            $wrongLeaseBackground = new LocalFencedBackgroundObjectWriter($graph->objectWriter, $wrongLease);
            $wrongLeaseRegistry = new HandlerRegistry();
            try {
                $wrongLeaseGraph = new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $metadata,
                    $metadata,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $wrongLease,
                    $graph->readPool,
                    $wrongLeaseBackground,
                    new \OpsFour\S3Server\Storage\TierTransitionExecutor($metadata, $graph->tiers, localFencedWriter: $wrongLeaseBackground, efsEvidence: $context->hostEvidenceReader()),
                    new \OpsFour\S3Server\Storage\RestoreExecutor($metadata, $graph->tiers, localFencedWriter: $wrongLeaseBackground, efsEvidence: $context->hostEvidenceReader()),
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $graph->accessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                    $config,
                );
                HandlerRegistrar::registerQualifiedEfsCandidate($wrongLeaseRegistry, $wrongLeaseGraph, $config);
                self::fail('A candidate with another lease SQL store was admitted.');
            } catch (\RuntimeException) {
                self::assertSame([], $wrongLeaseRegistry->storageRegistrations());
                self::assertFalse($wrongLeaseRegistry->has(S3Operation::PutObject));
            }

            $otherRenewer = (new \ReflectionClass(DedicatedAmpObjectWriteLeaseRenewer::class))->newInstanceWithoutConstructor();
            $otherLease = new AmpLocalObjectWriteLeaseGuardFactory(
                new AmpLocalObjectWriteLeaseSql($metadata),
                $otherRenewer,
                new LocalObjectWriteLeaseAdmission(),
            );
            $otherBackground = new LocalFencedBackgroundObjectWriter($graph->objectWriter, $otherLease);
            $wrongRenewerRegistry = new HandlerRegistry();
            try {
                $wrongRenewerGraph = new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $metadata,
                    $metadata,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    $graph->storageGarbageCollector,
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $otherLease,
                    $graph->readPool,
                    $otherBackground,
                    new \OpsFour\S3Server\Storage\TierTransitionExecutor($metadata, $graph->tiers, localFencedWriter: $otherBackground, efsEvidence: $context->hostEvidenceReader()),
                    new \OpsFour\S3Server\Storage\RestoreExecutor($metadata, $graph->tiers, localFencedWriter: $otherBackground, efsEvidence: $context->hostEvidenceReader()),
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $graph->accessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                    $config,
                );
                HandlerRegistrar::registerQualifiedEfsCandidate($wrongRenewerRegistry, $wrongRenewerGraph, $config);
                self::fail('A candidate with another lease renewer was admitted.');
            } catch (\RuntimeException) {
                self::assertSame([], $wrongRenewerRegistry->storageRegistrations());
                self::assertFalse($wrongRenewerRegistry->has(S3Operation::PutObject));
            }

            $registry = new HandlerRegistry();
            HandlerRegistrar::registerQualifiedEfsCandidate($registry, $graph, $config);
            self::assertSame([['storage' => $graph->backend, 'tiers' => $graph->tiers]], $registry->storageRegistrations());
            foreach ([
                [S3Operation::PutObject, 'localFencedWriter', $graph->objectWriter],
                [S3Operation::CopyObject, 'localFencedWriter', $graph->objectWriter],
                [S3Operation::UploadPart, 'localPartWriter', $graph->partWriter],
                [S3Operation::UploadPartCopy, 'localPartWriter', $graph->partWriter],
            ] as [$operation, $property, $expected]) {
                self::assertSame($expected, (new \ReflectionProperty($registry->get($operation), $property))->getValue($registry->get($operation)));
            }
            foreach ([S3Operation::GetObject, S3Operation::CompleteMultipartUpload, S3Operation::SelectObjectContent] as $operation) {
                self::assertTrue($registry->has($operation));
            }
            self::assertSame($graph->backend, (new \ReflectionProperty($registry->get(S3Operation::GetObject), 'storage'))->getValue($registry->get(S3Operation::GetObject)));
            foreach ([S3Operation::PutObject, S3Operation::CopyObject, S3Operation::CompleteMultipartUpload] as $operation) {
                self::assertSame($graph->notifications, (new \ReflectionProperty($registry->get($operation), 'notifications'))->getValue($registry->get($operation)));
            }
            self::assertTrue((new \ReflectionProperty($registry->get(S3Operation::CreateMultipartUpload), 'requireWorkerCompatibleSseS3'))->getValue($registry->get(S3Operation::CreateMultipartUpload)));
            self::assertSame($registry->get(S3Operation::PutObject), (new \ReflectionProperty($registry->get(S3Operation::PostObject), 'putObject'))->getValue($registry->get(S3Operation::PostObject)));
            self::assertTrue((new \ReflectionProperty($registry->get(S3Operation::CompleteMultipartUpload), 'requireLocalFencedCompletion'))->getValue($registry->get(S3Operation::CompleteMultipartUpload)));
            try {
                $registry->registerStorage($graph->backend, $graph->tiers);
                self::fail('The inert candidate registry remained mutable.');
            } catch (\LogicException) {
                self::assertTrue($registry->has(S3Operation::PutObject));
            }
            self::assertSame(1, $this->storageStats);
        } finally {
            $wrongObject?->shutdown();
            $wrongPart?->shutdown();
            $graph->shutdown();
        }
    }

    public function test_candidate_recovery_capabilities_reject_incomplete_metadata_before_resource_construction(): void
    {
        $incomplete = $this->createMock(MetadataStore::class);
        try {
            EfsCandidateRecoveryCapabilities::assertReady($incomplete);
            self::fail('An incomplete metadata facade was admitted to EFS recovery.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('generation-fenced storage and multipart recovery', $error->getMessage());
        }

        $raw = MysqlMetadataStore::fromDsn(self::POSTGRES_DSN);
        $observed = new ObservedMetadataStore($raw, new MetricsCollector(), 'mysql');
        self::assertSame($raw, EfsCandidateRecoveryCapabilities::assertReady($raw));
        self::assertSame($observed, EfsCandidateRecoveryCapabilities::assertReady($observed));
        EfsCandidateRecoveryCapabilities::assertBackgroundReady($raw);
        EfsCandidateRecoveryCapabilities::assertBackgroundReady($observed);
        try {
            EfsCandidateRecoveryCapabilities::assertBackgroundReady($incomplete);
            self::fail('An incomplete metadata facade was admitted to EFS background jobs.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('fenced background queue', $error->getMessage());
        }
    }

    public function test_candidate_graph_binds_one_observed_facade_to_one_native_sql_store(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $context = EfsQualificationContext::qualify($this->reader(driver: 'mysql'), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $raw = MysqlMetadataStore::fromDsn($dsn);
        $observed = new ObservedMetadataStore($raw, new MetricsCollector(), 'mysql');
        $config = new S3ServerConfig(storagePath: $this->storageRoot, metadataDriver: 'mysql', metadataDsn: $dsn);
        $factory = new S3ServerRuntimeFactory();
        $writersConstructed = false;

        foreach ([
            [$raw, new ObservedMetadataStore(MysqlMetadataStore::fromDsn($dsn), new MetricsCollector(), 'mysql')],
            [$raw, new ObservedMetadataStore($observed, new MetricsCollector(), 'mysql')],
            [$raw, new ObservedMetadataStore(new CachedMetadataStoreDecorator($raw, 0.0), new MetricsCollector(), 'mysql')],
            [$observed, null],
        ] as [$candidateRaw, $candidateObserved]) {
            try {
                $factory->createQualifiedEfsCandidateResources(
                    $context,
                    $scratch,
                    $config,
                    $candidateRaw,
                    new NullLogger(),
                    static function () use (&$writersConstructed): void {
                        $writersConstructed = true;
                    },
                    $candidateObserved,
                );
                self::fail('A candidate graph accepted mismatched or decorated native SQL metadata.');
            } catch (\RuntimeException) {
                self::assertFalse($writersConstructed);
                self::assertSame(1, $this->storageStats);
            }
        }

        $graph = $factory->createQualifiedEfsCandidateResources(
            $context,
            $scratch,
            $config,
            $raw,
            new NullLogger(),
            observedMetadata: $observed,
        );
        try {
            self::assertSame($raw, $graph->rawMetadata);
            self::assertSame($observed, $graph->metadata);
            $graph->partWriter->assertBoundTo($observed, $graph->backend, 'STANDARD');
            $graph->objectCollector->assertBoundTo($observed, $graph->objectWriter);
            $reader = $context->hostEvidenceReader();
            $graph->storageGarbageCollector->assertBoundTo($observed, $graph->tiers, $reader);
            $graph->multipartIntentCollector->assertBoundTo($observed, $graph->tiers, $reader);
            $graph->transitionExecutor->assertBoundTo($observed, $graph->tiers, $reader, $graph->backgroundWriter);
            $graph->notifications->assertBoundTo($observed, $reader);
            $graph->restoreExecutor->assertBoundTo($observed, $graph->tiers, $reader, $graph->backgroundWriter, $graph->notifications);
            $graph->restoreGarbageCollector->assertBoundTo($observed, $graph->tiers, $reader);
            try {
                new EfsCandidateRuntimeResources(
                    $context,
                    $scratch,
                    $observed,
                    $raw,
                    $graph->backend,
                    $graph->tiers,
                    $graph->objectWriter,
                    $graph->partWriter,
                    $graph->objectCollector,
                    new StorageGarbageCollector($raw, $graph->tiers, efsEvidence: $reader),
                    $graph->multipartIntentCollector,
                    $graph->objectRenewer,
                    $graph->objectLeaseFactory,
                    $graph->readPool,
                    $graph->backgroundWriter,
                    $graph->transitionExecutor,
                    $graph->restoreExecutor,
                    $graph->restoreGarbageCollector,
                    $graph->lifecycleExecutor,
                    $graph->notifications,
                    $graph->accessLogQuotas,
                    $graph->accessLogs,
                    $graph->accessLogEncryption,
                    $config->lifecycleIntervalSeconds,
                    new NullLogger(),
                );
                self::fail('A collector using raw SQL bypassed the handler metadata facade.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('collector differs', $error->getMessage());
            }
            $leaseSql = (new \ReflectionProperty($graph->objectLeaseFactory, 'sql'))->getValue($graph->objectLeaseFactory);
            self::assertSame($raw, (new \ReflectionProperty($leaseSql, 'store'))->getValue($leaseSql));
            self::assertSame(1, $this->storageStats);
        } finally {
            $graph->shutdown();
        }
    }

    public function test_candidate_graph_construction_failure_shuts_down_already_owned_workers_and_backend(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $context = EfsQualificationContext::qualify($this->reader(driver: 'mysql'), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $metadata = MysqlMetadataStore::fromDsn($dsn);
        $observed = new ObservedMetadataStore($metadata, new MetricsCollector(), 'mysql');
        $config = new S3ServerConfig(storagePath: $this->storageRoot, metadataDriver: 'mysql', metadataDsn: $dsn);
        $owned = null;
        $readPool = null;

        try {
            (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
                $context,
                $scratch,
                $config,
                $metadata,
                new NullLogger(),
                static function (FilesystemBackend $backend, LocalFencedObjectWriter $object, \OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter $part) use (&$owned, &$readPool): void {
                    $owned = [$backend, $object, $part];
                    $readPool = (new \ReflectionProperty($backend, 'efsReadPool'))->getValue($backend);
                    throw new \RuntimeException('Injected failure after resource acquisition.');
                },
                $observed,
            );
            self::fail('A candidate construction fault unexpectedly returned a resource graph.');
        } catch (\RuntimeException $error) {
            self::assertSame('Injected failure after resource acquisition.', $error->getMessage());
        }

        self::assertIsArray($owned);
        [$backend, $object, $part] = $owned;
        self::assertTrue((new \ReflectionProperty($object, 'shuttingDown'))->getValue($object));
        self::assertTrue((new \ReflectionProperty($part, 'shuttingDown'))->getValue($part));
        self::assertInstanceOf(\Amp\Parallel\Worker\ContextWorkerPool::class, $readPool);
        self::assertFalse($readPool->isRunning());
        self::assertSame([], (new \ReflectionProperty($backend, 'ownedWorkerPools'))->getValue($backend));
        self::assertNull((new \ReflectionProperty($backend, 'efsReadPool'))->getValue($backend));
        self::assertSame(1, $this->storageStats);
    }

    public function test_candidate_bootstrap_failure_retains_partial_graph_until_worker_stop_retry(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $context = EfsQualificationContext::qualify($this->reader(driver: 'mysql'), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $metadata = MysqlMetadataStore::fromDsn($dsn);
        $config = new S3ServerConfig(storagePath: $this->storageRoot, metadataDriver: 'mysql', metadataDsn: $dsn);
        $owned = null;
        $stopCalls = 0;

        try {
            (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
                $context,
                $scratch,
                $config,
                $metadata,
                new NullLogger(),
                function (FilesystemBackend $backend, LocalFencedObjectWriter $object, LocalFencedMultipartPartWriter $part) use (&$owned, &$stopCalls): Closure {
                    $owned = [$backend, $object, $part];
                    self::assertTrue(unlink($this->evidencePath));

                    return static function () use (&$stopCalls): void {
                        if (++$stopCalls === 1) {
                            throw new \RuntimeException('Injected worker stop failure.');
                        }
                    };
                },
            );
            self::fail('A failed partial shutdown unexpectedly returned a graph.');
        } catch (EfsCandidateBootstrapException $error) {
            self::assertSame('EFS candidate bootstrap failed and resource shutdown is incomplete.', $error->getMessage());
            self::assertInstanceOf(\RuntimeException::class, $error->getPrevious());
            self::assertSame('EFS host evidence is unavailable or invalid.', $error->getPrevious()->getMessage());
            self::assertFalse($error->resources->isClosed());
            self::assertIsArray($owned);
            [$backend, $object, $part] = $owned;
            self::assertFalse((new \ReflectionProperty($part, 'shuttingDown'))->getValue($part));
            self::assertTrue((new \ReflectionProperty($object, 'shuttingDown'))->getValue($object));
            self::assertNotSame([], (new \ReflectionProperty($backend, 'ownedWorkerPools'))->getValue($backend));
            $readPool = (new \ReflectionProperty($backend, 'efsReadPool'))->getValue($backend);
            self::assertInstanceOf(\Amp\Parallel\Worker\ContextWorkerPool::class, $readPool);
            self::assertTrue($readPool->isRunning());

            $error->resources->shutdown();
            $error->resources->shutdown();
            self::assertTrue($error->resources->isClosed());
            self::assertSame(2, $stopCalls);
            self::assertTrue((new \ReflectionProperty($part, 'shuttingDown'))->getValue($part));
            self::assertSame([], (new \ReflectionProperty($backend, 'ownedWorkerPools'))->getValue($backend));
            self::assertFalse($readPool->isRunning());
        }
    }

    public function test_candidate_graph_shutdown_retains_dependencies_after_worker_close_failure_and_can_retry(): void
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);
        $this->simulatedStorageDevice = $storage['dev'] + 1;
        self::assertTrue(unlink($this->evidencePath));
        $this->evidencePath = $this->evidenceDirectory . '/' . self::RUN_ID . '-api-one-mysql.json';
        $record = $this->record();
        $record['driver'] = 'mysql';
        $this->writeRecord($record);
        $context = EfsQualificationContext::qualify($this->reader(driver: 'mysql'), $this->storageRoot, fn(): string => $this->mountInfo());
        $scratchPath = $this->base . '/scratch';
        self::assertTrue(mkdir($scratchPath, 0o700));
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, fn(): string => $this->mountInfo());
        $dsn = 'host=127.0.0.1 user=test db=qualified';
        $metadata = MysqlMetadataStore::fromDsn($dsn);
        $config = new S3ServerConfig(storagePath: $this->storageRoot, metadataDriver: 'mysql', metadataDsn: $dsn);
        $graph = (new S3ServerRuntimeFactory())->createQualifiedEfsCandidateResources(
            $context,
            $scratch,
            $config,
            $metadata,
            new NullLogger(),
        );
        $graph->partWriter->shutdown();

        $closeCalls = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(2))->method('shutdown')->willReturnCallback(static function () use (&$closeCalls): void {
            if (++$closeCalls === 1) {
                throw new \RuntimeException('Injected worker close failure.');
            }
        });
        $failingPart = new LocalFencedMultipartPartWriter(
            $this->storageRoot,
            $scratchPath,
            'STANDARD',
            $metadata,
            writePool: $pool,
        );
        $owned = new EfsCandidateRuntimeResources(
            $context,
            $scratch,
            $metadata,
            $metadata,
            $graph->backend,
            $graph->tiers,
            $graph->objectWriter,
            $failingPart,
            $graph->objectCollector,
            $graph->storageGarbageCollector,
            $graph->multipartIntentCollector,
            $graph->objectRenewer,
            $graph->objectLeaseFactory,
            $graph->readPool,
            $graph->backgroundWriter,
            $graph->transitionExecutor,
            $graph->restoreExecutor,
            $graph->restoreGarbageCollector,
            $graph->lifecycleExecutor,
            $graph->notifications,
            $graph->accessLogQuotas,
            $graph->accessLogs,
            $graph->accessLogEncryption,
            $config->lifecycleIntervalSeconds,
            new NullLogger(),
        );
        try {
            $owned->shutdown();
            self::fail('A failed worker close was swallowed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('Multipart worker shutdown is uncertain', $error->getMessage());
        }
        self::assertTrue((new \ReflectionProperty($graph->objectWriter, 'shuttingDown'))->getValue($graph->objectWriter));
        self::assertNotSame([], (new \ReflectionProperty($graph->backend, 'ownedWorkerPools'))->getValue($graph->backend));
        self::assertTrue($graph->readPool->isRunning());
        self::assertFalse((new \ReflectionProperty($owned, 'renewerClosed'))->getValue($owned));
        self::assertFalse((new \ReflectionProperty($owned, 'backendClosed'))->getValue($owned));
        self::assertFalse((new \ReflectionProperty($owned, 'readPoolClosed'))->getValue($owned));
        $owned->shutdown();
        $owned->shutdown();
        self::assertSame(2, $closeCalls);
        self::assertSame([], (new \ReflectionProperty($graph->backend, 'ownedWorkerPools'))->getValue($graph->backend));
        self::assertFalse($graph->readPool->isRunning());
    }

    public function test_qualification_context_does_not_create_a_missing_root(): void
    {
        self::assertTrue(rmdir($this->storageRoot));
        $reader = $this->reader();
        try {
            EfsQualificationContext::qualify($reader, $this->storageRoot, fn(): string => $this->mountInfo());
            self::fail('A missing NFS root was qualified.');
        } catch (\RuntimeException) {
            self::assertDirectoryDoesNotExist($this->storageRoot);
            self::assertSame(0, $this->storageStats);
        }
    }

    public function test_qualification_context_rejects_a_different_mounted_root(): void
    {
        $otherRoot = $this->base . '/other-storage';
        self::assertTrue(mkdir($otherRoot, 0o700));
        $reader = $this->reader();
        try {
            $mountInfo = static function (): string {
                self::fail('Mountinfo was read for a root outside pinned evidence.');
            };
            try {
                EfsQualificationContext::qualify($reader, $otherRoot, $mountInfo);
                self::fail('A different NFS root was qualified.');
            } catch (\RuntimeException) {
                self::assertSame(0, $this->storageStats);
            }
        } finally {
            self::assertTrue(rmdir($otherRoot));
        }
    }

    public function test_qualification_context_rejects_epoch_change_during_preflight(): void
    {
        $reader = $this->reader();
        $mountInfo = function (): string {
            $record = $this->record();
            $record['epoch'] = str_repeat('d', 32);
            $this->writeRecord($record);

            return $this->mountInfo();
        };
        try {
            EfsQualificationContext::qualify($reader, $this->storageRoot, $mountInfo);
            self::fail('A replacement watcher epoch was qualified.');
        } catch (\RuntimeException $error) {
            self::assertStringNotContainsString($this->storageRoot, $error->getMessage());
            self::assertSame(0, $this->storageStats);
        }
    }

    public function test_qualification_context_latches_stale_evidence_after_creation(): void
    {
        $context = EfsQualificationContext::qualify($this->reader(), $this->storageRoot, fn(): string => $this->mountInfo());
        self::assertSame(1, $this->storageStats);
        $this->nowNs += 21_000_000_000;

        try {
            $context->workerScope();
            self::fail('Stale evidence was accepted by the context.');
        } catch (\RuntimeException) {
            self::assertSame(1, $this->storageStats);
        }
    }

    public function test_stale_wrong_boot_and_corrupt_json_fail_before_storage_root_access(): void
    {
        foreach (['stale', 'boot', 'json'] as $case) {
            $this->nowNs = self::NOW_NS;
            $this->bootId = self::BOOT_ID;
            $this->writeRecord($this->record());
            if ($case === 'stale') {
                $this->nowNs += 21_000_000_000;
            } elseif ($case === 'boot') {
                $this->bootId = '99999999-1234-1234-1234-123456789abc';
            } else {
                file_put_contents($this->evidencePath, '{broken-json' . "\n");
            }
            try {
                $this->reader();
                self::fail("{$case} evidence was accepted.");
            } catch (\RuntimeException $exception) {
                self::assertStringNotContainsString($this->storageRoot, $exception->getMessage());
            }
            self::assertSame(0, $this->storageStats);
        }
    }

    public function test_wrong_epoch_latches_without_adopting_the_new_record(): void
    {
        $reader = $this->reader();
        $firstEpoch = $reader->workerScope()->pinnedIdentity['epoch'];
        $record = $this->record();
        $record['epoch'] = str_repeat('d', 32);
        $this->writeRecord($record);

        try {
            $reader->assertFresh();
            self::fail('A changed watcher epoch was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unavailable or invalid', $exception->getMessage());
        }
        $this->writeRecord($this->record());
        $this->expectException(\RuntimeException::class);
        self::assertSame(str_repeat('e', 32), $firstEpoch);
        $reader->workerScope();
    }

    public function test_epoch_change_while_initializing_cannot_be_adopted(): void
    {
        $reads = 0;
        $mountInfo = function () use (&$reads): string {
            if (++$reads === 2) {
                $record = $this->record();
                $record['epoch'] = str_repeat('d', 32);
                $this->writeRecord($record);
            }

            return $this->mountInfo();
        };

        try {
            $this->reader($mountInfo);
            self::fail('A replacement watcher epoch was adopted during startup.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('unavailable or invalid', $exception->getMessage());
        }
        self::assertSame(0, $this->storageStats);
    }

    public function test_wrong_storage_inode_is_rejected_only_after_mount_preflight(): void
    {
        $record = $this->record();
        $record['storageIno'] = (int) $record['storageIno'] + 1;
        $this->writeRecord($record);
        $reader = $this->reader();
        self::assertSame(0, $this->storageStats);

        $preflight = new EfsStorageStartupPreflight($reader, fn(): string => $this->mountInfo());
        try {
            $preflight->assertExistingRoot($this->storageRoot);
            self::fail('A replaced storage root was accepted.');
        } catch (\RuntimeException $exception) {
            self::assertStringContainsString('identity', $exception->getMessage());
        }
        self::assertSame(1, $this->storageStats);
    }

    public function test_same_mount_root_replacement_is_rejected_even_after_prior_stat(): void
    {
        $reader = $this->reader();
        self::assertIsArray(@stat($this->storageRoot));
        $originalRoot = $this->base . '/original-storage';
        self::assertTrue(rename($this->storageRoot, $originalRoot));
        self::assertTrue(mkdir($this->storageRoot, 0o700));
        try {
            $preflight = new EfsStorageStartupPreflight($reader, fn(): string => $this->mountInfo());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('identity');
            $preflight->assertExistingRoot($this->storageRoot);
        } finally {
            self::assertTrue(rmdir($originalRoot));
        }
    }

    /** @param (Closure(): string)|null $mountInfoReader */
    private function reader(?Closure $mountInfoReader = null, string $driver = 'postgres'): EfsLocalStartupEvidenceReader
    {
        return new EfsLocalStartupEvidenceReader(
            $this->evidenceDirectory,
            $this->storageRoot,
            self::RUN_ID,
            'fs-12345678',
            'api-one',
            $driver,
            self::SOURCE_SHA,
            fn(): int => $this->nowNs,
            fn(): string => $this->bootId,
            $this->rootOwnedLstat(...),
            $this->rootOwnedFstat(...),
            $mountInfoReader ?? fn(): string => $this->mountInfo(),
            function (string $path): array|false {
                $this->storageStats++;
                $entry = @stat($path);
                if (is_array($entry) && $this->simulatedStorageDevice !== null) {
                    $entry['dev'] = $this->simulatedStorageDevice;
                }
                if (is_array($entry) && $this->simulatedStorageUid !== null) {
                    $entry['uid'] = $this->simulatedStorageUid;
                }
                if (is_array($entry) && $this->simulatedStorageMode !== null) {
                    $entry['mode'] = $this->simulatedStorageMode;
                }

                return $entry;
            },
        );
    }

    private function candidateConfig(): S3ServerConfig
    {
        return new S3ServerConfig(
            storagePath: $this->storageRoot,
            metadataDriver: 'postgres',
            metadataDsn: self::POSTGRES_DSN,
        );
    }

    /** @return array<array-key, int>|false */
    private function rootOwnedLstat(string $path): array|false
    {
        if ($path === $this->storageRoot || str_starts_with($path, $this->storageRoot . '/')) {
            self::fail('Storage root was accessed during local evidence validation.');
        }
        $entry = @lstat($path);
        if (is_array($entry)) {
            $entry['uid'] = 0;
        }

        return $entry;
    }

    /** @param resource $handle
     * @return array<array-key, int>|false
     */
    private function rootOwnedFstat($handle): array|false
    {
        $entry = @fstat($handle);
        if (is_array($entry)) {
            $entry['uid'] = 0;
        }

        return $entry;
    }

    /** @return array<string, int|string> */
    private function record(): array
    {
        $storage = @stat($this->storageRoot);
        self::assertIsArray($storage);

        return [
            'schemaVersion' => 1,
            'runId' => self::RUN_ID,
            'efsId' => 'fs-12345678',
            'sourceSha256' => self::SOURCE_SHA,
            'node' => 'api-one',
            'driver' => 'postgres',
            'containerId' => str_repeat('c', 64),
            'containerPid' => 1234,
            'relayPort' => 20049,
            'relayPid' => 5432,
            'hostMountId' => '39',
            'storageDev' => $this->simulatedStorageDevice ?? $storage['dev'],
            'storageIno' => $storage['ino'],
            'bootId' => self::BOOT_ID,
            'epoch' => str_repeat('e', 32),
            'issuedMonotonicNs' => self::NOW_NS,
            'expiresMonotonicNs' => self::NOW_NS + 20_000_000_000,
        ];
    }

    /** @param array<string, int|string> $record */
    private function writeRecord(array $record): void
    {
        file_put_contents($this->evidencePath, json_encode($record, JSON_THROW_ON_ERROR) . "\n");
        chmod($this->evidencePath, 0o644);
        clearstatcache(true, $this->evidencePath);
    }

    private function mountInfo(): string
    {
        return "1 0 8:1 / / rw - ext4 /dev/root rw\n"
            . "2 1 0:42 / {$this->evidenceDirectory} ro - tmpfs tmpfs rw\n"
            . "3 1 0:43 / {$this->storageRoot} rw - nfs4 127.0.0.1:/ rw\n";
    }
}

final class EfsProbeCredentialProvider implements CredentialHealthProbe, CredentialProvider
{
    public bool $available = true;

    public int $healthChecks = 0;

    public ?Closure $onHealthCheck = null;

    private InMemoryCredentialProvider $inner;

    public function __construct()
    {
        $this->inner = new InMemoryCredentialProvider();
    }

    public function assertCredentialStoreAvailable(): void
    {
        ++$this->healthChecks;
        if ($this->onHealthCheck !== null) {
            ($this->onHealthCheck)();
        }
        if (! $this->available) {
            throw new \RuntimeException('Credential store unavailable.');
        }
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        return $this->inner->getCredential($accessKeyId);
    }

    /** @return list<Credential> */
    public function listCredentials(): array
    {
        return array_values($this->inner->listCredentials());
    }

    public function putCredential(Credential $credential): void
    {
        $this->inner->putCredential($credential);
    }

    public function deleteCredential(string $accessKeyId): void
    {
        $this->inner->deleteCredential($accessKeyId);
    }
}

final class EfsRecursiveCleanupQueryState
{
    /** @var array<string, string> */
    public array $operationByTier = [];

    /** @var list<string> */
    public array $queriedTiers = [];

    /** @var list<string> */
    public array $referencedTiers = ['STANDARD'];

    public ?Closure $duringQuery = null;

    public ?Closure $duringTierInventory = null;
}

/** @implements \IteratorAggregate<int, array<string, int|float|string|null>> */
final class EfsRecursiveCleanupMysqlResult implements MysqlResult, \IteratorAggregate
{
    private int $offset = 0;

    /** @param list<array<string, int|float|string|null>> $rows */
    public function __construct(private readonly array $rows) {}

    /** @return \Traversable<int, array<string, int|float|string|null>> */
    public function getIterator(): \Traversable
    {
        while (($row = $this->fetchRow()) !== null) {
            yield $row;
        }
    }

    /** @return array<string, int|float|string|null>|null */
    public function fetchRow(): ?array
    {
        return $this->rows[$this->offset++] ?? null;
    }

    public function getNextResult(): ?MysqlResult
    {
        return null;
    }

    public function getRowCount(): int
    {
        return count($this->rows);
    }

    public function getColumnCount(): int
    {
        return $this->rows === [] ? 0 : count($this->rows[0]);
    }

    public function getLastInsertId(): ?int
    {
        return null;
    }

    public function getColumnDefinitions(): ?array
    {
        return null;
    }
}
