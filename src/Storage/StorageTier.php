<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

final readonly class StorageTier
{
    public function __construct(
        public string $name,
        public StorageBackend $backend,
        public bool $restoreRequired = false,
        public bool $defaultWriteTier = false,
    ) {
        if ($this->name === '') {
            throw new \InvalidArgumentException('Storage tier name cannot be empty.');
        }
    }
}
