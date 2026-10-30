<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Exception\SlowDownException;

/** Event-loop-local bounded admission; release occurs only when worker execution ends. */
final class AwsS3ReadAdmission
{
    private int $active = 0;

    /** @var array<int, DeferredFuture<void>> */
    private array $waiters = [];

    private int $nextWaiterId = 0;

    public function __construct(
        private readonly int $workerLimit,
        private readonly int $waiterLimit,
        /** @var (\Closure(int, int): void)|null */
        private readonly ?\Closure $onStateChange = null,
    ) {
        if ($workerLimit < 1 || $waiterLimit < 0) {
            throw new \InvalidArgumentException('AWS read admission limits are invalid.');
        }
    }

    public function acquire(TimeoutCancellation $deadline): void
    {
        $deadline->throwIfRequested();
        if ($this->active < $this->workerLimit) {
            ++$this->active;
            $this->notify();

            return;
        }
        if (count($this->waiters) >= $this->waiterLimit) {
            throw new SlowDownException('AWS payload read workers are busy.');
        }
        $waiter = new DeferredFuture();
        $id = ++$this->nextWaiterId;
        $this->waiters[$id] = $waiter;
        $this->notify();
        try {
            $waiter->getFuture()->await($deadline);
        } catch (CancelledException $error) {
            if ($waiter->isComplete()) {
                $this->release();
            }
            throw $error;
        } finally {
            unset($this->waiters[$id]);
            $this->notify();
        }
    }

    public function release(): void
    {
        foreach ($this->waiters as $id => $waiter) {
            unset($this->waiters[$id]);
            $this->notify();
            $waiter->complete();

            return;
        }
        if ($this->active < 1) {
            throw new \LogicException('AWS payload read capacity was released more than once.');
        }
        --$this->active;
        $this->notify();
    }

    public function active(): int
    {
        return $this->active;
    }

    public function waiting(): int
    {
        return count($this->waiters);
    }

    private function notify(): void
    {
        if ($this->onStateChange !== null) {
            ($this->onStateChange)($this->active, count($this->waiters));
        }
    }
}
