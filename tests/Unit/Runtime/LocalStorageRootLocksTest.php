<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\File\Driver\BlockingFilesystemDriver;
use Amp\File\Filesystem;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Runtime\LocalStorageRootLocks;
use OpsFour\S3Server\Storage\FilesystemBackend;
use PHPUnit\Framework\TestCase;

final class LocalStorageRootLocksTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-root-lock-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0o700));
    }

    protected function tearDown(): void
    {
        if (! is_dir($this->base)) {
            return;
        }

        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->base);
    }

    public function test_canonical_roots_are_deduplicated_and_released_together(): void
    {
        $firstRoot = $this->base . '/first';
        $secondRoot = $this->base . '/second';
        self::assertTrue(mkdir($firstRoot));
        self::assertTrue(mkdir($secondRoot));
        self::assertTrue(symlink($firstRoot, $this->base . '/first-alias'));

        $locks = LocalStorageRootLocks::create();
        $locks->acquireRoots([$secondRoot, $this->base . '/first-alias', $firstRoot]);

        self::assertFileExists($firstRoot . '/.opsfour/runtime.lock');
        self::assertFileExists($secondRoot . '/.opsfour/runtime.lock');
        $this->assertContended($firstRoot);
        $this->assertContended($secondRoot);

        $locks->release();

        foreach ([$firstRoot, $secondRoot] as $root) {
            $replacement = LocalStorageRootLocks::create();
            $replacement->acquireRoots([$root]);
            $replacement->release();
        }
    }

    public function test_reacquiring_the_same_roots_is_idempotent_but_cannot_change_the_set(): void
    {
        $root = $this->base . '/same';
        $otherRoot = $this->base . '/other';
        self::assertTrue(mkdir($root));
        self::assertTrue(mkdir($otherRoot));
        $locks = LocalStorageRootLocks::create();
        $locks->acquireRoots([$root]);

        $locks->acquireRoots([$root]);
        $this->assertContended($root);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Local storage root ownership was already acquired for different roots.');
        try {
            $locks->acquireRoots([$root, $otherRoot]);
        } finally {
            $locks->release();
        }
    }

    public function test_overlapping_roots_are_rejected_before_any_lock_is_taken(): void
    {
        $root = $this->base . '/objects';
        $nested = $root . '/nested';
        self::assertTrue(mkdir($nested, 0o700, true));

        $locks = LocalStorageRootLocks::create();
        try {
            $locks->acquireRoots([$nested, $root]);
            self::fail('Overlapping local storage roots were accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Configured local storage roots must not overlap.', $error->getMessage());
        }

        $replacement = LocalStorageRootLocks::create();
        $replacement->acquireRoots([$root]);
        $replacement->release();
    }

    public function test_backend_inventory_unwraps_observability(): void
    {
        $root = $this->base . '/observed';
        self::assertTrue(mkdir($root));
        $locks = LocalStorageRootLocks::create();
        $locks->acquireBackends([
            new ObservedStorageBackend($this->backend($root), new MetricsCollector(), 'filesystem:test'),
        ]);

        $this->assertContended($root);
        $locks->release();
    }

    public function test_missing_root_is_created_with_private_permissions(): void
    {
        $root = $this->base . '/new/nested';
        $locks = LocalStorageRootLocks::create();
        $locks->acquireRoots([$root]);

        self::assertDirectoryExists($root);
        self::assertSame(0o700, fileperms($root) & 0o777);

        $locks->release();
    }

    public function test_runtime_lock_must_be_a_single_link_regular_file(): void
    {
        $root = $this->base . '/hardlink';
        self::assertTrue(mkdir($root . '/.opsfour', 0o700, true));
        $outside = $this->base . '/outside-file';
        self::assertSame(4, file_put_contents($outside, 'safe'));
        self::assertTrue(link($outside, $root . '/.opsfour/runtime.lock'));
        $permissions = fileperms($outside) & 0o777;

        try {
            LocalStorageRootLocks::create()->acquireRoots([$root]);
            self::fail('A hard-linked runtime lock was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cannot safely acquire the local storage runtime lock.', $error->getMessage());
        }

        clearstatcache(true, $outside);
        self::assertSame($permissions, fileperms($outside) & 0o777);
        self::assertSame('safe', file_get_contents($outside));
    }

    public function test_reacquired_lock_can_still_be_retained_after_a_successful_retry(): void
    {
        $root = $this->base . '/retry';
        self::assertTrue(mkdir($root));
        $locks = LocalStorageRootLocks::create();
        $locks->acquireRoots([$root]);
        $locks->retainUntilProcessExit();
        $locks->release();
        $locks->acquireRoots([$root]);
        $locks->retainUntilProcessExit();
        unset($locks);
        gc_collect_cycles();

        $this->assertContended($root);
    }

    public function test_reserved_internal_namespace_must_not_be_a_symlink(): void
    {
        $root = $this->base . '/objects';
        $outside = $this->base . '/outside';
        self::assertTrue(mkdir($root));
        self::assertTrue(mkdir($outside));
        self::assertTrue(symlink($outside, $root . '/.opsfour'));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot use the reserved local storage runtime namespace.');

        LocalStorageRootLocks::create()->acquireBackends([$this->backend($root)]);
    }

    private function assertContended(string $root): void
    {
        $second = LocalStorageRootLocks::create();
        try {
            $second->acquireBackends([$this->backend($root)]);
            self::fail('A second owner acquired an already locked storage root.');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'A configured local storage root is already owned by another S3 server process.',
                $error->getMessage(),
            );
        }
    }

    private function backend(string $root): FilesystemBackend
    {
        $filesystem = new Filesystem(new BlockingFilesystemDriver());

        return new FilesystemBackend($root, $filesystem, $filesystem);
    }
}
