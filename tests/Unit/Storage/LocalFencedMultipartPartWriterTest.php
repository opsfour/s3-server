<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalMultipartPartWriteFence;
use OpsFour\S3Server\Storage\LocalMultipartPartWriteOutcome;
use OpsFour\S3Server\Storage\StoragePath;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\LocalMultipartLinkAckFaultIo;
use OpsFour\S3Server\Tests\Support\LocalMultipartSyncCorruptIo;
use OpsFour\S3Server\Tests\Support\LocalObjectWriteFaultIo;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class LocalFencedMultipartPartWriterTest extends TestCase
{
    public function test_one_shot_worker_publishes_exact_part_and_sql_collector_seals_uncommitted_path(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $root . '/.tmp/object-writes';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700, true));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $backend = new FilesystemBackend($root);
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata);
        try {
            $outcome = $writer->publish(
                'bucket',
                $bucket->generation,
                $uploadId,
                1,
                new ReadableBuffer('part-body'),
                knownPhysicalSize: 9,
            );
            self::assertSame(LocalMultipartPartWriteOutcome::COMPLETED, $outcome->state, $outcome->error?->getMessage() ?? '');
            self::assertNotNull($outcome->dispatchId);
            self::assertNull($outcome->error);
            self::assertNotNull($outcome->result);
            self::assertSame('part-body', file_get_contents($outcome->path));
            self::assertSame(md5('part-body'), $outcome->result->md5Hex);
            self::assertSame(base64_encode(hash('sha256', 'part-body', true)), $outcome->result->sha256Base64);
            self::assertFalse($metadata->armLocalMultipartPartWriteIntent('bucket', $uploadId, $outcome->path, bin2hex(random_bytes(16))));
            $statement = $this->pdo($metadata)->prepare('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = ?');
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $statement->execute([$outcome->path]);
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
            self::assertStringStartsWith("OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . substr(basename($outcome->path), -32) . "\n", (string) file_get_contents($outcome->path));
        } finally {
            $writer->shutdown();
            $backend->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_invalid_upload_never_dispatches_or_publishes(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata);
        try {
            $outcome = $writer->publish(
                'bucket',
                $bucket->generation,
                bin2hex(random_bytes(16)),
                1,
                new ReadableBuffer('part-body'),
            );
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertNull($outcome->dispatchId);
            self::assertNotNull($outcome->error);
            self::assertFileDoesNotExist($outcome->path);
            self::assertSame([], array_diff(scandir($scratch) ?: [], ['.', '..', '.opsfour-s3-storage-budget']));
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_known_size_reserves_scratch_and_publish_capacity_before_reading(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-capacity-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata, budgetBytes: 10);
        $body = $this->createMock(\Amp\ByteStream\ReadableStream::class);
        $body->expects(self::never())->method('read');

        try {
            $outcome = $writer->publish(
                'bucket',
                $bucket->generation,
                bin2hex(random_bytes(16)),
                1,
                $body,
                knownPhysicalSize: 6,
            );
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertNotNull($outcome->error);
            self::assertStringContainsString('Storage staging budget is full', $outcome->error->getMessage());
            self::assertFileDoesNotExist($outcome->path);
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_logical_validation_rejects_before_sql_arm_and_releases_scratch_budget(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata, budgetBytes: 16);
        try {
            $seen = false;
            $rejected = $writer->publish(
                'bucket',
                $bucket->generation,
                $uploadId,
                1,
                new ReadableBuffer('part-body'),
                validateBeforeArm: static function (\OpsFour\S3Server\Checksum\ChecksumResult $checksums) use (&$seen): void {
                    $seen = true;
                    self::assertSame(9, $checksums->size);
                    self::assertSame(md5('part-body'), $checksums->md5Hex);
                    self::assertSame(base64_encode(hash('crc32b', 'part-body', true)), $checksums->crc32Base64());
                    \OpsFour\S3Server\Handler\Multipart\MultipartPartLogicalValidator::assertValid(
                        ['sha256' => base64_encode(str_repeat("\0", 32))],
                        $checksums,
                        9,
                    );
                },
            );
            self::assertTrue($seen);
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $rejected->state);
            self::assertInstanceOf(\OpsFour\S3Server\Exception\BadDigestException::class, $rejected->error);
            self::assertNull($rejected->dispatchId);
            self::assertFileDoesNotExist($rejected->path);
            $query = $this->pdo($metadata)->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $query);
            self::assertSame(0, (int) $query->fetchColumn());

            $wrongLength = $writer->publish(
                'bucket',
                $bucket->generation,
                $uploadId,
                1,
                new ReadableBuffer('part-body'),
                validateBeforeArm: static function (\OpsFour\S3Server\Checksum\ChecksumResult $checksums): void {
                    \OpsFour\S3Server\Handler\Multipart\MultipartPartLogicalValidator::assertValid([], $checksums, 10);
                },
            );
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $wrongLength->state);
            self::assertInstanceOf(\OpsFour\S3Server\Exception\IncompleteBodyException::class, $wrongLength->error);
            self::assertNull($wrongLength->dispatchId);
            self::assertFileDoesNotExist($wrongLength->path);
            $query = $this->pdo($metadata)->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $query);
            self::assertSame(0, (int) $query->fetchColumn());

            $accepted = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('accepted'));
            self::assertSame(
                LocalMultipartPartWriteOutcome::COMPLETED,
                $accepted->state,
                $accepted->error?->getMessage() ?? 'worker did not complete',
            );
            self::assertSame('accepted', file_get_contents($accepted->path));
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_cancellation_after_arm_survives_cleanup_budget_failure_and_retires_dispatch(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $cancellation = new DeferredCancellation();
        $cancellationReason = new \RuntimeException('request cancelled');
        $intents = $this->createMock(FencedMultipartPartWriteIntentStore::class);
        $intents->method('reserveLocalMultipartPartWriteIntent')->willReturnCallback($metadata->reserveLocalMultipartPartWriteIntent(...));
        $intents->method('armLocalMultipartPartWriteIntent')->willReturnCallback(
            static function (string $bucket, string $upload, string $path, string $dispatch) use ($metadata, $cancellation, $cancellationReason, $scratch): bool {
                $armed = $metadata->armLocalMultipartPartWriteIntent($bucket, $upload, $path, $dispatch);
                $states = glob($scratch . '/.opsfour-s3-storage-budget/state-*') ?: [];
                if (count($states) !== 1 || file_put_contents($states[0], '{corrupt') === false) {
                    throw new \RuntimeException('Could not corrupt the staging budget fixture.');
                }
                $cancellation->cancel($cancellationReason);
                return $armed;
            },
        );
        $intents->method('retireTerminalLocalMultipartPartWriteIntent')->willReturnCallback($metadata->retireTerminalLocalMultipartPartWriteIntent(...));
        $intents->method('retireUndispatchedLocalMultipartPartWriteIntent')->willReturnCallback($metadata->retireUndispatchedLocalMultipartPartWriteIntent(...));
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $intents);
        try {
            $outcome = $writer->publish('bucket', $generation, $uploadId, 1, new ReadableBuffer('payload'), $cancellation->getCancellation());
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertInstanceOf(\Amp\CancelledException::class, $outcome->error);
            self::assertSame($cancellationReason, $outcome->error->getPrevious());
            self::assertFileDoesNotExist($outcome->path);
            $query = $this->pdo($metadata)->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $query);
            self::assertSame(0, (int) $query->fetchColumn());
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_validation_failure_remains_before_dispatch_when_scratch_budget_cleanup_fails(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $worker = $this->createMock(Worker::class);
        $worker->expects(self::never())->method('submit');
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('getWorker')->willReturn($worker);
        $primary = new \OpsFour\S3Server\Exception\BadDigestException('primary checksum failure');
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata, writePool: $pool);
        try {
            $outcome = $writer->publish(
                'bucket',
                $generation,
                $uploadId,
                1,
                new ReadableBuffer('payload'),
                validateBeforeArm: static function () use ($scratch, $primary): never {
                    $states = glob($scratch . '/.opsfour-s3-storage-budget/state-*') ?: [];
                    self::assertCount(1, $states);
                    self::assertNotFalse(file_put_contents($states[0], '{corrupt'));
                    throw $primary;
                },
            );
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertSame($primary, $outcome->error);
            self::assertNull($outcome->dispatchId);
            self::assertNull($outcome->result);
            self::assertFileDoesNotExist($outcome->path);
            $query = $this->pdo($metadata)->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $query);
            self::assertSame(0, (int) $query->fetchColumn());

            $later = $writer->publish('bucket', $generation, $uploadId, 1, new ReadableBuffer('later'));
            self::assertSame(LocalMultipartPartWriteOutcome::BEFORE_DISPATCH, $later->state, $later->error?->getMessage() ?? '');
            self::assertInstanceOf(SlowDownException::class, $later->error);
            self::assertStringContainsString('requires operator attention', $later->error->getMessage());
            self::assertFileDoesNotExist($later->path);
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_lost_link_ack_retains_sql_intent_until_collector_seals_visible_path(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $backend = new FilesystemBackend($root);
        $writer = new LocalFencedMultipartPartWriter(
            $root,
            $scratch,
            'STANDARD',
            $metadata,
            workerIo: new LocalMultipartLinkAckFaultIo(),
        );
        try {
            $outcome = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('late-part'));
            self::assertSame(LocalMultipartPartWriteOutcome::UNCERTAIN, $outcome->state);
            self::assertNotNull($outcome->dispatchId);
            self::assertNull($outcome->result);
            self::assertSame('late-part', file_get_contents($outcome->path));
            $query = $this->pdo($metadata)->query("SELECT dispatch_id FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $query);
            $row = $query->fetchColumn();
            self::assertSame($outcome->dispatchId, $row);

            $statement = $this->pdo($metadata)->prepare('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = ?');
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $statement->execute([$outcome->path]);
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
            $seal = file_get_contents($outcome->path);
            self::assertIsString($seal);
            self::assertStringStartsWith("OPSFOUR-LOCAL-MULTIPART-SEAL-V1\n" . substr(basename($outcome->path), -32) . "\n", $seal);
            self::assertSame($seal, file_get_contents($outcome->path));
        } finally {
            $writer->shutdown();
            $backend->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_same_length_corruption_of_synchronized_temp_never_publishes_part(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $writer = new LocalFencedMultipartPartWriter(
            $root,
            $scratch,
            'STANDARD',
            $metadata,
            workerIo: new LocalMultipartSyncCorruptIo(),
        );
        try {
            $outcome = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('sensitive'));
            self::assertSame(LocalMultipartPartWriteOutcome::COMPLETED, $outcome->state);
            self::assertNull($outcome->result);
            self::assertNotNull($outcome->dispatchId);
            self::assertNotNull($outcome->error);
            self::assertStringContainsString('physical integrity verification', $outcome->error->getMessage());
            self::assertFileDoesNotExist($outcome->path);
            self::assertSame([], $metadata->getParts($uploadId));
            $query = $this->pdo($metadata)->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $query);
            self::assertSame(0, (int) $query->fetchColumn());
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_terminal_pre_link_sync_failures_retire_exact_dispatch_without_using_owner_capacity(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $fault = new LocalObjectWriteFaultIo();
        $fault->failFileSync = true;
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata, workerIo: $fault);
        try {
            for ($i = 0; $i < 8; $i++) {
                $outcome = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('payload'));
                self::assertSame(LocalMultipartPartWriteOutcome::COMPLETED, $outcome->state);
                self::assertNotNull($outcome->dispatchId);
                self::assertNull($outcome->result);
                self::assertFileDoesNotExist($outcome->path);
                $query = $this->pdo($metadata)->query("SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
                self::assertInstanceOf(\PDOStatement::class, $query);
                self::assertSame(0, (int) $query->fetchColumn());
            }
            self::assertSame([], $metadata->getParts($uploadId));
        } finally {
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_stage_integrity_failure_never_links_a_part_path(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        $scratch = realpath($scratch);
        self::assertIsString($root);
        self::assertIsString($scratch);
        $attemptId = bin2hex(random_bytes(16));
        $source = $scratch . '/s3fw-part-' . $attemptId . bin2hex(random_bytes(16));
        $uploadId = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $target = StoragePath::forPlannedPart($root, 'bucket', $generation, $uploadId, 1, $attemptId);
        self::assertSame(7, file_put_contents($source, 'changed'));
        self::assertTrue(chmod($source, 0600));
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('integrity verification');
            (new LocalMultipartPartWriteFence($root))->publish(
                $source,
                $target,
                'bucket',
                $generation,
                $uploadId,
                1,
                $attemptId,
                7,
                hash('sha256', 'original', true),
            );
        } finally {
            self::assertFileDoesNotExist($target);
            $this->removeDirectory($directory);
        }
    }

    public function test_repeated_calls_cannot_reuse_a_committed_attempt_path(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        self::assertIsString($root);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $backend = new FilesystemBackend($root);
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata);
        try {
            $first = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('committed'));
            self::assertSame(LocalMultipartPartWriteOutcome::COMPLETED, $first->state);
            self::assertNotNull($first->result);
            self::assertNotNull($first->dispatchId);
            $metadata->transaction(function () use ($metadata, $uploadId, $first): void {
                $metadata->putPart($uploadId, 1, '"' . $first->result->md5Hex . '"', $first->result->size, $first->path);
                self::assertTrue($metadata->consumeLocalMultipartPartWriteIntent('bucket', $uploadId, $first->path, $first->dispatchId));
            });
            $second = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('uncommitted'));
            self::assertSame(LocalMultipartPartWriteOutcome::COMPLETED, $second->state);
            self::assertNotSame($first->path, $second->path);
            $statement = $this->pdo($metadata)->prepare('UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = ?');
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $statement->execute([$second->path]);
            self::assertSame(1, (new StorageGarbageCollector($metadata, StorageTierRegistry::single($backend)))->collect(1));
            self::assertSame('committed', file_get_contents($first->path));
            self::assertSame($first->path, $metadata->getParts($uploadId)[0]['storage_path']);
        } finally {
            $writer->shutdown();
            $backend->shutdown();
            $this->removeDirectory($directory);
        }
    }

    public function test_worker_rejects_preexisting_public_temp_or_part_directories(): void
    {
        foreach (['.tmp', '.parts', '.parts/upload'] as $unsafeDirectory) {
            $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
            $root = $directory . '/storage';
            $scratch = $directory . '/scratch';
            self::assertTrue(mkdir($directory, 0700));
            self::assertTrue(mkdir($root, 0700));
            self::assertTrue(mkdir($scratch, 0700));
            $root = realpath($root);
            $scratch = realpath($scratch);
            self::assertIsString($root);
            self::assertIsString($scratch);
            $uploadId = bin2hex(random_bytes(16));
            $attemptId = bin2hex(random_bytes(16));
            $generation = bin2hex(random_bytes(16));
            $unsafePath = $unsafeDirectory === '.parts/upload' ? $root . '/.parts/' . $uploadId : $root . '/' . $unsafeDirectory;
            self::assertTrue(mkdir($unsafePath, 0700, true));
            self::assertTrue(chmod($unsafePath, 0777));
            $source = $scratch . '/s3fw-part-' . $attemptId . bin2hex(random_bytes(16));
            self::assertSame(4, file_put_contents($source, 'data'));
            self::assertTrue(chmod($source, 0600));
            $target = StoragePath::forPlannedPart($root, 'bucket', $generation, $uploadId, 1, $attemptId);
            try {
                try {
                    (new LocalMultipartPartWriteFence($root))->publish(
                        $source,
                        $target,
                        'bucket',
                        $generation,
                        $uploadId,
                        1,
                        $attemptId,
                        4,
                        hash('sha256', 'data', true),
                    );
                    self::fail('Worker accepted a public storage directory: ' . $unsafeDirectory);
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('private and process-owned', $error->getMessage());
                }
                self::assertFileDoesNotExist($target);
            } finally {
                $this->removeDirectory($directory);
            }
        }
    }

    public function test_permissive_umask_still_publishes_private_inode_and_directories(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        $scratch = realpath($scratch);
        self::assertIsString($root);
        self::assertIsString($scratch);
        $uploadId = bin2hex(random_bytes(16));
        $attemptId = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $source = $scratch . '/s3fw-part-' . $attemptId . bin2hex(random_bytes(16));
        self::assertSame(4, file_put_contents($source, 'data'));
        self::assertTrue(chmod($source, 0600));
        $target = StoragePath::forPlannedPart($root, 'bucket', $generation, $uploadId, 1, $attemptId);
        $umask = umask(0000);
        try {
            (new LocalMultipartPartWriteFence($root))->publish(
                $source,
                $target,
                'bucket',
                $generation,
                $uploadId,
                1,
                $attemptId,
                4,
                hash('sha256', 'data', true),
            );
            self::assertSame(0, fileperms($target) & 0077);
            self::assertSame(0, fileperms($root . '/.tmp') & 0077);
            self::assertSame(0, fileperms($root . '/.parts') & 0077);
            self::assertSame(0, fileperms(dirname($target)) & 0077);
        } finally {
            umask($umask);
            $this->removeDirectory($directory);
        }
    }

    public function test_ipc_failure_retains_stage_until_worker_shutdown_is_proven(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-worker-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($directory, 0700));
        self::assertTrue(mkdir($root, 0700));
        self::assertTrue(mkdir($scratch, 0700));
        $root = realpath($root);
        $scratch = realpath($scratch);
        self::assertIsString($root);
        self::assertIsString($scratch);
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');

        $channel = $this->createMock(Channel::class);
        $worker = $this->createMock(Worker::class);
        $worker->method('submit')->willReturnCallback(
            static fn($task): Execution => new Execution($task, $channel, Future::error(new \RuntimeException('IPC failed'))),
        );
        $pool = $this->createMock(WorkerPool::class);
        $pool->method('getWorker')->willReturn($worker);
        $shutdownCount = 0;
        $pool->method('shutdown')->willReturnCallback(static function () use (&$shutdownCount): void {
            if (++$shutdownCount === 1) {
                throw new \RuntimeException('Worker exit unproven');
            }
        });
        $writer = new LocalFencedMultipartPartWriter($root, $scratch, 'STANDARD', $metadata, writePool: $pool);
        try {
            $outcome = $writer->publish('bucket', $bucket->generation, $uploadId, 1, new ReadableBuffer('retained'));
            self::assertSame(LocalMultipartPartWriteOutcome::UNCERTAIN, $outcome->state);
            self::assertNotNull($outcome->dispatchId);
            $stages = glob($scratch . '/s3fw-part-*');
            self::assertIsArray($stages);
            self::assertCount(1, $stages);
            self::assertSame('retained', file_get_contents($stages[0]));
            try {
                $writer->shutdown();
                self::fail('An unproven worker exit released its stage.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('staged bytes remain reserved', $error->getMessage());
            }
            self::assertFileExists($stages[0]);
            $writer->shutdown();
            self::assertFileDoesNotExist($stages[0]);
        } finally {
            $this->removeDirectory($directory);
        }
    }

    public function test_worker_timeout_returns_uncertain_and_retains_stage_until_late_completion(): void
    {
        $directory = sys_get_temp_dir() . '/s3-part-timeout-' . bin2hex(random_bytes(8));
        $root = $directory . '/storage';
        $scratch = $directory . '/scratch';
        self::assertTrue(mkdir($root, 0700, true));
        self::assertTrue(mkdir($scratch, 0700));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $bucket = $metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $uploadId = bin2hex(random_bytes(16));
        $metadata->createMultipartUpload($uploadId, 'bucket', 'key', 'owner');
        $entered = $directory . '/link-entered';
        $release = $directory . '/link-release';
        $io = new LocalObjectWriteFaultIo();
        $io->linkEnteredPath = $entered;
        $io->releaseLinkPath = $release;
        $writer = new LocalFencedMultipartPartWriter(
            $root,
            $scratch,
            'STANDARD',
            $metadata,
            workerIo: $io,
            workerTimeoutSeconds: 0.05,
        );

        try {
            $pending = async(fn(): LocalMultipartPartWriteOutcome => $writer->publish(
                'bucket',
                $bucket->generation,
                $uploadId,
                1,
                new ReadableBuffer('late part'),
            ));
            $deadline = microtime(true) + 5.0;
            while (! file_exists($entered) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($entered);
            $outcome = $pending->await();
            self::assertSame(LocalMultipartPartWriteOutcome::UNCERTAIN, $outcome->state);
            self::assertInstanceOf(SlowDownException::class, $outcome->error);
            self::assertNotNull($outcome->dispatchId);
            self::assertCount(1, glob($scratch . '/s3fw-part-*') ?: []);

            self::assertSame(1, file_put_contents($release, '1'));
            $deadline = microtime(true) + 5.0;
            while (glob($scratch . '/s3fw-part-*') !== [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertSame([], glob($scratch . '/s3fw-part-*'));
            self::assertFileExists($outcome->path);
        } finally {
            file_put_contents($release, '1');
            $writer->shutdown();
            $this->removeDirectory($directory);
        }
    }

    private function pdo(SqliteMetadataStore $metadata): \PDO
    {
        return (new \ReflectionMethod($metadata, 'connection'))->invoke($metadata);
    }

    private function removeDirectory(string $directory): void
    {
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
