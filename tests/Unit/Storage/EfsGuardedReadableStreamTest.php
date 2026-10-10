<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\NullCancellation;
use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Storage\EfsGuardedReadableStream;
use PHPUnit\Framework\TestCase;

final class EfsGuardedReadableStreamTest extends TestCase
{
    public function test_checks_local_evidence_before_and_after_each_chunk_and_eof(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['first', 'second']);
        $stream = new EfsGuardedReadableStream($source, $evidence);
        $cancel = new NullCancellation();
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            $closed++;
        });

        self::assertSame('first', $stream->read($cancel));
        self::assertSame($cancel, $source->lastCancellation);
        self::assertSame('second', $stream->read());
        self::assertNull($stream->read());
        self::assertSame(8, $evidence->checks);
        self::assertSame(3, $source->reads);
        self::assertSame(1, $source->closes);
        self::assertSame(1, $closed);
        self::assertTrue($stream->isClosed());
    }

    public function test_loss_before_first_read_latches_without_touching_source(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['secret-value']);
        $stream = new EfsGuardedReadableStream($source, $evidence);
        $closed = 0;
        $stream->onClose(static function () use (&$closed): void {
            $closed++;
        });
        $evidence->revoked = true;

        $this->assertRejected($stream);
        $this->assertRejected($stream);
        self::assertSame(1, $evidence->checks);
        self::assertSame(0, $source->reads);
        self::assertSame(0, $source->closes, 'The wrapper must not explicitly close after a trip.');
        self::assertSame(1, $closed);
        self::assertFalse($stream->isReadable());
    }

    public function test_loss_between_chunks_prevents_another_underlying_read(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['first', 'second']);
        $stream = new EfsGuardedReadableStream($source, $evidence);
        self::assertSame('first', $stream->read());
        $evidence->revoked = true;

        $this->assertRejected($stream);
        self::assertSame(1, $source->reads);
        self::assertSame(0, $source->closes);
    }

    public function test_explicit_close_after_host_trip_skips_direct_inner_close(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['first', 'second']);
        $stream = new EfsGuardedReadableStream($source, $evidence);
        self::assertSame('first', $stream->read());
        $evidence->revoked = true;

        $stream->close();
        self::assertTrue($stream->isClosed());
        self::assertSame(0, $source->closes);
        self::assertSame(1, $source->reads);
        $this->assertRejected($stream);
    }

    public function test_loss_during_read_discards_completed_chunk_and_remains_revoked(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['secret-value', 'later']);
        $source->onRead = static function () use ($evidence): void {
            $evidence->revoked = true;
        };
        $stream = new EfsGuardedReadableStream($source, $evidence);

        $this->assertRejected($stream);
        $this->assertRejected($stream);
        self::assertSame(1, $source->reads);
        self::assertSame(2, $evidence->checks);
        self::assertSame(0, $source->closes);
    }

    public function test_loss_while_underlying_read_throws_masks_storage_failure(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['never']);
        $source->onRead = static function () use ($evidence): never {
            $evidence->revoked = true;
            throw new \RuntimeException('secret-value from storage');
        };
        $stream = new EfsGuardedReadableStream($source, $evidence);

        $this->assertRejected($stream);
        self::assertSame(2, $evidence->checks);
        self::assertSame(0, $source->closes);
    }

    public function test_loss_during_eof_close_does_not_report_clean_eof(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream([]);
        $source->onClose = static function () use ($evidence): void {
            $evidence->revoked = true;
        };
        $stream = new EfsGuardedReadableStream($source, $evidence);

        $this->assertRejected($stream);
        $this->assertRejected($stream);
        self::assertSame(1, $source->reads);
        self::assertSame(1, $source->closes);
        self::assertTrue($stream->isClosed());
        self::assertSame(4, $evidence->checks);
    }

    public function test_loss_during_failed_eof_close_masks_storage_failure(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream([]);
        $source->onClose = static function () use ($evidence): never {
            $evidence->revoked = true;
            throw new \RuntimeException('secret-value from storage close');
        };
        $stream = new EfsGuardedReadableStream($source, $evidence);

        $this->assertRejected($stream);
        self::assertSame(1, $source->reads);
        self::assertSame(1, $source->closes);
        self::assertSame(4, $evidence->checks);
    }

    public function test_is_readable_latches_lost_evidence_without_issuing_a_read(): void
    {
        $evidence = new ProbeEfsEvidence();
        $source = new ProbeEfsReadableStream(['never']);
        $stream = new EfsGuardedReadableStream($source, $evidence);
        $evidence->revoked = true;

        self::assertFalse($stream->isReadable());
        self::assertTrue($stream->isClosed());
        self::assertSame(0, $source->reads);
        $this->assertRejected($stream);
    }

    private function assertRejected(EfsGuardedReadableStream $stream): void
    {
        try {
            $stream->read();
            self::fail('An EFS chunk escaped after evidence loss.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS read evidence is unavailable.', $error->getMessage());
        }
    }
}

final class ProbeEfsEvidence implements EfsHostEvidence
{
    public bool $revoked = false;

    public int $checks = 0;

    public function assertFresh(): void
    {
        $this->checks++;
        if ($this->revoked) {
            throw new \RuntimeException('secret-value from heartbeat');
        }
    }
}

/** @implements \IteratorAggregate<int, string> */
final class ProbeEfsReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    public int $reads = 0;

    public int $closes = 0;

    public ?Cancellation $lastCancellation = null;

    /** @var Closure(): void|null */
    public ?Closure $onRead = null;

    /** @var Closure(): void|null */
    public ?Closure $onClose = null;

    /** @var list<string> */
    private array $chunks;

    /** @param list<string> $chunks */
    public function __construct(array $chunks)
    {
        $this->chunks = $chunks;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        $this->reads++;
        $this->lastCancellation = $cancellation;
        ($this->onRead ?? static function (): void {})();
        return array_shift($this->chunks);
    }

    public function isReadable(): bool
    {
        return $this->closes === 0;
    }

    public function close(): void
    {
        $this->closes++;
        ($this->onClose ?? static function (): void {})();
    }

    public function isClosed(): bool
    {
        return $this->closes > 0;
    }

    public function onClose(Closure $onClose): void {}
}
