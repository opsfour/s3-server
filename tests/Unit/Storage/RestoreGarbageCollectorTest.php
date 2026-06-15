<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use PHPUnit\Framework\TestCase;

final class RestoreGarbageCollectorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-restore-gc-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_collect_removes_expired_restore_copy_and_clears_restore_state(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');

        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreState(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $restored->path,
            new \DateTimeImmutable('2020-01-01T00:00:00Z'),
        );

        $stats = (new RestoreGarbageCollector($this->metadata, $hot))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertSame(['scanned' => 1, 'expired' => 1, 'failed' => 0], $stats);
        self::assertNotNull($object);
        self::assertNull($object->restoreStatus);
        self::assertNull($object->restoredStoragePath);
        self::assertNull($object->restoreExpiresAt);

        $this->expectException(NoSuchKeyException::class);
        $hot->getObjectByPath($restored->path)->read();
    }

    public function test_collect_keeps_non_expired_restore_copy(): void
    {
        $hot = new InMemoryBackend();
        $hot->createBucket('bucket');

        $original = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-placeholder'));
        $restored = $hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->metadata->putObjectMetadata('bucket', 'archive.bin', 'owner', $original->size, '"' . $original->md5Hex . '"', 'application/octet-stream', $original->path);
        $this->metadata->updateObjectRestoreState(
            'bucket',
            'archive.bin',
            null,
            'restored',
            $restored->path,
            new \DateTimeImmutable('2020-01-03T00:00:00Z'),
        );

        $stats = (new RestoreGarbageCollector($this->metadata, $hot))
            ->collect(now: new \DateTimeImmutable('2020-01-02T00:00:00Z'));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        self::assertSame(['scanned' => 0, 'expired' => 0, 'failed' => 0], $stats);
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('restored-data', \Amp\ByteStream\buffer($hot->getObjectByPath($restored->path)));
    }
}
