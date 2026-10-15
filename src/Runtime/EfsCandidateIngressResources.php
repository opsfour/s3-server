<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\PostObjectTempFileSweeper;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;

/**
 * Owns the inert EFS candidate's browser POST and checksum ingress resources
 * until an exact S3Server accepts ownership. Production serving remains closed.
 */
final class EfsCandidateIngressResources
{
    private bool $closed = false;

    private bool $shutdownInProgress = false;

    private bool $workerPoolNeedsKill = false;

    private ?S3Server $serverOwner = null;

    private function __construct(
        public readonly EfsQualificationContext $qualification,
        public readonly EfsNodeLocalScratch $scratch,
        public readonly S3ServerConfig $config,
        private readonly MetadataStore $metadata,
        private readonly CredentialProvider $credentialProvider,
        public readonly string $tempDir,
        public readonly int $budgetBytes,
        public readonly ContextWorkerPool $workerPool,
        public readonly PostObjectSpoolCoordinator $coordinator,
        public readonly PostObjectTempFileSweeper $sweeper,
        public readonly AuthMiddleware $auth,
        public readonly RequestBodySpool $requestBodySpool,
    ) {
        $this->assertBoundTo($qualification, $scratch);
    }

    /** Build one fresh, bounded pool and all ingress components before API fibers. */
    public static function create(
        EfsQualificationContext $qualification,
        EfsNodeLocalScratch $scratch,
        S3ServerConfig $config,
        MetadataStore $metadata,
        CredentialProvider $credentialProvider,
    ): self {
        $qualification->assertCandidateMetadataBinding($config, $metadata);
        $scratch->assertBoundTo($qualification);
        if ($config->postSpoolBudgetBytes !== $scratch->budgetBytes()) {
            throw new \RuntimeException('EFS ingress budget must equal qualified node-local scratch budget.');
        }
        $tempDir = $scratch->path();
        $pool = new ContextWorkerPool($config->requestBodySpoolWorkerPoolSize);
        try {
            $coordinator = new PostObjectSpoolCoordinator($tempDir);
            $sweeper = new PostObjectTempFileSweeper($pool, $tempDir);
            $auth = new AuthMiddleware(
                credentialProvider: $credentialProvider,
                region: $config->region,
                requestBodySizeLimit: $config->requestBodySizeLimit,
                metadata: $metadata,
                postSpoolBudgetBytes: $config->postSpoolBudgetBytes,
                postSpoolSweeper: $sweeper,
                postSpoolCoordinator: $coordinator,
            );
            $requestBodySpool = new RequestBodySpool($pool, $tempDir, $config->postSpoolBudgetBytes, $coordinator);

            return new self(
                $qualification,
                $scratch,
                $config,
                $metadata,
                $credentialProvider,
                $tempDir,
                $config->postSpoolBudgetBytes,
                $pool,
                $coordinator,
                $sweeper,
                $auth,
                $requestBodySpool,
            );
        } catch (\Throwable $error) {
            try {
                $pool->shutdown();
            } catch (\Throwable) {
                throw new EfsCandidateIngressBootstrapException($pool, $error);
            }
            throw $error;
        }
    }

    /** May only run before API fibers, because scratch identity checks can block. */
    public function assertBoundTo(EfsQualificationContext $qualification, EfsNodeLocalScratch $scratch): void
    {
        if ($this->closed || $this->workerPoolNeedsKill
            || $this->qualification !== $qualification || $this->scratch !== $scratch) {
            throw new \RuntimeException('EFS ingress resources belong to a different qualification or were closed.');
        }
        $scratch->assertBoundTo($qualification);
        if ($this->tempDir !== $scratch->path() || $this->budgetBytes !== $scratch->budgetBytes()) {
            throw new \RuntimeException('EFS ingress path or budget differs from qualified scratch.');
        }
        if (! $this->workerPool->isRunning()
            || $this->workerPool->getWorkerLimit() !== $this->config->requestBodySpoolWorkerPoolSize) {
            throw new \RuntimeException('EFS ingress worker pool is stopped or has a different limit.');
        }
        $this->coordinator->assertQualifiedBinding($this->tempDir);
        $this->sweeper->assertQualifiedBinding($this->tempDir, $this->workerPool);
        $this->auth->assertQualifiedIngressBinding($this->tempDir, $this->budgetBytes, $this->sweeper, $this->coordinator);
        $this->assertCredentialProviderBinding($this->credentialProvider);
        $this->requestBodySpool->assertQualifiedBinding(
            $this->tempDir,
            $this->budgetBytes,
            $this->coordinator,
            $this->workerPool,
        );
    }

    /** @internal Prove the exact provider used by SigV4 and browser POST. */
    public function assertCredentialProviderBinding(CredentialProvider $credentialProvider): void
    {
        if ($this->closed || $this->shutdownInProgress || $this->workerPoolNeedsKill
            || $this->credentialProvider !== $credentialProvider) {
            throw new \RuntimeException('EFS ingress credential provider differs from candidate provider.');
        }
        $this->auth->assertQualifiedCredentialProviderBinding($credentialProvider);
    }

    /** @internal Return the exact provider after the existing identity checks have succeeded. */
    public function credentialProvider(): CredentialProvider
    {
        $this->assertCredentialProviderBinding($this->credentialProvider);

        return $this->credentialProvider;
    }

    /** @internal Attach only the exact config that created this ingress graph. */
    public function assertAttachableTo(S3ServerConfig $config): void
    {
        if ($this->closed || $this->shutdownInProgress || $this->workerPoolNeedsKill
            || $this->serverOwner !== null || $this->config !== $config) {
            throw new \RuntimeException('EFS ingress is closed, already owned or configured for another server.');
        }
        $this->assertBoundTo($this->qualification, $this->scratch);
        $this->assertDrained();
        if ($this->workerPool->getWorkerCount() !== 0) {
            throw new \RuntimeException('EFS ingress worker pool was used before candidate attachment.');
        }
    }

    /** @internal Browser POST authorization must use the graph's exact SQL facade. */
    public function assertMetadataBinding(MetadataStore $metadata): void
    {
        if ($this->metadata !== $metadata) {
            throw new \RuntimeException('EFS ingress metadata differs from candidate runtime metadata.');
        }
    }

    /** @internal The server becomes the sole worker-pool owner after all checks. */
    public function transferOwnershipTo(S3Server $server): void
    {
        if ($this->serverOwner !== null || $this->closed || $this->config !== $server->getConfig()
            || ! $server->ownsQualifiedEfsCandidateIngress($this)) {
            throw new \LogicException('EFS ingress worker pool already has another owner.');
        }
        $this->serverOwner = $server;
    }

    /** @internal The server calls this only after its HTTP drain completed. */
    public function assertDrainedForServerStop(S3Server $server): void
    {
        $this->assertDrainedForServerOwner($server, requireRunningPool: true);
    }

    /** @internal Retry after this server's recorded graceful pool stop failed. */
    public function assertDrainedForServerStopRetry(S3Server $server): void
    {
        $this->assertDrainedForServerOwner($server, requireRunningPool: false);
    }

    /** @internal Mark the already shut-down pool closed without a second close. */
    public function markServerPoolClosed(S3Server $server): void
    {
        if ($this->serverOwner !== $server || $this->closed) {
            throw new \LogicException('EFS ingress worker pool has another owner or is closed.');
        }
        if ($this->workerPool->isRunning()) {
            throw new \LogicException('EFS ingress worker pool is still running.');
        }
        $this->workerPoolNeedsKill = false;
        $this->closed = true;
    }

    private function assertDrained(): void
    {
        $this->requestBodySpool->assertIdle();
        $this->coordinator->assertIdle();
    }

    private function assertDrainedForServerOwner(S3Server $server, bool $requireRunningPool): void
    {
        if ($this->serverOwner !== $server || $this->closed) {
            throw new \LogicException('EFS ingress worker pool has another owner or is closed.');
        }
        if ($requireRunningPool && ! $this->workerPool->isRunning()) {
            throw new \RuntimeException('EFS ingress worker pool stopped outside its server owner.');
        }
        $this->assertDrained();
    }

    /** A failed worker shutdown leaves this bundle owned and retryable. */
    public function shutdown(): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->serverOwner !== null) {
            throw new \LogicException('EFS ingress worker pool is owned by its server.');
        }
        if ($this->shutdownInProgress) {
            throw new \LogicException('EFS ingress shutdown is already in progress.');
        }
        $this->shutdownInProgress = true;
        try {
            $this->assertDrained();
            try {
                if ($this->workerPoolNeedsKill) {
                    $this->workerPool->kill();
                } else {
                    $this->workerPool->shutdown();
                }
            } catch (\Throwable $error) {
                // A graceful ContextWorkerPool shutdown Future is terminal once
                // it fails. Retrying shutdown() would only replay that cached
                // failure, so retain ownership and force the next retry to kill.
                $this->workerPoolNeedsKill = true;

                throw $error;
            }
            $this->workerPoolNeedsKill = false;
            $this->closed = true;
        } finally {
            $this->shutdownInProgress = false;
        }
    }
}
