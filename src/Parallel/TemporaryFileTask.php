<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;

/**
 * Performs one bounded temporary-file operation without retaining a worker.
 *
 * @implements Task<string|null, never, never>
 */
final class TemporaryFileTask implements Task
{
    public function __construct(
        private readonly string $operation,
        private readonly string $path,
        private readonly string $data = '',
        private readonly int $offset = 0,
        private readonly int $length = 0,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): ?string
    {
        $cancellation->throwIfRequested();

        return match ($this->operation) {
            'create' => $this->create(),
            'append' => $this->append($cancellation),
            'read' => $this->read($cancellation),
            'delete' => $this->delete(),
            'sweep' => $this->sweep($cancellation),
            default => throw new \LogicException("Unknown temporary-file operation: {$this->operation}."),
        };
    }

    private function create(): string
    {
        $directory = dirname($this->path);
        $path = @tempnam($directory, basename($this->path));
        if ($path === false) {
            throw new \RuntimeException("Unable to create temporary file: {$this->path}.");
        }

        // tempnam creates the file with mode 0600 regardless of the process
        // umask. Refuse PHP's fallback to the system temp directory.
        clearstatcache(true, $path);
        $permissions = @fileperms($path);
        if (realpath(dirname($path)) !== realpath($directory)
            || $permissions === false || ($permissions & 0077) !== 0) {
            @unlink($path);
            throw new \RuntimeException("Unable to create private temporary file: {$this->path}.");
        }

        return $directory . DIRECTORY_SEPARATOR . basename($path);
    }

    private function append(Cancellation $cancellation): null
    {
        // An upload must never recreate a missing spool with umask-dependent
        // permissions after a cleanup or external deletion.
        $handle = @fopen($this->path, 'r+b');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open temporary file for append: {$this->path}.");
        }

        try {
            if (fseek($handle, 0, SEEK_END) !== 0) {
                throw new \RuntimeException("Unable to seek in temporary file: {$this->path}.");
            }
            $written = 0;
            $bytes = strlen($this->data);
            while ($written < $bytes) {
                $cancellation->throwIfRequested();
                $result = fwrite($handle, substr($this->data, $written));
                if ($result === false || $result === 0) {
                    throw new \RuntimeException("Unable to append to temporary file: {$this->path}.");
                }
                $written += $result;
            }
        } finally {
            fclose($handle);
        }

        return null;
    }

    private function read(Cancellation $cancellation): ?string
    {
        if ($this->offset < 0 || $this->length <= 0) {
            throw new \InvalidArgumentException('Temporary-file read offset and length are invalid.');
        }

        $handle = @fopen($this->path, 'rb');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open temporary file for reading: {$this->path}.");
        }

        try {
            if ($this->offset > 0 && fseek($handle, $this->offset) !== 0) {
                throw new \RuntimeException("Unable to seek in temporary file: {$this->path}.");
            }

            $cancellation->throwIfRequested();
            $data = fread($handle, $this->length);
            if ($data === false) {
                throw new \RuntimeException("Unable to read temporary file: {$this->path}.");
            }

            return $data === '' && feof($handle) ? null : $data;
        } finally {
            fclose($handle);
        }
    }

    private function delete(): null
    {
        if (is_file($this->path) && ! @unlink($this->path)) {
            throw new \RuntimeException("Unable to delete temporary file: {$this->path}.");
        }

        return null;
    }

    private function sweep(Cancellation $cancellation): string
    {
        if ($this->offset <= 0 || $this->length <= 0) {
            throw new \InvalidArgumentException('Temporary-file sweep cutoff and limit are invalid.');
        }

        $prefixes = array_values(array_filter(explode(',', $this->data)));
        $deleted = 0;
        $activePostPaths = $this->activePostPaths();

        foreach (new \FilesystemIterator($this->path, \FilesystemIterator::SKIP_DOTS) as $file) {
            $cancellation->throwIfRequested();
            if ($deleted >= $this->length) {
                break;
            }
            if (
                ! $file instanceof \SplFileInfo
                || ! $file->isFile()
                || $file->isLink()
            ) {
                continue;
            }

            $name = $file->getFilename();
            $matchesPrefix = false;
            foreach ($prefixes as $prefix) {
                if (str_starts_with($name, $prefix)) {
                    $matchesPrefix = true;
                    break;
                }
            }

            if (!$matchesPrefix || $file->getMTime() >= $this->offset) {
                continue;
            }
            if (str_starts_with($name, 's3-post-')) {
                if ($activePostPaths !== null && !isset($activePostPaths[$file->getPathname()])
                    && @unlink($file->getPathname())) {
                    $deleted++;
                }

                continue;
            }

            $handle = @fopen($file->getPathname(), 'rb');
            if ($handle === false) {
                continue;
            }
            try {
                if (flock($handle, LOCK_EX | LOCK_NB) && @unlink($file->getPathname())) {
                    $deleted++;
                }
            } finally {
                fclose($handle);
            }
        }

        return (string) $deleted;
    }

    /** @return array<string, true>|null Null means a lease could not be read safely. */
    private function activePostPaths(): ?array
    {
        $leaseDir = $this->path . DIRECTORY_SEPARATOR . '.opsfour-s3-post-budget';
        if (!is_dir($leaseDir)) {
            return [];
        }

        $paths = glob($leaseDir . DIRECTORY_SEPARATOR . 'lease-*');
        if ($paths === false) {
            return null;
        }

        $active = [];
        foreach ($paths as $path) {
            $handle = @fopen($path, 'r');
            if ($handle === false) {
                return null;
            }
            try {
                if (flock($handle, LOCK_EX | LOCK_NB)) {
                    continue;
                }
                $statePath = $leaseDir . DIRECTORY_SEPARATOR . 'state-' . substr(basename($path), 6);
                $contents = @file_get_contents($statePath);
                $lease = $contents === false ? null : json_decode($contents, true);
                if (!is_array($lease) || !isset($lease['tempPath']) || !is_string($lease['tempPath'])) {
                    return null;
                }
                $active[$lease['tempPath']] = true;
            } finally {
                fclose($handle);
            }
        }

        return $active;
    }
}
