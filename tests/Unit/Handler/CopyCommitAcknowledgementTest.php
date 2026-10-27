<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CopyCommitAcknowledgementTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $realMetadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-copy-commit-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->realMetadata = new SqliteMetadataStore($this->databasePath);
        $this->realMetadata->initialize();
        $this->realMetadata->createBucket('owner', 'source', 'us-east-1');
        $this->realMetadata->createBucket('owner', 'target', 'us-east-1');
        $this->realMetadata->putObjectMetadata('source', 'key', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'memory://source/key');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    /** @return iterable<string, array{bool}> */
    public static function versioningModes(): iterable
    {
        yield 'unversioned' => [false];
        yield 'versioned' => [true];
    }

    #[DataProvider('versioningModes')]
    public function test_committed_copy_survives_lost_transaction_acknowledgement(bool $versioned): void
    {
        if ($versioned) {
            $this->realMetadata->setBucketVersioning('target', 'Enabled');
        }
        $metadata = $this->acknowledgementMetadata(commit: true);
        $storage = $this->copyStorage('memory://target/copied', shouldDelete: false);

        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request());
            self::fail('The simulated lost acknowledgement must reach the handler.');
        } catch (\RuntimeException $error) {
            self::assertSame('lost commit acknowledgement', $error->getMessage());
        }

        $committed = $this->realMetadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($committed);
        self::assertSame('memory://target/copied', $committed->systemMetadata['storagePath']);
        self::assertSame($versioned, $committed->versionId !== null);
    }

    #[DataProvider('versioningModes')]
    public function test_rolled_back_copy_cleans_unreferenced_bytes(bool $versioned): void
    {
        if ($versioned) {
            $this->realMetadata->setBucketVersioning('target', 'Enabled');
        }
        $metadata = $this->acknowledgementMetadata(commit: false);
        $storage = $this->copyStorage('memory://target/copied', shouldDelete: true);

        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request());
            self::fail('The simulated rollback must reach the handler.');
        } catch (\RuntimeException $error) {
            self::assertSame('rolled back', $error->getMessage());
        }
        self::assertNull($this->realMetadata->getObjectMetadata('target', 'copied'));
    }

    public function test_lookup_failure_keeps_uncertain_copy_path(): void
    {
        $metadata = $this->acknowledgementMetadata(commit: false, failLookup: true);
        $storage = $this->copyStorage('memory://target/copied', shouldDelete: false);

        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request());
            self::fail('The simulated rollback must reach the handler.');
        } catch (\RuntimeException $error) {
            self::assertSame('rolled back', $error->getMessage());
        }
        self::assertNull($this->realMetadata->getObjectMetadata('target', 'copied'));
    }

    #[DataProvider('versioningModes')]
    public function test_callback_completed_before_rollback_keeps_ambiguous_path(bool $versioned): void
    {
        if ($versioned) {
            $this->realMetadata->setBucketVersioning('target', 'Enabled');
        }
        $metadata = $this->acknowledgementMetadata(commit: false, rollbackAfterCallback: true);
        $storage = $this->copyStorage('memory://target/copied', shouldDelete: false);

        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request());
            self::fail('The simulated rollback must reach the handler.');
        } catch (\RuntimeException $error) {
            self::assertSame('rolled back after callback', $error->getMessage());
        }
        self::assertNull($this->realMetadata->getObjectMetadata('target', 'copied'));
    }

    public function test_copy_backend_source_path_alias_is_never_deleted(): void
    {
        $storage = $this->copyStorage('memory://source/key', shouldDelete: false);

        $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
        (new CopyObjectHandler($this->realMetadata, $storage))->handleRequest($this->request());
    }

    public function test_copy_backend_existing_destination_path_alias_is_never_deleted(): void
    {
        $this->realMetadata->putObjectMetadata(
            'target',
            'copied',
            'owner',
            3,
            '"' . md5('old') . '"',
            'text/plain',
            'memory://target/existing',
        );
        $storage = $this->copyStorage('memory://target/existing', shouldDelete: false);

        try {
            (new CopyObjectHandler($this->realMetadata, $storage))->handleRequest($this->request());
            self::fail('The backend must not reuse a committed destination path.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertSame('memory://target/existing', $this->realMetadata->getObjectMetadata('target', 'copied')?->systemMetadata['storagePath']);
        }
    }

    private function acknowledgementMetadata(bool $commit, bool $failLookup = false, bool $rollbackAfterCallback = false): MetadataStore
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(true);
        $metadata->method('putObjectPartLayout')->willReturnCallback($this->realMetadata->putObjectPartLayout(...));
        $metadata->method('getObjectPartLayout')->willReturnCallback($this->realMetadata->getObjectPartLayout(...));
        $state = new class {
            public bool $transactionAttempted = false;
        };
        foreach ([
            'getBucket', 'getPublicAccessBlock', 'getObjectMetadata', 'getBucketOwner',
            'getBucketOwnershipControls', 'getAccountPolicy', 'getBucketPolicy',
            'getObjectTagging', 'getBucketEncryption', 'getBucketVersioning',
            'lockOwnerForUpdate', 'getObjectMetadataByVersion', 'putObjectMetadata',
            'putObjectVersioned', 'putAcl', 'putObjectTagging', 'getObjectLockConfig',
            'getObjectRetention', 'getObjectLegalHold',
        ] as $method) {
            $metadata->method($method)->willReturnCallback(fn(...$args) => $this->realMetadata->{$method}(...$args));
        }
        $metadata->method('getCurrentObjectVersion')->willReturnCallback(function (string $bucket, string $key) use ($failLookup, $state) {
            if ($failLookup && $state->transactionAttempted && $bucket === 'target') {
                throw new \RuntimeException('metadata lookup unavailable');
            }

            return $this->realMetadata->getCurrentObjectVersion($bucket, $key);
        });
        $metadata->method('transaction')->willReturnCallback(function (callable $callback) use ($commit, $rollbackAfterCallback, $state) {
            $state->transactionAttempted = true;
            if ($commit) {
                $this->realMetadata->transaction($callback);
                throw new \RuntimeException('lost commit acknowledgement');
            }

            if ($rollbackAfterCallback) {
                $this->realMetadata->transaction(function () use ($callback): void {
                    $callback();
                    throw new \RuntimeException('rolled back after callback');
                });
            }

            throw new \RuntimeException('rolled back');
        });

        return $metadata;
    }

    private function copyStorage(string $resultPath, bool $shouldDelete): StorageBackend
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer('payload'));
        $storage->method('putObject')->willReturn(new StorageWriteResult($resultPath, 7, md5('payload')));
        $storage->expects($shouldDelete ? self::once() : self::never())
            ->method('deleteObjectByPath')->with($resultPath, 'target');

        return $storage;
    }

    private function request(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/target/copied'),
            ['x-amz-copy-source' => '/source/key'],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
