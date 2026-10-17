<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
use PHPUnit\Framework\TestCase;

final class SqliteMetadataPathResolverTest extends TestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        $this->storagePath = sys_get_temp_dir() . '/s3-metadata-path-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->storagePath, 0o700, true));
    }

    protected function tearDown(): void
    {
        $this->remove($this->storagePath);
    }

    public function test_new_installation_uses_package_internal_namespace(): void
    {
        self::assertSame(
            $this->storagePath . '/.opsfour/metadata.sqlite',
            SqliteMetadataPathResolver::resolve($this->storagePath),
        );
    }

    public function test_existing_legacy_database_file_is_reused(): void
    {
        $legacyPath = $this->storagePath . '/metadata.sqlite';
        self::assertNotFalse(file_put_contents($legacyPath, 'legacy'));

        self::assertSame($legacyPath, SqliteMetadataPathResolver::resolve($this->storagePath));
    }

    public function test_legacy_name_as_directory_remains_available_for_bucket_data(): void
    {
        self::assertTrue(mkdir($this->storagePath . '/metadata.sqlite'));

        self::assertSame(
            $this->storagePath . '/.opsfour/metadata.sqlite',
            SqliteMetadataPathResolver::resolve($this->storagePath),
        );
        self::assertDirectoryExists($this->storagePath . '/metadata.sqlite');
    }

    public function test_explicit_path_wins_over_legacy_discovery(): void
    {
        self::assertNotFalse(file_put_contents($this->storagePath . '/metadata.sqlite', 'legacy'));
        $explicitPath = $this->storagePath . '/custom/database.sqlite';

        self::assertSame(
            $explicitPath,
            SqliteMetadataPathResolver::resolve($this->storagePath, $explicitPath),
        );
    }

    private function remove(string $path): void
    {
        if (! is_dir($path)) {
            @unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($iterator as $item) {
            if ($item->isDir()) {
                @rmdir($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
