<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** @internal Listenerless candidate check; this does not admit production serving. */
final readonly class EfsCandidateGraphAdmissionEvidence implements EfsHostEvidence
{
    public function __construct(
        private EfsCandidateRuntimeResources $graph,
        private EfsHostEvidenceReader $reader,
    ) {}

    public function assertFresh(): void
    {
        $this->graph->assertOpenForAdmission();
        $this->reader->assertFresh();
        $this->graph->assertOpenForAdmission();
    }
}
