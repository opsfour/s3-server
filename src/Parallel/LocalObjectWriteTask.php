<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\StorageTempWorkerLease;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

/** @implements Task<LocalObjectWriteTaskResult, never, never> */
final readonly class LocalObjectWriteTask implements Task
{
    public function __construct(
        private string $root,
        private ObjectWriteIntent $intent,
        private string $key,
        private string $stagePath,
        private string $stageLeasePath,
        private string $stageNonce,
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

    public function run(Channel $channel, Cancellation $cancellation): LocalObjectWriteTaskResult
    {
        $trackingIo = new TrackingLocalObjectWriteIo($this->io ?? new NativeLocalObjectWriteIo());
        $stream = null;
        $stageLease = null;
        $publicationCapacity = null;
        $coordinator = null;
        try {
            $cancellation->throwIfRequested();
            if ($this->tempCoordinatorRoot === null) {
                throw new \RuntimeException('Local object worker requires the canonical temporary coordinator root.');
            }
            $stageLease = StorageTempWorkerLease::acquire(
                $this->tempCoordinatorRoot,
                $this->stageLeasePath,
                $this->stagePath,
                $this->stageNonce,
            );
            clearstatcache(true, $this->stagePath);
            if (@filesize($this->stagePath) !== $this->expectedSize) {
                throw new \RuntimeException('Local object staging size changed before worker read.');
            }
            $resource = @fopen($this->stagePath, 'rb');
            if ($resource === false) {
                throw new \RuntimeException('Local object worker could not open staging file.');
            }
            $stream = new ReadableResourceStream($resource);
            $coordinator = new StorageTempBudgetCoordinator(
                $this->tempCoordinatorRoot,
                $this->tempBudgetBytes,
                $this->tempReserveBytes,
            );
            if ($this->publicationLeasePath !== null || $this->publicationTempPath !== null) {
                if ($this->publicationLeasePath === null || $this->publicationTempPath === null || $this->publicationNonce === null) {
                    throw new \RuntimeException('Local object publication capacity is incomplete.');
                }
                $publicationCapacity = $coordinator->resumeExisting(
                    $this->publicationLeasePath,
                    $this->publicationTempPath,
                    $this->publicationNonce,
                );
            }
            $result = (new LocalObjectWriteFence($this->root, $trackingIo, $coordinator))
                ->write($this->intent, $this->key, $stream, $this->expectedSize, $publicationCapacity);
            if ($result->size !== $this->expectedSize
                || $result->sha256Base64 === null
                || ! hash_equals(base64_encode($this->expectedSha256), $result->sha256Base64)) {
                throw new \RuntimeException('Local object worker result does not match staged bytes.');
            }

            return new LocalObjectWriteTaskResult($result, true);
        } catch (\Throwable $error) {
            return new LocalObjectWriteTaskResult(
                null,
                $trackingIo->publicationAttempted(),
                $error::class,
                $error->getMessage(),
            );
        } finally {
            try {
                $stream?->close();
            } finally {
                try {
                    $stageLease?->close();
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
}

/** Tracks the first possibly ambiguous create-only syscall. */
final class TrackingLocalObjectWriteIo implements LocalObjectWriteIo
{
    private bool $publicationAttempted = false;

    public function __construct(private readonly LocalObjectWriteIo $inner) {}

    public function publicationAttempted(): bool
    {
        return $this->publicationAttempted;
    }

    public function syncFile($file): bool
    {
        return $this->inner->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->inner->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        $this->publicationAttempted = true;
        return $this->inner->link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return $this->inner->rename($source, $target);
    }
}
