<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

/** IncrementalDigest adapter for PHP's hash extension. */
final class HashIncrementalDigest implements IncrementalDigest
{
    private \HashContext $context;

    private bool $finalized = false;

    public function __construct(string $algorithm)
    {
        $this->context = hash_init($algorithm);
    }

    public function update(string $data): void
    {
        if ($this->finalized) {
            throw new \LogicException('Cannot update a finalized digest.');
        }
        if ($data !== '') {
            hash_update($this->context, $data);
        }
    }

    public function finalize(): string
    {
        if ($this->finalized) {
            throw new \LogicException('Digest has already been finalized.');
        }
        $this->finalized = true;

        return hash_final($this->context, true);
    }
}
