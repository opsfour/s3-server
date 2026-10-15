<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Retains the sole cleanup owner when graph and ingress assembly fails. */
final class EfsCandidateAssemblyBootstrapException extends \RuntimeException
{
    public function __construct(
        public readonly EfsCandidateAssemblyPartialResources $resources,
        \Throwable $bootstrapError,
    ) {
        parent::__construct('EFS candidate assembly failed and resource shutdown is incomplete.', 0, $bootstrapError);
    }
}
