<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\Worker;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Storage\LocalScratchStage;
use OpsFour\S3Server\Storage\LocalScratchStager;
use PHPUnit\Framework\TestCase;

final class LocalScratchStagerTest extends TestCase
{
    private string $scratch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->scratch = sys_get_temp_dir() . '/s3-scratch-stager-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->scratch, 0700));
        $canonical = realpath($this->scratch);
        self::assertIsString($canonical);
        $this->scratch = $canonical;
    }

    protected function tearDown(): void
    {
        foreach (glob($this->scratch . '/.opsfour-s3-storage-budget/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->scratch . '/.opsfour-s3-storage-budget');
        foreach (glob($this->scratch . '/*') ?: [] as $path) {
            @unlink($path);
        }
        @rmdir($this->scratch);
        parent::tearDown();
    }

    public function test_stages_supplied_bytes_and_retains_reservation_until_cleanup(): void
    {
        $stager = new LocalScratchStager($this->scratch, 8);
        try {
            $bytes = "\x00cipher\xff";
            $stage = $stager->stage(str_repeat('a', 32), new ReadableIterableStream(["\x00cip", "her\xff"]), prefix: 's3fw-part-');
            self::assertStringStartsWith($this->scratch . '/s3fw-part-', $stage->path);
            self::assertSame($bytes, file_get_contents($stage->path));
            self::assertSame(strlen($bytes), $stage->size);
            self::assertSame(hash('sha256', $bytes, true), $stage->sha256);
            self::assertSame('8', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            self::assertSame(0, fileperms($stage->path) & 0077);
            $stager->cleanup($stage);
            self::assertFileDoesNotExist($stage->path);
            self::assertSame('0', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
        } finally {
            $stager->shutdown();
        }
    }

    public function test_budget_rejection_removes_partial_file_and_reservation(): void
    {
        $stager = new LocalScratchStager($this->scratch, 4);
        try {
            try {
                $stager->stage(str_repeat('b', 32), new ReadableIterableStream(['ab', 'cde']));
                self::fail('Stage exceeded the scratch budget.');
            } catch (TaskFailureException $error) {
                self::assertStringContainsString('Storage staging budget is full', $error->getMessage());
                self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
                self::assertSame([], glob($this->scratch . '/.opsfour-s3-storage-budget/lease-*'));
                self::assertSame('0', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            }
        } finally {
            $stager->shutdown();
        }
    }

    public function test_thousands_of_tiny_chunks_use_one_budget_roundtrip_per_block(): void
    {
        $pool = new CountingScratchBudgetPool(new ContextWorkerPool(1));
        $stager = new LocalScratchStager($this->scratch, 69_633, $pool);
        $chunks = (static function (): \Generator {
            for ($index = 0; $index < 4_097; ++$index) {
                yield 'a';
            }
            yield str_repeat('b', 65_536);
        })();
        try {
            $stage = $stager->stage(str_repeat('1', 32), new ReadableIterableStream($chunks));
            $expected = str_repeat('a', 4_097) . str_repeat('b', 65_536);
            self::assertSame($expected, file_get_contents($stage->path));
            self::assertSame(strlen($expected), $stage->size);
            self::assertSame(hash('sha256', $expected, true), $stage->sha256);
            self::assertSame(2, $pool->checkoutCount);
            $stager->cleanup($stage);
        } finally {
            $stager->shutdown();
            $pool->shutdown();
        }
    }

    public function test_tiny_chunk_budget_boundary_rejects_without_extra_reservations(): void
    {
        $pool = new CountingScratchBudgetPool(new ContextWorkerPool(1));
        $stager = new LocalScratchStager($this->scratch, 4_096, $pool);
        $chunks = (static function (): \Generator {
            for ($index = 0; $index < 4_097; ++$index) {
                yield 'x';
            }
        })();
        try {
            try {
                $stager->stage(str_repeat('2', 32), new ReadableIterableStream($chunks));
                self::fail('Tiny chunks exceeded the scratch budget.');
            } catch (TaskFailureException $error) {
                self::assertStringContainsString('Storage staging budget is full', $error->getMessage());
                self::assertSame(1, $pool->checkoutCount);
                self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
                self::assertSame('0', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            }
        } finally {
            $stager->shutdown();
            $pool->shutdown();
        }
    }

    public function test_cleanup_rejects_foreign_path_without_deleting_it_or_releasing_reservation(): void
    {
        $stager = new LocalScratchStager($this->scratch, 8);
        $foreign = $this->scratch . '/s3fw-put-' . str_repeat('f', 64);
        self::assertSame(7, file_put_contents($foreign, 'foreign'));
        $stage = $stager->stage(str_repeat('a', 32), new ReadableBuffer('payload'));
        try {
            $forged = new LocalScratchStage($foreign, $stage->reservation, $stage->size, $stage->sha256);
            try {
                $stager->cleanup($forged);
                self::fail('A foreign stage path was accepted for cleanup.');
            } catch (\InvalidArgumentException) {
                self::assertSame('foreign', file_get_contents($foreign));
                self::assertFileExists($stage->path);
                self::assertSame('7', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            }
            try {
                $stager->cleanup(new LocalScratchStage($stage->path, $stage->reservation, $stage->size, $stage->sha256));
                self::fail('A caller-constructed stage was accepted for cleanup.');
            } catch (\InvalidArgumentException) {
                self::assertFileExists($stage->path);
                self::assertSame('7', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            }
            $stager->cleanup($stage);
            self::assertSame('0', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            self::assertSame('foreign', file_get_contents($foreign));
        } finally {
            @unlink($foreign);
            $stager->shutdown();
        }
    }

    public function test_cancellation_before_and_during_staging_leaves_no_file_or_lease(): void
    {
        $stager = new LocalScratchStager($this->scratch, 8);
        $cancel = new DeferredCancellation();
        $cancel->cancel();
        try {
            try {
                $stager->stage(str_repeat('c', 32), new ReadableBuffer('x'), $cancel->getCancellation());
                self::fail('Pre-canceled stage was accepted.');
            } catch (CancelledException) {
                self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
            }

            $during = new DeferredCancellation();
            $chunks = (static function () use ($during): \Generator {
                yield 'ab';
                $during->cancel();
                yield 'cd';
            })();
            try {
                $stager->stage(str_repeat('d', 32), new ReadableIterableStream($chunks), $during->getCancellation());
                self::fail('Canceled stage was accepted.');
            } catch (CancelledException) {
                self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
                self::assertSame([], glob($this->scratch . '/.opsfour-s3-storage-budget/lease-*'));
                self::assertSame('0', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            }
        } finally {
            $stager->shutdown();
        }
    }

    public function test_cancellation_is_not_replaced_by_temp_budget_release_failure(): void
    {
        $stager = new LocalScratchStager($this->scratch, 8);
        $cancellation = new CancelledException();
        $body = $this->createMock(ReadableStream::class);
        $body->method('read')->willReturnCallback(function () use ($cancellation): never {
            $states = glob($this->scratch . '/.opsfour-s3-storage-budget/state-*') ?: [];
            self::assertCount(1, $states);
            self::assertNotFalse(file_put_contents($states[0], '{corrupt'));

            throw $cancellation;
        });

        try {
            $stager->stage(str_repeat('f', 32), $body);
            self::fail('Temp-budget cleanup failure must not replace cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
        } finally {
            $stager->shutdown();
        }
    }

    public function test_cancelled_tiny_chunk_stream_does_not_flush_pending_bytes(): void
    {
        $pool = new CountingScratchBudgetPool(new ContextWorkerPool(1));
        $stager = new LocalScratchStager($this->scratch, 4_096, $pool);
        $cancel = new DeferredCancellation();
        $chunks = (static function () use ($cancel): \Generator {
            for ($index = 0; $index < 1_000; ++$index) {
                yield 'x';
            }
            $cancel->cancel();
            yield 'last';
        })();
        try {
            try {
                $stager->stage(str_repeat('3', 32), new ReadableIterableStream($chunks), $cancel->getCancellation());
                self::fail('Canceled tiny-chunk stream was accepted.');
            } catch (CancelledException) {
                self::assertSame(0, $pool->checkoutCount);
                self::assertSame([], glob($this->scratch . '/s3fw-put-*'));
                self::assertSame('0', file_get_contents($this->scratch . '/.opsfour-s3-storage-budget/total'));
            }
        } finally {
            $stager->shutdown();
            $pool->shutdown();
        }
    }

    public function test_untrusted_prefix_is_rejected_before_file_creation(): void
    {
        $stager = new LocalScratchStager($this->scratch, 8);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $stager->stage(str_repeat('e', 32), new ReadableBuffer('x'), prefix: '../escape-');
        } finally {
            self::assertSame([], glob($this->scratch . '/s3fw-*'));
            $stager->shutdown();
        }
    }

    public function test_prefix_not_supported_by_budget_coordinator_is_rejected(): void
    {
        $stager = new LocalScratchStager($this->scratch, 8);
        try {
            $this->expectException(\InvalidArgumentException::class);
            $stager->stage(str_repeat('e', 32), new ReadableBuffer('x'), prefix: 's3fw-copy-');
        } finally {
            self::assertSame([], glob($this->scratch . '/s3fw-*'));
            $stager->shutdown();
        }
    }
}

/** Counts budget-worker checkouts without changing the real worker behavior. */
final class CountingScratchBudgetPool implements WorkerPool
{
    public int $checkoutCount = 0;

    public function __construct(private readonly WorkerPool $inner) {}

    public function getWorker(): Worker
    {
        ++$this->checkoutCount;

        return $this->inner->getWorker();
    }

    public function getWorkerCount(): int
    {
        return $this->inner->getWorkerCount();
    }

    public function getIdleWorkerCount(): int
    {
        return $this->inner->getIdleWorkerCount();
    }

    public function isRunning(): bool
    {
        return $this->inner->isRunning();
    }

    public function isIdle(): bool
    {
        return $this->inner->isIdle();
    }

    /**
     * @template TResult
     * @template TReceive
     * @template TSend
     * @param Task<TResult, TReceive, TSend> $task
     * @return Execution<TResult, TReceive, TSend>
     */
    public function submit(Task $task, ?Cancellation $cancellation = null): Execution
    {
        return $this->inner->submit($task, $cancellation);
    }

    public function shutdown(): void
    {
        $this->inner->shutdown();
    }

    public function kill(): void
    {
        $this->inner->kill();
    }
}
