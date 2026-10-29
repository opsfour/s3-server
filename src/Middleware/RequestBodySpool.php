<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Parallel\Worker\WorkerPool;
use Amp\Http\Server\Request;
use OpsFour\S3Server\Auth\EncryptedTempFrames;
use OpsFour\S3Server\Auth\EncryptedTempReadableStream;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\PostObjectSpoolReservation;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SlowDownException;
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
    private const int STALE_AFTER_SECONDS = 86_400;

    private const int SWEEP_INTERVAL_SECONDS = 300;

    private const int SWEEP_LIMIT = 1_000;

    private readonly string $tempDir;

    private int $lastSweepAt = 0;

    /** @var array<string, resource> Keeps active files fenced across worker processes. */
    private array $activeHandles = [];

    /** @var array<string,array{device:int,inode:int,owner:int}> */
    private array $fileIdentities = [];

    /** @var array<string, PostObjectSpoolReservation> */
    private array $reservations = [];

    /** @var array<string, string> Ephemeral keys never leave this API process. */
    private array $keys = [];

    /** @var array<string, int> */
    private array $plainSizes = [];

    /** @var array<string, int> */
    private array $frameIndexes = [];

    /** @var array<string, string> */
    private array $pendingPlaintext = [];

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
        $this->coordinator = $coordinator ?? new PostObjectSpoolCoordinator(
            $this->tempDir,
            $this->pool,
            budgetBytes: $this->budgetBytes,
        );
        $this->coordinator->bindBudget($this->budgetBytes);
    }

    /** @internal Closing the worker pool requires every request spool to drain. */
    public function assertIdle(): void
    {
        if ($this->activeHandles !== [] || $this->reservations !== []
            || $this->keys !== [] || $this->plainSizes !== [] || $this->frameIndexes !== []
            || $this->pendingPlaintext !== [] || $this->fileIdentities !== []) {
            throw new \RuntimeException('Request-body spool still has active files or keys.');
        }
    }

    public function create(string $prefix, ?int $knownPlaintextBytes = null): string
    {
        if (! in_array($prefix, ['s3-md5-', 's3-sha256-'], true)) {
            throw new \InvalidArgumentException('Unsupported request-body temporary file prefix.');
        }
        $knownEncodedBytes = $knownPlaintextBytes === null
            ? null
            : EncryptedTempFrames::worstCaseEncodedBytes($knownPlaintextBytes);
        $this->sweepStaleFilesIfDue();

        for ($attempt = 0; $attempt < 3; $attempt++) {
            $path = $this->tempDir . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16)) . '.tmp';
            $key = random_bytes(32);
            $created = $path;
            $reservation = null;
            $handle = null;

            try {
                $reservation = $this->coordinator->start($created);

                $createdStat = @lstat($created);
                $handle = @fopen($created, 'rb');
                if ($handle === false) {
                    $handle = null;
                    throw new \RuntimeException('Unable to lock request-body temporary file.');
                }
                $openedStat = @fstat($handle);
                if ($createdStat === false || $openedStat === false
                    || ($createdStat['mode'] & 0o170000) !== 0o100000
                    || ($createdStat['mode'] & 0o077) !== 0
                    || $openedStat['dev'] !== $createdStat['dev'] || $openedStat['ino'] !== $createdStat['ino']) {
                    if (is_resource($handle)) {
                        fclose($handle);
                    }
                    throw new \RuntimeException('Unable to lock request-body temporary file.');
                }
                if (! flock($handle, LOCK_SH | LOCK_NB)) {
                    fclose($handle);
                    $handle = null;
                    throw new \RuntimeException('Unable to lock request-body temporary file.');
                }
                if ($knownEncodedBytes !== null && $knownEncodedBytes > 0) {
                    $this->coordinator->reserve($reservation, $knownEncodedBytes, $this->budgetBytes);
                }
                $this->activeHandles[$created] = $handle;
                $this->fileIdentities[$created] = [
                    'device' => $openedStat['dev'],
                    'inode' => $openedStat['ino'],
                    'owner' => $openedStat['uid'],
                ];
                $this->reservations[$created] = $reservation;
                $this->keys[$created] = $key;
                $this->plainSizes[$created] = 0;
                $this->frameIndexes[$created] = 0;
                $this->pendingPlaintext[$created] = '';

                return $created;
            } catch (\Amp\CancelledException|SlowDownException $e) {
                $this->rollbackCreate($created, $handle, $reservation);
                throw $e;
            } catch (\Throwable $e) {
                $this->rollbackCreate($created, $handle, $reservation);
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
        if (strlen($data) > PHP_INT_MAX - $this->plainSizes[$path]) {
            throw new \OverflowException('Request-body temporary size overflowed.');
        }
        $nextPlainSize = $this->plainSizes[$path] + strlen($data);
        $requiredBytes = EncryptedTempFrames::worstCaseEncodedBytes($nextPlainSize);
        if ($requiredBytes > $reservation->bytes()) {
            $this->coordinator->reserve($reservation, $requiredBytes - $reservation->bytes(), $this->budgetBytes);
        }
        $this->plainSizes[$path] = $nextPlainSize;
        $pending = ($this->pendingPlaintext[$path] ?? '') . $data;
        while (strlen($pending) >= EncryptedTempFrames::MAX_PLAINTEXT_BYTES) {
            $this->appendFrame($path, $key, substr($pending, 0, EncryptedTempFrames::MAX_PLAINTEXT_BYTES));
            $pending = substr($pending, EncryptedTempFrames::MAX_PLAINTEXT_BYTES);
        }
        $this->pendingPlaintext[$path] = $pending;
    }

    public static function declaredPlaintextBytes(Request $request): ?int
    {
        $decoded = \OpsFour\S3Server\Auth\AwsChunkedContentLength::fromRequest($request);
        if ($decoded !== null) {
            return $decoded;
        }
        $value = $request->getHeader('content-length');
        if ($value === null || $value === '' || ! ctype_digit($value)) {
            return null;
        }
        $normalized = ltrim($value, '0');
        $maximum = (string) PHP_INT_MAX;
        if (strlen($normalized) > strlen($maximum)
            || (strlen($normalized) === strlen($maximum) && strcmp($normalized, $maximum) > 0)) {
            throw new \OverflowException('Request body size exceeds the local integer range.');
        }

        return (int) $value;
    }

    public function openReadable(string $path): EncryptedTempReadableStream
    {
        $key = $this->keys[$path] ?? null;
        if ($key === null) {
            throw new \RuntimeException('Request-body temporary file has no encryption key.');
        }
        $pending = $this->pendingPlaintext[$path] ?? '';
        if ($pending !== '') {
            $this->appendFrame($path, $key, $pending);
            $this->pendingPlaintext[$path] = '';
        }

        return new EncryptedTempReadableStream(
            new SpoolReadableStream(
                $this->pool,
                $path,
                ...($this->fileIdentities[$path]
                    ?? throw new \RuntimeException('Request-body temporary file has no inode identity.')),
            ),
            $key,
            $this->plainSizes[$path],
        );
    }

    public function delete(string $path): void
    {
        try {
            if (isset($this->activeHandles[$path])) {
                flock($this->activeHandles[$path], LOCK_UN);
                fclose($this->activeHandles[$path]);
                unset($this->activeHandles[$path]);
            }
            if (isset($this->reservations[$path])) {
                $this->coordinator->cleanup($this->reservations[$path]);
                unset($this->reservations[$path]);
            }
        } finally {
            unset($this->keys[$path], $this->plainSizes[$path], $this->frameIndexes[$path], $this->pendingPlaintext[$path], $this->fileIdentities[$path]);
        }
    }

    private function appendFrame(string $path, string $key, string $plaintext): void
    {
        $index = $this->frameIndexes[$path];
        $frame = EncryptedTempFrames::encode($key, $index, $plaintext);
        $identity = $this->fileIdentities[$path]
            ?? throw new \RuntimeException('Request-body temporary file has no inode identity.');
        $this->pool->submit(new TemporaryFileTask(
            'append',
            $path,
            data: $frame,
            expectedDevice: $identity['device'],
            expectedInode: $identity['inode'],
            expectedOwner: $identity['owner'],
        ))->await();
        $this->frameIndexes[$path] = $index + 1;
    }

    /** @param resource|null $handle */
    private function rollbackCreate(string $path, mixed $handle, ?PostObjectSpoolReservation $reservation): void
    {
        if (is_resource($handle)) {
            @flock($handle, LOCK_UN);
            @fclose($handle);
        }
        unset(
            $this->activeHandles[$path],
            $this->fileIdentities[$path],
            $this->reservations[$path],
            $this->keys[$path],
            $this->plainSizes[$path],
            $this->frameIndexes[$path],
            $this->pendingPlaintext[$path],
        );
        if ($reservation !== null && $reservation->isOpen()) {
            try {
                $this->coordinator->cleanup($reservation);
            } catch (\Throwable) {
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
                data: 's3-md5-,s3-sha256-,s3-post-',
                offset: $now - self::STALE_AFTER_SECONDS,
                length: self::SWEEP_LIMIT,
            ))->await();
        } catch (\Amp\CancelledException $e) {
            throw $e;
        } catch (\Throwable) {
            // Request processing can continue; failed cleanup is retried by a
            // later sweep and individual deletes still have their own retries.
        }
    }
}
