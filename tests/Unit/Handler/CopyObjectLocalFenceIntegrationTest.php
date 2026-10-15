<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseSql;
use OpsFour\S3Server\Storage\StoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CopyObjectLocalFenceIntegrationTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private FilesystemBackend $storage;

    private LocalFencedObjectWriter $writer;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-fenced-copy-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base . '/objects', 0700, true));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->createBucket('owner', 'target', 'us-east-1');
        $this->storage = new FilesystemBackend($this->base . '/objects');
        $this->storage->createBucket('source');
        $this->storage->createBucket('target');
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

    /** @return iterable<string, array{bool}> */
    public static function versioningModes(): iterable
    {
        yield 'unversioned' => [false];
        yield 'versioned' => [true];
    }

    #[DataProvider('versioningModes')]
    public function test_plain_copy_publishes_only_exact_armed_path(bool $versioned): void
    {
        $this->source('payload');
        if ($versioned) {
            $this->metadata->setBucketVersioning('target', 'Enabled');
        }

        $response = $this->handler()->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($object);
        self::assertSame($versioned, $object->versionId !== null);
        self::assertSame('payload', file_get_contents($object->systemMetadata['storagePath']));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($object->systemMetadata['storagePath'], -32)));
        self::assertSame([], $this->metadata->dequeueStorageGarbage(10));
    }

    public function test_anonymous_copy_intent_is_reserved_for_destination_bucket_owner(): void
    {
        $this->source('payload');
        $this->metadata->putBucketPolicy('source', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::source/*',
            ]],
        ], JSON_THROW_ON_ERROR));
        $this->metadata->putBucketPolicy('target', json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::target/*',
            ]],
        ], JSON_THROW_ON_ERROR));
        $intentOwner = null;
        $factory = $this->factory(function (string $attemptId) use (&$intentOwner): void {
            $intentOwner = $this->metadata->getObjectWriteIntent($attemptId)?->ownerId;
        });
        $request = $this->request();
        $request->setAttribute('ownerId', '');

        self::assertSame(200, $this->handler(factory: $factory)->handleRequest($request)->getStatus());
        self::assertSame('owner', $intentOwner);
        self::assertSame('owner', $this->metadata->getObjectMetadata('target', 'copied')?->ownerId);
    }

    public function test_sse_source_is_decrypted_before_one_fenced_plaintext_write(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $encrypted = $encryption->encryptSseS3('payload');
        $this->source($encrypted['ciphertext'], [
            '__sse-algorithm' => 'AES256',
            '__sse-key' => $encrypted['encryptedDataKey'],
            '__sse-iv' => $encrypted['iv'],
            '__sse-tag' => $encrypted['tag'],
        ], '"' . md5('payload') . '"');

        $response = $this->handler($encryption)->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($object);
        self::assertSame('payload', file_get_contents($object->systemMetadata['storagePath']));
        self::assertArrayNotHasKey('__sse-algorithm', $object->userMetadata);
        self::assertNull($this->metadata->getObjectWriteIntent(substr($object->systemMetadata['storagePath'], -32)));
    }

    public function test_sse_destination_publishes_ciphertext_once_with_plaintext_etag(): void
    {
        $this->source('payload');
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));

        $response = $this->handler($encryption)->handleRequest($this->request([
            'x-amz-server-side-encryption' => 'AES256',
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption'));
        $object = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($object);
        self::assertSame('"' . md5('payload') . '"', $object->etag);
        $ciphertext = file_get_contents($object->systemMetadata['storagePath']);
        self::assertIsString($ciphertext);
        self::assertNotSame('payload', $ciphertext);
        self::assertSame('payload', $encryption->decryptSseS3(
            $ciphertext,
            $object->userMetadata['__sse-key'],
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        ));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($object->systemMetadata['storagePath'], -32)));
    }

    public function test_sse_c_source_and_destination_use_distinct_keys_without_plaintext_staging(): void
    {
        $encryption = new EncryptionService();
        $sourceKey = random_bytes(32);
        $destinationKey = random_bytes(32);
        $encrypted = $encryption->encryptSseC('payload', $sourceKey);
        $this->source($encrypted['ciphertext'], [
            '__sse-algorithm' => 'SSE-C',
            '__sse-iv' => $encrypted['iv'],
            '__sse-tag' => $encrypted['tag'],
            '__sse-customer-key-md5' => base64_encode(md5($sourceKey, true)),
        ], '"' . md5('payload') . '"');

        $response = $this->handler($encryption)->handleRequest($this->request([
            'x-amz-copy-source-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-copy-source-server-side-encryption-customer-key' => base64_encode($sourceKey),
            'x-amz-copy-source-server-side-encryption-customer-key-md5' => base64_encode(md5($sourceKey, true)),
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($destinationKey),
            'x-amz-server-side-encryption-customer-key-md5' => base64_encode(md5($destinationKey, true)),
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertSame('AES256', $response->getHeader('x-amz-server-side-encryption-customer-algorithm'));
        self::assertSame(
            base64_encode(md5($destinationKey, true)),
            $response->getHeader('x-amz-server-side-encryption-customer-key-MD5'),
        );
        $object = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($object);
        self::assertSame('"' . md5('payload') . '"', $object->etag);
        $ciphertext = file_get_contents($object->systemMetadata['storagePath']);
        self::assertIsString($ciphertext);
        self::assertNotSame('payload', $ciphertext);
        self::assertSame('payload', $encryption->decryptSseC(
            $ciphertext,
            $destinationKey,
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        ));
        $deadline = microtime(true) + 5.0;
        while (glob($this->base . '/scratch/s3fw-put-*') !== [] && microtime(true) < $deadline) {
            \Amp\delay(0.01);
        }
        self::assertSame([], glob($this->base . '/scratch/s3fw-put-*'));
        self::assertNull($this->metadata->getObjectWriteIntent(substr($object->systemMetadata['storagePath'], -32)));
    }

    public function test_source_corruption_retains_fenced_intent_without_destination_metadata(): void
    {
        $this->source('corrupt', etag: '"' . md5('payload') . '"');

        try {
            $this->handler()->handleRequest($this->request());
            self::fail('The changed source bytes must be rejected.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }

        $paths = glob($this->base . '/objects/target/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('corrupt', file_get_contents($paths[0]));
        self::assertNotNull($this->metadata->getObjectWriteIntent(substr($paths[0], -32)));
    }

    public function test_unknown_stored_source_encryption_mode_fails_before_copy_publication(): void
    {
        $this->source('opaque', ['__sse-algorithm' => 'unknown']);
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));

        $this->expectException(InternalErrorException::class);
        try {
            $this->handler($encryption)->handleRequest($this->request());
        } finally {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
            self::assertSame([], glob($this->base . '/objects/target/*/*/*'));
        }
    }

    public function test_lost_exact_dispatch_token_cannot_publish_metadata_or_delete_bytes(): void
    {
        $this->source('payload');
        $factory = $this->factory(function (string $attemptId): void {
            $this->metadata->abandonObjectWriteIntent($attemptId);
        });

        try {
            $this->handler(factory: $factory)->handleRequest($this->request());
            self::fail('The lost exact dispatch token must reject publication.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }

        $paths = glob($this->base . '/objects/target/*/*/*');
        self::assertIsArray($paths);
        self::assertCount(1, $paths);
        self::assertSame('payload', file_get_contents($paths[0]));
        self::assertNotNull($this->metadata->getObjectWriteIntent(substr($paths[0], -32)));
    }

    /** @param array<string, string> $userMetadata */
    private function source(string $physicalBytes, array $userMetadata = [], ?string $etag = null): void
    {
        $write = $this->storage->putObject('source', 'key', new ReadableBuffer($physicalBytes));
        $this->metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            strlen($physicalBytes),
            $etag ?? '"' . md5($physicalBytes) . '"',
            'text/plain',
            $write->path,
            userMetadata: $userMetadata,
        );
    }

    private function handler(?EncryptionService $encryption = null, ?LocalObjectWriteLeaseGuardFactory $factory = null): CopyObjectHandler
    {
        return new CopyObjectHandler(
            $this->metadata,
            $this->storage,
            encryption: $encryption,
            localFencedWriter: $this->writer,
            localLeaseFactory: $factory ?? $this->factory(),
        );
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(array $headers = []): Request
    {
        $requestHeaders = ['x-amz-copy-source' => '/source/key'];
        foreach ($headers as $name => $value) {
            $requestHeaders[$name] = $value;
        }
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/target/copied'),
            $requestHeaders,
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    /** @param null|\Closure(string):void $afterArm */
    private function factory(?\Closure $afterArm = null): LocalObjectWriteLeaseGuardFactory
    {
        return new class ($this->metadata, $this->writer->storageRoot(), $afterArm) implements LocalObjectWriteLeaseGuardFactory {
            private LocalObjectWriteLeaseAdmission $admission;

            public function __construct(private SqliteMetadataStore $metadata, private string $root, private ?\Closure $afterArm)
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
                        ($this->afterArm)?->__invoke($current->attemptId);

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
                TestCase::assertSame($planned->storagePath, $guard->reservedIntent()->storagePath);
                TestCase::assertSame(
                    StoragePath::forPlannedObject($this->root, $planned->bucket, 'copied', $planned->bucketGeneration, $planned->attemptId),
                    $planned->storagePath,
                );

                return $guard;
            }
        };
    }
}
