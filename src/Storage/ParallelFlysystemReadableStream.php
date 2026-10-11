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
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Observability\MetricsCollector;

/**
 * A worker sends one bounded chunk, then waits for the next read before sending
 * another. This keeps downloads off disk and limits IPC buffering for slow clients.
 *
 * @implements \IteratorAggregate<int, string>
 */
final class ParallelFlysystemReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private bool $closed = false;

    private bool $pending = false;

    private bool $needsAck = false;

    private bool $primed = false;

    private bool $initialized = false;

    private ?string $firstChunk = null;

    /** @var list<\Closure(): void> */
    private array $onCloseCallbacks = [];

    /** @param Execution<StorageWriteResult|bool|null, string, string|null> $execution */
    public function __construct(
        private readonly Execution $execution,
        private readonly DeferredCancellation $taskCancellation,
        private readonly ?MetricsCollector $metrics,
        private readonly string $poolName,
    ) {
        $this->execution->getFuture()->ignore();
    }

    /** Read the first chunk so missing objects fail before response headers. */
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
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $error,
                $this->close(...),
            );
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

    public function close(): void
    {
        $this->finish(false, 'ClientClosed');
    }

    public function isClosed(): bool
    {
        return $this->closed;
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
            if ($this->needsAck) {
                $this->execution->getChannel()->send('next');
                $this->needsAck = false;
            }
            $chunk = $this->execution->getChannel()->receive($cancellation);
            if ($chunk === null) {
                $this->execution->await();
                $this->finish(true);

                return null;
            }
            if (! is_string($chunk) || $chunk === '') {
                throw new InternalErrorException('Flysystem read worker returned an invalid chunk.');
            }

            $this->needsAck = true;

            return $chunk;
        } catch (\Throwable $error) {
            if ($error instanceof ChannelException) {
                try {
                    $this->execution->await();
                } catch (\Throwable $taskError) {
                    $error = $taskError;
                }
            }
            \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::afterFailure(
                $error,
                fn() => $this->finish(false, $error::class),
            );
            if ($error instanceof CancelledException) {
                throw $error;
            }
            throw self::mapError($error);
        } finally {
            $this->pending = false;
        }
    }

    private function finish(bool $success, ?string $errorClass = null): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        /** @var list<callable(): void> $cleanups */
        $cleanups = [];
        if (! $success) {
            $cleanups[] = function (): void {
                try {
                    $this->execution->getChannel()->send('cancel');
                } catch (\Throwable) {
                    // A failed worker may already have closed its channel.
                }
            };
            $cleanups[] = $this->taskCancellation->cancel(...);
        }
        $cleanups[] = $this->execution->getChannel()->close(...);
        $cleanups[] = function () use ($success, $errorClass): void {
            $this->metrics?->recordWorkerPoolTask($this->poolName, 'streamObject', $success, $errorClass);
        };
        foreach ($this->onCloseCallbacks as $onClose) {
            $cleanups[] = $onClose;
        }
        $this->onCloseCallbacks = [];
        \OpsFour\S3Server\Runtime\CancellationPreservingCleanup::inFinally(null, ...$cleanups);
    }

    private static function mapError(\Throwable $error): \Throwable
    {
        if ($error instanceof TaskFailureThrowable) {
            if ($error->getOriginalClassName() === NoSuchKeyException::class) {
                return new NoSuchKeyException();
            }
            if ($error->getOriginalClassName() === SlowDownException::class) {
                return new SlowDownException('Storage read worker is overloaded.');
            }

            return new InternalErrorException('Flysystem read worker failed.', $error);
        }
        if ($error instanceof NoSuchKeyException || $error instanceof SlowDownException || $error instanceof InternalErrorException) {
            return $error;
        }

        return new InternalErrorException('Flysystem read worker failed.', $error);
    }
}
