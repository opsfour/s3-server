<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Resolve the most specific Linux mount for a canonical storage path. */
final class LinuxMountInfo
{
    /** An identity file must be the one exact, read-only local bind in this namespace. */
    public static function isExactReadOnlyLocalFileBind(string $path, string $mountInfo): bool
    {
        if (! str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Mount lookup requires an absolute path.');
        }

        $matches = 0;
        foreach (explode("\n", $mountInfo) as $line) {
            if ($line === '') {
                continue;
            }
            $sections = explode(' - ', $line, 2);
            if (count($sections) !== 2) {
                return false;
            }
            $before = preg_split('/ +/', $sections[0]);
            $after = preg_split('/ +/', $sections[1]);
            if ($before === false || $after === false || count($before) < 6 || count($after) < 3) {
                return false;
            }
            $point = self::decodePath($before[4]);
            if ($point === null || ! str_starts_with($point, '/')) {
                return false;
            }
            if ($point !== $path) {
                continue;
            }
            $matches++;
            if ($matches > 1 || ($after[0] !== 'tmpfs' && ! self::isQualifiedLocalFilesystem($after[0]))
                || ! in_array('ro', explode(',', $before[5]), true)) {
                return false;
            }
        }

        return $matches === 1;
    }

    /**
     * Linux mountinfo escapes spaces, tabs, newlines, and backslashes as octal.
     * A malformed record is treated as unknown by the startup guard.
     */
    public static function filesystemTypeForPath(string $path, string $mountInfo): ?string
    {
        if (! str_starts_with($path, '/')) {
            throw new \InvalidArgumentException('Mount lookup requires an absolute path.');
        }

        $bestLength = -1;
        $bestType = null;
        $ambiguous = false;
        foreach (explode("\n", $mountInfo) as $line) {
            if ($line === '') {
                continue;
            }
            $sections = explode(' - ', $line, 2);
            if (count($sections) !== 2) {
                return null;
            }
            $before = preg_split('/ +/', $sections[0]);
            $after = preg_split('/ +/', $sections[1]);
            if ($before === false || $after === false || count($before) < 6 || count($after) < 3) {
                return null;
            }
            $mountPoint = self::decodePath($before[4]);
            if ($mountPoint === null || ! str_starts_with($mountPoint, '/') || $after[0] === '') {
                return null;
            }
            $prefix = rtrim($mountPoint, '/') . '/';
            if ($path !== $mountPoint && ! str_starts_with($path, $prefix)) {
                continue;
            }
            if (strlen($mountPoint) > $bestLength) {
                $bestLength = strlen($mountPoint);
                $bestType = strtolower($after[0]);
                $ambiguous = false;
            } elseif (strlen($mountPoint) === $bestLength) {
                $ambiguous = true;
            }
        }

        return $ambiguous ? null : $bestType;
    }

    /** An explicit local allowlist makes new and opaque filesystem types fail closed. */
    public static function isQualifiedLocalFilesystem(string $type): bool
    {
        return in_array(strtolower($type), [
            'ext2', 'ext3', 'ext4', 'xfs', 'btrfs', 'zfs', 'f2fs', 'bcachefs',
            'overlay', 'ubifs',
        ], true);
    }

    private static function decodePath(string $encoded): ?string
    {
        if (preg_match('/\\\\(?!040|011|012|134)/', $encoded) === 1) {
            return null;
        }
        return str_replace(
            ['\\040', '\\011', '\\012', '\\134'],
            [' ', "\t", "\n", '\\'],
            $encoded,
        );
    }
}
