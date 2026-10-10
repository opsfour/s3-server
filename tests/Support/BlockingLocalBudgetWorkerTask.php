<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/** @implements Task<null, never, never> */
final readonly class BlockingLocalBudgetWorkerTask implements Task
{
    public function __construct(private string $enteredPath, private string $releasePath) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        if (file_put_contents($this->enteredPath, 'entered') === false) {
            throw new \RuntimeException('Could not signal local budget worker entry.');
        }
        $deadline = microtime(true) + 10.0;
        while (! file_exists($this->releasePath) && microtime(true) < $deadline) {
            usleep(10_000);
        }
        if (! file_exists($this->releasePath)) {
            throw new \RuntimeException('Local budget worker was not released.');
        }

        return null;
    }
}
