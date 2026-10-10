<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** The incomplete graph remains owned after startup cleanup failed. */
final class EfsCandidateBootstrapException extends \RuntimeException
{
    public function __construct(
        public readonly EfsCandidatePartialResources $resources,
        \Throwable $bootstrapError,
    ) {
        parent::__construct('EFS candidate bootstrap failed and resource shutdown is incomplete.', 0, $bootstrapError);
    }
}
