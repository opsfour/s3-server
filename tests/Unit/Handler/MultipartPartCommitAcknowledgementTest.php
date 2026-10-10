<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartPartCommitAcknowledgementTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-part-ack-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->createMultipartUpload('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa', 'bucket', 'target', 'owner');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
        $source = $this->storage->putObject('bucket', 'source', new ReadableBuffer('payload'));
        $this->metadata->putObjectMetadata('bucket', 'source', 'owner', $source->size, '"' . $source->md5Hex . '"', 'text/plain', $source->path);
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    /** @return iterable<string, array{bool, string}> */
    public static function cases(): iterable
    {
        foreach ([false => 'upload', true => 'copy'] as $copy => $label) {
            yield $label . ' committed then throws' => [(bool) $copy, 'committed'];
            yield $label . ' rolled back' => [(bool) $copy, 'rollback'];
            yield $label . ' lookup unavailable' => [(bool) $copy, 'lookup-failed'];
            yield $label . ' callback completed without commit' => [(bool) $copy, 'ambiguous-no-row'];
        }
    }

    #[DataProvider('cases')]
    public function test_failed_transaction_acknowledgement_never_deletes_a_possibly_committed_part(bool $copy, string $mode): void
    {
        $proxy = $this->metadataProxy($mode);
        $storage = new CallbackStorageBackend($this->storage);
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/target?partNumber=1&uploadId=aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa'),
            $copy ? ['x-amz-copy-source' => '/bucket/source'] : [],
            $copy ? '' : 'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'target');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', $copy ? S3Operation::UploadPartCopy : S3Operation::UploadPart);

        try {
            ($copy ? new UploadPartCopyHandler($proxy, $storage) : new UploadPartHandler($proxy, $storage))
                ->handleRequest($request);
            self::fail('The simulated transaction acknowledgment must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated transaction failure', $e->getMessage());
        }

        self::assertNotNull($storage->lastPartWrite);
        $path = $storage->lastPartWrite->path;
        $parts = $this->metadata->getParts('aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa');
        if ($mode !== 'committed') {
            self::assertSame([], $parts);
        }
        if ($mode === 'rollback') {
            try {
                $this->storage->getObjectByPath($path);
                self::fail('The rolled-back physical part was not deleted.');
            } catch (NoSuchKeyException) {
                return;
            }
        }

        if ($mode === 'committed') {
            self::assertCount(1, $parts);
            self::assertSame($path, $parts[0]['storage_path']);
        }
        self::assertSame('payload', \Amp\ByteStream\buffer($this->storage->getObjectByPath($path)));
    }

    /** @return iterable<string, array{bool, string}> */
    public static function replacementCases(): iterable
    {
        foreach ([false => 'upload', true => 'copy'] as $copy => $label) {
            yield $label . ' committed before cleanup' => [(bool) $copy, 'committed'];
            yield $label . ' rolled back' => [(bool) $copy, 'rollback'];
        }
    }

    #[DataProvider('replacementCases')]
    public function test_replaced_part_cleanup_is_committed_with_part_metadata(bool $copy, string $mode): void
    {
        $uploadId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $old = $this->storage->putPart('bucket', 'target', $uploadId, 1, new ReadableBuffer('old'));
        $this->metadata->putPart($uploadId, 1, '"' . $old->md5Hex . '"', $old->size, $old->path);
        $storage = new CallbackStorageBackend($this->storage);
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/target?partNumber=1&uploadId=' . $uploadId),
            $copy ? ['x-amz-copy-source' => '/bucket/source'] : [],
            $copy ? '' : 'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'target');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', $copy ? S3Operation::UploadPartCopy : S3Operation::UploadPart);

        try {
            ($copy ? new UploadPartCopyHandler($this->metadataProxy($mode), $storage) : new UploadPartHandler($this->metadataProxy($mode), $storage))
                ->handleRequest($request);
            self::fail('The simulated transaction acknowledgment must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('simulated transaction failure', $e->getMessage());
        }

        $jobs = $this->metadata->dequeueStorageGarbage(10);
        if ($mode === 'committed') {
            self::assertNotNull($storage->lastPartWrite);
            self::assertSame($storage->lastPartWrite->path, $this->metadata->getParts($uploadId)[0]['storage_path']);
            self::assertCount(1, $jobs);
            self::assertSame(['STANDARD', $old->path, 'delete_path'], [$jobs[0]['storage_tier'], $jobs[0]['storage_path'], $jobs[0]['operation']]);
            self::assertSame('old', \Amp\ByteStream\buffer($this->storage->getObjectByPath($old->path)));
        } else {
            self::assertSame($old->path, $this->metadata->getParts($uploadId)[0]['storage_path']);
            self::assertSame([], $jobs);
        }
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function inlineDeleteCases(): iterable
    {
        foreach ([false => 'upload', true => 'copy'] as $copy => $label) {
            yield $label . ' delete succeeds' => [(bool) $copy, false];
            yield $label . ' delete fails' => [(bool) $copy, true];
        }
    }

    #[DataProvider('inlineDeleteCases')]
    public function test_replaced_part_job_is_discarded_only_after_physical_delete(bool $copy, bool $deleteFails): void
    {
        $uploadId = 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';
        $old = $this->storage->putPart('bucket', 'target', $uploadId, 1, new ReadableBuffer('old'));
        $this->metadata->putPart($uploadId, 1, '"' . $old->md5Hex . '"', $old->size, $old->path);
        $storage = new CallbackStorageBackend(
            $this->storage,
            beforeDelete: $deleteFails ? static function (string $path) use ($old): void {
                if ($path === $old->path) {
                    throw new \RuntimeException('physical delete failed');
                }
            } : null,
        );
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/target?partNumber=1&uploadId=' . $uploadId),
            $copy ? ['x-amz-copy-source' => '/bucket/source'] : [],
            $copy ? '' : 'payload',
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'target');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', $copy ? S3Operation::UploadPartCopy : S3Operation::UploadPart);

        self::assertSame(200, ($copy ? new UploadPartCopyHandler($this->metadata, $storage) : new UploadPartHandler($this->metadata, $storage))
            ->handleRequest($request)->getStatus());
        $jobs = $this->metadata->dequeueStorageGarbage(10);
        if ($deleteFails) {
            self::assertCount(1, $jobs);
            self::assertSame($old->path, $jobs[0]['storage_path']);
            self::assertSame('old', \Amp\ByteStream\buffer($this->storage->getObjectByPath($old->path)));
        } else {
            self::assertSame([], $jobs);
            try {
                $this->storage->getObjectByPath($old->path);
                self::fail('The replaced part was not deleted.');
            } catch (NoSuchKeyException) {
                self::assertNotSame($old->path, $this->metadata->getParts($uploadId)[0]['storage_path']);
            }
        }
    }

    private function metadataProxy(string $mode): MetadataStore
    {
        $proxy = $this->createMock(MetadataStore::class);
        $state = new class {
            public bool $putPartFailed = false;
        };
        foreach ((new \ReflectionClass(MetadataStore::class))->getMethods() as $method) {
            $name = $method->getName();
            if ($name === 'transaction' || $name === 'getParts' || $name === 'putPart') {
                continue;
            }
            $proxy->method($name)->willReturnCallback(fn(mixed ...$arguments): mixed => $this->metadata->{$name}(...$arguments));
        }
        $proxy->method('putPart')->willReturnCallback(function (string $uploadId, int $partNumber, string $etag, int $size, string $path) use ($state, $mode): void {
            $this->metadata->putPart($uploadId, $partNumber, $etag, $size, $path);
            if ($mode === 'rollback' || $mode === 'lookup-failed') {
                $state->putPartFailed = true;
                throw new \RuntimeException('simulated transaction failure');
            }
        });
        $proxy->method('getParts')->willReturnCallback(function (string $uploadId) use ($state, $mode): array {
            if ($state->putPartFailed && $mode === 'lookup-failed') {
                throw new \RuntimeException('metadata lookup unavailable');
            }

            return $this->metadata->getParts($uploadId);
        });
        $proxy->method('transaction')->willReturnCallback(function (callable $callback) use ($mode): mixed {
            if ($mode === 'ambiguous-no-row') {
                $this->metadata->transaction(static function () use ($callback): void {
                    $callback();
                    throw new \RuntimeException('simulated transaction failure');
                });
            }
            $this->metadata->transaction($callback);
            throw new \RuntimeException('simulated transaction failure');
        });

        return $proxy;
    }
}
