<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\NullCancellation;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Storage\LocalEfsChunkReadFence;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Sync\createChannelPair;

final class LocalEfsChunkReadFenceTest extends TestCase
{
    private string $path;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-efs-chunk-' . bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
    }

    public function test_held_inode_survives_path_unlink_between_bounded_chunks(): void
    {
        $bytes = str_repeat('a', 1_048_576) . 'tail';
        file_put_contents($this->path, $bytes);
        [$parent, $worker] = createChannelPair();
        $evidence = new ChunkReadEvidence();
        $fence = new LocalEfsChunkReadFence($evidence, static function (string $path): bool {
            self::assertFileExists($path);
            return true;
        });
        $task = async(fn(): null => $fence->run($this->path, null, null, $worker, new NullCancellation()));

        self::assertSame(['chunk' => str_repeat('a', 1_048_576)], $parent->receive());
        unlink($this->path);
        $parent->send('next');
        self::assertSame(['chunk' => 'tail'], $parent->receive());
        $parent->send('next');
        self::assertSame(['eof' => true], $parent->receive());
        $task->await();
    }

    public function test_range_is_exact_and_eof_waits_for_worker_close(): void
    {
        file_put_contents($this->path, '0123456789');
        [$parent, $worker] = createChannelPair();
        $fence = new LocalEfsChunkReadFence(new ChunkReadEvidence(), static fn(): bool => true);
        $task = async(fn(): null => $fence->run($this->path, 3, 4, $worker, new NullCancellation()));

        self::assertSame(['chunk' => '3456'], $parent->receive());
        $parent->send('next');
        self::assertSame(['eof' => true], $parent->receive());
        $task->await();
    }

    public function test_short_range_fails_without_clean_eof(): void
    {
        file_put_contents($this->path, '0123456789');
        [$parent, $worker] = createChannelPair();
        $fence = new LocalEfsChunkReadFence(new ChunkReadEvidence(), static fn(): bool => true);
        $task = async(fn(): null => $fence->run($this->path, 8, 4, $worker, new NullCancellation()));

        self::assertSame(['chunk' => '89'], $parent->receive());
        $parent->send('next');
        try {
            $task->await();
            self::fail('A truncated range was reported as a successful read.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('ended before the requested range', $error->getMessage());
        }
    }

    public function test_evidence_loss_between_credits_never_sends_another_chunk(): void
    {
        file_put_contents($this->path, str_repeat('x', 1_048_577));
        [$parent, $worker] = createChannelPair();
        $evidence = new ChunkReadEvidence();
        $fence = new LocalEfsChunkReadFence($evidence, static fn(): bool => true);
        $task = async(fn(): null => $fence->run($this->path, null, null, $worker, new NullCancellation()));

        self::assertSame(1_048_576, strlen($parent->receive()['chunk']));
        $evidence->revoked = true;
        $parent->send('next');
        try {
            $task->await();
            self::fail('A revoked worker completed its read.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS evidence is unavailable.', $error->getMessage());
        }
    }

    public function test_unconfined_path_is_rejected_before_open_or_channel_send(): void
    {
        file_put_contents($this->path, 'secret');
        [$parent, $worker] = createChannelPair();
        $fence = new LocalEfsChunkReadFence(new ChunkReadEvidence(), static fn(): bool => false);
        try {
            $fence->run($this->path, null, null, $worker, new NullCancellation());
            self::fail('An unconfined path was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('not confined', $error->getMessage());
        }
        self::assertSame('secret', file_get_contents($this->path));
        $parent->close();
        $worker->close();
    }
}

final class ChunkReadEvidence implements EfsHostEvidence
{
    public bool $revoked = false;

    public function assertFresh(): void
    {
        if ($this->revoked) {
            throw new \RuntimeException('EFS evidence is unavailable.');
        }
    }
}
