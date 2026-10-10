<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Pass both values to consumeArmedObjectWriteIntent in the metadata commit. */
final readonly class LocalObjectWriteDispatchToken
{
    public function __construct(public string $attemptId, public string $dispatchId) {}
}
