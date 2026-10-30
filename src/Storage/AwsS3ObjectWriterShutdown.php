<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Runtime-owned shutdown capability for AWS staging writers. */
interface AwsS3ObjectWriterShutdown
{
    public function shutdown(): void;
}
