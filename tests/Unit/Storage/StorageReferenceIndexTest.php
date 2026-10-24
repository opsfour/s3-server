<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Storage\StorageReferenceIndex;
use PHPUnit\Framework\TestCase;

final class StorageReferenceIndexTest extends TestCase
{
    private string $base;

    private string $scratch;

    private string $root;

    protected function setUp(): void
    {
        $base = sys_get_temp_dir() . '/s3-reference-index-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($base, 0o700));
        $this->base = realpath($base) ?: throw new \RuntimeException('Test root is unavailable.');
        $this->scratch = $this->base . '/scratch';
        $this->root = $this->base . '/storage';
        self::assertTrue(mkdir($this->scratch, 0o700));
        self::assertTrue(mkdir($this->root, 0o700));
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
    }

    public function test_scratch_limit_below_one_mib_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('scratch limit is outside the supported range');

        new StorageReferenceIndex($this->scratch, StorageReferenceIndex::MIN_MAX_BYTES - 1, ['HOT' => $this->root]);
    }

    public function test_scratch_directory_below_storage_root_is_rejected_without_creating_lock(): void
    {
        $nestedScratch = $this->root . '/scratch';
        self::assertTrue(mkdir($nestedScratch, 0o700));

        try {
            new StorageReferenceIndex(
                $nestedScratch,
                StorageReferenceIndex::MIN_MAX_BYTES,
                ['HOT' => $this->root],
            );
            self::fail('A scratch path below the storage root was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('disjoint from every storage tier root', $error->getMessage());
        }

        self::assertFileDoesNotExist($nestedScratch . '/.opsfour-s3-inventory.lock');
    }

    public function test_reference_writes_stop_at_the_configured_byte_limit(): void
    {
        $index = new StorageReferenceIndex(
            $this->scratch,
            StorageReferenceIndex::MIN_MAX_BYTES,
            ['HOT' => $this->root],
        );
        $failedClosed = false;
        try {
            for ($page = 0; $page < 200; ++$page) {
                $rows = [];
                for ($offset = 0; $offset < 500; ++$offset) {
                    $rows[] = [
                        'tier' => 'HOT',
                        'path_sha256' => hash('sha256', $page . ':' . $offset),
                        'relative_path_sha256' => null,
                    ];
                }
                try {
                    $index->addPage($rows);
                } catch (\RuntimeException $error) {
                    self::assertStringContainsString('--scratch-max-bytes', $error->getMessage());
                    $failedClosed = true;
                    break;
                }
            }
        } finally {
            $index->close();
        }

        self::assertTrue($failedClosed, 'The 1 MiB SQLite limit accepted 100,000 unique references.');
        self::assertSame(
            ['.opsfour-s3-inventory.lock'],
            array_values(array_diff(scandir($this->scratch) ?: [], ['.', '..'])),
        );
    }

    public function test_scratch_capacity_is_owned_by_only_one_inventory_process(): void
    {
        $first = new StorageReferenceIndex(
            $this->scratch,
            StorageReferenceIndex::MIN_MAX_BYTES,
            ['HOT' => $this->root],
        );
        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('already using --scratch-path');
            new StorageReferenceIndex(
                $this->scratch,
                StorageReferenceIndex::MIN_MAX_BYTES,
                ['COLD' => $this->base . '/other-storage'],
            );
        } finally {
            $first->closeOrFail();
        }
    }

    public function test_checked_close_removes_private_index_and_releases_lock(): void
    {
        $index = new StorageReferenceIndex(
            $this->scratch,
            StorageReferenceIndex::MIN_MAX_BYTES,
            ['HOT' => $this->root],
        );
        self::assertCount(1, glob($this->scratch . '/opsfour-s3-inventory-*') ?: []);

        $index->closeOrFail();

        self::assertSame([], glob($this->scratch . '/opsfour-s3-inventory-*') ?: []);
        $next = new StorageReferenceIndex(
            $this->scratch,
            StorageReferenceIndex::MIN_MAX_BYTES,
            ['HOT' => $this->root],
        );
        $next->closeOrFail();
    }

    public function test_checked_close_reports_leftover_index_directory_and_releases_lock(): void
    {
        $index = new StorageReferenceIndex(
            $this->scratch,
            StorageReferenceIndex::MIN_MAX_BYTES,
            ['HOT' => $this->root],
        );
        $directories = glob($this->scratch . '/opsfour-s3-inventory-*') ?: [];
        self::assertCount(1, $directories);
        self::assertTrue(file_put_contents($directories[0] . '/unexpected', 'x') !== false);

        try {
            $index->closeOrFail();
            self::fail('A leftover private index directory was reported as cleaned.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('scratch index cleanup failed', $error->getMessage());
        }

        $next = new StorageReferenceIndex(
            $this->scratch,
            StorageReferenceIndex::MIN_MAX_BYTES,
            ['HOT' => $this->root],
        );
        $next->closeOrFail();
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new \DirectoryIterator($path) as $entry) {
            if (! $entry->isDot()) {
                $this->remove($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
