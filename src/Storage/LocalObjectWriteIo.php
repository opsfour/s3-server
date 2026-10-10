<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Small syscall boundary for the isolated local create-only write protocol. */
interface LocalObjectWriteIo
{
    /** @param resource $file */
    public function syncFile($file): bool;

    public function syncDirectory(string $path): bool;

    public function link(string $source, string $target): bool;

    public function rename(string $source, string $target): bool;
}
