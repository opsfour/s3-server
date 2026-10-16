<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\CancelledException;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextPanicError;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerException;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Serialization\SerializationException;
use SplObjectStorage;

/**
 * Parent-process terminal feedback for production EFS worker failures.
 *
 * This primitive is intentionally not connected to a task, listener, or
 * runtime factory yet. Callers must use the typed rethrow methods at the
 * corresponding Amp failure boundaries.
 */
final class ProductionEfsWorkerFailureFeedback
{
    private const int POOL_PENDING = 0;

    private const int POOL_KILLING = 1;

    private const int POOL_KILLED = 2;

    /** @var SplObjectStorage<WorkerPool, int> */
    private SplObjectStorage $pools;

    private ?EfsCandidateServingAdmission $admission = null;

    private bool $terminal = false;

    public function __construct()
    {
        $this->pools = new SplObjectStorage();
    }

    /** Bind the candidate admission exactly once. */
    public function bindAdmission(EfsCandidateServingAdmission $admission): void
    {
        if ($this->admission !== null) {
            throw new \LogicException('Production EFS worker feedback admission was already bound.');
        }

        $this->admission = $admission;
        if ($this->terminal) {
            $this->revoke($admission);
        }
    }

    /** Register a pool by object identity; a terminal controller kills it immediately. */
    public function registerPool(WorkerPool $pool): void
    {
        if ($this->pools->offsetExists($pool)) {
            if ($this->terminal && $this->pools[$pool] === self::POOL_PENDING) {
                $this->attemptPoolKill($pool);
            }

            return;
        }

        $this->pools[$pool] = self::POOL_PENDING;
        if ($this->terminal) {
            $this->attemptPoolKill($pool);
        }
    }

    public function isTerminal(): bool
    {
        return $this->terminal;
    }

    /** Retry every registered pool whose prior kill did not complete. */
    public function retryPendingPoolKills(): void
    {
        if (! $this->terminal) {
            return;
        }

        $this->attemptPendingPoolKills();
    }

    /** True only after kill() returned successfully for this pool. */
    public function wasPoolKilled(WorkerPool $pool): bool
    {
        return $this->pools->offsetExists($pool)
            && $this->pools[$pool] === self::POOL_KILLED;
    }

    public function hasPendingPoolKills(): bool
    {
        if (! $this->terminal) {
            return false;
        }

        foreach ($this->pools as $pool) {
            if ($this->pools[$pool] !== self::POOL_KILLED) {
                return true;
            }
        }

        return false;
    }

    /**
     * Inspect one task outcome and rethrow the exact failure received.
     *
     * Native and worker-serialized cancellation never revoke the candidate.
     */
    public function rethrowTaskFailure(TaskFailureThrowable|CancelledException $failure): never
    {
        if (
            ! $failure instanceof CancelledException
            && in_array($failure->getOriginalClassName(), [
                ProductionEfsWorkerEvidenceException::class,
                SerializationException::class,
            ], true)
        ) {
            $this->trip();
        }

        throw $failure;
    }

    /**
     * Mark an explicitly classified worker/IPC ambiguity as terminal and
     * rethrow the exact failure received.
     */
    public function rethrowWorkerAmbiguity(
        WorkerException|ContextException|ContextPanicError|SerializationException $failure,
    ): never {
        $this->trip();

        throw $failure;
    }

    private function trip(): void
    {
        if (! $this->terminal) {
            // Publish terminal state before any cleanup callback can observe us.
            $this->terminal = true;

            if ($this->admission !== null) {
                $this->revoke($this->admission);
            }
        }

        $this->attemptPendingPoolKills();
    }

    private function attemptPendingPoolKills(): void
    {
        // A kill callback may register another pool. That late pool is killed
        // immediately by registerPool(), so iterate a stable identity snapshot
        // to avoid killing it twice.
        $pools = clone $this->pools;
        foreach ($pools as $pool) {
            if ($this->pools[$pool] === self::POOL_PENDING) {
                $this->attemptPoolKill($pool);
            }
        }
    }

    private function revoke(EfsCandidateServingAdmission $admission): void
    {
        try {
            $admission->revoke();
        } catch (\Throwable) {
            // Terminal state and the original failure take precedence.
        }
    }

    private function attemptPoolKill(WorkerPool $pool): void
    {
        $this->pools[$pool] = self::POOL_KILLING;
        try {
            $pool->kill();
        } catch (\Throwable) {
            $this->pools[$pool] = self::POOL_PENDING;

            return;
        }

        $this->pools[$pool] = self::POOL_KILLED;
    }
}
