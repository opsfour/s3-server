<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\LocalMultipartPathFence;

/** @implements Task<null, never, never> */
final readonly class LocalMultipartPublishTask implements Task
{
    public function __construct(
        private string $root,
        private string $stagedPath,
        private string $targetPath,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $cancellation->throwIfRequested();
        (new LocalMultipartPathFence($this->root))->publish($this->stagedPath, $this->targetPath);

        return null;
    }
}
