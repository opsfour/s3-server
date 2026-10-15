<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\S3Server;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Owns one completely prepared but listenerless qualified EFS candidate.
 *
 * The serving admission remains PREPARED and the background roster remains
 * parked. This owner deliberately has no operation that can bind a socket,
 * activate serving, or release background work.
 *
 * @internal
 */
final class EfsCandidatePreBindRuntime
{
    private ?S3Server $server = null;

    private ?EfsCandidateManagementResources $management = null;

    private ?EfsCandidateBackgroundSupervisor $backgroundSupervisor = null;

    private ?ContextWorkerPool $selectWorkerPool = null;

    private bool $graphAttached = false;

    private bool $prepared = false;

    private bool $stopped = false;

    private bool $stopInProgress = false;

    private function __construct(
        private readonly EfsCandidateRuntimeResources $graph,
        private readonly EfsCandidateIngressResources $ingress,
    ) {}

    /**
     * Consume the supplied graph and ingress bundle and prepare every local
     * admission gate. Ownership begins before the server shell is constructed,
     * so every failure closes the supplied resources or returns a retryable
     * owner in EfsCandidatePreBindBootstrapException.
     */
    public static function prepare(
        EfsCandidateRuntimeResources $graph,
        EfsCandidateIngressResources $ingress,
        LoggerInterface $logger = new NullLogger(),
        float $backgroundReadyTimeoutSeconds = 5.0,
    ): self {
        return self::prepareWithFaultInjection(
            $graph,
            $ingress,
            $logger,
            $backgroundReadyTimeoutSeconds,
            null,
        );
    }

    /**
     * @internal Test-only fault boundary after background fibers start and
     * before the readiness wait. Production builders must call prepare().
     *
     * @param \Closure(): void $beforeBackgroundReadyWait
     */
    public static function prepareForTests(
        EfsCandidateRuntimeResources $graph,
        EfsCandidateIngressResources $ingress,
        \Closure $beforeBackgroundReadyWait,
        LoggerInterface $logger = new NullLogger(),
        float $backgroundReadyTimeoutSeconds = 5.0,
    ): self {
        return self::prepareWithFaultInjection(
            $graph,
            $ingress,
            $logger,
            $backgroundReadyTimeoutSeconds,
            $beforeBackgroundReadyWait,
        );
    }

    /** @param (\Closure(): void)|null $beforeBackgroundReadyWait */
    private static function prepareWithFaultInjection(
        EfsCandidateRuntimeResources $graph,
        EfsCandidateIngressResources $ingress,
        LoggerInterface $logger,
        float $backgroundReadyTimeoutSeconds,
        ?\Closure $beforeBackgroundReadyWait,
    ): self {
        $runtime = new self($graph, $ingress);

        try {
            $runtime->server = S3Server::forQualifiedEfsCandidate($graph, $logger);
            $runtime->prepareOwnedResources($logger, $backgroundReadyTimeoutSeconds, $beforeBackgroundReadyWait);

            return $runtime;
        } catch (\Throwable $bootstrapError) {
            try {
                $runtime->stop();
            } catch (\Throwable) {
                throw new EfsCandidatePreBindBootstrapException($runtime, $bootstrapError);
            }

            throw $bootstrapError;
        }
    }

    /** The only request-facing view exposed before a future start capability. */
    public function handleReadiness(Request $request): Response
    {
        if (! $this->prepared || $this->stopped || $this->management === null) {
            throw new \LogicException('EFS candidate pre-bind runtime is unavailable.');
        }

        return $this->management->readiness->handleRequest($request);
    }

    public function servingAdmissionState(): ?EfsCandidateServingAdmissionState
    {
        return $this->management?->admission->state();
    }

    public function backgroundSupervisorState(): ?EfsCandidateBackgroundSupervisorState
    {
        return $this->backgroundSupervisor?->state();
    }

    public function isPreBindAdmissionPrepared(): bool
    {
        return $this->prepared
            && ($this->server?->isQualifiedEfsCandidatePreBindAdmissionPrepared() ?? false);
    }

    public function isPrepared(): bool
    {
        return $this->prepared
            && ! $this->stopped
            && ! $this->stopInProgress
            && $this->management?->admission->state() === EfsCandidateServingAdmissionState::PREPARED
            && $this->backgroundSupervisor?->state() === EfsCandidateBackgroundSupervisorState::READY
            && $this->isPreBindAdmissionPrepared();
    }

    /** Revoke admission, stop parked work, then close the server-owned graph. */
    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        if ($this->stopInProgress) {
            throw new \LogicException('EFS candidate pre-bind shutdown is already in progress.');
        }
        $this->stopInProgress = true;
        // Leaving PREPARED is terminal even when cleanup needs a retry. Never
        // expose a partly closed owner as usable after stop() has begun.
        $this->prepared = false;

        try {
            $this->management?->admission->revoke();
            $this->backgroundSupervisor?->stop();

            $server = $this->server;

            // Attachment commits all server fields before ownership transfer.
            // Recover that committed state if a transfer assertion threw after
            // the fields were assigned, so rollback still uses the sole owner.
            if (! $this->graphAttached && $server?->ownsQualifiedEfsCandidateGraph($this->graph)) {
                $this->graphAttached = true;
            }

            if ($this->graphAttached) {
                if ($server === null) {
                    throw new \LogicException('EFS candidate graph is attached without its server owner.');
                }
                $server->stop();
            } else {
                $this->selectWorkerPool?->shutdown();
                $this->ingress->shutdown();
                $this->graph->shutdown();
            }

            $this->stopped = true;
        } catch (\Throwable) {
            throw new \RuntimeException('EFS candidate pre-bind shutdown incomplete; dependencies remain owned.');
        } finally {
            $this->stopInProgress = false;
        }
    }

    /** @param (\Closure(): void)|null $beforeBackgroundReadyWait */
    private function prepareOwnedResources(
        LoggerInterface $logger,
        float $backgroundReadyTimeoutSeconds,
        ?\Closure $beforeBackgroundReadyWait,
    ): void {
        $server = $this->server
            ?? throw new \LogicException('EFS candidate pre-bind runtime requires its server shell.');
        $config = $this->graph->buildConfig
            ?? throw new \LogicException('EFS candidate pre-bind runtime requires a factory-built configuration.');
        if ($config->allowUnfencedStorageForTests || $config->localObjectWriteTestScratchPath !== null) {
            throw new \RuntimeException('EFS candidate pre-bind runtime rejects unsafe storage test overrides.');
        }
        if ($config->notificationWorkerEnabled) {
            throw new \RuntimeException('EFS candidate notification background processing is not qualified.');
        }
        if (! is_finite($backgroundReadyTimeoutSeconds) || $backgroundReadyTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('EFS candidate background ready timeout must be positive and finite.');
        }

        $this->graph->qualification->assertCandidateRuntimeInputs(
            $config,
            $this->graph->metadata,
            $this->graph->backend,
            $this->graph->tiers,
            $this->graph->scratch,
        );
        $this->ingress->assertBoundTo($this->graph->qualification, $this->graph->scratch);
        $this->ingress->assertMetadataBinding($this->graph->metadata);
        $credentialProvider = $this->ingress->credentialProvider();
        if (! $credentialProvider instanceof CredentialHealthProbe) {
            throw new \RuntimeException('EFS candidate authentication requires a credential readiness probe.');
        }

        $this->management = EfsCandidateManagementResources::create(
            $this->graph,
            $config,
            $logger,
            $credentialProvider,
        );
        $this->backgroundSupervisor = new EfsCandidateBackgroundSupervisor(
            $this->graph,
            new EfsCandidateBackgroundBarrier(),
            $logger,
        );
        if ($config->selectWorkerPoolSize > 0) {
            $this->selectWorkerPool = new ContextWorkerPool($config->selectWorkerPoolSize);
        }

        $server->attachQualifiedEfsCandidateGraph($this->graph, $this->ingress, $this->selectWorkerPool);
        $this->graphAttached = true;
        $server->attachQualifiedEfsCandidateManagement($this->management);

        $this->backgroundSupervisor->startParked();
        $beforeBackgroundReadyWait?->__invoke();
        $this->backgroundSupervisor->awaitReady($backgroundReadyTimeoutSeconds);
        $server->prepareQualifiedEfsCandidatePreBindAdmission();
        $server->assertQualifiedEfsCandidatePreBindAdmissionFresh();

        if ($this->management->admission->state() !== EfsCandidateServingAdmissionState::PREPARED
            || $this->backgroundSupervisor->state() !== EfsCandidateBackgroundSupervisorState::READY
            || ! $server->isQualifiedEfsCandidatePreBindAdmissionPrepared()) {
            throw new \RuntimeException('EFS candidate pre-bind runtime did not remain closed and ready.');
        }
        $this->prepared = true;
    }
}
