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

    private function create(): null
    {
        $handle = @fopen($this->path, 'x+b');
        if ($handle === false) {
            throw new \RuntimeException("Unable to create temporary file: {$this->path}.");
        }

        fclose($handle);

        return null;
    }

    private function append(Cancellation $cancellation): null
    {
        $handle = @fopen($this->path, 'ab');
        if ($handle === false) {
            throw new \RuntimeException("Unable to open temporary file for append: {$this->path}.");
        }

        try {
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

        foreach (new \FilesystemIterator($this->path, \FilesystemIterator::SKIP_DOTS) as $file) {
            $cancellation->throwIfRequested();
            if (
                ! $file instanceof \SplFileInfo
                || $deleted >= $this->length
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

            if ($matchesPrefix && $file->getMTime() < $this->offset && @unlink($file->getPathname())) {
                $deleted++;
            }
        }

        return (string) $deleted;
    }
}
