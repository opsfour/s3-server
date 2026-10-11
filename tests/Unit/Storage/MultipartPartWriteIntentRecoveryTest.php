<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class MultipartPartWriteIntentRecoveryTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-part-intent-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_crash_before_part_commit_and_late_write_are_both_collected(): void
    {
        [$uploadId, $path, $generation] = $this->reservePart('attempt-a');
        self::assertStringContainsString($generation, $path);
        $this->storage->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('ciphertext'), $path);

        $this->forceDue($path);
        self::assertSame(1, $this->collector()->collect());
        $this->assertPathMissing($path);
        self::assertSame(1, $this->intentCount($path));

        // A remote worker finishes after the first sweep. The retained
        // tombstone must remove exactly the same preplanned path again.
        $this->storage->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('late ciphertext'), $path);
        $this->forceDue($path);
        self::assertSame(1, $this->collector()->collect());
        $this->assertPathMissing($path);
        self::assertSame(1, $this->intentCount($path));
    }

    public function test_committed_part_consumes_intent_atomically_and_collector_keeps_it(): void
    {
        [$uploadId, $path] = $this->reservePart('attempt-b');
        $this->storage->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('ciphertext'), $path);
        $this->metadata->transaction(function () use ($uploadId, $path): void {
            $this->metadata->putPart($uploadId, 1, '"' . md5('plain') . '"', 5, $path);
            self::assertTrue($this->metadata->consumeMultipartPartWriteIntent('bucket', $uploadId, $path));
        });

        self::assertSame(0, $this->intentCount($path));
        self::assertSame(0, $this->collector()->collect());
        self::assertSame('ciphertext', \Amp\ByteStream\buffer($this->storage->getObjectByPath($path)));
        self::assertSame($path, $this->metadata->getParts($uploadId)[0]['storage_path']);
    }

    public function test_collector_claim_before_commit_fences_part_metadata(): void
    {
        [$uploadId, $path] = $this->reservePart('attempt-c');
        $this->storage->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('ciphertext'), $path);
        $this->forceDue($path);
        $claim = $this->metadata->dequeueStorageGarbage(1);
        self::assertSame('part_write_intent', $claim[0]['operation']);

        try {
            $this->metadata->transaction(function () use ($uploadId, $path): void {
                $this->metadata->putPart($uploadId, 1, '"' . md5('plain') . '"', 5, $path);
                if (! $this->metadata->consumeMultipartPartWriteIntent('bucket', $uploadId, $path)) {
                    throw new \RuntimeException('Part intent was already claimed.');
                }
            });
            self::fail('Claimed intent committed a part.');
        } catch (\RuntimeException $error) {
            self::assertSame('Part intent was already claimed.', $error->getMessage());
        }
        self::assertSame([], $this->metadata->getParts($uploadId));

        self::assertTrue($this->metadata->retryStorageGarbage(
            $claim[0]['id'],
            'claimed race',
            0,
            $claim[0]['lease_expires_at'],
        ));
        self::assertSame(1, $this->collector()->collect());
        $this->assertPathMissing($path);
    }

    public function test_old_generation_tombstone_cannot_delete_recreated_bucket_object(): void
    {
        [$uploadId, $oldPath, $oldGeneration] = $this->reservePart('attempt-d');
        $this->metadata->deleteMultipartUpload($uploadId);
        $this->metadata->deleteBucket('owner', 'bucket');
        $this->storage->deleteBucket('bucket');
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage->createBucket('bucket');
        $newGeneration = $this->metadata->getBucket('bucket')?->generation;
        self::assertNotSame($oldGeneration, $newGeneration);
        $newObject = $this->storage->putObject('bucket', 'new-key', new ReadableBuffer('new contents'));

        $this->storage->putPartAtPath('bucket', 'key', $uploadId, 1, new ReadableBuffer('late old ciphertext'), $oldPath);
        $this->forceDue($oldPath);
        self::assertSame(1, $this->collector()->collect());
        $this->assertPathMissing($oldPath);
        self::assertSame('new contents', \Amp\ByteStream\buffer($this->storage->getObjectByPath($newObject->path)));
    }

    public function test_owner_recovery_cap_is_shared_across_connections_but_isolates_other_owners(): void
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $pdo->beginTransaction();
        $insert = $pdo->prepare(
            "INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, owner_id, next_attempt_at)
             VALUES ('bucket', 'STANDARD', ?, 'part_write_intent', 'owner', 9999999999)",
        );
        self::assertInstanceOf(\PDOStatement::class, $insert);
        for ($i = 0; $i < MultipartPartWriteIntentStore::MAX_OUTSTANDING_INTENTS - 1; $i++) {
            $insert->execute(['old-orphan-' . $i]);
        }
        $pdo->commit();

        [$uploadId, $path] = $this->reservePart('last-slot');
        self::assertSame(1, $this->intentCount($path));
        $this->metadata->deleteMultipartUpload($uploadId);
        self::assertSame(1, $this->intentCount($path));

        $otherConnection = new SqliteMetadataStore($this->databasePath);
        $otherConnection->initialize();
        $otherUploadId = bin2hex(random_bytes(16));
        $otherConnection->createMultipartUpload($otherUploadId, 'bucket', 'key', 'owner');
        $generation = $otherConnection->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $otherPath = $this->storage->planMultipartPartPath(
            'bucket',
            $generation,
            $otherUploadId,
            1,
            bin2hex(random_bytes(16)),
        );

        try {
            $otherConnection->createMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $otherUploadId, $otherPath);
            self::fail('A second connection exceeded the same owner recovery cap.');
        } catch (SlowDownException) {
            self::assertSame(0, $this->intentCount($otherPath));
        }

        $otherConnection->createBucket('another-owner', 'another-bucket', 'us-east-1');
        $independentUploadId = bin2hex(random_bytes(16));
        $otherConnection->createMultipartUpload($independentUploadId, 'another-bucket', 'key', 'another-owner');
        $otherGeneration = $otherConnection->getBucket('another-bucket')?->generation;
        self::assertNotNull($otherGeneration);
        $independentPath = $this->storage->planMultipartPartPath(
            'another-bucket',
            $otherGeneration,
            $independentUploadId,
            1,
            bin2hex(random_bytes(16)),
        );
        $otherConnection->createMultipartPartWriteIntent(
            'another-bucket',
            $otherGeneration,
            'STANDARD',
            $independentUploadId,
            $independentPath,
        );
        self::assertSame(1, $this->intentCount($independentPath));
    }

    public function test_thirteen_hour_fast_gc_node_cannot_claim_active_part_intent(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        [, $path] = $this->reservePart('clock-skew');
        $process = proc_open(
            [PHP_BINARY, '-d', 'display_errors=stderr', dirname(__DIR__, 2) . '/Support/storage-garbage-clock-skew-worker.php', $this->databasePath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        fclose($pipes[0]);
        $output = stream_get_contents($pipes[1]);
        $error = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        self::assertSame(0, proc_close($process), (string) $error);
        self::assertSame('0', $output);
        self::assertSame(1, $this->intentCount($path));
    }

    public function test_intent_admission_inside_existing_transaction_fails_closed(): void
    {
        [$uploadId, , $generation] = $this->reservePart('nested-transaction');
        $path = $this->storage->planMultipartPartPath('bucket', $generation, $uploadId, 2, bin2hex(random_bytes(16)));
        try {
            $this->metadata->transaction(function () use ($uploadId, $generation, $path): void {
                $this->metadata->createMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);
            });
            self::fail('Nested intent admission used an old transaction snapshot.');
        } catch (\LogicException) {
            self::assertSame(0, $this->intentCount($path));
        }
    }

    public function test_planned_path_cannot_be_reserved_twice_or_reused_after_commit(): void
    {
        [$uploadId, $path, $generation] = $this->reservePart('same-path');

        try {
            $this->metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);
            self::fail('The same physical path received a second recovery intent.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
            self::assertSame(1, $this->intentCount($path));
        }

        $dispatchId = bin2hex(random_bytes(16));
        self::assertFalse($this->metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $path, $dispatchId));
        $this->metadata->putPart($uploadId, 1, '"etag"', 7, $path);
        self::assertTrue($this->metadata->consumeMultipartPartWriteIntent('bucket', $uploadId, $path));
        self::assertSame(0, $this->intentCount($path));

        try {
            $this->metadata->reserveLocalMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);
            self::fail('A committed part path received another recovery intent.');
        } catch (\OpsFour\S3Server\Exception\OperationAbortedException) {
            self::assertSame(0, $this->intentCount($path));
        }
    }

    /** @return array{string, string, string} */
    private function reservePart(string $attempt): array
    {
        $uploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $generation = $this->metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attemptId = substr(hash('sha256', $attempt), 0, 32);
        $path = $this->storage->planMultipartPartPath('bucket', $generation, $uploadId, 1, $attemptId);
        $this->metadata->createMultipartPartWriteIntent('bucket', $generation, 'STANDARD', $uploadId, $path);

        return [$uploadId, $path, $generation];
    }

    private function forceDue(string $path): void
    {
        $statement = (new \PDO('sqlite:' . $this->databasePath))
            ->prepare("UPDATE s3_storage_garbage SET next_attempt_at = 0, status = 'pending' WHERE storage_path = ?");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute([$path]);
    }

    /** @phpstan-impure */
    private function intentCount(string $path): int
    {
        $statement = (new \PDO('sqlite:' . $this->databasePath))
            ->prepare("SELECT COUNT(*) FROM s3_storage_garbage WHERE storage_path = ? AND operation = 'part_write_intent'");
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $statement->execute([$path]);

        return (int) $statement->fetchColumn();
    }

    private function collector(): StorageGarbageCollector
    {
        return new StorageGarbageCollector($this->metadata, StorageTierRegistry::single($this->storage));
    }

    private function assertPathMissing(string $path): void
    {
        try {
            $this->storage->getObjectByPath($path);
            self::fail('Uncommitted multipart part remained readable.');
        } catch (NoSuchKeyException) {
            return;
        }
    }
}
