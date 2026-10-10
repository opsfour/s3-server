<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\DeferredCancellation;
use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\SlowDownException;

/**
 * Closed-by-default serving gate for a future production EFS activation path.
 *
 * State transitions themselves never yield. Evidence checks may suspend the
 * current fiber, so both activate() and assertFresh() recheck state afterward;
 * a concurrent revoke therefore always wins and REVOKED can never reopen.
 *
 * @internal This gate is intentionally not wired to a listener or router yet.
 */
final class EfsCandidateServingAdmission implements Middleware, EfsHostEvidence
{
    private EfsCandidateServingAdmissionState $state = EfsCandidateServingAdmissionState::PREPARED;

    private readonly DeferredCancellation $revocation;

    public function __construct(private readonly EfsHostEvidence $evidence)
    {
        $this->revocation = new DeferredCancellation();
    }

    public function state(): EfsCandidateServingAdmissionState
    {
        return $this->state;
    }

    /** Activate exactly once after the caller has completed every startup gate. */
    public function activate(): void
    {
        match ($this->state) {
            EfsCandidateServingAdmissionState::PREPARED => null,
            EfsCandidateServingAdmissionState::ACTIVATING => throw new \LogicException('EFS serving admission activation is already in progress.'),
            EfsCandidateServingAdmissionState::ACTIVE => throw new \LogicException('EFS serving admission was already activated.'),
            EfsCandidateServingAdmissionState::REVOKED => throw new \RuntimeException('EFS serving admission was revoked.'),
        };

        $this->state = EfsCandidateServingAdmissionState::ACTIVATING;
        try {
            $this->evidence->assertFresh();
        } catch (\Throwable) {
            $this->revoke();
            throw new \RuntimeException('EFS serving admission activation failed.');
        }

        if ($this->revocation->isCancelled()) {
            throw new \RuntimeException('EFS serving admission was revoked during activation.');
        }

        $this->state = EfsCandidateServingAdmissionState::ACTIVE;
    }

    /** Revocation is idempotent and terminal from every prior state. */
    public function revoke(): void
    {
        $this->state = EfsCandidateServingAdmissionState::REVOKED;
        if (! $this->revocation->isCancelled()) {
            $this->revocation->cancel();
        }
    }

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        try {
            $this->assertFresh();
        } catch (\Throwable) {
            throw new SlowDownException('Storage host evidence is unavailable.');
        }

        return $requestHandler->handleRequest($request);
    }

    /** ACTIVE requests share the terminal revocation state with readiness. */
    public function assertFresh(): void
    {
        if ($this->state !== EfsCandidateServingAdmissionState::ACTIVE) {
            throw new \RuntimeException('EFS serving admission is unavailable.');
        }

        try {
            $this->evidence->assertFresh();
        } catch (\Throwable) {
            $this->revoke();
            throw new \RuntimeException('EFS serving admission is unavailable.');
        }

        if ($this->revocation->isCancelled()) {
            throw new \RuntimeException('EFS serving admission is unavailable.');
        }
    }
}
