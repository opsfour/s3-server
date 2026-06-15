<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Console;

use OpsFour\S3Server\Console\QuotaCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class QuotaCommandTest extends TestCase
{
    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/s3-quota-command-' . bin2hex(random_bytes(4));
        mkdir($this->storagePath, 0o755, true);
    }

    protected function tearDown(): void
    {
        if ($this->storagePath !== '' && is_dir($this->storagePath)) {
            $this->recursiveDelete($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_set_show_list_and_delete_account_quota(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute([
            'action' => 'set',
            'owner-id' => 'account-a',
            '--storage-path' => $this->storagePath,
            '--max-buckets' => '2',
            '--max-objects-per-bucket' => '10',
            '--max-bytes-per-bucket' => '1024',
            '--max-bytes' => '4096',
        ]);
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('Quota saved for owner account-a', $tester->getDisplay());

        $tester = $this->tester();
        $exit = $tester->execute([
            'action' => 'show',
            'owner-id' => 'account-a',
            '--storage-path' => $this->storagePath,
        ]);
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('account-a', $tester->getDisplay());
        $this->assertStringContainsString('4096', $tester->getDisplay());

        $tester = $this->tester();
        $exit = $tester->execute([
            'action' => 'list',
            '--storage-path' => $this->storagePath,
        ]);
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('account-a', $tester->getDisplay());

        $tester = $this->tester();
        $exit = $tester->execute([
            'action' => 'delete',
            'owner-id' => 'account-a',
            '--storage-path' => $this->storagePath,
        ]);
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());

        $tester = $this->tester();
        $exit = $tester->execute([
            'action' => 'show',
            'owner-id' => 'account-a',
            '--storage-path' => $this->storagePath,
        ]);
        $this->assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
        $this->assertStringContainsString('No account-specific quota configured', $tester->getDisplay());
    }

    public function test_set_rejects_negative_or_non_integer_values(): void
    {
        $tester = $this->tester();

        $exit = $tester->execute([
            'action' => 'set',
            'owner-id' => 'account-a',
            '--storage-path' => $this->storagePath,
            '--max-buckets' => '-1',
        ]);

        $this->assertSame(Command::FAILURE, $exit, $tester->getDisplay());
        $this->assertStringContainsString('--max-buckets must be an integer >= 0', $tester->getDisplay());
    }

    private function tester(): CommandTester
    {
        return new CommandTester(new QuotaCommand());
    }

    private function recursiveDelete(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}
