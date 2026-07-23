<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Parallel\TemporaryFileTask;

/**
 * Stores request bodies through stateless worker tasks.
 *
 * Keeping file handles inside request fibers can exhaust amphp/file's worker
 * pool when concurrency exceeds the worker count. Each operation here releases
 * its worker before returning.
 */
final class RequestBodySpool
{
    private readonly string $tempDir;

    public function __construct(
        private readonly WorkerPool $pool,
        ?string $tempDir = null,
    ) {
        $this->tempDir = rtrim($tempDir ?? sys_get_temp_dir(), DIRECTORY_SEPARATOR);
        if (! is_dir($this->tempDir) || ! is_writable($this->tempDir)) {
            throw new InternalErrorException('Request-body temporary directory is not writable.');
        }
    }

    public function create(string $prefix): string
    {
        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = $this->tempDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16)) . '.tmp';

            try {
                $this->pool->submit(new TemporaryFileTask('create', $path))->await();

                return $path;
            } catch (\Throwable $e) {
                if ($attempt === 2) {
                    throw new InternalErrorException('Unable to create request-body temporary file.', $e);
                }
            }
        }

        throw new InternalErrorException('Unable to create request-body temporary file.');
    }

    public function append(string $path, string $data): void
    {
        if ($data === '') {
            return;
        }

        $this->pool->submit(new TemporaryFileTask('append', $path, data: $data))->await();
    }

    public function openReadable(string $path): SpoolReadableStream
    {
        return new SpoolReadableStream($this->pool, $path);
    }

    public function delete(string $path): void
    {
        $this->pool->submit(new TemporaryFileTask('delete', $path))->await();
    }
}
