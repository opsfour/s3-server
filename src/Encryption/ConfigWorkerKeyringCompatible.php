<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

/** @internal Proves that an SSE-S3 worker can use the exact injected keyring. */
interface ConfigWorkerKeyringCompatible
{
    /** Returns null when this service cannot use the inherited worker keyring. */
    public function configWorkerKeyringFingerprint(): ?string;
}
