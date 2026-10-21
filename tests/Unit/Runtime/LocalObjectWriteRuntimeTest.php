<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

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
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalScratchStager;
use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use OpsFour\S3Server\Storage\StorageBackend;
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
