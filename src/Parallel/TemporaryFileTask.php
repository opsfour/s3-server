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
        private readonly ?int $expectedDevice = null,
        private readonly ?int $expectedInode = null,
        private readonly ?int $expectedOwner = null,
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
        $handle = $this->openExpectedFile('r+b', 'append');

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

        $handle = $this->openExpectedFile('rb', 'reading');

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

    /** @return resource */
    private function openExpectedFile(string $mode, string $operation)
    {
        if ($this->expectedDevice === null || $this->expectedInode === null || $this->expectedOwner === null) {
            throw new \InvalidArgumentException("Temporary-file {$operation} requires expected inode identity.");
        }
        clearstatcache(true, $this->path);
        $before = @lstat($this->path);
        if ($before === false || ($before['mode'] & 0o170000) !== 0o100000
            || ($before['mode'] & 0o077) !== 0
            || $before['dev'] !== $this->expectedDevice
            || $before['ino'] !== $this->expectedInode
            || $before['uid'] !== $this->expectedOwner) {
            throw new \RuntimeException("Temporary file identity changed before {$operation}: {$this->path}.");
        }
        $handle = @fopen($this->path, $mode);
        if ($handle === false) {
            throw new \RuntimeException("Unable to open temporary file for {$operation}: {$this->path}.");
        }
        $opened = @fstat($handle);
        if ($opened === false || ($opened['mode'] & 0o170000) !== 0o100000
            || ($opened['mode'] & 0o077) !== 0
            || $opened['dev'] !== $this->expectedDevice
            || $opened['ino'] !== $this->expectedInode
            || $opened['uid'] !== $this->expectedOwner
            || ! @flock($handle, LOCK_SH | LOCK_NB)) {
            @fclose($handle);
            throw new \RuntimeException("Unable to verify temporary file for {$operation}: {$this->path}.");
        }

        return $handle;
    }

    private function sweep(Cancellation $cancellation): string
    {
        if ($this->offset <= 0 || $this->length <= 0) {
            throw new \InvalidArgumentException('Temporary-file sweep cutoff and limit are invalid.');
        }

        $prefixes = array_values(array_filter(explode(',', $this->data)));
        $deleted = 0;

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
            if (str_starts_with($name, 's3-post-')
                || str_starts_with($name, 's3-md5-')
                || str_starts_with($name, 's3-sha256-')) {
                // Unified coordinator recovery requires reservation identity;
                // an old-looking unleased path is never sufficient evidence.
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

}
