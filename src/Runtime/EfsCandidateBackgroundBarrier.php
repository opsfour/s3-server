<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\TimeoutException;

use function Amp\async;
use function Amp\delay;

/**
 * Inert ready/release barrier for the future qualified EFS start sequence.
 *
 * A component acknowledges readiness only by entering arriveAndAwaitRelease()
 * and remaining suspended there. Abort, protocol failure, or component
 * cancellation is terminal and wakes every waiter; no state can reopen.
 *
 * @internal This barrier is not connected to the server or candidate graph.
 */
final class EfsCandidateBackgroundBarrier
{
    public const HOUSEKEEPING = 'housekeeping';

    public const OBJECT_INTENT_COLLECTOR = 'object_intent_collector';

    public const TIERING = 'tiering';

    public const LIFECYCLE = 'lifecycle';

    /** @var list<string> */
    private const ROSTER = [
        self::HOUSEKEEPING,
        self::OBJECT_INTENT_COLLECTOR,
        self::TIERING,
        self::LIFECYCLE,
    ];

    private EfsCandidateBackgroundBarrierState $state = EfsCandidateBackgroundBarrierState::PREPARED;

    /** @var array<string, true> */
    private array $arrived = [];

    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $ready;

    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $release;

    public function __construct()
    {
        $this->ready = new DeferredFuture();
        $this->release = new DeferredFuture();
        // Abort may precede an observer or component. Mark both shared futures
        // handled while preserving their errors for every later await().
        $this->ready->getFuture()->ignore();
        $this->release->getFuture()->ignore();
    }

    public function state(): EfsCandidateBackgroundBarrierState
    {
        return $this->state;
    }

    /**
     * Record this running component and keep it parked until release.
     *
     * The supplied cancellation belongs to the component. Cancellation before
     * release aborts the complete barrier and is rethrown to that component.
     */
    public function arriveAndAwaitRelease(string $name, Cancellation $cancellation): void
    {
        $this->throwIfAborted();
        if (! in_array($name, self::ROSTER, true)) {
            $this->abort();
            throw new \LogicException('Unknown EFS background barrier component.');
        }
        if (isset($this->arrived[$name])) {
            $this->abort();
            throw new \LogicException('Duplicate EFS background barrier acknowledgement.');
        }

        try {
            $cancellation->throwIfRequested();
        } catch (CancelledException $error) {
            $this->abort();
            throw $error;
        }

        $this->arrived[$name] = true;
        if (count($this->arrived) === count(self::ROSTER)) {
            $this->state = EfsCandidateBackgroundBarrierState::READY;
            $this->ready->complete();
        }

        try {
            $this->release->getFuture()->await($cancellation);
        } catch (CancelledException $error) {
            $this->abort();
            throw $error;
        }
    }

    /** Wait for the exact roster; timeout aborts the barrier but remains distinct. */
    public function awaitReady(float $timeoutSeconds): void
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('EFS background ready timeout must be positive and finite.');
        }
        $this->throwIfAborted();

        $timeout = new DeferredCancellation();
        $timerStop = new DeferredCancellation();
        $timer = async(static function () use ($timeoutSeconds, $timeout, $timerStop): void {
            try {
                delay($timeoutSeconds, cancellation: $timerStop->getCancellation());
            } catch (CancelledException) {
                return;
            }
            $timeout->cancel(new TimeoutException('EFS background readiness timed out.'));
        });

        try {
            $this->ready->getFuture()->await($timeout->getCancellation());
        } catch (CancelledException $error) {
            if (! $error->getPrevious() instanceof TimeoutException) {
                $this->abort();
                throw new EfsCandidateBackgroundBarrierAbortedException('EFS background readiness was aborted.');
            }

            $this->abort();
            throw new EfsCandidateBackgroundBarrierTimeoutException('EFS background readiness timed out.');
        } finally {
            $timerStop->cancel();
            $timer->await();
        }

        $this->throwIfAborted();
    }

    /** Release every parked component exactly once, and only after readiness. */
    public function release(): void
    {
        $this->throwIfAborted();
        if ($this->state === EfsCandidateBackgroundBarrierState::RELEASED) {
            throw new \LogicException('EFS background barrier was already released.');
        }
        if ($this->state !== EfsCandidateBackgroundBarrierState::READY) {
            $this->abort();
            throw new \LogicException('EFS background barrier cannot release before complete readiness.');
        }

        $this->state = EfsCandidateBackgroundBarrierState::RELEASED;
        $this->release->complete();
    }

    /** Abort once from any nonterminal phase and wake all parked components. */
    public function abort(): void
    {
        if ($this->state === EfsCandidateBackgroundBarrierState::ABORTED
            || $this->state === EfsCandidateBackgroundBarrierState::RELEASED) {
            return;
        }

        $this->state = EfsCandidateBackgroundBarrierState::ABORTED;
        if (! $this->ready->isComplete()) {
            $this->ready->error(new EfsCandidateBackgroundBarrierAbortedException('EFS background readiness was aborted.'));
        }
        if (! $this->release->isComplete()) {
            $this->release->error(new EfsCandidateBackgroundBarrierAbortedException('EFS background release was aborted.'));
        }
    }

    private function throwIfAborted(): void
    {
        if ($this->state === EfsCandidateBackgroundBarrierState::ABORTED) {
            throw new EfsCandidateBackgroundBarrierAbortedException('EFS background barrier was aborted.');
        }
    }
}
