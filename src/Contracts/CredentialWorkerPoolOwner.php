<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

use Amp\Parallel\Worker\WorkerPool;

/** Transfers credential worker pools to the serving runtime lifecycle. */
interface CredentialWorkerPoolOwner
{
    /** @param \Closure(WorkerPool, int): void $receiver */
    public function transferCredentialWorkerPools(\Closure $receiver): void;
}
