<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Dto;

/**
 * Data transfer object representing S3 bucket metadata.
 */
final readonly class BucketInfo
{
    public function __construct(
        public string $name,
        public string $ownerId,
        public string $region = 'us-east-1',
        public \DateTimeImmutable $creationDate = new \DateTimeImmutable,
    ) {}
}
