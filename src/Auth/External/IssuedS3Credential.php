<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

use OpsFour\S3Server\Auth\Credential;

final readonly class IssuedS3Credential
{
    /**
     * @param list<string> $policyNames
     * @param list<string> $allowedPrefixes
     */
    public function __construct(
        public Credential $credential,
        public ExternalIdentity $identity,
        public ?\DateTimeImmutable $expiresAt = null,
        public array $policyNames = [],
        public array $allowedPrefixes = [],
    ) {}
}
