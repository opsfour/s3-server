<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use OpsFour\S3Server\Storage\GenerationGuardedReadableStream;
use PHPUnit\Framework\TestCase;

final class GenerationGuardedReadableStreamTest extends TestCase
{
    public function test_coalesces_bytewise_source_before_rechecking_generation(): void
    {
        $checks = 0;
        $stream = new GenerationGuardedReadableStream(
            new ReadableIterableStream(array_fill(0, 1_025, 'x')),
            static function () use (&$checks): void {
                ++$checks;
            },
        );

        self::assertSame(str_repeat('x', 1_024), $stream->read());
        self::assertSame(2, $checks);
        self::assertSame('x', $stream->read());
        self::assertSame(4, $checks);
        self::assertTrue($stream->isClosed());
        self::assertNull($stream->read());
        self::assertSame(4, $checks);
    }

    public function test_read_failure_is_not_replaced_by_close_failure(): void
    {
        $primary = new \RuntimeException('primary read failure');
        $stream = new GenerationGuardedReadableStream(
            new FailingGenerationGuardSource($primary),
            static function (): void {},
        );

        try {
            $stream->read();
            self::fail('The source failure must escape.');
        } catch (\Throwable $caught) {
            self::assertSame($primary, $caught);
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_generation_failure_after_storage_read_discards_buffered_bytes(): void
    {
        $failure = new \RuntimeException('generation changed');
        $checks = 0;
        $source = new ReadableIterableStream(['secret']);
        $stream = new GenerationGuardedReadableStream(
            $source,
            static function () use (&$checks, $failure): void {
                if (++$checks === 2) {
                    throw $failure;
                }
            },
        );

        try {
            $stream->read();
            self::fail('Buffered bytes must not escape after the generation changes.');
        } catch (\Throwable $caught) {
            self::assertSame($failure, $caught);
            self::assertSame(2, $checks);
            self::assertTrue($source->isClosed());
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_cancellation_is_not_replaced_by_close_failure(): void
    {
        $cancellation = new CancelledException();
        $stream = new GenerationGuardedReadableStream(
            new FailingGenerationGuardSource($cancellation),
            static function (): void {},
        );

        try {
            $stream->read();
            self::fail('Cancellation must escape unchanged.');
        } catch (\Throwable $caught) {
            self::assertSame($cancellation, $caught);
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_pre_cancelled_read_does_not_check_generation_or_read_source(): void
    {
        $primary = new CancelledException();
        $cancellation = new GenerationGuardCancellation($primary);
        $cancellation->request();
        $checks = 0;
        $source = new GenerationGuardProbeSource(['secret']);
        $stream = new GenerationGuardedReadableStream(
            $source,
            static function () use (&$checks): void {
                ++$checks;
            },
        );

        try {
            $stream->read($cancellation);
            self::fail('Pre-cancelled reads must fail before authorization or source I/O.');
        } catch (\Throwable $caught) {
            self::assertSame($primary, $caught);
            self::assertSame(0, $checks);
            self::assertSame(0, $source->readCalls);
            self::assertSame(1, $source->closeCalls);
            self::assertTrue($stream->isClosed());
        }
    }

    public function test_cancellation_between_source_reads_discards_buffer_without_further_checks_or_reads(): void
    {
        $primary = new CancelledException();
        $cancellation = new GenerationGuardCancellation($primary);
        $checks = 0;
        $source = new GenerationGuardProbeSource(
            ['secret', 'must-not-be-read'],
            static function (int $readCalls) use ($cancellation): void {
                if ($readCalls === 1) {
                    $cancellation->request();
                }
            },
        );
        $stream = new GenerationGuardedReadableStream(
            $source,
            static function () use (&$checks): void {
                ++$checks;
            },
        );

        try {
            $stream->read($cancellation);
            self::fail('Cancellation between source reads must discard buffered bytes.');
        } catch (\Throwable $caught) {
            self::assertSame($primary, $caught);
            self::assertSame(1, $checks);
            self::assertSame(1, $source->readCalls);
            self::assertSame(1, $source->closeCalls);
            self::assertTrue($stream->isClosed());
        }
    }

}

final class GenerationGuardCancellation implements Cancellation
{
    private bool $requested = false;

    public function __construct(private readonly CancelledException $exception) {}

    public function request(): void
    {
        $this->requested = true;
    }

    public function subscribe(\Closure $callback): string
    {
        return 'unused';
    }

    public function unsubscribe(string $id): void {}

    public function isRequested(): bool
    {
        return $this->requested;
    }

    public function throwIfRequested(): void
    {
        if ($this->requested) {
            throw $this->exception;
        }
    }
}

/** @implements \IteratorAggregate<int, string> */
final class GenerationGuardProbeSource implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    public int $readCalls = 0;

    public int $closeCalls = 0;

    private bool $closed = false;

    private int $offset = 0;

    /**
     * @param list<string>       $chunks
     * @param (\Closure(int): void)|null $afterRead
     */
    public function __construct(
        private readonly array $chunks,
        private readonly ?\Closure $afterRead = null,
    ) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        ++$this->readCalls;
        $chunk = $this->chunks[$this->offset++] ?? null;
        ($this->afterRead) !== null && ($this->afterRead)($this->readCalls);

        return $chunk;
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        ++$this->closeCalls;
        $this->closed = true;
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void {}
}

/** @implements \IteratorAggregate<int, string> */
final class FailingGenerationGuardSource implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    public function __construct(private readonly \Throwable $failure) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        throw $this->failure;
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        $this->closed = true;
        throw new \RuntimeException('cleanup failure');
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void {}
}
