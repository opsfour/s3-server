<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use League\Uri\Http;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Handler\Object\DeleteObjectHandler;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class DeleteObjectGarbageStagingTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-delete-garbage-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_single_delete_keeps_old_path_queued_when_inline_delete_fails(): void
    {
        $written = $this->storage->putObject('bucket', 'one', new ReadableBuffer('old-data'));
        $this->metadata->putObjectMetadata('bucket', 'one', 'owner', $written->size, '"' . $written->md5Hex . '"', 'text/plain', $written->path);
        $failing = new CallbackStorageBackend($this->storage, beforeDelete: static function (): never {
            throw new \RuntimeException('injected physical delete failure');
        });

        $response = (new DeleteObjectHandler($this->metadata, StorageTierRegistry::single($failing)))
            ->handleRequest($this->request('DELETE', '/bucket/one'));

        self::assertSame(204, $response->getStatus());
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'one'));
        self::assertSame('old-data', \Amp\ByteStream\buffer($this->storage->getObjectByPath($written->path)));
        self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect(1));
        $this->expectException(NoSuchKeyException::class);
        $this->storage->getObjectByPath($written->path);
    }

    public function test_batch_delete_keeps_every_old_path_queued_when_inline_deletes_fail(): void
    {
        $paths = [];
        foreach (['one', 'two'] as $key) {
            $written = $this->storage->putObject('bucket', $key, new ReadableBuffer($key));
            $paths[] = $written->path;
            $this->metadata->putObjectMetadata('bucket', $key, 'owner', $written->size, '"' . $written->md5Hex . '"', 'text/plain', $written->path);
        }
        $failing = new CallbackStorageBackend($this->storage, beforeDelete: static function (): never {
            throw new \RuntimeException('injected physical delete failure');
        });
        $body = '<Delete><Object><Key>one</Key></Object><Object><Key>two</Key></Object></Delete>';

        $response = (new DeleteObjectsHandler($this->metadata, StorageTierRegistry::single($failing)))
            ->handleRequest($this->request('POST', '/bucket?delete', $body));

        self::assertSame(200, $response->getStatus());
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'one'));
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'two'));
        self::assertSame('one', \Amp\ByteStream\buffer($this->storage->getObjectByPath($paths[0])));
        self::assertSame('two', \Amp\ByteStream\buffer($this->storage->getObjectByPath($paths[1])));
        self::assertSame(2, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect(2));
        foreach ($paths as $path) {
            try {
                $this->storage->getObjectByPath($path);
                self::fail('Batch delete left an old physical path behind.');
            } catch (NoSuchKeyException) {
                // Expected after durable garbage collection.
            }
        }
    }

    public function test_lost_delete_commit_acknowledgement_leaves_recoverable_exact_path(): void
    {
        $written = $this->storage->putObject('bucket', 'crash', new ReadableBuffer('old-data'));
        $this->metadata->putObjectMetadata('bucket', 'crash', 'owner', $written->size, '"' . $written->md5Hex . '"', 'text/plain', $written->path);
        $proxy = $this->createMock(MetadataStore::class);
        foreach ((new \ReflectionClass(MetadataStore::class))->getMethods() as $method) {
            if ($method->getName() === 'transaction') {
                continue;
            }
            $proxy->method($method->getName())->willReturnCallback((new \ReflectionMethod($this->metadata, $method->getName()))->getClosure($this->metadata));
        }
        $proxy->method('transaction')->willReturnCallback(function (callable $callback): never {
            $this->metadata->transaction($callback);
            throw new \RuntimeException('injected lost commit acknowledgement');
        });

        try {
            (new DeleteObjectHandler($proxy, StorageTierRegistry::single($this->storage)))
                ->handleRequest($this->request('DELETE', '/bucket/crash'));
            self::fail('Expected the injected commit acknowledgement failure.');
        } catch (\RuntimeException $error) {
            self::assertSame('injected lost commit acknowledgement', $error->getMessage());
        }

        self::assertNull($this->metadata->getObjectMetadata('bucket', 'crash'));
        self::assertSame('old-data', \Amp\ByteStream\buffer($this->storage->getObjectByPath($written->path)));
        self::assertSame(1, (new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage)))->collect(1));
        $this->expectException(NoSuchKeyException::class);
        $this->storage->getObjectByPath($written->path);
    }

    public function test_version_delete_queues_primary_and_restored_paths_on_their_physical_tiers(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $primary = $this->storage->putObject('bucket', 'versioned', new ReadableBuffer('primary'));
        $version = $this->metadata->putObjectVersioned('bucket', 'versioned', 'owner', $primary->size, '"' . $primary->md5Hex . '"', 'text/plain', $primary->path);
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');
        $restored = $hot->putObject('bucket', 'versioned', new ReadableBuffer('restored'));
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'versioned',
            $version,
            'restored',
            $restored->path,
            new \DateTimeImmutable('+1 day'),
            'HOT',
        );
        $failDelete = static function (): never {
            throw new \RuntimeException('injected physical delete failure');
        };
        $failingTiers = new StorageTierRegistry([
            new StorageTier('STANDARD', new CallbackStorageBackend($this->storage, beforeDelete: $failDelete), defaultWriteTier: true),
            new StorageTier('HOT', new CallbackStorageBackend($hot, beforeDelete: $failDelete)),
        ]);

        $response = (new DeleteObjectHandler($this->metadata, $failingTiers))
            ->handleRequest($this->request('DELETE', '/bucket/versioned?versionId=' . rawurlencode($version)));

        self::assertSame(204, $response->getStatus());
        self::assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'versioned', $version));
        self::assertSame('primary', \Amp\ByteStream\buffer($this->storage->getObjectByPath($primary->path)));
        self::assertSame('restored', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));
        $cleanTiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->storage, defaultWriteTier: true),
            new StorageTier('HOT', $hot),
        ]);
        self::assertSame(2, (new StorageGarbageCollector($this->metadata, $cleanTiers))->collect(2));
        foreach ([[$this->storage, $primary->path], [$hot, $restored->path]] as [$backend, $path]) {
            try {
                $backend->getObjectByPath($path);
                self::fail('Version delete left an old physical path behind.');
            } catch (NoSuchKeyException) {
                // Expected after durable garbage collection.
            }
        }
    }

    /** @param non-empty-string $method */
    private function request(string $method, string $uri, string $body = ''): Request
    {
        $client = $this->createMock(Client::class);
        $client->method('getRemoteAddress')->willReturn(new InternetAddress('127.0.0.1', 12345));
        $request = new Request($client, $method, Http::new('http://127.0.0.1' . $uri), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $path = parse_url($uri, PHP_URL_PATH);
        $request->setAttribute('s3.key', is_string($path) ? (explode('/', $path, 3)[2] ?? null) : null);
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
