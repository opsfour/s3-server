<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalEfsChunkReadFence;
use OpsFour\S3Server\Storage\LocalEfsStoredPathCheck;

/** @implements Task<null, string, array{chunk?: string, eof?: bool}> */
final readonly class LocalEfsChunkReadTask implements Task
{
    public function __construct(
        private EfsWorkerEvidenceScope $scope,
        private string $path,
        private ?int $offset,
        private ?int $length,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): null
    {
        $evidence = EfsHostEvidenceReader::fromWorkerScope($this->scope);
        $check = new LocalEfsStoredPathCheck($this->scope->storageRoot, $evidence);
        (new LocalEfsChunkReadFence(
            $evidence,
            static fn(string $path): bool => $check->isConfined($path),
        ))->run($this->path, $this->offset, $this->length, $channel, $cancellation);
        return null;
    }
}
