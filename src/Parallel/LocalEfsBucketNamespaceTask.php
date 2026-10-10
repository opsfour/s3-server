<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\EfsWorkerEvidenceScope;
use OpsFour\S3Server\Storage\LocalEfsBucketNamespace;

/** @implements Task<bool, never, never> */
final readonly class LocalEfsBucketNamespaceTask implements Task
{
    public function __construct(
        private EfsWorkerEvidenceScope $scope,
        private string $bucket,
        private bool $create,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): bool
    {
        $cancellation->throwIfRequested();
        $evidence = EfsHostEvidenceReader::fromWorkerScope($this->scope);
        $namespace = new LocalEfsBucketNamespace($this->scope->storageRoot, $evidence);
        if ($this->create) {
            $namespace->createBucket($this->bucket);
            return true;
        }
        return $namespace->bucketExists($this->bucket);
    }
}
