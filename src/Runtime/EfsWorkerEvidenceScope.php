<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Scalar-only startup identity passed through Amp's process-worker channel.
 * A worker must revalidate the local heartbeat; this snapshot is not proof
 * that the EFS relay is still healthy.
 *
 * @internal No serving profile constructs or passes this scope yet.
 */
final readonly class EfsWorkerEvidenceScope
{
    /** @param array<string, int|string> $pinnedIdentity */
    public function __construct(
        public string $evidenceDirectory,
        public string $storageRoot,
        public string $runId,
        public string $efsId,
        public string $node,
        public string $driver,
        public string $sourceSha256,
        public string $storageDev,
        public string $storageIno,
        public string $directoryDev,
        public string $directoryIno,
        public array $pinnedIdentity,
    ) {}
}
