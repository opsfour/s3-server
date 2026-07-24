<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

final readonly class QuotaConfig
{
    public function __construct(
        public int $maxBucketsPerOwner = 0,
        public int $maxObjectsPerBucket = 0,
        public int $maxBytesPerBucket = 0,
        public int $maxBytesPerOwner = 0,
        public int $maxMultipartUploadsPerBucket = 0,
        public int $maxMultipartUploadsPerOwner = 0,
        public int $maxMultipartBytesPerBucket = 0,
        public int $maxMultipartBytesPerOwner = 0,
    ) {
        foreach ([
            'maxBucketsPerOwner' => $this->maxBucketsPerOwner,
            'maxObjectsPerBucket' => $this->maxObjectsPerBucket,
            'maxBytesPerBucket' => $this->maxBytesPerBucket,
            'maxBytesPerOwner' => $this->maxBytesPerOwner,
            'maxMultipartUploadsPerBucket' => $this->maxMultipartUploadsPerBucket,
            'maxMultipartUploadsPerOwner' => $this->maxMultipartUploadsPerOwner,
            'maxMultipartBytesPerBucket' => $this->maxMultipartBytesPerBucket,
            'maxMultipartBytesPerOwner' => $this->maxMultipartBytesPerOwner,
        ] as $name => $value) {
            if ($value < 0) {
                throw new \InvalidArgumentException("{$name} must be >= 0, got {$value}.");
            }
        }
    }

    public function enabled(): bool
    {
        return $this->maxBucketsPerOwner > 0
            || $this->maxObjectsPerBucket > 0
            || $this->maxBytesPerBucket > 0
            || $this->maxBytesPerOwner > 0
            || $this->maxMultipartUploadsPerBucket > 0
            || $this->maxMultipartUploadsPerOwner > 0
            || $this->maxMultipartBytesPerBucket > 0
            || $this->maxMultipartBytesPerOwner > 0;
    }
}
