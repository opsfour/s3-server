<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\ServeCommand;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ProductionEfsServeCommandTest extends TestCase
{
    private string $directory;

    protected function setUp(): void
    {
        parent::setUp();
        $this->directory = (realpath(sys_get_temp_dir()) ?: sys_get_temp_dir())
            . '/s3-production-cli-' . bin2hex(random_bytes(5));
        self::assertTrue(mkdir($this->directory, 0o700));
    }

    protected function tearDown(): void
    {
        rmdir($this->directory);
        parent::tearDown();
    }

    public function test_missing_production_identity_or_linux_evidence_never_creates_storage_root(): void
    {
        $tester = new CommandTester(new ServeCommand());
        $root = $this->directory . '/never-created-efs-root';

        self::assertSame(Command::FAILURE, $tester->execute($this->options($root)));
        self::assertSame("Error: EFS production identity or runtime admission failed.\n", $tester->getDisplay());
        self::assertDirectoryDoesNotExist($root);
    }

    /** @param array<string, string> $changes */
    #[DataProvider('mixedOrUnsafeProfileProvider')]
    public function test_mixed_or_unsafe_profile_fails_before_identity_and_root_access(array $changes): void
    {
        $tester = new CommandTester(new ServeCommand());
        $root = $this->directory . '/never-created-efs-root';
        $options = array_replace($this->options($root), $changes);

        self::assertSame(Command::FAILURE, $tester->execute($options));
        self::assertSame("Error: EFS production identity or runtime admission failed.\n", $tester->getDisplay());
        self::assertDirectoryDoesNotExist($root);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function mixedOrUnsafeProfileProvider(): iterable
    {
        yield 'v1 run ID' => [['--efs-run-id' => str_repeat('a', 32)]];
        yield 'v1 evidence directory' => [['--efs-evidence-directory' => '/run/v1-evidence']];
        yield 'unsafe override' => [['--allow-unfenced-storage-for-tests' => 'true']];
        yield 'remote backend instead of sole filesystem tier' => [['--storage-driver' => 'flysystem']];
        yield 'SQLite metadata' => [['--metadata-driver' => 'sqlite']];
        yield 'missing SQL DSN' => [['--metadata-dsn' => '']];
        yield 'metadata cache' => [['--metadata-cache-ttl' => '5']];
        yield 'empty identity path' => [['--efs-identity-file' => '']];
    }

    /** @return array<string, string> */
    private function options(string $root): array
    {
        return [
            '--efs-identity-file' => $this->directory . '/missing-identity.json',
            '--storage-path' => $root,
            '--metadata-driver' => 'postgres',
            '--metadata-dsn' => 'pgsql:host=127.0.0.1;dbname=must-not-connect',
            '--metadata-cache-ttl' => '0',
        ];
    }
}
