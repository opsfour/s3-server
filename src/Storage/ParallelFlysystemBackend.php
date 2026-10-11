<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\DeferredFuture;
use Amp\File;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerPool;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Multipart\MultipartPartSize;
use OpsFour\S3Server\Parallel\FlysystemStorageTask;

use function Amp\async;

/**
 * Flysystem backend that executes every synchronous adapter call in a bounded
 * worker process pool. HTTP request fibers only perform asynchronous local
 * staging I/O and wait on worker futures.
 */
final class ParallelFlysystemBackend implements RecoverableMultipartAssemblyBackend, RecoverableMultipartPartBackend, RecoverableObjectWriteBackend, ShutdownAwareStorageBackend, BoundedCopyStorageBackend
{
    private readonly WorkerPool $pool;

    private readonly WorkerPool $readPool;

    private readonly File\Filesystem $localFilesystem;

    private readonly StorageTempBudgetCoordinator $tempBudget;

    private readonly int $readAdmissionLimit;

    private readonly int $writeAdmissionLimit;

    /** @var \WeakMap<ParallelFlysystemReadableStream, true> */
    private readonly \WeakMap $activeReadStreams;

    private int $activeReadSlots = 0;

    private int $activeWriteSlots = 0;

    /** @var array<int, DeferredFuture<void>> */
    private array $readWaiters = [];

    /** @var array<int, DeferredFuture<void>> */
    private array $writeWaiters = [];

    private int $nextReadWaiterId = 0;

    private int $nextWriteWaiterId = 0;

    public function __construct(
        private readonly FlysystemFilesystemFactory $factory,
        private readonly string $tempDir,
        int $workerLimit = 4,
        private readonly ?MetricsCollector $metrics = null,
        ?WorkerPool $pool = null,
        private readonly string $poolName = 'flysystem',
        private readonly int $tempBudgetBytes = 10_737_418_240,
        ?WorkerPool $readPool = null,
        private readonly int $readIdleTimeoutSeconds = 120,
        ?File\Filesystem $localFilesystem = null,
        private readonly int $firstByteTimeoutSeconds = 20,
        private readonly int $writeAdmissionTimeoutSeconds = 20,
    ) {
        if ($workerLimit < 1) {
            throw new \InvalidArgumentException('Flysystem worker limit must be at least 1.');
        }
        if (! is_dir($tempDir) || ! is_writable($tempDir)) {
            throw new \InvalidArgumentException("Flysystem temp directory must exist and be writable: {$tempDir}");
        }
        if ($readIdleTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Flysystem read idle timeout must be at least 1 second.');
        }
        if ($firstByteTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Flysystem first-byte timeout must be at least 1 second.');
        }
        if ($writeAdmissionTimeoutSeconds < 1) {
            throw new \InvalidArgumentException('Flysystem write admission timeout must be at least 1 second.');
        }

        $this->pool = $pool ?? new ContextWorkerPool($workerLimit);
        $this->readPool = $readPool ?? new ContextWorkerPool($workerLimit);
        $this->readAdmissionLimit = $workerLimit;
        $this->writeAdmissionLimit = $workerLimit;
        $this->localFilesystem = $localFilesystem ?? File\filesystem();
        $this->tempBudget = new StorageTempBudgetCoordinator($tempDir, $tempBudgetBytes);
        $this->activeReadStreams = new \WeakMap();
        $this->metrics?->registerWorkerPool($this->poolName, $workerLimit);
        $this->metrics?->registerWorkerPool($this->poolName . '-reads', $workerLimit);
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        StoragePath::assertBucketPathSegment($bucket);

        [$inputPath, $reservation] = $this->spool($body, 's3fw-put-');

        $cancellation = null;
        try {
            return $this->writeResult('putObject', [$bucket, $key, $inputPath]);
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            $this->releaseSpooledInput($inputPath, $reservation, $cancellation);
        }
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        FlysystemPathScope::assertStoredPath($storagePath);

        $startedAt = hrtime(true);
        $admitted = false;
        $admittedAt = null;
        $cancellation = new DeferredCancellation();
        $deadline = new TimeoutCancellation($this->firstByteTimeoutSeconds);
        $pending = async(function () use ($storagePath, $offset, $length, $cancellation, $deadline, $startedAt, &$admitted, &$admittedAt): ParallelFlysystemReadableStream {
            try {
                $this->acquireReadSlot($deadline);
                $this->metrics?->recordFlysystemReadStage('admission', 'ready', hrtime(true) - $startedAt);
            } catch (CancelledException $error) {
                $this->metrics?->recordFlysystemReadStage('admission', $deadline->isRequested() ? 'timeout' : 'error', hrtime(true) - $startedAt);
                throw $error;
            } catch (SlowDownException $error) {
                $this->metrics?->recordFlysystemReadStage('admission', 'rejected', hrtime(true) - $startedAt);
                throw $error;
            } catch (\Throwable $error) {
                $this->metrics?->recordFlysystemReadStage('admission', 'error', hrtime(true) - $startedAt);
                throw $error;
            }
            $admitted = true;
            $admittedAt = hrtime(true);
            try {
                $execution = $this->readPool->submit(new FlysystemStorageTask(
                    $this->factory,
                    $this->tempDir,
                    'streamObject',
                    [
                        $storagePath,
                        $offset,
                        $length,
                        $this->readIdleTimeoutSeconds,
                        $this->factory instanceof AwsS3FlysystemFilesystemFactory
                            ? $this->factory->readTimeoutSeconds()
                            : $this->readIdleTimeoutSeconds,
                    ],
                    tempBudgetBytes: $this->tempBudgetBytes,
                ), $cancellation->getCancellation());
            } catch (CancelledException $error) {
                $this->releaseReadSlot();
                throw $error;
            } catch (\Throwable $error) {
                $this->releaseReadSlot();
                $this->metrics?->recordWorkerPoolTask($this->poolName . '-reads', 'streamObject', false, $error::class);
                throw new InternalErrorException('Flysystem read worker could not start.', $error);
            }

            $execution->getFuture()->finally($this->releaseReadSlot(...))->ignore();

            $stream = new ParallelFlysystemReadableStream(
                $execution,
                $cancellation,
                $this->metrics,
                $this->poolName . '-reads',
            );
            $this->activeReadStreams[$stream] = true;
            $reference = \WeakReference::create($stream);
            $stream->onClose(function () use ($reference): void {
                $stream = $reference->get();
                if ($stream !== null) {
                    unset($this->activeReadStreams[$stream]);
                }
            });
            $stream->prime($cancellation->getCancellation());

            return $stream;
        });
        try {
            $stream = $pending->await($deadline);
            $this->metrics?->recordFlysystemReadFirstByte('ready', hrtime(true) - $startedAt);
            $this->metrics?->recordFlysystemReadStage('worker', 'ready', hrtime(true) - ($admittedAt ?? $startedAt));

            return $stream;
        } catch (CancelledException $error) {
            if (! $deadline->isRequested()) {
                $this->metrics?->recordFlysystemReadFirstByte('error', hrtime(true) - $startedAt);
                throw $error;
            }
            $this->metrics?->recordFlysystemReadFirstByte($admitted ? 'first_byte_timeout' : 'admission_timeout', hrtime(true) - $startedAt);
            if ($admittedAt !== null) {
                $this->metrics?->recordFlysystemReadStage('worker', 'timeout', hrtime(true) - $admittedAt);
            }
            $cancellation->cancel();
            // A pool submission may finish just as its caller times out. Close
            // a late stream so it cannot retain a worker or unobserved body.
            $pending->map(static function (ParallelFlysystemReadableStream $lateStream): void {
                $lateStream->close();
            })->ignore();

            throw new SlowDownException('Storage read timed out before the first byte.');
        } catch (\Throwable $error) {
            $result = ! $admitted && $error instanceof SlowDownException ? 'admission_rejected' : 'error';
            $this->metrics?->recordFlysystemReadFirstByte($result, hrtime(true) - $startedAt);
            if ($admittedAt !== null) {
                $this->metrics?->recordFlysystemReadStage('worker', 'error', hrtime(true) - $admittedAt);
            }
            throw $error;
        }
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        FlysystemPathScope::assertDeletePath($storagePath, $bucket);

        $this->submit('deleteObject', [$storagePath, $bucket]);
    }

    public function createBucket(string $bucket): void
    {
        StoragePath::assertBucketPathSegment($bucket);

        $this->submit('createBucket', [$bucket]);
    }

    public function deleteBucket(string $bucket): void
    {
        throw new \LogicException('Name-only Flysystem bucket deletion is unsafe after bucket-name reuse.');
    }

    public function bucketExists(string $bucket): bool
    {
        StoragePath::assertBucketPathSegment($bucket);

        $result = $this->submit('bucketExists', [$bucket]);
        if (! is_bool($result)) {
            throw new InternalErrorException('Flysystem worker returned an invalid bucket-exists result.');
        }

        return $result;
    }

    public function putPart(
        string $bucket,
        string $key,
        string $uploadId,
        int $partNumber,
        ReadableStream $data,
    ): StorageWriteResult {
        [$inputPath, $reservation] = $this->spool($data, 's3fw-part-', MultipartPartSize::MAX_BYTES);

        $cancellation = null;
        try {
            return $this->writeResult('putPart', [$bucket, $key, $uploadId, $partNumber, $inputPath]);
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            $this->releaseSpooledInput($inputPath, $reservation, $cancellation);
        }
    }

    public function planMultipartPartPath(string $bucket, string $bucketGeneration, string $uploadId, int $partNumber, string $attemptId): string
    {
        return StoragePath::forPlannedPart('', $bucket, $bucketGeneration, $uploadId, $partNumber, $attemptId);
    }

    public function putPartAtPath(string $bucket, string $key, string $uploadId, int $partNumber, ReadableStream $data, string $path): StorageWriteResult
    {
        StoragePath::assertPlannedPartPath('', $path, $uploadId, $partNumber);
        [$inputPath, $reservation] = $this->spool($data, 's3fw-part-', MultipartPartSize::MAX_BYTES);

        $cancellation = null;
        try {
            return $this->writeResult('putPartAtPath', [$bucket, $key, $uploadId, $partNumber, $inputPath, $path]);
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            $this->releaseSpooledInput($inputPath, $reservation, $cancellation);
        }
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        StoragePath::assertBucketPathSegment($bucket);

        return $this->writeResult('assembleMultipartUpload', [$bucket, $key, $uploadId, $parts]);
    }

    public function planMultipartCompletionPaths(string $bucket, string $key, string $bucketGeneration, string $attemptId): array
    {
        return StoragePath::forMultipartCompletion('', $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function planObjectWritePath(string $bucket, string $key, string $bucketGeneration, string $attemptId): string
    {
        return StoragePath::forPlannedObject('', $bucket, $key, $bucketGeneration, $attemptId);
    }

    public function assembleMultipartUploadAtPath(string $bucket, string $key, string $uploadId, array $parts, string $path): StorageWriteResult
    {
        StoragePath::assertMultipartCompletionPath('', $path);
        StoragePath::assertObjectWritePath('', $path, $bucket, $key);

        return $this->writeResult('assembleMultipartUploadAtPath', [$bucket, $key, $uploadId, $parts, $path]);
    }

    public function putObjectAtPath(string $bucket, string $key, ReadableStream $body, string $path): StorageWriteResult
    {
        StoragePath::assertObjectWritePath('', $path, $bucket, $key);
        [$inputPath, $reservation] = $this->spool($body, 's3fw-put-');

        $cancellation = null;
        try {
            return $this->writeResult('putObjectAtPath', [$bucket, $key, $inputPath, $path]);
        } catch (CancelledException $error) {
            $cancellation = $error;
            throw $error;
        } finally {
            $this->releaseSpooledInput($inputPath, $reservation, $cancellation);
        }
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        StoragePath::assertSafeUploadIdSegment($uploadId);
        $this->submit('abortMultipartUpload', [$bucket, $key, $uploadId]);
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        StoragePath::assertBucketPathSegment($dstBucket);
        FlysystemPathScope::assertStoredPath($srcPath);

        return $this->writeResult('copyObject', [$srcPath, $dstBucket, $dstKey]);
    }

    public function copyObjectBounded(string $srcPath, string $dstBucket, string $dstKey, int $maxBytes): StorageWriteResult
    {
        StoragePath::assertBucketPathSegment($dstBucket);
        FlysystemPathScope::assertStoredPath($srcPath);

        if ($maxBytes < 0) {
            throw new \InvalidArgumentException('Maximum copied bytes must be non-negative.');
        }

        // The source read and destination write must run in one worker task.
        // A streamed API-level copy could otherwise hold the only read worker
        // while a second worker is needed to finish the write.
        return $this->writeResult('copyObjectBounded', [$srcPath, $dstBucket, $dstKey, $maxBytes]);
    }

    public function shutdown(): void
    {
        foreach ($this->activeReadStreams as $stream => $_) {
            $stream->close();
        }
        $failure = null;
        try {
            $this->readPool->shutdown();
        } catch (\Throwable $error) {
            $this->metrics?->recordWorkerPoolShutdownFailure($this->poolName . '-reads', $error::class);
            $failure = $error;
        }
        if ($this->pool !== $this->readPool) {
            try {
                $this->pool->shutdown();
            } catch (\Throwable $error) {
                $this->metrics?->recordWorkerPoolShutdownFailure($this->poolName, $error::class);
                $failure ??= $error;
            }
        }
        if ($failure !== null) {
            throw $failure;
        }
    }

    /** Admit at most one live read task and one queued request per read worker. */
    private function acquireReadSlot(TimeoutCancellation $deadline): void
    {
        $deadline->throwIfRequested();
        $limit = $this->readAdmissionLimit;
        if ($this->activeReadSlots < $limit) {
            ++$this->activeReadSlots;

            return;
        }
        if (count($this->readWaiters) >= $limit) {
            throw new SlowDownException('Storage read workers are busy.');
        }

        $waiter = new DeferredFuture();
        $id = ++$this->nextReadWaiterId;
        $this->readWaiters[$id] = $waiter;
        try {
            $waiter->getFuture()->await($deadline);
        } catch (CancelledException $error) {
            if ($waiter->isComplete()) {
                // A slot may have been handed over in the same event-loop turn
                // as the deadline. Pass it on instead of losing capacity.
                $this->releaseReadSlot();
            }
            throw $error;
        } finally {
            unset($this->readWaiters[$id]);
        }
    }

    private function releaseReadSlot(): void
    {
        foreach ($this->readWaiters as $id => $waiter) {
            unset($this->readWaiters[$id]);
            $waiter->complete();

            return;
        }

        --$this->activeReadSlots;
    }

    /** Admit one running write and one cancellable waiter per write worker. */
    private function acquireWriteSlot(): void
    {
        if ($this->activeWriteSlots < $this->writeAdmissionLimit) {
            ++$this->activeWriteSlots;

            return;
        }
        if (count($this->writeWaiters) >= $this->writeAdmissionLimit) {
            throw new SlowDownException('Storage write workers are busy.');
        }

        $waiter = new DeferredFuture();
        $id = ++$this->nextWriteWaiterId;
        $this->writeWaiters[$id] = $waiter;
        try {
            $waiter->getFuture()->await(new TimeoutCancellation($this->writeAdmissionTimeoutSeconds));
        } catch (CancelledException $error) {
            if ($waiter->isComplete()) {
                // A slot handed over as the timer fires belongs to the next
                // waiter, not to this request after its deadline.
                $this->releaseWriteSlot();
            }
            throw new SlowDownException('Storage write workers are busy.');
        } finally {
            unset($this->writeWaiters[$id]);
        }
    }

    private function releaseWriteSlot(): void
    {
        foreach ($this->writeWaiters as $id => $waiter) {
            unset($this->writeWaiters[$id]);
            $waiter->complete();

            return;
        }

        --$this->activeWriteSlots;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function writeResult(string $operation, array $arguments): StorageWriteResult
    {
        $result = $this->submit($operation, $arguments);
        if (! $result instanceof StorageWriteResult) {
            throw new InternalErrorException('Flysystem worker returned an invalid result.');
        }

        return $result;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function submit(string $operation, array $arguments): StorageWriteResult|bool|null
    {
        $this->acquireWriteSlot();
        try {
            $result = $this->pool->submit(new FlysystemStorageTask(
                $this->factory,
                $this->tempDir,
                $operation,
                $arguments,
                tempBudgetBytes: $this->tempBudgetBytes,
            ))->await();
            $this->metrics?->recordWorkerPoolTask($this->poolName, $operation);

            return $result;
        } catch (CancelledException $error) {
            throw $error;
        } catch (TaskFailureThrowable $error) {
            $this->metrics?->recordWorkerPoolTask(
                $this->poolName,
                $operation,
                false,
                $error->getOriginalClassName(),
            );
            if ($error->getOriginalClassName() === NoSuchKeyException::class) {
                throw new NoSuchKeyException();
            }
            if ($error->getOriginalClassName() === SlowDownException::class) {
                throw new SlowDownException('Storage staging budget is full.');
            }

            throw new InternalErrorException(
                'Flysystem worker operation failed.',
                $error,
            );
        } catch (NoSuchKeyException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->metrics?->recordWorkerPoolTask($this->poolName, $operation, false, $error::class);
            throw new InternalErrorException(
                'Flysystem worker operation failed.',
                $error,
            );
        } finally {
            $this->releaseWriteSlot();
        }
    }

    /** @return array{string, StorageTempReservation} */
    private function spool(ReadableStream $stream, string $prefix, ?int $maxBytes = null): array
    {
        $path = $this->createTempFile($prefix);
        try {
            $reservation = $this->tempBudget->start($path);
        } catch (\Throwable $error) {
            $this->deleteTempFile($path);
            throw $error;
        }

        $file = null;
        $size = 0;
        try {
            $file = $this->localFilesystem->openFile($path, 'w');
            while (($chunk = $stream->read()) !== null) {
                $size += strlen($chunk);
                if ($maxBytes !== null && $size > $maxBytes) {
                    throw new EntityTooLargeException('Multipart part exceeds the 5 GiB maximum.');
                }
                $this->tempBudget->reserve($reservation, strlen($chunk));
                $file->write($chunk);
            }
            $file->close();
            clearstatcache(true, $path);
            if (@filesize($path) !== $size) {
                throw new InternalErrorException('Flysystem upload staging file is incomplete.');
            }
        } catch (\Throwable $error) {
            try {
                $file?->close();
            } catch (\Throwable) {
                // Cleanup failures must not replace the staging failure.
            }
            try {
                $this->deleteTempFile($path);
            } catch (\Throwable) {
                // The original failure remains the actionable cause.
            }
            try {
                $this->tempBudget->release($reservation);
            } catch (\Throwable) {
                // Reservation cleanup must not mask request cancellation.
            }
            if ($error instanceof CancelledException || $error instanceof SlowDownException || $error instanceof EntityTooLargeException) {
                throw $error;
            }
            throw new InternalErrorException('Failed to stage Flysystem upload.', $error);
        }

        return [$path, $reservation];
    }

    private function createTempFile(string $prefix): string
    {
        $directory = realpath($this->tempDir);
        if ($directory === false || !is_dir($directory) || !is_writable($directory)) {
            throw new InternalErrorException('Failed to create Flysystem worker temporary file.');
        }

        // PHP's tempnam() falls back to the system temp directory when the
        // configured volume disappears. Never create staging bytes elsewhere.
        $path = $directory . DIRECTORY_SEPARATOR . $prefix . bin2hex(random_bytes(16));
        $handle = @fopen($path, 'x+b');
        if ($handle === false) {
            throw new InternalErrorException('Failed to create Flysystem worker temporary file.');
        }
        if (!@chmod($path, 0600)) {
            fclose($handle);
            @unlink($path);
            throw new InternalErrorException('Failed to protect Flysystem worker temporary file.');
        }
        fclose($handle);

        return $path;
    }

    private function deleteTempFile(string $path): void
    {
        try {
            if ($this->localFilesystem->exists($path)) {
                $this->localFilesystem->deleteFile($path);
            }
        } catch (\Throwable) {
            @unlink($path);
        }
    }

    private function releaseSpooledInput(
        string $path,
        StorageTempReservation $reservation,
        ?CancelledException $cancellation,
    ): void {
        $this->deleteTempFile($path);
        try {
            $this->tempBudget->release($reservation);
        } catch (\Throwable $releaseError) {
            if ($cancellation === null) {
                throw $releaseError;
            }
        }
    }
}
