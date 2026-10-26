<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Contracts;

/** Atomically removes at most a bounded number of expired temporary credentials. */
interface AtomicExpiredCredentialPruner
{
    public function pruneExpiredCredentials(\DateTimeImmutable $now, int $limit): int;
}
