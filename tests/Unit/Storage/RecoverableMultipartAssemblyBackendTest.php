<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use League\Flysystem\Filesystem;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RecoverableMultipartAssemblyBackend;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class RecoverableMultipartAssemblyBackendTest extends TestCase
{
    public function test_planned_paths_are_isolated_by_generation_and_attempt_and_used_exactly(): void
    {
        $root = sys_get_temp_dir() . '/s3-planned-assembly-' . bin2hex(random_bytes(8));
        mkdir($root, 0700);
        $backends = [
            new InMemoryBackend(),
            new FlysystemBackend(new Filesystem(new InMemoryFlysystemAdapter()), $root),
            new FilesystemBackend($root),
        ];

        try {
            foreach ($backends as $backend) {
                self::assertInstanceOf(RecoverableMultipartAssemblyBackend::class, $backend);
                $backend->createBucket('bucket');
                $generation = str_repeat('a', 32);
                $attempt = str_repeat('b', 32);
                $paths = $backend->planMultipartCompletionPaths('bucket', 'key', $generation, $attempt);
                self::assertNotSame($paths['assembly'], $paths['final']);
                self::assertNotSame($paths, $backend->planMultipartCompletionPaths('bucket', 'key', str_repeat('c', 32), $attempt));
                self::assertNotSame($paths, $backend->planMultipartCompletionPaths('bucket', 'key', $generation, str_repeat('d', 32)));

                $part = $backend->putPart('bucket', 'key', 'abcdef1234567890', 1, new ReadableBuffer('plaintext'));
                $assembly = $backend->assembleMultipartUploadAtPath('bucket', 'key', 'abcdef1234567890', [[
                    'partNumber' => 1,
                    'etag' => $part->md5Hex,
                    'storagePath' => $part->path,
                ]], $paths['assembly']);
                self::assertSame($paths['assembly'], $assembly->path);
                self::assertSame('plaintext', buffer($backend->getObjectByPath($assembly->path)));

                $final = $backend->putObjectAtPath('bucket', 'key', new ReadableBuffer('ciphertext'), $paths['final']);
                self::assertSame($paths['final'], $final->path);
                self::assertSame('ciphertext', buffer($backend->getObjectByPath($final->path)));
                $backend->deleteObjectByPath($assembly->path, 'bucket');
                self::assertSame('ciphertext', buffer($backend->getObjectByPath($final->path)));
                $backend->deleteObjectByPath($final->path, 'bucket');
            }
        } finally {
            foreach ($backends as $backend) {
                if ($backend instanceof FilesystemBackend) {
                    $backend->shutdown();
                }
            }
            $this->removeDirectory($root);
        }
    }

    public function test_invalid_destination_is_rejected_before_reading_body(): void
    {
        $backend = new InMemoryBackend();
        $body = $this->createMock(ReadableStream::class);
        $body->expects(self::never())->method('read');

        $this->expectException(\InvalidArgumentException::class);
        $backend->putObjectAtPath('bucket', 'key', $body, '../outside');
    }

    private function removeDirectory(string $path): void
    {
        if (! is_dir($path)) {
            return;
        }
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $child = $path . '/' . $entry;
            if (is_dir($child)) {
                $this->removeDirectory($child);
            } else {
                unlink($child);
            }
        }
        rmdir($path);
    }
}
