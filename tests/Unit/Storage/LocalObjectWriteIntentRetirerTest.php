<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\DeferredCancellation;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentRetirer;
use OpsFour\S3Server\Storage\LocalObjectWriteOutcome;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Tests\Support\LocalObjectWriteFaultIo;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class LocalObjectWriteIntentRetirerTest extends TestCase
{
    private string $base;

    private string $root;

    private string $scratch;

    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private LocalObjectWriteFence $fence;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-local-retire-' . bin2hex(random_bytes(8));
        $this->root = $this->base . '/objects';
        $this->scratch = $this->base . '/scratch';
        $this->databasePath = $this->base . '/metadata.sqlite';
        self::assertTrue(mkdir($this->root, 0700, true));
        self::assertTrue(mkdir($this->scratch, 0700));
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->fence = new LocalObjectWriteFence($this->root);
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

    public function test_repeated_pre_dispatch_and_pre_link_failures_remain_for_collector(): void
    {
        $retirer = new LocalObjectWriteIntentRetirer();
        for ($i = 0; $i < 32; $i++) {
            $intent = $this->reserve();
            $outcome = $i % 2 === 0
                ? LocalObjectWriteOutcome::beforeDispatch($intent, new \RuntimeException('Invalid request body.'))
                : LocalObjectWriteOutcome::completed($intent, null, new \RuntimeException('File sync failed before link.'));
            self::assertFalse($retirer->retire($intent, $outcome));
            self::assertNotNull($this->metadata->getObjectWriteIntent($intent->attemptId));
        }
        self::assertCount(0, $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
    }

    public function test_claim_or_expired_lease_prevents_retirement(): void
    {
        $retirer = new LocalObjectWriteIntentRetirer();
        $claimed = $this->arm($this->reserve());
        self::assertTrue($this->metadata->abandonObjectWriteIntent($claimed->attemptId));
        delay(0.01);
        self::assertCount(1, $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
        self::assertFalse($retirer->retire($claimed, LocalObjectWriteOutcome::beforeDispatch($claimed, new \RuntimeException('cancelled'))));
        self::assertSame('reconciling', $this->metadata->getObjectWriteIntent($claimed->attemptId)?->state);

        $expired = $this->reserve();
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $update = $pdo->prepare('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $update);
        $update->execute([$expired->attemptId]);
        self::assertFalse($retirer->retire($expired, LocalObjectWriteOutcome::completed($expired, null, new \RuntimeException('sync failed'))));
        self::assertSame('active', $this->metadata->getObjectWriteIntent($expired->attemptId)?->state);
    }

    public function test_outcome_from_another_attempt_cannot_retire_an_active_write(): void
    {
        $first = $this->reserve();
        $second = $this->reserve();
        $retirer = new LocalObjectWriteIntentRetirer();
        $firstOutcome = LocalObjectWriteOutcome::beforeDispatch($first, new \RuntimeException('first failed'));

        self::assertFalse($retirer->retire($second, $firstOutcome));
        self::assertNotNull($this->metadata->getObjectWriteIntent($first->attemptId));
        self::assertNotNull($this->metadata->getObjectWriteIntent($second->attemptId));

        $wrongPath = $this->copyIntent($second, storagePath: $second->storagePath . '-different');
        self::assertFalse($retirer->retire(
            $wrongPath,
            LocalObjectWriteOutcome::beforeDispatch($wrongPath, new \RuntimeException('wrong path')),
        ));
        $wrongTier = $this->copyIntent($second, storageTier: 'OTHER');
        self::assertFalse($retirer->retire(
            $wrongTier,
            LocalObjectWriteOutcome::completed($wrongTier, null, new \RuntimeException('wrong tier')),
        ));
        self::assertNotNull($this->metadata->getObjectWriteIntent($second->attemptId));

        self::assertFalse($retirer->retire($first, $firstOutcome));
        self::assertNotNull($this->metadata->getObjectWriteIntent($first->attemptId));
        self::assertNotNull($this->metadata->getObjectWriteIntent($second->attemptId));
    }

    public function test_successful_publication_cannot_be_retired_as_a_failure(): void
    {
        $intent = $this->reserve();
        $outcome = LocalObjectWriteOutcome::completed($intent, new StorageWriteResult($intent->storagePath, 1, md5('x')));
        $this->expectException(\LogicException::class);
        try {
            (new LocalObjectWriteIntentRetirer())->retire($intent, $outcome);
        } finally {
            self::assertNotNull($this->metadata->getObjectWriteIntent($intent->attemptId));
        }
    }

    public function test_uncertain_late_worker_is_left_for_collector_seal(): void
    {
        $intent = $this->arm($this->reserve());
        $entered = $this->base . '/link-entered';
        $release = $this->base . '/release-link';
        $io = new LocalObjectWriteFaultIo();
        $io->linkEnteredPath = $entered;
        $io->releaseLinkPath = $release;
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerIo: $io);
        $cancel = new DeferredCancellation();

        try {
            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publish(
                $intent,
                'key',
                new ReadableBuffer('late payload'),
                $cancel->getCancellation(),
            ));
            $deadline = microtime(true) + 5.0;
            while (! file_exists($entered) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($entered);
            $cancel->cancel();
            $outcome = $pending->await();
            self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $outcome->state);
            self::assertFalse((new LocalObjectWriteIntentRetirer())->retire($intent, $outcome));
            self::assertSame('active', $this->metadata->getObjectWriteIntent($intent->attemptId)?->state);

            self::assertTrue($this->metadata->abandonObjectWriteIntent($intent->attemptId));
            delay(0.01);
            $claimed = $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
            self::assertCount(1, $claimed);
            $this->fence->seal($claimed[0]);
            self::assertTrue($this->metadata->sealObjectWriteIntent($intent->attemptId, $claimed[0]->claimGeneration));
            $seal = file_get_contents($intent->storagePath);
            self::assertIsString($seal);
            file_put_contents($release, 'release');
            $writer->shutdown();
            self::assertSame($seal, file_get_contents($intent->storagePath));
            self::assertSame('sealed', $this->metadata->getObjectWriteIntent($intent->attemptId)?->state);
        } finally {
            file_put_contents($release, 'release');
            $writer->shutdown();
        }
    }

    public function test_second_pre_dispatch_outcome_cannot_retire_first_dispatched_worker(): void
    {
        $intent = $this->reserve();
        $entered = $this->base . '/second-link-entered';
        $release = $this->base . '/release-second-link';
        $io = new LocalObjectWriteFaultIo();
        $io->linkEnteredPath = $entered;
        $io->releaseLinkPath = $release;
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerIo: $io);

        try {
            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publish($intent, 'key', new ReadableBuffer('first worker')));
            $deadline = microtime(true) + 5.0;
            while (! file_exists($entered) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($entered);

            $other = $writer->publish($intent, 'wrong-key', new ReadableBuffer('second worker'));
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $other->state);
            self::assertFalse((new LocalObjectWriteIntentRetirer())->retire($intent, $other));
            self::assertNotNull($this->metadata->getObjectWriteIntent($intent->attemptId));

            file_put_contents($release, 'release');
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $pending->await()->state);
            self::assertSame('first worker', file_get_contents($intent->storagePath));
        } finally {
            file_put_contents($release, 'release');
            $writer->shutdown();
        }
    }

    private function reserve(): ObjectWriteIntent
    {
        $attempt = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));

        return $this->metadata->reserveObjectWriteIntent(new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            storagePath: $this->fence->planPath('bucket', 'key', $generation, $attempt),
            purpose: 'put',
        ), 60.0);
    }

    private function arm(ObjectWriteIntent $intent): ObjectWriteIntent
    {
        $armed = $this->metadata->armObjectWriteIntent(
            $intent->attemptId,
            $intent->leaseUntil,
            bin2hex(random_bytes(16)),
            60.0,
        );
        self::assertNotNull($armed);

        return $armed;
    }

    private function copyIntent(ObjectWriteIntent $intent, ?string $storageTier = null, ?string $storagePath = null): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: $intent->attemptId,
            bucket: $intent->bucket,
            bucketGeneration: $intent->bucketGeneration,
            ownerId: $intent->ownerId,
            storageTier: $storageTier ?? $intent->storageTier,
            storagePath: $storagePath ?? $intent->storagePath,
            purpose: $intent->purpose,
            state: $intent->state,
            leaseUntil: $intent->leaseUntil,
            claimGeneration: $intent->claimGeneration,
        );
    }
}
