<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Parallel\Worker\WorkerPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\Runtime\StorageWriteFenceStartupGuard;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3FencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\LocalScratchStager;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class PostgresAwsS3OperationalSummaryTest extends TestCase
{
    private PostgresConnectionPool $pool;
    private string $suffix;

    protected function setUp(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the PostgreSQL AWS aggregate gate.');
        }
        $this->pool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $database = $this->pool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertIsString($database['database_name'] ?? null);
        self::assertStringStartsWith('s3audit_', $database['database_name'], 'The AWS aggregate gate requires an isolated audit database.');
        (new PostgresMetadataStore($this->pool))->initialize();
        $this->suffix = bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! isset($this->pool, $this->suffix)) {
            return;
        }
        $like = '%qualified-aws-' . $this->suffix . '%';
        $this->pool->execute('DELETE FROM s3_object_write_intents WHERE storage_path LIKE $1', [$like]);
        $this->pool->execute('DELETE FROM s3_storage_garbage WHERE storage_path LIKE $1', [$like]);
        $this->pool->execute('DELETE FROM s3_multipart_write_intents WHERE assembly_path LIKE $1', [$like]);
        $this->pool->close();
    }

    public function test_summary_is_exactly_tier_scoped_and_uses_db_time_for_armed_ambiguity_and_owner_bounds(): void
    {
        $store = new PostgresMetadataStore($this->pool);
        $before = $store->getAwsS3OperationalSummary('AWS');
        $ownerA = 'owner-a-' . $this->suffix;
        $ownerB = 'owner-b-' . $this->suffix;
        $this->object('AWS', $ownerA, 'active', str_repeat('1', 32), true, 901.0);
        $this->object('AWS', $ownerA, 'reconciling', str_repeat('2', 32), true, 100.0);
        $this->object('AWS', $ownerB, 'active', null, true, 50.0);
        $this->object('AWS', 'owner-c-' . $this->suffix, 'sealed', str_repeat('3', 32), true, 5_000.0);
        $this->object('LOCAL', 'owner-local-' . $this->suffix, 'active', str_repeat('4', 32), true, 2_000.0);
        $this->multipart('AWS', 200.0);
        $this->multipart('AWS', 250.0, 'abandoned');
        $this->multipart('LOCAL', 3_000.0);
        $this->multipart('AWS_MULTIPART_LIVE', 1_800.0, leaseOffsetSeconds: 300.0);
        $this->garbage('AWS', 'part_write_intent', $ownerA, 1, 300.0);
        $this->garbage('AWS', 'delete_path', null, 1, 400.0);
        $this->garbage('LOCAL', 'part_write_intent', 'owner-local-' . $this->suffix, 1, 4_000.0);
        $this->object('AWS_LIVE', 'owner-live-armed-' . $this->suffix, 'active', str_repeat('5', 32), true, 1_800.0, 300.0);
        $this->object('AWS_LIVE', 'owner-live-unarmed-' . $this->suffix, 'active', null, true, 2_000.0, 300.0);

        $summary = $store->getAwsS3OperationalSummary('AWS');

        self::assertSame(3, $summary['open']);
        self::assertSame(2, $summary['ambiguous']);
        self::assertSame(4, $summary['seal_reserved']);
        self::assertSame(1, $summary['sealed']);
        self::assertSame(2, $summary['multipart']);
        self::assertSame(1, $summary['part']);
        self::assertSame(1, $summary['cleanup']);
        self::assertSame(2, $summary['failures']);
        self::assertGreaterThanOrEqual(900.0, $summary['oldest_age_seconds']);
        self::assertLessThan(1_000.0, $summary['oldest_age_seconds']);
        self::assertSame(2, $summary['max_owner_open']);
        self::assertSame(2, $summary['max_owner_sealed']);
        self::assertSame(3, $summary['max_owner_multipart']);
        self::assertSame(1, $summary['max_owner_part']);
        self::assertSame($before['global_open'] + 6, $summary['global_open']);
        self::assertSame($before['global_seal_reserved'] + 7, $summary['global_seal_reserved']);
        self::assertSame($before['global_multipart'] + 3, $summary['global_multipart']);
        self::assertSame($before['global_part'] + 2, $summary['global_part']);
        self::assertSame($before['global_cleanup'] + 3, $summary['global_cleanup']);

        $this->multipart('AWS_RETAINED', 1_800.0, 'abandoned');
        $retained = $store->getAwsS3OperationalSummary('AWS_RETAINED');
        self::assertSame(1, $retained['multipart']);
        self::assertSame(0, $retained['failures']);
        self::assertSame(0.0, $retained['oldest_age_seconds']);

        $live = $store->getAwsS3OperationalSummary('AWS_LIVE');
        self::assertSame(2, $live['open']);
        self::assertSame(0, $live['ambiguous']);
        self::assertSame(0.0, $live['oldest_age_seconds']);
        self::assertSame(0.0, $store->getAwsS3OperationalSummary('AWS_MULTIPART_LIVE')['oldest_age_seconds']);
    }

    public function test_postgres_runtime_factory_wires_one_exact_shared_state_and_owned_probe_worker(): void
    {
        $mock = new MockHandler([
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ]);
        $validator = new AwsS3ProviderValidator(
            static fn(RequestInterface $request, array $options) => $mock($request, $options),
        );
        $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
            'bucket' => 'qualified-runtime-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'data',
            'reserved_prefix' => '.opsfour',
        ], $validator);
        $backend = new AwsS3StorageBackend($binding->admission, $binding);
        $factory = new S3ServerRuntimeFactory();
        $config = new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            metadataDriver: 'postgres',
            selectWorkerPoolSize: 1,
            requestBodySpoolWorkerPoolSize: 1,
        );
        try {
            $factory->create(
                config: $config,
                metadata: new PostgresMetadataStore($this->pool),
                storage: $backend,
                credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
                logger: new NullLogger(),
                metrics: new MetricsCollector(),
                encryption: new EncryptionService(),
                notificationListeners: [['pattern' => '', 'listener' => static fn() => null]],
            );
            self::fail('A post-attachment factory error was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('pattern', $error->getMessage());
        }
        self::assertNull($backend->operationalState());
        self::assertFalse($backend->awsS3ProviderAdmission()->fenceComplete());

        $runtime = $factory->create(
            config: $config,
            metadata: new PostgresMetadataStore($this->pool),
            storage: $backend,
            credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
            encryption: new EncryptionService(),
        );

        $writer = null;
        $probePool = null;
        $stagePool = null;
        $budgetPool = null;
        try {
            $state = $backend->operationalState();
            self::assertInstanceOf(AwsS3OperationalState::class, $state);
            $runtimeMetadata = (new \ReflectionProperty(S3Server::class, 'metadata'))->getValue($runtime->server);
            self::assertInstanceOf(MetadataStore::class, $runtimeMetadata);
            self::assertInstanceOf(AwsS3OperationalSummaryReader::class, $runtimeMetadata);
            self::assertTrue($state->isBoundTo($binding, 'STANDARD', $runtimeMetadata));
            $graphCheck = new \ReflectionMethod(S3Server::class, 'assertAwsOperationalGraph');
            $registry = StorageTierRegistry::single($backend);
            $absentStateServer = new S3Server($config, $runtimeMetadata, $backend);
            try {
                $graphCheck->invoke($absentStateServer, [$registry]);
                self::fail('Direct AWS wiring without an exposed state was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('shared operational state', $error->getMessage());
            }

            $otherState = new AwsS3OperationalState(
                $binding,
                $validator,
                StorageMutatorInventory::forEnabledRuntime([
                    ...HandlerRegistrar::storageMutatorRegistrations(),
                    ...S3Server::storageMutatorRegistrations(),
                    ...AwsS3ObjectWriteIntentReconciler::storageMutatorRegistrations(),
                ]),
                $runtimeMetadata,
                'STANDARD',
            );
            $differentStateServer = new S3Server($config, $runtimeMetadata, $backend);
            $differentStateServer->setAwsOperationalState($otherState);
            try {
                $graphCheck->invoke($differentStateServer, [$registry]);
                self::fail('Direct AWS wiring with a different exposed state was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('share the configured operational state', $error->getMessage());
            }

            $otherBackend = new AwsS3StorageBackend($binding->admission, $binding);
            $otherBackend->attachOperationalState($otherState, 'STANDARD', $runtimeMetadata);
            $multipleStateServer = new S3Server($config, $runtimeMetadata, $backend);
            $multipleStateServer->setAwsOperationalState($state);
            try {
                $graphCheck->invoke($multipleStateServer, [
                    $registry,
                    StorageTierRegistry::single($otherBackend),
                ]);
                self::fail('Direct AWS wiring with multiple operational states was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('share the configured operational state', $error->getMessage());
            } finally {
                $otherBackend->rollbackOperationalStateAttachment($otherState, 'STANDARD', $runtimeMetadata);
            }

            $differentMetadataServer = new S3Server(
                $config,
                new PostgresMetadataStore($this->pool),
                $backend,
            );
            $differentMetadataServer->setAwsOperationalState($state);
            try {
                $graphCheck->invoke($differentMetadataServer, [$registry]);
                self::fail('Direct AWS wiring with a different metadata graph was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('metadata graph', $error->getMessage());
            }
            foreach (['awsOperationalState', 'awsObjectIntentCollector', 'awsBackgroundWriter', 'awsAccessLogWriter'] as $propertyName) {
                $property = new \ReflectionProperty(S3Server::class, $propertyName);
                self::assertNotNull($property->getValue($runtime->server), $propertyName . ' was not wired.');
            }
            $pools = (new \ReflectionProperty(S3Server::class, 'workerPools'))->getValue($runtime->server);
            self::assertIsArray($pools);
            self::assertContains('aws_provider_probe', array_column($pools, 'name'));
            $probeEntry = array_values(array_filter($pools, static fn(array $entry): bool => $entry['name'] === 'aws_provider_probe'))[0] ?? null;
            self::assertIsArray($probeEntry);
            self::assertInstanceOf(WorkerPool::class, $probeEntry['pool'] ?? null);
            self::assertTrue($probeEntry['forceKillOnShutdown'] ?? false);
            $probePool = $probeEntry['pool'];

            $writer = (new \ReflectionProperty($runtime, 'awsObjectWriter'))->getValue($runtime);
            self::assertInstanceOf(AwsS3ForegroundObjectWriter::class, $writer);
            $registry = $runtime->server->getHandlerRegistry();
            $put = $registry->get(S3Operation::PutObject);
            self::assertSame($writer, (new \ReflectionProperty($put, 'awsObjectWriter'))->getValue($put));

            // A directly embedded primary AWS server may register handlers
            // manually without a parallel registerStorage() call. Primary
            // default identity must still require exact foreground coverage.
            $storageRegistrations = new \ReflectionProperty($registry, 'storageRegistrations');
            $savedStorageRegistrations = $storageRegistrations->getValue($registry);
            self::assertIsArray($savedStorageRegistrations);
            $storageRegistrations->setValue($registry, []);
            $graphCheck->invoke($runtime->server, [StorageTierRegistry::single($backend)]);
            $registry->register(
                S3Operation::PutObject,
                $this->createMock(\Amp\Http\Server\RequestHandler::class),
            );
            try {
                $graphCheck->invoke($runtime->server, [StorageTierRegistry::single($backend)]);
                self::fail('A manually registered primary AWS handler without the exact writer was accepted.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('PutObject', $error->getMessage());
            } finally {
                $registry->register(S3Operation::PutObject, $put);
                $storageRegistrations->setValue($registry, $savedStorageRegistrations);
            }

            $post = $registry->get(S3Operation::PostObject);
            self::assertSame($put, (new \ReflectionProperty($post, 'putObject'))->getValue($post));
            foreach ([
                S3Operation::CopyObject,
                S3Operation::CreateMultipartUpload,
                S3Operation::UploadPart,
                S3Operation::UploadPartCopy,
                S3Operation::CompleteMultipartUpload,
            ] as $operation) {
                $handler = $registry->get($operation);
                self::assertSame(
                    $writer,
                    (new \ReflectionProperty($handler, 'awsObjectWriter'))->getValue($handler),
                    $operation->value . ' lost the exact foreground writer.',
                );
            }

            $accessLogs = (new \ReflectionProperty(S3Server::class, 'accessLogs'))->getValue($runtime->server);
            self::assertInstanceOf(AccessLogWriter::class, $accessLogs);
            self::assertSame($writer, (new \ReflectionProperty($accessLogs, 'awsWriter'))->getValue($accessLogs));
            self::assertSame($writer, (new \ReflectionProperty(S3Server::class, 'awsAccessLogWriter'))->getValue($runtime->server));

            $background = (new \ReflectionProperty(S3Server::class, 'awsBackgroundWriter'))->getValue($runtime->server);
            self::assertInstanceOf(AwsS3FencedBackgroundObjectWriter::class, $background);
            self::assertSame($writer, (new \ReflectionProperty($background, 'writer'))->getValue($background));
            $components = (new \ReflectionMethod(S3Server::class, 'prepareBackgroundComponents'))->invoke($runtime->server);
            self::assertIsArray($components);
            $transition = $components['transitionExecutor'] ?? null;
            $restore = $components['restoreExecutor'] ?? null;
            self::assertInstanceOf(TierTransitionExecutor::class, $transition);
            self::assertInstanceOf(RestoreExecutor::class, $restore);
            self::assertSame($background, (new \ReflectionProperty($transition, 'awsFencedWriter'))->getValue($transition));
            self::assertSame($background, (new \ReflectionProperty($restore, 'awsFencedWriter'))->getValue($restore));

            // The binding already passed the two mocked admission requests.
            // Avoid another external worker probe while exercising the guard's
            // exact runtime graph acceptance.
            (new \ReflectionProperty($state, 'startupValidated'))->setValue($state, true);
            self::assertSame([], StorageWriteFenceStartupGuard::assertReady(
                StorageTierRegistry::single($backend),
                false,
                'postgres',
                $runtimeMetadata,
            ));
            self::assertFalse($backend->operationalAttachmentCommitted());

            $stager = (new \ReflectionProperty($writer, 'stager'))->getValue($writer);
            self::assertInstanceOf(LocalScratchStager::class, $stager);
            $stagePool = (new \ReflectionProperty($stager, 'stagePool'))->getValue($stager);
            $budgetPool = (new \ReflectionProperty($stager, 'budgetPool'))->getValue($stager);
            self::assertInstanceOf(WorkerPool::class, $stagePool);
            self::assertInstanceOf(WorkerPool::class, $budgetPool);
        } finally {
            $runtime->stop();
            if ($writer instanceof AwsS3ForegroundObjectWriter) {
                $stager = (new \ReflectionProperty($writer, 'stager'))->getValue($writer);
                self::assertInstanceOf(LocalScratchStager::class, $stager);
                self::assertTrue((new \ReflectionProperty($stager, 'shuttingDown'))->getValue($stager));
                self::assertNull((new \ReflectionProperty($runtime, 'awsObjectWriter'))->getValue($runtime));
            }
            self::assertFalse($probePool?->isRunning() ?? true);
            self::assertFalse($stagePool?->isRunning() ?? true);
            self::assertFalse($budgetPool?->isRunning() ?? true);
        }
    }

    private function object(
        string $tier,
        string $owner,
        string $state,
        ?string $dispatchId,
        bool $sealReserved,
        float $ageSeconds,
        float $leaseOffsetSeconds = 0.0,
    ): void {
        $attempt = bin2hex(random_bytes(16));
        $path = 'qualified-aws-' . $this->suffix . '/object/' . $attempt;
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                 path_digest, purpose, state, lease_until, dispatch_id, seal_reserved, created_at)
            VALUES ($1, 'bucket', 'generation', $2, $3, $4, $5, 'put_object', 'active',
                    EXTRACT(EPOCH FROM clock_timestamp()) + $8::double precision, $6, $7,
                    clock_timestamp() - ($9::double precision * interval '1 second'))
            SQL, [$attempt, $owner, $tier, $path, hash('sha256', $path), $dispatchId, $sealReserved, $leaseOffsetSeconds, $ageSeconds]);
        if (in_array($state, ['reconciling', 'sealed'], true)) {
            $this->pool->execute("UPDATE s3_object_write_intents SET state = 'reconciling' WHERE attempt_id = $1", [$attempt]);
        }
        if ($state === 'sealed') {
            $this->pool->execute("UPDATE s3_object_write_intents SET state = 'sealed' WHERE attempt_id = $1", [$attempt]);
        }
    }

    private function multipart(
        string $tier,
        float $ageSeconds,
        string $state = 'active',
        float $leaseOffsetSeconds = 0.0,
    ): void {
        $attempt = bin2hex(random_bytes(16));
        $base = 'qualified-aws-' . $this->suffix . '/multipart/' . $attempt;
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id, created_at)
            VALUES ($1, $2, 'bucket', 'generation', $3, $4, $5, $6, $7, $8,
                    EXTRACT(EPOCH FROM clock_timestamp()) + $10::double precision, 0, NULL,
                    clock_timestamp() - ($9::double precision * interval '1 second'))
            SQL, [
            $attempt,
            bin2hex(random_bytes(16)),
            'owner-' . $this->suffix,
            $tier,
            $base . '/assembly',
            $base . '/final',
            hash('sha256', $base),
            $state,
            $ageSeconds,
            $leaseOffsetSeconds,
        ]);
    }

    private function garbage(string $tier, string $operation, ?string $owner, int $attempts, float $ageSeconds): void
    {
        $path = 'qualified-aws-' . $this->suffix . '/garbage/' . bin2hex(random_bytes(8));
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, owner_id, attempts, next_attempt_at, created_at)
            VALUES ('bucket', $1, $2, $3, $4, $5, 0,
                    clock_timestamp() - ($6::double precision * interval '1 second'))
            SQL, [$tier, $path, $operation, $owner, $attempts, $ageSeconds]);
    }
}
