<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Storage\LocalDurableFilePublisher;
use OpsFour\S3Server\Storage\LocalDurableFileIo;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class LocalDurableFilePublisherTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $path = sys_get_temp_dir() . '/s3-durable-publisher-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($path, 0o700));
        $root = realpath($path);
        self::assertIsString($root);
        $this->root = $root;
    }

    protected function tearDown(): void
    {
        $this->removeDirectory($this->root);
    }

    public function test_publish_orders_durability_before_success(): void
    {
        [$publisher, $io, $temp, $target] = $this->fixture();

        $publisher->publish($temp, $target);

        self::assertSame('payload', file_get_contents($target));
        self::assertFileDoesNotExist($temp);
        self::assertSame([
            'sync-file',
            'link',
            'sync-directory:' . dirname($target),
            'unlink',
            'sync-directory:' . dirname($temp),
        ], $io->events);
        self::assertFalse($io->renameCalled, 'Durable publication must remain create-only.');
    }

    public function test_write_readiness_probe_is_durable_and_leaves_no_file(): void
    {
        $io = new RecordingDurableFileIo();

        (new LocalDurableFilePublisher($this->root, $io))->assertWritable();

        self::assertSame(['sync-file', 'unlink', 'sync-directory:' . $this->root], $io->events);
        self::assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    public function test_write_readiness_probe_cleans_up_after_a_write_failure(): void
    {
        $io = new RecordingDurableFileIo();
        $io->failure = 'sync-file';

        try {
            (new LocalDurableFilePublisher($this->root, $io))->assertWritable();
            self::fail('An unsynchronized readiness write must fail.');
        } catch (\RuntimeException) {
        }

        self::assertSame(['sync-file', 'unlink', 'sync-directory:' . $this->root], $io->events);
        self::assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    public function test_write_readiness_probe_removes_a_stale_sentinel_before_retry(): void
    {
        $stale = $this->root . '/.opsfour-readiness-' . str_repeat('a', 32);
        self::assertSame(5, file_put_contents($stale, 'stale'));
        $io = new RecordingDurableFileIo();

        (new LocalDurableFilePublisher($this->root, $io))->assertWritable();

        self::assertSame([
            'unlink',
            'sync-directory:' . $this->root,
            'sync-file',
            'unlink',
            'sync-directory:' . $this->root,
        ], $io->events);
        self::assertSame([], array_values(array_diff(scandir($this->root) ?: [], ['.', '..'])));
    }

    /** @return iterable<string, array{string, list<string>, bool, bool}> */
    public static function publicationFailures(): iterable
    {
        yield 'file fsync' => ['sync-file', ['sync-file'], false, true];
        yield 'create-only link' => ['link', ['sync-file', 'link'], false, true];
        yield 'target directory fsync' => ['target-directory', ['sync-file', 'link', 'sync-target-directory'], true, true];
        yield 'temp unlink' => ['unlink', ['sync-file', 'link', 'sync-target-directory', 'unlink'], true, true];
        yield 'temp directory fsync' => ['temp-directory', ['sync-file', 'link', 'sync-target-directory', 'unlink', 'sync-temp-directory'], true, false];
    }

    /** @param list<string> $eventPrefix */
    #[DataProvider('publicationFailures')]
    public function test_publish_never_reports_success_after_a_durability_failure(
        string $failure,
        array $eventPrefix,
        bool $targetExists,
        bool $tempExists,
    ): void {
        [$publisher, $io, $temp, $target] = $this->fixture();
        $io->failure = $failure;

        try {
            $publisher->publish($temp, $target);
            self::fail('A durability fault must fail publication.');
        } catch (\RuntimeException) {
        }

        $normalizedEvents = array_map(static function (string $event) use ($temp, $target): string {
            return match ($event) {
                'sync-directory:' . dirname($target) => 'sync-target-directory',
                'sync-directory:' . dirname($temp) => 'sync-temp-directory',
                default => $event,
            };
        }, $io->events);
        self::assertSame($eventPrefix, $normalizedEvents);
        self::assertSame($targetExists, file_exists($target));
        self::assertSame($tempExists, file_exists($temp));
        self::assertFalse($io->renameCalled);
    }

    public function test_existing_target_is_never_replaced(): void
    {
        [$publisher, $io, $temp, $target] = $this->fixture();
        self::assertSame(8, file_put_contents($target, 'existing'));

        try {
            $publisher->publish($temp, $target);
            self::fail('An existing target must reject create-only publication.');
        } catch (\RuntimeException) {
        }

        self::assertSame('existing', file_get_contents($target));
        self::assertSame('payload', file_get_contents($temp));
        self::assertSame(['sync-file', 'link'], $io->events);
        self::assertFalse($io->renameCalled);
    }

    public function test_ambiguous_link_keeps_both_paths_occupied(): void
    {
        [$publisher, $io, $temp, $target] = $this->fixture();
        $io->linkThenFail = true;

        $this->expectException(\RuntimeException::class);
        try {
            $publisher->publish($temp, $target);
        } finally {
            self::assertSame('payload', file_get_contents($target));
            self::assertSame('payload', file_get_contents($temp));
            self::assertSame(['sync-file', 'link'], $io->events);
        }
    }

    public function test_ambiguous_unlink_keeps_the_published_target(): void
    {
        [$publisher, $io, $temp, $target] = $this->fixture();
        $io->unlinkThenFail = true;

        $this->expectException(\RuntimeException::class);
        try {
            $publisher->publish($temp, $target);
        } finally {
            self::assertSame('payload', file_get_contents($target));
            self::assertFileDoesNotExist($temp);
            self::assertSame([
                'sync-file',
                'link',
                'sync-directory:' . dirname($target),
                'unlink',
            ], $io->events);
        }
    }

    public function test_operations_reject_paths_outside_the_storage_root(): void
    {
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);
        $outside = dirname($this->root) . '/outside-' . bin2hex(random_bytes(8));

        foreach (['ensure', 'delete'] as $operation) {
            try {
                $operation === 'ensure' ? $publisher->ensureDirectory($outside) : $publisher->delete($outside);
                self::fail("{$operation} accepted a path outside its storage root.");
            } catch (\InvalidArgumentException) {
            }
        }
    }

    public function test_operations_reject_dot_dot_and_symbolic_link_parents(): void
    {
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);
        $outside = dirname($this->root) . '/outside-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($outside, 0o700));
        self::assertTrue(symlink($outside, $this->root . '/linked'));

        try {
            foreach ([$this->root . '/bucket/../escape', $this->root . '/linked/child'] as $path) {
                try {
                    $publisher->ensureDirectory($path);
                    self::fail('Unsafe directory path was accepted.');
                } catch (\InvalidArgumentException) {
                }
            }
        } finally {
            unlink($this->root . '/linked');
            rmdir($outside);
        }
    }

    public function test_publish_rejects_a_target_in_the_temporary_namespace(): void
    {
        [$publisher, , $temp] = $this->fixture();
        $this->expectException(\InvalidArgumentException::class);
        $publisher->publish($temp, $this->root . '/.tmp/target');
    }

    public function test_ensure_directory_syncs_a_new_shard_chain_from_leaf_to_root(): void
    {
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);
        $target = $this->root . '/bucket/aa/bb';

        $publisher->ensureDirectory($target);

        self::assertDirectoryExists($target);
        self::assertSame([
            'sync-directory:' . $target,
            'sync-directory:' . dirname($target),
            'sync-directory:' . dirname($target, 2),
            'sync-directory:' . $this->root,
        ], $io->events);
    }

    public function test_ensure_directory_fails_if_any_directory_entry_cannot_be_synced(): void
    {
        $io = new RecordingDurableFileIo();
        $io->failedDirectory = $this->root . '/bucket';
        $publisher = new LocalDurableFilePublisher($this->root, $io);

        $this->expectException(\RuntimeException::class);
        try {
            $publisher->ensureDirectory($this->root . '/bucket/aa/bb');
        } finally {
            self::assertDirectoryExists($this->root . '/bucket/aa/bb');
            self::assertContains('sync-directory:' . $this->root . '/bucket', $io->events);
        }
    }

    public function test_delete_syncs_the_parent_directory_before_success(): void
    {
        $directory = $this->root . '/bucket/aa/bb';
        self::assertTrue(mkdir($directory, 0o700, true));
        $path = $directory . '/object';
        self::assertSame(7, file_put_contents($path, 'payload'));
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);

        $publisher->delete($path);

        self::assertFileDoesNotExist($path);
        self::assertSame(['unlink', 'sync-directory:' . $directory], $io->events);
    }

    public function test_missing_delete_still_syncs_its_parent_for_an_idempotent_retry(): void
    {
        $directory = $this->root . '/bucket/aa/bb';
        self::assertTrue(mkdir($directory, 0o700, true));
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);

        $publisher->delete($directory . '/missing');

        self::assertSame(['sync-directory:' . $directory], $io->events);
    }

    public function test_missing_delete_with_removed_parents_syncs_the_nearest_existing_ancestor(): void
    {
        $bucket = $this->root . '/bucket';
        self::assertTrue(mkdir($bucket, 0o700));
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);

        $publisher->delete($bucket . '/missing/shard/object');

        self::assertSame(['sync-directory:' . $bucket], $io->events);
    }

    public function test_delete_accepts_a_temporary_file_for_durable_cleanup(): void
    {
        $directory = $this->root . '/.tmp';
        self::assertTrue(mkdir($directory, 0o700));
        $path = $directory . '/attempt';
        self::assertSame(7, file_put_contents($path, 'payload'));
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($this->root, $io);

        $publisher->delete($path);

        self::assertFileDoesNotExist($path);
        self::assertSame(['unlink', 'sync-directory:' . $directory], $io->events);
    }

    public function test_canonical_root_accepts_paths_using_the_configured_symlink(): void
    {
        $link = dirname($this->root) . '/storage-link-' . bin2hex(random_bytes(8));
        self::assertTrue(symlink($this->root, $link));
        try {
            $io = new RecordingDurableFileIo();
            $publisher = new LocalDurableFilePublisher($link, $io);

            $publisher->ensureDirectory($link . '/bucket');

            self::assertDirectoryExists($this->root . '/bucket');
            self::assertSame([
                'sync-directory:' . $this->root . '/bucket',
                'sync-directory:' . $this->root,
            ], $io->events);
        } finally {
            unlink($link);
        }
    }

    public function test_directory_creation_can_durably_bootstrap_a_missing_storage_root(): void
    {
        $root = $this->root . '/new/storage';
        $io = new RecordingDurableFileIo();
        $publisher = new LocalDurableFilePublisher($root, $io, createRoot: true);

        $publisher->ensureDirectory($root . '/bucket');

        self::assertDirectoryExists($root . '/bucket');
        self::assertSame([
            'sync-directory:' . $root,
            'sync-directory:' . dirname($root),
            'sync-directory:' . $this->root,
            'sync-directory:' . $root . '/bucket',
            'sync-directory:' . $root,
        ], $io->events);
    }

    public function test_delete_can_retry_after_unlink_succeeded_but_parent_sync_failed(): void
    {
        $directory = $this->root . '/bucket/aa/bb';
        self::assertTrue(mkdir($directory, 0o700, true));
        $path = $directory . '/object';
        self::assertSame(7, file_put_contents($path, 'payload'));
        $io = new RecordingDurableFileIo();
        $io->failedDirectory = $directory;
        $publisher = new LocalDurableFilePublisher($this->root, $io);

        try {
            $publisher->delete($path);
            self::fail('A failed delete-directory sync must not report success.');
        } catch (\RuntimeException) {
            self::assertFileDoesNotExist($path);
        }

        $io->events = [];
        $io->failedDirectory = null;
        $publisher->delete($path);

        self::assertSame(['sync-directory:' . $directory], $io->events);
    }

    /** @return array{LocalDurableFilePublisher, RecordingDurableFileIo, string, string} */
    private function fixture(): array
    {
        $tempDirectory = $this->root . '/.tmp';
        $targetDirectory = $this->root . '/bucket/aa/bb';
        self::assertTrue(mkdir($tempDirectory, 0o700));
        self::assertTrue(mkdir($targetDirectory, 0o700, true));
        $temp = $tempDirectory . '/attempt';
        $target = $targetDirectory . '/object';
        self::assertSame(7, file_put_contents($temp, 'payload'));
        $io = new RecordingDurableFileIo();

        return [new LocalDurableFilePublisher($this->root, $io), $io, $temp, $target];
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
            if (is_dir($child) && ! is_link($child)) {
                $this->removeDirectory($child);
            } else {
                @unlink($child);
            }
        }
        @rmdir($path);
    }
}

/** @internal */
final class RecordingDurableFileIo implements LocalDurableFileIo
{
    /** @var list<string> */
    public array $events = [];

    public ?string $failure = null;

    public ?string $failedDirectory = null;

    public bool $renameCalled = false;

    public bool $linkThenFail = false;

    public bool $unlinkThenFail = false;

    public function syncFile($file): bool
    {
        $this->events[] = 'sync-file';

        return $this->failure !== 'sync-file';
    }

    public function syncDirectory(string $path): bool
    {
        $this->events[] = 'sync-directory:' . $path;
        if ($this->failedDirectory === $path) {
            return false;
        }
        if ($this->failure === 'target-directory' && ! str_ends_with($path, '/.tmp')) {
            return false;
        }

        return $this->failure !== 'temp-directory' || ! str_ends_with($path, '/.tmp');
    }

    public function link(string $source, string $target): bool
    {
        $this->events[] = 'link';

        if ($this->linkThenFail) {
            @link($source, $target);

            return false;
        }

        return $this->failure !== 'link' && @link($source, $target);
    }

    public function unlink(string $path): bool
    {
        $this->events[] = 'unlink';

        if ($this->unlinkThenFail) {
            @unlink($path);

            return false;
        }

        return $this->failure !== 'unlink' && @unlink($path);
    }
}
