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
    public static function parse(string $data, string $jsonType = 'LINES', ?SelectMemoryGuard $memoryGuard = null): array
    {
        $memoryGuard ??= SelectMemoryGuard::start();
        if ($jsonType === 'LINES') {
            return self::parseLines($data, $memoryGuard);
        }

        return self::parseDocument($data, $memoryGuard);
    }

    /**
     * Format rows as JSON output.
     *
     * @param list<array<string, mixed>> $rows
     */
    public static function format(array $rows, ?SelectMemoryGuard $memoryGuard = null): string
    {
        $memoryGuard ??= SelectMemoryGuard::start();
        $output = '';
        foreach ($rows as $row) {
            $memoryGuard->check();
            $record = json_encode((object) $row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
            SelectRecordSizeException::assertLength(strlen($record));
            $memoryGuard->assertOutputSize(strlen($output), strlen($record) + 1);
            $output .= $record . "\n";
            $memoryGuard->check();
        }
        return $output;
    }

    /** Maximum number of parsed rows to prevent memory exhaustion. */
    private const int MAX_ROWS = 1_000_000;

    /** Bound PHP array/object expansion before decoding a JSON document. */
    private const int MAX_DOCUMENT_STRUCTURE_TOKENS = 50_000;

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseLines(string $data, SelectMemoryGuard $memoryGuard): array
    {
        $rows = [];
        $dataLen = strlen($data);
        $pos = 0;

        // Iterate line-by-line using strpos() to avoid doubling memory with explode().
        while ($pos < $dataLen) {
            $memoryGuard->check();
            $lineStart = $pos;
            $end = strpos($data, "\n", $pos);
            if ($end === false) {
                $lineEnd = $dataLen;
                $pos = $dataLen;
            } else {
                $lineEnd = $end;
                $pos = $end + 1;
            }

            while ($lineStart < $lineEnd && str_contains(" \t\n\r\0\x0B", $data[$lineStart])) {
                ++$lineStart;
            }
            while ($lineEnd > $lineStart && str_contains(" \t\n\r\0\x0B", $data[$lineEnd - 1])) {
                --$lineEnd;
            }

            $lineLength = $lineEnd - $lineStart;
            if ($lineLength === 0) {
                continue;
            }
            SelectRecordSizeException::assertLength($lineLength);
            if (count($rows) >= self::MAX_ROWS) {
                throw new SelectMemoryLimitException(
                    'S3 Select: JSON input exceeds maximum row count (' . self::MAX_ROWS . ').',
                );
            }
            $decoded = json_decode(substr($data, $lineStart, $lineLength), false, 512, JSON_THROW_ON_ERROR);
            if (! $decoded instanceof \stdClass) {
                throw new \JsonException('S3 Select JSON lines must contain objects.');
            }
            $rows[] = get_object_vars($decoded);
            $memoryGuard->check();
        }
        return $rows;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function parseDocument(string $data, SelectMemoryGuard $memoryGuard): array
    {
        $memoryGuard->assertJsonDocumentSize(strlen($data));
        self::assertDocumentStructureBudget($data, $memoryGuard);
        $decoded = json_decode($data, false, 512, JSON_THROW_ON_ERROR);
        $memoryGuard->check();

        if ($decoded instanceof \stdClass) {
            self::assertDecodedRecordSize($decoded);
            return [get_object_vars($decoded)];
        }
        if (! is_array($decoded)) {
            throw new \JsonException('S3 Select JSON document must contain an object or array of objects.');
        }
        if (count($decoded) > self::MAX_ROWS) {
            throw new SelectMemoryLimitException(
                'S3 Select: JSON document exceeds maximum row count (' . self::MAX_ROWS . ').',
            );
        }
        $rows = [];
        foreach ($decoded as $row) {
            if (! $row instanceof \stdClass) {
                throw new \JsonException('S3 Select JSON document array must contain only objects.');
            }
            self::assertDecodedRecordSize($row);
            $rows[] = get_object_vars($row);
            $memoryGuard->check();
        }

        return $rows;
    }

    private static function assertDocumentStructureBudget(string $data, SelectMemoryGuard $memoryGuard): void
    {
        $tokens = 0;
        $insideString = false;
        $escaped = false;
        $length = strlen($data);

        for ($i = 0; $i < $length; ++$i) {
            $byte = $data[$i];
            if ($insideString) {
                if ($escaped) {
                    $escaped = false;
                } elseif ($byte === '\\') {
                    $escaped = true;
                } elseif ($byte === '"') {
                    $insideString = false;
                }

                continue;
            }
            if ($byte === '"') {
                $insideString = true;

                continue;
            }
            if ($byte === '{' || $byte === '}' || $byte === '[' || $byte === ']'
                || $byte === ',' || $byte === ':') {
                if (++$tokens > self::MAX_DOCUMENT_STRUCTURE_TOKENS) {
                    throw new SelectMemoryLimitException('S3 Select JSON document exceeds the structure budget.');
                }
            }
        }

        // Decoded PHP objects and hash-table entries can cost far more than
        // their JSON syntax. Reserve a conservative allowance before json_decode.
        $memoryGuard->check($tokens * 512 + $length * 2);
    }

    private static function assertDecodedRecordSize(\stdClass $row): void
    {
        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
        SelectRecordSizeException::assertLength(strlen($encoded));
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
            } elseif ($current instanceof \stdClass && property_exists($current, $part)) {
                $current = $current->{$part};
            } else {
                return null;
            }
        }

        return $current;
    }

    /** @param array<string, mixed> $row */
    public static function hasPath(array $row, string $path): bool
    {
        $parts = explode('.', $path);
        $current = $row;

        foreach ($parts as $part) {
            if (is_array($current) && array_key_exists($part, $current)) {
                $current = $current[$part];
            } elseif ($current instanceof \stdClass && property_exists($current, $part)) {
                $current = $current->{$part};
            } else {
                return false;
            }
        }

        return true;
    }
}
