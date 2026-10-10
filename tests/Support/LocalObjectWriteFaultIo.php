<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;

/** Serializable worker fault boundary for Linux local publication tests. */
final class LocalObjectWriteFaultIo implements LocalObjectWriteIo
{
    public bool $failFileSync = false;

    public bool $failDirectorySync = false;

    public bool $reportLinkFailureAfterSuccess = false;

    public ?string $linkEnteredPath = null;

    public ?string $releaseLinkPath = null;

    public ?string $linkEnteredDirectory = null;

    public ?string $linkReleaseDirectory = null;

    public function syncFile($file): bool
    {
        return ! $this->failFileSync && (new NativeLocalObjectWriteIo())->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return ! $this->failDirectorySync && (new NativeLocalObjectWriteIo())->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        if ($this->linkEnteredDirectory !== null && $this->linkReleaseDirectory !== null) {
            $name = basename($target);
            file_put_contents($this->linkEnteredDirectory . '/' . $name, 'entered');
            $deadline = microtime(true) + 10.0;
            while (! file_exists($this->linkReleaseDirectory . '/' . $name) && microtime(true) < $deadline) {
                usleep(10_000);
            }
        }
        if ($this->linkEnteredPath !== null && $this->releaseLinkPath !== null) {
            file_put_contents($this->linkEnteredPath, 'entered');
            $deadline = microtime(true) + 10.0;
            while (! file_exists($this->releaseLinkPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
        }
        $linked = (new NativeLocalObjectWriteIo())->link($source, $target);
        return $linked && ! $this->reportLinkFailureAfterSuccess;
    }

    public function rename(string $source, string $target): bool
    {
        return (new NativeLocalObjectWriteIo())->rename($source, $target);
    }
}
