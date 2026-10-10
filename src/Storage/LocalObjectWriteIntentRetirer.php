<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntent;

/**
 * Disabled until SQL can durably prove that an attempt has no other dispatched
 * worker. One call's before-dispatch result cannot establish that fact when
 * another call with the same attempt ID may still publish later. Let the
 * collector seal such intents; never remove their SQL row or physical path.
 */
final readonly class LocalObjectWriteIntentRetirer
{
    /** Always false until a durable one-shot dispatch/retire CAS exists. */
    public function retire(ObjectWriteIntent $reserved, LocalObjectWriteOutcome $outcome): bool
    {
        if ($outcome->attemptId !== $reserved->attemptId
            || $outcome->storageTier !== $reserved->storageTier
            || $outcome->storagePath !== $reserved->storagePath) {
            return false;
        }
        if ($outcome->state === LocalObjectWriteOutcome::UNCERTAIN) {
            return false;
        }
        if ($outcome->state !== LocalObjectWriteOutcome::BEFORE_DISPATCH
            && ($outcome->state !== LocalObjectWriteOutcome::COMPLETED || $outcome->result !== null)) {
            throw new \LogicException('A published local write cannot be retired without metadata or a collector seal.');
        }
        return false;
    }
}
