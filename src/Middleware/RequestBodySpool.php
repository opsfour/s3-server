<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Auth\EncryptedTempFrames;
use OpsFour\S3Server\Auth\EncryptedTempReadableStream;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\PostObjectSpoolReservation;
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

    /** @var array<string, resource> Keeps active files fenced across worker processes. */
    private array $activeHandles = [];

    /** @var array<string, PostObjectSpoolReservation> */
    private array $reservations = [];

    /** @var array<string, string> Ephemeral keys never leave this API process. */
    private array $keys = [];

    /** @var array<string, int> */
    private array $plainSizes = [];

    /** @var array<string, int> */
    private array $frameIndexes = [];

    private readonly PostObjectSpoolCoordinator $coordinator;

    public function __construct(
        private readonly WorkerPool $pool,
        ?string $tempDir = null,
        private readonly int $budgetBytes = 10_737_418_240,
        ?PostObjectSpoolCoordinator $coordinator = null,
    ) {
        if ($budgetBytes < 1) {
            throw new \InvalidArgumentException('Request-body temporary budget must be positive.');
        }
        $directory = realpath($tempDir ?? sys_get_temp_dir());
        $this->tempDir = $directory === false
            ? ''
            : (rtrim($directory, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR);
        if (! is_dir($this->tempDir) || ! is_writable($this->tempDir)) {
            throw new InternalErrorException('Request-body temporary directory is not writable.');
        }
        $permissions = @fileperms($this->tempDir);
        if ($permissions === false || (($permissions & 0o022) !== 0 && ($permissions & 0o1000) === 0)) {
            throw new InternalErrorException('Request-body temporary directory must be private or sticky.');
        }
        $this->coordinator = $coordinator ?? new PostObjectSpoolCoordinator($this->tempDir);
    }

    /** @internal Prove checksum spooling uses the exact qualified ingress resources. */
    public function assertQualifiedBinding(
        string $tempDir,
        int $budgetBytes,
        PostObjectSpoolCoordinator $coordinator,
        WorkerPool $pool,
    ): void {
        if ($this->tempDir !== $tempDir || $this->budgetBytes !== $budgetBytes
            || $this->coordinator !== $coordinator || $this->pool !== $pool) {
            throw new \RuntimeException('EFS request-body spool differs from qualified ingress resources.');
        }
    }

    /** @internal Closing the worker pool requires every request spool to drain. */
    public function assertIdle(): void
    {
        if ($this->activeHandles !== [] || $this->reservations !== []
            || $this->keys !== [] || $this->plainSizes !== [] || $this->frameIndexes !== []) {
            throw new \RuntimeException('EFS request-body spool still has active files or keys.');
        }
    }

    public function create(string $prefix): string
    {
        if (! in_array($prefix, ['s3-md5-', 's3-sha256-'], true)) {
            throw new \InvalidArgumentException('Unsupported request-body temporary file prefix.');
        }
        $this->sweepStaleFilesIfDue();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = $this->tempDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16)) . '.tmp';
            $key = random_bytes(32);

            try {
                $created = $this->pool->submit(new TemporaryFileTask('create', $path))->await();
                if (! is_string($created)) {
                    throw new \RuntimeException('Temporary-file worker did not return a path.');
                }

                $handle = @fopen($created, 'rb');
                if ($handle === false) {
                    $this->delete($created);
                    throw new \RuntimeException('Unable to lock request-body temporary file.');
                }
                if (! flock($handle, LOCK_SH | LOCK_NB)) {
                    fclose($handle);
                    $this->delete($created);
                    throw new \RuntimeException('Unable to lock request-body temporary file.');
                }
                try {
                    $reservation = $this->coordinator->start($created);
                } catch (\Throwable $e) {
                    flock($handle, LOCK_UN);
                    fclose($handle);
                    $this->delete($created);
                    throw $e;
                }
                $this->activeHandles[$created] = $handle;
                $this->reservations[$created] = $reservation;
                $this->keys[$created] = $key;
                $this->plainSizes[$created] = 0;
                $this->frameIndexes[$created] = 0;

                return $created;
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

        $reservation = $this->reservations[$path] ?? null;
        if ($reservation === null) {
            throw new \RuntimeException('Request-body temporary file has no active reservation.');
        }
        $key = $this->keys[$path] ?? null;
        if ($key === null) {
            throw new \RuntimeException('Request-body temporary file has no encryption key.');
        }
        for ($offset = 0, $length = strlen($data); $offset < $length; $offset += EncryptedTempFrames::MAX_PLAINTEXT_BYTES) {
            $plaintext = substr($data, $offset, EncryptedTempFrames::MAX_PLAINTEXT_BYTES);
            $index = $this->frameIndexes[$path];
            $frame = EncryptedTempFrames::encode($key, $index, $plaintext);
            $this->coordinator->reserve($reservation, strlen($frame), $this->budgetBytes);
            $this->pool->submit(new TemporaryFileTask('append', $path, data: $frame))->await();
            $this->frameIndexes[$path] = $index + 1;
            $this->plainSizes[$path] += strlen($plaintext);
        }
    }

    public function openReadable(string $path): EncryptedTempReadableStream
    {
        $key = $this->keys[$path] ?? null;
        if ($key === null) {
            throw new \RuntimeException('Request-body temporary file has no encryption key.');
        }

        return new EncryptedTempReadableStream(
            new SpoolReadableStream($this->pool, $path),
            $key,
            $this->plainSizes[$path],
        );
    }

    public function delete(string $path): void
    {
        $deleted = false;
        try {
            for ($attempt = 1; $attempt <= self::DELETE_ATTEMPTS; $attempt++) {
                try {
                    $this->pool->submit(new TemporaryFileTask('delete', $path))->await();
                    $deleted = true;

                    return;
                } catch (\Throwable $e) {
                    if ($attempt === self::DELETE_ATTEMPTS) {
                        throw $e;
                    }
                }
            }
        } finally {
            if (isset($this->activeHandles[$path])) {
                flock($this->activeHandles[$path], LOCK_UN);
                fclose($this->activeHandles[$path]);
                unset($this->activeHandles[$path]);
            }
            if (isset($this->reservations[$path])) {
                $this->coordinator->release($this->reservations[$path], $deleted);
                unset($this->reservations[$path]);
            }
            unset($this->keys[$path], $this->plainSizes[$path], $this->frameIndexes[$path]);
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
                data: 's3-md5-,s3-sha256-,s3-post-',
                offset: $now - self::STALE_AFTER_SECONDS,
                length: self::SWEEP_LIMIT,
            ))->await();
        } catch (\Throwable) {
            // Request processing can continue; failed cleanup is retried by a
            // later sweep and individual deletes still have their own retries.
        }
    }
}
