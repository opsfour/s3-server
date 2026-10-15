<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Retains the listenerless owner when rollback could not finish. */
final class EfsCandidatePreBindBootstrapException extends \RuntimeException
{
    public function __construct(
        public readonly EfsCandidatePreBindRuntime $runtime,
        \Throwable $bootstrapError,
    ) {
        parent::__construct('EFS candidate pre-bind bootstrap failed and resource shutdown is incomplete.', 0, $bootstrapError);
    }
}
