<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Closure;
use OpsFour\S3Server\Runtime\EfsHostEvidenceReader;
use OpsFour\S3Server\Runtime\ProductionEfsWorkerStorageEvidence;

/** Linux/POSIX file operations; actual EFS semantics require a two-host gate. */
final class NativeLocalObjectWriteIo implements EfsAwareLocalObjectWriteIo
{
    public function syncFile($file): bool
    {
        return @fsync($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->syncDirectoryWithCallback($path);
    }

    /** @param (Closure(): void)|null $afterSyscall */
    public function syncDirectoryWithCallback(string $path, ?Closure $afterSyscall = null): bool
    {
        $directory = @fopen($path, 'rb');
        $afterSyscall?->__invoke();
        if ($directory === false) {
            return false;
        }
        try {
            $synchronized = @fsync($directory);
            $afterSyscall?->__invoke();
            $closed = @fclose($directory);
            $afterSyscall?->__invoke();

            return $closed && $synchronized;
        } finally {
            if (is_resource($directory)) {
                // Descriptor-only best-effort cleanup after evidence loss.
                @fclose($directory);
            }
        }
    }

    public function syncDirectoryWithEvidence(
        string $path,
        EfsHostEvidenceReader|ProductionEfsWorkerStorageEvidence $evidence,
    ): bool {
        $evidence->assertFresh();

        return $this->syncDirectoryWithCallback($path, $evidence->assertFresh(...));
    }

    public function link(string $source, string $target): bool
    {
        return @link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return @rename($source, $target);
    }
}
