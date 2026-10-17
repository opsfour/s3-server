<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;

/** @implements Task<null, never, never> */
final readonly class LocalObjectSealTask implements Task
{
    public function __construct(
        private string $root,
        private ObjectWriteIntent $claimed,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $cancellation->throwIfRequested();
        (new LocalObjectWriteFence($this->root))->seal($this->claimed);

        return null;
    }
}
