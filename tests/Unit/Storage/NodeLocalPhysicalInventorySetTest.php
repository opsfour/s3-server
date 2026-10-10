<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Storage\NodeLocalPhysicalInventorySet;
use PHPUnit\Framework\TestCase;

final class NodeLocalPhysicalInventorySetTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/s3-node-inventory-' . bin2hex(random_bytes(8));
        mkdir($this->base, 0o700);
        $this->base = realpath($this->base) ?: throw new \RuntimeException('Test root is unavailable.');
        foreach (['tier', 'scratch', 'historical', 'other'] as $name) {
            mkdir($this->base . '/' . $name, 0o700);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        parent::tearDown();
    }

    public function test_orphan_prefixes_and_unknown_files_are_blocking_digest_only_observations(): void
    {
        $files = [
            's3fw-part-' . str_repeat('a', 64) => 's3fw-',
            's3-post-legacy' => 's3-post-',
            's3-md5-legacy.tmp' => 's3-md5-',
            's3-sha256-legacy.tmp' => 's3-sha256-',
            'unexpected.bin' => 'unknown',
        ];
        foreach ($files as $name => $_class) {
            file_put_contents($this->base . '/scratch/' . $name, 'private bytes');
        }
        mkdir($this->base . '/scratch/subdir', 0o700);
        file_put_contents($this->base . '/scratch/subdir/s3-post-nested', 'nested bytes');
        file_put_contents($this->base . '/historical/s3-post-old', 'old bytes');
        $set = $this->inventorySet();

        self::assertSame(2, $set->count());
        $rows = [];
        do {
            $page = $set->nextPage(0, 2);
            self::assertLessThanOrEqual(2, count($page));
            array_push($rows, ...$page);
        } while ($page !== []);

        self::assertCount(7, $rows);
        $byDigest = array_column($rows, null, 'relative_path_sha256');
        foreach ($files as $name => $class) {
            $row = $byDigest[hash('sha256', $name)];
            self::assertSame('node_scratch', $row['scope_kind']);
            self::assertSame($class, $row['prefix_class']);
            self::assertSame('file', $row['type']);
            self::assertTrue($row['blocking_observation']);
            self::assertSame(strlen('private bytes'), $row['size_bytes']);
            self::assertSame(hash('sha256', $this->base . '/scratch/' . $name), $row['path_sha256']);
            self::assertSame(hash('sha256', 'private-host-id'), $row['host_sha256']);
            self::assertSame(hash('sha256', $this->base . '/scratch'), $row['root_path_sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['root_inode_sha256']);
        }
        self::assertSame('unknown', $byDigest[hash('sha256', 'subdir')]['prefix_class']);
        self::assertFalse($byDigest[hash('sha256', 'subdir')]['blocking_observation']);
        self::assertSame('s3-post-', $byDigest[hash('sha256', 'subdir/s3-post-nested')]['prefix_class']);
        $encoded = json_encode($rows, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->base, $encoded);
        self::assertStringNotContainsString('private-host-id', $encoded);
        self::assertStringNotContainsString('private bytes', $encoded);
        self::assertStringNotContainsString('s3-post-old', $encoded);

        $historical = $set->nextPage(1);
        self::assertCount(1, $historical);
        self::assertSame('historical_temp', $historical[0]['scope_kind']);
        self::assertSame('s3-post-', $historical[0]['prefix_class']);
        self::assertTrue($historical[0]['blocking_observation']);
    }

    public function test_descriptor_exists_for_empty_root_and_remains_stable_across_pages(): void
    {
        $set = $this->inventorySet();
        $empty = $set->scopeDescriptor(0);
        $stat = lstat($this->base . '/scratch') ?: throw new \RuntimeException('Test scratch identity is unavailable.');
        self::assertSame([
            'scope_kind' => 'node_scratch',
            'host_sha256' => hash('sha256', 'private-host-id'),
            'root_path_sha256' => hash('sha256', $this->base . '/scratch'),
            'root_inode_sha256' => hash('sha256', $stat['dev'] . ':' . $stat['ino']),
        ], $empty);
        self::assertSame([], $set->nextPage(0, 1));
        self::assertSame($empty, $set->scopeDescriptor(0));

        file_put_contents($this->base . '/historical/s3-post-first', 'one');
        file_put_contents($this->base . '/historical/s3-post-second', 'two');
        $set = $this->inventorySet();
        $descriptor = $set->scopeDescriptor(1);
        self::assertSame('historical_temp', $descriptor['scope_kind']);
        self::assertCount(1, $set->nextPage(1, 1));
        self::assertSame($descriptor, $set->scopeDescriptor(1));
        self::assertCount(1, $set->nextPage(1, 1));
        self::assertSame([], $set->nextPage(1, 1));
        self::assertSame($descriptor, $set->scopeDescriptor(1));
        self::assertStringNotContainsString($this->base, json_encode($descriptor, JSON_THROW_ON_ERROR));
    }

    public function test_unknown_descriptor_index_is_rejected(): void
    {
        $set = $this->inventorySet();
        foreach ([-1, 2] as $index) {
            try {
                $set->scopeDescriptor($index);
                self::fail('Unknown scope index was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Unknown node-local inventory scope.', $error->getMessage());
            }
        }
    }

    public function test_symlink_and_unreadable_entries_revoke_the_scope(): void
    {
        file_put_contents($this->base . '/scratch/target', 'x');
        symlink('target', $this->base . '/scratch/link');
        $set = $this->inventorySet();
        try {
            $set->nextPage(0);
            self::fail('Symlink was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory failed closed.', $error->getMessage());
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('revoked');
            $set->nextPage(0);
        }
    }

    public function test_unreadable_file_fails_closed(): void
    {
        file_put_contents($this->base . '/scratch/unreadable', 'x');
        chmod($this->base . '/scratch/unreadable', 0o000);
        $this->expectException(\RuntimeException::class);
        $this->inventorySet()->nextPage(0);
    }

    public function test_mount_drift_between_pages_fails_closed(): void
    {
        file_put_contents($this->base . '/scratch/a', 'x');
        file_put_contents($this->base . '/scratch/b', 'y');
        $state = new class {
            public bool $changed = false;
        };
        $reader = static function () use ($state): string {
            return $state->changed
                ? "2 0 0:2 / / rw - ext4 /dev/changed rw\n"
                : "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        };
        $set = $this->inventorySet($reader);
        self::assertCount(1, $set->nextPage(0, 1));
        $state->changed = true;
        $this->expectException(\RuntimeException::class);
        $set->nextPage(0, 1);
    }

    public function test_root_overlap_bind_alias_and_symlink_roots_fail_before_scan(): void
    {
        foreach ([$this->base . '/tier', $this->base] as $root) {
            try {
                $this->inventorySet(null, [['kind' => 'node_scratch', 'root' => $root]]);
                self::fail('Overlapping roots were accepted.');
            } catch (\RuntimeException $error) {
                self::assertSame('Physical inventory tier roots overlap.', $error->getMessage());
            }
        }
        symlink($this->base . '/scratch', $this->base . '/scratch-alias');
        try {
            $this->inventorySet(null, [['kind' => 'node_scratch', 'root' => $this->base . '/scratch-alias']]);
            self::fail('Symlink root was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory root must be an existing canonical directory.', $error->getMessage());
        }

        try {
            $this->inventorySet(
                null,
                [['kind' => 'node_scratch', 'root' => $this->base . '/scratch']],
                static fn(string $path): array => ['dev' => 1, 'ino' => 9],
            );
            self::fail('Same-inode bind alias was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Physical inventory tier roots share an inode.', $error->getMessage());
        }
    }

    public function test_public_or_unlisted_roots_and_invalid_scope_fail_closed(): void
    {
        chmod($this->base . '/historical', 0o755);
        try {
            $this->inventorySet();
            self::fail('Public historical root was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Node-local inventory root must be a private, readable, process-owned directory.', $error->getMessage());
        }
        chmod($this->base . '/historical', 0o700);

        foreach ([
            [],
            [['kind' => 'other', 'root' => $this->base . '/scratch']],
        ] as $roots) {
            try {
                $this->inventorySet(null, $roots);
                self::fail('Invalid scope configuration was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertNotSame('', $error->getMessage());
            }
        }
    }

    public function test_nfs_node_local_root_is_rejected_before_scanning(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Node-local inventory requires a qualified local filesystem.');
        $this->inventorySet(fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n"
            . "2 1 0:2 / {$this->base}/scratch rw - nfs4 server:/ rw\n");
    }

    public function test_synthetic_scope_cannot_receive_nfs_evidence(): void
    {
        // Key validation must precede every evidence method or scratch stat.
        $evidence = (new \ReflectionClass(EfsHostEvidenceReader::class))->newInstanceWithoutConstructor();
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('evidence belongs to an unknown tier');
        new NodeLocalPhysicalInventorySet(
            'private-host-id',
            [['kind' => 'node_scratch', 'root' => $this->base . '/scratch']],
            ['HOT' => $this->base . '/tier'],
            ['__node_local_physical_inventory_0' => $evidence],
            fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n"
                . "2 1 0:2 / {$this->base}/scratch rw - nfs4 server:/ rw\n",
        );
    }

    /**
     * @param \Closure(): string|null $mountInfoReader
     * @param list<array{kind: string, root: string}>|null $scopes
     * @param (\Closure(string): array{dev: int, ino: int})|null $identityReader
     */
    private function inventorySet(
        ?\Closure $mountInfoReader = null,
        ?array $scopes = null,
        ?\Closure $identityReader = null,
    ): NodeLocalPhysicalInventorySet {
        return new NodeLocalPhysicalInventorySet(
            'private-host-id',
            $scopes ?? [
                ['kind' => 'node_scratch', 'root' => $this->base . '/scratch'],
                ['kind' => 'historical_temp', 'root' => $this->base . '/historical'],
            ],
            ['HOT' => $this->base . '/tier'],
            mountInfoReader: $mountInfoReader ?? static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
            localRootIdentityReader: $identityReader,
        );
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
        chmod($path, 0o700);
        foreach (new \DirectoryIterator($path) as $entry) {
            if (! $entry->isDot()) {
                $this->remove($entry->getPathname());
            }
        }
        rmdir($path);
    }
}
