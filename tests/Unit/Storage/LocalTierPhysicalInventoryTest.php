<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Storage\LocalTierPhysicalInventory;
use PHPUnit\Framework\TestCase;

final class LocalTierPhysicalInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        parent::setUp();
        $this->root = sys_get_temp_dir() . '/s3-physical-inventory-' . bin2hex(random_bytes(8));
        mkdir($this->root);
        $this->root = realpath($this->root) ?: throw new \RuntimeException('Test root is unavailable.');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        parent::tearDown();
    }

    public function test_hashes_directory_and_file_without_exposing_names_or_contents(): void
    {
        mkdir($this->root . '/.parts');
        mkdir($this->root . '/.parts/UploadId');
        file_put_contents($this->root . '/.parts/UploadId/1-part', 'unclassified bytes');
        $inventory = $this->inventory();

        $rows = [];
        do {
            $page = $inventory->nextPage(1);
            self::assertLessThanOrEqual(1, count($page));
            array_push($rows, ...$page);
        } while ($page !== []);

        self::assertCount(3, $rows);
        $byRelativeHash = array_column($rows, null, 'relative_path_sha256');
        $partDirectory = $byRelativeHash[hash('sha256', '.parts/UploadId')];
        self::assertSame('directory', $partDirectory['type']);
        self::assertSame(hash('sha256', $this->root . '/.parts/UploadId'), $partDirectory['path_sha256']);
        self::assertSame(hash('sha256', '.parts/UploadId'), $partDirectory['multipart_scope_sha256']);
        self::assertSame(hash('sha256', $this->root), $inventory->rootPathSha256());
        self::assertSame('opaque_unclassified', $byRelativeHash[hash('sha256', '.parts/UploadId/1-part')]['format']);
        self::assertSame(hash('sha256', '.parts/UploadId'), $byRelativeHash[hash('sha256', '.parts/UploadId/1-part')]['multipart_scope_sha256']);
        self::assertNull($byRelativeHash[hash('sha256', '.parts')]['multipart_scope_sha256']);
        self::assertNotSame(hash('sha256', '.parts/uploadid'), $partDirectory['multipart_scope_sha256']);
        self::assertSame(strlen('unclassified bytes'), $byRelativeHash[hash('sha256', '.parts/UploadId/1-part')]['size_bytes']);
        self::assertStringNotContainsString('UploadId', json_encode($rows, JSON_THROW_ON_ERROR));
        self::assertSame([], $inventory->nextPage());
    }

    public function test_invalid_multipart_directory_name_has_no_scope_digest(): void
    {
        mkdir($this->root . '/.parts');
        mkdir($this->root . '/.parts/invalid space');
        file_put_contents($this->root . '/.parts/invalid space/part', 'x');
        $rows = $this->inventory()->nextPage();
        $byRelativeHash = array_column($rows, null, 'relative_path_sha256');
        self::assertNull($byRelativeHash[hash('sha256', '.parts/invalid space')]['multipart_scope_sha256']);
        self::assertNull($byRelativeHash[hash('sha256', '.parts/invalid space/part')]['multipart_scope_sha256']);
    }

    public function test_recognizes_only_unverified_envelope_and_seal_shapes(): void
    {
        file_put_contents($this->root . '/part', "S3MPU2\0\x02\0\0" . str_repeat('x', 60));
        file_put_contents($this->root . '/seal', "OPSFOUR-LOCAL-OBJECT-SEAL-V1\n" . str_repeat('a', 32) . "\n" . str_repeat('b', 64) . "\n");
        $rows = $this->inventory()->nextPage();
        $formats = array_column($rows, 'format');
        self::assertContains('multipart_v2_header_unverified', $formats);
        self::assertContains('object_seal_shape_unverified', $formats);
    }

    public function test_rejects_symlink_and_revokes_subsequent_pages(): void
    {
        file_put_contents($this->root . '/target', 'x');
        symlink('target', $this->root . '/link');
        $inventory = $this->inventory();
        try {
            $inventory->nextPage();
            self::fail('Symlink should be rejected.');
        } catch (\RuntimeException) {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('revoked');
            $inventory->nextPage();
        }
    }

    public function test_rejects_unreadable_file_even_when_test_process_is_privileged(): void
    {
        file_put_contents($this->root . '/unreadable', 'secret');
        chmod($this->root . '/unreadable', 0o000);
        $this->expectException(\RuntimeException::class);
        $this->inventory()->nextPage();
    }

    public function test_rejects_unreadable_directory_and_root_alias(): void
    {
        mkdir($this->root . '/unreadable');
        chmod($this->root . '/unreadable', 0o000);
        try {
            $this->inventory()->nextPage();
            self::fail('Unreadable directory should be rejected.');
        } catch (\RuntimeException) {
            chmod($this->root . '/unreadable', 0o700);
        }

        symlink($this->root, $this->root . '-alias');
        try {
            $this->expectException(\RuntimeException::class);
            new LocalTierPhysicalInventory('hot', $this->root . '-alias', fn(): string => $this->mounts());
        } finally {
            unlink($this->root . '-alias');
        }
    }

    public function test_rejects_nested_mount_without_reading_mountinfo_per_entry(): void
    {
        mkdir($this->root . '/nested');
        $calls = 0;
        $mounts = $this->mounts() . '2 1 0:2 / ' . $this->root . "/nested rw - tmpfs tmpfs rw\n";
        $inventory = $this->inventory(static function () use (&$calls, $mounts): string {
            $calls++;
            return $mounts;
        });
        $this->expectException(\RuntimeException::class);
        try {
            $inventory->nextPage();
        } finally {
            self::assertLessThanOrEqual(3, $calls);
        }
    }

    public function test_mount_change_between_pages_fails_closed(): void
    {
        file_put_contents($this->root . '/first', 'x');
        file_put_contents($this->root . '/second', 'y');
        $calls = 0;
        $inventory = $this->inventory(function () use (&$calls): string {
            $calls++;
            return $calls > 3 ? str_replace('1 0 0:1', '2 0 0:2', $this->mounts()) : $this->mounts();
        });
        self::assertCount(1, $inventory->nextPage(1));
        $this->expectException(\RuntimeException::class);
        $inventory->nextPage(1);
    }

    public function test_mount_change_after_last_nonempty_page_blocks_explicit_eof(): void
    {
        file_put_contents($this->root . '/only', 'x');
        $state = new class {
            public bool $changed = false;
        };
        $inventory = $this->inventory(function () use ($state): string {
            return $state->changed ? str_replace('1 0 0:1', '2 0 0:2', $this->mounts()) : $this->mounts();
        });
        self::assertCount(1, $inventory->nextPage());
        $state->changed = true;

        try {
            $inventory->nextPage();
            self::fail('Changed mount was accepted as EOF.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory failed closed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    public function test_eof_claim_reserves_a_fresh_scanner_and_cannot_be_reclaimed(): void
    {
        file_put_contents($this->root . '/only', 'x');
        $inventory = $this->inventory();
        $claim = $inventory->claimForEofReceipt();

        try {
            $inventory->nextPage();
            self::fail('Unclaimed page access was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory is reserved for an EOF receipt.', $error->getMessage());
        }
        try {
            $inventory->claimForEofReceipt();
            self::fail('Second claim was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory is not fresh for an EOF receipt.', $error->getMessage());
        }
        self::assertCount(1, $inventory->nextReceiptPage($claim));
        self::assertSame([], $inventory->nextReceiptPage($claim));
    }

    public function test_page_failure_does_not_expose_a_raw_path_through_previous_exception(): void
    {
        $calls = 0;
        $inventory = $this->inventory(function () use (&$calls): string {
            if (++$calls > 1) {
                throw new \RuntimeException('Private path: ' . $this->root . '/secret-object');
            }
            return $this->mounts();
        });

        try {
            $inventory->nextPage();
            self::fail('Inventory should fail closed.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory failed closed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString($this->root, (string) $error);
        }
    }

    public function test_rejects_network_filesystem_before_root_stat(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported physical inventory filesystem');
        $this->inventory(static fn(): string => "1 0 0:1 / / rw - nfs4 server:/ rw\n");
    }

    public function test_rejects_unknown_network_mount_before_traversal(): void
    {
        file_put_contents($this->root . '/object', 'x');
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Unsupported physical inventory filesystem');
        $this->inventory(static fn(): string => "1 0 0:1 / / rw - cifs //server/share rw\n");
    }

    public function test_rejects_invalid_page_size_and_malformed_envelope(): void
    {
        $inventory = $this->inventory();
        try {
            $inventory->nextPage(501);
            self::fail('Page size should be rejected.');
        } catch (\InvalidArgumentException) {
            self::assertSame([], $inventory->nextPage());
        }
        file_put_contents($this->root . '/bad', "S3MPU2\0bad");
        $this->expectException(\RuntimeException::class);
        $this->inventory()->nextPage();
    }

    private function inventory(?\Closure $mounts = null): LocalTierPhysicalInventory
    {
        return new LocalTierPhysicalInventory('hot', $this->root, $mounts ?? fn(): string => $this->mounts());
    }

    private function mounts(): string
    {
        return "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
            if (! is_link($path)) {
                chmod($path, 0o600);
            }
            unlink($path);
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
        rmdir($path);
    }
}
