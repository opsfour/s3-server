<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Closure;
use OpsFour\S3Server\Runtime\ProductionEfsHostPinFileReader;
use PHPUnit\Framework\TestCase;

final class ProductionEfsHostPinFileReaderTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $temp = realpath(sys_get_temp_dir());
        self::assertIsString($temp);
        $this->directory = $temp . '/s3-production-host-pin-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0o700));
        $this->path = $this->directory . '/host-pin.json';
        self::assertIsInt(file_put_contents($this->path, self::json()));
        self::assertTrue(chmod($this->path, 0o644));
    }

    protected function tearDown(): void
    {
        if (is_link($this->path) || is_file($this->path)) {
            unlink($this->path);
        }
        rmdir($this->directory);
    }

    public function test_root_owned_read_only_local_bind_is_parsed_without_other_access(): void
    {
        $pin = $this->reader()->read();

        self::assertSame(str_repeat('a', 32), $pin->deploymentId);
        self::assertSame(str_repeat('c', 64), $pin->containerId);
        self::assertSame('39', $pin->hostMountId);
    }

    public function test_exact_read_only_local_bind_is_required_before_file_stat(): void
    {
        $root = "1 0 0:1 / / rw - ext4 /dev/root rw\n";
        $cases = [
            'missing' => $root,
            'writable' => $root . "2 1 0:2 /pin {$this->path} rw - tmpfs tmpfs rw\n",
            'network' => $root . "2 1 0:2 /pin {$this->path} ro - nfs4 server:/ rw\n",
            'stacked' => self::localMountInfo($this->path)
                . "3 2 0:3 /pin {$this->path} ro - ext4 /dev/other ro\n",
        ];

        foreach ($cases as $name => $mountInfo) {
            $fileStats = 0;
            $reader = $this->reader(
                mountInfo: static fn(): string => $mountInfo,
                lstat: function (string $path) use (&$fileStats): array|false {
                    if ($path === $this->path) {
                        $fileStats++;
                    }

                    return self::rootStat(@lstat($path));
                },
            );
            try {
                $reader->read();
                self::fail("{$name} host pin bind was accepted.");
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('read-only local file bind', $error->getMessage(), $name);
                self::assertSame(0, $fileStats, $name);
            }
        }
    }

    public function test_network_or_ambiguous_parent_mount_is_rejected_before_file_stat(): void
    {
        foreach (['nfs4', 'ambiguous'] as $case) {
            $fileStats = 0;
            $extra = $case === 'nfs4'
                ? "3 2 0:3 / {$this->directory} rw - nfs4 server:/ rw\n"
                : "3 2 0:3 / {$this->directory} rw - tmpfs tmpfs rw\n"
                    . "4 3 0:4 / {$this->directory} rw - ext4 /dev/other rw\n";
            $reader = $this->reader(
                mountInfo: fn(): string => self::localMountInfo($this->path) . $extra,
                lstat: function (string $path) use (&$fileStats): array|false {
                    if ($path === $this->path) {
                        $fileStats++;
                    }

                    return self::rootStat(@lstat($path));
                },
            );
            try {
                $reader->read();
                self::fail("{$case} parent mount was accepted.");
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('qualified local mount', $error->getMessage());
                self::assertSame(0, $fileStats);
            }
        }
    }

    public function test_symlinked_file_parent_or_non_root_owner_is_rejected(): void
    {
        $target = $this->directory . '/target.json';
        self::assertTrue(rename($this->path, $target));
        self::assertTrue(symlink($target, $this->path));
        try {
            $this->reader()->read();
            self::fail('Symlinked host pin was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ownership or file type', $error->getMessage());
        } finally {
            unlink($this->path);
            self::assertTrue(rename($target, $this->path));
        }

        $alias = $this->directory . '/alias';
        self::assertTrue(symlink($this->directory, $alias));
        $aliasPath = $alias . '/host-pin.json';
        try {
            (new ProductionEfsHostPinFileReader(
                $aliasPath,
                fn(): string => self::localMountInfo($aliasPath),
                static fn(string $path): array|false => self::rootStat(@lstat($path)),
            ))->read();
            self::fail('Host pin through a symlinked parent was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ownership or file type', $error->getMessage());
        } finally {
            unlink($alias);
        }

        $reader = $this->reader(lstat: function (string $path): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->path) {
                $entry['uid'] = 10001;
            }

            return $entry;
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('ownership or file type');
        $reader->read();
    }

    public function test_hardlink_inode_swap_size_change_and_mount_change_are_rejected(): void
    {
        $reader = $this->reader(lstat: function (string $path): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->path) {
                $entry['nlink'] = 2;
            }

            return $entry;
        });
        try {
            $reader->read();
            self::fail('Hardlinked host pin was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('file is invalid', $error->getMessage());
        }

        $visits = 0;
        $reader = $this->reader(lstat: function (string $path) use (&$visits): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->path && ++$visits === 2) {
                $entry['ino']++;
            }

            return $entry;
        });
        try {
            $reader->read();
            self::fail('Replaced host pin inode was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed while reading', $error->getMessage());
        }

        $reader = $this->reader(fstat: function ($handle): array|false {
            $entry = self::rootStat(@fstat($handle));
            if (is_array($entry)) {
                $entry['size']++;
            }

            return $entry;
        });
        try {
            $reader->read();
            self::fail('Changed open host pin size was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed while opening', $error->getMessage());
        }

        $mountReads = 0;
        $reader = $this->reader(mountInfo: function () use (&$mountReads): string {
            return ++$mountReads === 1
                ? self::localMountInfo($this->path)
                : self::localMountInfo($this->path) . "3 2 0:3 / /other rw - ext4 /dev/other rw\n";
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mount information changed');
        $reader->read();
    }

    public function test_parent_inode_change_during_read_is_rejected(): void
    {
        $directoryVisits = 0;
        $reader = $this->reader(lstat: function (string $path) use (&$directoryVisits): array|false {
            $entry = self::rootStat(@lstat($path));
            if (is_array($entry) && $path === $this->directory && ++$directoryVisits === 2) {
                $entry['ino']++;
            }

            return $entry;
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('parent changed while reading');
        $reader->read();
    }

    public function test_malformed_record_and_unsafe_path_are_rejected(): void
    {
        self::assertIsInt(file_put_contents($this->path, '{}'));
        try {
            $this->reader()->read();
            self::fail('Malformed host pin record was accepted.');
        } catch (\InvalidArgumentException) {
            self::addToAssertionCount(1);
        }

        foreach (['relative.json', '/', '/tmp/../pin.json', '/tmp//pin.json', '/tmp/pin.json/'] as $path) {
            try {
                new ProductionEfsHostPinFileReader($path, static fn(): string => 'unused');
                self::fail("Unsafe host pin path {$path} was accepted.");
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    /**
     * @param (Closure(): (string|false))|null $mountInfo
     * @param (Closure(string): (array<array-key, int>|false))|null $lstat
     * @param (Closure(resource): (array<array-key, int>|false))|null $fstat
     */
    private function reader(
        ?Closure $mountInfo = null,
        ?Closure $lstat = null,
        ?Closure $fstat = null,
    ): ProductionEfsHostPinFileReader {
        return new ProductionEfsHostPinFileReader(
            $this->path,
            $mountInfo ?? fn(): string => self::localMountInfo($this->path),
            $lstat ?? static fn(string $path): array|false => self::rootStat(@lstat($path)),
            $fstat ?? static fn($handle): array|false => self::rootStat(@fstat($handle)),
        );
    }

    private static function localMountInfo(string $bindPath): string
    {
        return "1 0 0:1 / / rw - ext4 /dev/root rw\n"
            . "2 1 0:2 /host-pin.json {$bindPath} ro - tmpfs tmpfs rw\n";
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>|false
     */
    private static function rootStat(array|false $entry): array|false
    {
        if ($entry === false) {
            return false;
        }
        $entry['uid'] = 0;
        $entry['mode'] &= ~0o022;

        return $entry;
    }

    private static function json(): string
    {
        return json_encode([
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'containerId' => str_repeat('c', 64),
            'bootId' => '11111111-2222-3333-4444-555555555555',
            'hostMountId' => '39',
            'storageDev' => '2048',
            'storageIno' => '3001',
            'epoch' => str_repeat('d', 32),
        ], JSON_THROW_ON_ERROR);
    }
}
