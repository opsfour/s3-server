<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

/** @implements Task<null, never, never> */
final readonly class LocalStageBudgetReserveTask implements Task
{
    public function __construct(
        private string $scratch,
        private int $budgetBytes,
        private string $leasePath,
        private string $tempPath,
        private int $bytes,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $cancellation->throwIfRequested();
        (new StorageTempBudgetCoordinator($this->scratch, $this->budgetBytes))
            ->reserveExisting($this->leasePath, $this->tempPath, $this->bytes);

        return null;
    }
}
