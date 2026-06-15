<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Processes CSV input for S3 Select.
 */
final class CsvProcessor
{
    /**
     * Parse CSV data into rows.
     *
     * @param string $data Raw CSV data.
     * @param string $fieldDelimiter Column delimiter.
     * @param string $recordDelimiter Row delimiter.
     * @param string $quoteCharacter Quote character.
     * @param bool $fileHeaderInfo Whether first row is header (USE/IGNORE/NONE).
     * @return array{headers: ?list<string>, rows: list<array<string, string>>}
     */
    /** Maximum number of parsed rows to prevent memory exhaustion. */
    private const int MAX_ROWS = 1_000_000;

    public static function parse(
        string $data,
        string $fieldDelimiter = ',',
        string $recordDelimiter = "\n",
        string $quoteCharacter = '"',
        string $fileHeaderInfo = 'NONE',
    ): array {
        $headers = null;
        $rows = [];
        $firstDataLine = true;

        // Iterate line-by-line using strpos() to avoid doubling memory with explode().
        $dataLen = strlen($data);
        $delimLen = strlen($recordDelimiter);
        $pos = 0;

        while ($pos < $dataLen) {
            $end = strpos($data, $recordDelimiter, $pos);
            if ($end === false) {
                $line = substr($data, $pos);
                $pos = $dataLen;
            } else {
                $line = substr($data, $pos, $end - $pos);
                $pos = $end + $delimLen;
            }

            if (trim($line) === '') {
                continue;
            }

            $fields = str_getcsv($line, $fieldDelimiter, $quoteCharacter, '');

            if ($firstDataLine && $fileHeaderInfo === 'USE') {
                $headers = $fields;
                $firstDataLine = false;
                continue;
            }

            if ($firstDataLine && $fileHeaderInfo === 'IGNORE') {
                $firstDataLine = false;
                continue;
            }
            $firstDataLine = false;

            if (count($rows) >= self::MAX_ROWS) {
                throw new \RuntimeException(
                    'S3 Select: CSV input exceeds maximum row count (' . self::MAX_ROWS . ').',
                );
            }

            $row = [];
            foreach ($fields as $j => $field) {
                $colName = $headers !== null && isset($headers[$j]) ? $headers[$j] : '_' . ($j + 1);
                $row[$colName] = $field ?? '';
            }
            $rows[] = $row;
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    /**
     * Format rows back to CSV.
     *
     * @param list<array<string, string>> $rows
     * @param list<string>|null $columns Specific columns to output.
     */
    public static function format(
        array $rows,
        ?array $columns = null,
        string $fieldDelimiter = ',',
        string $recordDelimiter = "\n",
        string $quoteCharacter = '"',
    ): string {
        $output = '';

        foreach ($rows as $row) {
            $values = [];
            if ($columns !== null) {
                foreach ($columns as $col) {
                    $values[] = $row[$col] ?? '';
                }
            } else {
                $values = array_values($row);
            }

            $quoted = array_map(function (string $v) use ($fieldDelimiter, $quoteCharacter, $recordDelimiter): string {
                if (str_contains($v, $fieldDelimiter) || str_contains($v, $quoteCharacter) || str_contains($v, $recordDelimiter) || str_contains($v, "\n") || str_contains($v, "\r")) {
                    return $quoteCharacter . str_replace($quoteCharacter, $quoteCharacter . $quoteCharacter, $v) . $quoteCharacter;
                }
                return $v;
            }, $values);

            $output .= implode($fieldDelimiter, $quoted) . $recordDelimiter;
        }

        return $output;
    }
}
