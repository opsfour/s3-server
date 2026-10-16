<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Parallel\Context\ContextException;
use Amp\Parallel\Context\ContextPanicError;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskCancelledException;
use Amp\Parallel\Worker\TaskFailureError;
use Amp\Parallel\Worker\TaskFailureException;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerException;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Serialization\SerializationException;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Runtime\EfsCandidateServingAdmission;
use OpsFour\S3Server\Runtime\EfsCandidateServingAdmissionState;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerEvidenceException;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerFailureFeedback;
use OpsFour\S3Server\Tests\Support\ProductionEfsFeedbackWorkerTask;
use PHPUnit\Framework\TestCase;

final class ProductionEfsWorkerFailureFeedbackTest extends TestCase
{
    public function test_worker_evidence_task_failure_terminally_revokes_and_kills_every_distinct_pool(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $admission = $this->activeAdmission();
        $feedback->bindAdmission($admission);

        $late = $this->createMock(WorkerPool::class);
        $late->expects(self::once())->method('kill');
        $firstAttempts = 0;
        $first = $this->createMock(WorkerPool::class);
        $first->expects(self::exactly(2))->method('kill')->willReturnCallback(
            static function () use ($feedback, $admission, $late, &$firstAttempts): void {
                self::assertTrue($feedback->isTerminal());
                self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
                $feedback->registerPool($late);
                if (++$firstAttempts === 1) {
                    throw new \RuntimeException('first kill failed');
                }

            },
        );
        $second = $this->createMock(WorkerPool::class);
        $second->expects(self::once())->method('kill')->willReturnCallback(
            static function () use ($feedback): void {
                self::assertTrue($feedback->isTerminal());
            },
        );
        $feedback->registerPool($first);
        $feedback->registerPool($first);
        $feedback->registerPool($second);

        $failure = $this->taskFailure(ProductionEfsWorkerEvidenceException::class);
        try {
            $feedback->rethrowTaskFailure($failure);
        } catch (TaskFailureException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->isTerminal());
        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
        self::assertFalse($feedback->wasPoolKilled($first));
        self::assertTrue($feedback->wasPoolKilled($second));
        self::assertTrue($feedback->wasPoolKilled($late));
        self::assertTrue($feedback->hasPendingPoolKills());

        try {
            $feedback->rethrowTaskFailure($failure);
        } catch (TaskFailureException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->wasPoolKilled($first));
        self::assertFalse($feedback->hasPendingPoolKills());
    }

    public function test_native_and_serialized_cancellation_are_rethrown_without_tripping(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('kill');
        $feedback->registerPool($pool);

        $native = new CancelledException();
        try {
            $feedback->rethrowTaskFailure($native);
        } catch (CancelledException $caught) {
            self::assertSame($native, $caught);
        }

        $serialized = new TaskCancelledException(
            $this->taskFailure(ProductionEfsWorkerEvidenceException::class),
        );
        try {
            $feedback->rethrowTaskFailure($serialized);
        } catch (CancelledException $caught) {
            self::assertSame($serialized, $caught);
        }

        self::assertFalse($feedback->isTerminal());
    }

    public function test_other_task_failures_are_rethrown_without_tripping(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('kill');
        $feedback->registerPool($pool);

        $exception = $this->taskFailure(\DomainException::class);
        try {
            $feedback->rethrowTaskFailure($exception);
        } catch (TaskFailureException $caught) {
            self::assertSame($exception, $caught);
        }

        $error = new TaskFailureError(\Error::class, 'other error', 0, __FILE__, __LINE__, []);
        try {
            $feedback->rethrowTaskFailure($error);
        } catch (TaskFailureError $caught) {
            self::assertSame($error, $caught);
        }

        self::assertFalse($feedback->isTerminal());
    }

    public function test_serialization_task_failure_is_treated_as_unknown_terminal_outcome(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $failure = $this->taskFailure(SerializationException::class);

        try {
            $feedback->rethrowTaskFailure($failure);
        } catch (TaskFailureException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_explicit_worker_ambiguity_trips_and_preserves_worker_failure(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('kill')->willThrowException(new \RuntimeException('kill failed'));
        $feedback->registerPool($pool);
        $failure = new WorkerException('uncertain worker result');

        try {
            $feedback->rethrowWorkerAmbiguity($failure);
        } catch (WorkerException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_explicit_context_ambiguity_is_accepted_by_the_narrow_api(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $failure = new ContextException('uncertain IPC result');

        try {
            $feedback->rethrowWorkerAmbiguity($failure);
        } catch (ContextException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_explicit_context_panic_is_accepted_by_the_narrow_api(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $failure = new ContextPanicError(\Error::class, 'context panic', 0, __FILE__, __LINE__, []);

        try {
            $feedback->rethrowWorkerAmbiguity($failure);
        } catch (ContextPanicError $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_native_parent_ipc_serialization_failure_is_terminal_and_preserved(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $failure = new SerializationException('parent IPC deserialization failed');

        try {
            $feedback->rethrowWorkerAmbiguity($failure);
        } catch (SerializationException $caught) {
            self::assertSame($failure, $caught);
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_late_registration_and_binding_are_closed_after_terminal_failure(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $failure = new WorkerException('uncertain worker result');
        try {
            $feedback->rethrowWorkerAmbiguity($failure);
        } catch (WorkerException) {
        }

        $attempts = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(2))->method('kill')->willReturnCallback(
            static function () use (&$attempts): void {
                if (++$attempts === 1) {
                    throw new \RuntimeException('late kill failed');
                }
            },
        );
        $feedback->registerPool($pool);
        self::assertFalse($feedback->wasPoolKilled($pool));
        self::assertTrue($feedback->hasPendingPoolKills());
        $feedback->registerPool($pool);
        self::assertTrue($feedback->wasPoolKilled($pool));
        self::assertFalse($feedback->hasPendingPoolKills());

        $admission = $this->activeAdmission();
        $feedback->bindAdmission($admission);
        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
    }

    public function test_cleanup_owner_can_retry_and_inspect_pending_pool_kills(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $attempts = 0;
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(2))->method('kill')->willReturnCallback(
            static function () use (&$attempts): void {
                if (++$attempts === 1) {
                    throw new \RuntimeException('kill failed');
                }
            },
        );
        $feedback->registerPool($pool);
        self::assertFalse($feedback->hasPendingPoolKills());

        $failure = new WorkerException('uncertain worker result');
        try {
            $feedback->rethrowWorkerAmbiguity($failure);
        } catch (WorkerException) {
        }
        self::assertTrue($feedback->hasPendingPoolKills());
        self::assertFalse($feedback->wasPoolKilled($pool));

        $feedback->retryPendingPoolKills();
        self::assertFalse($feedback->hasPendingPoolKills());
        self::assertTrue($feedback->wasPoolKilled($pool));

        $feedback->retryPendingPoolKills();
    }

    public function test_real_amp_worker_serializes_evidence_failure_for_terminal_feedback(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $pool = new ContextWorkerPool(1);
        try {
            try {
                $pool->submit(new ProductionEfsFeedbackWorkerTask(
                    ProductionEfsFeedbackWorkerTask::EVIDENCE_FAILURE,
                ))->await(new TimeoutCancellation(2));
                self::fail('Worker evidence failure crossed the process boundary as success.');
            } catch (TaskFailureThrowable $failure) {
                self::assertSame(ProductionEfsWorkerEvidenceException::class, $failure->getOriginalClassName());
                try {
                    $feedback->rethrowTaskFailure($failure);
                } catch (TaskFailureThrowable $caught) {
                    self::assertSame($failure, $caught);
                }
            }
        } finally {
            $pool->shutdown();
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_real_amp_worker_serializes_cancellation_without_terminal_feedback(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $cancellation = new DeferredCancellation();
        $pool = new ContextWorkerPool(1);
        try {
            $execution = $pool->submit(
                new ProductionEfsFeedbackWorkerTask(ProductionEfsFeedbackWorkerTask::CANCELLATION),
                $cancellation->getCancellation(),
            );
            self::assertSame('started', $execution->getChannel()->receive(new TimeoutCancellation(2)));
            $cancellation->cancel();

            try {
                $execution->await(new TimeoutCancellation(2));
                self::fail('Cancelled worker task crossed the process boundary as success.');
            } catch (TaskCancelledException $failure) {
                try {
                    $feedback->rethrowTaskFailure($failure);
                } catch (TaskCancelledException $caught) {
                    self::assertSame($failure, $caught);
                }
            }
        } finally {
            $pool->shutdown();
        }

        self::assertFalse($feedback->isTerminal());
    }

    public function test_real_amp_result_serialization_failure_is_terminal_feedback(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $pool = new ContextWorkerPool(1);
        try {
            try {
                $pool->submit(new ProductionEfsFeedbackWorkerTask(
                    ProductionEfsFeedbackWorkerTask::UNSERIALIZABLE_RESULT,
                ))->await(new TimeoutCancellation(2));
                self::fail('Unserializable task result crossed the process boundary as success.');
            } catch (TaskFailureThrowable $failure) {
                self::assertSame(SerializationException::class, $failure->getOriginalClassName());
                try {
                    $feedback->rethrowTaskFailure($failure);
                } catch (TaskFailureThrowable $caught) {
                    self::assertSame($failure, $caught);
                }
            }
        } finally {
            $pool->shutdown();
        }

        self::assertTrue($feedback->isTerminal());
    }

    public function test_admission_can_only_be_bound_once_even_with_same_instance(): void
    {
        $feedback = new ProductionEfsWorkerFailureFeedback();
        $admission = $this->activeAdmission();
        $feedback->bindAdmission($admission);

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Production EFS worker feedback admission was already bound.');

        $feedback->bindAdmission($admission);
    }

    /** @param class-string<\Throwable> $originalClass */
    private function taskFailure(string $originalClass): TaskFailureException
    {
        return new TaskFailureException($originalClass, 'worker failure', 0, __FILE__, __LINE__, []);
    }

    private function activeAdmission(): EfsCandidateServingAdmission
    {
        $admission = new EfsCandidateServingAdmission(new class implements EfsHostEvidence {
            public function assertFresh(): void {}
        });
        $admission->activate();

        return $admission;
    }
}
