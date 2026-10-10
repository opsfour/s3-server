<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalEfsTempSizeFence;

/** @implements Task<null, never, never> */
final readonly class LocalEfsTempSizeTask implements Task
{
    public function __construct(
        private EfsWorkerEvidenceScope $scope,
        private string $path,
        private int $expectedSize,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $cancellation->throwIfRequested();
        $evidence = EfsHostEvidenceReader::fromWorkerScope($this->scope);
        (new LocalEfsTempSizeFence($this->scope->storageRoot, $evidence))->assertSize($this->path, $this->expectedSize);
        return null;
    }
}
