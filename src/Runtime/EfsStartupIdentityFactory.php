<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Closure;

/**
 * Build one optional CLI startup identity in evidence-first order. The result
 * is only a prerequisite: production EFS runtime admission remains closed.
 */
final readonly class EfsStartupIdentityFactory
{
    /** @param (Closure(): (string|false))|null $mountInfoReader */
    public function __construct(private ?Closure $mountInfoReader = null) {}

    public function qualify(
        string $evidenceDirectory,
        string $storageRoot,
        string $runId,
        string $efsId,
        string $node,
        string $driver,
        string $sourceSha256,
        string $scratchPath,
    ): EfsStartupIdentity {
        $reader = new EfsLocalStartupEvidenceReader(
            $evidenceDirectory,
            $storageRoot,
            $runId,
            $efsId,
            $node,
            $driver,
            $sourceSha256,
            mountInfoReader: $this->mountInfoReader,
        );
        $context = EfsQualificationContext::qualify($reader, $storageRoot, $this->mountInfoReader);
        $scratch = EfsNodeLocalScratch::qualify($context, $scratchPath, $this->mountInfoReader);
        $scratch->assertBoundTo($context);

        return new EfsStartupIdentity($context, $scratch);
    }
}
