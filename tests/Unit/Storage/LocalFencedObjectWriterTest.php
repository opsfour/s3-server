<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteOutcome;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseAdmission;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseSql;
use OpsFour\S3Server\Storage\LocalObjectWriteTerminalRetirementSql;
use OpsFour\S3Server\Storage\LocalObjectTestPublishPauseIo;
use OpsFour\S3Server\Tests\Support\LocalObjectWriteFaultIo;
use OpsFour\S3Server\Tests\Support\LocalObjectWriteCrashIo;
use OpsFour\S3Server\Tests\Support\BlockingLocalBudgetWorkerTask;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class LocalFencedObjectWriterTest extends TestCase
{
    private string $base;

    private string $root;

    private string $scratch;

    protected function setUp(): void
    {
        $this->base = sys_get_temp_dir() . '/s3-local-writer-' . bin2hex(random_bytes(8));
        $this->root = $this->base . '/objects';
        $this->scratch = $this->base . '/scratch';
        self::assertTrue(mkdir($this->root, 0700, true));
        self::assertTrue(mkdir($this->scratch, 0700));
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

    public function test_async_staging_and_worker_publish_exact_create_only_path(): void
    {
        $writer = $this->writer();
        $intent = $this->intent();
        try {
            $outcome = $writer->publish($intent, 'key', new ReadableBuffer('payload'));
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $outcome->state, $outcome->error?->getMessage() ?? '');
            self::assertSame($intent->attemptId, $outcome->attemptId);
            self::assertSame($intent->storageTier, $outcome->storageTier);
            self::assertSame($intent->storagePath, $outcome->storagePath);
            self::assertNull($outcome->error);
            self::assertSame($intent->storagePath, $outcome->result?->path);
            self::assertSame('payload', file_get_contents($intent->storagePath));
            $deadline = microtime(true) + 5.0;
            while (glob($this->scratch . '/s3fw-put-*') !== [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
        } finally {
            $writer->shutdown();
        }
    }

    public function test_terminal_pre_link_worker_failure_retires_only_its_armed_sql_dispatch(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attemptId = bin2hex(random_bytes(16));
        $planned = new ObjectWriteIntent(
            $attemptId,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            (new LocalObjectWriteFence($this->root))->planPath('bucket', 'key', $generation, $attemptId),
            'put_object',
        );
        $sql = new class ($metadata) implements LocalObjectWriteLeaseSql, LocalObjectWriteTerminalRetirementSql {
            public function __construct(private SqliteMetadataStore $metadata) {}

            public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent
            {
                return $this->metadata->reserveObjectWriteIntent($planned, $leaseSeconds);
            }

            public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
            {
                return $this->metadata->armObjectWriteIntent($current->attemptId, $current->leaseUntil, $dispatchId, $leaseSeconds);
            }

            public function retireTerminalPreLink(string $attemptId, string $dispatchId): bool
            {
                return $this->metadata->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
            }
        };
        $renewer = new class ($metadata) implements LocalObjectWriteLeaseRenewer {
            public function __construct(private SqliteMetadataStore $metadata) {}

            public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
            {
                return $this->metadata->renewObjectWriteIntent($current->attemptId, $current->leaseUntil, $leaseSeconds);
            }
        };
        $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
        $lease = $method->invoke(null, $planned, $sql, $renewer, new LocalObjectWriteLeaseAdmission(1));
        self::assertInstanceOf(LocalObjectWriteLeaseGuard::class, $lease);
        $fault = new LocalObjectWriteFaultIo();
        $fault->failFileSync = true;
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerIo: $fault);
        $secondLease = null;
        try {
            $outcome = $writer->publishWithLease($lease->reservedIntent(), 'key', new ReadableBuffer('payload'), $lease);
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $outcome->state);
            self::assertNull($outcome->result);
            self::assertNotNull($outcome->error);
            self::assertFileDoesNotExist($planned->storagePath);
            self::assertNull($metadata->getObjectWriteIntent($attemptId));

            // A lost link ACK is different: the path is already visible and
            // must keep its armed row for a later exact-path collector seal.
            $fault->failFileSync = false;
            $fault->reportLinkFailureAfterSuccess = true;
            $secondAttempt = bin2hex(random_bytes(16));
            $second = new ObjectWriteIntent(
                $secondAttempt,
                'bucket',
                $generation,
                'owner',
                'STANDARD',
                (new LocalObjectWriteFence($this->root))->planPath('bucket', 'key', $generation, $secondAttempt),
                'put_object',
            );
            $secondLease = $method->invoke(null, $second, $sql, $renewer, new LocalObjectWriteLeaseAdmission(1));
            self::assertInstanceOf(LocalObjectWriteLeaseGuard::class, $secondLease);
            $uncertain = $writer->publishWithLease($secondLease->reservedIntent(), 'key', new ReadableBuffer('visible'), $secondLease);
            self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $uncertain->state);
            self::assertFileExists($second->storagePath);
            self::assertNotNull($metadata->getObjectWriteIntent($secondAttempt));
        } finally {
            $secondLease?->close();
            $lease->close();
            $writer->shutdown();
        }
    }

    public function test_cancellation_after_arm_survives_cleanup_budget_failure_and_retires_dispatch(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attempt = bin2hex(random_bytes(16));
        $planned = new ObjectWriteIntent(
            $attempt,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            (new LocalObjectWriteFence($this->root))->planPath('bucket', 'key', $generation, $attempt),
            'put_object',
        );
        $cancel = new DeferredCancellation();
        $cancellationReason = new \RuntimeException('request cancelled');
        $sql = new class ($metadata, $cancel, $cancellationReason, $this->scratch) implements LocalObjectWriteLeaseSql, LocalObjectWriteTerminalRetirementSql {
            public function __construct(
                private SqliteMetadataStore $metadata,
                private DeferredCancellation $cancel,
                private \Throwable $cancellationReason,
                private string $scratch,
            ) {}

            public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent
            {
                return $this->metadata->reserveObjectWriteIntent($planned, $leaseSeconds);
            }

            public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
            {
                $armed = $this->metadata->armObjectWriteIntent($current->attemptId, $current->leaseUntil, $dispatchId, $leaseSeconds);
                if ($armed !== null) {
                    $states = glob($this->scratch . '/.opsfour-s3-storage-budget/state-*') ?: [];
                    if (count($states) !== 1 || file_put_contents($states[0], '{corrupt') === false) {
                        throw new \RuntimeException('Could not corrupt the staging budget fixture.');
                    }
                    $this->cancel->cancel($this->cancellationReason);
                }

                return $armed;
            }

            public function retireTerminalPreLink(string $attemptId, string $dispatchId): bool
            {
                return $this->metadata->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
            }
        };
        $renewer = new class ($metadata) implements LocalObjectWriteLeaseRenewer {
            public function __construct(private SqliteMetadataStore $metadata) {}

            public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
            {
                return $this->metadata->renewObjectWriteIntent($current->attemptId, $current->leaseUntil, $leaseSeconds);
            }
        };
        $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
        $lease = $method->invoke(null, $planned, $sql, $renewer, new LocalObjectWriteLeaseAdmission(1));
        self::assertInstanceOf(LocalObjectWriteLeaseGuard::class, $lease);
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD');
        try {
            $outcome = $writer->publishWithLease($lease->reservedIntent(), 'key', new ReadableBuffer('payload'), $lease, $cancel->getCancellation());
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertNull($outcome->result);
            self::assertInstanceOf(CancelledException::class, $outcome->error);
            self::assertSame($cancellationReason, $outcome->error->getPrevious());
            self::assertNull($metadata->getObjectWriteIntent($attempt));
            self::assertFileDoesNotExist($planned->storagePath);
        } finally {
            $lease->close();
            $writer->shutdown();
        }
    }

    public function test_arm_failure_remains_before_dispatch_when_scratch_budget_cleanup_fails(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $generation = $metadata->getBucket('bucket')?->generation;
        self::assertNotNull($generation);
        $attempt = bin2hex(random_bytes(16));
        $planned = new ObjectWriteIntent(
            $attempt,
            'bucket',
            $generation,
            'owner',
            'STANDARD',
            (new LocalObjectWriteFence($this->root))->planPath('bucket', 'key', $generation, $attempt),
            'put_object',
        );
        $sql = new class ($metadata, $this->scratch) implements LocalObjectWriteLeaseSql, LocalObjectWriteTerminalRetirementSql {
            public int $retireCalls = 0;

            public function __construct(
                private SqliteMetadataStore $metadata,
                private string $scratch,
            ) {}

            public function reserve(ObjectWriteIntent $planned, float $leaseSeconds): ObjectWriteIntent
            {
                return $this->metadata->reserveObjectWriteIntent($planned, $leaseSeconds);
            }

            public function arm(ObjectWriteIntent $current, string $dispatchId, float $leaseSeconds): ?ObjectWriteIntent
            {
                $armed = $this->metadata->armObjectWriteIntent($current->attemptId, $current->leaseUntil, $dispatchId, $leaseSeconds);
                $states = glob($this->scratch . '/.opsfour-s3-storage-budget/state-*') ?: [];
                if ($armed === null || count($states) !== 1 || file_put_contents($states[0], '{corrupt') === false) {
                    throw new \RuntimeException('Could not prepare the lost-arm cleanup fixture.');
                }

                // Simulate a lost SQL acknowledgement after the durable arm.
                return null;
            }

            public function retireTerminalPreLink(string $attemptId, string $dispatchId): bool
            {
                ++$this->retireCalls;

                return $this->metadata->retireTerminalPreLinkObjectWriteIntent($attemptId, $dispatchId);
            }
        };
        $renewer = new class ($metadata) implements LocalObjectWriteLeaseRenewer {
            public function __construct(private SqliteMetadataStore $metadata) {}

            public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
            {
                return $this->metadata->renewObjectWriteIntent($current->attemptId, $current->leaseUntil, $leaseSeconds);
            }
        };
        $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
        $lease = $method->invoke(null, $planned, $sql, $renewer, new LocalObjectWriteLeaseAdmission(1));
        self::assertInstanceOf(LocalObjectWriteLeaseGuard::class, $lease);
        $unexpectedWorkerLink = $this->base . '/unexpected-worker-link';
        $fault = new LocalObjectWriteFaultIo();
        $fault->linkEnteredPath = $unexpectedWorkerLink;
        $fault->releaseLinkPath = $this->base . '/unexpected-worker-release';
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerIo: $fault);
        try {
            $outcome = $writer->publishWithLease($lease->reservedIntent(), 'key', new ReadableBuffer('payload'), $lease);
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertNull($outcome->result);
            self::assertInstanceOf(\RuntimeException::class, $outcome->error);
            self::assertSame('Local write intent arm did not commit the expected token.', $outcome->error->getMessage());
            self::assertSame(0, $sql->retireCalls);
            self::assertFileDoesNotExist($planned->storagePath);
            self::assertFileDoesNotExist($unexpectedWorkerLink);

            $retained = $metadata->getObjectWriteIntent($attempt);
            self::assertNotNull($retained);
            self::assertNotNull($retained->dispatchId);
        } finally {
            $lease->close();
            $writer->shutdown();
        }
    }

    public function test_private_one_shot_barrier_pauses_worker_before_exact_physical_link(): void
    {
        $io = new LocalObjectTestPublishPauseIo($this->scratch, 15);
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerIo: $io);
        $intent = $this->intent();
        $control = $this->scratch . '/' . LocalObjectTestPublishPauseIo::CONTROL_DIRECTORY;
        $id = hash('sha256', $intent->storagePath);
        self::assertSame(0, file_put_contents($control . '/arm', ''));
        self::assertTrue(chmod($control . '/arm', 0600));

        try {
            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publish(
                $intent,
                'key',
                new ReadableBuffer('paused payload'),
            ));
            $deadline = microtime(true) + 10.0;
            while (! is_file($control . '/ready-' . $id) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($control . '/ready-' . $id);
            self::assertFileDoesNotExist($intent->storagePath);
            $pendingRelease = $control . '/pending-release-' . $id;
            self::assertSame(0, file_put_contents($pendingRelease, ''));
            self::assertTrue(chmod($pendingRelease, 0600));
            self::assertTrue(rename($pendingRelease, $control . '/release-' . $id));

            $outcome = $pending->await();
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $outcome->state, $outcome->error?->getMessage() ?? '');
            self::assertSame('paused payload', file_get_contents($intent->storagePath));
            self::assertFileDoesNotExist($control . '/ready-' . $id);
            self::assertFileDoesNotExist($control . '/release-' . $id);
            self::assertSame("linked\n", file_get_contents($control . '/result-' . $id));
        } finally {
            $writer->shutdown();
        }
    }

    public function test_paused_worker_reports_denied_link_after_collector_seals_exact_path(): void
    {
        $io = new LocalObjectTestPublishPauseIo($this->scratch, 15);
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerIo: $io);
        $intent = $this->intent();
        $control = $this->scratch . '/' . LocalObjectTestPublishPauseIo::CONTROL_DIRECTORY;
        $id = hash('sha256', $intent->storagePath);
        self::assertSame(0, file_put_contents($control . '/arm', ''));
        self::assertTrue(chmod($control . '/arm', 0600));

        try {
            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publish(
                $intent,
                'key',
                new ReadableBuffer('late payload'),
            ));
            $deadline = microtime(true) + 10.0;
            while (! is_file($control . '/ready-' . $id) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($control . '/ready-' . $id);
            self::assertFileDoesNotExist($intent->storagePath);

            (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
            $seal = file_get_contents($intent->storagePath);
            self::assertIsString($seal);
            $pendingRelease = $control . '/pending-release-' . $id;
            self::assertSame(0, file_put_contents($pendingRelease, ''));
            self::assertTrue(chmod($pendingRelease, 0600));
            self::assertTrue(rename($pendingRelease, $control . '/release-' . $id));

            $outcome = $pending->await();
            self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $outcome->state);
            self::assertSame("denied\n", file_get_contents($control . '/result-' . $id));
            self::assertSame($seal, file_get_contents($intent->storagePath));
        } finally {
            $writer->shutdown();
        }
    }

    public function test_failed_sql_arm_never_submits_worker_and_releases_staging(): void
    {
        $writerAdmission = new LocalObjectWriteLeaseAdmission(2);
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerAdmission: $writerAdmission);
        $intent = $this->intent();
        $leaseAdmission = new LocalObjectWriteLeaseAdmission(1);
        $guard = $this->leaseGuard($intent, $leaseAdmission, static function (): never {
            throw new \RuntimeException('SQL arm failed before commit.');
        });
        try {
            $outcome = $writer->publishWithLease($intent, 'key', new ReadableBuffer('payload'), $guard);
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $outcome->state);
            self::assertFileDoesNotExist($intent->storagePath);
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
            self::assertSame(0, $writerAdmission->activeCount());
            self::assertTrue($guard->isLost());
        } finally {
            $guard->close();
            $writer->shutdown();
        }
        self::assertSame(0, $leaseAdmission->activeCount());
    }

    public function test_armed_writer_publishes_once_and_reuse_cannot_stage_or_submit(): void
    {
        $writer = $this->writer();
        $intent = $this->intent();
        $guard = $this->leaseGuard($intent, new LocalObjectWriteLeaseAdmission(1));
        try {
            $first = $writer->publishWithLease($intent, 'key', new ReadableBuffer('first'), $guard);
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $first->state, $first->error?->getMessage() ?? '');
            self::assertSame('first', file_get_contents($intent->storagePath));
            $second = $writer->publishWithLease($intent, 'key', new ReadableBuffer('second'), $guard);
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $second->state);
            self::assertSame('first', file_get_contents($intent->storagePath));
            $deadline = microtime(true) + 5.0;
            while (glob($this->scratch . '/s3fw-put-*') !== [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
            self::assertSame($intent->attemptId, $guard->stopForCommit()->attemptId);
        } finally {
            $guard->close();
            $writer->shutdown();
        }
    }

    public function test_cancellation_while_sql_arm_is_in_flight_never_submits_worker(): void
    {
        $writer = $this->writer();
        $intent = $this->intent();
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $guard = $this->leaseGuard(
            $intent,
            new LocalObjectWriteLeaseAdmission(1),
            static function (ObjectWriteIntent $current, string $dispatchId) use ($entered, $release): ObjectWriteIntent {
                $entered->complete();
                $release->getFuture()->await();
                return self::withDispatch($current, $dispatchId);
            },
        );
        $cancel = new DeferredCancellation();
        try {
            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publishWithLease(
                $intent,
                'key',
                new ReadableBuffer('payload'),
                $guard,
                $cancel->getCancellation(),
            ));
            $entered->getFuture()->await();
            $cancel->cancel();
            $release->complete();
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $pending->await()->state);
            self::assertFileDoesNotExist($intent->storagePath);
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
        } finally {
            if (! $release->getFuture()->isComplete()) {
                $release->complete();
            }
            $guard->close();
            $writer->shutdown();
        }
    }

    public function test_armed_worker_cancelled_after_submit_remains_uncertain_and_late_link_cannot_replace_seal(): void
    {
        $entered = $this->base . '/armed-link-entered';
        $release = $this->base . '/armed-link-release';
        self::assertTrue(mkdir($entered, 0700));
        self::assertTrue(mkdir($release, 0700));
        $io = new LocalObjectWriteFaultIo();
        $io->linkEnteredDirectory = $entered;
        $io->linkReleaseDirectory = $release;
        $writer = $this->writer(io: $io);
        $intent = $this->intent();
        $guard = $this->leaseGuard($intent, new LocalObjectWriteLeaseAdmission(1));
        $cancel = new DeferredCancellation();

        try {
            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publishWithLease(
                $intent,
                'key',
                new ReadableBuffer('late payload'),
                $guard,
                $cancel->getCancellation(),
            ));
            $deadline = microtime(true) + 15.0;
            while (! file_exists($entered . '/' . basename($intent->storagePath)) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($entered . '/' . basename($intent->storagePath));
            $cancel->cancel();
            self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $pending->await()->state);
            $guard->close();

            (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
            $seal = file_get_contents($intent->storagePath);
            self::assertIsString($seal);
            file_put_contents($release . '/' . basename($intent->storagePath), 'release');
            $deadline = microtime(true) + 5.0;
            while (glob($this->scratch . '/s3fw-put-*') !== [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
            self::assertSame($seal, file_get_contents($intent->storagePath));
        } finally {
            file_put_contents($release . '/' . basename($intent->storagePath), 'release');
            $guard->close();
            $writer->shutdown();
        }
    }

    public function test_budget_failure_and_preexisting_cancellation_are_before_dispatch(): void
    {
        $writer = $this->writer(4);
        $intent = $this->intent();
        $cancel = new DeferredCancellation();
        $cancel->cancel();
        try {
            $cancelled = $writer->publish($intent, 'key', new ReadableBuffer('payload'), $cancel->getCancellation());
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $cancelled->state);

            $tooLarge = $writer->publish($intent, 'key', new ReadableBuffer('payload'));
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $tooLarge->state);
            self::assertNotNull($tooLarge->error);
            self::assertFileDoesNotExist($intent->storagePath);
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
        } finally {
            $writer->shutdown();
        }
    }

    public function test_budget_reservations_cover_all_chunks_without_exceeding_limit(): void
    {
        $writer = $this->writer(5);
        $first = $this->intent();
        $second = $this->intent();
        try {
            $success = $writer->publish($first, 'key', new ReadableIterableStream(['ab', 'cd', 'e']));
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $success->state, $success->error?->getMessage() ?? '');
            self::assertSame('abcde', file_get_contents($first->storagePath));
            $deadline = microtime(true) + 5.0;
            while (glob($this->scratch . '/s3fw-put-*') !== [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));

            $denied = $writer->publish($second, 'key', new ReadableIterableStream(['ab', 'cd', 'ef']));
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $denied->state);
            self::assertFileDoesNotExist($second->storagePath);
        } finally {
            $writer->shutdown();
        }
    }

    public function test_cancellation_while_budget_pool_is_saturated_does_not_dispatch_late_reservation(): void
    {
        $entered = $this->base . '/budget-worker-entered';
        $release = $this->base . '/release-budget-worker';
        $pool = new ContextWorkerPool(1);
        $blocker = $pool->submit(new BlockingLocalBudgetWorkerTask($entered, $release))->getFuture();
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', budgetPool: $pool);
        $nextWriter = null;
        $cancel = new DeferredCancellation();
        $intent = $this->intent();

        try {
            $deadline = microtime(true) + 5.0;
            while (! file_exists($entered) && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertFileExists($entered);

            $pending = async(fn(): LocalObjectWriteOutcome => $writer->publish(
                $intent,
                'key',
                new ReadableBuffer('payload'),
                $cancel->getCancellation(),
            ));
            $deadline = microtime(true) + 5.0;
            while (glob($this->scratch . '/s3fw-put-*') === [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertNotSame([], glob($this->scratch . '/s3fw-put-*'));

            $cancel->cancel();
            $deadline = microtime(true) + 2.0;
            while (! $pending->isComplete() && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertTrue($pending->isComplete(), 'Cancellation must not wait for a saturated budget pool.');
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $pending->await()->state);
            self::assertFileDoesNotExist($intent->storagePath);

            $writer->shutdown();
            file_put_contents($release, 'release');
            $blocker->await();
            delay(0.05);
            self::assertSame([], glob($this->scratch . '/.opsfour-s3-storage-budget/lease-*'));

            $next = $this->intent();
            $nextWriter = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', budgetPool: $pool);
            $success = $nextWriter->publish($next, 'key', new ReadableBuffer('new payload'));
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $success->state, $success->error?->getMessage() ?? '');
            self::assertSame('new payload', file_get_contents($next->storagePath));
        } finally {
            file_put_contents($release, 'release');
            $blocker->await();
            $writer->shutdown();
            $nextWriter?->shutdown();
            $pool->shutdown();
        }
    }

    public function test_shared_scratch_directory_is_rejected(): void
    {
        self::assertTrue(chmod($this->scratch, 0777));
        try {
            $this->expectException(\InvalidArgumentException::class);
            $this->writer();
        } finally {
            chmod($this->scratch, 0700);
        }
    }

    public function test_worker_admission_cannot_exceed_pool_capacity(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new LocalFencedObjectWriter(
            $this->root,
            $this->scratch,
            'STANDARD',
            workerAdmission: new LocalObjectWriteLeaseAdmission(3),
        );
    }

    public function test_worker_failure_before_link_is_definitively_complete(): void
    {
        $io = new LocalObjectWriteFaultIo();
        $io->failFileSync = true;
        $writer = $this->writer(io: $io);
        $intent = $this->intent();
        try {
            $outcome = $writer->publish($intent, 'key', new ReadableBuffer('payload'));
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $outcome->state);
            self::assertNull($outcome->result);
            self::assertNotNull($outcome->error);
            self::assertFileDoesNotExist($intent->storagePath);
        } finally {
            $writer->shutdown();
        }
    }

    public function test_worker_process_exit_retains_scratch_and_admission_until_shutdown_proves_exit(): void
    {
        $admission = new LocalObjectWriteLeaseAdmission(2);
        $writer = new LocalFencedObjectWriter(
            $this->root,
            $this->scratch,
            'STANDARD',
            workerIo: new LocalObjectWriteCrashIo(),
            workerAdmission: $admission,
        );
        $intent = $this->intent();
        $outcome = $writer->publish($intent, 'key', new ReadableBuffer('payload'));
        self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $outcome->state);
        self::assertSame(1, $admission->activeCount());
        self::assertCount(1, glob($this->scratch . '/s3fw-put-*') ?: []);
        self::assertFileDoesNotExist($intent->storagePath);

        try {
            $writer->shutdown();
            self::assertSame([], glob($this->scratch . '/s3fw-put-*') ?: []);
            self::assertSame(0, $admission->activeCount());
        } catch (\Throwable) {
            // A failed join does not authorize cleanup, even if this test's
            // deliberately crashed worker has in fact exited.
            self::assertCount(1, glob($this->scratch . '/s3fw-put-*') ?: []);
            self::assertSame(1, $admission->activeCount());
        }
    }

    public function test_existing_target_is_never_overwritten(): void
    {
        $writer = $this->writer();
        $intent = $this->intent();
        self::assertTrue(mkdir(dirname($intent->storagePath), 0700, true));
        self::assertSame(8, file_put_contents($intent->storagePath, 'original'));
        try {
            $outcome = $writer->publish($intent, 'key', new ReadableBuffer('replacement'));
            self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $outcome->state);
            self::assertSame('original', file_get_contents($intent->storagePath));
        } finally {
            $writer->shutdown();
        }
    }

    public function test_link_and_directory_sync_failures_are_uncertain(): void
    {
        foreach (['link', 'directory'] as $failure) {
            $io = new LocalObjectWriteFaultIo();
            $io->reportLinkFailureAfterSuccess = $failure === 'link';
            $io->failDirectorySync = $failure === 'directory';
            $writer = $this->writer(io: $io);
            $intent = $this->intent();
            try {
                $outcome = $writer->publish($intent, 'key', new ReadableBuffer('payload'));
                self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $outcome->state);
                self::assertNull($outcome->result);
                self::assertSame('payload', file_get_contents($intent->storagePath));
            } finally {
                $writer->shutdown();
            }
        }
    }

    public function test_cancellation_after_dispatch_retains_intent_for_seal_before_late_link(): void
    {
        $entered = $this->base . '/link-entered';
        $release = $this->base . '/release-link';
        $io = new LocalObjectWriteFaultIo();
        $io->linkEnteredPath = $entered;
        $io->releaseLinkPath = $release;
        $writer = $this->writer(io: $io);
        $intent = $this->intent();
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

            (new LocalObjectWriteFence($this->root))->seal($this->claimed($intent));
            $seal = file_get_contents($intent->storagePath);
            self::assertIsString($seal);
            self::assertStringStartsWith('OPSFOUR-LOCAL-OBJECT-SEAL-V1', $seal);
            file_put_contents($release, 'release');
            $deadline = microtime(true) + 5.0;
            while (glob($this->scratch . '/s3fw-put-*') !== [] && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
            self::assertSame($seal, file_get_contents($intent->storagePath));
        } finally {
            file_put_contents($release, 'release');
            $writer->shutdown();
        }
    }

    public function test_two_blocked_writes_do_not_queue_a_third_staged_or_late_publication(): void
    {
        $entered = $this->base . '/link-entered';
        $release = $this->base . '/link-release';
        self::assertTrue(mkdir($entered, 0700));
        self::assertTrue(mkdir($release, 0700));
        $io = new LocalObjectWriteFaultIo();
        $io->linkEnteredDirectory = $entered;
        $io->linkReleaseDirectory = $release;
        $admission = new LocalObjectWriteLeaseAdmission(2);
        $writer = new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', workerAdmission: $admission, workerIo: $io);
        $first = $this->intent();
        $second = $this->intent();
        $third = $this->intent();
        $cancelFirst = new DeferredCancellation();
        $pendingFirst = async(fn(): LocalObjectWriteOutcome => $writer->publish($first, 'key', new ReadableBuffer('first'), $cancelFirst->getCancellation()));
        $pendingSecond = async(fn(): LocalObjectWriteOutcome => $writer->publish($second, 'key', new ReadableBuffer('second')));
        $pendingThird = null;

        try {
            $deadline = microtime(true) + 5.0;
            while (count(glob($entered . '/*') ?: []) < 2 && microtime(true) < $deadline) {
                delay(0.01);
            }
            self::assertCount(2, glob($entered . '/*') ?: []);
            self::assertSame(2, $admission->activeCount());

            $pendingThird = async(fn(): LocalObjectWriteOutcome => $writer->publish($third, 'key', new ReadableBuffer('third')));
            delay(0.05);
            self::assertTrue($pendingThird->isComplete(), 'A saturated EFS worker pool must reject before staging or queuing.');
            self::assertSame(LocalObjectWriteOutcome::BEFORE_DISPATCH, $pendingThird->await()->state);
            self::assertSame([], glob($this->scratch . '/s3fw-put-' . $third->attemptId . '*'));
            self::assertFileDoesNotExist($third->storagePath);

            $cancelFirst->cancel();
            self::assertSame(LocalObjectWriteOutcome::UNCERTAIN, $pendingFirst->await()->state);
            self::assertSame(2, $admission->activeCount(), 'A canceled in-flight worker still occupies its slot.');
            file_put_contents($release . '/' . basename($first->storagePath), 'release');
            $this->awaitAdmissionCount($admission, 1);
            self::assertFileDoesNotExist($third->storagePath);

            file_put_contents($release . '/' . basename($second->storagePath), 'release');
            self::assertSame(LocalObjectWriteOutcome::COMPLETED, $pendingSecond->await()->state);
            self::assertFileDoesNotExist($third->storagePath);
        } finally {
            file_put_contents($release . '/' . basename($first->storagePath), 'release');
            file_put_contents($release . '/' . basename($second->storagePath), 'release');
            file_put_contents($release . '/' . basename($third->storagePath), 'release');
            $pendingFirst->ignore();
            $pendingSecond->ignore();
            $pendingThird?->ignore();
            $writer->shutdown();
        }
    }

    private function writer(int $budgetBytes = 10_737_418_240, ?LocalObjectWriteFaultIo $io = null): LocalFencedObjectWriter
    {
        return new LocalFencedObjectWriter($this->root, $this->scratch, 'STANDARD', $budgetBytes, $io);
    }

    /** @param null|\Closure(ObjectWriteIntent, string): ObjectWriteIntent $arm */
    private function leaseGuard(
        ObjectWriteIntent $intent,
        LocalObjectWriteLeaseAdmission $admission,
        ?\Closure $arm = null,
    ): LocalObjectWriteLeaseGuard {
        $sql = $this->createMock(LocalObjectWriteLeaseSql::class);
        $sql->method('reserve')->willReturn(self::withDispatch($intent, null));
        $sql->method('arm')->willReturnCallback($arm ?? self::withDispatch(...));
        $renewer = $this->createMock(LocalObjectWriteLeaseRenewer::class);
        $renewer->method('renew')->willReturnCallback(static fn(ObjectWriteIntent $current, float $seconds): ObjectWriteIntent => new ObjectWriteIntent(
            $current->attemptId,
            $current->bucket,
            $current->bucketGeneration,
            $current->ownerId,
            $current->storageTier,
            $current->storagePath,
            $current->purpose,
            'active',
            $current->leaseUntil + $seconds,
            $current->claimGeneration,
            $current->dispatchId,
        ));
        $method = new \ReflectionMethod(LocalObjectWriteLeaseGuard::class, 'reserveWithOperations');
        $guard = $method->invoke(null, $intent, $sql, $renewer, $admission, 30.0, 5.0, 10.0);
        self::assertInstanceOf(LocalObjectWriteLeaseGuard::class, $guard);

        return $guard;
    }

    private static function withDispatch(ObjectWriteIntent $intent, ?string $dispatchId): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            $intent->attemptId,
            $intent->bucket,
            $intent->bucketGeneration,
            $intent->ownerId,
            $intent->storageTier,
            $intent->storagePath,
            $intent->purpose,
            'active',
            $dispatchId === null ? 100.0 : 200.0,
            0,
            $dispatchId,
        );
    }

    private function awaitAdmissionCount(LocalObjectWriteLeaseAdmission $admission, int $expected): void
    {
        $deadline = microtime(true) + 5.0;
        while ($admission->activeCount() !== $expected && microtime(true) < $deadline) {
            delay(0.01);
        }
        self::assertSame($expected, $admission->activeCount());
    }

    private function intent(): ObjectWriteIntent
    {
        $attempt = bin2hex(random_bytes(16));
        $generation = bin2hex(random_bytes(16));
        $path = (new LocalObjectWriteFence($this->root))->planPath('bucket', 'key', $generation, $attempt);

        return new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            storagePath: $path,
            purpose: 'put',
        );
    }

    private function claimed(ObjectWriteIntent $intent): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: $intent->attemptId,
            bucket: $intent->bucket,
            bucketGeneration: $intent->bucketGeneration,
            ownerId: $intent->ownerId,
            storageTier: $intent->storageTier,
            storagePath: $intent->storagePath,
            purpose: $intent->purpose,
            state: 'reconciling',
            leaseUntil: microtime(true) + 60,
            claimGeneration: 1,
        );
    }
}
