<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

/** Optional read capability for the shared completion-intent capacity row. */
interface MultipartCompletionCapacityReader
{
    /** Null means the wrapped metadata store does not support this metric. */
    public function getMultipartCompletionIntentCount(): ?int;
}
