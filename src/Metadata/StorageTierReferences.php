<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Collects physical tier names needed to serve persisted metadata. */
final class StorageTierReferences
{
    public const string TIER_SQL = <<<'SQL'
        SELECT DISTINCT storage_tier AS tier FROM s3_objects
        UNION ALL SELECT DISTINCT restored_storage_tier AS tier FROM s3_objects WHERE restored_storage_path IS NOT NULL
        UNION ALL SELECT DISTINCT storage_tier AS tier FROM s3_multipart_uploads
        UNION ALL SELECT DISTINCT transition_target_tier AS tier FROM s3_objects WHERE transition_target_tier IS NOT NULL
        UNION ALL SELECT DISTINCT source_tier AS tier FROM s3_tier_transition_jobs WHERE status <> 'completed'
        UNION ALL SELECT DISTINCT target_tier AS tier FROM s3_tier_transition_jobs WHERE status <> 'completed'
        UNION ALL SELECT DISTINCT source_tier AS tier FROM s3_restore_jobs WHERE status <> 'completed'
        UNION ALL SELECT DISTINCT storage_tier AS tier FROM s3_storage_garbage
        UNION ALL SELECT DISTINCT storage_tier AS tier FROM s3_multipart_write_intents
        UNION ALL SELECT DISTINCT storage_tier AS tier FROM s3_object_write_intents
        SQL;

    /** MySQL VARCHAR comparison follows the database collation unless forced byte-exact. */
    public const string MYSQL_TIER_SQL = <<<'SQL'
        SELECT DISTINCT BINARY storage_tier AS tier FROM s3_objects
        UNION ALL SELECT DISTINCT BINARY restored_storage_tier AS tier FROM s3_objects WHERE restored_storage_path IS NOT NULL
        UNION ALL SELECT DISTINCT BINARY storage_tier AS tier FROM s3_multipart_uploads
        UNION ALL SELECT DISTINCT BINARY transition_target_tier AS tier FROM s3_objects WHERE transition_target_tier IS NOT NULL
        UNION ALL SELECT DISTINCT BINARY source_tier AS tier FROM s3_tier_transition_jobs WHERE status <> 'completed'
        UNION ALL SELECT DISTINCT BINARY target_tier AS tier FROM s3_tier_transition_jobs WHERE status <> 'completed'
        UNION ALL SELECT DISTINCT BINARY source_tier AS tier FROM s3_restore_jobs WHERE status <> 'completed'
        UNION ALL SELECT DISTINCT BINARY storage_tier AS tier FROM s3_storage_garbage
        UNION ALL SELECT DISTINCT BINARY storage_tier AS tier FROM s3_multipart_write_intents
        UNION ALL SELECT DISTINCT BINARY storage_tier AS tier FROM s3_object_write_intents
        SQL;

    public const string LIFECYCLE_SQL = 'SELECT transitions_json, noncurrent_transitions_json FROM s3_lifecycle_rules WHERE transitions_json IS NOT NULL OR noncurrent_transitions_json IS NOT NULL';

    /**
     * @param iterable<array<string, mixed>> $tierRows
     * @param iterable<array<string, mixed>> $lifecycleRows
     * @return list<string>
     */
    public static function collect(iterable $tierRows, iterable $lifecycleRows): array
    {
        $names = [];
        foreach ($tierRows as $row) {
            $name = $row['tier'] ?? null;
            if (! is_string($name) && ! $name instanceof \Stringable) {
                throw new \UnexpectedValueException('Stored physical tier name is invalid.');
            }
            $name = (string) $name;
            if ($name === '') {
                throw new \UnexpectedValueException('Stored physical tier name is invalid.');
            }
            $names["tier\0{$name}"] = $name;
        }

        foreach ($lifecycleRows as $row) {
            foreach (['transitions_json', 'noncurrent_transitions_json'] as $column) {
                $json = $row[$column] ?? null;
                if ($json === null) {
                    continue;
                }
                if (! is_string($json) && ! $json instanceof \Stringable) {
                    throw new \UnexpectedValueException('Stored lifecycle transitions are invalid.');
                }
                $transitions = json_decode((string) $json, true, 512, JSON_THROW_ON_ERROR);
                if (! is_array($transitions)) {
                    throw new \UnexpectedValueException('Stored lifecycle transitions are invalid.');
                }
                foreach ($transitions as $transition) {
                    $name = is_array($transition) ? ($transition['storageClass'] ?? null) : null;
                    if (! is_string($name) || $name === '') {
                        throw new \UnexpectedValueException('Stored lifecycle transition tier is invalid.');
                    }
                    $names["tier\0{$name}"] = $name;
                }
            }
        }

        $tiers = array_values($names);
        sort($tiers, SORT_STRING);

        return $tiers;
    }
}
