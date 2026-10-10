<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Closure;
use OpsFour\S3Server\Runtime\ProductionEfsIdentityFileReader;
use PHPUnit\Framework\TestCase;

final class ProductionEfsIdentityFileReaderTest extends TestCase
{
    private string $directory;

    private string $path;

    protected function setUp(): void
    {
        $temp = realpath(sys_get_temp_dir());
        self::assertIsString($temp);
        $this->directory = $temp . '/s3-production-identity-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->directory, 0o700));
        $this->path = $this->directory . '/identity.json';
        self::assertIsInt(file_put_contents($this->path, self::json($this->scope())));
        self::assertTrue(chmod($this->path, 0o644));
    }

    protected function tearDown(): void
    {
        if (is_link($this->path) || is_file($this->path)) {
            unlink($this->path);
        }
        rmdir($this->directory);
    }

    public function test_root_owned_local_file_is_parsed_without_accessing_runtime_paths(): void
    {
        $identity = $this->reader()->read();

        self::assertSame(str_repeat('a', 32), $identity->deploymentId);
        self::assertSame('/unmounted-efs/objects', $identity->storageRoot);
        self::assertSame('/unmounted-local-scratch', $identity->scratchPath);
    }

    public function test_identity_requires_one_exact_read_only_tmpfs_file_bind_before_stat(): void
    {
        $root = "1 0 0:1 / / rw - ext4 /dev/root rw\n";
        $cases = [
            'missing bind' => $root,
            'writable bind' => $root . "2 1 0:2 /identity.json {$this->path} rw - tmpfs tmpfs rw\n",
            'network filesystem' => $root . "2 1 0:2 /identity.json {$this->path} ro - nfs4 server:/ rw\n",
            'stacked bind' => self::localMountInfo($this->path)
                . "3 2 0:3 /identity.json {$this->path} ro - nfs4 server:/ rw\n",
        ];

        foreach ($cases as $name => $mountInfo) {
            $identityStats = 0;
            $reader = $this->reader(
                mountInfo: static fn(): string => $mountInfo,
                lstat: function (string $path) use (&$identityStats): array|false {
                    if ($path === $this->path) {
                        $identityStats++;
                    }

                    return self::rootStat(@lstat($path));
                },
            );
            try {
                $reader->read();
                self::fail("{$name} was admitted as a local identity bind.");
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('read-only local file bind', $error->getMessage(), $name);
                self::assertSame(0, $identityStats, $name);
            }
        }
    }

    public function test_network_mount_is_rejected_before_identity_path_stat_or_open(): void
    {
        $identityStats = 0;
        $reader = $this->reader(
            mountInfo: fn(): string => self::localMountInfo($this->path) . "2 1 0:2 / {$this->directory} rw - nfs4 server:/ rw\n",
            lstat: function (string $path) use (&$identityStats): array|false {
                if ($path === $this->path) {
                    $identityStats++;
                }

                return self::rootStat(@lstat($path));
            },
        );

        try {
            $reader->read();
            self::fail('Identity on NFS was opened.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('qualified local mount', $error->getMessage());
            self::assertSame(0, $identityStats);
        }
    }

    public function test_stacked_tmpfs_and_nfs_mount_is_rejected_before_identity_file_stat(): void
    {
        $identityStats = 0;
        $reader = $this->reader(
            mountInfo: fn(): string => self::localMountInfo($this->path)
                . "2 1 0:2 / {$this->directory} rw - tmpfs tmpfs rw\n"
                . "3 2 0:3 / {$this->directory} rw - nfs4 server:/ rw\n",
            lstat: function (string $path) use (&$identityStats): array|false {
                if ($path === $this->path) {
                    $identityStats++;
                }

                return self::rootStat(@lstat($path));
            },
        );

        try {
            $reader->read();
            self::fail('An ambiguous stacked network mount was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('qualified local mount', $error->getMessage());
            self::assertSame(0, $identityStats);
        }
    }

    public function test_symlink_or_non_root_owned_file_is_rejected(): void
    {
        $target = $this->directory . '/target.json';
        self::assertTrue(rename($this->path, $target));
        self::assertTrue(symlink($target, $this->path));
        try {
            $this->reader()->read();
            self::fail('Symlinked identity was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ownership or file type', $error->getMessage());
        } finally {
            unlink($this->path);
            self::assertTrue(rename($target, $this->path));
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

    public function test_symlinked_parent_is_rejected_before_following_it_to_the_file(): void
    {
        $alias = $this->directory . '/alias';
        self::assertTrue(symlink($this->directory, $alias));
        $path = $alias . '/identity.json';
        $fileStats = 0;
        $reader = new ProductionEfsIdentityFileReader(
            $path,
            fn(): string => self::localMountInfo($path),
            function (string $entry) use ($path, &$fileStats): array|false {
                if ($entry === $path) {
                    $fileStats++;
                }

                return self::rootStat(@lstat($entry));
            },
        );
        try {
            $reader->read();
            self::fail('Identity through a symlinked parent was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ownership or file type', $error->getMessage());
            self::assertSame(0, $fileStats);
        } finally {
            unlink($alias);
        }
    }

    public function test_inode_swap_or_mount_change_during_read_is_rejected(): void
    {
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
            self::fail('A replaced identity inode was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('changed while reading', $error->getMessage());
        }

        $mountReads = 0;
        $reader = $this->reader(mountInfo: function () use (&$mountReads): string {
            return ++$mountReads === 1
                ? self::localMountInfo($this->path)
                : self::localMountInfo($this->path) . "2 1 0:2 / {$this->directory} rw - nfs4 server:/ rw\n";
        });
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('mount information changed');
        $reader->read();
    }

    public function test_v1_or_identity_on_declared_efs_mount_is_rejected(): void
    {
        $v1 = $this->scope();
        $v1['schemaVersion'] = 1;
        self::assertIsInt(file_put_contents($this->path, self::json($v1)));
        try {
            $this->reader()->read();
            self::fail('V1 qualification identity was accepted.');
        } catch (\InvalidArgumentException $error) {
            self::assertStringContainsString('schema or profile', $error->getMessage());
        }

        $overlap = $this->scope();
        $overlap['mountPoint'] = $this->directory;
        $overlap['storageRoot'] = $this->directory . '/objects';
        self::assertIsInt(file_put_contents($this->path, self::json($overlap)));
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('overlaps a runtime volume');
        $this->reader()->read();
    }

    /**
     * @param (Closure(): (string|false))|null $mountInfo
     * @param (Closure(string): (array<array-key, int>|false))|null $lstat
     */
    private function reader(?Closure $mountInfo = null, ?Closure $lstat = null): ProductionEfsIdentityFileReader
    {
        return new ProductionEfsIdentityFileReader(
            $this->path,
            $mountInfo ?? fn(): string => self::localMountInfo($this->path),
            $lstat ?? static fn(string $path): array|false => self::rootStat(@lstat($path)),
            static fn($handle): array|false => self::rootStat(@fstat($handle)),
        );
    }

    private static function localMountInfo(string $bindPath): string
    {
        return "1 0 0:1 / / rw - ext4 /dev/root rw\n"
            . "2 1 0:2 /identity.json {$bindPath} ro - tmpfs tmpfs rw\n";
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

    /** @return array<string, int|string> */
    private function scope(): array
    {
        return [
            'schemaVersion' => 2,
            'profile' => 'production',
            'deploymentId' => str_repeat('a', 32),
            'instanceId' => str_repeat('b', 32),
            'efsId' => 'fs-0123456789abcdef0',
            'sourceSha256' => str_repeat('c', 64),
            'metadataDriver' => 'postgres',
            'mountPoint' => '/unmounted-efs',
            'storageRoot' => '/unmounted-efs/objects',
            'scratchPath' => '/unmounted-local-scratch',
            'evidenceDirectory' => '/run/s3gate/evidence',
        ];
    }

    /** @param array<string, int|string> $scope */
    private static function json(array $scope): string
    {
        return json_encode($scope, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES);
    }
}
