<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Reject paths that a Flysystem adapter could normalize into another scope. */
final class FlysystemPathScope
{
    public static function assertStoredPath(string $path): void
    {
        if ($path === '' || str_starts_with($path, '/') || str_ends_with($path, '/')
            || str_contains($path, '\\') || str_contains($path, '//')
            || preg_match('~(?:^|/)\.{1,2}(?:/|$)~D', $path)
            || preg_match('/[\x00-\x1F\x7F:]/', $path)
        ) {
            throw new \InvalidArgumentException('Invalid Flysystem storage path.');
        }
    }

    public static function assertDeletePath(string $path, string $bucket): void
    {
        self::assertStoredPath($path);
        StoragePath::assertBucketPathSegment($bucket);

        if (! (str_starts_with($path, $bucket . '/')
            || str_starts_with($path, '.assemblies/' . $bucket . '/')
            || str_starts_with($path, '.parts/'))
        ) {
            throw new \InvalidArgumentException('Flysystem storage path does not belong to the bucket.');
        }
    }

    private function __construct() {}
}
