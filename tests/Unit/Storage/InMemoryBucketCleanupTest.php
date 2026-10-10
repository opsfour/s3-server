<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class InMemoryBucketCleanupTest extends TestCase
{
    public function test_planned_object_path_binds_bucket_and_key(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $path = $storage->planObjectWritePath('bucket', 'key', bin2hex(random_bytes(16)), bin2hex(random_bytes(16)));

        try {
            $storage->putObjectAtPath('bucket', 'other-key', new ReadableBuffer('wrong'), $path);
            self::fail('Wrong-key planned object path must be rejected.');
        } catch (\InvalidArgumentException) {
        }

        $write = $storage->putObjectAtPath('bucket', 'key', new ReadableBuffer('planned'), $path);
        self::assertSame($path, $write->path);
        self::assertSame('planned', $storage->getObjectByPath($path)->read());
    }

    public function test_multipart_assembly_rejects_part_checksum_disagreement(): void
    {
        $storage = new InMemoryBackend();
        $part = $storage->putPart('bucket', 'key', 'upload', 1, new ReadableBuffer('original'));

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('checksum verification');
        $storage->assembleMultipartUpload('bucket', 'key', 'upload', [[
            'partNumber' => 1,
            'etag' => md5('tampered'),
            'storagePath' => $part->path,
        ]]);
    }

    public function test_bucket_deletion_releases_its_objects_and_parts_only(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('old');
        $storage->createBucket('other');
        $oldObject = $storage->putObject('old', 'key', new ReadableBuffer('old data'));
        $otherObject = $storage->putObject('other', 'key', new ReadableBuffer('other data'));
        $oldPart = $storage->putPart('old', 'key', 'old-upload', 1, new ReadableBuffer('old part'));
        $otherPart = $storage->putPart('other', 'key', 'other-upload', 1, new ReadableBuffer('other part'));

        $storage->deleteBucket('old');
        self::assertFalse($storage->bucketExists('old'));
        self::assertTrue($storage->bucketExists('other'));
        try {
            $storage->getObjectByPath($oldObject->path);
            self::fail('Deleted bucket object data is still retained.');
        } catch (NoSuchKeyException) {
        }
        try {
            $storage->assembleMultipartUpload('old', 'key', 'old-upload', [[
                'partNumber' => 1,
                'etag' => $oldPart->md5Hex,
                'storagePath' => $oldPart->path,
            ]]);
            self::fail('Deleted bucket part data is still retained.');
        } catch (InternalErrorException) {
        }

        self::assertSame('other data', $storage->getObjectByPath($otherObject->path)->read());
        $assembled = $storage->assembleMultipartUpload('other', 'key', 'other-upload', [[
            'partNumber' => 1,
            'etag' => $otherPart->md5Hex,
            'storagePath' => $otherPart->path,
        ]]);
        self::assertSame('other part', $storage->getObjectByPath($assembled->path)->read());

        $storage->createBucket('old');
        self::assertTrue($storage->bucketExists('old'));
    }
}
