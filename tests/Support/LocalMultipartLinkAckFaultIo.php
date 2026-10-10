<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;

/** Serializable worker fault: the create-only link succeeds but its ACK is lost. */
final class LocalMultipartLinkAckFaultIo implements LocalObjectWriteIo
{
    private readonly NativeLocalObjectWriteIo $inner;

    public function __construct()
    {
        $this->inner = new NativeLocalObjectWriteIo();
    }

    public function syncFile($file): bool
    {
        return $this->inner->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->inner->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        $this->inner->link($source, $target);
        return false;
    }

    public function rename(string $source, string $target): bool
    {
        return $this->inner->rename($source, $target);
    }
}
