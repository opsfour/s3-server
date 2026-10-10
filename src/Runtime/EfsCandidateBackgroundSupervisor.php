<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Cancellation;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\Future;
use OpsFour\S3Server\Logging\FailureIsolatingLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\delay;

/**
 * Listenerless owner of the qualified EFS candidate's background schedules.
 *
 * Every component first parks on the exact startup barrier. Operational tick
 * failures remain isolated as in the ordinary server loops. Ending an entire
 * component before an explicit stop is terminal and cancels all siblings.
 *
 * @internal This supervisor is not connected to S3Server, a router or a CLI.
 */
final class EfsCandidateBackgroundSupervisor
{
    private EfsCandidateBackgroundSupervisorState $state = EfsCandidateBackgroundSupervisorState::PREPARED;

    private readonly DeferredCancellation $cancellation;

    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $failure;

    /** @var array<string, Future<void>> */
    private array $futures = [];

    private bool $stopRequested = false;

    private ?string $failedComponent = null;

    private readonly LoggerInterface $logger;

    public function __construct(
        private readonly EfsCandidateRuntimeResources $graph,
        private readonly EfsCandidateBackgroundBarrier $barrier,
        LoggerInterface $logger = new NullLogger(),
    ) {
        $this->logger = new FailureIsolatingLogger($logger);
        $this->cancellation = new DeferredCancellation();
        $this->failure = new DeferredFuture();
        $this->failure->getFuture()->ignore();
        $this->assertExactGraph($graph);
    }

    public function state(): EfsCandidateBackgroundSupervisorState
    {
        return $this->state;
    }

    public function failedComponent(): ?string
    {
        return $this->failedComponent;
    }

    /**
     * A requested stop leaves this pending; an unexpected exit errors it.
     *
     * @return Future<void>
     */
    public function failureFuture(): Future
    {
        return $this->failure->getFuture();
    }

    /** Prove that this supervisor still refers to the exact candidate graph. */
    public function assertBoundTo(EfsCandidateRuntimeResources $graph): void
    {
        $this->assertExactGraph($graph);
    }

    /** Start the exact fixed roster. No component may do work before release. */
    public function startParked(): void
    {
        if ($this->state !== EfsCandidateBackgroundSupervisorState::PREPARED) {
            throw new \LogicException('EFS background supervisor was already started.');
        }
        $this->assertExactGraph($this->graph);
        $this->state = EfsCandidateBackgroundSupervisorState::STARTING;
        $cancellation = $this->cancellation->getCancellation();

        // Lifecycle is intentionally registered last. Its timer must never be
        // installed before all recovery and housekeeping loops are parked.
        $this->startComponent(
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            fn() => $this->runHousekeeping($cancellation),
            $cancellation,
        );
        $this->startComponent(
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
            fn() => $this->runObjectIntentCollector($cancellation),
            $cancellation,
        );
        $this->startComponent(
            EfsCandidateBackgroundBarrier::TIERING,
            fn() => $this->runTiering($cancellation),
            $cancellation,
        );
        $this->startComponent(
            EfsCandidateBackgroundBarrier::LIFECYCLE,
            fn() => $this->runLifecycle($cancellation),
            $cancellation,
        );
    }

    public function awaitReady(float $timeoutSeconds): void
    {
        if ($this->state !== EfsCandidateBackgroundSupervisorState::STARTING) {
            throw new \LogicException('EFS background supervisor is not awaiting readiness.');
        }

        $this->barrier->awaitReady($timeoutSeconds);
        if ($this->failure->isComplete()) {
            throw new \RuntimeException('EFS background component exited during readiness.');
        }
        $this->state = EfsCandidateBackgroundSupervisorState::READY;
    }

    public function release(): void
    {
        if ($this->state !== EfsCandidateBackgroundSupervisorState::READY) {
            throw new \LogicException('EFS background supervisor is not ready for release.');
        }

        $this->barrier->release();
        $this->state = EfsCandidateBackgroundSupervisorState::RELEASED;
    }

    /** Verify the four schedules after release without touching a listener. */
    public function assertRunning(): void
    {
        if ($this->state !== EfsCandidateBackgroundSupervisorState::RELEASED) {
            throw new \RuntimeException('EFS background supervisor is not released.');
        }
        $this->assertExactGraph($this->graph);
        foreach ($this->futures as $future) {
            if ($future->isComplete()) {
                throw new \RuntimeException('EFS background component is not running.');
            }
        }
        $this->graph->lifecycleRunner->assertRunning();
        if ($this->state !== EfsCandidateBackgroundSupervisorState::RELEASED) {
            throw new \RuntimeException('EFS background supervisor lost admission during its running check.');
        }
    }

    /**
     * Stop schedules only. The graph remains owned and is closed separately in
     * its established dependency order.
     */
    public function stop(int $timeoutSeconds = 30): void
    {
        if ($timeoutSeconds < 1) {
            throw new \InvalidArgumentException('EFS background stop timeout must be at least one second.');
        }
        if ($this->state === EfsCandidateBackgroundSupervisorState::STOPPED) {
            return;
        }
        if ($this->state === EfsCandidateBackgroundSupervisorState::STOPPING) {
            throw new \LogicException('EFS background supervisor stop is already in progress.');
        }

        $this->stopRequested = true;
        $this->state = EfsCandidateBackgroundSupervisorState::STOPPING;
        $this->barrier->abort();
        if (! $this->cancellation->isCancelled()) {
            $this->cancellation->cancel();
        }

        $firstError = null;
        try {
            $this->graph->lifecycleRunner->stop($timeoutSeconds);
        } catch (\Throwable $error) {
            $firstError = $error;
        }

        foreach ($this->futures as $future) {
            try {
                $future->await(new \Amp\TimeoutCancellation($timeoutSeconds));
            } catch (CancelledException) {
                // Dependencies stay alive until every component has returned.
                try {
                    $future->await();
                } catch (\Throwable) {
                    // The terminal failure is already available via failureFuture().
                }
            } catch (\Throwable) {
                // The terminal failure is already available via failureFuture().
            }
        }

        $this->state = EfsCandidateBackgroundSupervisorState::STOPPED;
        if ($firstError !== null) {
            throw new \RuntimeException('EFS background supervisor shutdown failed.');
        }
    }

    /** @param \Closure():void $run */
    private function startComponent(string $name, \Closure $run, Cancellation $cancellation): void
    {
        $future = async(function () use ($name, $run, $cancellation): void {
            try {
                $this->barrier->arriveAndAwaitRelease($name, $cancellation);
                $cancellation->throwIfRequested();
                $run();
            } finally {
                // This runs in the component fiber before its Future completes.
                // A later stop request therefore cannot reclassify an earlier exit.
                $this->componentExited($name);
            }
        });
        $this->futures[$name] = $future;
        $future->ignore();
    }

    private function componentExited(string $name): void
    {
        if ($this->stopRequested
            || $this->state === EfsCandidateBackgroundSupervisorState::STOPPING
            || $this->state === EfsCandidateBackgroundSupervisorState::STOPPED
            || $this->state === EfsCandidateBackgroundSupervisorState::FAILED) {
            return;
        }

        $this->failedComponent = $name;
        $this->state = EfsCandidateBackgroundSupervisorState::FAILED;
        $this->barrier->abort();
        if (! $this->cancellation->isCancelled()) {
            $this->cancellation->cancel();
        }
        if (! $this->failure->isComplete()) {
            $this->failure->error(new \RuntimeException("EFS background component exited unexpectedly: {$name}."));
        }
    }

    private function runHousekeeping(Cancellation $cancellation): void
    {
        while (! $cancellation->isRequested()) {
            $this->assertEvidenceFresh();
            delay(300, cancellation: $cancellation);
            $cancellation->throwIfRequested();
            $this->assertEvidenceFresh();
            try {
                $this->graph->metadata->rateLimitCleanup(3600);
                $this->graph->metadata->cleanupOldNotifications(86400);
                $this->graph->accessLogs->flush();
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->logOperationalFailure('background_cleanup', $error);
            }
            $this->assertEvidenceFresh();
        }
        $cancellation->throwIfRequested();
    }

    private function runObjectIntentCollector(Cancellation $cancellation): void
    {
        $config = $this->requireConfig();
        while (! $cancellation->isRequested()) {
            $cancellation->throwIfRequested();
            $this->assertEvidenceFresh();
            try {
                $this->graph->objectCollector->collect(100, $cancellation);
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->logOperationalFailure('local_object_intent_gc', $error);
            }
            $this->assertEvidenceFresh();
            delay(min(5.0, $config->lifecycleIntervalSeconds), cancellation: $cancellation);
        }
        $cancellation->throwIfRequested();
    }

    private function runTiering(Cancellation $cancellation): void
    {
        $config = $this->requireConfig();
        while (! $cancellation->isRequested()) {
            $cancellation->throwIfRequested();
            $this->assertEvidenceFresh();
            try {
                $this->graph->transitionExecutor->processNext($config->lifecycleBatchSize, $cancellation);
                $this->graph->restoreExecutor->processNext($config->lifecycleBatchSize, $cancellation);
                $this->graph->restoreGarbageCollector->collect($config->lifecycleBatchSize);
                $this->graph->storageGarbageCollector->collect($config->lifecycleBatchSize, $cancellation);
                $this->graph->multipartIntentCollector->collect($config->lifecycleBatchSize, $cancellation);
            } catch (CancelledException $error) {
                throw $error;
            } catch (\Throwable $error) {
                $this->logOperationalFailure('tiering', $error);
            }
            $this->assertEvidenceFresh();
            delay($config->lifecycleIntervalSeconds, cancellation: $cancellation);
        }
        $cancellation->throwIfRequested();
    }

    private function runLifecycle(Cancellation $cancellation): void
    {
        $config = $this->requireConfig();
        $this->assertEvidenceFresh();
        $this->graph->lifecycleRunner->start();
        $this->graph->lifecycleRunner->assertRunning();
        $this->assertEvidenceFresh();

        while (! $cancellation->isRequested()) {
            delay(min(5.0, $config->lifecycleIntervalSeconds), cancellation: $cancellation);
            $cancellation->throwIfRequested();
            $this->assertEvidenceFresh();
            $this->graph->lifecycleRunner->assertRunning();
        }
        $cancellation->throwIfRequested();
    }

    /** The graph constructor binds this executor to the candidate host reader. */
    private function assertEvidenceFresh(): void
    {
        $this->graph->assertOpenForAdmission();
        $this->graph->lifecycleExecutor->assertAdmissionFresh();
        $this->graph->assertOpenForAdmission();
    }

    private function assertExactGraph(EfsCandidateRuntimeResources $graph): void
    {
        if ($graph !== $this->graph) {
            throw new \LogicException('EFS background supervisor belongs to another candidate graph.');
        }
        $graph->assertOpenForAdmission();
        $config = $this->requireConfig();
        if ($config->notificationWorkerEnabled) {
            throw new \LogicException('EFS notification background processing is not qualified.');
        }
    }

    private function requireConfig(): \OpsFour\S3Server\S3ServerConfig
    {
        return $this->graph->buildConfig
            ?? throw new \LogicException('EFS background supervisor requires a factory-built configuration.');
    }

    private function logOperationalFailure(string $component, \Throwable $error): void
    {
        $this->logger->warning('EFS background processing failed.', [
            'component' => $component,
            'exception' => $error::class,
        ]);
    }
}
