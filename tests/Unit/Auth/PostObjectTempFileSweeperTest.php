<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Auth;

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Auth\PostObjectFormParser;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\PostObjectTempFileSweeper;
use OpsFour\S3Server\Contracts\CredentialProvider;
use PHPUnit\Framework\TestCase;

final class PostObjectTempFileSweeperTest extends TestCase
{
    public function test_parser_default_sweeper_uses_injected_directory_without_age_only_deletion(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-injected-sweep-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $stalePost = $tempDir . '/s3-post-abandoned.tmp';
        self::assertSame(9, file_put_contents($stalePost, 'abandoned'));
        self::assertTrue(touch($stalePost, time() - 90_000));
        $pool = new ContextWorkerPool(1);
        $coordinator = new PostObjectSpoolCoordinator($tempDir, $pool);
        $parser = new PostObjectFormParser(
            $this->createStub(CredentialProvider::class),
            'us-east-1',
            1_048_576,
            spoolCoordinator: $coordinator,
        );

        try {
            $sweeper = (new \ReflectionProperty($parser, 'tempFileSweeper'))->getValue($parser);
            self::assertInstanceOf(PostObjectTempFileSweeper::class, $sweeper);
            $sweeper->sweepIfDue();
            self::assertFileExists($stalePost);
        } finally {
            $pool->shutdown();
            @unlink($stalePost);
            foreach (glob($tempDir . '/.opsfour-s3-post-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-post-budget');
            @rmdir($tempDir);
        }
    }

    public function test_alias_sweeper_preserves_an_old_file_reserved_by_canonical_directory(): void
    {
        $root = sys_get_temp_dir() . '/s3-post-sweep-alias-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $tempDir = $root . '/real';
        self::assertTrue(mkdir($tempDir, 0700));
        $alias = $root . '/alias';
        self::assertTrue(symlink($tempDir, $alias));
        $activePath = $tempDir . '/s3-post-active.tmp';
        $coordinator = new PostObjectSpoolCoordinator($tempDir, runInline: true, budgetBytes: 4);
        $reservation = $coordinator->start($activePath);
        $coordinator->reserve($reservation, 4, 4);
        self::assertSame(4, file_put_contents($activePath, 'data'));
        self::assertTrue(touch($activePath, time() - 90_000));
        $pool = new ContextWorkerPool(1);

        try {
            (new PostObjectTempFileSweeper($pool, $alias))->sweepIfDue();
            self::assertFileExists($activePath);
        } finally {
            $pool->shutdown();
            @unlink($activePath);
            $coordinator->release($reservation, true);
            foreach (glob($tempDir . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
                @unlink($path);
            }
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @unlink($alias);
            @rmdir($tempDir);
            @rmdir($root);
        }
    }

    public function test_it_never_reclaims_unaccounted_files_from_age_alone(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-sweep-test-' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0o755, true);
        $stalePost = $tempDir . '/s3-post-abandoned.tmp';
        $recentPost = $tempDir . '/s3-post-active.tmp';
        $staleOther = $tempDir . '/unrelated-stale.tmp';
        file_put_contents($stalePost, 'abandoned');
        file_put_contents($recentPost, 'active');
        file_put_contents($staleOther, 'unrelated');
        touch($stalePost, time() - 90_000);
        touch($staleOther, time() - 90_000);

        $pool = new ContextWorkerPool(1);
        try {
            $sweeper = new PostObjectTempFileSweeper($pool, $tempDir);
            $sweeper->sweepIfDue();
            self::assertFileExists($stalePost);
            self::assertFileExists($recentPost);
            self::assertFileExists($staleOther);
        } finally {
            $pool->shutdown();
            @unlink($stalePost);
            @unlink($recentPost);
            @unlink($staleOther);
            @rmdir($tempDir);
        }
    }

    public function test_it_keeps_both_reserved_and_unaccounted_old_files(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-post-sweep-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0700));
        $activePath = $tempDir . '/s3-post-live.tmp';
        $abandonedPath = $tempDir . '/s3-post-abandoned.tmp';
        $coordinator = new PostObjectSpoolCoordinator($tempDir, runInline: true, budgetBytes: 100);
        $reservation = $coordinator->start($activePath);
        $coordinator->reserve($reservation, 6, 100);
        file_put_contents($activePath, 'active');
        touch($activePath, time() - 90_000);
        file_put_contents($abandonedPath, 'abandoned');
        touch($abandonedPath, time() - 90_000);
        $pool = new ContextWorkerPool(1);
        try {
            (new PostObjectTempFileSweeper($pool, $tempDir))->sweepIfDue();
            self::assertFileExists($activePath);
            self::assertFileExists($abandonedPath);
        } finally {
            $pool->shutdown();
            @unlink($activePath);
            $coordinator->release($reservation, true);
            @unlink($abandonedPath);
            @unlink($tempDir . '/.opsfour-s3-storage-budget/lock');
            @rmdir($tempDir . '/.opsfour-s3-storage-budget');
            @rmdir($tempDir);
        }
    }
}
