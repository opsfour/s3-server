<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Closure;
use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator;

/** Read-only stopped-profile comparison of local files with every durable path reference. */
final class StorageOrphanInventory
{
    public const int MAX_REPORTED_CANDIDATES = 500;

    /** @param array<string, string> $tierRoots */
    public function __construct(
        private readonly array $tierRoots,
        private readonly string $defaultStorageTier,
        private readonly CutoverPathReferenceEnumerator $references,
        private readonly int $pageSize = 500,
        private readonly bool $revealPaths = false,
        /** @var list<string> */
        private readonly array $excludedPathHashes = [],
        /** @var (Closure(): (string|false))|null Test seam; production reads /proc/self/mountinfo. */
        private readonly ?Closure $mountInfoReader = null,
        private readonly string $scratchPath = '',
        private readonly int $scratchMaxBytes = 1_073_741_824,
        /** @var array{storage_tier: string, path_sha256: string}|null */
        private readonly ?array $afterCandidate = null,
    ) {
        if ($pageSize < 1 || $pageSize > 500) {
            throw new \InvalidArgumentException('Storage inventory page size must be 1..500.');
        }
        if ($defaultStorageTier === '' || ! array_key_exists($defaultStorageTier, $tierRoots)) {
            throw new \InvalidArgumentException('Storage inventory requires a mapped default storage tier.');
        }
    }

    public function run(): StorageInventoryResult
    {
        $scratchPath = $this->scratchPath !== '' ? $this->scratchPath : (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir());
        $referenceIndex = new StorageReferenceIndex($scratchPath, $this->scratchMaxBytes, $this->tierRoots);
        $referenceCount = 0;
        $completed = false;
        try {
            foreach (CutoverPathReferenceEnumerator::SOURCES as $source) {
                $after = null;
                do {
                    $page = $this->references->page($source, $after, $this->pageSize);
                    $indexed = [];
                    foreach ($page as $reference) {
                        if ($reference['blocking_anomaly'] !== null) {
                            throw new \RuntimeException('Metadata path inventory contains a blocking anomaly.');
                        }
                        if ($referenceCount === PHP_INT_MAX) {
                            throw new \OverflowException('Metadata path inventory count overflowed.');
                        }
                        ++$referenceCount;
                        $tier = $reference['storage_tier'] ?? $this->defaultStorageTier;
                        if (! array_key_exists($tier, $this->tierRoots)) {
                            throw new \RuntimeException('Metadata path inventory references an unmapped storage tier.');
                        }
                        $indexed[] = [
                            'tier' => $tier,
                            'path_sha256' => $reference['path_sha256'],
                            'relative_path_sha256' => $reference['relative_path_sha256'],
                        ];
                    }
                    $referenceIndex->addPage($indexed);
                    $next = $page === [] ? null : $page[array_key_last($page)]['id'];
                    if ($next !== null && $next === $after) {
                        throw new \RuntimeException('Metadata path inventory cursor did not advance.');
                    }
                    $after = $next;
                } while (count($page) === $this->pageSize);
            }

            // Bind the physical view only after read-only metadata access has
            // settled SQLite sidecars and the exact reference index is complete.
            $inventories = new LocalTierPhysicalInventorySet(
                $this->tierRoots,
                $this->mountInfoReader,
                revealPaths: $this->revealPaths,
                strictFormatValidation: false,
            );
            $excluded = array_fill_keys($this->excludedPathHashes, true);
            $physicalFiles = 0;
            $candidateCount = 0;
            foreach ($inventories->tiers() as $tier) {
                $inventory = $inventories->inventory($tier);
                do {
                    $page = $inventory->nextPage($this->pageSize);
                    $candidatePage = [];
                    foreach ($page as $physical) {
                        if ($physical['type'] !== 'file') {
                            continue;
                        }
                        if ($physicalFiles === PHP_INT_MAX) {
                            throw new \OverflowException('Physical storage inventory count overflowed.');
                        }
                        ++$physicalFiles;
                        $scope = $physical['multipart_scope_sha256'];
                        if (isset($excluded[$physical['path_sha256']])
                            || $referenceIndex->containsPath($tier, $physical['path_sha256'])
                            || ($scope !== null && $referenceIndex->containsScope($tier, $scope))) {
                            continue;
                        }
                        if ($candidateCount === PHP_INT_MAX) {
                            throw new \OverflowException('Storage orphan candidate count overflowed.');
                        }
                        ++$candidateCount;
                        $candidatePage[] = [
                            'storage_tier' => $tier,
                            'path_sha256' => $physical['path_sha256'],
                            'size_bytes' => $physical['size_bytes'],
                            'format' => $physical['format'],
                            'path' => $this->revealPaths && isset($physical['path']) && is_string($physical['path'])
                                ? $physical['path']
                                : null,
                        ];
                    }
                    $referenceIndex->addCandidatePage($candidatePage);
                } while ($page !== []);
            }

            [$candidates, $nextCandidate] = $referenceIndex->candidatePage(
                $this->afterCandidate,
                self::MAX_REPORTED_CANDIDATES,
            );

            $result = new StorageInventoryResult(
                $physicalFiles,
                $referenceCount,
                $candidateCount,
                $candidates,
                $nextCandidate,
            );
            $completed = true;

            return $result;
        } finally {
            if ($completed) {
                $referenceIndex->closeOrFail();
            } else {
                $referenceIndex->close();
            }
        }
    }
}
