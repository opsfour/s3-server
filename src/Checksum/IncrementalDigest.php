<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Checksum;

/** A one-shot incremental binary digest. */
interface IncrementalDigest
{
    public function update(string $data): void;

    /** Return the raw digest bytes. */
    public function finalize(): string;
}
