<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Logging;

use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseSql;
use PHPUnit\Framework\TestCase;

final class AccessLogLocalFenceTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private FilesystemBackend $storage;

    private LocalFencedObjectWriter $writer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-fenced-access-log-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base . '/objects', 0700, true));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('alice', 'source', 'us-east-1');
        $this->metadata->createBucket('alice', 'log-target', 'us-east-1');
        $this->metadata->putBucketLogging('source', 'log-target', 'access/');
        $this->storage = new FilesystemBackend($this->base . '/objects');
        $this->storage->createBucket('source');
        $this->storage->createBucket('log-target');
        $this->writer = new LocalFencedObjectWriter($this->base . '/objects', $this->base . '/scratch', 'STANDARD');
    }

    protected function tearDown(): void
    {
        $this->writer->shutdown();
        $this->storage->shutdown();
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

    public function test_plain_access_log_consumes_one_shot_intent_on_metadata_publication(): void
    {
        $logger = $this->accessLogs();
        $logger->log('source', 'private-key', 'GetObject', 200, 42);
        $logger->flush();

        self::assertSame(0, $logger->getBufferCount());
        $objects = $this->metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $path = $objects[0]->systemMetadata['storagePath'];
        self::assertStringContainsString('private-key', (string) file_get_contents($path));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($path, -32)));
    }

    public function test_sse_s3_access_log_publishes_only_ciphertext(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $this->metadata->putBucketEncryption('log-target', 'AES256');
        $logger = $this->accessLogs($encryption);
        $logger->log('source', 'private-key', 'GetObject', 200, 42);
        $logger->flush();

        $objects = $this->metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $object = $this->metadata->getObjectMetadata('log-target', $objects[0]->key);
        self::assertNotNull($object);
        $ciphertext = file_get_contents($object->systemMetadata['storagePath']);
        self::assertIsString($ciphertext);
        self::assertStringNotContainsString('private-key', $ciphertext);
        $plain = $encryption->decryptSseS3($ciphertext, $object->userMetadata['__sse-key'], $object->userMetadata['__sse-iv'], $object->userMetadata['__sse-tag']);
        self::assertStringContainsString('private-key', $plain);
    }

    public function test_lost_dispatch_token_retains_path_and_requeues_log_without_publication(): void
    {
        $logger = $this->accessLogs(factory: $this->factory(function (ObjectWriteIntent $armed): void {
            self::assertTrue($this->metadata->abandonObjectWriteIntent($armed->attemptId));
        }));
        $logger->log('source', 'private-key', 'GetObject', 200, 42);
        $logger->flush();

        self::assertSame(1, $logger->getBufferCount());
        self::assertSame([], $this->metadata->listObjects('log-target')->objects);
        $paths = glob($this->base . '/objects/log-target/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertStringContainsString('private-key', (string) file_get_contents($paths[0]));
        self::assertNotNull($this->metadata->getObjectWriteIntent(substr($paths[0], -32)));
    }

    private function accessLogs(?EncryptionService $encryption = null, ?LocalObjectWriteLeaseGuardFactory $factory = null): AccessLogWriter
    {
        return new AccessLogWriter(
            $this->metadata,
            $this->storage,
            encryption: $encryption,
            localFencedWriter: $this->writer,
            localLeaseFactory: $factory ?? $this->factory(),
        );
    }

    /** @param null|\Closure(ObjectWriteIntent):void $afterArm */
    private function factory(?\Closure $afterArm = null): LocalObjectWriteLeaseGuardFactory
    {
        return new class ($this->metadata, $afterArm) implements LocalObjectWriteLeaseGuardFactory {
            private LocalObjectWriteLeaseAdmission $admission;

            public function __construct(private SqliteMetadataStore $metadata, private ?\Closure $afterArm)
            {
                $this->admission = new LocalObjectWriteLeaseAdmission(2);
            }

            public function reserve(ObjectWriteIntent $planned): LocalObjectWriteLeaseGuard
            {
                $sql = new class ($this->metadata, $this->afterArm) implements LocalObjectWriteLeaseSql {
                    public function __construct(private SqliteMetadataStore $metadata, private ?\Closure $afterArm) {}

                    public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent
                    {
                        return $this->metadata->reserveObjectWriteIntent($planned, $leaseSeconds);
                    }

                    public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
                    {
                        $armed = $this->metadata->armObjectWriteIntent($current->attemptId, $current->leaseUntil, $dispatchId, $leaseSeconds);
                        if ($armed !== null) {
                            ($this->afterArm)?->__invoke($armed);
                        }

                        return $armed;
                    }
                };
                $renewer = new class ($this->metadata) implements LocalObjectWriteLeaseRenewer {
                    public function __construct(private SqliteMetadataStore $metadata) {}

                    public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
                    {
                        return $this->metadata->renewObjectWriteIntent($current->attemptId, $current->leaseUntil, $leaseSeconds);
                    }
                };
                $guard = (new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations'))
                    ->invoke(null, $planned, $sql, $renewer, $this->admission);
                if (! $guard instanceof LocalObjectWriteLeaseGuard) {
                    throw new \LogicException('Lease guard test seam returned another type.');
                }

                return $guard;
            }
        };
    }
}
