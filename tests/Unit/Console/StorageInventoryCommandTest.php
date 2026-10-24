<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\StorageInventoryCommand;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\LocalStorageRootLocks;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class StorageInventoryCommandTest extends TestCase
{
    private string $root;

    private string $databasePath;

    protected function setUp(): void
    {
        $root = sys_get_temp_dir() . '/s3-storage-inventory-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o700, true));
        $this->root = realpath($root) ?: throw new \RuntimeException('Test root is unavailable.');
        $this->databasePath = sys_get_temp_dir() . '/s3-storage-inventory-metadata-' . bin2hex(random_bytes(8)) . '.sqlite';
    }

    protected function tearDown(): void
    {
        $this->remove($this->root);
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
        @unlink($this->databasePath . '-journal');
    }

    public function test_command_finds_one_orphan_without_disclosing_or_deleting_it(): void
    {
        $referenced = $this->root . '/referenced-object';
        $orphan = $this->root . '/private-orphan';
        self::assertSame(4, file_put_contents($referenced, 'live'));
        self::assertSame(6, file_put_contents($orphan, 'orphan'));

        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->putObjectMetadata('bucket', 'key', 'owner', 4, 'etag', 'text/plain', $referenced);

        $mountInfo = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        $tester = new CommandTester(new StorageInventoryCommand($mountInfo));
        $exit = $tester->execute([
            '--metadata-driver' => 'sqlite',
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
            '--page-size' => '1',
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString(hash('sha256', $orphan), $tester->getDisplay());
        self::assertStringNotContainsString(hash('sha256', $referenced), $tester->getDisplay());
        self::assertStringNotContainsString($orphan, $tester->getDisplay());
        self::assertStringContainsString('orphan candidates: 1', $tester->getDisplay());
        self::assertFileExists($orphan);
        self::assertFileExists($referenced);
    }

    public function test_command_resolves_restore_targets_to_the_configured_default_tier(): void
    {
        $coldRoot = $this->root . '-cold';
        self::assertTrue(mkdir($coldRoot, 0o700));
        $coldRoot = realpath($coldRoot) ?: throw new \RuntimeException('Cold root is unavailable.');
        $source = $coldRoot . '/restore-source';
        $target = $this->root . '/restore-target';
        self::assertSame(6, file_put_contents($source, 'source'));
        self::assertSame(6, file_put_contents($target, 'target'));

        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $writer = new \PDO('sqlite:' . $this->databasePath);
        $insert = $writer->prepare(<<<'SQL'
            INSERT INTO s3_restore_jobs
                (bucket, key_name, source_tier, source_storage_path, restored_storage_path,
                 restore_days, status, next_attempt_at)
            VALUES ('bucket', 'key', 'COLD', ?, ?, 1, 'processing', 0)
            SQL);
        self::assertTrue($insert->execute([$source, $target]));
        unset($writer);

        try {
            $tester = new CommandTester(new StorageInventoryCommand(
                static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
            ));
            $exit = $tester->execute([
                '--metadata-path' => $this->databasePath,
                '--storage-tier-root' => [
                    json_encode(['HOT', $this->root], JSON_THROW_ON_ERROR),
                    json_encode(['COLD', $coldRoot], JSON_THROW_ON_ERROR),
                ],
                '--default-storage-tier' => 'HOT',
                '--page-size' => '1',
            ]);

            self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
            self::assertStringContainsString('orphan candidates: 0', $tester->getDisplay());
        } finally {
            $this->remove($coldRoot);
        }
    }

    public function test_command_excludes_the_canonical_sqlite_database_and_all_sidecars(): void
    {
        $metadataDirectory = $this->root . '/metadata';
        self::assertTrue(mkdir($metadataDirectory, 0o700));
        $canonicalPath = $this->root . '/metadata.sqlite';
        $configuredPath = $metadataDirectory . '/../metadata.sqlite';

        $metadata = new SqliteMetadataStore($configuredPath);
        $metadata->initialize();
        unset($metadata);
        self::assertTrue(touch($canonicalPath . '-journal'));

        $tester = new CommandTester(new StorageInventoryCommand(
            static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
        ));
        $exit = $tester->execute([
            '--metadata-path' => $configuredPath,
            '--storage-path' => $this->root,
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('orphan candidates: 0', $tester->getDisplay());
        self::assertFileExists($canonicalPath);
        self::assertFileExists($canonicalPath . '-journal');
    }

    public function test_command_validates_scratch_constraints_and_candidate_cursor(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $mountInfo = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";

        $tooSmall = new CommandTester(new StorageInventoryCommand($mountInfo));
        self::assertSame(Command::FAILURE, $tooSmall->execute([
            '--scratch-max-bytes' => '1048575',
        ]));
        self::assertStringContainsString('--scratch-max-bytes must be between', $tooSmall->getDisplay());

        $overlap = new CommandTester(new StorageInventoryCommand($mountInfo));
        self::assertSame(Command::FAILURE, $overlap->execute([
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
            '--scratch-path' => $this->root,
            '--scratch-max-bytes' => '1048576',
        ]));
        self::assertMatchesRegularExpression('/disjoint from every storage\s+tier\s+root/', $overlap->getDisplay());

        $invalidCursor = new CommandTester(new StorageInventoryCommand($mountInfo));
        self::assertSame(Command::FAILURE, $invalidCursor->execute([
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
            '--scratch-max-bytes' => '1048576',
            '--after-candidate' => 'not+a+base64url+cursor',
        ]));
    }

    public function test_command_returns_an_opaque_cursor_for_a_deterministic_next_candidate_page(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $expected = [];
        for ($i = 0; $i < 501; ++$i) {
            $path = $this->root . '/candidate-' . $i;
            self::assertSame(1, file_put_contents($path, 'x'));
            $expected[] = hash('sha256', $path);
        }
        sort($expected, SORT_STRING);

        $options = [
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
        ];
        $mountInfo = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";
        $first = new CommandTester(new StorageInventoryCommand($mountInfo));
        self::assertSame(Command::SUCCESS, $first->execute($options), $first->getDisplay());
        self::assertMatchesRegularExpression('/Next candidate cursor: ([A-Za-z0-9_-]+)/', $first->getDisplay());
        self::assertSame(1, preg_match('/Next candidate cursor: ([A-Za-z0-9_-]+)/', $first->getDisplay(), $match));
        $cursor = $match[1] ?? null;
        self::assertIsString($cursor);
        self::assertNotSame('', $cursor);

        $second = new CommandTester(new StorageInventoryCommand($mountInfo));
        self::assertSame(Command::SUCCESS, $second->execute([
            ...$options,
            '--after-candidate' => $cursor,
        ]), $second->getDisplay());
        self::assertStringContainsString($expected[500], $second->getDisplay());
        self::assertStringNotContainsString($expected[499], $second->getDisplay());
        self::assertStringNotContainsString('Next candidate cursor:', $second->getDisplay());
        self::assertStringContainsString('orphan candidates: 501', $second->getDisplay());
    }

    public function test_command_rejects_ambiguous_tier_configuration(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $nested = $this->root . '/nested';
        self::assertTrue(mkdir($nested, 0o700));
        $sibling = $this->root . '-sibling';
        self::assertTrue(mkdir($sibling, 0o700));
        $sibling = realpath($sibling) ?: throw new \RuntimeException('Sibling root is unavailable.');
        $mountInfo = static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n";

        try {
            foreach ([
                [
                    '--storage-path' => $this->root,
                    '--storage-tier-root' => [json_encode(['STANDARD', $this->root], JSON_THROW_ON_ERROR)],
                ],
                [
                    '--storage-tier-root' => [
                        json_encode(['HOT', $this->root], JSON_THROW_ON_ERROR),
                        json_encode(['COLD', $nested], JSON_THROW_ON_ERROR),
                    ],
                    '--default-storage-tier' => 'HOT',
                ],
                [
                    '--storage-tier-root' => [
                        json_encode(['HOT', $this->root], JSON_THROW_ON_ERROR),
                        json_encode(['COLD', $sibling], JSON_THROW_ON_ERROR),
                    ],
                ],
            ] as $options) {
                $tester = new CommandTester(new StorageInventoryCommand($mountInfo));
                self::assertSame(Command::FAILURE, $tester->execute([
                    '--metadata-path' => $this->databasePath,
                    ...$options,
                ]), $tester->getDisplay());
            }
        } finally {
            $this->remove($sibling);
        }
    }

    public function test_command_refuses_a_storage_root_owned_by_a_live_writer(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $locks = LocalStorageRootLocks::create();
        $locks->acquireRoots([$this->root]);

        try {
            $tester = new CommandTester(new StorageInventoryCommand(
                static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
            ));
            $exit = $tester->execute([
                '--metadata-path' => $this->databasePath,
                '--storage-path' => $this->root,
            ]);

            self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
            self::assertStringContainsString('Storage inventory failed (RuntimeException)', $tester->getDisplay());
        } finally {
            $locks->release();
        }

        $afterRelease = new CommandTester(new StorageInventoryCommand(
            static fn(): string => "1 0 0:1 / / rw - ext4 /dev/mock rw\n",
        ));
        self::assertSame(Command::SUCCESS, $afterRelease->execute([
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
        ]), $afterRelease->getDisplay());
    }

    public function test_binary_registers_offline_inventory_help(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../bin/s3-server', 'storage:inventory', '--help']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('Report unreferenced local storage paths while all writers are stopped', $process->getOutput());
        self::assertStringContainsString('--scratch-max-bytes', $process->getOutput());
        self::assertStringContainsString('--after-candidate', $process->getOutput());
    }

    private function remove(string $path): void
    {
        if (is_file($path) || is_link($path)) {
            @unlink($path);
            return;
        }
        if (! is_dir($path)) {
            return;
        }
        foreach (new \DirectoryIterator($path) as $entry) {
            if (! $entry->isDot()) {
                $this->remove($entry->getPathname());
            }
        }
        @rmdir($path);
    }
}
