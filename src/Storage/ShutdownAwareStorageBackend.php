<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

interface ShutdownAwareStorageBackend
{
    public function shutdown(): void;
}
