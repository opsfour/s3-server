<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class CopyOverwriteGarbageTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-copy-old-gc-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->createBucket('owner', 'target', 'us-east-1');
        $this->metadata->putObjectMetadata('source', 'key', 'owner', 7, '"' . md5('payload') . '"', 'text/plain', 'source-name');
        $this->metadata->putObjectMetadata('target', 'copied', 'owner', 3, '"' . md5('old') . '"', 'text/plain', 'same-name');
        $this->metadata->updateObjectPlacement('target', 'copied', null, 'STANDARD', 'COLD', 'same-name');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    /** @return iterable<string, array{bool}> */
    public static function deleteOutcomes(): iterable
    {
        yield 'delete confirms removal' => [false];
        yield 'delete fails after commit' => [true];
    }

    #[DataProvider('deleteOutcomes')]
    public function test_old_tier_path_is_prequeued_and_discarded_only_after_confirmed_delete(bool $failDelete): void
    {
        $hot = $this->createMock(StorageBackend::class);
        $hot->method('getObjectByPath')->with('source-name')->willReturn(new ReadableBuffer('payload'));
        $hot->method('putObject')->willReturn(new StorageWriteResult('same-name', 7, md5('payload')));
        $hot->expects(self::never())->method('deleteObjectByPath');

        $cold = $this->createMock(StorageBackend::class);
        $cold->expects(self::once())->method('deleteObjectByPath')->with('same-name', 'target')
            ->willReturnCallback(static function () use ($failDelete): void {
                if ($failDelete) {
                    throw new \RuntimeException('physical delete failed');
                }
            });

        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $hot, defaultWriteTier: true),
            new StorageTier('COLD', $cold),
        ]);
        $this->metadata->updateObjectPlacement('source', 'key', null, 'STANDARD', 'HOT', 'source-name');

        $response = (new CopyObjectHandler($this->metadata, $hot, storageTiers: $tiers))->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        $copied = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($copied);
        self::assertSame('HOT', $copied->storageTier);
        self::assertSame('same-name', $copied->systemMetadata['storagePath']);
        $jobs = $this->metadata->dequeueStorageGarbage(10);
        if ($failDelete) {
            self::assertCount(1, $jobs);
            self::assertSame('COLD', $jobs[0]['storage_tier']);
            self::assertSame('same-name', $jobs[0]['storage_path']);
        } else {
            self::assertSame([], $jobs);
        }
    }

    public function test_old_restored_copy_is_also_prequeued_before_overwrite_commit(): void
    {
        $this->metadata->updateObjectRestoreStateWithTier(
            'target',
            'copied',
            null,
            'restored',
            'restore-name',
            new \DateTimeImmutable('+1 day'),
            'COLD',
        );
        $this->metadata->updateObjectPlacement('source', 'key', null, 'STANDARD', 'HOT', 'source-name');

        $hot = $this->createMock(StorageBackend::class);
        $hot->method('getObjectByPath')->with('source-name')->willReturn(new ReadableBuffer('payload'));
        $hot->method('putObject')->willReturn(new StorageWriteResult('new-hot-name', 7, md5('payload')));
        $cold = $this->createMock(StorageBackend::class);
        $cold->expects(self::exactly(2))->method('deleteObjectByPath')
            ->willThrowException(new \RuntimeException('physical delete failed'));
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $hot, defaultWriteTier: true),
            new StorageTier('COLD', $cold),
        ]);

        self::assertSame(200, (new CopyObjectHandler($this->metadata, $hot, storageTiers: $tiers))
            ->handleRequest($this->request())->getStatus());

        $jobs = $this->metadata->dequeueStorageGarbage(10);
        self::assertCount(2, $jobs);
        self::assertSame(['same-name', 'restore-name'], array_map(
            static fn(array $job): string => $job['storage_path'],
            $jobs,
        ));
        self::assertSame(['COLD', 'COLD'], array_column($jobs, 'storage_tier'));
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
