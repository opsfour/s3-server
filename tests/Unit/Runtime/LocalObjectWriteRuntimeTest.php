<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
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
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
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
                $writer = (new \ReflectionProperty($runtime->server, 'localObjectTestWriter'))->getValue($runtime->server);
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
                self::assertNull((new \ReflectionProperty($normal->server, 'localObjectTestWriter'))->getValue($normal->server));
            } finally {
                $normal->stop();
            }

            $runtime = $this->createRuntime($this->runtimeConfig(), MysqlMetadataStore::fromDsn(self::MYSQL_DSN));
            try {
                $writer = (new \ReflectionProperty($runtime->server, 'localObjectTestWriter'))->getValue($runtime->server);
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
                [S3Operation::UploadPart, UploadPartHandler::class, 'requireLocalFencedParts'],
                [S3Operation::UploadPartCopy, UploadPartCopyHandler::class, 'requireLocalFencedParts'],
                [S3Operation::CompleteMultipartUpload, CompleteMultipartUploadHandler::class, 'requireLocalFencedCompletion'],
            ] as [$operation, $class, $flag]) {
                $handler = $runtime->server->getHandlerRegistry()->get($operation);
                self::assertInstanceOf($class, $handler);
                self::assertTrue((new \ReflectionProperty($class, $flag))->getValue($handler));
            }
            $backgroundWriter = (new \ReflectionProperty($runtime->server, 'localBackgroundTestWriter'))->getValue($runtime->server);
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

    public function test_sqlite_and_wrapped_metadata_are_rejected_before_resource_creation(): void
    {
        $sqlite = new SqliteMetadataStore(':memory:');
        $sqlite->initialize();
        try {
            $this->createRuntime(
                $this->runtimeConfig()->with(['metadataDriver' => 'sqlite', 'metadataDsn' => '']),
                $sqlite,
            );
            self::fail('SQLite must not enter the local write test profile.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('raw PostgreSQL/MySQL', $error->getMessage());
        }

        $wrapped = new \OpsFour\S3Server\Observability\ObservedMetadataStore(
            MysqlMetadataStore::fromDsn(self::MYSQL_DSN),
            new MetricsCollector(),
            'mysql',
        );
        try {
            $this->createRuntime($this->runtimeConfig(), $wrapped);
            self::fail('A wrapped metadata store must not enter the raw SQL lease profile.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('raw PostgreSQL/MySQL', $error->getMessage());
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
            $writer = (new \ReflectionProperty($runtime->server, 'localObjectTestWriter'))->getValue($runtime->server);
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

    /** @return iterable<string, array{bool}> */
    public static function unqualifiedDirectMetadata(): iterable
    {
        yield 'sqlite' => [false];
        yield 'cached sqlite' => [true];
    }

    #[DataProvider('unqualifiedDirectMetadata')]
    public function test_direct_server_refuses_local_fence_with_sqlite_before_http_bind(bool $wrapped): void
    {
        $raw = new SqliteMetadataStore(':memory:');
        $raw->initialize();
        $metadata = $wrapped ? new CachedMetadataStoreDecorator($raw) : $raw;
        $storage = new FilesystemBackend($this->objects);
        $writer = new LocalFencedObjectWriter($this->objects, $this->scratch, 'STANDARD');
        $collector = new LocalObjectWriteIntentCollector($metadata, StorageTierRegistry::single($storage), 'STANDARD');
        $server = new S3Server(new S3ServerConfig(
            storagePath: $this->objects,
            allowUnfencedStorageForTests: true,
            localObjectWriteTestScratchPath: $this->scratch,
        ), $metadata, $storage);
        $server->setLocalObjectWriteIntentCollector($collector);
        $server->setLocalObjectWriteTestWriter($writer, $this->createMock(LocalObjectWriteLeaseGuardFactory::class));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            self::fail('A direct SQLite server admitted the local write fence.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('raw PostgreSQL/MySQL metadata', $error->getMessage());
            self::assertNull((new \ReflectionProperty($server, 'server'))->getValue($server));
        } finally {
            $server->stop();
            $writer->shutdown();
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
}
