<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator;
use OpsFour\S3Server\Storage\StorageOrphanInventory;
use PHPUnit\Framework\TestCase;

final class StorageOrphanInventoryTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/s3-orphan-inventory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o700));
        $this->root = realpath($root) ?: throw new \RuntimeException('Test root is unavailable.');
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
    }

    public function test_null_tier_uses_the_explicit_default_and_unknown_tier_fails_closed(): void
    {
        $path = $this->root . '/restored-target';
        self::assertSame(6, file_put_contents($path, 'target'));
        $row = $this->row('1', null, hash('sha256', $path));

        $result = $this->inventory(new OrphanInventoryReferences([$row]))->run();
        self::assertSame(1, $result->physicalFiles);
        self::assertSame(1, $result->metadataReferences);
        self::assertSame(0, $result->orphanCandidates);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('unmapped storage tier');
        $this->inventory(new OrphanInventoryReferences([$this->row('1', 'COLD', hash('sha256', $path))]))->run();
    }

    public function test_candidate_output_is_bounded_while_the_total_remains_exact(): void
    {
        for ($i = 0; $i < 502; ++$i) {
            self::assertSame(1, file_put_contents($this->root . '/orphan-' . $i, 'x'));
        }

        $references = new OrphanInventoryReferences([]);
        $result = $this->inventory($references, pageSize: 37)->run();

        self::assertSame(502, $result->physicalFiles);
        self::assertSame(0, $result->metadataReferences);
        self::assertSame(502, $result->orphanCandidates);
        self::assertCount(500, $result->candidates);
        self::assertNotNull($result->nextCandidate);

        $second = new StorageOrphanInventory(
            ['HOT' => $this->root],
            'HOT',
            $references,
            37,
            mountInfoReader: static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
            afterCandidate: $result->nextCandidate,
        );
        $secondResult = $second->run();
        self::assertCount(2, $secondResult->candidates);
        self::assertNull($secondResult->nextCandidate);

        $actual = array_column([...$result->candidates, ...$secondResult->candidates], 'path_sha256');
        $expected = [];
        for ($i = 0; $i < 502; ++$i) {
            $expected[] = hash('sha256', $this->root . '/orphan-' . $i);
        }
        sort($expected, SORT_STRING);
        self::assertSame($expected, $actual);
    }

    public function test_reserved_prefix_payloads_are_reported_without_blocking_inventory(): void
    {
        $multipart = $this->root . '/user-multipart-prefix';
        $seal = $this->root . '/user-seal-prefix';
        self::assertSame(9, file_put_contents($multipart, "S3MPU2\0xx"));
        $sealPayload = "OPSFOUR-LOCAL-OBJECT-SEAL-V1\nbad";
        self::assertSame(strlen($sealPayload), file_put_contents($seal, $sealPayload));

        $result = $this->inventory(new OrphanInventoryReferences([]))->run();

        self::assertSame(2, $result->orphanCandidates);
        self::assertSame(
            ['malformed_reserved_prefix', 'malformed_reserved_prefix'],
            array_column($result->candidates, 'format'),
        );
    }

    public function test_disk_index_counts_duplicate_references_exactly_but_matches_once(): void
    {
        $path = $this->root . '/referenced';
        self::assertSame(4, file_put_contents($path, 'live'));
        $digest = hash('sha256', $path);
        $rows = [];
        for ($i = 0; $i < 1_001; ++$i) {
            $rows[] = $this->row((string) $i, 'HOT', $digest);
        }

        $result = $this->inventory(new OrphanInventoryReferences($rows), pageSize: 41)->run();

        self::assertSame(1_001, $result->metadataReferences);
        self::assertSame(1, $result->physicalFiles);
        self::assertSame(0, $result->orphanCandidates);
        self::assertSame([], $result->candidates);
    }

    private function inventory(CutoverPathReferenceEnumerator $references, int $pageSize = 500): StorageOrphanInventory
    {
        return new StorageOrphanInventory(
            ['HOT' => $this->root],
            'HOT',
            $references,
            $pageSize,
            mountInfoReader: static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
        );
    }

    /**
     * @return array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string}
     */
    private function row(string $id, ?string $tier, string $digest): array
    {
        return [
            'source' => CutoverPathReferenceEnumerator::RESTORE_TARGET,
            'id' => $id,
            'storage_tier' => $tier,
            'path_sha256' => $digest,
            'relative_path_sha256' => null,
            'sse_mode' => null,
            'part_format' => null,
            'operation' => 'restore_target',
            'state' => 'processing',
            'blocking_anomaly' => null,
        ];
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

/** @internal */
final class OrphanInventoryReferences implements CutoverPathReferenceEnumerator
{
    /**
     * @param list<array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string}> $rows
     */
    public function __construct(private readonly array $rows) {}

    public function page(string $source, ?string $afterId = null, int $limit = 100): array
    {
        if ($source !== self::RESTORE_TARGET) {
            return [];
        }
        $offset = $afterId === null ? 0 : ((int) $afterId + 1);
        return array_slice($this->rows, $offset, $limit);
    }
}
