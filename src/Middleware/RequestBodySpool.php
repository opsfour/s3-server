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
    private const int DELETE_ATTEMPTS = 3;

    private const int STALE_AFTER_SECONDS = 86_400;

    private const int SWEEP_INTERVAL_SECONDS = 300;

    private const int SWEEP_LIMIT = 1_000;

    private readonly string $tempDir;

    private int $lastSweepAt = 0;

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
        $this->sweepStaleFilesIfDue();

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
        for ($attempt = 1; $attempt <= self::DELETE_ATTEMPTS; $attempt++) {
            try {
                $this->pool->submit(new TemporaryFileTask('delete', $path))->await();

                return;
            } catch (\Throwable $e) {
                if ($attempt === self::DELETE_ATTEMPTS) {
                    throw $e;
                }
            }
        }
    }

    private function sweepStaleFilesIfDue(): void
    {
        $now = time();
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastSweepAt = $now;

        try {
            $this->pool->submit(new TemporaryFileTask(
                'sweep',
                $this->tempDir,
                data: 's3-md5-,s3-sha256-',
                offset: $now - self::STALE_AFTER_SECONDS,
                length: self::SWEEP_LIMIT,
            ))->await();
        } catch (\Throwable) {
            // Request processing can continue; failed cleanup is retried by a
            // later sweep and individual deletes still have their own retries.
        }
    }
}
