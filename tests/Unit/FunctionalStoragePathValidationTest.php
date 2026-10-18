<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit;

use OpsFour\S3Server\Tests\Functional\S3FunctionalTestCase;
use PHPUnit\Framework\TestCase;

final class FunctionalStoragePathValidationTest extends TestCase
{
    private string $root;

    protected function setUp(): void
    {
        $base = realpath(sys_get_temp_dir());
        self::assertIsString($base);
        $this->root = $base . '/s3-storage-path-validation-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o700));
    }

    protected function tearDown(): void
    {
        @unlink($this->root . '/link');
        @unlink($this->root . '/occupied');
        @rmdir($this->root . '/target/child');
        @rmdir($this->root . '/target');
        @rmdir($this->root);
    }

    public function test_accepts_a_canonical_empty_directory(): void
    {
        self::assertSame($this->root, $this->validate($this->root));
    }

    public function test_rejects_a_relative_path(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validate('relative/path');
    }

    public function test_rejects_the_filesystem_root(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->validate(DIRECTORY_SEPARATOR);
    }

    public function test_rejects_a_nonempty_directory(): void
    {
        file_put_contents($this->root . '/occupied', 'x');

        $this->expectException(\InvalidArgumentException::class);
        $this->validate($this->root);
    }

    public function test_rejects_a_symbolic_link(): void
    {
        self::assertTrue(mkdir($this->root . '/target'));
        self::assertTrue(symlink($this->root . '/target', $this->root . '/link'));

        $this->expectException(\InvalidArgumentException::class);
        $this->validate($this->root . '/link');
    }

    public function test_rejects_a_symbolic_link_in_a_parent_segment(): void
    {
        self::assertTrue(mkdir($this->root . '/target'));
        self::assertTrue(mkdir($this->root . '/target/child'));
        self::assertTrue(symlink($this->root . '/target', $this->root . '/link'));

        $this->expectException(\InvalidArgumentException::class);
        $this->validate($this->root . '/link/child');
    }

    private function validate(string $path): string
    {
        $method = new \ReflectionMethod(S3FunctionalTestCase::class, 'validateConfiguredStoragePath');
        $result = $method->invoke(null, $path);
        self::assertIsString($result);

        return $result;
    }
}
