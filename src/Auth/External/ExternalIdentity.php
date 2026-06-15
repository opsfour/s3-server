<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth\External;

/**
 * Normalized identity resolved from an external IdP token.
 */
final readonly class ExternalIdentity
{
    /**
     * @param array<string, mixed> $claims
     * @param list<string> $groups
     * @param list<string> $policyNames
     * @param list<string> $allowedPrefixes
     */
    public function __construct(
        public string $subject,
        public string $issuer,
        public string $ownerId,
        public string $displayName = '',
        public array $claims = [],
        public array $groups = [],
        public array $policyNames = [],
        public array $allowedPrefixes = [],
    ) {
        if ($this->subject === '') {
            throw new \InvalidArgumentException('External identity subject must not be empty.');
        }

        if ($this->ownerId === '') {
            throw new \InvalidArgumentException('External identity owner ID must not be empty.');
        }
    }
}
