<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\LinuxMountInfo;

/**
 * Read-only observations from explicitly listed private node-local roots.
 * The caller must supply every current and historical root on every host;
 * neither this class nor its tier-root guard can discover omitted roots.
 * An omitted root blocks cutover. Observations never certify SSE or release.
 */
final class NodeLocalPhysicalInventorySet
{
    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    /** @var list<array{kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string, inventory: LocalTierPhysicalInventory}> */
    private readonly array $scopes;

    /** @var array<string, array{root_path_sha256: string, root_inode_sha256: string}> */
    private readonly array $configuredTierRootDigests;

    /**
     * @param list<array{kind: string, root: string}> $localRoots
     * @param array<string, string> $tierRoots Complete configured local-tier roots.
     * @param array<string, EfsHostEvidenceReader> $efsEvidenceByTier
     * @param Closure(): (string|false)|null $mountInfoReader
     * @param (Closure(string): (array{dev: int, ino: int}|false))|null $localRootIdentityReader Test seam.
     */
    public function __construct(
        string $hostIdentity,
        array $localRoots,
        array $tierRoots,
        array $efsEvidenceByTier = [],
        ?Closure $mountInfoReader = null,
        ?Closure $localRootIdentityReader = null,
    ) {
        if ($hostIdentity === '' || str_contains($hostIdentity, "\0")
            || $localRoots === [] || ! array_is_list($localRoots) || $tierRoots === []) {
            throw new \InvalidArgumentException('Node-local inventory requires a host and explicit roots.');
        }
        foreach ($efsEvidenceByTier as $tier => $_evidence) {
            if (! array_key_exists($tier, $tierRoots)) {
                throw new \InvalidArgumentException('Node-local inventory evidence belongs to an unknown tier.');
            }
        }

        $reader = $mountInfoReader ?? self::nativeMountInfo(...);
        $mountInfo = $reader();
        if (! is_string($mountInfo) || $mountInfo === '' || strlen($mountInfo) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('Node-local inventory mount table is unavailable.');
        }

        $guardRoots = $tierRoots;
        /** @var list<array{kind: string, key: string, root: string}> $requested */
        $requested = [];
        foreach ($localRoots as $index => $scope) {
            $kind = $scope['kind'] ?? null;
            $root = $scope['root'] ?? null;
            if (! in_array($kind, ['node_scratch', 'historical_temp'], true) || ! is_string($root)) {
                throw new \InvalidArgumentException('Invalid node-local inventory scope.');
            }
            $key = '__node_local_physical_inventory_' . $index;
            if (array_key_exists($key, $guardRoots)) {
                throw new \InvalidArgumentException('Node-local inventory scope conflicts with a tier name.');
            }
            $type = LinuxMountInfo::filesystemTypeForPath($root, $mountInfo);
            if ($type === null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type)) {
                throw new \RuntimeException('Node-local inventory requires a qualified local filesystem.');
            }
            $guardRoots[$key] = $root;
            $requested[] = ['kind' => $kind, 'key' => $key, 'root' => $root];
        }

        // Reuse the tier scanner's canonical path, mount, disjointness and
        // dev+inode alias checks across both tier and node-local roots.
        $guard = new LocalTierPhysicalInventorySet(
            $guardRoots,
            $efsEvidenceByTier,
            $reader,
            $localRootIdentityReader,
        );
        $configuredTierRootDigests = [];
        foreach ($tierRoots as $tier => $_root) {
            $inventory = $guard->inventory($tier);
            $configuredTierRootDigests[$tier] = [
                'root_path_sha256' => $inventory->rootPathSha256(),
                'root_inode_sha256' => $inventory->rootInodeSha256(),
            ];
        }
        $this->configuredTierRootDigests = $configuredTierRootDigests;
        $scopes = [];
        foreach ($requested as $scope) {
            $inventory = $guard->inventory($scope['key']);
            $stat = @lstat($scope['root']);
            $uid = function_exists('posix_geteuid') ? posix_geteuid() : false;
            if (! is_array($stat) || ! is_int($uid)
                || ($stat['mode'] & 0o170000) !== 0o040000
                || $stat['uid'] !== $uid || ($stat['mode'] & 0o077) !== 0
                || ($stat['mode'] & 0o500) !== 0o500
                || ! is_int($stat['dev'] ?? null) || ! is_int($stat['ino'] ?? null)) {
                throw new \RuntimeException('Node-local inventory root must be a private, readable, process-owned directory.');
            }
            $inodeDigest = hash('sha256', $stat['dev'] . ':' . $stat['ino']);
            if ($inodeDigest !== $inventory->rootInodeSha256()) {
                throw new \RuntimeException('Node-local inventory root changed during binding.');
            }
            $scopes[] = [
                'kind' => $scope['kind'],
                'host_sha256' => hash('sha256', $hostIdentity),
                'root_path_sha256' => $inventory->rootPathSha256(),
                'root_inode_sha256' => $inodeDigest,
                'inventory' => $inventory,
            ];
        }
        $this->scopes = $scopes;
    }

    public function count(): int
    {
        return count($this->scopes);
    }

    /**
     * Bound tier guard identity for matching against the separately scanned tier set.
     * Tier names remain internal; no raw root path is returned.
     *
     * @return array<string, array{root_path_sha256: string, root_inode_sha256: string}>
     */
    public function configuredTierRootDigests(): array
    {
        return $this->configuredTierRootDigests;
    }

    /**
     * Bound identity of a configured scope, including an empty directory.
     * This says nothing about scan completion or snapshot authenticity.
     *
     * @return array{scope_kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string}
     */
    public function scopeDescriptor(int $scopeIndex): array
    {
        $scope = $this->scopes[$scopeIndex] ?? throw new \InvalidArgumentException('Unknown node-local inventory scope.');

        return [
            'scope_kind' => $scope['kind'],
            'host_sha256' => $scope['host_sha256'],
            'root_path_sha256' => $scope['root_path_sha256'],
            'root_inode_sha256' => $scope['root_inode_sha256'],
        ];
    }

    /** Reserve a fresh node-local scanner for one EOF receipt. */
    public function claimScopeForEofReceipt(int $scopeIndex): object
    {
        $scope = $this->scopes[$scopeIndex] ?? throw new \InvalidArgumentException('Unknown node-local inventory scope.');
        return $scope['inventory']->claimForEofReceipt();
    }

    /**
     * Every file is a blocker observation. A directory is an observation only;
     * empty directories do not themselves establish stored object bytes.
     *
     * @return list<array{scope_kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string, path_sha256: string, relative_path_sha256: string, type: string, size_bytes: ?int, prefix_class: string, blocking_observation: bool}>
     */
    public function nextPage(int $scopeIndex, int $limit = 100): array
    {
        $scope = $this->scopes[$scopeIndex] ?? throw new \InvalidArgumentException('Unknown node-local inventory scope.');
        return self::mapRows($scope, $scope['inventory']->nextPage($limit));
    }

    /**
     * @return list<array{scope_kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string, path_sha256: string, relative_path_sha256: string, type: string, size_bytes: ?int, prefix_class: string, blocking_observation: bool}>
     */
    public function nextReceiptPage(int $scopeIndex, object $claim, int $limit = 100): array
    {
        $scope = $this->scopes[$scopeIndex] ?? throw new \InvalidArgumentException('Unknown node-local inventory scope.');
        return self::mapRows($scope, $scope['inventory']->nextReceiptPage($claim, $limit));
    }

    /**
     * @param array{kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string, inventory: LocalTierPhysicalInventory} $scope
     * @param list<array{storage_tier: string, path_sha256: string, relative_path_sha256: string, multipart_scope_sha256: ?string, type: string, size_bytes: ?int, format: ?string, basename_prefix_class: string}> $entries
     * @return list<array{scope_kind: string, host_sha256: string, root_path_sha256: string, root_inode_sha256: string, path_sha256: string, relative_path_sha256: string, type: string, size_bytes: ?int, prefix_class: string, blocking_observation: bool}>
     */
    private static function mapRows(array $scope, array $entries): array
    {
        $rows = [];
        foreach ($entries as $entry) {
            // Prefixes are checked against the basename only. A nested file
            // remains visible, but does not inherit a parent prefix class.
            $rows[] = [
                'scope_kind' => $scope['kind'],
                'host_sha256' => $scope['host_sha256'],
                'root_path_sha256' => $scope['root_path_sha256'],
                'root_inode_sha256' => $scope['root_inode_sha256'],
                'path_sha256' => $entry['path_sha256'],
                'relative_path_sha256' => $entry['relative_path_sha256'],
                'type' => $entry['type'],
                'size_bytes' => $entry['size_bytes'],
                'prefix_class' => $entry['basename_prefix_class'],
                'blocking_observation' => $entry['type'] === 'file',
            ];
        }
        return $rows;
    }

    private static function nativeMountInfo(): string|false
    {
        $handle = @fopen('/proc/self/mountinfo', 'rb');
        if ($handle === false) {
            return false;
        }
        try {
            $raw = stream_get_contents($handle, self::MAX_MOUNTINFO_BYTES + 1);
            return is_string($raw) ? $raw : false;
        } finally {
            fclose($handle);
        }
    }
}
