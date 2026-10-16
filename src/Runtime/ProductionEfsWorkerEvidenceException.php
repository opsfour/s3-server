<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/**
 * Terminal worker-local production EFS evidence failure.
 *
 * Amp preserves this class name as TaskFailureThrowable::getOriginalClassName()
 * across its process channel. The fixed message does not disclose evidence,
 * paths, identities, or the failed validation step.
 */
final class ProductionEfsWorkerEvidenceException extends \RuntimeException
{
    public function __construct()
    {
        parent::__construct('Production EFS worker evidence validation failed.');
    }
}
