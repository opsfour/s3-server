<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class LocalMultipartPartFenceTest extends TestCase
{
    public function test_collector_counts_retired_intent_and_completed_garbage_in_one_pass(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-part-count-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $backend = new FilesystemBackend($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $partPath = $backend->planMultipartPartPath(
            'bucket',
            $bucket->generation,
            $uploadId,
            1,
            bin2hex(random_bytes(16)),
        );

        try {
            $metadata->reserveLocalMultipartPartWriteIntent(
                'bucket',
                $bucket->generation,
                'STANDARD',
                $uploadId,
                $partPath,
            );
            $this->forceDue($metadata, $partPath);
            $garbage = $backend->putObject('bucket', 'old', new ReadableBuffer('obsolete'));
            $metadata->enqueueStorageGarbage('bucket', 'STANDARD', $garbage->path);
            $this->forceDue($metadata, $garbage->path);

            self::assertSame(
                2,
                (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(2),
            );
            self::assertSame([], $metadata->dequeueStorageGarbage(10));
        } finally {
            $backend->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_armed_part_is_sealed_and_abort_cannot_remove_seal_or_allow_late_publish(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-part-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $backend = new FilesystemBackend($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $attemptId = bin2hex(random_bytes(16));
        $dispatchId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $path = $backend->planMultipartPartPath('bucket', $bucket->generation, $uploadId, 1, $attemptId);
        try {
            $metadata->reserveLocalMultipartPartWriteIntent('bucket', $bucket->generation, 'STANDARD', $uploadId, $path);
            self::assertTrue($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));
            self::assertFalse($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, bin2hex(random_bytes(16))));
            self::assertFalse($metadata->consumeMultipartPartWriteIntent('bucket', $uploadId, $path));
            $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('uncommitted'), $path);
            $this->forceDue($metadata, $path);
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
            $seal = file_get_contents($path);
            self::assertIsString($seal);
            self::assertStringStartsWith("OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . $attemptId . "\n", $seal);

            $backend->abortMultipartUpload('bucket', 'key', $uploadId);
            self::assertSame($seal, file_get_contents($path));
            try {
                $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('late'), $path);
                self::fail('Late part worker replaced its permanent seal.');
            } catch (InternalErrorException) {
                self::assertSame($seal, self::readFile($path));
            }
            $claim = $metadata->dequeueStorageGarbage(1);
            self::assertSame([], $claim);
        } finally {
            $backend->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_never_dispatched_part_retires_and_legacy_intent_blocks_admission(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $path = '/unused/' . bin2hex(random_bytes(16));
        $metadata->reserveLocalMultipartPartWriteIntent('bucket', $bucket->generation, 'STANDARD', $uploadId, $path);
        $metadata->assertNoLegacyLocalMultipartIntents('STANDARD');
        $this->forceDue($metadata, $path);
        self::assertSame([], $metadata->dequeueStorageGarbage(1));
        self::assertSame(1, $metadata->retireExpiredUndispatchedLocalMultipartPartWriteIntents('STANDARD', 1));
        self::assertFalse($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, bin2hex(random_bytes(16))));

        $metadata->createMultipartPartWriteIntent('bucket', $bucket->generation, 'STANDARD', $uploadId, $path);
        $pdo = $this->pdo($metadata);
        $legacy = $pdo->query("SELECT dispatch_id FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $legacy);
        self::assertSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $legacy->fetchColumn());
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Legacy multipart part intent');
        $metadata->assertNoLegacyLocalMultipartIntents('STANDARD');
    }

    public function test_terminal_part_retirement_requires_exact_dispatch_and_no_part_reference(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $dispatchId = bin2hex(random_bytes(16));
        $path = '/unused/' . bin2hex(random_bytes(16));
        $metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);
        self::assertTrue($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));
        self::assertFalse($metadata->retireTerminalLocalMultipartPartWriteIntent('bucket', $uploadId, $path, bin2hex(random_bytes(16))));
        $metadata->putPart($uploadId, 1, '"etag"', 4, $path);
        self::assertFalse($metadata->retireTerminalLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));

        $otherPath = '/unused/' . bin2hex(random_bytes(16));
        $metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $otherPath);
        self::assertTrue($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $otherPath, $dispatchId));
        self::assertTrue($metadata->retireTerminalLocalMultipartPartWriteIntent('bucket', $uploadId, $otherPath, $dispatchId));
        self::assertFalse($metadata->retireTerminalLocalMultipartPartWriteIntent('bucket', $uploadId, $otherPath, $dispatchId));
    }

    public function test_replayed_intent_cannot_seal_a_committed_part(): void
    {
        $root = sys_get_temp_dir() . '/s3-part-replay-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $backend = new FilesystemBackend($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $uploadId = bin2hex(random_bytes(16));
        $dispatchId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $path = $backend->planMultipartPartPath('bucket', $generation, $uploadId, 1, bin2hex(random_bytes(16)));
        try {
            $metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);
            self::assertTrue($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));
            $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('committed part'), $path);
            $metadata->transaction(function () use ($metadata, $uploadId, $path, $dispatchId): void {
                $metadata->putPart($uploadId, 1, '"etag"', 14, $path);
                self::assertTrue($metadata->consumeLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));
            });

            $this->insertReplay($metadata, $path, $uploadId, $dispatchId);
            self::assertSame(0, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
            self::assertSame('committed part', file_get_contents($path));
            self::assertSame($path, $metadata->getParts($uploadId)[0]['storage_path']);
            $statement = $this->pdo($metadata)->query("SELECT status, attempts FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $statement);
            self::assertSame(['status' => 'pending', 'attempts' => 1], $statement->fetch(\PDO::FETCH_ASSOC));
        } finally {
            $backend->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_duplicate_uncommitted_intent_cannot_seal_the_other_attempt(): void
    {
        $root = sys_get_temp_dir() . '/s3-part-duplicate-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $backend = new FilesystemBackend($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $uploadId = bin2hex(random_bytes(16));
        $dispatchId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $path = $backend->planMultipartPartPath('bucket', $generation, $uploadId, 1, bin2hex(random_bytes(16)));
        try {
            $metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);
            self::assertTrue($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));
            $backend->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('still writable'), $path);
            $this->insertReplay($metadata, $path, $uploadId, bin2hex(random_bytes(16)));
            $this->forceDue($metadata, $path);

            self::assertSame(0, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
            self::assertSame('still writable', file_get_contents($path));
            self::assertSame([], $metadata->getParts($uploadId));
        } finally {
            $backend->shutdown();
            $this->removeDirectory($root);
        }
    }

    /** @phpstan-impure */
    private static function readFile(string $path): string
    {
        $contents = file_get_contents($path);
        if ($contents === false) {
            throw new \RuntimeException("Unable to read test file {$path}.");
        }

        return $contents;
    }

    private function insertReplay(SqliteMetadataStore $metadata, string $path, string $uploadId, string $dispatchId): void
    {
        $statement = $this->pdo($metadata)->prepare("INSERT INTO s3_storage_garbage
            (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
            VALUES ('bucket', 'STANDARD', ?, 'part_write_intent', 'key', ?, 'owner', 0, ?)");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute([$path, $uploadId, $dispatchId]);
    }

    private function forceDue(SqliteMetadataStore $metadata, string $path): void
    {
        $statement = $this->pdo($metadata)->prepare('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = ?');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute([$path]);
    }

    private function pdo(SqliteMetadataStore $metadata): \PDO
    {
        $method = new \ReflectionMethod($metadata, 'connection');

        return $method->invoke($metadata);
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
