<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalEfsTempCleanup;

/** @implements Task<int, never, never> */
final readonly class LocalEfsTempCleanupTask implements Task
{
    public function __construct(
        private EfsWorkerEvidenceScope $scope,
        private int $maxAgeSeconds,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): int
    {
        $cancellation->throwIfRequested();
        $evidence = EfsHostEvidenceReader::fromWorkerScope($this->scope);
        return (new LocalEfsTempCleanup($this->scope->storageRoot, $evidence))->run($this->maxAgeSeconds);
    }
}
