<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream;
use Amp\DeferredFuture;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use League\Uri\Http;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use OpsFour\S3Server\Runtime\EfsCandidateServingAdmission;
use OpsFour\S3Server\Runtime\EfsCandidateServingAdmissionState;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class EfsCandidateServingAdmissionTest extends TestCase
{
    public function test_gate_returns_slow_down_until_activated_and_then_dispatches(): void
    {
        $evidence = new CountingEfsHostEvidence();
        $admission = new EfsCandidateServingAdmission($evidence);
        $handler = new CountingRequestHandler();

        self::assertSame(EfsCandidateServingAdmissionState::PREPARED, $admission->state());
        $response = $this->dispatch($admission, $handler);
        self::assertSame(503, $response->getStatus());
        self::assertStringContainsString('<Code>SlowDown</Code>', ByteStream\buffer($response->getBody()));
        self::assertSame(0, $evidence->checks);
        self::assertSame(0, $handler->calls);

        $admission->activate();

        self::assertSame(EfsCandidateServingAdmissionState::ACTIVE, $admission->state());
        self::assertSame(1, $evidence->checks);
        self::assertSame(204, $this->dispatch($admission, $handler)->getStatus());
        self::assertSame(2, $evidence->checks);
        self::assertSame(1, $handler->calls);
    }

    public function test_revoke_before_activate_is_idempotent_and_cannot_reopen(): void
    {
        $evidence = new CountingEfsHostEvidence();
        $admission = new EfsCandidateServingAdmission($evidence);
        $admission->revoke();
        $admission->revoke();

        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
        self::assertSame(0, $evidence->checks);

        try {
            $admission->activate();
            self::fail('A revoked serving admission reopened.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS serving admission was revoked.', $error->getMessage());
        }

        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
        self::assertSame(503, $this->dispatch($admission, new CountingRequestHandler())->getStatus());
        self::assertSame(0, $evidence->checks);
    }

    public function test_revoke_during_activation_wins_after_yield_and_cannot_reopen(): void
    {
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $evidence = new BlockingEfsHostEvidence($entered, $release);
        $admission = new EfsCandidateServingAdmission($evidence);

        $activation = async(static fn() => $admission->activate());
        $entered->getFuture()->await(new TimeoutCancellation(1));
        self::assertSame(EfsCandidateServingAdmissionState::ACTIVATING, $admission->state());

        $admission->revoke();
        $release->complete();

        try {
            $activation->await(new TimeoutCancellation(1));
            self::fail('Activation reopened a concurrently revoked serving admission.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS serving admission was revoked during activation.', $error->getMessage());
        }

        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
        self::assertSame(503, $this->dispatch($admission, new CountingRequestHandler())->getStatus());
        $this->assertCannotActivateAgain($admission);
    }

    public function test_second_activate_is_rejected_without_closing_active_gate(): void
    {
        $evidence = new CountingEfsHostEvidence();
        $admission = new EfsCandidateServingAdmission($evidence);
        $admission->activate();

        try {
            $admission->activate();
            self::fail('A serving admission activated twice.');
        } catch (\LogicException $error) {
            self::assertSame('EFS serving admission was already activated.', $error->getMessage());
        }

        self::assertSame(EfsCandidateServingAdmissionState::ACTIVE, $admission->state());
        self::assertSame(204, $this->dispatch($admission, new CountingRequestHandler())->getStatus());
    }

    public function test_overlapping_activate_is_rejected_while_original_activation_can_finish(): void
    {
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $admission = new EfsCandidateServingAdmission(new BlockingEfsHostEvidence($entered, $release));
        $first = async(static fn() => $admission->activate());
        $entered->getFuture()->await(new TimeoutCancellation(1));

        try {
            $admission->activate();
            self::fail('Overlapping activation was accepted.');
        } catch (\LogicException $error) {
            self::assertSame('EFS serving admission activation is already in progress.', $error->getMessage());
        }

        $release->complete();
        $first->await(new TimeoutCancellation(1));
        self::assertSame(EfsCandidateServingAdmissionState::ACTIVE, $admission->state());
    }

    public function test_revoke_after_activate_returns_slow_down_and_never_rechecks_evidence(): void
    {
        $evidence = new CountingEfsHostEvidence();
        $admission = new EfsCandidateServingAdmission($evidence);
        $handler = new CountingRequestHandler();
        $admission->activate();
        $admission->revoke();
        $admission->revoke();

        self::assertSame(503, $this->dispatch($admission, $handler)->getStatus());
        self::assertSame(1, $evidence->checks);
        self::assertSame(0, $handler->calls);
        $this->assertCannotActivateAgain($admission);
    }

    public function test_evidence_failure_during_activation_revokes_without_disclosing_message(): void
    {
        $evidence = new CountingEfsHostEvidence();
        $evidence->failure = new \RuntimeException('private-watcher-path');
        $admission = new EfsCandidateServingAdmission($evidence);

        try {
            $admission->activate();
            self::fail('Failed evidence activated serving.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS serving admission activation failed.', $error->getMessage());
            self::assertStringNotContainsString('private-watcher-path', $error->getMessage());
        }

        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
        $evidence->failure = null;
        $this->assertCannotActivateAgain($admission);
    }

    public function test_revoke_during_active_evidence_check_wins_and_skips_handler(): void
    {
        $evidence = new CountingEfsHostEvidence();
        $admission = new EfsCandidateServingAdmission($evidence);
        $admission->activate();
        $evidence->duringCheck = static fn() => $admission->revoke();
        $handler = new CountingRequestHandler();

        self::assertSame(503, $this->dispatch($admission, $handler)->getStatus());
        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
        self::assertSame(0, $handler->calls);
    }

    private function dispatch(EfsCandidateServingAdmission $admission, RequestHandler $handler): Response
    {
        $request = new Request($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket/key'));
        $request->setAttribute('requestId', 'efs-serving-admission-test');

        return (new ErrorHandlingMiddleware())->handleRequest(
            $request,
            new class ($admission, $handler) implements RequestHandler {
                public function __construct(
                    private readonly EfsCandidateServingAdmission $admission,
                    private readonly RequestHandler $handler,
                ) {}

                public function handleRequest(Request $request): Response
                {
                    return $this->admission->handleRequest($request, $this->handler);
                }
            },
        );
    }

    private function assertCannotActivateAgain(EfsCandidateServingAdmission $admission): void
    {
        try {
            $admission->activate();
            self::fail('A revoked serving admission reopened.');
        } catch (\RuntimeException $error) {
            self::assertSame('EFS serving admission was revoked.', $error->getMessage());
        }
        self::assertSame(EfsCandidateServingAdmissionState::REVOKED, $admission->state());
    }
}

final class CountingEfsHostEvidence implements EfsHostEvidence
{
    public int $checks = 0;

    public ?\Throwable $failure = null;

    public ?\Closure $duringCheck = null;

    public function assertFresh(): void
    {
        $this->checks++;
        ($this->duringCheck)?->__invoke();
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

final readonly class BlockingEfsHostEvidence implements EfsHostEvidence
{
    /**
     * @param DeferredFuture<void> $entered
     * @param DeferredFuture<void> $release
     */
    public function __construct(
        private DeferredFuture $entered,
        private DeferredFuture $release,
    ) {}

    public function assertFresh(): void
    {
        $this->entered->complete();
        $this->release->getFuture()->await(new TimeoutCancellation(1));
    }
}

final class CountingRequestHandler implements RequestHandler
{
    public int $calls = 0;

    public function handleRequest(Request $request): Response
    {
        $this->calls++;

        return new Response(status: 204);
    }
}
