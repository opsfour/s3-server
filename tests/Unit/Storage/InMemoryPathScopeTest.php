<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class InMemoryPathScopeTest extends TestCase
{
    public function test_delete_rejects_another_buckets_object_and_part(): void
    {
        $storage = new InMemoryBackend();
        $object = $storage->putObject('other', 'key', new ReadableBuffer('object'));
        $part = $storage->putPart('other', 'key', str_repeat('a', 32), 1, new ReadableBuffer('part'));

        foreach ([$object->path, $part->path] as $path) {
            try {
                $storage->deleteObjectByPath($path, 'bucket');
                self::fail('Cross-bucket deletion should fail.');
            } catch (\InvalidArgumentException) {
                self::assertNotNull($storage->getObjectByPath($path)->read());
            }
        }
    }

    public function test_planned_assembly_rejects_another_bucket_or_key(): void
    {
        $storage = new InMemoryBackend();
        $uploadId = str_repeat('a', 32);
        $paths = $storage->planMultipartCompletionPaths('other', 'key', str_repeat('b', 32), str_repeat('c', 32));

        foreach ([['bucket', 'key'], ['other', 'different-key']] as [$bucket, $key]) {
            $this->expectScopedAssemblyFailure($storage, $bucket, $key, $uploadId, $paths['assembly']);
        }
    }

    public function test_abort_rejects_another_buckets_upload_parts(): void
    {
        $storage = new InMemoryBackend();
        $part = $storage->putPart('other', 'key', str_repeat('a', 32), 1, new ReadableBuffer('part'));

        try {
            $storage->abortMultipartUpload('bucket', 'key', str_repeat('a', 32));
            self::fail('Cross-bucket abort should fail.');
        } catch (\InvalidArgumentException) {
            self::assertSame('part', \Amp\ByteStream\buffer($storage->getObjectByPath($part->path)));
        }
    }

    public function test_assembly_rejects_part_from_another_bucket_with_same_upload_id(): void
    {
        $storage = new InMemoryBackend();
        $uploadId = str_repeat('a', 32);
        $part = $storage->putPart('other', 'key', $uploadId, 1, new ReadableBuffer('part'));

        try {
            $storage->assembleMultipartUpload('bucket', 'key', $uploadId, [
                ['partNumber' => 1, 'etag' => $part->md5Hex, 'storagePath' => $part->path],
            ]);
            self::fail('Assembly must not read another bucket\'s part.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertSame('part', \Amp\ByteStream\buffer($storage->getObjectByPath($part->path)));
        }
    }

    private function expectScopedAssemblyFailure(InMemoryBackend $storage, string $bucket, string $key, string $uploadId, string $path): void
    {
        try {
            $storage->assembleMultipartUploadAtPath($bucket, $key, $uploadId, [], $path);
            self::fail('Cross-scope assembly should fail.');
        } catch (\InvalidArgumentException) {
            try {
                $storage->getObjectByPath($path);
                self::fail('Rejected assembly created an object.');
            } catch (\OpsFour\S3Server\Exception\NoSuchKeyException $error) {
                self::assertSame('NoSuchKey', $error->getErrorCode());
            }
        }
    }
}
