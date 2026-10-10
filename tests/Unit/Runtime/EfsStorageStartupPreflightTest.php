<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Console\ServeCommand;
use OpsFour\S3Server\Runtime\EfsLocalStartupEvidence;
use OpsFour\S3Server\Runtime\EfsStorageStartupPreflight;
use OpsFour\S3Server\Runtime\EfsStartupIdentityFactory;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class EfsStorageStartupPreflightTest extends TestCase
{
    private string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tempDir = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
            . '/s3-efs-startup-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o700);
    }

    protected function tearDown(): void
    {
        @rmdir($this->tempDir . '/storage');
        @rmdir($this->tempDir);
        parent::tearDown();
    }

    public function test_missing_local_evidence_prevents_all_mount_and_storage_operations(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $evidence = new class implements EfsLocalStartupEvidence {
            public function assertFresh(): void
            {
                throw new \RuntimeException('Evidence missing.');
            }

            public function assertStorageIdentity(string $canonicalRoot): void {}
        };
        $mountReads = 0;
        $preflight = new EfsStorageStartupPreflight($evidence, static function () use (&$mountReads): string {
            ++$mountReads;

            return 'unreachable';
        });

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath)));
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
        self::assertSame(0, $mountReads);
        self::assertDirectoryDoesNotExist($storagePath);
    }

    public function test_missing_storage_root_is_never_created_after_fresh_evidence(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                ++$this->checks;
            }

            public function assertStorageIdentity(string $canonicalRoot): void {}
        };
        $preflight = new EfsStorageStartupPreflight($evidence, fn(): string => $this->mountInfo('nfs4', $storagePath));

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath)));
        self::assertSame(2, $evidence->checks);
        self::assertDirectoryDoesNotExist($storagePath);
    }

    public function test_mount_replacement_rejects_before_backend_or_bind(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $evidence = new class implements EfsLocalStartupEvidence {
            public function assertFresh(): void {}

            public function assertStorageIdentity(string $canonicalRoot): void {}
        };
        $reads = 0;
        $preflight = new EfsStorageStartupPreflight($evidence, function () use (&$reads, $storagePath): string {
            return $this->mountInfo(++$reads === 1 ? 'nfs4' : 'ext4', $storagePath);
        });

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath)));
        self::assertSame(2, $reads);
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
        self::assertDirectoryExists($storagePath);
        self::assertSame([], array_values(array_diff(scandir($storagePath) ?: [], ['.', '..'])));
    }

    public function test_existing_root_on_local_mount_is_rejected_without_backend_start(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $preflight = new EfsStorageStartupPreflight(new class implements EfsLocalStartupEvidence {
            public function assertFresh(): void {}

            public function assertStorageIdentity(string $canonicalRoot): void {}
        }, fn(): string => $this->mountInfo('ext4', $storagePath));

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath)));
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
        self::assertStringNotContainsString($storagePath, $tester->getDisplay());
        self::assertSame([], array_values(array_diff(scandir($storagePath) ?: [], ['.', '..'])));
    }

    public function test_internal_preflight_rejects_unsafe_test_override_without_touching_storage(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                ++$this->checks;
            }

            public function assertStorageIdentity(string $canonicalRoot): void {}
        };
        $preflight = new EfsStorageStartupPreflight($evidence);

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--storage-path' => $storagePath,
            '--allow-unfenced-storage-for-tests' => 'true',
        ]));
        self::assertSame(0, $evidence->checks);
        self::assertDirectoryDoesNotExist($storagePath);
    }

    public function test_internal_preflight_rejects_sqlite_before_evidence_or_metadata_file_creation(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                ++$this->checks;
            }

            public function assertStorageIdentity(string $canonicalRoot): void
            {
                ++$this->checks;
            }
        };
        $mountReads = 0;
        $preflight = new EfsStorageStartupPreflight($evidence, static function () use (&$mountReads): string {
            ++$mountReads;

            return 'unreachable';
        });

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--storage-path' => $storagePath,
            '--metadata-driver' => 'sqlite',
            '--metadata-cache-ttl' => '0',
        ]));
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
        self::assertSame(0, $evidence->checks);
        self::assertSame(0, $mountReads);
        self::assertSame([], array_values(array_diff(scandir($storagePath) ?: [], ['.', '..'])));
    }

    public function test_internal_preflight_rejects_empty_sql_dsn_before_evidence_or_backend_creation(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                ++$this->checks;
            }

            public function assertStorageIdentity(string $canonicalRoot): void
            {
                ++$this->checks;
            }
        };
        $preflight = new EfsStorageStartupPreflight($evidence);

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute([
            '--storage-path' => $storagePath,
            '--metadata-driver' => 'postgres',
            '--metadata-dsn' => ' ',
            '--metadata-cache-ttl' => '0',
        ]));
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
        self::assertSame(0, $evidence->checks);
        self::assertSame([], array_values(array_diff(scandir($storagePath) ?: [], ['.', '..'])));
    }

    public function test_internal_preflight_rejects_cached_sql_metadata_before_evidence_or_backend_creation(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                ++$this->checks;
            }

            public function assertStorageIdentity(string $canonicalRoot): void
            {
                ++$this->checks;
            }
        };
        $preflight = new EfsStorageStartupPreflight($evidence);

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute(array_merge($this->efsCliOptions($storagePath), [
            '--metadata-cache-ttl' => '5',
        ])));
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
        self::assertSame(0, $evidence->checks);
        self::assertSame([], array_values(array_diff(scandir($storagePath) ?: [], ['.', '..'])));
    }

    public function test_evidence_loss_after_path_check_rejects_before_root_stat(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                if (++$this->checks === 3) {
                    throw new \RuntimeException('Watcher stopped.');
                }
            }

            public function assertStorageIdentity(string $canonicalRoot): void
            {
                throw new \LogicException('Root was accessed after evidence loss.');
            }
        };
        $preflight = new EfsStorageStartupPreflight($evidence, fn(): string => $this->mountInfo('nfs4', $storagePath));

        $tester = new CommandTester(new ServeCommand(efsStartupPreflight: $preflight));
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath)));
        self::assertSame(3, $evidence->checks);
        self::assertStringContainsString('EFS startup preflight failed', $tester->getDisplay());
    }

    public function test_evidence_loss_during_mountinfo_read_rejects_before_root_resolution(): void
    {
        $storagePath = $this->tempDir . '/missing-storage';
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                if (++$this->checks === 2) {
                    throw new \RuntimeException('Watcher lost during mountinfo read.');
                }
            }

            public function assertStorageIdentity(string $canonicalRoot): void
            {
                \PHPUnit\Framework\Assert::fail('Storage identity was read after evidence loss.');
            }
        };
        $preflight = new EfsStorageStartupPreflight($evidence, fn(): string => $this->mountInfo('nfs4', $storagePath));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Watcher lost during mountinfo read.');
        try {
            $preflight->assertExistingRoot($storagePath);
        } finally {
            self::assertSame(2, $evidence->checks);
            self::assertDirectoryDoesNotExist($storagePath);
        }
    }

    public function test_successful_preflight_checks_identity_and_evidence_again(): void
    {
        $storagePath = $this->tempDir . '/storage';
        mkdir($storagePath, 0o700);
        $evidence = new class implements EfsLocalStartupEvidence {
            public int $checks = 0;

            public ?string $checkedRoot = null;

            public function assertFresh(): void
            {
                ++$this->checks;
            }

            public function assertStorageIdentity(string $canonicalRoot): void
            {
                $this->checkedRoot = $canonicalRoot;
            }
        };
        $reads = 0;
        $preflight = new EfsStorageStartupPreflight($evidence, function () use (&$reads, $storagePath): string {
            ++$reads;

            return $this->mountInfo('nfs4', $storagePath);
        });

        self::assertSame($storagePath, $preflight->assertExistingRoot($storagePath));
        self::assertSame($storagePath, $evidence->checkedRoot);
        self::assertSame(4, $evidence->checks);
        self::assertSame(3, $reads);
    }

    public function test_default_serve_command_still_creates_local_storage_root(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $tester = new CommandTester(new ServeCommand());

        self::assertSame(Command::FAILURE, $tester->execute([
            '--storage-path' => $storagePath,
            '--metadata-driver' => 'invalid-driver',
        ]));
        self::assertDirectoryExists($storagePath);
    }

    public function test_partial_explicit_efs_identity_fails_before_creating_storage_or_metadata(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $tester = new CommandTester(new ServeCommand());
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath) + [
            '--efs-evidence-directory' => $this->tempDir . '/missing-evidence',
        ]));
        self::assertStringContainsString('EFS startup identity or runtime admission failed', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($storagePath);
        self::assertFileDoesNotExist($storagePath . '/metadata.sqlite');
    }

    public function test_missing_local_evidence_rejects_complete_cli_identity_before_storage_root(): void
    {
        $storagePath = $this->tempDir . '/storage';
        $factory = new EfsStartupIdentityFactory(fn(): string => $this->mountInfo('nfs4', $storagePath));
        $tester = new CommandTester(new ServeCommand(efsStartupIdentityFactory: $factory));
        self::assertSame(Command::FAILURE, $tester->execute($this->efsCliOptions($storagePath) + $this->efsIdentityOptions()));
        self::assertStringContainsString('EFS startup identity or runtime admission failed', $tester->getDisplay());
        self::assertDirectoryDoesNotExist($storagePath);
        self::assertFileDoesNotExist($storagePath . '/metadata.sqlite');
    }

    public function test_explicit_efs_identity_rejects_unsafe_or_cached_metadata_profile_without_storage_access(): void
    {
        foreach ([
            ['--allow-unfenced-storage-for-tests' => 'true'],
            ['--metadata-cache-ttl' => '5'],
            ['--metadata-driver' => 'sqlite'],
            ['--metadata-dsn' => ''],
        ] as $override) {
            $storagePath = $this->tempDir . '/storage';
            $tester = new CommandTester(new ServeCommand());
            self::assertSame(Command::FAILURE, $tester->execute(array_replace(
                $this->efsCliOptions($storagePath) + $this->efsIdentityOptions(),
                $override,
            )));
            self::assertDirectoryDoesNotExist($storagePath);
        }
    }

    /** @return array<string, string> */
    private function efsIdentityOptions(): array
    {
        return [
            '--efs-evidence-directory' => $this->tempDir . '/missing-evidence',
            '--efs-run-id' => str_repeat('a', 32),
            '--efs-id' => 'fs-12345678',
            '--efs-node' => 'api-one',
            '--efs-source-sha256' => str_repeat('b', 64),
            '--efs-scratch-path' => $this->tempDir . '/missing-scratch',
        ];
    }

    private function mountInfo(string $type, string $mountPoint): string
    {
        return "1 0 0:1 / / rw - ext4 /dev/root rw\n2 1 0:2 / {$mountPoint} rw - {$type} 127.0.0.1:/ rw\n";
    }

    /** @return array<string, string> */
    private function efsCliOptions(string $storagePath): array
    {
        return [
            '--storage-path' => $storagePath,
            '--metadata-driver' => 'mysql',
            '--metadata-dsn' => 'mysql:host=127.0.0.1;dbname=efs_preflight_test',
            '--metadata-cache-ttl' => '0',
        ];
    }
}
