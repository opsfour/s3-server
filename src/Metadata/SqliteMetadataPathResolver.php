<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** @internal Resolves the package-managed SQLite path for integration entry points. */
final class SqliteMetadataPathResolver
{
    private const string DATABASE_FILENAME = 'metadata.sqlite';

    private const string INTERNAL_DIRECTORY = '.opsfour';

    public static function resolve(string $storagePath, ?string $configuredPath = null): string
    {
        if ($configuredPath !== null && $configuredPath !== '') {
            return $configuredPath;
        }

        $legacyPath = self::join($storagePath, self::DATABASE_FILENAME);
        $internalPath = self::join(
            self::join($storagePath, self::INTERNAL_DIRECTORY),
            self::DATABASE_FILENAME,
        );
        if (is_file($legacyPath) && is_file($internalPath)) {
            throw new \RuntimeException(
                'Both the legacy and internal SQLite metadata files exist. '
                . 'Set S3_METADATA_PATH explicitly to select the active database.',
            );
        }
        if (is_file($legacyPath)) {
            return $legacyPath;
        }

        return $internalPath;
    }

    private static function join(string $directory, string $name): string
    {
        $directory = rtrim($directory, '/\\');

        return ($directory === '' ? DIRECTORY_SEPARATOR : $directory . DIRECTORY_SEPARATOR) . $name;
    }
}
