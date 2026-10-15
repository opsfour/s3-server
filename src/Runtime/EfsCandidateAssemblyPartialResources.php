<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\WorkerPool;

/**
 * Retains resources when assembly fails between graph and ingress ownership.
 *
 * @internal This is a retryable cleanup owner, not a serving capability.
 */
final class EfsCandidateAssemblyPartialResources
{
    private bool $ingressPoolClosed = false;

    private bool $ingressPoolNeedsKill = false;

    private bool $graphClosed = false;

    private bool $shutdownInProgress = false;

    public function __construct(
        private readonly EfsCandidateRuntimeResources $graph,
        private readonly ?WorkerPool $ingressPool = null,
    ) {
        $this->ingressPoolClosed = $ingressPool === null;
        // A retained pool arrives only through EfsCandidateIngressBootstrapException,
        // after its graceful shutdown Future has already failed permanently.
        $this->ingressPoolNeedsKill = $ingressPool !== null;
    }

    public function isClosed(): bool
    {
        return $this->ingressPoolClosed && $this->graphClosed;
    }

    /** Close ingress first because a partially built pool may retain graph users. */
    public function shutdown(): void
    {
        if ($this->isClosed()) {
            return;
        }
        if ($this->shutdownInProgress) {
            throw new \LogicException('EFS candidate assembly shutdown is already in progress.');
        }
        $this->shutdownInProgress = true;

        try {
            if (! $this->ingressPoolClosed) {
                try {
                    if ($this->ingressPoolNeedsKill) {
                        $this->ingressPool?->kill();
                    } else {
                        $this->ingressPool?->shutdown();
                    }
                    $this->ingressPoolClosed = true;
                } catch (\Throwable) {
                    $this->ingressPoolNeedsKill = true;
                    throw new \RuntimeException('EFS candidate assembly shutdown incomplete.');
                }
            }
            if (! $this->graphClosed) {
                try {
                    $this->graph->shutdown();
                    $this->graphClosed = true;
                } catch (\Throwable) {
                    throw new \RuntimeException('EFS candidate assembly shutdown incomplete.');
                }
            }
        } finally {
            $this->shutdownInProgress = false;
        }
    }
}
