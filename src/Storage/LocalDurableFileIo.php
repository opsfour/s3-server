<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Blocking syscall boundary for durable local publication and deletion. */
interface LocalDurableFileIo
{
    /** @param resource $file */
    public function syncFile($file): bool;

    public function syncDirectory(string $path): bool;

    public function link(string $source, string $target): bool;

    public function unlink(string $path): bool;
}
