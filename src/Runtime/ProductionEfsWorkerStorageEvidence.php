<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Schema-v2 worker evidence backed by the process-lifetime validation latch. */
final readonly class ProductionEfsWorkerStorageEvidence implements EfsStorageEvidence
{
    public function __construct(private ProductionEfsWorkerEvidenceScope $scope) {}

    public function assertFresh(): void
    {
        ProductionEfsWorkerEvidenceLatch::assertFresh($this->scope);
    }

    public function storageRoot(): string
    {
        return $this->scope->storageRoot;
    }
}
