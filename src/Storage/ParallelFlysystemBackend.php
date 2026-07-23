<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\File;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\FlysystemStorageTask;

/**
 * Flysystem backend that executes every synchronous adapter call in a bounded
 * worker process pool. HTTP request fibers only perform asynchronous local
 * staging I/O and wait on worker futures.
 */
final class ParallelFlysystemBackend implements StorageBackend, ShutdownAwareStorageBackend
{
    private readonly WorkerPool $pool;

    private readonly File\Filesystem $localFilesystem;

    public function __construct(
        private readonly FlysystemFilesystemFactory $factory,
        private readonly string $tempDir,
        int $workerLimit = 4,
        private readonly ?MetricsCollector $metrics = null,
        ?WorkerPool $pool = null,
        private readonly string $poolName = 'flysystem',
    ) {
        if ($workerLimit < 1) {
            throw new \InvalidArgumentException('Flysystem worker limit must be at least 1.');
        }
        if (! is_dir($tempDir) || ! is_writable($tempDir)) {
            throw new \InvalidArgumentException("Flysystem temp directory must exist and be writable: {$tempDir}");
        }

        $this->pool = $pool ?? new ContextWorkerPool($workerLimit);
        $this->localFilesystem = File\filesystem();
        $this->metrics?->registerWorkerPool($this->poolName, $workerLimit);
    }

    public function putObject(string $bucket, string $key, ReadableStream $body): StorageWriteResult
    {
        $inputPath = $this->spool($body, 's3fw-put-');

        try {
            return $this->writeResult('putObject', [$bucket, $key, $inputPath]);
        } finally {
            $this->deleteTempFile($inputPath);
        }
    }

    public function getObjectByPath(string $storagePath, ?int $offset = null, ?int $length = null): ReadableStream
    {
        $outputPath = $this->createTempFile('s3fw-get-');

        try {
            $this->submit('getObject', [$storagePath, $outputPath, $offset, $length]);
            $file = $this->localFilesystem->openFile($outputPath, 'r');

            return new DeletingReadableStream($file, $outputPath);
        } catch (\Throwable $error) {
            $this->deleteTempFile($outputPath);
            throw $error;
        }
    }

    public function deleteObjectByPath(string $storagePath, string $bucket): void
    {
        $this->submit('deleteObject', [$storagePath, $bucket]);
    }

    public function createBucket(string $bucket): void
    {
        $this->submit('createBucket', [$bucket]);
    }

    public function deleteBucket(string $bucket): void
    {
        $this->submit('deleteBucket', [$bucket]);
    }

    public function bucketExists(string $bucket): bool
    {
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
        $inputPath = $this->spool($data, 's3fw-part-');

        try {
            return $this->writeResult('putPart', [$bucket, $key, $uploadId, $partNumber, $inputPath]);
        } finally {
            $this->deleteTempFile($inputPath);
        }
    }

    public function assembleMultipartUpload(string $bucket, string $key, string $uploadId, array $parts): StorageWriteResult
    {
        return $this->writeResult('assembleMultipartUpload', [$bucket, $key, $uploadId, $parts]);
    }

    public function abortMultipartUpload(string $bucket, string $key, string $uploadId): void
    {
        $this->submit('abortMultipartUpload', [$bucket, $key, $uploadId]);
    }

    public function copyObject(string $srcPath, string $dstBucket, string $dstKey): StorageWriteResult
    {
        return $this->writeResult('copyObject', [$srcPath, $dstBucket, $dstKey]);
    }

    public function shutdown(): void
    {
        try {
            $this->pool->shutdown();
        } catch (\Throwable $error) {
            $this->metrics?->recordWorkerPoolShutdownFailure($this->poolName, $error::class);
            throw $error;
        }
    }

    /**
     * @param list<mixed> $arguments
     */
    private function writeResult(string $operation, array $arguments): StorageWriteResult
    {
        $result = $this->submit($operation, $arguments);
        if (! $result instanceof StorageWriteResult) {
            throw new InternalErrorException("Flysystem worker returned an invalid {$operation} result.");
        }

        return $result;
    }

    /**
     * @param list<mixed> $arguments
     */
    private function submit(string $operation, array $arguments): StorageWriteResult|bool|null
    {
        try {
            $result = $this->pool->submit(new FlysystemStorageTask(
                $this->factory,
                $this->tempDir,
                $operation,
                $arguments,
            ))->await();
            $this->metrics?->recordWorkerPoolTask($this->poolName, $operation);

            return $result;
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

            throw new InternalErrorException(
                "Flysystem worker {$operation} failed: {$error->getOriginalMessage()}",
                $error,
            );
        } catch (NoSuchKeyException $error) {
            throw $error;
        } catch (\Throwable $error) {
            $this->metrics?->recordWorkerPoolTask($this->poolName, $operation, false, $error::class);
            throw new InternalErrorException(
                "Flysystem worker {$operation} failed: {$error->getMessage()}",
                $error,
            );
        }
    }

    private function spool(ReadableStream $stream, string $prefix): string
    {
        $path = $this->createTempFile($prefix);
        $file = $this->localFilesystem->openFile($path, 'w');

        try {
            while (($chunk = $stream->read()) !== null) {
                $file->write($chunk);
            }
            $file->close();
        } catch (\Throwable $error) {
            $file->close();
            $this->deleteTempFile($path);
            throw new InternalErrorException('Failed to stage Flysystem upload: ' . $error->getMessage(), $error);
        }

        return $path;
    }

    private function createTempFile(string $prefix): string
    {
        $path = tempnam($this->tempDir, $prefix);
        if ($path === false) {
            throw new InternalErrorException("Failed to create Flysystem worker temp file in {$this->tempDir}.");
        }

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
}
