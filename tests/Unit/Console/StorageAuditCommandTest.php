<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\StorageAuditCommand;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\SqliteStorageIntegrityReferenceEnumerator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;
use Symfony\Component\Process\Process;

final class StorageAuditCommandTest extends TestCase
{
    private string $root;

    private string $databasePath;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s3-storage-command-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0o700, true));
        $canonical = realpath($this->root);
        self::assertNotFalse($canonical);
        $this->root = $canonical;
        $this->databasePath = $this->root . '/metadata.sqlite';
    }

    protected function tearDown(): void
    {
        foreach (glob($this->root . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->root);
    }

    public function test_command_passes_read_only_and_paginates_all_object_versions(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        for ($index = 1; $index <= 3; ++$index) {
            $path = $this->root . '/object-' . $index;
            self::assertSame($index, file_put_contents($path, str_repeat('x', $index)));
            $metadata->putObjectMetadata('bucket', 'key-' . $index, 'owner', $index, 'etag', 'text/plain', $path);
        }
        $plainPart = $this->root . '/part-plain';
        self::assertSame(3, file_put_contents($plainPart, 'abc'));
        $metadata->createMultipartUpload('plain-upload', 'bucket', 'plain-key', 'owner');
        $metadata->putPart('plain-upload', 1, 'etag', 3, $plainPart);
        $encryptedPart = $this->root . '/part-encrypted';
        self::assertSame(100, file_put_contents($encryptedPart, str_repeat('c', 100)));
        $metadata->createMultipartUpload(
            'encrypted-upload',
            'bucket',
            'encrypted-key',
            'owner',
            userMetadata: ['__mpu-part-format' => 'encrypted-v2'],
        );
        $metadata->putPart('encrypted-upload', 1, 'etag', 3, $encryptedPart);
        clearstatcache();

        $enumerator = new SqliteStorageIntegrityReferenceEnumerator($this->databasePath);
        self::assertCount(2, $enumerator->page('object', null, 2));
        self::assertCount(1, $enumerator->page('object', '2', 2));
        $partRows = $enumerator->page('multipart_part', null, 10);
        self::assertSame([3, null], array_column($partRows, 'expected_size'));

        $tester = new CommandTester(new StorageAuditCommand());
        $exit = $tester->execute([
            '--metadata-driver' => 'sqlite',
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
            '--page-size' => '2',
        ]);

        self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        self::assertStringContainsString('Checked: 5; failed: 0', $tester->getDisplay());
        self::assertStringNotContainsString($this->root, $tester->getDisplay());
        self::assertSame(3, count(glob($this->root . '/object-*') ?: []));
    }

    public function test_command_fails_for_a_missing_file_and_discloses_only_hashes(): void
    {
        $metadata = new SqliteMetadataStore($this->databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $missing = $this->root . '/private-object-name';
        $metadata->putObjectMetadata('bucket', 'private-key-name', 'owner', 7, 'etag', 'text/plain', $missing);

        $tester = new CommandTester(new StorageAuditCommand());
        $exit = $tester->execute([
            '--metadata-path' => $this->databasePath,
            '--storage-path' => $this->root,
        ]);

        self::assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        self::assertStringContainsString(hash('sha256', $missing), $tester->getDisplay());
        self::assertStringNotContainsString($missing, $tester->getDisplay());
        self::assertStringNotContainsString('private-key-name', $tester->getDisplay());
    }

    public function test_driver_configuration_is_validated_without_creating_metadata(): void
    {
        foreach (['postgres', 'mysql', 'invalid'] as $driver) {
            $tester = new CommandTester(new StorageAuditCommand());
            self::assertSame(Command::FAILURE, $tester->execute([
                '--metadata-driver' => $driver,
                '--metadata-dsn' => '',
                '--storage-path' => $this->root,
            ]), $tester->getDisplay());
        }
        self::assertFileDoesNotExist($this->root . '/.opsfour/metadata.sqlite');
    }

    public function test_binary_registers_offline_audit_help(): void
    {
        $process = new Process([PHP_BINARY, __DIR__ . '/../../../bin/s3-server', 'storage:audit', '--help']);
        $process->run();

        self::assertTrue($process->isSuccessful(), $process->getErrorOutput());
        self::assertStringContainsString('Verify local storage references while the server is offline', $process->getOutput());
        self::assertStringContainsString('--metadata-dsn', $process->getOutput());
        self::assertStringContainsString('--storage-path', $process->getOutput());
    }
}
