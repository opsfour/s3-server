<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\StorageWriteResult;

/**
 * @implements Task<StorageWriteResult|bool|null, never, never>
 */
final class FlysystemStorageTask implements Task
{
    /** @var array<string, FlysystemBackend> */
    private static array $backends = [];

    /**
     * @param list<mixed> $arguments
     */
    public function __construct(
        private readonly FlysystemFilesystemFactory $factory,
        private readonly string $tempDir,
        private readonly string $operation,
        private readonly array $arguments = [],
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): StorageWriteResult|bool|null
    {
        $backendKey = $this->factory->cacheKey() . ':' . hash('sha256', $this->tempDir);
        $backend = self::$backends[$backendKey] ??= new FlysystemBackend(
            $this->factory->createFilesystem(),
            $this->tempDir,
        );

        return match ($this->operation) {
            'putObject' => $backend->putObject(
                $this->stringArgument(0),
                $this->stringArgument(1),
                self::fileStream($this->stringArgument(2)),
            ),
            'getObject' => $this->download(
                $backend,
                $this->stringArgument(0),
                $this->stringArgument(1),
                $this->nullableIntArgument(2),
                $this->nullableIntArgument(3),
                $cancellation,
            ),
            'deleteObject' => $this->deleteObject(
                $backend,
                $this->stringArgument(0),
                $this->stringArgument(1),
            ),
            'createBucket' => $this->createBucket($backend, $this->stringArgument(0)),
            'deleteBucket' => $this->deleteBucket($backend, $this->stringArgument(0)),
            'bucketExists' => $backend->bucketExists($this->stringArgument(0)),
            'putPart' => $backend->putPart(
                $this->stringArgument(0),
                $this->stringArgument(1),
                $this->stringArgument(2),
                $this->intArgument(3),
                self::fileStream($this->stringArgument(4)),
            ),
            'assembleMultipartUpload' => $backend->assembleMultipartUpload(
                $this->stringArgument(0),
                $this->stringArgument(1),
                $this->stringArgument(2),
                $this->partsArgument(3),
            ),
            'abortMultipartUpload' => $this->abortMultipartUpload(
                $backend,
                $this->stringArgument(0),
                $this->stringArgument(1),
                $this->stringArgument(2),
            ),
            'copyObject' => $backend->copyObject(
                $this->stringArgument(0),
                $this->stringArgument(1),
                $this->stringArgument(2),
            ),
            default => throw new \LogicException("Unknown Flysystem worker operation: {$this->operation}."),
        };
    }

    private function download(
        FlysystemBackend $backend,
        string $storagePath,
        string $targetPath,
        ?int $offset,
        ?int $length,
        Cancellation $cancellation,
    ): null {
        $source = $backend->getObjectByPath($storagePath, $offset, $length);
        $target = fopen($targetPath, 'wb');
        if ($target === false) {
            throw new \RuntimeException("Unable to open worker download target: {$targetPath}.");
        }

        try {
            while (($chunk = $source->read($cancellation)) !== null) {
                self::writeAll($target, $chunk);
            }
        } finally {
            $source->close();
            fclose($target);
        }

        return null;
    }

    private function deleteObject(FlysystemBackend $backend, string $storagePath, string $bucket): null
    {
        $backend->deleteObjectByPath($storagePath, $bucket);

        return null;
    }

    private function createBucket(FlysystemBackend $backend, string $bucket): null
    {
        $backend->createBucket($bucket);

        return null;
    }

    private function deleteBucket(FlysystemBackend $backend, string $bucket): null
    {
        $backend->deleteBucket($bucket);

        return null;
    }

    private function abortMultipartUpload(
        FlysystemBackend $backend,
        string $bucket,
        string $key,
        string $uploadId,
    ): null {
        $backend->abortMultipartUpload($bucket, $key, $uploadId);

        return null;
    }

    private static function fileStream(string $path): ReadableResourceStream
    {
        $resource = fopen($path, 'rb');
        if ($resource === false) {
            throw new \RuntimeException("Unable to open worker input file: {$path}.");
        }

        return new ReadableResourceStream($resource);
    }

    /**
     * @param resource $resource
     */
    private static function writeAll($resource, string $data): void
    {
        $offset = 0;
        while ($offset < strlen($data)) {
            $written = fwrite($resource, substr($data, $offset));
            if ($written === false || $written === 0) {
                throw new \RuntimeException('Unable to write worker download data.');
            }
            $offset += $written;
        }
    }

    private function stringArgument(int $offset): string
    {
        $value = $this->arguments[$offset] ?? null;
        if (! is_string($value)) {
            throw new \LogicException("Flysystem task argument {$offset} must be a string.");
        }

        return $value;
    }

    private function intArgument(int $offset): int
    {
        $value = $this->arguments[$offset] ?? null;
        if (! is_int($value)) {
            throw new \LogicException("Flysystem task argument {$offset} must be an integer.");
        }

        return $value;
    }

    private function nullableIntArgument(int $offset): ?int
    {
        $value = $this->arguments[$offset] ?? null;
        if ($value !== null && ! is_int($value)) {
            throw new \LogicException("Flysystem task argument {$offset} must be an integer or null.");
        }

        return $value;
    }

    /**
     * @return array<int, array{partNumber: int, etag: string}>
     */
    private function partsArgument(int $offset): array
    {
        $value = $this->arguments[$offset] ?? null;
        if (! is_array($value)) {
            throw new \LogicException("Flysystem task argument {$offset} must be a parts array.");
        }

        /** @var array<int, array{partNumber: int, etag: string}> $value */
        return $value;
    }
}
