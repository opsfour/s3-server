<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundBarrier;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundBarrierState;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundSupervisor;
use OpsFour\S3Server\Runtime\EfsCandidateBackgroundSupervisorState;
use OpsFour\S3Server\Runtime\EfsCandidateRuntimeResources;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

final class EfsCandidateBackgroundSupervisorTest extends TestCase
{
    /** @var list<ContextWorkerPool> */
    private array $pools = [];

    protected function tearDown(): void
    {
        foreach ($this->pools as $pool) {
            if ($pool->isRunning()) {
                $pool->shutdown();
            }
        }
        $this->pools = [];
    }

    public function test_components_park_before_release_and_requested_stop_is_not_a_failure(): void
    {
        $graph = $this->graph();
        $barrier = new EfsCandidateBackgroundBarrier();
        $supervisor = new EfsCandidateBackgroundSupervisor($graph, $barrier);

        $supervisor->startParked();
        $supervisor->awaitReady(1);

        self::assertSame(EfsCandidateBackgroundSupervisorState::READY, $supervisor->state());
        self::assertSame(EfsCandidateBackgroundBarrierState::READY, $barrier->state());
        self::assertFalse($graph->lifecycleRunner->isRunning());
        self::assertFalse($supervisor->failureFuture()->isComplete());

        $supervisor->stop();

        self::assertSame(EfsCandidateBackgroundSupervisorState::STOPPED, $supervisor->state());
        self::assertSame(EfsCandidateBackgroundBarrierState::ABORTED, $barrier->state());
        self::assertFalse($supervisor->failureFuture()->isComplete());
        self::assertNull($supervisor->failedComponent());
    }

    public function test_release_starts_all_schedules_and_stop_remains_distinct(): void
    {
        $graph = $this->graph();
        $barrier = new EfsCandidateBackgroundBarrier();
        $supervisor = new EfsCandidateBackgroundSupervisor($graph, $barrier);

        $supervisor->startParked();
        $supervisor->awaitReady(1);
        $supervisor->release();
        $this->nextEventLoopTurn();

        $supervisor->assertRunning();
        self::assertSame(EfsCandidateBackgroundSupervisorState::RELEASED, $supervisor->state());
        self::assertSame(EfsCandidateBackgroundBarrierState::RELEASED, $barrier->state());
        self::assertTrue($graph->lifecycleRunner->isRunning());

        $supervisor->stop();
        self::assertFalse($graph->lifecycleRunner->isRunning());
        self::assertFalse($supervisor->failureFuture()->isComplete());
    }

    public function test_unexpected_component_exit_is_terminal_visible_and_cancels_siblings(): void
    {
        $graph = $this->graph(brokenLifecycle: true);
        $barrier = new EfsCandidateBackgroundBarrier();
        $supervisor = new EfsCandidateBackgroundSupervisor($graph, $barrier);

        $supervisor->startParked();
        $supervisor->awaitReady(1);
        $supervisor->release();

        try {
            $supervisor->failureFuture()->await(new TimeoutCancellation(1));
            self::fail('An unexpected lifecycle exit was not reported.');
        } catch (\RuntimeException $error) {
            self::assertSame(
                'EFS background component exited unexpectedly: lifecycle.',
                $error->getMessage(),
            );
        }

        self::assertSame(EfsCandidateBackgroundSupervisorState::FAILED, $supervisor->state());
        self::assertSame(EfsCandidateBackgroundBarrierState::RELEASED, $barrier->state());
        self::assertSame(EfsCandidateBackgroundBarrier::LIFECYCLE, $supervisor->failedComponent());
        $supervisor->stop();
        self::assertSame(EfsCandidateBackgroundSupervisorState::STOPPED, $supervisor->state());
    }

    public function test_external_rollback_wakes_parked_components_and_becomes_terminal(): void
    {
        $graph = $this->graph();
        $barrier = new EfsCandidateBackgroundBarrier();
        $supervisor = new EfsCandidateBackgroundSupervisor($graph, $barrier);

        $supervisor->startParked();
        $supervisor->awaitReady(1);
        $barrier->abort();

        try {
            $supervisor->failureFuture()->await(new TimeoutCancellation(1));
            self::fail('An externally aborted startup barrier did not terminate supervision.');
        } catch (\RuntimeException $error) {
            self::assertStringStartsWith('EFS background component exited unexpectedly:', $error->getMessage());
        }

        self::assertSame(EfsCandidateBackgroundSupervisorState::FAILED, $supervisor->state());
        self::assertContains($supervisor->failedComponent(), [
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
            EfsCandidateBackgroundBarrier::TIERING,
            EfsCandidateBackgroundBarrier::LIFECYCLE,
        ]);
        $supervisor->stop();
    }

    public function test_graph_evidence_failure_escapes_operational_retry_catches(): void
    {
        $graph = $this->graph(revokedEvidence: true);
        $barrier = new EfsCandidateBackgroundBarrier();
        $supervisor = new EfsCandidateBackgroundSupervisor($graph, $barrier);

        $supervisor->startParked();
        $supervisor->awaitReady(1);
        $supervisor->release();

        try {
            $supervisor->failureFuture()->await(new TimeoutCancellation(1));
            self::fail('Revoked graph evidence was swallowed by an operational retry loop.');
        } catch (\RuntimeException $error) {
            self::assertStringStartsWith('EFS background component exited unexpectedly:', $error->getMessage());
        }

        self::assertSame(EfsCandidateBackgroundSupervisorState::FAILED, $supervisor->state());
        self::assertContains($supervisor->failedComponent(), [
            EfsCandidateBackgroundBarrier::HOUSEKEEPING,
            EfsCandidateBackgroundBarrier::OBJECT_INTENT_COLLECTOR,
            EfsCandidateBackgroundBarrier::TIERING,
            EfsCandidateBackgroundBarrier::LIFECYCLE,
        ]);
        $supervisor->stop();
    }

    public function test_supervisor_rejects_foreign_graph_and_unqualified_notification_worker(): void
    {
        $graph = $this->graph();
        $foreign = $this->graph();
        $supervisor = new EfsCandidateBackgroundSupervisor($graph, new EfsCandidateBackgroundBarrier());

        try {
            $supervisor->assertBoundTo($foreign);
            self::fail('A supervisor accepted another candidate graph.');
        } catch (\LogicException $error) {
            self::assertSame('EFS background supervisor belongs to another candidate graph.', $error->getMessage());
        }

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('EFS notification background processing is not qualified.');
        new EfsCandidateBackgroundSupervisor(
            $this->graph(notificationWorkerEnabled: true),
            new EfsCandidateBackgroundBarrier(),
        );
    }

    public function test_operational_failure_logging_cannot_terminate_a_component(): void
    {
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };
        $supervisor = new EfsCandidateBackgroundSupervisor(
            $this->graph(),
            new EfsCandidateBackgroundBarrier(),
            $logger,
        );

        $method = (new \ReflectionClass($supervisor))->getMethod('logOperationalFailure');
        $method->invoke($supervisor, 'background_cleanup', new \RuntimeException('dependency unavailable'));

        self::assertSame(EfsCandidateBackgroundSupervisorState::PREPARED, $supervisor->state());
    }

    private function graph(
        bool $brokenLifecycle = false,
        bool $notificationWorkerEnabled = false,
        bool $revokedEvidence = false,
    ): EfsCandidateRuntimeResources {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $storage = new InMemoryBackend();
        $evidence = null;
        if ($revokedEvidence) {
            $evidence = $this->withoutConstructor(EfsHostEvidenceReader::class);
            $this->set($evidence, 'revoked', true);
        }
        $executor = new LifecycleExecutor($metadata, $storage, efsEvidence: $evidence);
        $lifecycle = new LifecycleRunner(
            $executor,
            $brokenLifecycle ? -1.0 : 60.0,
        );
        $pool = new ContextWorkerPool(1);
        $this->pools[] = $pool;
        $config = new S3ServerConfig(
            storagePath: sys_get_temp_dir(),
            notificationWorkerEnabled: $notificationWorkerEnabled,
            lifecycleIntervalSeconds: 60,
        );

        $graph = (new \ReflectionClass(EfsCandidateRuntimeResources::class))->newInstanceWithoutConstructor();
        $this->set($graph, 'metadata', $metadata);
        $this->set($graph, 'readPool', $pool);
        $this->set($graph, 'buildConfig', $config);
        $this->set($graph, 'lifecycleExecutor', $executor);
        $this->set($graph, 'lifecycleRunner', $lifecycle);
        $this->set($graph, 'accessLogs', $this->withoutConstructor(AccessLogWriter::class));
        $this->set($graph, 'objectCollector', $this->withoutConstructor(LocalObjectWriteIntentCollector::class));
        $this->set($graph, 'transitionExecutor', $this->withoutConstructor(TierTransitionExecutor::class));
        $this->set($graph, 'restoreExecutor', $this->withoutConstructor(RestoreExecutor::class));
        $this->set($graph, 'restoreGarbageCollector', $this->withoutConstructor(RestoreGarbageCollector::class));
        $this->set($graph, 'storageGarbageCollector', $this->withoutConstructor(StorageGarbageCollector::class));
        $this->set($graph, 'multipartIntentCollector', $this->withoutConstructor(MultipartWriteIntentCollector::class));

        return $graph;
    }

    private function nextEventLoopTurn(): void
    {
        $continued = new \Amp\DeferredFuture();
        EventLoop::queue(static fn() => $continued->complete());
        $continued->getFuture()->await(new TimeoutCancellation(1));
    }

    /**
     * @template T of object
     * @param class-string<T> $class
     * @return T
     */
    private function withoutConstructor(string $class): object
    {
        return (new \ReflectionClass($class))->newInstanceWithoutConstructor();
    }

    private function set(object $object, string $property, mixed $value): void
    {
        (new \ReflectionProperty($object, $property))->setValue($object, $value);
    }
}
