<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalEfsStoredPathCheck;

/** @implements Task<bool, never, never> */
final readonly class LocalEfsStoredPathCheckTask implements Task
{
    public function __construct(
        private EfsWorkerEvidenceScope $scope,
        private string $path,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): bool
    {
        $cancellation->throwIfRequested();
        $evidence = EfsHostEvidenceReader::fromWorkerScope($this->scope);
        return (new LocalEfsStoredPathCheck($this->scope->storageRoot, $evidence))->isConfined($this->path);
    }
}
