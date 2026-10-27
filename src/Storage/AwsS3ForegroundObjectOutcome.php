<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** A confirmed physical result and the exact dispatch to consume with metadata. */
final readonly class AwsS3ForegroundObjectOutcome
{
    public function __construct(
        public StorageWriteResult $result,
        public LocalObjectWriteDispatchToken $dispatch,
    ) {}
}
