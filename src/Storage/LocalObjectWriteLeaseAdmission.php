<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\Sync\Lock;
use OpsFour\S3Server\Exception\SlowDownException;
use Revolt\EventLoop;

/**
 * Process-local admission for the shared SQL renewal pool. Acquire before
 * reserving a SQL intent or staging bytes. A timed-out renewal must retain
 * its permit until its detached SQL future actually terminates.
 */
final class LocalObjectWriteLeaseAdmission
{
    private int $active = 0;

    private int $waiting = 0;

    private int $nextWaiterId = 0;

    /** @var array<int, DeferredFuture<Lock>> */
    private array $waiters = [];

    public function __construct(
        private readonly int $maximum = 8,
        private readonly bool $waitForCapacity = false,
        private readonly float $waitTimeoutSeconds = 15.0,
        private readonly int $maximumWaiters = 256,
    ) {
        if ($maximum < 1 || $maximum > 256) {
            throw new \InvalidArgumentException('Local write lease admission limit must be between 1 and 256.');
        }
        if (! is_finite($waitTimeoutSeconds) || $waitTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Local write admission timeout must be finite and greater than zero.');
        }
        if ($maximumWaiters < 1 || $maximumWaiters > 10_000) {
            throw new \InvalidArgumentException('Local write admission waiter limit must be between 1 and 10000.');
        }
    }

    public function acquire(?Cancellation $cancellation = null): Lock
    {
        $cancellation?->throwIfRequested();
        if (! $this->waitForCapacity) {
            return $this->acquireImmediate();
        }
        if ($this->active < $this->maximum) {
            $this->active++;

            return $this->createLock();
        }
        if ($this->waiting >= $this->maximumWaiters) {
            throw new SlowDownException('Local write lease renewal capacity is exhausted.');
        }

        $waiterId = ++$this->nextWaiterId;
        $deferred = new DeferredFuture();
        $this->waiters[$waiterId] = $deferred;
        $this->waiting++;
        $future = $deferred->getFuture();
        try {
            $inner = self::awaitBounded($future, $this->waitTimeoutSeconds, $cancellation);
        } catch (CancelledException) {
            $this->discardWaiterOrReleaseHandover($waiterId, $future);
            $cancellation?->throwIfRequested();
            throw new SlowDownException('Local write worker capacity remained exhausted.');
        } catch (\Throwable $error) {
            $this->discardWaiterOrReleaseHandover($waiterId, $future);
            throw $error;
        }

        return $inner;
    }

    private function acquireImmediate(): Lock
    {
        if ($this->active >= $this->maximum) {
            throw new SlowDownException('Local write lease renewal capacity is exhausted.');
        }
        $this->active++;

        return $this->createLock();
    }

    public function activeCount(): int
    {
        return $this->active;
    }

    public function capacity(): int
    {
        return $this->maximum;
    }

    public function waitingCount(): int
    {
        return $this->waiting;
    }

    private function createLock(): Lock
    {
        return new Lock($this->release(...));
    }

    private function release(): void
    {
        $waiterId = array_key_first($this->waiters);
        if ($waiterId === null) {
            $this->active--;

            return;
        }

        $waiter = $this->waiters[$waiterId];
        unset($this->waiters[$waiterId]);
        $this->waiting--;
        // The active permit moves directly to the oldest waiter.
        $waiter->complete($this->createLock());
    }

    /** @param Future<Lock> $future */
    private function discardWaiterOrReleaseHandover(int $waiterId, Future $future): void
    {
        if (isset($this->waiters[$waiterId])) {
            unset($this->waiters[$waiterId]);
            $this->waiting--;

            return;
        }

        // A permit can be handed over in the same event-loop turn as the
        // deadline or client cancellation. Release that completed handover.
        $future->map(static function (Lock $late): void {
            $late->release();
        })->ignore();
    }

    /**
     * @template T
     * @param  Future<T>  $future
     * @return T
     */
    private static function awaitBounded(Future $future, float $seconds, ?Cancellation $cancellation): mixed
    {
        $timeout = new DeferredCancellation();
        $timer = EventLoop::delay($seconds, static function () use ($timeout): void {
            $timeout->cancel(new \RuntimeException('Local write admission timed out.'));
        });
        try {
            $deadline = $timeout->getCancellation();

            return $future->await($cancellation === null
                ? $deadline
                : new CompositeCancellation($cancellation, $deadline));
        } finally {
            EventLoop::cancel($timer);
        }
    }
}
