<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Select;

/**
 * Processes JSON input for S3 Select (LINES and DOCUMENT formats).
 */
final class JsonProcessor
{
    /**
     * Parse JSON data into rows.
     *
     * @param string $data Raw JSON data.
     * @param string $jsonType 'LINES' or 'DOCUMENT'.
     * @return list<array<string, mixed>>
     */
    public static function parse(string $data, string $jsonType = 'LINES'): array
    {
        if ($jsonType === 'LINES') {
            return self::parseLines($data);
        }

        return self::parseDocument($data);
    }

    /**
     * Format rows as JSON output.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function format(array $rows): string
    {
        $output = '';
        foreach ($rows as $row) {
            $output .= json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR) . "\n";
        }
        return $output;
    }

    /** Maximum number of parsed rows to prevent memory exhaustion. */
    private const int MAX_ROWS = 1_000_000;

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseLines(string $data): array
    {
        $rows = [];
        $data = trim($data);
        $dataLen = strlen($data);
        $pos = 0;

        // Iterate line-by-line using strpos() to avoid doubling memory with explode().
        while ($pos < $dataLen) {
            $end = strpos($data, "\n", $pos);
            if ($end === false) {
                $line = trim(substr($data, $pos));
                $pos = $dataLen;
            } else {
                $line = trim(substr($data, $pos, $end - $pos));
                $pos = $end + 1;
            }

            if ($line === '') {
                continue;
            }
            if (count($rows) >= self::MAX_ROWS) {
                throw new \RuntimeException(
                    'S3 Select: JSON input exceeds maximum row count (' . self::MAX_ROWS . ').',
                );
            }
            $decoded = json_decode($line, true, 512, JSON_THROW_ON_ERROR);
            if (is_array($decoded) && !array_is_list($decoded)) {
                $rows[] = $decoded;
            }
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseDocument(string $data): array
    {
        $decoded = json_decode(trim($data), true, 512, JSON_THROW_ON_ERROR);

        if (!is_array($decoded)) {
            return [];
        }

        // If it's a list of objects, return as-is (with row count guard).
        if (array_is_list($decoded)) {
            if (count($decoded) > self::MAX_ROWS) {
                throw new \RuntimeException(
                    'S3 Select: JSON document exceeds maximum row count (' . self::MAX_ROWS . ').',
                );
            }
            return array_values(array_filter($decoded, static fn(mixed $row): bool => is_array($row) && !array_is_list($row)));
        }

        // Single object — wrap in a list.
        return [$decoded];
    }

    /**
     * Resolve a dot-notation path in a nested array.
     *
     * @param array<string, mixed> $row
     */
    public static function resolvePath(array $row, string $path): mixed
    {
        $parts = explode('.', $path);
        $current = $row;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } else {
                return null;
            }
        }

        return $current;
    }
}
