<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use PHPUnit\Framework\TestCase;

final class LocalObjectTestPublishPauseIoTest extends TestCase
{
    private string $base;

    private string $scratch;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-test-publish-pause-' . bin2hex(random_bytes(8));
        $this->scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($this->scratch, 0700, true));
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->base);
    }

    public function test_unarmed_link_is_immediate_and_an_armed_timeout_cannot_publish(): void
    {
        $io = new LocalObjectTestPublishPauseIo($this->scratch, 1);
        $source = $this->base . '/source';
        self::assertSame(7, file_put_contents($source, 'payload'));
        self::assertTrue($io->link($source, $this->base . '/unarmed'));
        self::assertSame('payload', file_get_contents($this->base . '/unarmed'));

        $control = $this->scratch . '/' . LocalObjectTestPublishPauseIo::CONTROL_DIRECTORY;
        self::assertSame(0, file_put_contents($control . '/arm', ''));
        self::assertTrue(chmod($control . '/arm', 0600));
        $target = $this->base . '/armed';
        $started = microtime(true);
        try {
            $io->link($source, $target);
            self::fail('The test barrier must time out without a release marker.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('timed out before the physical link', $error->getMessage());
        }
        self::assertLessThan(3.0, microtime(true) - $started);
        self::assertFileDoesNotExist($target);
        self::assertSame([], glob($control . '/ready-*'));
        self::assertSame([], glob($control . '/claimed-*'));
        self::assertSame([], glob($control . '/result-*'));
    }

    public function test_public_or_symlink_arm_markers_fail_before_publication(): void
    {
        $io = new LocalObjectTestPublishPauseIo($this->scratch, 1);
        $control = $this->scratch . '/' . LocalObjectTestPublishPauseIo::CONTROL_DIRECTORY;
        $source = $this->base . '/source';
        self::assertSame(7, file_put_contents($source, 'payload'));
        self::assertSame(0, file_put_contents($control . '/arm', ''));
        self::assertTrue(chmod($control . '/arm', 0644));

        try {
            $io->link($source, $this->base . '/public-target');
            self::fail('A public arm marker must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('private and empty', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->base . '/public-target');

        self::assertTrue(unlink($control . '/arm'));
        self::assertTrue(symlink($source, $control . '/arm'));
        try {
            $io->link($source, $this->base . '/symlink-target');
            self::fail('A symbolic-link arm marker must fail closed.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('private and empty', $error->getMessage());
        }
        self::assertFileDoesNotExist($this->base . '/symlink-target');
    }

    public function test_stale_release_marker_cannot_skip_the_pause(): void
    {
        $io = new LocalObjectTestPublishPauseIo($this->scratch, 1);
        $control = $this->scratch . '/' . LocalObjectTestPublishPauseIo::CONTROL_DIRECTORY;
        $source = $this->base . '/source';
        $target = $this->base . '/target';
        self::assertSame(7, file_put_contents($source, 'payload'));
        self::assertSame(0, file_put_contents($control . '/arm', ''));
        self::assertTrue(chmod($control . '/arm', 0600));
        $release = $control . '/release-' . hash('sha256', $target);
        self::assertSame(0, file_put_contents($release, ''));
        self::assertTrue(chmod($release, 0600));

        try {
            $io->link($source, $target);
            self::fail('A pre-existing release marker must not permit publication.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('already exists', $error->getMessage());
        }
        self::assertFileDoesNotExist($target);
        self::assertFileExists($control . '/arm');
    }

    public function test_invalid_timeout_is_rejected(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalObjectTestPublishPauseIo($this->scratch, 601);
    }

    public function test_public_scratch_is_rejected(): void
    {
        self::assertTrue(chmod($this->scratch, 0755));
        $this->expectException(\InvalidArgumentException::class);
        new LocalObjectTestPublishPauseIo($this->scratch);
    }
}
