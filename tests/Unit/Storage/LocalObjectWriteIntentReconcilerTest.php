<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class LocalObjectWriteIntentReconcilerTest extends TestCase
{
    private string $root;

    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private LocalObjectWriteFence $fence;

    private StorageTierRegistry $tiers;

    protected function setUp(): void
    {
        $this->root = sys_get_temp_dir() . '/s3-local-reconcile-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->root, 0700));
        $this->databasePath = $this->root . '/metadata.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->fence = new LocalObjectWriteFence($this->root);
        $this->tiers = StorageTierRegistry::single(new FilesystemBackend($this->root));
    }

    protected function tearDown(): void
    {
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($this->root);
    }

    public function test_committed_claim_seals_sql_and_prevents_delayed_create_only_writer(): void
    {
        $reserved = $this->reserve();
        $this->fence->write($reserved, 'key', new ReadableBuffer('abandoned'));
        $claimed = $this->claim($reserved);
        self::assertFalse($this->metadata->consumeObjectWriteIntent($reserved->attemptId));

        $this->reconciler()->reconcile($claimed);

        self::assertSame('sealed', $this->metadata->getObjectWriteIntent($reserved->attemptId)?->state);
        $seal = file_get_contents($reserved->storagePath);
        self::assertIsString($seal);
        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', $seal);

        try {
            $this->fence->write($reserved, 'key', new ReadableBuffer('late worker'));
            self::fail('The delayed create-only writer must not replace a seal.');
        } catch (\RuntimeException $e) {
            self::assertSame('Local create-only object publication is uncertain.', $e->getMessage());
        }
        self::assertSame($seal, file_get_contents($reserved->storagePath));
    }

    public function test_consumed_active_intent_cannot_be_claimed_or_sealed(): void
    {
        $reserved = $this->reserve();
        $this->metadata->transaction(fn() => $this->metadata->consumeArmedObjectWriteIntent($reserved->attemptId, (string) $reserved->dispatchId));
        self::assertNull($this->metadata->getObjectWriteIntent($reserved->attemptId));
        self::assertSame([], $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('no longer current in SQL');
        try {
            $this->reconciler()->reconcile($this->asClaim($reserved));
        } finally {
            self::assertFileDoesNotExist($reserved->storagePath);
        }
    }

    public function test_wrong_tier_and_changed_path_are_rejected_before_sealing(): void
    {
        $reserved = $this->reserve();
        $claimed = $this->claim($reserved);
        $wrongTier = $this->copyClaim($claimed, storageTier: 'OTHER');
        $wrongPath = $this->copyClaim($claimed, storagePath: $claimed->storagePath . '-other');

        foreach ([$wrongTier, $wrongPath] as $bad) {
            try {
                $this->reconciler()->reconcile($bad);
                self::fail('A mismatched claimed tier or path must fail.');
            } catch (\InvalidArgumentException|\RuntimeException) {
                self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($reserved->attemptId)?->state);
                self::assertFileDoesNotExist($reserved->storagePath);
            }
        }

        $this->expectException(\InvalidArgumentException::class);
        new LocalObjectWriteIntentReconciler(
            $this->metadata,
            StorageTierRegistry::single(new InMemoryBackend()),
            'STANDARD',
        );
    }

    public function test_directory_sync_failure_keeps_sql_claim_retryable(): void
    {
        $reserved = $this->reserve();
        $claimed = $this->claim($reserved);
        $io = new ReconcilerFaultIo();
        $io->failNextDirectorySync = true;

        try {
            $this->reconciler($io)->reconcile($claimed);
            self::fail('A failed directory sync cannot commit SQL.');
        } catch (\RuntimeException $e) {
            self::assertSame('Failed to synchronize local object directory.', $e->getMessage());
        }
        self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($reserved->attemptId)?->state);

        $this->reconciler()->reconcile($claimed);
        self::assertSame('sealed', $this->metadata->getObjectWriteIntent($reserved->attemptId)->state);
        self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', (string) file_get_contents($reserved->storagePath));
    }

    public function test_lost_claim_generation_cannot_commit_sql_and_new_claim_can_retry(): void
    {
        $reserved = $this->reserve();
        $oldClaim = $this->claim($reserved);
        $io = new ReconcilerFaultIo();
        $newClaim = null;
        $io->onRename = function () use ($reserved, &$newClaim): void {
            $this->expire($reserved->attemptId);
            $next = $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
            self::assertCount(1, $next);
            $newClaim = $next[0];
        };

        try {
            $this->reconciler($io)->reconcile($oldClaim);
            self::fail('A stale claim generation must not commit SQL.');
        } catch (\RuntimeException $e) {
            self::assertSame('Object write seal could not be committed in SQL.', $e->getMessage());
        }
        self::assertInstanceOf(ObjectWriteIntent::class, $newClaim);
        self::assertSame(2, $newClaim->claimGeneration);
        self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($reserved->attemptId)?->state);

        $this->reconciler()->reconcile($newClaim);
        self::assertSame('sealed', $this->metadata->getObjectWriteIntent($reserved->attemptId)->state);
    }

    private function reserve(): ObjectWriteIntent
    {
        $attempt = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $reserved = $this->metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            storagePath: $this->fence->planPath('bucket', 'key', $generation, $attempt),
            purpose: 'put',
        ), 60.0);
        return $this->metadata->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, bin2hex(random_bytes(16)), 60.0)
            ?? throw new \RuntimeException('Fixture dispatch was not armed.');
    }

    private function claim(ObjectWriteIntent $reserved): ObjectWriteIntent
    {
        self::assertTrue($this->metadata->abandonObjectWriteIntent($reserved->attemptId));
        $claimed = $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claimed);
        return $claimed[0];
    }

    private function reconciler(?LocalObjectWriteIo $io = null): LocalObjectWriteIntentReconciler
    {
        return new LocalObjectWriteIntentReconciler($this->metadata, $this->tiers, 'STANDARD', $io);
    }

    private function expire(string $attemptId): void
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $stmt = $pdo->prepare('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->execute([$attemptId]);
    }

    private function asClaim(ObjectWriteIntent $intent): ObjectWriteIntent
    {
        return $this->copyClaim($intent, state: 'reconciling', claimGeneration: 1);
    }

    private function copyClaim(
        ObjectWriteIntent $intent,
        ?string $storageTier = null,
        ?string $storagePath = null,
        ?string $state = null,
        ?int $claimGeneration = null,
    ): ObjectWriteIntent {
        return new ObjectWriteIntent(
            attemptId: $intent->attemptId,
            bucket: $intent->bucket,
            bucketGeneration: $intent->bucketGeneration,
            ownerId: $intent->ownerId,
            storageTier: $storageTier ?? $intent->storageTier,
            storagePath: $storagePath ?? $intent->storagePath,
            purpose: $intent->purpose,
            state: $state ?? $intent->state,
            leaseUntil: $intent->leaseUntil,
            claimGeneration: $claimGeneration ?? $intent->claimGeneration,
        );
    }
}

final class ReconcilerFaultIo implements LocalObjectWriteIo
{
    public bool $failNextDirectorySync = false;

    public ?\Closure $onRename = null;

    private NativeLocalObjectWriteIo $native;

    public function __construct()
    {
        $this->native = new NativeLocalObjectWriteIo();
    }

    public function syncFile($file): bool
    {
        return $this->native->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        if ($this->failNextDirectorySync) {
            $this->failNextDirectorySync = false;
            return false;
        }
        return $this->native->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        return $this->native->link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        $renamed = $this->native->rename($source, $target);
        if ($this->onRename !== null) {
            ($this->onRename)();
        }
        return $renamed;
    }
}
