<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseSql;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class PutObjectLocalFenceIntegrationTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private FilesystemBackend $storage;

    private LocalFencedObjectWriter $writer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-fenced-put-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base . '/objects', 0700, true));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new FilesystemBackend($this->base . '/objects');
        $this->storage->createBucket('bucket');
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

    public function test_versioned_plain_put_consumes_exact_armed_intent(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $payload = 'fenced payload';
        $response = $this->handler()->handleRequest($this->request($payload, [
            'x-amz-checksum-sha256' => base64_encode(hash('sha256', $payload, true)),
        ]));

        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertNotNull($object->versionId);
        self::assertSame('STANDARD', $object->storageTier);
        self::assertSame('"' . md5($payload) . '"', $object->etag);
        self::assertSame(base64_encode(hash('sha256', $payload, true)), $object->systemMetadata['checksum-sha256']);
        self::assertSame(ObjectPartLayout::KNOWN_SINGLE, $object->partLayout?->kind);
        $layout = $this->metadata->getObjectPartLayout($object);
        self::assertSame(0, $layout->parts[0]->offset);
        self::assertSame(strlen($payload), $layout->parts[0]->size);
        $path = $object->systemMetadata['storagePath'];
        self::assertSame($payload, file_get_contents($path));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($path, -32)));
    }

    public function test_zero_byte_put_publishes_one_exact_logical_part(): void
    {
        self::assertSame(200, $this->handler()->handleRequest($this->request(''))->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        $layout = $this->metadata->getObjectPartLayout($object);
        self::assertSame(ObjectPartLayout::KNOWN_SINGLE, $layout->kind);
        self::assertCount(1, $layout->parts);
        self::assertSame(0, $layout->parts[0]->offset);
        self::assertSame(0, $layout->parts[0]->size);
    }

    public function test_preferred_transfer_reserves_intent_for_writer_but_persists_bucket_owner(): void
    {
        $this->metadata->putBucketOwnershipControls('bucket', 'BucketOwnerPreferred');
        $intentOwner = null;
        $factory = $this->factory(function (ObjectWriteIntent $armed) use (&$intentOwner): void {
            $intentOwner = $armed->ownerId;
        });

        $response = $this->handler(factory: $factory)->handleRequest($this->request(
            'payload',
            ['x-amz-acl' => 'bucket-owner-full-control'],
            'writer',
        ));

        self::assertSame(200, $response->getStatus());
        self::assertSame('writer', $intentOwner);
        self::assertSame('owner', $this->metadata->getObjectMetadata('bucket', 'key')?->ownerId);
    }

    public function test_sse_s3_and_sse_c_publish_ciphertext_with_plaintext_checksums(): void
    {
        $key = random_bytes(32);
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        foreach ([
            ['x-amz-server-side-encryption' => 'AES256'],
            [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode($key),
                'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($key, true)),
            ],
        ] as $index => $headers) {
            $payload = 'encrypted payload ' . $index;
            $headers['x-amz-checksum-sha256'] = base64_encode(hash('sha256', $payload, true));
            $response = $this->handler($encryption)->handleRequest($this->request($payload, $headers));
            self::assertSame(200, $response->getStatus());
            self::assertSame($headers['x-amz-checksum-sha256'], $response->getHeader('x-amz-checksum-sha256'));
            $object = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);
            self::assertSame(strlen($payload), $object->size);
            self::assertSame('"' . md5($payload) . '"', $object->etag);
            self::assertSame($headers['x-amz-checksum-sha256'], $object->systemMetadata['checksum-sha256']);
            $ciphertext = file_get_contents($object->systemMetadata['storagePath']);
            self::assertIsString($ciphertext);
            self::assertNotSame($payload, $ciphertext);
            $decrypted = $index === 0
                ? $encryption->decryptSseS3($ciphertext, $object->userMetadata['__sse-key'], $object->userMetadata['__sse-iv'], $object->userMetadata['__sse-tag'])
                : $encryption->decryptSseC($ciphertext, $key, $object->userMetadata['__sse-iv'], $object->userMetadata['__sse-tag']);
            self::assertSame($payload, $decrypted);
            self::assertNull($this->metadata->getObjectWriteIntent(substr($object->systemMetadata['storagePath'], -32)));
        }
    }

    public function test_bad_checksum_leaves_fenced_path_for_collector_without_publishing_metadata(): void
    {
        try {
            $this->handler()->handleRequest($this->request('payload', ['x-amz-checksum-sha256' => base64_encode(str_repeat('x', 32))]));
            self::fail('Expected checksum rejection.');
        } catch (BadDigestException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }

        $paths = glob($this->base . '/objects/bucket/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('payload', file_get_contents($paths[0]));
        self::assertNotNull($this->metadata->getObjectWriteIntent(substr($paths[0], -32)));
    }

    public function test_changed_bucket_encryption_rejects_plaintext_commit_and_retains_fenced_intent(): void
    {
        $factory = $this->factory(function (ObjectWriteIntent $armed): void {
            $this->metadata->putBucketEncryption('bucket', 'AES256');
        });
        try {
            $this->handler(factory: $factory)->handleRequest($this->request('payload'));
            self::fail('Expected commit cutover rejection.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }

        $paths = glob($this->base . '/objects/bucket/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('payload', file_get_contents($paths[0]));
        self::assertNotNull($this->metadata->getObjectWriteIntent(substr($paths[0], -32)));
    }

    public function test_expired_dispatch_token_cannot_publish_metadata_or_delete_target(): void
    {
        $factory = $this->factory(function (ObjectWriteIntent $armed): void {
            self::assertTrue($this->metadata->abandonObjectWriteIntent($armed->attemptId));
        });
        try {
            $this->handler(factory: $factory)->handleRequest($this->request('payload'));
            self::fail('Expected token CAS rejection.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }

        $paths = glob($this->base . '/objects/bucket/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('payload', file_get_contents($paths[0]));
        $intent = $this->metadata->getObjectWriteIntent(substr($paths[0], -32));
        self::assertNotNull($intent);
        self::assertNotNull($intent->dispatchId);
    }

    public function test_fenced_writer_rejects_nonlocal_default_tier(): void
    {
        $remote = new InMemoryBackend();
        $tiers = new StorageTierRegistry([new StorageTier('STANDARD', $remote, defaultWriteTier: true)]);

        $this->expectException(\InvalidArgumentException::class);
        new PutObjectHandler(
            $this->metadata,
            $remote,
            storageTiers: $tiers,
            localFencedWriter: $this->writer,
            localLeaseFactory: $this->factory(),
        );
    }

    public function test_fenced_writer_requires_lease_factory(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new PutObjectHandler($this->metadata, $this->storage, localFencedWriter: $this->writer);
    }

    private function handler(?EncryptionServiceInterface $encryption = null, ?LocalObjectWriteLeaseGuardFactory $factory = null): PutObjectHandler
    {
        return new PutObjectHandler(
            $this->metadata,
            $this->storage,
            encryption: $encryption,
            localFencedWriter: $this->writer,
            localLeaseFactory: $factory ?? $this->factory(),
        );
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(string $payload, array $headers = [], string $ownerId = 'owner'): Request
    {
        $requestHeaders = ['content-length' => (string) strlen($payload)];
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            $requestHeaders,
            $payload,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', $ownerId);
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        return $request;
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
                    /** @param null|\Closure(ObjectWriteIntent):void $afterArm */
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
                $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
                $guard = $method->invoke(null, $planned, $sql, $renewer, $this->admission);
                if (! $guard instanceof LocalObjectWriteLeaseGuard) {
                    throw new \LogicException('Lease guard test seam returned another type.');
                }

                return $guard;
            }
        };
    }
}
