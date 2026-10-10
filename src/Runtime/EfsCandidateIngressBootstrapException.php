<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\WorkerPool;

/** Retains the pool owner if partial ingress construction cannot close it. */
final class EfsCandidateIngressBootstrapException extends \RuntimeException
{
    public function __construct(
        public readonly WorkerPool $workerPool,
        \Throwable $bootstrapError,
    ) {
        parent::__construct('EFS ingress bootstrap failed and worker shutdown is incomplete.', 0, $bootstrapError);
    }
}
