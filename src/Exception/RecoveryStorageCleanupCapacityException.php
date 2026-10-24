<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

/** A failed physical delete could not be retained at the hard cleanup ceiling. */
final class RecoveryStorageCleanupCapacityException extends \RuntimeException
{
    public function __construct(
        public readonly string $bucket,
        public readonly string $storageTier,
        public readonly string $storagePathHash,
        public readonly string $origin,
        public readonly ?int $cleanupCount,
        \Throwable $deleteError,
        public readonly string $queueException,
    ) {
        parent::__construct(
            'Physical delete failed and recovery cleanup capacity is exhausted; an untracked orphan may exist.',
            0,
            $deleteError,
        );
    }
}
