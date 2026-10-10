<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Converts a database row without exposing its physical storage path. */
final class MultipartPartWriteIntentInventoryRow
{
    /**
     * @param array<string, mixed> $row
     * @return array{id: int, bucket: string, storage_tier: string, path_sha256: string, status: string, attempts: int, created_at: string, next_attempt_at: float}
     */
    public static function fromDatabase(array $row): array
    {
        return [
            'id' => self::integer($row['id'] ?? null),
            'bucket' => self::scalarString($row['bucket'] ?? null),
            'storage_tier' => self::scalarString($row['storage_tier'] ?? null),
            'path_sha256' => hash('sha256', self::scalarString($row['storage_path'] ?? null)),
            'status' => self::scalarString($row['status'] ?? null),
            'attempts' => self::integer($row['attempts'] ?? null),
            'created_at' => self::scalarString($row['created_at'] ?? null),
            'next_attempt_at' => self::number($row['next_attempt_at'] ?? null),
        ];
    }

    private static function scalarString(mixed $value): string
    {
        if (! is_scalar($value)) {
            throw new \UnexpectedValueException('Multipart-part intent inventory row has an invalid scalar value.');
        }

        return (string) $value;
    }

    private static function integer(mixed $value): int
    {
        if (! is_int($value) && (! is_string($value) || filter_var($value, FILTER_VALIDATE_INT) === false)) {
            throw new \UnexpectedValueException('Multipart-part intent inventory row has an invalid integer value.');
        }

        return (int) $value;
    }

    private static function number(mixed $value): float
    {
        if (! is_float($value) && ! is_int($value) && (! is_string($value) || ! is_numeric($value))) {
            throw new \UnexpectedValueException('Multipart-part intent inventory row has an invalid number value.');
        }

        return (float) $value;
    }
}
