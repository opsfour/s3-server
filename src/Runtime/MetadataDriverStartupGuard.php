<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Metadata\CachedMetadataStoreDecorator;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\Schema\MetadataSchemaAdmission;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;

/** Bind startup safety checks to the actual built-in metadata engine. */
final class MetadataDriverStartupGuard
{
    public static function assertMatches(?MetadataStore $metadata, string $configuredDriver): void
    {
        $metadata = self::unwrap($metadata);

        $actualDriver = match (true) {
            $metadata instanceof PostgresMetadataStore => 'postgres',
            $metadata instanceof MysqlMetadataStore => 'mysql',
            $metadata instanceof SqliteMetadataStore, $metadata instanceof ParallelSqliteMetadataStore => 'sqlite',
            default => null,
        };

        if ($actualDriver !== null && $actualDriver !== $configuredDriver) {
            throw new \RuntimeException(sprintf(
                'Refusing to start: configured metadata driver %s does not match injected %s metadata store.',
                $configuredDriver,
                $actualDriver,
            ));
        }
    }

    public static function assertSchemaCurrent(?MetadataStore $metadata): void
    {
        $metadata = self::unwrap($metadata);
        if ($metadata instanceof MetadataSchemaAdmission) {
            $metadata->assertCurrentSchema();
        } elseif ($metadata instanceof SqliteMetadataStore || $metadata instanceof ParallelSqliteMetadataStore) {
            $metadata->assertSupportedBucketEncryptionInventory();
        }
    }

    /** The fenced local writer cannot use SQLite, an opaque store or a cache decorator. */
    public static function assertRawFencedSqlStore(?MetadataStore $metadata, string $driver, string $dsn): void
    {
        if ($metadata instanceof ObservedMetadataStore) {
            $metadata = $metadata->innerStore();
        }

        if (trim($dsn) === '' || ! (
            ($driver === 'postgres' && $metadata instanceof PostgresMetadataStore && $metadata->matchesCreationDsn($dsn))
            || ($driver === 'mysql' && $metadata instanceof MysqlMetadataStore && $metadata->matchesCreationDsn($dsn))
        )) {
            throw new \RuntimeException('Local object write fencing requires raw PostgreSQL/MySQL metadata created from the configured DSN.');
        }
    }

    public static function hasVolatileSqliteMetadata(?MetadataStore $metadata): bool
    {
        $metadata = self::unwrap($metadata);

        return $metadata instanceof SqliteMetadataStore && $metadata->isInMemory();
    }

    /** A tmpfs/ramfs object root is safe only when metadata disappears with it. */
    public static function hasColocatedVolatileSqliteMetadata(?MetadataStore $metadata, string $storageRoot): bool
    {
        $metadata = self::unwrap($metadata);
        if (! $metadata instanceof SqliteMetadataStore) {
            return false;
        }
        if ($metadata->isInMemory()) {
            return true;
        }

        $databaseFile = $metadata->databaseFilePath();
        $canonicalFile = $databaseFile === null ? false : realpath($databaseFile);
        if ($canonicalFile === false || ! is_file($canonicalFile)) {
            return false;
        }
        $storageStat = @stat($storageRoot);
        $metadataStat = @stat($canonicalFile);

        return $storageStat !== false && $metadataStat !== false
            && $storageStat['dev'] === $metadataStat['dev'];
    }

    private static function unwrap(?MetadataStore $metadata): ?MetadataStore
    {
        while ($metadata instanceof ObservedMetadataStore || $metadata instanceof CachedMetadataStoreDecorator) {
            $metadata = $metadata->innerStore();
        }

        return $metadata;
    }
}
