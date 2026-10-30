<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\PendingReadError;
use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Sync\ChannelException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Runtime\CancellationPreservingCleanup;

/** @implements \IteratorAggregate<int, string> */
final class AwsS3ParallelReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;
    private bool $pending = false;
    private bool $needsAck = false;
    private bool $initialized = false;
    private bool $primed = false;
    private ?string $firstChunk = null;
    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param Execution<null, string, string|null> $execution */
    public function __construct(
        private readonly Execution $execution,
        private readonly DeferredCancellation $taskCancellation,
        private readonly ?\Closure $onOutcome = null,
    ) {
        $this->execution->getFuture()->ignore();
    }

    public function prime(?Cancellation $cancellation = null): void
    {
        if ($this->initialized) {
            return;
        }
        $this->initialized = true;
        $this->firstChunk = $this->receiveNext($cancellation);
        $this->primed = $this->firstChunk !== null;
    }

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        try {
            $cancellation?->throwIfRequested();
        } catch (CancelledException $error) {
            CancellationPreservingCleanup::afterFailure($error, $this->close(...));
            throw $error;
        }
        if ($this->pending) {
            throw new PendingReadError();
        }
        if ($this->primed) {
            $this->primed = false;
            $chunk = $this->firstChunk;
            $this->firstChunk = null;

            return $chunk;
        }

        return $this->receiveNext($cancellation);
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }
    public function isClosed(): bool
    {
        return $this->closed;
    }
    public function close(): void
    {
        $this->finish(false, 'cancelled');
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
            return;
        }
        $this->onCloseCallbacks[] = $onClose;
    }

    public function __destruct()
    {
        $this->close();
    }

    private function receiveNext(?Cancellation $cancellation): ?string
    {
        $this->pending = true;
        try {
            $cancellation?->throwIfRequested();
            if ($this->needsAck) {
                $this->execution->getChannel()->send('next');
                $this->needsAck = false;
            }
            $chunk = $this->execution->getChannel()->receive($cancellation);
            if ($chunk === null) {
                $this->execution->await($cancellation);
                $this->finish(true, 'complete');
                return null;
            }
            if (! is_string($chunk) || $chunk === '' || strlen($chunk) > 65_536) {
                throw new InternalErrorException('AWS read worker returned an invalid chunk.');
            }
            $this->needsAck = true;

            return $chunk;
        } catch (\Throwable $error) {
            if ($error instanceof ChannelException) {
                try {
                    $this->execution->await($cancellation);
                } catch (\Throwable $taskError) {
                    $error = $taskError;
                }
            }
            CancellationPreservingCleanup::afterFailure($error, fn() => $this->finish(false, self::safeClass($error)));
            if ($error instanceof CancelledException) {
                throw $error;
            }
            if ($error instanceof InternalErrorException) {
                throw $error;
            }
            throw new InternalErrorException('AWS payload read failed.', $error);
        } finally {
            $this->pending = false;
        }
    }

    private function finish(bool $success, string $outcome): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        $cleanups = [];
        if (! $success) {
            $cleanups[] = function (): void {
                try {
                    $this->execution->getChannel()->send('cancel');
                } catch (\Throwable) {
                }
            };
            $cleanups[] = $this->taskCancellation->cancel(...);
        }
        $cleanups[] = $this->execution->getChannel()->close(...);
        if ($this->onOutcome !== null) {
            $cleanups[] = fn() => ($this->onOutcome)($outcome);
        }
        array_push($cleanups, ...$this->onCloseCallbacks);
        $this->onCloseCallbacks = [];
        CancellationPreservingCleanup::inFinally(null, ...$cleanups);
    }

    private static function safeClass(\Throwable $error): string
    {
        return $error instanceof TaskFailureThrowable ? 'worker_failure' : match (true) {
            $error instanceof CancelledException => 'cancelled',
            $error instanceof ChannelException => 'ipc_failure',
            default => 'protocol_failure',
        };
    }
}
