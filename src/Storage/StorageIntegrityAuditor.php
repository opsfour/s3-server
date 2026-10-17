<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\StorageIntegrityReferenceEnumerator;

/** Offline, read-only verification of metadata references against a local root. */
final class StorageIntegrityAuditor
{
    private readonly string $root;

    public function __construct(
        string $storageRoot,
        private readonly StorageIntegrityReferenceEnumerator $references,
        private readonly int $pageSize = 100,
        private readonly int $maxReportedIssues = 100,
    ) {
        $canonical = realpath($storageRoot);
        if (! str_starts_with($storageRoot, '/') || $canonical === false || $canonical !== rtrim($storageRoot, '/')
            || is_link($storageRoot) || ! is_dir($storageRoot) || $pageSize < 1 || $pageSize > 500
            || $maxReportedIssues < 0 || $maxReportedIssues > 10_000) {
            throw new \InvalidArgumentException('Storage audit requires a canonical absolute local directory and valid limits.');
        }
        $this->root = $canonical;
    }

    public function run(): StorageIntegrityAuditResult
    {
        $checked = 0;
        $failed = 0;
        $counts = [];
        $issues = [];

        foreach (StorageIntegrityReferenceEnumerator::SOURCES as $source) {
            $counts[$source] = 0;
            $afterId = null;
            do {
                $page = $this->references->page($source, $afterId, $this->pageSize);
                if (count($page) > $this->pageSize) {
                    throw new \UnexpectedValueException('Storage audit inventory exceeded its page size.');
                }
                foreach ($page as $reference) {
                    ++$checked;
                    $counts[$source] = ($counts[$source] ?? 0) + 1;
                    $reason = $this->inspect($reference['path'], $reference['expected_size']);
                    if ($reason !== null) {
                        ++$failed;
                        if (count($issues) < $this->maxReportedIssues) {
                            $issues[] = [
                                'source' => $source,
                                'reference_sha256' => hash('sha256', $source . "\0" . $reference['id']),
                                'path_sha256' => hash('sha256', $reference['path']),
                                'reason' => $reason,
                            ];
                        }
                    }
                }
                $next = $page === [] ? null : $page[array_key_last($page)]['id'];
                if ($next !== null && $next === $afterId) {
                    throw new \UnexpectedValueException('Storage audit inventory cursor did not advance.');
                }
                $afterId = $next;
            } while (count($page) === $this->pageSize);
        }

        return new StorageIntegrityAuditResult($checked, $failed, $counts, $issues);
    }

    private function inspect(string $path, ?int $expectedSize): ?string
    {
        $prefix = $this->root . '/';
        if (! str_starts_with($path, $prefix) || str_contains($path, "\0") || str_contains($path, '\\')) {
            return 'invalid_path';
        }
        $relative = substr($path, strlen($prefix));
        $parts = explode('/', $relative);
        if ($relative === '' || in_array('', $parts, true) || in_array('.', $parts, true) || in_array('..', $parts, true)) {
            return 'invalid_path';
        }

        $candidate = $this->root;
        $last = array_key_last($parts);
        foreach ($parts as $index => $part) {
            $candidate .= '/' . $part;
            $stat = @lstat($candidate);
            if ($stat === false) {
                return 'missing';
            }
            $type = $stat['mode'] & 0170000;
            if ($type === 0120000) {
                return 'symlink';
            }
            if ($index !== $last && $type !== 0040000) {
                return 'invalid_path';
            }
            if ($index === $last && $type !== 0100000) {
                return 'not_regular_file';
            }
            if ($index === $last && $expectedSize !== null && $stat['size'] !== $expectedSize) {
                return 'size_mismatch';
            }
        }

        return null;
    }
}
