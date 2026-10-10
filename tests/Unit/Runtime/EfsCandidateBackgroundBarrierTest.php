<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use Amp\NullCancellation;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundBarrier;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundBarrierAbortedException;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundBarrierState;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundBarrierTimeoutException;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class EfsCandidateBackgroundBarrierTest extends TestCase
{
    public function test_ack_order_is_independent_and_every_component_waits_for_release(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $continued = [];
        $futures = [];
        foreach ([
            EfsCandidateBackgroundBarrier::LIFECYCLE,
            EfsCandidateBackgroundBarrier::TIERING,
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
        ] as $name) {
            $futures[] = async(static function () use ($barrier, $name, &$continued): void {
                $barrier->arriveAndAwaitRelease($name, new NullCancellation());
                $continued[] = $name;
            });
        }

        $barrier->awaitReady(1);
        self::assertSame(EfsCandidateBackgroundBarrierState::READY, $barrier->state());
        self::assertSame([], $continued);

        $barrier->release();
        $this->awaitAll($futures);
        self::assertSame(EfsCandidateBackgroundBarrierState::RELEASED, $barrier->state());
        self::assertEqualsCanonicalizing([
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
            EfsCandidateBackgroundBarrier::TIERING,
            EfsCandidateBackgroundBarrier::LIFECYCLE,
        ], $continued);
    }

    public function test_missing_ack_times_out_distinctly_and_wakes_existing_waiters(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $futures = $this->startComponents($barrier, [
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
            EfsCandidateBackgroundBarrier::TIERING,
        ]);

        try {
            $barrier->awaitReady(0.01);
            self::fail('A barrier with a missing acknowledgement became ready.');
        } catch (EfsCandidateBackgroundBarrierTimeoutException $error) {
            self::assertSame('EFS background readiness timed out.', $error->getMessage());
        }

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->assertAllAborted($futures);
        $this->assertAwaitReadyAborted($barrier);
    }

    public function test_unknown_ack_aborts_without_recording_readiness(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();

        try {
            $barrier->arriveAndAwaitRelease('foreign_worker', new NullCancellation());
            self::fail('An unknown component entered the barrier.');
        } catch (\LogicException $error) {
            self::assertSame('Unknown EFS background barrier component.', $error->getMessage());
        }

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->assertAwaitReadyAborted($barrier);
    }

    public function test_duplicate_ack_aborts_and_wakes_original_component(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $entered = new DeferredFuture();
        $first = async(static function () use ($barrier, $entered): void {
            $entered->complete();
            $barrier->arriveAndAwaitRelease(EfsCandidateBackgroundBarrier::HOUSEKEEPING, new NullCancellation());
        });
        $entered->getFuture()->await(new TimeoutCancellation(1));

        try {
            $barrier->arriveAndAwaitRelease(EfsCandidateBackgroundBarrier::HOUSEKEEPING, new NullCancellation());
            self::fail('A duplicate acknowledgement entered the barrier.');
        } catch (\LogicException $error) {
            self::assertSame('Duplicate EFS background barrier acknowledgement.', $error->getMessage());
        }

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->assertFutureAborted($first);
    }

    public function test_abort_before_any_ack_is_idempotent_and_terminal(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $barrier->abort();
        $barrier->abort();

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->assertAwaitReadyAborted($barrier);

        $this->expectException(EfsCandidateBackgroundBarrierAbortedException::class);
        $barrier->arriveAndAwaitRelease(EfsCandidateBackgroundBarrier::LIFECYCLE, new NullCancellation());
    }

    public function test_abort_while_components_wait_wakes_every_waiter_and_cannot_release(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $futures = $this->startComponents($barrier, [
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
        ]);
        $barrier->abort();
        $barrier->abort();

        $this->assertAllAborted($futures);
        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->expectException(EfsCandidateBackgroundBarrierAbortedException::class);
        $barrier->release();
    }

    public function test_release_before_full_readiness_is_rejected_and_aborts(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $futures = $this->startComponents($barrier, [EfsCandidateBackgroundBarrier::HOUSEKEEPING]);

        try {
            $barrier->release();
            self::fail('An incomplete barrier released its component.');
        } catch (\LogicException $error) {
            self::assertSame('EFS background barrier cannot release before complete readiness.', $error->getMessage());
        }

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->assertAllAborted($futures);
    }

    public function test_release_is_exactly_once_and_second_call_keeps_success_terminal(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $futures = $this->startComponents($barrier, self::roster());
        $barrier->awaitReady(1);
        $barrier->release();
        $this->awaitAll($futures);

        try {
            $barrier->release();
            self::fail('A released barrier accepted a second release.');
        } catch (\LogicException $error) {
            self::assertSame('EFS background barrier was already released.', $error->getMessage());
        }
        self::assertSame(EfsCandidateBackgroundBarrierState::RELEASED, $barrier->state());
    }

    public function test_abort_after_release_cannot_rewrite_successful_terminal_state(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $futures = $this->startComponents($barrier, self::roster());
        $barrier->awaitReady(1);
        $barrier->release();
        $this->awaitAll($futures);

        $barrier->abort();

        self::assertSame(EfsCandidateBackgroundBarrierState::RELEASED, $barrier->state());
    }

    public function test_pre_cancelled_component_aborts_before_recording_readiness(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $componentCancellation = new DeferredCancellation();
        $componentCancellation->cancel();

        try {
            $barrier->arriveAndAwaitRelease(
                EfsCandidateBackgroundBarrier::HOUSEKEEPING,
                $componentCancellation->getCancellation(),
            );
            self::fail('A pre-cancelled component entered the barrier.');
        } catch (\Amp\CancelledException) {
            self::addToAssertionCount(1);
        }

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        $this->assertAwaitReadyAborted($barrier);
    }

    public function test_component_cancellation_aborts_ready_barrier_and_wakes_other_components(): void
    {
        $barrier = new EfsCandidateBackgroundBarrier();
        $componentCancellation = new DeferredCancellation();
        $futures = [];
        foreach (self::roster() as $name) {
            $cancellation = $name === EfsCandidateBackgroundBarrier::TIERING
                ? $componentCancellation->getCancellation()
                : new NullCancellation();
            $futures[$name] = async(static fn() => $barrier->arriveAndAwaitRelease($name, $cancellation));
        }
        $barrier->awaitReady(1);

        $componentCancellation->cancel();
        try {
            $futures[EfsCandidateBackgroundBarrier::TIERING]->await(new TimeoutCancellation(1));
            self::fail('The cancelled component remained in the barrier.');
        } catch (\Amp\CancelledException) {
            // The component retains its own cancellation result.
        }

        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        foreach ($futures as $name => $future) {
            if ($name !== EfsCandidateBackgroundBarrier::TIERING) {
                $this->assertFutureAborted($future);
            }
        }
        $this->expectException(EfsCandidateBackgroundBarrierAbortedException::class);
        $barrier->release();
    }

    /**
     * @param list<string> $names
     * @return list<Future<void>>
     */
    private function startComponents(EfsCandidateBackgroundBarrier $barrier, array $names): array
    {
        $started = [];
        $futures = [];
        foreach ($names as $name) {
            $entered = new DeferredFuture();
            $started[] = $entered;
            $futures[] = async(static function () use ($barrier, $name, $entered): void {
                $entered->complete();
                $barrier->arriveAndAwaitRelease($name, new NullCancellation());
            });
        }
        foreach ($started as $entered) {
            $entered->getFuture()->await(new TimeoutCancellation(1));
        }

        return $futures;
    }

    /** @param list<Future<void>> $futures */
    private function awaitAll(array $futures): void
    {
        foreach ($futures as $future) {
            $future->await(new TimeoutCancellation(1));
        }
    }

    /** @param list<Future<void>> $futures */
    private function assertAllAborted(array $futures): void
    {
        foreach ($futures as $future) {
            $this->assertFutureAborted($future);
        }
    }

    /** @param Future<void> $future */
    private function assertFutureAborted(Future $future): void
    {
        try {
            $future->await(new TimeoutCancellation(1));
            self::fail('A parked component was not aborted.');
        } catch (EfsCandidateBackgroundBarrierAbortedException) {
            self::addToAssertionCount(1);
        }
    }

    private function assertAwaitReadyAborted(EfsCandidateBackgroundBarrier $barrier): void
    {
        try {
            $barrier->awaitReady(1);
            self::fail('An aborted barrier reported readiness.');
        } catch (EfsCandidateBackgroundBarrierAbortedException) {
            self::addToAssertionCount(1);
        }
    }

    /** @return list<string> */
    private static function roster(): array
    {
        return [
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
            EfsCandidateBackgroundBarrier::TIERING,
            EfsCandidateBackgroundBarrier::LIFECYCLE,
        ];
    }
}
