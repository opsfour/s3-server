<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Refuses a local fence rollout while pre-token multipart writers may exist. */
interface LocalMultipartFenceAdmissionStore
{
    public function assertNoLegacyLocalMultipartIntents(string $storageTier): void;
}
