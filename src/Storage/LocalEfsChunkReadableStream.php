<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Future;
use Amp\Parallel\Worker\Execution;
use Amp\TimeoutCancellation;
use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidence;

use function Amp\async;

/**
 * The API side of one guarded EFS read session. It owns no filesystem handle.
 * Credits and cancellation are sent from separate fibers so a blocked IPC
 * send or hard-NFS close cannot synchronously trap the request or destructor.
 *
 * @implements \IteratorAggregate<int, string>
 * @internal The serving EFS profile remains closed.
 */
final class LocalEfsChunkReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private const int IPC_WAIT_SECONDS = 30;

    private bool $closed = false;

    private bool $revoked = false;

    private bool $pending = false;

    private bool $needsCredit = false;

    private bool $eof = false;

    /** @var Future<null>|null */
    private ?Future $creditSend = null;

    /** @var list<Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param Execution<null, string, array{chunk?: string, eof?: bool}> $execution */
    public function __construct(
        private readonly Execution $execution,
        private readonly EfsHostEvidence $evidence,
    ) {
        $this->execution->getFuture()->ignore();
    }

    private ?string $primedChunk = null;

    public function primeRead(): void
    {
        if ($this->primedChunk !== null || $this->eof) {
            return;
        }
        $this->primedChunk = $this->receiveChunk(null);
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        try {
            $cancellation?->throwIfRequested();
        } catch (CancelledException $error) {
            $this->finish(false);
            throw $error;
        }
        if ($this->revoked) {
            throw new \RuntimeException('EFS read evidence is unavailable.');
        }
        if ($this->closed || $this->eof) {
            return null;
        }
        if ($this->pending) {
            throw new PendingReadError();
        }
        if ($this->primedChunk !== null) {
            $chunk = $this->primedChunk;
            $this->primedChunk = null;
            $this->assertEvidence();
            return $chunk;
        }

        return $this->receiveChunk($cancellation);
    }

    private function receiveChunk(?Cancellation $cancellation): ?string
    {
        $this->pending = true;
        try {
            $this->assertEvidence();
            $wait = $cancellation === null
                ? new TimeoutCancellation(self::IPC_WAIT_SECONDS)
                : new CompositeCancellation($cancellation, new TimeoutCancellation(self::IPC_WAIT_SECONDS));
            if ($this->needsCredit) {
                $channel = $this->execution->getChannel();
                $this->creditSend = async(static function () use ($channel): void {
                    $channel->send('next');
                });
                $this->creditSend->await($wait);
                $this->creditSend = null;
                $this->needsCredit = false;
            }
            $message = $this->execution->getChannel()->receive($wait);
            $this->assertEvidence();
            if ($this->closed) {
                throw new \RuntimeException('Guarded read closed while awaiting a chunk.');
            }
            if (! is_array($message)) {
                throw new \RuntimeException('Guarded read worker returned an invalid message.');
            }
            if (($message['eof'] ?? false) === true && count($message) === 1) {
                $this->execution->getFuture()->await($wait);
                $this->assertEvidence();
                $this->eof = true;
                $this->finish(true);
                return null;
            }
            $chunk = $message['chunk'] ?? null;
            if (! is_string($chunk) || $chunk === '' || count($message) !== 1 || strlen($chunk) > 1_048_576) {
                throw new \RuntimeException('Guarded read worker returned an invalid chunk.');
            }
            $this->needsCredit = true;
            return $chunk;
        } catch (\Throwable $error) {
            try {
                $this->assertEvidence();
            } catch (\RuntimeException $lost) {
                $error = $lost;
            }
            $this->finish(false);
            throw $error;
        } finally {
            $this->pending = false;
        }
    }

    public function isReadable(): bool
    {
        if ($this->closed || $this->eof || $this->revoked) {
            return false;
        }
        try {
            $this->assertEvidence();
            return true;
        } catch (\RuntimeException) {
            return false;
        }
    }

    public function close(): void
    {
        $this->finish(false);
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
            return;
        }
        $this->onCloseCallbacks[] = $onClose;
    }

    public function __destruct()
    {
        try {
            $this->close();
        } catch (\Throwable) {
            // A destructor never waits for hard-NFS cleanup.
        }
    }

    private function assertEvidence(): void
    {
        try {
            $this->evidence->assertFresh();
        } catch (\Throwable) {
            $this->revoked = true;
            throw new \RuntimeException('EFS read evidence is unavailable.');
        }
    }

    private function finish(bool $complete): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        if (! $complete) {
            $channel = $this->execution->getChannel();
            $pending = $this->creditSend;
            async(static function () use ($channel, $pending): void {
                try {
                    $pending?->await();
                    $channel->send('cancel');
                } catch (\Throwable) {
                    // The permit stays occupied until a definitive task result.
                }
            })->ignore();
        }
        foreach ($this->onCloseCallbacks as $callback) {
            $callback();
        }
        $this->onCloseCallbacks = [];
    }
}
