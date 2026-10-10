<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Cutover;

use Closure;
use OpsFour\S3Server\Cutover\CutoverSqlEofReceiptCollector;
use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator;
use PHPUnit\Framework\TestCase;

final class CutoverSqlEofReceiptCollectorTest extends TestCase
{
    public function test_all_twelve_empty_sources_have_observed_eof_and_no_raw_data(): void
    {
        $calls = [];
        $receipt = (new CutoverSqlEofReceiptCollector($this->enumerator(
            static function (string $source, ?string $cursor, int $limit) use (&$calls): array {
                $calls[] = [$source, $cursor, $limit];
                return [];
            },
        )))->collect();

        self::assertSame(CutoverPathReferenceEnumerator::SOURCES, array_keys($receipt));
        self::assertCount(12, $calls);
        foreach ($calls as $index => $call) {
            self::assertSame([CutoverPathReferenceEnumerator::SOURCES[$index], null, 500], $call);
        }
        foreach ($receipt as $row) {
            self::assertSame(0, $row['row_count']);
            self::assertTrue($row['observed_empty_page']);
            self::assertMatchesRegularExpression('/^[a-f0-9]{64}$/D', $row['transcript_sha256']);
        }
    }

    public function test_cursor_is_forwarded_until_actual_empty_page_even_after_short_pages(): void
    {
        $source = CutoverPathReferenceEnumerator::OBJECT;
        $cursors = [];
        $receipt = (new CutoverSqlEofReceiptCollector($this->enumerator(
            function (string $current, ?string $cursor, int $limit) use ($source, &$cursors): array {
                if ($current !== $source) {
                    return [];
                }
                $cursors[] = $cursor;
                return match ($cursor) {
                    null => [$this->row($source, '0')],
                    '0' => [$this->row($source, '2')],
                    '2' => [],
                    default => throw new \RuntimeException('Unexpected cursor.'),
                };
            },
        )))->collect();

        self::assertSame([null, '0', '2'], $cursors);
        self::assertSame(2, $receipt[$source]['row_count']);
        self::assertTrue($receipt[$source]['observed_empty_page']);
        self::assertCount(12, $receipt);
    }

    public function test_oversized_page_fails_without_partial_receipt(): void
    {
        $source = CutoverPathReferenceEnumerator::OBJECT;
        $row = $this->row($source, '1');
        $this->assertClosedFailure($this->enumerator(
            static fn(string $current, ?string $cursor, int $limit): array => $current === $source
                ? array_fill(0, 501, $row) : [],
        ));
    }

    public function test_duplicate_and_out_of_order_integer_ids_fail_closed(): void
    {
        foreach ([['1', '1'], ['2', '1'], ['01', '2']] as $ids) {
            $source = CutoverPathReferenceEnumerator::OBJECT;
            $this->assertClosedFailure($this->enumerator(
                fn(string $current, ?string $cursor, int $limit): array => $current === $source
                    ? [$this->row($source, $ids[0]), $this->row($source, $ids[1])] : [],
            ));
        }
    }

    public function test_string_id_order_is_byte_sensitive_and_cross_page(): void
    {
        $source = CutoverPathReferenceEnumerator::OBJECT_INTENT;
        $this->assertClosedFailure($this->enumerator(
            function (string $current, ?string $cursor, int $limit) use ($source): array {
                if ($current !== $source) {
                    return [];
                }
                return $cursor === null ? [$this->row($source, 'CaseID')] : [$this->row($source, 'caseid'), $this->row($source, 'CaseID')];
            },
        ));
    }

    public function test_row_shape_digest_and_source_are_checked_without_exposing_values(): void
    {
        $source = CutoverPathReferenceEnumerator::PART;
        $valid = $this->row($source, '1');
        $other = $valid;
        $other['path_sha256'] = hash('sha256', 'secret-file-path');
        $first = (new CutoverSqlEofReceiptCollector($this->singleRowEnumerator($source, $valid)))->collect();
        $second = (new CutoverSqlEofReceiptCollector($this->singleRowEnumerator($source, $other)))->collect();
        self::assertNotSame($first[$source]['transcript_sha256'], $second[$source]['transcript_sha256']);
        self::assertStringNotContainsString('secret-file-path', json_encode($second, JSON_THROW_ON_ERROR));

        foreach ([
            array_replace($valid, ['source' => CutoverPathReferenceEnumerator::OBJECT]),
            array_replace($valid, ['path_sha256' => 'not-a-digest']),
            array_replace($valid, ['unexpected' => 'private/path/raw-id']),
        ] as $bad) {
            $this->assertClosedFailure($this->singleRowEnumerator($source, $bad));
        }
    }

    public function test_driver_abort_hides_raw_exception_and_discards_partial_work(): void
    {
        $source = CutoverPathReferenceEnumerator::PART;
        $this->assertClosedFailure($this->enumerator(
            function (string $current, ?string $cursor, int $limit) use ($source): array {
                if ($current !== $source) {
                    return [];
                }
                if ($cursor === null) {
                    return [$this->row($source, '1')];
                }
                throw new \RuntimeException('private/path/raw-id');
            },
        ));
    }

    public function test_ten_thousand_short_pages_stream_with_one_cursor(): void
    {
        $source = CutoverPathReferenceEnumerator::OBJECT;
        $calls = 0;
        $receipt = (new CutoverSqlEofReceiptCollector($this->enumerator(
            function (string $current, ?string $cursor, int $limit) use ($source, &$calls): array {
                if ($current !== $source) {
                    return [];
                }
                $calls++;
                if ($cursor !== null) {
                    self::assertSame((string) ($calls - 1), $cursor);
                }
                return $calls > 10_000 ? [] : [$this->row($source, (string) $calls)];
            },
        )))->collect();

        self::assertSame(10_001, $calls);
        self::assertSame(10_000, $receipt[$source]['row_count']);
        self::assertCount(12, $receipt);
    }

    /**
     * @param Closure(string, ?string, int): list<array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string}> $callback
     */
    private function enumerator(Closure $callback): CutoverPathReferenceEnumerator
    {
        return new class ($callback) implements CutoverPathReferenceEnumerator {
            /** @param Closure(string, ?string, int): list<array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string}> $callback */
            public function __construct(private readonly Closure $callback) {}

            public function page(string $source, ?string $afterId = null, int $limit = 100): array
            {
                return ($this->callback)($source, $afterId, $limit);
            }
        };
    }

    /** @param array{source: string, id: string, storage_tier: ?string, path_sha256: ?string, relative_path_sha256: ?string, sse_mode: ?string, part_format: ?string, operation: ?string, state: ?string, blocking_anomaly: ?string} $row */
    private function singleRowEnumerator(string $source, array $row): CutoverPathReferenceEnumerator
    {
        return $this->enumerator(static fn(string $current, ?string $cursor, int $limit): array
            => $current === $source && $cursor === null ? [$row] : []);
    }

    /** @return array{source: string, id: string, storage_tier: string, path_sha256: string, relative_path_sha256: null, sse_mode: null, part_format: null, operation: null, state: null, blocking_anomaly: null} */
    private function row(string $source, string $id): array
    {
        return [
            'source' => $source,
            'id' => $id,
            'storage_tier' => 'HOT',
            'path_sha256' => hash('sha256', 'object'),
            'relative_path_sha256' => null,
            'sse_mode' => null,
            'part_format' => null,
            'operation' => null,
            'state' => null,
            'blocking_anomaly' => null,
        ];
    }

    private function assertClosedFailure(CutoverPathReferenceEnumerator $enumerator): void
    {
        try {
            (new CutoverSqlEofReceiptCollector($enumerator))->collect();
            self::fail('Invalid SQL inventory produced a receipt.');
        } catch (\RuntimeException $error) {
            self::assertSame('Cutover SQL EOF enumeration failed closed.', $error->getMessage());
            self::assertNull($error->getPrevious());
            self::assertStringNotContainsString('private/path/raw-id', (string) $error);
        }
    }
}
