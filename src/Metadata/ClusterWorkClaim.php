<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** A durable work payload paired with its exact worker/generation fence. */
final readonly class ClusterWorkClaim
{
    public function __construct(
        public ClusterWorkToken $token,
        public mixed $work,
    ) {}
}
