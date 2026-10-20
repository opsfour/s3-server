<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Processes CSV input for S3 Select.
 */
final class CsvProcessor
{
    /** Maximum number of parsed rows to prevent memory exhaustion. */
    private const int MAX_ROWS = 1_000_000;

    /** Prevent a single short row from expanding into a huge PHP field array. */
    private const int MAX_FIELDS_PER_RECORD = 16_384;

    /**
     * Parse CSV data into rows.
     *
     * @param string $data Raw CSV data.
     * @param string $fieldDelimiter Column delimiter.
     * @param string $recordDelimiter Row delimiter.
     * @param string $quoteCharacter Quote character.
     * @param string $fileHeaderInfo Whether first row is header (USE/IGNORE/NONE).
     * @return array{headers: ?list<string>, rows: list<array<string, string>>}
     */
    public static function parse(
        string $data,
        string $fieldDelimiter = ',',
        string $recordDelimiter = "\n",
        string $quoteCharacter = '"',
        string $fileHeaderInfo = 'NONE',
        ?SelectMemoryGuard $memoryGuard = null,
    ): array {
        self::validateDelimiters($fieldDelimiter, $recordDelimiter, $quoteCharacter);
        $memoryGuard ??= SelectMemoryGuard::start();
        $memoryGuard->check();

        $headers = null;
        $rows = [];
        $firstDataLine = true;

        foreach (self::records($data, $fieldDelimiter, $recordDelimiter, $quoteCharacter) as $line) {
            $memoryGuard->check();
            if (trim($line) === '') {
                continue;
            }

            self::assertFieldCount($line, $fieldDelimiter, $quoteCharacter);
            $fields = str_getcsv($line, $fieldDelimiter, $quoteCharacter, '');

            if ($firstDataLine && $fileHeaderInfo === 'USE') {
                $headers = array_map(static fn(?string $field): string => $field ?? '', $fields);
                $firstDataLine = false;
                continue;
            }

            if ($firstDataLine && $fileHeaderInfo === 'IGNORE') {
                $firstDataLine = false;
                continue;
            }
            $firstDataLine = false;

            if (count($rows) >= self::MAX_ROWS) {
                throw new SelectMemoryLimitException(
                    'S3 Select: CSV input exceeds maximum row count (' . self::MAX_ROWS . ').',
                );
            }

            $row = [];
            foreach ($fields as $j => $field) {
                $colName = $headers !== null && isset($headers[$j]) ? $headers[$j] : '_' . ($j + 1);
                $row[$colName] = $field ?? '';
            }
            $rows[] = $row;
            $memoryGuard->check();
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Format rows back to CSV.
     *
     * @param list<array<string, mixed>> $rows
     * @param list<string>|null $columns Specific columns to output.
     */
    public static function format(
        array $rows,
        ?array $columns = null,
        string $fieldDelimiter = ',',
        string $recordDelimiter = "\n",
        string $quoteCharacter = '"',
        ?SelectMemoryGuard $memoryGuard = null,
    ): string {
        self::validateDelimiters($fieldDelimiter, $recordDelimiter, $quoteCharacter);
        $memoryGuard ??= SelectMemoryGuard::start();

        $output = '';

        foreach ($rows as $row) {
            $memoryGuard->check();
            $values = [];
            if ($columns !== null) {
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? '';
                }
            } else {
                $values = array_values($row);
            }

            $quoted = array_map(function (mixed $value) use ($fieldDelimiter, $quoteCharacter, $recordDelimiter): string {
                $v = self::formatValue($value);
                if (str_contains($v, $fieldDelimiter) || str_contains($v, $quoteCharacter) || str_contains($v, $recordDelimiter) || str_contains($v, "\n") || str_contains($v, "\r")) {
                    return $quoteCharacter . str_replace($quoteCharacter, $quoteCharacter . $quoteCharacter, $v) . $quoteCharacter;
                }
                return $v;
            }, $values);

            $record = implode($fieldDelimiter, $quoted);
            SelectRecordSizeException::assertLength(strlen($record));
            $memoryGuard->assertOutputSize(strlen($output), strlen($record) + strlen($recordDelimiter));
            $output .= $record . $recordDelimiter;
            $memoryGuard->check();
        }

        return $output;
    }

    private static function validateDelimiters(string $fieldDelimiter, string $recordDelimiter, string $quoteCharacter): void
    {
        if (strlen($fieldDelimiter) !== 1 || strlen($quoteCharacter) !== 1 || $recordDelimiter === ''
            || $fieldDelimiter === $quoteCharacter || str_starts_with($recordDelimiter, $fieldDelimiter)
            || str_starts_with($recordDelimiter, $quoteCharacter)) {
            throw new SelectCsvException('CSV delimiters and quote character must be non-empty, distinct, and valid lengths.');
        }
    }

    private static function assertFieldCount(string $line, string $fieldDelimiter, string $quoteCharacter): void
    {
        $fields = 1;
        $quoted = false;
        $atFieldStart = true;
        for ($offset = 0, $length = strlen($line); $offset < $length; ++$offset) {
            $byte = $line[$offset];
            if ($quoted) {
                if ($byte === $quoteCharacter) {
                    if ($offset + 1 < $length && $line[$offset + 1] === $quoteCharacter) {
                        ++$offset;
                    } else {
                        $quoted = false;
                    }
                }

                continue;
            }
            if ($byte === $fieldDelimiter) {
                if (++$fields > self::MAX_FIELDS_PER_RECORD) {
                    throw new SelectMemoryLimitException('S3 Select CSV record exceeds the field budget.');
                }
                $atFieldStart = true;
            } elseif ($byte === $quoteCharacter && $atFieldStart) {
                $quoted = true;
                $atFieldStart = false;
            } else {
                $atFieldStart = false;
            }
        }
    }

    /** @return \Generator<int, string> */
    private static function records(string $data, string $fieldDelimiter, string $recordDelimiter, string $quoteCharacter): \Generator
    {
        $length = strlen($data);
        $recordLength = strlen($recordDelimiter);
        $special = $fieldDelimiter . $quoteCharacter . $recordDelimiter[0];
        $recordStart = 0;
        $cursor = 0;
        $atFieldStart = true;
        $inQuotes = false;

        while ($cursor < $length) {
            SelectRecordSizeException::assertLength($cursor - $recordStart);
            if ($inQuotes) {
                $nextQuote = strpos($data, $quoteCharacter, $cursor);
                if ($nextQuote === false) {
                    throw new SelectCsvException('CSV input contains an unterminated quoted field.');
                }
                if ($nextQuote + 1 < $length && $data[$nextQuote + 1] === $quoteCharacter) {
                    $cursor = $nextQuote + 2;

                    continue;
                }
                $inQuotes = false;
                $cursor = $nextQuote + 1;
                if ($cursor < $length
                    && $data[$cursor] !== $fieldDelimiter
                    && ! ($cursor + $recordLength <= $length
                        && substr_compare($data, $recordDelimiter, $cursor, $recordLength) === 0)) {
                    throw new SelectCsvException(
                        'CSV input contains characters after a closing quote.',
                    );
                }

                continue;
            }

            $skipped = strcspn($data, $special, $cursor);
            if ($skipped > 0) {
                $atFieldStart = false;
                $cursor += $skipped;
            }
            if ($cursor >= $length) {
                break;
            }
            if ($cursor + $recordLength <= $length
                && substr_compare($data, $recordDelimiter, $cursor, $recordLength) === 0) {
                SelectRecordSizeException::assertLength($cursor - $recordStart);
                yield substr($data, $recordStart, $cursor - $recordStart);
                $cursor += $recordLength;
                $recordStart = $cursor;
                $atFieldStart = true;

                continue;
            }

            $byte = $data[$cursor++];
            if ($byte === $fieldDelimiter) {
                $atFieldStart = true;
            } elseif ($byte === $quoteCharacter && $atFieldStart) {
                $inQuotes = true;
                $atFieldStart = false;
            } else {
                $atFieldStart = false;
            }
        }

        if ($inQuotes) {
            throw new SelectCsvException('CSV input contains an unterminated quoted field.');
        }
        if ($recordStart < $length) {
            SelectRecordSizeException::assertLength($length - $recordStart);
            yield substr($data, $recordStart);
        }
    }

    private static function formatValue(mixed $value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (is_string($value) || is_int($value) || is_float($value)) {
            return (string) $value;
        }
        if (is_array($value) || $value instanceof \stdClass) {
            throw new SelectCsvException('Nested S3 Select results require JSON output serialization.');
        }

        throw new SelectCsvException('S3 Select CSV output contains an unsupported value.');
    }
}
