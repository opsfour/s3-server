<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Cutover;

use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator;

/**
 * Records observed empty SQL pages for the twelve declared metadata sources.
 * This is a transcript of one uninterrupted enumeration, not a database
 * snapshot, physical correlation, ciphertext proof or release decision.
 */
final class CutoverSqlEofReceiptCollector
{
    private const int PAGE_SIZE = 500;

    private const int MAX_FIELD_BYTES = 4_096;

    private const array INTEGER_ID_SOURCES = [
        CutoverPathReferenceEnumerator::OBJECT,
        CutoverPathReferenceEnumerator::RESTORED_OBJECT,
        CutoverPathReferenceEnumerator::PART,
        CutoverPathReferenceEnumerator::GARBAGE,
        CutoverPathReferenceEnumerator::TIER_TRANSITION_SOURCE,
        CutoverPathReferenceEnumerator::TIER_TRANSITION_TARGET,
        CutoverPathReferenceEnumerator::RESTORE_SOURCE,
        CutoverPathReferenceEnumerator::RESTORE_TARGET,
    ];

    private const array ROW_FIELDS = [
        'source', 'id', 'storage_tier', 'path_sha256', 'relative_path_sha256',
        'sse_mode', 'part_format', 'operation', 'state', 'blocking_anomaly',
    ];

    public function __construct(private readonly CutoverPathReferenceEnumerator $enumerator) {}

    /**
     * @return array<string, array{row_count: int, transcript_sha256: string, observed_empty_page: true}>
     */
    public function collect(): array
    {
        $receipt = [];
        try {
            foreach (CutoverPathReferenceEnumerator::SOURCES as $source) {
                $cursor = null;
                $count = 0;
                $digest = hash_init('sha256');
                self::digestField($digest, 'opsfour-cutover-sql-eof-v1');
                self::digestField($digest, $source);
                while (true) {
                    $rows = $this->enumerator->page($source, $cursor, self::PAGE_SIZE);
                    if (! array_is_list($rows) || count($rows) > self::PAGE_SIZE) {
                        throw new \UnexpectedValueException('Cutover SQL page exceeded its limit.');
                    }
                    if ($rows === []) {
                        $receipt[$source] = [
                            'row_count' => $count,
                            'transcript_sha256' => hash_final($digest),
                            'observed_empty_page' => true,
                        ];
                        break;
                    }
                    if ($count > PHP_INT_MAX - count($rows)) {
                        throw new \OverflowException('Cutover SQL row count overflowed.');
                    }
                    foreach ($rows as $row) {
                        self::assertRow($row, $source, $cursor);
                        foreach (self::ROW_FIELDS as $field) {
                            self::digestField($digest, $row[$field]);
                        }
                        $cursor = $row['id'];
                    }
                    $count += count($rows);
                }
            }
        } catch (\Throwable) {
            // Database drivers and test doubles can include raw IDs or paths
            // in exception messages. Never expose a partial receipt or cause.
            throw new \RuntimeException('Cutover SQL EOF enumeration failed closed.');
        }

        return $receipt;
    }

    /** @param array<string, mixed> $row */
    private static function assertRow(array $row, string $source, ?string $cursor): void
    {
        if (count($row) !== count(self::ROW_FIELDS)) {
            throw new \UnexpectedValueException('Cutover SQL row has an unexpected shape.');
        }
        foreach (self::ROW_FIELDS as $field) {
            if (! array_key_exists($field, $row)) {
                throw new \UnexpectedValueException('Cutover SQL row is incomplete.');
            }
        }
        $id = $row['id'];
        if ($row['source'] !== $source || ! is_string($id) || $id === '' || strlen($id) > 255
            || str_contains($id, "\0")) {
            throw new \UnexpectedValueException('Cutover SQL row identity is invalid.');
        }
        if (in_array($source, self::INTEGER_ID_SOURCES, true)) {
            if (preg_match('/^(?:0|-?[1-9][0-9]*)$/D', $id) !== 1) {
                throw new \UnexpectedValueException('Cutover SQL integer ID is invalid.');
            }
            $number = filter_var($id, FILTER_VALIDATE_INT);
            $previous = $cursor === null ? null : filter_var($cursor, FILTER_VALIDATE_INT);
            if (! is_int($number) || ($cursor !== null && (! is_int($previous) || $number <= $previous))) {
                throw new \UnexpectedValueException('Cutover SQL integer IDs are not strictly increasing.');
            }
        } elseif ($cursor !== null && strcmp($id, $cursor) <= 0) {
            throw new \UnexpectedValueException('Cutover SQL string IDs are not strictly increasing.');
        }
        foreach (self::ROW_FIELDS as $field) {
            $value = $row[$field];
            if ($field === 'source' || $field === 'id') {
                continue;
            }
            if ($value !== null && ! is_string($value)) {
                throw new \UnexpectedValueException('Cutover SQL row field is invalid.');
            }
            if ($value !== null && strlen($value) > self::MAX_FIELD_BYTES) {
                throw new \UnexpectedValueException('Cutover SQL row field is too large.');
            }
            if (in_array($field, ['path_sha256', 'relative_path_sha256'], true)
                && $value !== null && preg_match('/^[a-f0-9]{64}$/D', $value) !== 1) {
                throw new \UnexpectedValueException('Cutover SQL path digest is invalid.');
            }
        }
    }

    private static function digestField(\HashContext $digest, ?string $value): void
    {
        if ($value === null) {
            hash_update($digest, 'N;');
            return;
        }
        hash_update($digest, 'S' . strlen($value) . ':' . $value . ';');
    }
}
