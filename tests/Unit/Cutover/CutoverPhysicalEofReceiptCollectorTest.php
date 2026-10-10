<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Cutover;

use OpsFour\S3Server\Cutover\CutoverPhysicalEofReceiptCollector;
use OpsFour\S3Server\Storage\LocalTierPhysicalInventorySet;
use OpsFour\S3Server\Storage\NodeLocalPhysicalInventorySet;
use PHPUnit\Framework\TestCase;

final class CutoverPhysicalEofReceiptCollectorTest extends TestCase
{
    private string $base;

    protected function setUp(): void
    {
        parent::setUp();
        $this->base = sys_get_temp_dir() . '/s3-physical-receipt-' . bin2hex(random_bytes(8));
        mkdir($this->base, 0o700);
        $this->base = realpath($this->base) ?: throw new \RuntimeException('Test root is unavailable.');
        foreach (['cold', 'hot', 'scratch', 'historical'] as $name) {
            mkdir($this->base . '/' . $name, 0o700);
        }
    }

    protected function tearDown(): void
    {
        $this->remove($this->base);
        parent::tearDown();
    }

    public function test_empty_roots_have_explicit_eof_receipts_for_every_scope(): void
    {
        $receipt = $this->collector()->collect();

        self::assertCount(2, $receipt['tier_scopes']);
        self::assertCount(2, $receipt['node_scopes']);
        self::assertSame(hash('sha256', 'COLD'), $receipt['tier_scopes'][0]['storage_tier_sha256']);
        self::assertSame(hash('sha256', 'HOT'), $receipt['tier_scopes'][1]['storage_tier_sha256']);
        foreach ([...$receipt['tier_scopes'], ...$receipt['node_scopes']] as $scope) {
            self::assertSame(0, $scope['row_count']);
            self::assertTrue($scope['observed_empty_page']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $scope['transcript_sha256']);
        }
        self::assertSame('node_scratch', $receipt['node_scopes'][0]['scope_kind']);
        self::assertSame('historical_temp', $receipt['node_scopes'][1]['scope_kind']);
        self::assertSame(hash('sha256', 'host-secret'), $receipt['node_scopes'][0]['host_sha256']);
        $this->assertNoRawPathsOrHost($receipt);
    }

    public function test_multiple_pages_are_counted_and_the_observed_sequence_changes_the_transcript(): void
    {
        file_put_contents($this->base . '/hot/one', 'first');
        file_put_contents($this->base . '/hot/two', 'second');
        mkdir($this->base . '/scratch/sub', 0o700);
        file_put_contents($this->base . '/scratch/sub/s3-post-old', 'old bytes');
        $first = $this->collector(pageSize: 1)->collect();

        self::assertSame(2, $first['tier_scopes'][1]['row_count']);
        self::assertSame(2, $first['node_scopes'][0]['row_count']);
        self::assertSame(0, $first['node_scopes'][1]['row_count']);
        $this->assertNoRawPathsOrHost($first);

        file_put_contents($this->base . '/hot/three', 'third');
        $second = $this->collector(pageSize: 1)->collect();
        self::assertSame(3, $second['tier_scopes'][1]['row_count']);
        self::assertNotSame(
            $first['tier_scopes'][1]['transcript_sha256'],
            $second['tier_scopes'][1]['transcript_sha256'],
        );
    }

    public function test_mount_drift_during_pagination_aborts_without_partial_receipt_or_path(): void
    {
        for ($index = 0; $index < 20; ++$index) {
            file_put_contents($this->base . '/hot/file-' . $index, 'x');
        }
        $calls = 0;
        $reader = static function () use (&$calls): string {
            ++$calls;
            return $calls > 12
                ? "2 0 0:2 / / rw - ext4 /dev/changed rw\n"
                : "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        };

        try {
            $this->collector($reader, 1)->collect();
            self::fail('Mount drift was accepted.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cutover physical EOF enumeration failed closed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
        self::assertGreaterThan(12, $calls);
    }

    public function test_invalid_page_size_is_rejected_before_scanning(): void
    {
        foreach ([0, 501] as $size) {
            try {
                $this->collector(pageSize: $size);
                self::fail('Invalid page size was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Physical EOF page size must be 1..500.', $error->getMessage());
            }
        }
    }

    public function test_same_or_new_collector_cannot_reuse_completed_scanners_even_when_roots_are_empty(): void
    {
        [$tiers, $nodes] = $this->inventorySets();
        $collector = new CutoverPhysicalEofReceiptCollector($tiers, $nodes);
        self::assertSame(0, $collector->collect()['tier_scopes'][0]['row_count']);

        $this->assertReceiptFailsClosed($collector);
        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));
    }

    public function test_a_partially_consumed_tier_or_node_scope_blocks_every_receipt(): void
    {
        file_put_contents($this->base . '/hot/object', 'bytes');
        file_put_contents($this->base . '/scratch/s3-post-orphan', 'bytes');

        [$tiers, $nodes] = $this->inventorySets();
        self::assertCount(1, $tiers->inventory('HOT')->nextPage(1));
        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));

        [$tiers, $nodes] = $this->inventorySets();
        self::assertCount(1, $nodes->nextPage(0, 1));
        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));
    }

    public function test_failed_scan_cannot_be_retried_with_same_or_new_collector(): void
    {
        file_put_contents($this->base . '/hot/target', 'bytes');
        symlink('target', $this->base . '/hot/link');
        [$tiers, $nodes] = $this->inventorySets();
        $collector = new CutoverPhysicalEofReceiptCollector($tiers, $nodes);

        $this->assertReceiptFailsClosed($collector);
        $this->assertReceiptFailsClosed($collector);
        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));
    }

    public function test_different_tier_root_maps_fail_before_any_scanner_claim(): void
    {
        mkdir($this->base . '/other-hot', 0o700);
        $reader = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        $tiers = new LocalTierPhysicalInventorySet([
            'HOT' => $this->base . '/hot',
            'COLD' => $this->base . '/cold',
        ], mountInfoReader: $reader);
        $nodes = new NodeLocalPhysicalInventorySet(
            'host-secret',
            [['kind' => 'node_scratch', 'root' => $this->base . '/scratch']],
            ['HOT' => $this->base . '/other-hot', 'COLD' => $this->base . '/cold'],
            mountInfoReader: $reader,
        );

        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));
        $this->assertAllScopesStillUnclaimed($tiers, $nodes);
    }

    public function test_different_tier_names_fail_even_when_root_paths_are_the_same(): void
    {
        $reader = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        $tiers = new LocalTierPhysicalInventorySet([
            'HOT' => $this->base . '/hot',
            'COLD' => $this->base . '/cold',
        ], mountInfoReader: $reader);
        $nodes = new NodeLocalPhysicalInventorySet(
            'host-secret',
            [['kind' => 'node_scratch', 'root' => $this->base . '/scratch']],
            ['WARM' => $this->base . '/hot', 'COLD' => $this->base . '/cold'],
            mountInfoReader: $reader,
        );

        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));
        $this->assertAllScopesStillUnclaimed($tiers, $nodes);
    }

    public function test_node_scratch_inside_actual_tier_root_cannot_be_hidden_by_another_tier_map(): void
    {
        mkdir($this->base . '/other-hot', 0o700);
        mkdir($this->base . '/hot/nested-scratch', 0o700);
        $reader = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        $tiers = new LocalTierPhysicalInventorySet([
            'HOT' => $this->base . '/hot',
            'COLD' => $this->base . '/cold',
        ], mountInfoReader: $reader);
        $nodes = new NodeLocalPhysicalInventorySet(
            'host-secret',
            [['kind' => 'node_scratch', 'root' => $this->base . '/hot/nested-scratch']],
            ['HOT' => $this->base . '/other-hot', 'COLD' => $this->base . '/cold'],
            mountInfoReader: $reader,
        );

        $this->assertReceiptFailsClosed(new CutoverPhysicalEofReceiptCollector($tiers, $nodes));
        $this->assertAllScopesStillUnclaimed($tiers, $nodes);
    }

    /** @param \Closure(): string|null $reader */
    private function collector(?\Closure $reader = null, int $pageSize = 500): CutoverPhysicalEofReceiptCollector
    {
        [$tiers, $nodes] = $this->inventorySets($reader);
        return new CutoverPhysicalEofReceiptCollector($tiers, $nodes, $pageSize);
    }

    /**
     * @param \Closure(): string|null $reader
     * @return array{LocalTierPhysicalInventorySet, NodeLocalPhysicalInventorySet}
     */
    private function inventorySets(?\Closure $reader = null): array
    {
        $reader ??= static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        $roots = [
            'HOT' => $this->base . '/hot',
            'COLD' => $this->base . '/cold',
        ];
        return [
            new LocalTierPhysicalInventorySet($roots, mountInfoReader: $reader),
            new NodeLocalPhysicalInventorySet(
                'host-secret',
                [
                    ['kind' => 'node_scratch', 'root' => $this->base . '/scratch'],
                    ['kind' => 'historical_temp', 'root' => $this->base . '/historical'],
                ],
                $roots,
                mountInfoReader: $reader,
            ),
        ];
    }

    private function assertReceiptFailsClosed(CutoverPhysicalEofReceiptCollector $collector): void
    {
        try {
            $collector->collect();
            self::fail('Reused or failed scanner produced an EOF receipt.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cutover physical EOF enumeration failed closed.', $error->getMessage());
            self::assertNull($error->getPrevious());
        }
    }

    private function assertAllScopesStillUnclaimed(
        LocalTierPhysicalInventorySet $tiers,
        NodeLocalPhysicalInventorySet $nodes,
    ): void {
        foreach ($tiers->tiers() as $tier) {
            self::assertInstanceOf(\stdClass::class, $tiers->inventory($tier)->claimForEofReceipt());
        }
        for ($index = 0; $index < $nodes->count(); ++$index) {
            self::assertInstanceOf(\stdClass::class, $nodes->claimScopeForEofReceipt($index));
        }
    }

    /** @param array<string, mixed> $receipt */
    private function assertNoRawPathsOrHost(array $receipt): void
    {
        $encoded = json_encode($receipt, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString($this->base, $encoded);
        self::assertStringNotContainsString('host-secret', $encoded);
        self::assertStringNotContainsString('HOT', $encoded);
        self::assertStringNotContainsString('COLD', $encoded);
        self::assertStringNotContainsString('s3-post-old', $encoded);
        self::assertStringNotContainsString('old bytes', $encoded);
    }

    private function remove(string $path): void
    {
        if (is_dir($path) && ! is_link($path)) {
            foreach (new \FilesystemIterator($path) as $entry) {
                if (! $entry instanceof \SplFileInfo) {
                    throw new \RuntimeException('Unexpected test fixture entry.');
                }
                $this->remove($entry->getPathname());
            }
            rmdir($path);
        } elseif (file_exists($path) || is_link($path)) {
            unlink($path);
        }
    }
}
