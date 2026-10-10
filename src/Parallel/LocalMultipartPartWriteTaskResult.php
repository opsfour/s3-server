<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use OpsFour\S3Server\Storage\StorageWriteResult;

final readonly class LocalMultipartPartWriteTaskResult
{
    public function __construct(
        public ?StorageWriteResult $result,
        public bool $publicationAttempted,
        public ?string $failureClass = null,
        public ?string $failureMessage = null,
    ) {}
}
