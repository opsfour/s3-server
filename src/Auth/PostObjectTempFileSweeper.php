<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Parallel\TemporaryFileTask;

use function Amp\Parallel\Worker\workerPool;

/** Reclaims abandoned browser-upload files without scanning temp storage on the event loop. */
final class PostObjectTempFileSweeper
{
    private const int STALE_AFTER_SECONDS = 86_400;

    private const int SWEEP_INTERVAL_SECONDS = 300;

    private const int SWEEP_LIMIT = 1_000;

    private int $lastSweepAt = 0;

    private readonly string $tempDir;

    public function __construct(private readonly ?WorkerPool $pool = null, ?string $tempDir = null)
    {
        $directory = $tempDir ?? sys_get_temp_dir();
        $resolved = realpath($directory);
        $this->tempDir = rtrim($resolved === false ? $directory : $resolved, DIRECTORY_SEPARATOR) ?: DIRECTORY_SEPARATOR;
    }

    /** @internal Prove the inert EFS candidate uses its owned worker pool. */
    public function assertQualifiedBinding(string $tempDir, WorkerPool $pool): void
    {
        if ($this->tempDir !== $tempDir || $this->pool !== $pool) {
            throw new \RuntimeException('EFS browser POST sweeper differs from qualified ingress scratch.');
        }
    }

    public function sweepIfDue(): void
    {
        $now = time();
        if ($now - $this->lastSweepAt < self::SWEEP_INTERVAL_SECONDS) {
            return;
        }
        $this->lastSweepAt = $now;

        try {
            ($this->pool ?? workerPool())->submit(new TemporaryFileTask(
                'sweep',
                $this->tempDir,
                data: 's3-post-',
                offset: $now - self::STALE_AFTER_SECONDS,
                length: self::SWEEP_LIMIT,
            ))->await();
        } catch (\Throwable) {
            // Normal/error request cleanup still runs; retry stale recovery
            // on the next interval without changing the upload response.
        }
    }
}
