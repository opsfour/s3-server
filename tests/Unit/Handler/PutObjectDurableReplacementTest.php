<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class PutObjectDurableReplacementTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-put-replace-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_failed_delete_after_commit_keeps_exact_old_path_queued_for_recovery(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $old = $storage->putObject('bucket', 'key', new ReadableBuffer('old'));
        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 3, '"' . md5('old') . '"', 'text/plain', $old->path);
        $failing = new CallbackStorageBackend($storage, beforeDelete: static function (string $path) use ($old): void {
            if ($path === $old->path) {
                throw new \RuntimeException('Injected failure after committed replacement.');
            }
        });

        $response = (new PutObjectHandler($this->metadata, $failing))->handleRequest($this->request('new'));

        self::assertSame(200, $response->getStatus());
        $current = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($current);
        self::assertNotSame($old->path, $current->systemMetadata['storagePath']);
        self::assertSame('new', \Amp\ByteStream\buffer($storage->getObjectByPath($current->systemMetadata['storagePath'])));
        self::assertSame('old', \Amp\ByteStream\buffer($storage->getObjectByPath($old->path)));
        $queued = $this->metadata->dequeueStorageGarbage(10);
        self::assertCount(1, $queued);
        self::assertSame('STANDARD', $queued[0]['storage_tier']);
        self::assertSame($old->path, $queued[0]['storage_path']);
    }

    public function test_cancelled_post_commit_delete_keeps_replacement_and_rethrows_cancellation(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $old = $storage->putObject('bucket', 'key', new ReadableBuffer('old'));
        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 3, '"' . md5('old') . '"', 'text/plain', $old->path);
        $cancellation = new \Amp\CancelledException();
        $failing = new CallbackStorageBackend($storage, beforeDelete: static function (string $path) use ($old, $cancellation): void {
            if ($path === $old->path) {
                throw $cancellation;
            }
        });

        try {
            (new PutObjectHandler($this->metadata, $failing))->handleRequest($this->request('new'));
            self::fail('Cancellation after the metadata commit must propagate.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }

        $current = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($current);
        self::assertNotSame($old->path, $current->systemMetadata['storagePath']);
        self::assertSame('new', \Amp\ByteStream\buffer($storage->getObjectByPath($current->systemMetadata['storagePath'])));
        self::assertSame('old', \Amp\ByteStream\buffer($storage->getObjectByPath($old->path)));
        $queued = $this->metadata->dequeueStorageGarbage(10);
        self::assertCount(1, $queued);
        self::assertSame($old->path, $queued[0]['storage_path']);
    }

    public function test_metadata_cancellation_is_not_replaced_when_failed_write_cleanup_cannot_be_queued(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $cancellation = new \Amp\CancelledException();
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        self::assertInstanceOf(MetadataStore::class, $metadata);
        self::assertInstanceOf(ObjectPartLayoutStore::class, $metadata);
        $configured = [];
        foreach ([MetadataStore::class, ObjectPartLayoutStore::class] as $contract) {
            foreach ((new \ReflectionClass($contract))->getMethods() as $method) {
                $name = $method->getName();
                if (in_array($name, ['transaction', 'enqueueStorageGarbage'], true) || isset($configured[$name])) {
                    continue;
                }
                $configured[$name] = true;
                $metadata->method($name)->willReturnCallback(
                    fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments),
                );
            }
        }
        $metadata->method('transaction')->willThrowException($cancellation);
        $metadata->method('enqueueStorageGarbage')->willThrowException(new \RuntimeException('cleanup queue failed'));
        $failing = new CallbackStorageBackend(
            $storage,
            beforeDelete: static function (): void {
                throw new \RuntimeException('delete failed');
            },
        );

        try {
            (new PutObjectHandler($metadata, $failing))->handleRequest($this->request('new'));
            self::fail('The metadata cancellation must propagate.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
    }

    public function test_identical_relative_paths_in_different_tiers_cleanup_only_old_tier(): void
    {
        $oldTier = new InMemoryBackend();
        $newTier = new InMemoryBackend();
        $oldTier->createBucket('bucket');
        $newTier->createBucket('bucket');
        $old = $oldTier->putObject('bucket', 'key', new ReadableBuffer('old'));
        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 3, '"' . md5('old') . '"', 'text/plain', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'key', null, 'STANDARD', 'STANDARD', $old->path);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $oldTier),
            new StorageTier('COLD', $newTier, defaultWriteTier: true),
        ]);

        $response = (new PutObjectHandler($this->metadata, $newTier, storageTiers: $tiers))->handleRequest($this->request('new'));

        self::assertSame(200, $response->getStatus());
        $current = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($current);
        self::assertSame($old->path, $current->systemMetadata['storagePath']);
        self::assertSame('COLD', $current->storageTier);
        self::assertSame('new', \Amp\ByteStream\buffer($newTier->getObjectByPath($current->systemMetadata['storagePath'])));
        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $oldTier->getObjectByPath($old->path)->read();
    }

    public function test_failed_source_and_restore_deletes_keep_separate_tier_path_jobs(): void
    {
        $oldTier = new InMemoryBackend();
        $restoredTier = new InMemoryBackend();
        $newTier = new InMemoryBackend();
        foreach ([$oldTier, $restoredTier, $newTier] as $backend) {
            $backend->createBucket('bucket');
        }
        $old = $oldTier->putObject('bucket', 'key', new ReadableBuffer('old'));
        $restored = $restoredTier->putObject('bucket', 'key', new ReadableBuffer('restored'));
        self::assertSame($old->path, $restored->path);
        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 3, '"' . md5('old') . '"', 'text/plain', $old->path);
        $this->metadata->updateObjectPlacement('bucket', 'key', null, 'GLACIER', 'GLACIER', $old->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'key',
            'null',
            'restored',
            $restored->path,
            new \DateTimeImmutable('+1 day'),
            'HOT',
        );
        $failDelete = static function (): never {
            throw new \RuntimeException('Injected physical delete failure.');
        };
        $tiers = new StorageTierRegistry([
            new StorageTier('GLACIER', new CallbackStorageBackend($oldTier, beforeDelete: $failDelete)),
            new StorageTier('HOT', new CallbackStorageBackend($restoredTier, beforeDelete: $failDelete)),
            new StorageTier('COLD', $newTier, defaultWriteTier: true),
        ]);

        $response = (new PutObjectHandler($this->metadata, $newTier, storageTiers: $tiers))->handleRequest($this->request('new'));

        self::assertSame(200, $response->getStatus());
        $current = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($current);
        self::assertSame('COLD', $current->storageTier);
        self::assertSame($old->path, $current->systemMetadata['storagePath']);
        self::assertSame('new', \Amp\ByteStream\buffer($newTier->getObjectByPath($current->systemMetadata['storagePath'])));
        $queued = $this->metadata->dequeueStorageGarbage(10);
        self::assertCount(2, $queued);
        self::assertSame(['GLACIER', 'HOT'], array_column($queued, 'storage_tier'));
        self::assertSame([$old->path, $restored->path], array_column($queued, 'storage_path'));
    }

    private function request(string $payload): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['content-length' => (string) strlen($payload)],
            $payload,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        return $request;
    }
}
