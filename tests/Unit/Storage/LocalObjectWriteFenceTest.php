<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use PHPUnit\Framework\TestCase;

final class LocalObjectWriteFenceTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s3-local-fence-' . bin2hex(random_bytes(8));
        mkdir($this->root, 0700);
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_create_only_write_syncs_and_returns_exact_planned_path(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);

        $result = $fence->write($intent, 'key', new ReadableBuffer('payload'));

        self::assertSame($intent->storagePath, $result->path);
        self::assertSame(7, $result->size);
        self::assertSame(md5('payload'), $result->md5Hex);
        self::assertSame('payload', file_get_contents($intent->storagePath));
        self::assertSame([], glob(realpath($this->root) . '/.tmp/*'));
    }

    public function test_existing_target_is_never_overwritten(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);
        $fence->write($intent, 'key', new ReadableBuffer('first'));

        try {
            $fence->write($intent, 'key', new ReadableBuffer('second'));
            self::fail('A second create-only publication must fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('Local create-only object publication is uncertain.', $e->getMessage());
        }

        self::assertSame('first', file_get_contents($intent->storagePath));
    }

    public function test_ambiguous_link_result_retains_final_path_for_seal(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $io->reportLinkFailureAfterSuccess = true;
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('publication is uncertain');
        try {
            $fence->write($intent, 'key', new ReadableBuffer('possibly published'));
        } finally {
            self::assertSame('possibly published', file_get_contents($intent->storagePath));
            (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
            self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
        }
    }

    public function test_seal_replaces_data_and_blocks_late_create_only_link(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);
        $fence->write($intent, 'key', new ReadableBuffer('abandoned'));
        $fence->seal($this->claimed($intent));
        $marker = file_get_contents($intent->storagePath);

        self::assertIsString($marker);
        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', $marker);

        try {
            $fence->write($intent, 'key', new ReadableBuffer('late worker'));
            self::fail('A late writer must not replace the seal.');
        } catch (\RuntimeException $e) {
            self::assertSame('Local create-only object publication is uncertain.', $e->getMessage());
        }
        self::assertSame($marker, file_get_contents($intent->storagePath));
    }

    public function test_ambiguous_rename_result_is_verified_by_unique_seal_marker(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $io->reportRenameFailureAfterSuccess = true;
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);
        $fence->write($intent, 'key', new ReadableBuffer('abandoned'));

        $fence->seal($this->claimed($intent));

        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
    }

    public function test_failed_rename_does_not_report_a_seal_or_remove_existing_data(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);
        $fence->write($intent, 'key', new ReadableBuffer('abandoned'));
        $io->rejectRename = true;

        try {
            $fence->seal($this->claimed($intent));
            self::fail('An unverified seal must not be accepted.');
        } catch (\RuntimeException $e) {
            self::assertSame('Local object seal publication is uncertain.', $e->getMessage());
        }

        self::assertSame('abandoned', file_get_contents($intent->storagePath));
        self::assertSame([], glob(dirname($intent->storagePath) . '/.seal-*'));
    }

    public function test_data_sync_failure_prevents_publication(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $io->failFileSync = true;
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);

        try {
            $fence->write($intent, 'key', new ReadableBuffer('unflushed'));
            self::fail('Unsynchronized bytes must not be published.');
        } catch (\RuntimeException $e) {
            self::assertSame('Failed to synchronize local object data.', $e->getMessage());
        }

        self::assertSame(0, $io->linkCalls);
        self::assertFileDoesNotExist($intent->storagePath);
    }

    public function test_equal_length_corruption_of_synced_temp_file_prevents_publication(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $io->corruptFileOnSync = true;
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);

        try {
            $fence->write($intent, 'key', new ReadableBuffer('payload'));
            self::fail('Corrupted physical bytes must not be published.');
        } catch (\RuntimeException $error) {
            self::assertSame('Local object staging file differs from input.', $error->getMessage());
        }

        self::assertSame(0, $io->linkCalls);
        self::assertFileDoesNotExist($intent->storagePath);
    }

    public function test_temp_inode_remains_locked_against_stale_cleanup_until_link(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);

        $fence->write($intent, 'key', new ReadableBuffer('payload'));

        self::assertFalse($io->sourceExclusivelyLockableDuringLink);
        self::assertSame('payload', file_get_contents($intent->storagePath));
    }

    public function test_directory_sync_failure_after_link_retains_path_for_seal(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);
        $io->failDirectory = dirname($intent->storagePath);

        try {
            $fence->write($intent, 'key', new ReadableBuffer('unconfirmed'));
            self::fail('A failed directory sync must reject publication.');
        } catch (\RuntimeException $e) {
            self::assertSame('Failed to synchronize local object directory.', $e->getMessage());
        }

        self::assertSame('unconfirmed', file_get_contents($intent->storagePath));
        (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
    }

    public function test_failed_seal_directory_sync_can_be_retried(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);
        $fence->write($intent, 'key', new ReadableBuffer('abandoned'));
        $io->failDirectory = dirname($intent->storagePath);

        try {
            $fence->seal($this->claimed($intent));
            self::fail('An unsynchronized seal must not be acknowledged.');
        } catch (\RuntimeException $e) {
            self::assertSame('Failed to synchronize local object directory.', $e->getMessage());
        }

        (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
    }

    public function test_ambiguous_rename_followed_by_directory_sync_failure_remains_retryable(): void
    {
        $io = new FaultLocalObjectWriteIo();
        $io->reportRenameFailureAfterSuccess = true;
        $fence = new LocalObjectWriteFence($this->root, $io);
        $intent = $this->intent($fence);
        $fence->write($intent, 'key', new ReadableBuffer('abandoned'));
        $io->failDirectory = dirname($intent->storagePath);

        try {
            $fence->seal($this->claimed($intent));
            self::fail('A failed directory sync must keep the claim unresolved.');
        } catch (\RuntimeException $e) {
            self::assertSame('Failed to synchronize local object directory.', $e->getMessage());
        }

        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
        (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
    }

    public function test_target_symlink_is_never_followed_by_write_or_seal(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);
        mkdir(dirname($intent->storagePath), 0700, true);
        $outside = dirname($this->root) . '/s3-local-outside-' . bin2hex(random_bytes(8));
        file_put_contents($outside, 'outside');
        symlink($outside, $intent->storagePath);

        try {
            try {
                $fence->write($intent, 'key', new ReadableBuffer('wrong'));
                self::fail('A symlink target must block create-only link.');
            } catch (\RuntimeException $e) {
                self::assertSame('Local create-only object publication is uncertain.', $e->getMessage());
            }
            self::assertSame('outside', file_get_contents($outside));

            $fence->seal($this->claimed($intent));
            self::assertFalse(is_link($intent->storagePath));
            self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($intent->storagePath));
            self::assertSame('outside', file_get_contents($outside));
        } finally {
            unlink($outside);
        }
    }

    public function test_symlinked_ancestor_is_rejected_before_creating_directories_outside_root(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);
        $outside = dirname($this->root) . '/s3-local-outside-dir-' . bin2hex(random_bytes(8));
        mkdir($outside, 0700);
        symlink($outside, realpath($this->root) . '/bucket');

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('escapes its storage root');
            $fence->write($intent, 'key', new ReadableBuffer('wrong'));
        } finally {
            self::assertSame(['.', '..'], scandir($outside));
            rmdir($outside);
        }
    }

    public function test_wrong_planned_path_and_unclaimed_seal_are_rejected(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);

        try {
            $fence->write($intent, 'different-key', new ReadableBuffer('wrong'));
            self::fail('Wrong object keys must fail before I/O.');
        } catch (\InvalidArgumentException) {
            self::assertFileDoesNotExist($intent->storagePath);
        }

        $this->expectException(\InvalidArgumentException::class);
        $fence->seal($intent);
    }

    public function test_seal_rejects_mismatched_scope_and_path_traversal_without_original_key(): void
    {
        $fence = new LocalObjectWriteFence($this->root);
        $intent = $this->intent($fence);
        $claimed = $this->claimed($intent);
        $otherGeneration = str_repeat($intent->bucketGeneration[0] === 'f' ? 'e' : 'f', 32);
        $otherAttempt = str_repeat($intent->attemptId[0] === 'f' ? 'e' : 'f', 32);
        $root = realpath($this->root);
        self::assertIsString($root);

        $invalid = [
            ['bucket' => 'other', 'generation' => $claimed->bucketGeneration, 'attempt' => $claimed->attemptId, 'path' => $claimed->storagePath],
            ['bucket' => $claimed->bucket, 'generation' => $otherGeneration, 'attempt' => $claimed->attemptId, 'path' => $claimed->storagePath],
            ['bucket' => $claimed->bucket, 'generation' => $claimed->bucketGeneration, 'attempt' => $otherAttempt, 'path' => $claimed->storagePath],
            ['bucket' => $claimed->bucket, 'generation' => $claimed->bucketGeneration, 'attempt' => $claimed->attemptId, 'path' => $root . '/bucket/aa/bb/../' . $claimed->bucketGeneration . '-' . $claimed->attemptId],
            ['bucket' => $claimed->bucket, 'generation' => $claimed->bucketGeneration, 'attempt' => $claimed->attemptId, 'path' => $root . '/bucket/gg/bb/' . $claimed->bucketGeneration . '-' . $claimed->attemptId],
            ['bucket' => $claimed->bucket, 'generation' => $claimed->bucketGeneration, 'attempt' => $claimed->attemptId, 'path' => $root . '/bucket/aa/bb/' . $claimed->bucketGeneration . '-' . $claimed->attemptId . '/extra'],
        ];

        foreach ($invalid as $case) {
            $bad = new ObjectWriteIntent(
                attemptId: $case['attempt'],
                bucket: $case['bucket'],
                bucketGeneration: $case['generation'],
                ownerId: $claimed->ownerId,
                storageTier: $claimed->storageTier,
                storagePath: $case['path'],
                purpose: $claimed->purpose,
                state: 'reconciling',
                leaseUntil: $claimed->leaseUntil,
                claimGeneration: $claimed->claimGeneration,
            );
            try {
                $fence->seal($bad);
                self::fail('The claimed scope must match the exact planned path.');
            } catch (\InvalidArgumentException) {
                self::assertFileDoesNotExist($case['path']);
            }
        }
    }

    private function intent(LocalObjectWriteFence $fence): ObjectWriteIntent
    {
        $attempt = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));

        return new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            storagePath: $fence->planPath('bucket', 'key', $generation, $attempt),
            purpose: 'put',
        );
    }

    private function claimed(ObjectWriteIntent $intent): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: $intent->attemptId,
            bucket: $intent->bucket,
            bucketGeneration: $intent->bucketGeneration,
            ownerId: $intent->ownerId,
            storageTier: $intent->storageTier,
            storagePath: $intent->storagePath,
            purpose: $intent->purpose,
            state: 'reconciling',
            leaseUntil: microtime(true) + 300,
            claimGeneration: 1,
        );
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

final class FaultLocalObjectWriteIo implements LocalObjectWriteIo
{
    public bool $reportLinkFailureAfterSuccess = false;

    public bool $reportRenameFailureAfterSuccess = false;

    public bool $rejectRename = false;

    public bool $failFileSync = false;

    public bool $corruptFileOnSync = false;

    public ?string $failDirectory = null;

    public int $linkCalls = 0;

    public ?bool $sourceExclusivelyLockableDuringLink = null;

    private NativeLocalObjectWriteIo $native;

    public function __construct()
    {
        $this->native = new NativeLocalObjectWriteIo();
    }

    public function syncFile($file): bool
    {
        if ($this->corruptFileOnSync) {
            if (! rewind($file) || fwrite($file, 'X') !== 1) {
                return false;
            }
        }
        return ! $this->failFileSync && $this->native->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $path !== $this->failDirectory && $this->native->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        $this->linkCalls++;
        $probe = @fopen($source, 'rb');
        if ($probe !== false) {
            $this->sourceExclusivelyLockableDuringLink = @flock($probe, LOCK_EX | LOCK_NB);
            if ($this->sourceExclusivelyLockableDuringLink) {
                @flock($probe, LOCK_UN);
            }
            @fclose($probe);
        }
        $linked = $this->native->link($source, $target);

        return $linked && ! $this->reportLinkFailureAfterSuccess;
    }

    public function rename(string $source, string $target): bool
    {
        if ($this->rejectRename) {
            return false;
        }
        $renamed = $this->native->rename($source, $target);

        return $renamed && ! $this->reportRenameFailureAfterSuccess;
    }
}
