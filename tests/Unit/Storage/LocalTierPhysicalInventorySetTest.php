<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Storage\LocalTierPhysicalInventorySet;
use PHPUnit\Framework\TestCase;

final class LocalTierPhysicalInventorySetTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/s3-inventory-set-' . bin2hex(random_bytes(8));
        mkdir($this->base);
        $this->base = realpath($this->base) ?: throw new \RuntimeException('Test root is unavailable.');
        mkdir($this->base . '/a');
        mkdir($this->base . '/ab');
        mkdir($this->base . '/a/nested');
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        parent::tearDown();
    }

    public function test_disjoint_segment_siblings_yield_only_bound_inventories(): void
    {
        file_put_contents($this->base . '/a/one', 'x');
        file_put_contents($this->base . '/ab/two', 'y');
        $set = $this->set(['HOT' => $this->base . '/a', 'COLD' => $this->base . '/ab']);

        self::assertSame(['HOT', 'COLD'], $set->tiers());
        self::assertSame(hash('sha256', $this->base . '/a'), $set->inventory('HOT')->rootPathSha256());
        self::assertSame('HOT', $set->inventory('HOT')->nextPage()[0]['storage_tier']);
        self::assertSame('COLD', $set->inventory('COLD')->nextPage()[0]['storage_tier']);
        $this->expectException(\InvalidArgumentException::class);
        $set->inventory('UNKNOWN');
    }

    public function test_duplicate_and_both_prefix_orders_fail_before_scanners_are_returned(): void
    {
        foreach ([
            ['HOT' => $this->base . '/a', 'COLD' => $this->base . '/a'],
            ['HOT' => $this->base . '/a', 'COLD' => $this->base . '/a/nested'],
            ['HOT' => $this->base . '/a/nested', 'COLD' => $this->base . '/a'],
        ] as $roots) {
            try {
                $this->set($roots);
                self::fail('Overlapping tier roots were accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Physical inventory tier roots overlap.', $error->getMessage());
            }
        }
    }

    public function test_disjoint_bind_mount_aliases_with_the_same_device_and_inode_fail_closed(): void
    {
        $sameIdentity = static fn(string $root): array => ['dev' => 42, 'ino' => 99];
        try {
            new LocalTierPhysicalInventorySet(
                ['HOT' => $this->base . '/a', 'COLD' => $this->base . '/ab'],
                mountInfoReader: static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
                localRootIdentityReader: $sameIdentity,
            );
            self::fail('Bind-mount aliases were accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory tier roots share an inode.', $error->getMessage());
        }

        $distinctDevices = static fn(string $root): array => [
            'dev' => str_ends_with($root, '/a') ? 42 : 43,
            'ino' => 99,
        ];
        $set = new LocalTierPhysicalInventorySet(
            ['HOT' => $this->base . '/a', 'COLD' => $this->base . '/ab'],
            mountInfoReader: static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
            localRootIdentityReader: $distinctDevices,
        );
        self::assertSame(['HOT', 'COLD'], $set->tiers());
    }

    public function test_missing_local_root_identity_fails_closed(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Physical inventory root identity is unavailable.');
        new LocalTierPhysicalInventorySet(
            ['HOT' => $this->base . '/a'],
            mountInfoReader: static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
            localRootIdentityReader: static fn(string $root): false => false,
        );
    }

    public function test_root_alias_and_symlink_parent_are_rejected(): void
    {
        symlink($this->base . '/a', $this->base . '/alias');
        foreach ([$this->base . '/alias', $this->base . '/alias/nested'] as $alias) {
            try {
                $this->set(['HOT' => $alias]);
                self::fail('A symlink root was accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Physical inventory root must be an existing canonical directory.', $error->getMessage());
            }
        }
    }

    public function test_noncanonical_spellings_and_empty_configuration_are_rejected(): void
    {
        foreach ([
            [],
            ['HOT' => $this->base . '/a/'],
            ['HOT' => $this->base . '/a/../ab'],
            ['HOT' => $this->base . '//a'],
            ['HOT' => 'relative/path'],
        ] as $roots) {
            $this->expectInvalidConfiguration($roots);
        }
    }

    public function test_nfs_without_explicit_evidence_fails_before_accessing_missing_root(): void
    {
        $missingRoot = $this->base . '/does-not-exist';
        try {
            new LocalTierPhysicalInventorySet(
                ['HOT' => $missingRoot],
                mountInfoReader: static fn(): string => "1 0 0:1 / / rw - nfs4 server:/ rw\n",
            );
            self::fail('NFS root without evidence was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('NFS physical inventory requires exact per-tier host evidence.', $error->getMessage());
        }
    }

    public function test_unknown_mount_and_malformed_mount_information_fail_closed(): void
    {
        foreach ([
            "1 0 0:1 / / rw - cifs //server/share rw\n",
            "1 0 0:1 / / rw - ext4 /dev/mock rw\nmalformed\n",
            '',
        ] as $mounts) {
            try {
                new LocalTierPhysicalInventorySet(['HOT' => $this->base . '/a'], mountInfoReader: static fn(): string => $mounts);
                self::fail('Unsupported or malformed mount was accepted.');
            } catch (\RuntimeException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    /** @param array<string, string> $roots */
    private function set(array $roots): LocalTierPhysicalInventorySet
    {
        return new LocalTierPhysicalInventorySet(
            $roots,
            mountInfoReader: static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
        );
    }

    /** @param array<string, string> $roots */
    private function expectInvalidConfiguration(array $roots): void
    {
        try {
            $this->set($roots);
            self::fail('Invalid tier roots were accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertNotSame('', $error->getMessage());
        }
    }

    private function remove(string $path): void
    {
        if (is_link($path) || is_file($path)) {
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
