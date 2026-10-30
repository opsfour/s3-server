<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Http\Server\RequestHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\S3ServerRuntime;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalFilesystemPublicationConsumer;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalScratchStager;
use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class LocalObjectWriteRuntimeTest extends TestCase
{
    private const string MYSQL_DSN = 'host=127.0.0.1 user=test db=test';

    private string $base;

    private string $objects;

    private string $scratch;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-local-runtime-' . bin2hex(random_bytes(8));
        $this->objects = $this->base . '/objects';
        $this->scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($this->objects, 0700, true));
        self::assertTrue(mkdir($this->scratch, 0700));
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->base);
    }

    public function test_profile_needs_explicit_unsafe_test_override(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new S3ServerConfig(storagePath: $this->objects, localObjectWriteTestScratchPath: $this->scratch);
    }

    public function test_runtime_factory_rejects_a_different_metadata_dsn_before_creating_fence(): void
    {
        $store = MysqlMetadataStore::fromDsn('host=127.0.0.1 user=test db=other');

        try {
            $this->createRuntime($this->runtimeConfig(), $store);
            self::fail('A metadata store bound to another database was admitted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('created from the configured DSN', $error->getMessage());
            self::assertStringNotContainsString('db=other', $error->getMessage());
        }
    }

    public function test_physical_pause_hook_requires_both_unsafe_profile_and_exact_test_flag(): void
    {
        $previous = getenv('S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK');
        try {
            putenv('S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK=true');
            $runtime = $this->createRuntime($this->runtimeConfig(), MysqlMetadataStore::fromDsn(self::MYSQL_DSN));
            try {
                $writer = (new \ReflectionProperty($runtime->server, 'localObjectWriter'))->getValue($runtime->server);
                self::assertInstanceOf(LocalFencedObjectWriter::class, $writer);
                self::assertNull((new \ReflectionProperty(LocalFencedObjectWriter::class, 'workerIo'))->getValue($writer));
            } finally {
                $runtime->stop();
            }

            putenv('S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK=1');
            $normal = $this->createRuntime(
                new S3ServerConfig(storagePath: $this->objects, metadataDriver: 'mysql', metadataDsn: self::MYSQL_DSN),
                MysqlMetadataStore::fromDsn(self::MYSQL_DSN),
            );
            try {
                self::assertInstanceOf(
                    LocalFencedObjectWriter::class,
                    (new \ReflectionProperty($normal->server, 'localObjectWriter'))->getValue($normal->server),
                );
                $normalWriter = (new \ReflectionProperty($normal->server, 'localObjectWriter'))->getValue($normal->server);
                self::assertNull((new \ReflectionProperty(LocalFencedObjectWriter::class, 'workerIo'))->getValue($normalWriter));
            } finally {
                $normal->stop();
            }

            $runtime = $this->createRuntime($this->runtimeConfig(), MysqlMetadataStore::fromDsn(self::MYSQL_DSN));
            try {
                $writer = (new \ReflectionProperty($runtime->server, 'localObjectWriter'))->getValue($runtime->server);
                self::assertInstanceOf(LocalFencedObjectWriter::class, $writer);
                self::assertInstanceOf(
                    LocalObjectTestPublishPauseIo::class,
                    (new \ReflectionProperty(LocalFencedObjectWriter::class, 'workerIo'))->getValue($writer),
                );
            } finally {
                $runtime->stop();
            }
        } finally {
            putenv($previous === false
                ? 'S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK'
                : 'S3_LOCAL_OBJECT_TEST_PAUSE_BEFORE_LINK=' . $previous);
        }
    }

    public function test_raw_mysql_store_and_private_scratch_wire_same_fence_into_put_and_copy(): void
    {
        $metadata = MysqlMetadataStore::fromDsn(self::MYSQL_DSN);
        $runtime = $this->createRuntime($this->runtimeConfig(), $metadata);
        try {
            $put = $runtime->server->getHandlerRegistry()->get(S3Operation::PutObject);
            $copy = $runtime->server->getHandlerRegistry()->get(S3Operation::CopyObject);
            self::assertInstanceOf(PutObjectHandler::class, $put);
            self::assertInstanceOf(CopyObjectHandler::class, $copy);
            $writer = (new \ReflectionProperty(PutObjectHandler::class, 'localFencedWriter'))->getValue($put);
            $factory = (new \ReflectionProperty(PutObjectHandler::class, 'localLeaseFactory'))->getValue($put);
            self::assertInstanceOf(LocalFencedObjectWriter::class, $writer);
            self::assertInstanceOf(LocalObjectWriteLeaseGuardFactory::class, $factory);
            self::assertSame($writer, (new \ReflectionProperty(CopyObjectHandler::class, 'localFencedWriter'))->getValue($copy));
            self::assertSame($factory, (new \ReflectionProperty(CopyObjectHandler::class, 'localLeaseFactory'))->getValue($copy));
            foreach ([
                [S3Operation::UploadPart, UploadPartHandler::class, 'requireLocalFencedParts', false],
                [S3Operation::UploadPartCopy, UploadPartCopyHandler::class, 'requireLocalFencedParts', false],
                [S3Operation::CompleteMultipartUpload, CompleteMultipartUploadHandler::class, 'requireLocalFencedCompletion', true],
            ] as [$operation, $class, $flag, $required]) {
                $handler = $runtime->server->getHandlerRegistry()->get($operation);
                self::assertInstanceOf($class, $handler);
                self::assertSame($required, (new \ReflectionProperty($class, $flag))->getValue($handler));
                if ($operation !== S3Operation::CompleteMultipartUpload) {
                    self::assertInstanceOf(
                        LocalFencedMultipartPartWriter::class,
                        (new \ReflectionProperty($class, 'localPartWriter'))->getValue($handler),
                    );
                }
            }
            $backgroundWriter = (new \ReflectionProperty($runtime->server, 'localBackgroundWriter'))->getValue($runtime->server);
            self::assertInstanceOf(LocalFencedBackgroundObjectWriter::class, $backgroundWriter);
            self::assertSame($writer, (new \ReflectionProperty(LocalFencedBackgroundObjectWriter::class, 'writer'))->getValue($backgroundWriter));
            self::assertSame($factory, (new \ReflectionProperty(LocalFencedBackgroundObjectWriter::class, 'leases'))->getValue($backgroundWriter));
            self::assertSame(realpath($this->objects), $writer->storageRoot());
            self::assertSame('STANDARD', $writer->storageTierName());
            self::assertInstanceOf(
                LocalObjectWriteIntentCollector::class,
                (new \ReflectionProperty($runtime->server, 'localObjectIntentCollector'))->getValue($runtime->server),
            );
            $renewer = (new \ReflectionProperty(S3ServerRuntime::class, 'localObjectRenewer'))->getValue($runtime);
            self::assertInstanceOf(\OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer::class, $renewer);
            $renewalPool = (new \ReflectionProperty($renewer, 'pool'))->getValue($renewer);
            $metadataPool = (new \ReflectionProperty($metadata, 'pool'))->getValue($metadata);
            self::assertNotSame($metadataPool, $renewalPool);
            self::assertFalse($renewalPool->isClosed());
        } finally {
            $runtime->stop();
        }
        self::assertTrue((new \ReflectionProperty(LocalFencedObjectWriter::class, 'shuttingDown'))->getValue($writer));
        self::assertTrue($renewalPool->isClosed());
        self::assertFalse($metadataPool->isClosed());
        $runtime->stop();
    }

    public function test_normal_sqlite_runtime_wires_local_object_fence_without_test_profile(): void
    {
        $database = $this->base . '/metadata.sqlite';
        $sqlite = new SqliteMetadataStore($database);
        $sqlite->initialize();
        $runtime = $this->createRuntime(new S3ServerConfig(
            storagePath: $this->objects,
            metadataDriver: 'sqlite',
            metadataDsn: $database,
        ), $sqlite);

        try {
            $writer = (new \ReflectionProperty($runtime->server, 'localObjectWriter'))->getValue($runtime->server);
            self::assertInstanceOf(LocalFencedObjectWriter::class, $writer);
            self::assertNull((new \ReflectionProperty(LocalFencedObjectWriter::class, 'workerIo'))->getValue($writer));
            $stager = (new \ReflectionProperty(LocalFencedObjectWriter::class, 'stager'))->getValue($writer);
            self::assertInstanceOf(LocalScratchStager::class, $stager);
            self::assertSame(
                realpath($this->objects . '/.tmp/object-writes'),
                (new \ReflectionProperty($stager, 'scratch'))->getValue($stager),
            );
            self::assertNull((new \ReflectionProperty(S3ServerRuntime::class, 'localObjectRenewer'))->getValue($runtime));
            $partWriter = (new \ReflectionProperty(S3ServerRuntime::class, 'localMultipartPartWriter'))->getValue($runtime);
            self::assertInstanceOf(LocalFencedMultipartPartWriter::class, $partWriter);
            self::assertSame(
                $partWriter,
                (new \ReflectionProperty($runtime->server, 'localMultipartPartWriter'))->getValue($runtime->server),
            );

            $put = $runtime->server->getHandlerRegistry()->get(S3Operation::PutObject);
            self::assertInstanceOf(PutObjectHandler::class, $put);
            self::assertSame(
                $writer,
                (new \ReflectionProperty(PutObjectHandler::class, 'localFencedWriter'))->getValue($put),
            );
        } finally {
            $runtime->stop();
        }
        self::assertTrue((new \ReflectionProperty(LocalFencedMultipartPartWriter::class, 'shuttingDown'))->getValue($partWriter));
    }

    public function test_factory_runtime_rejects_each_mismatched_local_publication_handler_before_binding(): void
    {
        foreach ([
            S3Operation::PutObject,
            S3Operation::PostObject,
            S3Operation::CopyObject,
            S3Operation::UploadPart,
            S3Operation::UploadPartCopy,
            S3Operation::CompleteMultipartUpload,
        ] as $operation) {
            $database = $this->base . '/' . $operation->value . '.sqlite';
            $metadata = new SqliteMetadataStore($database);
            $metadata->initialize();
            $runtime = $this->createRuntime(new S3ServerConfig(
                storagePath: $this->objects,
                metadataDriver: 'sqlite',
                metadataDsn: $database,
                selectWorkerPoolSize: 0,
                notificationWorkerEnabled: false,
            ), $metadata);
            $mismatched = $this->createMockForIntersectionOfInterfaces([
                RequestHandler::class,
                LocalFilesystemPublicationConsumer::class,
            ]);
            if (! $mismatched instanceof RequestHandler) {
                throw new \LogicException('Intersection mock is missing the request-handler interface.');
            }
            $runtime->server->getHandlerRegistry()->register($operation, $mismatched);

            try {
                $runtime->server->start();
                self::fail("Expected {$operation->value} to fail local publication attestation.");
            } catch (\LogicException $exception) {
                self::assertStringContainsString("handler {$operation->value}", $exception->getMessage());
                self::assertNull((new \ReflectionProperty($runtime->server, 'server'))->getValue($runtime->server));
            } finally {
                $runtime->stop();
            }
        }
    }

    public function test_concrete_local_publication_consumers_require_exact_resources_and_tier(): void
    {
        $database = $this->base . '/predicate-primary.sqlite';
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $runtime = $this->createRuntime(new S3ServerConfig(
            storagePath: $this->objects,
            metadataDriver: 'sqlite',
            metadataDsn: $database,
            selectWorkerPoolSize: 0,
            notificationWorkerEnabled: false,
        ), $metadata);

        $otherRoot = $this->base . '/predicate-other';
        self::assertTrue(mkdir($otherRoot, 0700));
        $otherDatabase = $this->base . '/predicate-other.sqlite';
        $otherMetadata = new SqliteMetadataStore($otherDatabase);
        $otherMetadata->initialize();
        $otherRuntime = $this->createRuntime(new S3ServerConfig(
            storagePath: $otherRoot,
            metadataDriver: 'sqlite',
            metadataDsn: $otherDatabase,
            selectWorkerPoolSize: 0,
            notificationWorkerEnabled: false,
        ), $otherMetadata, new FilesystemBackend($otherRoot));

        try {
            $writer = (new \ReflectionProperty($runtime->server, 'localObjectWriter'))->getValue($runtime->server);
            $leases = (new \ReflectionProperty($runtime->server, 'localObjectLeaseFactory'))->getValue($runtime->server);
            $parts = (new \ReflectionProperty($runtime->server, 'localMultipartPartWriter'))->getValue($runtime->server);
            $otherWriter = (new \ReflectionProperty($otherRuntime->server, 'localObjectWriter'))->getValue($otherRuntime->server);
            $otherLeases = (new \ReflectionProperty($otherRuntime->server, 'localObjectLeaseFactory'))->getValue($otherRuntime->server);
            $otherParts = (new \ReflectionProperty($otherRuntime->server, 'localMultipartPartWriter'))->getValue($otherRuntime->server);
            self::assertInstanceOf(LocalFencedObjectWriter::class, $writer);
            self::assertInstanceOf(LocalObjectWriteLeaseGuardFactory::class, $leases);
            self::assertInstanceOf(LocalFencedMultipartPartWriter::class, $parts);
            self::assertInstanceOf(LocalFencedObjectWriter::class, $otherWriter);
            self::assertInstanceOf(LocalObjectWriteLeaseGuardFactory::class, $otherLeases);
            self::assertInstanceOf(LocalFencedMultipartPartWriter::class, $otherParts);

            foreach ([
                S3Operation::PutObject,
                S3Operation::PostObject,
                S3Operation::CopyObject,
            ] as $operation) {
                $handler = $runtime->server->getHandlerRegistry()->get($operation);
                self::assertInstanceOf(LocalFilesystemPublicationConsumer::class, $handler);
                self::assertTrue($handler->usesLocalFilesystemPublicationGraph($writer, $leases, $parts, 'STANDARD'));
                self::assertFalse($handler->usesLocalFilesystemPublicationGraph($otherWriter, $leases, $parts, 'STANDARD'));
                self::assertFalse($handler->usesLocalFilesystemPublicationGraph($writer, $otherLeases, $parts, 'STANDARD'));
                self::assertFalse($handler->usesLocalFilesystemPublicationGraph($writer, $leases, $parts, 'OTHER'));
            }

            foreach ([S3Operation::UploadPart, S3Operation::UploadPartCopy] as $operation) {
                $handler = $runtime->server->getHandlerRegistry()->get($operation);
                self::assertInstanceOf(LocalFilesystemPublicationConsumer::class, $handler);
                self::assertTrue($handler->usesLocalFilesystemPublicationGraph($writer, $leases, $parts, 'STANDARD'));
                self::assertFalse($handler->usesLocalFilesystemPublicationGraph($writer, $leases, $otherParts, 'STANDARD'));
                self::assertFalse($handler->usesLocalFilesystemPublicationGraph($writer, $leases, $parts, 'OTHER'));
            }

            $completion = $runtime->server->getHandlerRegistry()->get(S3Operation::CompleteMultipartUpload);
            self::assertInstanceOf(LocalFilesystemPublicationConsumer::class, $completion);
            self::assertTrue($completion->usesLocalFilesystemPublicationGraph($writer, $leases, $parts, 'STANDARD'));
            self::assertTrue($completion->usesLocalFilesystemPublicationGraph($otherWriter, $otherLeases, $otherParts, 'STANDARD'));
            self::assertFalse($completion->usesLocalFilesystemPublicationGraph($writer, $leases, $parts, 'OTHER'));
        } finally {
            $otherRuntime->stop();
            $runtime->stop();
        }
    }

    public function test_publication_handler_without_exposed_storage_graph_is_rejected(): void
    {
        $server = new S3Server(new S3ServerConfig(
            storagePath: $this->objects,
            perClientRateLimit: 0,
        ));
        $server->getHandlerRegistry()->register(S3Operation::PutObject, $this->createMock(RequestHandler::class));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('Expected a publication handler without an exposed storage graph to fail.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('require an exposed storage graph', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
        }
    }

    public function test_differing_explicit_handler_filesystem_is_rejected(): void
    {
        $database = $this->base . '/explicit-storage.sqlite';
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $runtime = $this->createRuntime(new S3ServerConfig(
            storagePath: $this->objects,
            metadataDriver: 'sqlite',
            metadataDsn: $database,
            selectWorkerPoolSize: 0,
            notificationWorkerEnabled: false,
        ), $metadata);
        $otherRoot = $this->base . '/explicit-storage-other';
        self::assertTrue(mkdir($otherRoot, 0700));
        $tiers = (new \ReflectionProperty($runtime->server, 'storageTiers'))->getValue($runtime->server);
        self::assertInstanceOf(StorageTierRegistry::class, $tiers);
        $runtime->server->getHandlerRegistry()->registerStorage(new FilesystemBackend($otherRoot), $tiers);

        try {
            $runtime->server->start();
            self::fail('Expected the differing explicit handler filesystem to fail attestation.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('handler PutObject', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($runtime->server, 'server'))->getValue($runtime->server));
        } finally {
            $runtime->stop();
        }
    }

    public function test_nondefault_filesystem_tier_is_rejected_without_its_exact_publication_graph(): void
    {
        $archiveRoot = $this->base . '/archive';
        self::assertTrue(mkdir($archiveRoot, 0700));
        $database = $this->base . '/nondefault-tier.sqlite';
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $storage = new FilesystemBackend($this->objects);
        $runtime = (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(
                storagePath: $this->objects,
                metadataDriver: 'sqlite',
                metadataDsn: $database,
                selectWorkerPoolSize: 0,
                notificationWorkerEnabled: false,
            ),
            metadata: $metadata,
            storage: $storage,
            credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
            storageTiers: new StorageTierRegistry([
                new StorageTier('STANDARD', $storage, defaultWriteTier: true),
                new StorageTier('ARCHIVE', new FilesystemBackend($archiveRoot)),
            ]),
        );

        try {
            $runtime->server->start();
            self::fail('Expected the non-default filesystem tier to fail publication attestation.');
        } catch (\LogicException $exception) {
            self::assertStringContainsString('handler UploadPart', $exception->getMessage());
            self::assertNull((new \ReflectionProperty($runtime->server, 'server'))->getValue($runtime->server));
        } finally {
            $runtime->stop();
        }
    }

    public function test_absent_publication_operation_is_rejected_and_retryable(): void
    {
        $database = $this->base . '/absent-operation.sqlite';
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $runtime = $this->createRuntime(new S3ServerConfig(
            host: '127.0.0.1',
            port: $this->freePort(),
            storagePath: $this->objects,
            metadataDriver: 'sqlite',
            metadataDsn: $database,
            selectWorkerPoolSize: 0,
            notificationWorkerEnabled: false,
        ), $metadata);
        $registry = $runtime->server->getHandlerRegistry();
        $operation = S3Operation::UploadPartCopy;
        $handler = $registry->get($operation);
        $handlersProperty = new \ReflectionProperty($registry, 'handlers');
        $handlers = $handlersProperty->getValue($registry);
        unset($handlers[$operation->value]);
        $handlersProperty->setValue($registry, $handlers);

        try {
            try {
                $runtime->server->start();
                self::fail('Expected the absent publication operation to fail attestation.');
            } catch (\LogicException $exception) {
                self::assertStringContainsString('handler UploadPartCopy', $exception->getMessage());
                self::assertNull((new \ReflectionProperty($runtime->server, 'server'))->getValue($runtime->server));
            }

            $registry->register($operation, $handler);
            $runtime->server->start();
            self::assertNotNull((new \ReflectionProperty($runtime->server, 'server'))->getValue($runtime->server));
        } finally {
            $runtime->stop();
        }
    }

    public function test_factory_runtime_starts_with_the_exact_local_publication_graph(): void
    {
        $database = $this->base . '/positive.sqlite';
        $metadata = new SqliteMetadataStore($database);
        $metadata->initialize();
        $runtime = $this->createRuntime(new S3ServerConfig(
            host: '127.0.0.1',
            port: $this->freePort(),
            storagePath: $this->objects,
            metadataDriver: 'sqlite',
            metadataDsn: $database,
            selectWorkerPoolSize: 0,
            notificationWorkerEnabled: false,
        ), $metadata);

        try {
            $runtime->server->start();
            self::assertNotNull((new \ReflectionProperty($runtime->server, 'server'))->getValue($runtime->server));
        } finally {
            $runtime->stop();
        }
    }

    public function test_known_metadata_decorator_is_unwrapped_for_sql_lease_resources(): void
    {
        $wrapped = new CachedMetadataStoreDecorator(
            new \OpsFour\S3Server\Observability\ObservedMetadataStore(
                MysqlMetadataStore::fromDsn(self::MYSQL_DSN),
                new MetricsCollector(),
                'mysql',
            ),
        );
        $runtime = $this->createRuntime($this->runtimeConfig(), $wrapped);
        try {
            self::assertInstanceOf(
                LocalFencedObjectWriter::class,
                (new \ReflectionProperty($runtime->server, 'localObjectWriter'))->getValue($runtime->server),
            );
        } finally {
            $runtime->stop();
        }
    }

    public function test_nonfilesystem_default_and_public_scratch_fail_closed(): void
    {
        try {
            $this->createRuntime(
                $this->runtimeConfig(),
                MysqlMetadataStore::fromDsn(self::MYSQL_DSN),
                new InMemoryBackend(),
            );
            self::fail('In-memory default storage must not receive a local filesystem writer.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('filesystem default tier', $error->getMessage());
        }

        self::assertTrue(chmod($this->scratch, 0755));
        try {
            $this->createRuntime($this->runtimeConfig(), MysqlMetadataStore::fromDsn(self::MYSQL_DSN));
            self::fail('Public scratch must be rejected before handler publication.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('scratch directory must be private', $error->getMessage());
        }
    }

    public function test_intent_collector_must_match_writer_metadata_tier_and_canonical_root(): void
    {
        $runtime = $this->createRuntime($this->runtimeConfig(), MysqlMetadataStore::fromDsn(self::MYSQL_DSN));
        try {
            $metadata = (new \ReflectionProperty($runtime->server, 'metadata'))->getValue($runtime->server);
            $writer = (new \ReflectionProperty($runtime->server, 'localObjectWriter'))->getValue($runtime->server);
            $collector = (new \ReflectionProperty($runtime->server, 'localObjectIntentCollector'))->getValue($runtime->server);
            self::assertInstanceOf(MetadataStore::class, $metadata);
            self::assertInstanceOf(ObjectWriteIntentStore::class, $metadata);
            self::assertInstanceOf(LocalFencedObjectWriter::class, $writer);
            self::assertInstanceOf(LocalObjectWriteIntentCollector::class, $collector);
            $collector->assertBoundTo($metadata, $writer);

            try {
                $collector->assertBoundTo(MysqlMetadataStore::fromDsn(self::MYSQL_DSN), $writer);
                self::fail('A collector bound to different metadata was admitted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('differs from the fenced writer', $error->getMessage());
            }

            $otherRoot = $this->base . '/other';
            self::assertTrue(mkdir($otherRoot, 0700));
            foreach ([
                ['OTHER', $this->objects],
                ['STANDARD', $otherRoot],
            ] as [$tierName, $root]) {
                $wrong = new LocalObjectWriteIntentCollector(
                    $metadata,
                    StorageTierRegistry::single(new FilesystemBackend($root), $tierName),
                    $tierName,
                );
                try {
                    try {
                        $wrong->assertBoundTo($metadata, $writer);
                        self::fail('A collector bound to a different tier or root was admitted.');
                    } catch (\RuntimeException $error) {
                        self::assertStringContainsString('differs from the fenced writer', $error->getMessage());
                    }
                } finally {
                    $wrong->shutdown();
                }
            }
        } finally {
            $runtime->stop();
        }
    }

    private function runtimeConfig(): S3ServerConfig
    {
        return new S3ServerConfig(
            storagePath: $this->objects,
            metadataDriver: 'mysql',
            metadataDsn: self::MYSQL_DSN,
            allowUnfencedStorageForTests: true,
            localObjectWriteTestScratchPath: $this->scratch,
            storageTempDir: $this->scratch,
        );
    }

    private function createRuntime(S3ServerConfig $config, MetadataStore $metadata, ?StorageBackend $storage = null): S3ServerRuntime
    {
        return (new S3ServerRuntimeFactory())->create(
            config: $config,
            metadata: $metadata,
            storage: $storage ?? new FilesystemBackend($this->objects),
            credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
        );
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
}
