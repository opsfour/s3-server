<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalMultipartAssemblyFence;
use OpsFour\S3Server\Storage\StorageWriteResult;

/** @implements Task<StorageWriteResult, never, never> */
final readonly class LocalMultipartAssemblyTask implements Task
{
    /** @param list<array{partNumber: int, etag: string, size: int, storagePath: string}> $parts */
    public function __construct(
        private string $root,
        private string $bucket,
        private string $key,
        private string $generation,
        private string $attemptId,
        private string $uploadId,
        private array $parts,
        private string $target,
        private ?EfsWorkerEvidenceScope $scope = null,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): StorageWriteResult
    {
        $cancellation->throwIfRequested();
        $evidence = $this->scope !== null ? EfsHostEvidenceReader::fromWorkerScope($this->scope) : null;
        $evidence?->assertFresh();

        return (new LocalMultipartAssemblyFence($this->root, evidence: $evidence))->assemble(
            $this->bucket,
            $this->key,
            $this->generation,
            $this->attemptId,
            $this->uploadId,
            $this->parts,
            $this->target,
        );
    }
}
