<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\LocalMultipartAssemblyFence;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

/** @implements Task<StorageWriteResult, never, never> */
final readonly class LocalMultipartAssemblyTask implements Task
{
    /** @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts */
    public function __construct(
        private string $root,
        private string $bucket,
        private string $key,
        private string $generation,
        private string $attemptId,
        private string $uploadId,
        private array $parts,
        private string $target,
        private ?string $tempCoordinatorRoot = null,
        private int $tempBudgetBytes = StorageTempBudgetCoordinator::DEFAULT_BUDGET_BYTES,
        private int $tempReserveBytes = StorageTempBudgetCoordinator::DEFAULT_FILESYSTEM_RESERVE_BYTES,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): StorageWriteResult
    {
        $cancellation->throwIfRequested();
        $coordinator = $this->tempCoordinatorRoot === null ? null : new StorageTempBudgetCoordinator(
            $this->tempCoordinatorRoot,
            $this->tempBudgetBytes,
            $this->tempReserveBytes,
        );
        return (new LocalMultipartAssemblyFence($this->root, tempBudget: $coordinator))->assemble(
            $this->bucket,
            $this->key,
            $this->generation,
            $this->attemptId,
            $this->uploadId,
            $this->parts,
            $this->target,
        );
    }
}
