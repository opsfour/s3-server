<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Cutover;

use OpsFour\S3Server\Storage\LocalTierPhysicalInventorySet;
use OpsFour\S3Server\Storage\NodeLocalPhysicalInventorySet;

/**
 * Records observed empty pages for every explicitly bound tier and node root.
 *
 * Scope order is stable (tier names sorted by bytes, then node indices), but
 * directory iteration order is not sorted. The transcript binds the observed
 * row sequence; it does not prove a canonical path order or detect omitted
 * roots, rows skipped by concurrent changes, or a genuine stopped snapshot.
 * Scanner mount/evidence checks apply through the observed EOF page, but do
 * not establish that the mount or bytes remain unchanged afterward.
 * Every scanner is claimed before traversal and cannot produce another
 * receipt from the same state, including after a failed run.
 * The node guard's tier-root names and bound path/inode digests must exactly
 * match the tier scanners before any claim or filesystem traversal.
 * This receipt is neither cross-host evidence nor an SSE or release decision.
 */
final class CutoverPhysicalEofReceiptCollector
{
    private bool $attempted = false;

    private const array TIER_ROW_FIELDS = [
        'storage_tier', 'path_sha256', 'relative_path_sha256',
        'multipart_scope_sha256', 'type', 'size_bytes', 'format',
        'basename_prefix_class',
    ];

    private const array NODE_ROW_FIELDS = [
        'scope_kind', 'host_sha256', 'root_path_sha256',
        'root_inode_sha256', 'path_sha256', 'relative_path_sha256',
        'type', 'size_bytes', 'prefix_class', 'blocking_observation',
    ];

    private const array PREFIX_CLASSES = [
        's3fw-', 's3-post-', 's3-md5-', 's3-sha256-', 'unknown',
    ];

    private const array FORMATS = [
        'multipart_v2_header_unverified', 'object_seal_shape_unverified',
        'multipart_seal_shape_unverified', 'opaque_unclassified',
    ];

    public function __construct(
        private readonly LocalTierPhysicalInventorySet $tierSet,
        private readonly NodeLocalPhysicalInventorySet $nodeSet,
        private readonly int $pageSize = 500,
    ) {
        if ($pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Physical EOF page size must be 1..500.');
        }
    }

    /**
     * @return array{
     *     tier_scopes: list<array{storage_tier_sha256: string, root_path_sha256: string, root_inode_sha256: string, row_count: int, transcript_sha256: string, observed_empty_page: true}>,
     *     node_scopes: list<array{scope_kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string, row_count: int, transcript_sha256: string, observed_empty_page: true}>
     * }
     */
    public function collect(): array
    {
        if ($this->attempted) {
            throw new \RuntimeException('Cutover physical EOF enumeration failed closed.');
        }
        $this->attempted = true;
        $tierReceipts = [];
        $nodeReceipts = [];
        try {
            $tiers = $this->tierSet->tiers();
            if ($tiers === [] || ! array_is_list($tiers) || count($tiers) !== count(array_unique($tiers))) {
                throw new \UnexpectedValueException('Physical tier scope list is invalid.');
            }
            usort($tiers, static fn(string $left, string $right): int => strcmp($left, $right));
            $nodeTierRoots = $this->nodeSet->configuredTierRootDigests();
            $nodeTierNames = array_keys($nodeTierRoots);
            usort($nodeTierNames, static fn(string $left, string $right): int => strcmp($left, $right));
            if ($nodeTierNames !== $tiers) {
                throw new \UnexpectedValueException('Physical inventory tier-root sets differ.');
            }
            foreach ($tiers as $tier) {
                $inventory = $this->tierSet->inventory($tier);
                $nodeRoot = $nodeTierRoots[$tier];
                if ($inventory->rootPathSha256() !== $nodeRoot['root_path_sha256']
                    || $inventory->rootInodeSha256() !== $nodeRoot['root_inode_sha256']) {
                    throw new \UnexpectedValueException('Physical inventory tier-root identities differ.');
                }
            }
            $tierClaims = [];
            foreach ($tiers as $tier) {
                if ($tier === '' || strlen($tier) > 255 || str_contains($tier, "\0")) {
                    throw new \UnexpectedValueException('Physical tier name is invalid.');
                }
                $tierClaims[$tier] = $this->tierSet->inventory($tier)->claimForEofReceipt();
            }
            $scopeCount = $this->nodeSet->count();
            if ($scopeCount < 1) {
                throw new \UnexpectedValueException('Physical node scope list is empty.');
            }
            $nodeClaims = [];
            for ($index = 0; $index < $scopeCount; ++$index) {
                $nodeClaims[] = $this->nodeSet->claimScopeForEofReceipt($index);
            }

            foreach ($tiers as $tier) {
                $inventory = $this->tierSet->inventory($tier);
                $rootPathDigest = $inventory->rootPathSha256();
                $rootInodeDigest = $inventory->rootInodeSha256();
                self::assertDigest($rootPathDigest);
                self::assertDigest($rootInodeDigest);
                $digest = hash_init('sha256');
                self::digestFields($digest, ['opsfour-cutover-physical-eof-v1', 'tier', $tier, $rootPathDigest, $rootInodeDigest]);
                $count = 0;
                while (true) {
                    $rows = $inventory->nextReceiptPage($tierClaims[$tier], $this->pageSize);
                    self::assertPage($rows, $this->pageSize);
                    if ($rows === []) {
                        self::digestField($digest, 'EOF');
                        $tierReceipts[] = [
                            'storage_tier_sha256' => hash('sha256', $tier),
                            'root_path_sha256' => $rootPathDigest,
                            'root_inode_sha256' => $rootInodeDigest,
                            'row_count' => $count,
                            'transcript_sha256' => hash_final($digest),
                            'observed_empty_page' => true,
                        ];
                        break;
                    }
                    $count = self::increasedCount($count, count($rows));
                    foreach ($rows as $row) {
                        self::assertTierRow($row, $tier);
                        self::digestRow($digest, $row, self::TIER_ROW_FIELDS);
                    }
                }
            }

            for ($index = 0; $index < $scopeCount; ++$index) {
                $descriptor = $this->nodeSet->scopeDescriptor($index);
                self::assertDescriptor($descriptor);
                $digest = hash_init('sha256');
                self::digestFields($digest, [
                    'opsfour-cutover-physical-eof-v1', 'node', (string) $index,
                    $descriptor['scope_kind'], $descriptor['host_sha256'],
                    $descriptor['root_path_sha256'], $descriptor['root_inode_sha256'],
                ]);
                $count = 0;
                while (true) {
                    $rows = $this->nodeSet->nextReceiptPage($index, $nodeClaims[$index], $this->pageSize);
                    self::assertPage($rows, $this->pageSize);
                    if ($rows === []) {
                        self::digestField($digest, 'EOF');
                        $nodeReceipts[] = [
                            ...$descriptor,
                            'row_count' => $count,
                            'transcript_sha256' => hash_final($digest),
                            'observed_empty_page' => true,
                        ];
                        break;
                    }
                    $count = self::increasedCount($count, count($rows));
                    foreach ($rows as $row) {
                        self::assertNodeRow($row, $descriptor);
                        self::digestRow($digest, $row, self::NODE_ROW_FIELDS);
                    }
                }
            }
        } catch (\Throwable) {
            // Scanner exceptions may contain raw host paths. Never expose the
            // underlying exception, a partial receipt, or its previous cause.
            throw new \RuntimeException('Cutover physical EOF enumeration failed closed.');
        }

        return ['tier_scopes' => $tierReceipts, 'node_scopes' => $nodeReceipts];
    }

    /** @param array<array-key, mixed> $rows */
    private static function assertPage(array $rows, int $limit): void
    {
        if (! array_is_list($rows) || count($rows) > $limit) {
            throw new \UnexpectedValueException('Physical inventory page is invalid.');
        }
    }

    private static function increasedCount(int $count, int $pageCount): int
    {
        if ($count > PHP_INT_MAX - $pageCount) {
            throw new \OverflowException('Physical inventory row count overflowed.');
        }
        return $count + $pageCount;
    }

    /** @param array<string, mixed> $row */
    private static function assertTierRow(array $row, string $tier): void
    {
        self::assertKeys($row, self::TIER_ROW_FIELDS);
        if ($row['storage_tier'] !== $tier) {
            throw new \UnexpectedValueException('Physical tier row belongs to another scope.');
        }
        self::assertCommonRow($row);
        if ($row['multipart_scope_sha256'] !== null) {
            self::assertDigest($row['multipart_scope_sha256']);
        }
        if ($row['type'] === 'directory' && $row['format'] !== null) {
            throw new \UnexpectedValueException('Physical directory has a format.');
        }
        if ($row['type'] === 'file' && ! in_array($row['format'], self::FORMATS, true)) {
            throw new \UnexpectedValueException('Physical file format is invalid.');
        }
        if (! in_array($row['basename_prefix_class'], self::PREFIX_CLASSES, true)) {
            throw new \UnexpectedValueException('Physical prefix class is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param array{scope_kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string} $descriptor
     */
    private static function assertNodeRow(array $row, array $descriptor): void
    {
        self::assertKeys($row, self::NODE_ROW_FIELDS);
        foreach ($descriptor as $field => $value) {
            if ($row[$field] !== $value) {
                throw new \UnexpectedValueException('Physical node row belongs to another scope.');
            }
        }
        self::assertCommonRow($row);
        if (! in_array($row['prefix_class'], self::PREFIX_CLASSES, true)
            || ! is_bool($row['blocking_observation'])
            || $row['blocking_observation'] !== ($row['type'] === 'file')) {
            throw new \UnexpectedValueException('Physical node observation is invalid.');
        }
    }

    /** @param array<string, mixed> $row */
    private static function assertCommonRow(array $row): void
    {
        self::assertDigest($row['path_sha256']);
        self::assertDigest($row['relative_path_sha256']);
        if (! in_array($row['type'], ['file', 'directory'], true)
            || ($row['type'] === 'file' && (! is_int($row['size_bytes']) || $row['size_bytes'] < 0))
            || ($row['type'] === 'directory' && $row['size_bytes'] !== null)) {
            throw new \UnexpectedValueException('Physical row type or size is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function assertKeys(array $row, array $fields): void
    {
        if (count($row) !== count($fields)) {
            throw new \UnexpectedValueException('Physical row has an unexpected shape.');
        }
        foreach ($fields as $field) {
            if (! array_key_exists($field, $row)) {
                throw new \UnexpectedValueException('Physical row is incomplete.');
            }
        }
    }

    /** @param array<string, mixed> $descriptor */
    private static function assertDescriptor(array $descriptor): void
    {
        self::assertKeys($descriptor, ['scope_kind', 'host_sha256', 'root_path_sha256', 'root_inode_sha256']);
        if (! in_array($descriptor['scope_kind'], ['node_scratch', 'historical_temp'], true)) {
            throw new \UnexpectedValueException('Physical node scope kind is invalid.');
        }
        foreach (['host_sha256', 'root_path_sha256', 'root_inode_sha256'] as $field) {
            self::assertDigest($descriptor[$field]);
        }
    }

    private static function assertDigest(mixed $value): void
    {
        if (! is_string($value) || preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
            throw new \UnexpectedValueException('Physical identity digest is invalid.');
        }
    }

    /**
     * @param array<string, mixed> $row
     * @param list<string> $fields
     */
    private static function digestRow(\HashContext $digest, array $row, array $fields): void
    {
        foreach ($fields as $field) {
            self::digestField($digest, $row[$field]);
        }
    }

    /** @param list<string> $fields */
    private static function digestFields(\HashContext $digest, array $fields): void
    {
        foreach ($fields as $field) {
            self::digestField($digest, $field);
        }
    }

    private static function digestField(\HashContext $digest, string|int|bool|null $value): void
    {
        if ($value === null) {
            hash_update($digest, 'N;');
        } elseif (is_int($value)) {
            hash_update($digest, 'I' . $value . ';');
        } elseif (is_bool($value)) {
            hash_update($digest, $value ? 'B1;' : 'B0;');
        } else {
            hash_update($digest, 'S' . strlen($value) . ':' . $value . ';');
        }
    }
}
