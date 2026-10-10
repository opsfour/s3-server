<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Future;
use Amp\Sync\LocalMutex;
use Amp\Sync\Lock;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use Revolt\EventLoop;

use function Amp\async;
use function Amp\delay;

/**
 * Lease state machine for fenced local object writes. Call
 * reserve before worker checkout or staging, arm after staging and directly
 * before submit, then stopForCommit after a definite worker success. Runtime
 * callers must supply the concrete Amp SQL adapters to reserve().
 * The cooperative operation seam is private and used by isolated fault tests.
 */
final class LocalObjectWriteLeaseGuard
{
    private readonly LocalMutex $mutex;

    private readonly DeferredCancellation $stopSignal;

    /** @var Future<void> */
    private Future $heartbeat;

    private int $pendingSql = 0;

    private bool $lost = false;

    private bool $stopping = false;

    private bool $armed = false;

    private bool $released = false;

    private int $deadlineNanos;

    private function __construct(
        private ObjectWriteIntent $current,
        private readonly LocalObjectWriteLeaseSql $sql,
        private readonly LocalObjectWriteLeaseRenewer $renewer,
        private readonly Lock $permit,
        private readonly float $leaseSeconds,
        private readonly float $operationTimeoutSeconds,
        private readonly float $renewIntervalSeconds,
        int $reservedAtNanos,
    ) {
        $this->mutex = new LocalMutex();
        $this->stopSignal = new DeferredCancellation();
        $this->deadlineNanos = self::deadlineFrom($reservedAtNanos, $leaseSeconds);
        $this->heartbeat = async($this->runHeartbeat(...))->ignore();
    }

    public static function reserve(
        ObjectWriteIntent $planned,
        AmpLocalObjectWriteLeaseSql $sql,
        DedicatedAmpObjectWriteLeaseRenewer $renewer,
        LocalObjectWriteLeaseAdmission $admission,
        float $leaseSeconds = 300.0,
        float $operationTimeoutSeconds = 15.0,
        float $renewIntervalSeconds = 60.0,
    ): self {
        return self::reserveWithOperations(
            $planned,
            $sql,
            $renewer,
            $admission,
            $leaseSeconds,
            $operationTimeoutSeconds,
            $renewIntervalSeconds,
        );
    }

    private static function reserveWithOperations(
        ObjectWriteIntent $planned,
        LocalObjectWriteLeaseSql $sql,
        LocalObjectWriteLeaseRenewer $renewer,
        LocalObjectWriteLeaseAdmission $admission,
        float $leaseSeconds = 300.0,
        float $operationTimeoutSeconds = 15.0,
        float $renewIntervalSeconds = 60.0,
    ): self {
        if ($leaseSeconds <= 0 || $operationTimeoutSeconds <= 0 || $renewIntervalSeconds <= 0
            || $operationTimeoutSeconds >= $leaseSeconds / 2 || $renewIntervalSeconds >= $leaseSeconds / 2) {
            throw new \InvalidArgumentException('Local write lease timings must leave a renewal margin.');
        }
        if ($planned->state !== 'active' || $planned->dispatchId !== null) {
            throw new \InvalidArgumentException('Only an unarmed active write intent can be reserved.');
        }

        $permit = $admission->acquire();
        $started = hrtime(true);
        $future = async(static fn(): ObjectWriteIntent => $sql->reserve($planned, $leaseSeconds))->ignore();
        try {
            $reserved = self::awaitBounded($future, $operationTimeoutSeconds);
            if (! self::sameIntent($planned, $reserved) || $reserved->dispatchId !== null
                || hrtime(true) >= self::deadlineFrom($started, $leaseSeconds)) {
                throw new \RuntimeException('Local write reservation returned an invalid or expired intent.');
            }
            return new self($reserved, $sql, $renewer, $permit, $leaseSeconds, $operationTimeoutSeconds, $renewIntervalSeconds, $started);
        } catch (\Throwable $error) {
            if ($future->isComplete()) {
                $permit->release();
            } else {
                $future->finally(static function () use ($permit): void {
                    $permit->release();
                })->ignore();
            }
            throw $error;
        }
    }

    /** Arm exactly once. The SQL adapter must return only after its own commit. */
    public function arm(): LocalObjectWriteDispatchToken
    {
        $lock = $this->mutex->acquire();
        try {
            $this->assertHealthy();
            if ($this->stopping) {
                throw new \LogicException('Local write lease guard has stopped.');
            }
            if ($this->armed) {
                throw new \LogicException('Local write intent was already armed.');
            }
            $this->armed = true;
            $dispatchId = bin2hex(random_bytes(16));
            $current = $this->current;
            $started = hrtime(true);
            $future = async(fn(): ?ObjectWriteIntent => $this->sql->arm($current, $dispatchId, $this->leaseSeconds));
            $this->track($future);
            try {
                $armed = self::awaitBounded($future, $this->operationTimeoutSeconds);
            } catch (\Throwable $error) {
                $this->lost = true;
                throw $error;
            }
            if ($armed === null || ! self::sameIntent($current, $armed) || $armed->dispatchId !== $dispatchId
                || $armed->leaseUntil < $current->leaseUntil) {
                $this->lost = true;
                throw new \RuntimeException('Local write intent arm did not commit the expected token.');
            }
            $this->current = $armed;
            $this->deadlineNanos = self::deadlineFrom($started, $this->leaseSeconds);
            $this->assertHealthy();
            if ($this->stopping) {
                $this->lost = true;
                throw new \RuntimeException('Local write lease guard stopped while arm was in flight.');
            }

            return new LocalObjectWriteDispatchToken($armed->attemptId, $dispatchId);
        } finally {
            $lock->release();
        }
    }

    /** The committed reservation returned by the SQL adapter. */
    public function reservedIntent(): ObjectWriteIntent
    {
        return $this->current;
    }

    /** Reject a mismatched or already armed writer before it stages bytes. */
    public function assertReadyForStaging(ObjectWriteIntent $reserved): void
    {
        $this->assertHealthy();
        if ($this->stopping || $this->armed || $reserved->dispatchId !== null
            || ! self::sameIntent($reserved, $this->current)) {
            throw new \RuntimeException('Local write lease does not match an unarmed intent.');
        }
    }

    /** Stop renewal before metadata publish; SQL consume still checks lease. */
    public function stopForCommit(): LocalObjectWriteDispatchToken
    {
        $this->stopping = true;
        $this->stopSignal->cancel();
        try {
            self::awaitBounded($this->heartbeat, $this->operationTimeoutSeconds);
        } catch (\Throwable $error) {
            $this->lost = true;
            throw $error;
        }
        $lock = $this->mutex->acquire();
        try {
            $this->assertHealthy();
            if (! $this->armed || $this->current->dispatchId === null || $this->pendingSql !== 0) {
                throw new \RuntimeException('Local write is not ready for metadata commit.');
            }

            return new LocalObjectWriteDispatchToken($this->current->attemptId, $this->current->dispatchId);
        } finally {
            $lock->release();
        }
    }

    /**
     * @internal Only the writer may call this after an actual task result proves
     * its one-shot worker ended before any create-only link attempt. A missing
     * capability or uncertain SQL response retains the row for the collector.
     */
    public function retireTerminalPreLink(LocalObjectWriteDispatchToken $token): bool
    {
        $this->stopping = true;
        $this->stopSignal->cancel();
        try {
            self::awaitBounded($this->heartbeat, $this->operationTimeoutSeconds);
        } catch (\Throwable) {
            $this->lost = true;
            return false;
        }

        $lock = $this->mutex->acquire();
        try {
            if (! $this->armed || $this->pendingSql !== 0
                || $this->current->attemptId !== $token->attemptId
                || $this->current->dispatchId !== $token->dispatchId
                || ! $this->sql instanceof LocalObjectWriteTerminalRetirementSql) {
                return false;
            }
            $future = async(fn(): bool => $this->sql->retireTerminalPreLink($token->attemptId, $token->dispatchId));
            $this->track($future);
            try {
                return self::awaitBounded($future, $this->operationTimeoutSeconds);
            } catch (\Throwable) {
                return false;
            }
        } finally {
            $lock->release();
        }
    }

    public function close(): void
    {
        $this->stopping = true;
        $this->stopSignal->cancel();
        try {
            self::awaitBounded($this->heartbeat, $this->operationTimeoutSeconds);
        } catch (\Throwable) {
            $this->lost = true;
            $this->heartbeat->finally($this->releaseWhenSettled(...))->ignore();
            return;
        }
        $this->releaseWhenSettled();
    }

    public function isLost(): bool
    {
        return $this->lost || hrtime(true) >= $this->deadlineNanos;
    }

    private function runHeartbeat(): void
    {
        while (! $this->stopping && ! $this->lost) {
            try {
                delay($this->renewIntervalSeconds, cancellation: $this->stopSignal->getCancellation());
            } catch (CancelledException) {
                return;
            }
            $lock = $this->mutex->acquire();
            try {
                if ($this->isStoppedOrLost()) {
                    return;
                }
                $this->assertHealthy();
                $current = $this->current;
                $started = hrtime(true);
                $future = async(fn(): ?ObjectWriteIntent => $this->renewer->renew($current, $this->leaseSeconds));
                $this->track($future);
                $renewed = self::awaitBounded($future, $this->operationTimeoutSeconds);
                if ($renewed === null || ! self::sameIntent($current, $renewed)
                    || $renewed->dispatchId !== $current->dispatchId
                    || $renewed->leaseUntil <= $current->leaseUntil) {
                    throw new \RuntimeException('Local write lease renewal was lost.');
                }
                $this->current = $renewed;
                $this->deadlineNanos = self::deadlineFrom($started, $this->leaseSeconds);
                $this->assertHealthy();
            } catch (\Throwable) {
                $this->lost = true;
                return;
            } finally {
                $lock->release();
            }
        }
    }

    /** @param Future<mixed> $future */
    private function track(Future $future): void
    {
        $this->pendingSql++;
        $future->finally(function (): void {
            $this->pendingSql--;
            $this->releaseWhenSettled();
        })->ignore();
    }

    private function releaseWhenSettled(): void
    {
        if ($this->stopping && $this->pendingSql === 0 && $this->heartbeat->isComplete() && ! $this->released) {
            $this->released = true;
            $this->permit->release();
        }
    }

    private function assertHealthy(): void
    {
        if ($this->lost || hrtime(true) >= $this->deadlineNanos) {
            $this->lost = true;
            throw new \RuntimeException('Local write lease was lost.');
        }
    }

    private function isStoppedOrLost(): bool
    {
        return $this->stopping || $this->lost;
    }

    private static function deadlineFrom(int $startedNanos, float $leaseSeconds): int
    {
        return $startedNanos + (int) (($leaseSeconds - min(1.0, $leaseSeconds / 10)) * 1_000_000_000);
    }

    private static function sameIntent(ObjectWriteIntent $expected, ObjectWriteIntent $actual): bool
    {
        return $expected->attemptId === $actual->attemptId
            && $expected->bucket === $actual->bucket
            && $expected->bucketGeneration === $actual->bucketGeneration
            && $expected->ownerId === $actual->ownerId
            && $expected->storageTier === $actual->storageTier
            && $expected->storagePath === $actual->storagePath
            && $expected->purpose === $actual->purpose
            && $expected->claimGeneration === $actual->claimGeneration
            && $actual->state === 'active';
    }

    /**
     * Keep a referenced timer while awaiting SQL; TimeoutCancellation uses an
     * unreferenced watcher and cannot by itself keep a standalone loop alive.
     *
     * @template T
     * @param Future<T> $future
     * @return T
     */
    private static function awaitBounded(Future $future, float $seconds): mixed
    {
        $timeout = new DeferredCancellation();
        $timer = EventLoop::delay($seconds, static function () use ($timeout): void {
            $timeout->cancel(new \RuntimeException('Local write SQL operation timed out.'));
        });
        try {
            return $future->await($timeout->getCancellation());
        } finally {
            EventLoop::cancel($timer);
        }
    }
}
