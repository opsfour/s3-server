<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;

/** @implements Task<string, never, never> */
final readonly class EfsEvidenceScopeWorkerTask implements Task
{
    public function __construct(private EfsWorkerEvidenceScope $scope) {}

    public function run(Channel $channel, Cancellation $cancellation): string
    {
        $cancellation->throwIfRequested();
        return $this->scope->runId . ':' . ($this->scope->pinnedIdentity['epoch'] ?? 'missing');
    }
}
