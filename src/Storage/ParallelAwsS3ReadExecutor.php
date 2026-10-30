<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\ByteStream\ReadableStream;
use Amp\CancelledException;
use Amp\DeferredCancellation;
use Amp\Parallel\Worker\WorkerPool;
use Amp\TimeoutCancellation;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\AwsS3PayloadReadTask;

use function Amp\async;

/** Bounded API-side admission and priming for dedicated AWS read workers. */
final class ParallelAwsS3ReadExecutor implements AwsS3ReadExecutor
{
    private readonly AwsS3ReadAdmission $admission;
    /** @var \WeakMap<AwsS3ParallelReadableStream, true> */
    private readonly \WeakMap $streams;

    public function __construct(
        private readonly WorkerPool $pool,
        int $workerLimit,
        int $waiterLimit,
        private readonly float $admissionTimeoutSeconds,
        private readonly float $firstByteTimeoutSeconds,
        private readonly ?MetricsCollector $metrics = null,
    ) {
        if ($workerLimit < 1 || $waiterLimit < 0
            || ! is_finite($admissionTimeoutSeconds) || $admissionTimeoutSeconds <= 0
            || ! is_finite($firstByteTimeoutSeconds) || $firstByteTimeoutSeconds <= 0) {
            throw new \InvalidArgumentException('AWS read executor limits and timeouts are invalid.');
        }
        $this->streams = new \WeakMap();
        $this->admission = new AwsS3ReadAdmission(
            $workerLimit,
            $waiterLimit,
            fn(int $active, int $waiting) => $this->metrics?->setAwsReadPoolState($active, $waiting),
        );
        $this->metrics?->setAwsReadPoolState(0, 0);
    }

    public function readCommittedObject(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        ObjectInfo $object,
        string $key,
        string $path,
        string $storageTier,
        ?int $offset,
        ?int $length,
    ): ReadableStream {
        return $this->execute(new AwsS3PayloadReadTask(
            $profile,
            AwsS3PayloadReadTask::COMMITTED,
            $bucket->name,
            $bucket->generation,
            $key,
            $path,
            $storageTier,
            $object->ownerId,
            $object->size,
            $object->size,
            $offset,
            $length,
        ));
    }

    public function readMultipartPart(
        AwsS3ProviderProfile $profile,
        BucketInfo $bucket,
        string $logicalScope,
        string $path,
        string $storageTier,
        string $expectedOwnerId,
        int $expectedSize,
        int $maximumSize,
    ): ReadableStream {
        return $this->execute(new AwsS3PayloadReadTask(
            $profile,
            AwsS3PayloadReadTask::MULTIPART_PART,
            $bucket->name,
            $bucket->generation,
            $logicalScope,
            $path,
            $storageTier,
            $expectedOwnerId,
            $expectedSize,
            $maximumSize,
        ));
    }

    public function usesPool(WorkerPool $pool): bool
    {
        return $this->pool === $pool;
    }

    private function execute(AwsS3PayloadReadTask $task): ReadableStream
    {
        $taskCancellation = new DeferredCancellation();
        $admissionDeadline = new TimeoutCancellation($this->admissionTimeoutSeconds);
        try {
            $this->admission->acquire($admissionDeadline);
            $this->metrics?->recordAwsReadOutcome('admission', 'ready');
        } catch (CancelledException) {
            $this->metrics?->recordAwsReadOutcome('admission', 'timeout');
            throw new SlowDownException('AWS payload read admission timed out.');
        } catch (SlowDownException $error) {
            $this->metrics?->recordAwsReadOutcome('admission', 'rejected');
            throw $error;
        }

        try {
            $execution = $this->pool->submit($task, $taskCancellation->getCancellation());
        } catch (\Throwable $error) {
            $this->admission->release();
            $this->metrics?->recordAwsReadOutcome('worker', 'start_failure');
            throw new InternalErrorException('AWS payload read worker could not start.', $error);
        }
        $execution->getFuture()->finally($this->admission->release(...))->ignore();
        $stream = new AwsS3ParallelReadableStream(
            $execution,
            $taskCancellation,
            fn(string $outcome) => $this->metrics?->recordAwsReadOutcome('stream', $outcome),
        );
        $this->streams[$stream] = true;
        $reference = \WeakReference::create($stream);
        $stream->onClose(function () use ($reference): void {
            $stream = $reference->get();
            if ($stream !== null) {
                unset($this->streams[$stream]);
            }
        });

        $firstByteDeadline = new TimeoutCancellation($this->firstByteTimeoutSeconds);
        try {
            async(fn() => $stream->prime($taskCancellation->getCancellation()))->await($firstByteDeadline);
            $this->metrics?->recordAwsReadOutcome('first_byte', 'ready');
            return $stream;
        } catch (CancelledException) {
            $stream->close();
            $this->metrics?->recordAwsReadOutcome('first_byte', 'timeout');
            throw new SlowDownException('AWS payload read first byte timed out.');
        } catch (\Throwable $error) {
            $stream->close();
            $this->metrics?->recordAwsReadOutcome('first_byte', 'failure');
            throw $error;
        }
    }
}
