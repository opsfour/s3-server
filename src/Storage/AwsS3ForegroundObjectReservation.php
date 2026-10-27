<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/** Mutable exact reservation authority carried through every pre-dispatch step. */
final class AwsS3ForegroundObjectReservation
{
    private int $nextRenewalNanos;

    public function __construct(
        private readonly object $authority,
        private ObjectWriteIntent $intent,
        private readonly int $renewIntervalNanos,
    ) {
        $this->nextRenewalNanos = hrtime(true) + $renewIntervalNanos;
    }

    public function current(object $authority): ObjectWriteIntent
    {
        $this->assertAuthority($authority);

        return $this->intent;
    }

    public function renewalDue(object $authority, bool $force = false): bool
    {
        $this->assertAuthority($authority);

        return $force || hrtime(true) >= $this->nextRenewalNanos;
    }

    public function replace(object $authority, ObjectWriteIntent $intent): void
    {
        $this->assertAuthority($authority);
        $this->intent = $intent;
        $this->nextRenewalNanos = hrtime(true) + $this->renewIntervalNanos;
    }

    private function assertAuthority(object $authority): void
    {
        if ($this->authority !== $authority) {
            throw new \InvalidArgumentException('AWS foreground reservation belongs to another coordinator.');
        }
    }
}
