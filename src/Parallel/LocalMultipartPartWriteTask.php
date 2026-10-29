<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\LocalMultipartPartWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\StorageTempWorkerLease;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

/** @implements Task<LocalMultipartPartWriteTaskResult, never, never> */
final readonly class LocalMultipartPartWriteTask implements Task
{
    public function __construct(
        private string $root,
        private string $source,
        private string $leasePath,
        private string $sourceNonce,
        private string $target,
        private string $bucket,
        private string $generation,
        private string $uploadId,
        private int $partNumber,
        private string $attemptId,
        private int $expectedSize,
        private string $expectedSha256,
        private ?LocalObjectWriteIo $io = null,
        private ?string $tempCoordinatorRoot = null,
        private int $tempBudgetBytes = StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
        private int $tempReserveBytes = StorageTempBudgetCoordinator::DEFAULT_FILESYSTEM_RESERVE_BYTES,
        private ?string $publicationLeasePath = null,
        private ?string $publicationTempPath = null,
        private ?string $publicationNonce = null,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): LocalMultipartPartWriteTaskResult
    {
        $tracking = new TrackingMultipartPartIo($this->io ?? new NativeLocalObjectWriteIo());
        $lease = null;
        $publicationCapacity = null;
        $coordinator = null;
        try {
            $cancellation->throwIfRequested();
            if ($this->tempCoordinatorRoot === null) {
                throw new \RuntimeException('Local multipart worker requires the canonical temporary coordinator root.');
            }
            $lease = StorageTempWorkerLease::acquire(
                $this->tempCoordinatorRoot,
                $this->leasePath,
                $this->source,
                $this->sourceNonce,
            );
            $coordinator = new StorageTempBudgetCoordinator(
                $this->tempCoordinatorRoot,
                $this->tempBudgetBytes,
                $this->tempReserveBytes,
            );
            if ($this->publicationLeasePath !== null || $this->publicationTempPath !== null) {
                if ($this->publicationLeasePath === null || $this->publicationTempPath === null || $this->publicationNonce === null) {
                    throw new \RuntimeException('Local multipart publication capacity is incomplete.');
                }
                $publicationCapacity = $coordinator->resumeExisting(
                    $this->publicationLeasePath,
                    $this->publicationTempPath,
                    $this->publicationNonce,
                );
            }
            $result = (new LocalMultipartPartWriteFence($this->root, $tracking, tempBudget: $coordinator))->publish(
                $this->source,
                $this->target,
                $this->bucket,
                $this->generation,
                $this->uploadId,
                $this->partNumber,
                $this->attemptId,
                $this->expectedSize,
                $this->expectedSha256,
                $publicationCapacity,
            );
            return new LocalMultipartPartWriteTaskResult($result, true);
        } catch (\Throwable $error) {
            return new LocalMultipartPartWriteTaskResult(
                null,
                $tracking->publicationAttempted(),
                $error::class,
                $error->getMessage(),
            );
        } finally {
            try {
                $lease?->close();
            } finally {
                if ($publicationCapacity?->isOpen()) {
                    try {
                        $coordinator?->cleanup($publicationCapacity);
                    } catch (\Throwable) {
                        $publicationCapacity->close();
                    }
                }
                $coordinator?->close();
            }
        }
    }
}
