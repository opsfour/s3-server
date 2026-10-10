<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Sync\Lock;
use OpsFour\S3Server\Exception\SlowDownException;

/**
 * Process-local admission for the shared SQL renewal pool. Acquire before
 * reserving a SQL intent or staging bytes. A timed-out renewal must retain
 * its permit until its detached SQL future actually terminates.
 */
final class LocalObjectWriteLeaseAdmission
{
    private int $active = 0;

    public function __construct(private readonly int $maximum = 8)
    {
        if ($maximum < 1 || $maximum > 256) {
            throw new \InvalidArgumentException('Local write lease admission limit must be between 1 and 256.');
        }
    }

    public function acquire(): Lock
    {
        if ($this->active >= $this->maximum) {
            throw new SlowDownException('Local write lease renewal capacity is exhausted.');
        }
        $this->active++;

        return new Lock(function (): void {
            $this->active--;
        });
    }

    public function activeCount(): int
    {
        return $this->active;
    }

    public function capacity(): int
    {
        return $this->maximum;
    }
}
