<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Metadata\StorageIntegrityReferenceEnumerator;
use OpsFour\S3Server\Storage\StorageIntegrityAuditor;
use PHPUnit\Framework\TestCase;

final class StorageIntegrityAuditorTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s3-storage-audit-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o700, true));
        $canonical = realpath($this->root);
        self::assertNotFalse($canonical);
        $this->root = $canonical;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->root);
    }

    public function test_audit_pages_all_sources_and_accepts_regular_files_with_matching_sizes(): void
    {
        $rows = [];
        foreach (StorageIntegrityReferenceEnumerator::SOURCES as $sourceIndex => $source) {
            for ($index = 1; $index <= 3; ++$index) {
                $path = $this->root . '/' . $sourceIndex . '-' . $index;
                self::assertSame(3, file_put_contents($path, 'abc'));
                $rows[$source][] = $this->row($source, (string) $index, $path, 3);
            }
        }
        $enumerator = new InMemoryStorageIntegrityReferenceEnumerator($rows);

        $result = (new StorageIntegrityAuditor($this->root, $enumerator, 2))->run();

        self::assertSame(15, $result->checked);
        self::assertSame(0, $result->failed);
        self::assertSame(array_fill_keys(StorageIntegrityReferenceEnumerator::SOURCES, 3), $result->sourceCounts);
        self::assertGreaterThan(5, $enumerator->pageCalls);
    }

    public function test_audit_reports_missing_size_traversal_symlink_and_non_file_without_disclosing_paths(): void
    {
        $mismatch = $this->root . '/mismatch-secret';
        self::assertSame(3, file_put_contents($mismatch, 'abc'));
        $target = $this->root . '/target-secret';
        self::assertSame(3, file_put_contents($target, 'abc'));
        $link = $this->root . '/link-secret';
        self::assertTrue(symlink($target, $link));
        $directory = $this->root . '/directory-secret';
        self::assertTrue(mkdir($directory, 0o700));
        $realParent = $this->root . '/real-parent';
        self::assertTrue(mkdir($realParent, 0o700));
        self::assertSame(3, file_put_contents($realParent . '/nested-secret', 'abc'));
        $linkedParent = $this->root . '/linked-parent';
        self::assertTrue(symlink($realParent, $linkedParent));
        $paths = [
            [$this->root . '/missing-secret', 1],
            [$mismatch, 4],
            [$this->root . '/../escape-secret', null],
            [$link, 3],
            [$directory, null],
            [$linkedParent . '/nested-secret', 3],
        ];
        $rows = [StorageIntegrityReferenceEnumerator::OBJECT => []];
        foreach ($paths as $index => [$path, $size]) {
            $rows[StorageIntegrityReferenceEnumerator::OBJECT][] = $this->row(
                StorageIntegrityReferenceEnumerator::OBJECT,
                (string) ($index + 1),
                $path,
                $size,
            );
        }

        $result = (new StorageIntegrityAuditor(
            $this->root,
            new InMemoryStorageIntegrityReferenceEnumerator($rows),
            maxReportedIssues: 10,
        ))->run();

        self::assertSame(6, $result->checked);
        self::assertSame(6, $result->failed);
        self::assertSame(
            ['missing', 'size_mismatch', 'invalid_path', 'symlink', 'not_regular_file', 'symlink'],
            array_column($result->reportedIssues, 'reason'),
        );
        foreach ($result->reportedIssues as $issue) {
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $issue['reference_sha256']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $issue['path_sha256']);
            foreach ($paths as [$path]) {
                self::assertStringNotContainsString($path, implode('|', $issue));
            }
        }

        @unlink($link);
        @unlink($linkedParent);
        @rmdir($directory);
        @unlink($realParent . '/nested-secret');
        @rmdir($realParent);
    }

    /** @return array{source: string, id: string, path: string, expected_size: ?int} */
    private function row(string $source, string $id, string $path, ?int $size): array
    {
        return ['source' => $source, 'id' => $id, 'path' => $path, 'expected_size' => $size];
    }
}

/** @internal */
final class InMemoryStorageIntegrityReferenceEnumerator implements StorageIntegrityReferenceEnumerator
{
    public int $pageCalls = 0;

    /** @param array<string, list<array{source: string, id: string, path: string, expected_size: ?int}>> $rows */
    public function __construct(private readonly array $rows) {}

    public function page(string $source, ?string $afterId, int $limit): array
    {
        ++$this->pageCalls;
        $rows = array_values(array_filter(
            $this->rows[$source] ?? [],
            static fn(array $row): bool => $afterId === null || (int) $row['id'] > (int) $afterId,
        ));

        return array_slice($rows, 0, $limit);
    }

    public function close(): void {}
}
