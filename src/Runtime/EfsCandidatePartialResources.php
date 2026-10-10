<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;

/**
 * Retains an incomplete candidate graph until every owned worker is proven
 * stopped. The bootstrap caller must retry shutdown or terminate its process.
 *
 * @internal This is not a serving capability.
 */
final class EfsCandidatePartialResources
{
    private bool $partClosed = false;

    private bool $objectClosed = false;

    private bool $collectorClosed = false;

    private bool $renewerClosed = false;

    private bool $backendClosed = false;

    private bool $readPoolClosed = false;

    private bool $shutdownInProgress = false;

    public function __construct(
        private readonly ?LocalFencedMultipartPartWriter $partWriter,
        private readonly ?LocalFencedObjectWriter $objectWriter,
        private readonly ?LocalObjectWriteIntentCollector $collector,
        private readonly ?DedicatedAmpObjectWriteLeaseRenewer $renewer,
        private readonly ?FilesystemBackend $backend,
        private readonly ?ContextWorkerPool $readPool,
        private readonly ?\Closure $beforePartStop = null,
    ) {}

    public function isClosed(): bool
    {
        return $this->partClosed && $this->objectClosed && $this->collectorClosed
            && $this->renewerClosed && $this->backendClosed && $this->readPoolClosed;
    }

    /** A failed stop retains all later dependencies for a caller-driven retry. */
    public function shutdown(): void
    {
        if ($this->shutdownInProgress) {
            throw new \LogicException('EFS candidate partial shutdown is already in progress.');
        }
        $this->shutdownInProgress = true;
        try {
            $this->stopOwnedResources();
        } finally {
            $this->shutdownInProgress = false;
        }
    }

    private function stopOwnedResources(): void
    {
        $firstError = null;
        if (! $this->partClosed) {
            try {
                ($this->beforePartStop)?->__invoke();
                $this->partWriter?->shutdown();
                $this->partClosed = true;
            } catch (\Throwable $error) {
                $firstError = $error;
            }
        }
        if (! $this->objectClosed) {
            try {
                $this->objectWriter?->shutdown();
                $this->objectClosed = true;
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }
        if (! $this->collectorClosed) {
            try {
                $this->collector?->shutdown();
                $this->collectorClosed = true;
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }
        if ($firstError !== null) {
            throw $firstError;
        }

        if (! $this->renewerClosed) {
            $this->renewer?->close();
            $this->renewerClosed = true;
        }
        if (! $this->backendClosed) {
            $this->backend?->shutdown();
            $this->backendClosed = true;
        }
        if (! $this->readPoolClosed) {
            $this->readPool?->shutdown();
            $this->readPoolClosed = true;
        }
    }
}
