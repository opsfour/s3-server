<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** A local, bounded heartbeat check; it does not authorize physical writes. */
interface EfsHostEvidence
{
    /** Reject missing, stale, changed or untrusted host evidence. */
    public function assertFresh(): void;
}
