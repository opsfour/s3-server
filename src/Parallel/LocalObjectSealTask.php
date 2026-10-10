<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;

/** @implements Task<null, never, never> */
final readonly class LocalObjectSealTask implements Task
{
    public function __construct(
        private string $root,
        private ObjectWriteIntent $claimed,
        private ?EfsWorkerEvidenceScope $efsEvidenceScope = null,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $cancellation->throwIfRequested();
        $evidence = $this->efsEvidenceScope !== null
            ? EfsHostEvidenceReader::fromWorkerScope($this->efsEvidenceScope) : null;
        $evidence?->assertFresh();
        (new LocalObjectWriteFence($this->root, efsEvidence: $evidence))->seal($this->claimed);

        return null;
    }
}
