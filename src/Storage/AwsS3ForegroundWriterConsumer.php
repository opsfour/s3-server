<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Runtime attestation for handlers that can publish ordinary AWS objects. */
interface AwsS3ForegroundWriterConsumer
{
    public function usesAwsS3ForegroundObjectWriter(AwsS3ForegroundObjectWriter $writer): bool;
}
