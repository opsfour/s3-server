<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\LinuxMountInfo;

/**
 * Bind a complete set of disjoint local tier roots before offline scanning.
 * This does not snapshot either SQL metadata or the filesystem.
 */
final class LocalTierPhysicalInventorySet
{
    private const int MAX_MOUNTINFO_BYTES = 4_194_304;

    /** @var array<string, LocalTierPhysicalInventory> */
    private readonly array $inventories;

    /**
     * @param array<string, string> $tierRoots
     * @param array<string, EfsHostEvidenceReader> $efsEvidenceByTier
     * @param Closure(): (string|false)|null $mountInfoReader Test seam; production reads /proc/self/mountinfo.
     * @param (Closure(string): (array{dev: int, ino: int}|false))|null $localRootIdentityReader Test seam; never called for NFS.
     */
    public function __construct(
        array $tierRoots,
        array $efsEvidenceByTier = [],
        ?Closure $mountInfoReader = null,
        ?Closure $localRootIdentityReader = null,
    ) {
        if ($tierRoots === []) {
            throw new \InvalidArgumentException('Physical inventory needs at least one local tier root.');
        }
        $reader = $mountInfoReader ?? self::nativeMountInfo(...);
        $mountInfo = $reader();
        if (! is_string($mountInfo) || $mountInfo === '' || strlen($mountInfo) > self::MAX_MOUNTINFO_BYTES) {
            throw new \RuntimeException('Physical inventory mount table is unavailable.');
        }

        /** @var array<string, string> $canonicalRoots */
        $canonicalRoots = [];
        /** @var array<string, string> $rootIdentities */
        $rootIdentities = [];
        $localRootIdentityReader ??= static function (string $root): array|false {
            clearstatcache(true, $root);
            return @lstat($root);
        };
        foreach ($tierRoots as $tier => $root) {
            if (! is_string($tier) || $tier === '' || str_contains($tier, "\0") || ! is_string($root)) {
                throw new \InvalidArgumentException('Invalid physical inventory tier configuration.');
            }
            self::assertRootSpelling($root);
            $type = LinuxMountInfo::filesystemTypeForPath($root, $mountInfo);
            if ($type === null) {
                throw new \RuntimeException('Physical inventory root mount is unknown.');
            }
            $evidence = $efsEvidenceByTier[$tier] ?? null;
            if (in_array($type, ['nfs', 'nfs4'], true)) {
                if (! $evidence instanceof EfsHostEvidenceReader || $evidence->storageRoot() !== $root) {
                    throw new \RuntimeException('NFS physical inventory requires exact per-tier host evidence.');
                }
                $evidence->assertFresh();
            } elseif ($evidence !== null || ! LinuxMountInfo::isQualifiedLocalFilesystem($type)) {
                throw new \RuntimeException('Unsupported physical inventory tier mount or evidence.');
            }
            if (realpath($root) !== $root || ! is_dir($root)) {
                throw new \RuntimeException('Physical inventory root must be an existing canonical directory.');
            }
            if ($evidence instanceof EfsHostEvidenceReader) {
                // The host watcher pinned these values at startup. Do not add
                // an NFS stat merely to detect a bind-mount alias.
                $scope = $evidence->workerScope();
                $identity = $scope->storageDev . ':' . $scope->storageIno;
            } else {
                $stat = $localRootIdentityReader($root);
                if (! is_array($stat) || ! is_int($stat['dev'] ?? null)
                    || ! is_int($stat['ino'] ?? null) || $stat['ino'] < 1) {
                    throw new \RuntimeException('Physical inventory root identity is unavailable.');
                }
                $identity = $stat['dev'] . ':' . $stat['ino'];
            }
            foreach ($canonicalRoots as $existingRoot) {
                if ($root === $existingRoot || str_starts_with($root, $existingRoot . '/')
                    || str_starts_with($existingRoot, $root . '/')) {
                    throw new \RuntimeException('Physical inventory tier roots overlap.');
                }
            }
            if (in_array($identity, $rootIdentities, true)) {
                throw new \RuntimeException('Physical inventory tier roots share an inode.');
            }
            $canonicalRoots[$tier] = $root;
            $rootIdentities[$tier] = $identity;
        }
        foreach ($efsEvidenceByTier as $tier => $evidence) {
            if (! isset($canonicalRoots[$tier]) || ! $evidence instanceof EfsHostEvidenceReader) {
                throw new \InvalidArgumentException('Unexpected physical inventory host evidence tier.');
            }
        }

        $inventories = [];
        foreach ($canonicalRoots as $tier => $root) {
            $inventories[$tier] = new LocalTierPhysicalInventory(
                $tier,
                $root,
                $reader,
                $efsEvidenceByTier[$tier] ?? null,
            );
        }
        $this->inventories = $inventories;
    }

    /** @return list<string> */
    public function tiers(): array
    {
        return array_keys($this->inventories);
    }

    public function inventory(string $tier): LocalTierPhysicalInventory
    {
        return $this->inventories[$tier] ?? throw new \InvalidArgumentException('Unknown physical inventory tier.');
    }

    private static function assertRootSpelling(string $root): void
    {
        if (! str_starts_with($root, '/') || $root === '/' || str_ends_with($root, '/')
            || str_contains($root, "\0") || str_contains($root, '//')
            || preg_match('~/(?:\.|\.\.)($|/)~', $root) === 1) {
            throw new \InvalidArgumentException('Invalid physical inventory root spelling.');
        }
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
