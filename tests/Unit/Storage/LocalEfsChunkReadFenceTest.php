<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\NullCancellation;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Storage\LocalEfsChunkReadFence;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Sync\createChannelPair;

final class LocalEfsChunkReadFenceTest extends TestCase
{
    private string $path;

    private string $root;

    private string $evidenceDirectory;

    private string $evidencePath;

    protected function setUp(): void
    {
        $temporaryRoot = realpath(sys_get_temp_dir());
        self::assertIsString($temporaryRoot);
        $this->root = $temporaryRoot . '/s3-efs-chunk-' . bin2hex(random_bytes(8));
        $this->evidenceDirectory = $this->root . '-evidence';
        self::assertTrue(mkdir($this->root, 0o700));
        self::assertTrue(mkdir($this->evidenceDirectory, 0o700));
        $this->path = $this->root . '/object';
        $this->evidencePath = $this->evidenceDirectory . '/' . str_repeat('a', 32) . '-api-one-postgres.json';
        $this->writeEvidence();
    }

    protected function tearDown(): void
    {
        if (is_file($this->path)) {
            unlink($this->path);
        }
        if (is_file($this->evidencePath)) {
            unlink($this->evidencePath);
        }
        if (is_dir($this->root)) {
            rmdir($this->root);
        }
        if (is_dir($this->evidenceDirectory)) {
            rmdir($this->evidenceDirectory);
        }
    }

    public function test_held_inode_survives_path_unlink_between_bounded_chunks(): void
    {
        $bytes = str_repeat('a', 1_048_576) . 'tail';
        file_put_contents($this->path, $bytes);
        [$parent, $worker] = createChannelPair();
        $evidence = $this->evidence();
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
        $fence = new LocalEfsChunkReadFence($this->evidence(), static fn(): bool => true);
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
        $fence = new LocalEfsChunkReadFence($this->evidence(), static fn(): bool => true);
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
        $evidence = $this->evidence();
        $fence = new LocalEfsChunkReadFence($evidence, static fn(): bool => true);
        $task = async(fn(): null => $fence->run($this->path, null, null, $worker, new NullCancellation()));

        self::assertSame(1_048_576, strlen($parent->receive()['chunk']));
        self::assertTrue(unlink($this->evidencePath));
        $parent->send('next');
        try {
            $task->await();
            self::fail('A revoked worker completed its read.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString('evidence', strtolower($error->getMessage()));
        }
    }

    public function test_unconfined_path_is_rejected_before_open_or_channel_send(): void
    {
        file_put_contents($this->path, 'secret');
        [$parent, $worker] = createChannelPair();
        $fence = new LocalEfsChunkReadFence($this->evidence(), static fn(): bool => false);
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

    private function evidence(): EfsHostEvidenceReader
    {
        return new EfsHostEvidenceReader(
            $this->evidenceDirectory,
            $this->root,
            str_repeat('a', 32),
            'fs-12345678',
            'api-one',
            'postgres',
            str_repeat('b', 64),
            static fn(): int => 1_000_000_000_000,
            static fn(): string => '11111111-1234-1234-1234-123456789abc',
            static fn(string $path): array|false => self::rootOwned(@lstat($path)),
            static fn($handle): array|false => self::rootOwned(@fstat($handle)),
            fn(): string => "1 0 0:1 / / rw - ext4 /dev/root rw\n"
                . "2 1 0:2 /evidence {$this->evidenceDirectory} ro - tmpfs tmpfs rw\n",
        );
    }

    private function writeEvidence(): void
    {
        $storage = stat($this->root);
        self::assertIsArray($storage);
        self::assertIsInt(file_put_contents($this->evidencePath, json_encode([
            'schemaVersion' => 1,
            'runId' => str_repeat('a', 32),
            'efsId' => 'fs-12345678',
            'sourceSha256' => str_repeat('b', 64),
            'node' => 'api-one',
            'driver' => 'postgres',
            'containerId' => str_repeat('c', 64),
            'containerPid' => 1234,
            'relayPort' => 20049,
            'relayPid' => 5432,
            'hostMountId' => '39',
            'storageDev' => $storage['dev'],
            'storageIno' => $storage['ino'],
            'bootId' => '11111111-1234-1234-1234-123456789abc',
            'epoch' => str_repeat('d', 32),
            'issuedMonotonicNs' => 999_000_000_000,
            'expiresMonotonicNs' => 1_019_000_000_000,
        ], JSON_THROW_ON_ERROR) . "\n"));
        self::assertTrue(chmod($this->evidencePath, 0o644));
    }

    /** @param array<array-key, int>|false $entry
     *  @return array<array-key, int>|false
     */
    private static function rootOwned(array|false $entry): array|false
    {
        if ($entry !== false) {
            $entry['uid'] = 0;
            $entry['mode'] &= ~0o022;
        }

        return $entry;
    }
}
