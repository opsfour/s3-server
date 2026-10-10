<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;
use Amp\CancelledException;
use Amp\CompositeCancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;

/**
 * @implements Task<StorageWriteResult|bool|null, string, string|null>
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
        private readonly int $tempBudgetBytes = 10_737_418_240,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): StorageWriteResult|bool|null
    {
        try {
            $backendKey = $this->factory->cacheKey() . ':' . hash('sha256', $this->tempDir) . ':' . $this->tempBudgetBytes;
            $backend = self::$backends[$backendKey] ??= new FlysystemBackend(
                $this->factory->createFilesystem(),
                $this->tempDir,
                new StorageTempBudgetCoordinator($this->tempDir, $this->tempBudgetBytes),
            );

            return match ($this->operation) {
                'putObject' => $backend->putObject(
                    $this->stringArgument(0),
                    $this->stringArgument(1),
                    self::fileStream($this->stringArgument(2)),
                ),
                'putObjectAtPath' => $backend->putObjectAtPath(
                    $this->stringArgument(0),
                    $this->stringArgument(1),
                    self::fileStream($this->stringArgument(2)),
                    $this->stringArgument(3),
                ),
                'streamObject' => $this->streamObject(
                    $backend,
                    $this->stringArgument(0),
                    $this->nullableIntArgument(1),
                    $this->nullableIntArgument(2),
                    $this->intArgument(3),
                    $this->intArgument(4),
                    $channel,
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
                'putPartAtPath' => $backend->putPartAtPath(
                    $this->stringArgument(0),
                    $this->stringArgument(1),
                    $this->stringArgument(2),
                    $this->intArgument(3),
                    self::fileStream($this->stringArgument(4)),
                    $this->stringArgument(5),
                ),
                'assembleMultipartUpload' => $backend->assembleMultipartUpload(
                    $this->stringArgument(0),
                    $this->stringArgument(1),
                    $this->stringArgument(2),
                    $this->partsArgument(3),
                ),
                'assembleMultipartUploadAtPath' => $backend->assembleMultipartUploadAtPath(
                    $this->stringArgument(0),
                    $this->stringArgument(1),
                    $this->stringArgument(2),
                    $this->partsArgument(3),
                    $this->stringArgument(4),
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
                'copyObjectBounded' => $backend->copyObjectBounded(
                    $this->stringArgument(0),
                    $this->stringArgument(1),
                    $this->stringArgument(2),
                    $this->intArgument(3),
                ),
                default => throw new \LogicException("Unknown Flysystem worker operation: {$this->operation}."),
            };
        } catch (CancelledException) {
            throw new CancelledException();
        } catch (NoSuchKeyException) {
            throw new NoSuchKeyException();
        } catch (SlowDownException) {
            throw new SlowDownException('Flysystem worker operation was throttled.');
        } catch (\Throwable) {
            // Flysystem/AWS exceptions can retain a PHP stream resource in a
            // nested backtrace. Amp's cross-process TaskFailure serializer
            // cannot flatten that resource and would crash the worker.
            throw new InternalErrorException('Flysystem worker operation failed.');
        }
    }

    /** @param Channel<string, string|null> $channel */
    private function streamObject(
        FlysystemBackend $backend,
        string $storagePath,
        ?int $offset,
        ?int $length,
        int $idleTimeoutSeconds,
        int $sourceReadTimeoutSeconds,
        Channel $channel,
        Cancellation $cancellation,
    ): null {
        $source = $backend->getObjectByPath($storagePath, $offset, $length);
        try {
            $buffer = '';
            while (true) {
                // Guzzle's PHP stream timeout does not bound Amp's event-loop
                // wait on a detached response resource. Reset this deadline
                // for each source chunk, not for the whole object.
                $sourceReadDeadline = new TimeoutCancellation($sourceReadTimeoutSeconds);
                try {
                    $chunk = $source->read(new CompositeCancellation($cancellation, $sourceReadDeadline));
                } catch (CancelledException $error) {
                    if ($sourceReadDeadline->isRequested() && ! $cancellation->isRequested()) {
                        throw new SlowDownException('Backing storage read was idle for too long.');
                    }
                    throw $error;
                }
                if ($chunk === null) {
                    break;
                }
                $buffer .= $chunk;
                if (strlen($buffer) >= 65_536 && ! self::sendChunk($channel, $buffer, $idleTimeoutSeconds, $cancellation)) {
                    return null;
                }
                if (strlen($buffer) >= 65_536) {
                    $buffer = '';
                }
            }
            if ($buffer !== '' && ! self::sendChunk($channel, $buffer, $idleTimeoutSeconds, $cancellation)) {
                return null;
            }
            $channel->send(null);
        } finally {
            $source->close();
        }

        return null;
    }

    /** @param Channel<string, string|null> $channel */
    private static function sendChunk(
        Channel $channel,
        string $chunk,
        int $idleTimeoutSeconds,
        Cancellation $cancellation,
    ): bool {
        $channel->send($chunk);

        return $channel->receive(new CompositeCancellation(
            $cancellation,
            new TimeoutCancellation($idleTimeoutSeconds),
        )) === 'next';
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
     * @return array<int, array{partNumber: int, etag: string, storagePath: string}>
     */
    private function partsArgument(int $offset): array
    {
        $value = $this->arguments[$offset] ?? null;
        if (! is_array($value)) {
            throw new \LogicException("Flysystem task argument {$offset} must be a parts array.");
        }

        /** @var array<int, array{partNumber: int, etag: string, storagePath: string}> $value */
        return $value;
    }
}
