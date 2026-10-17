<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Storage\LocalMultipartPartWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\StorageTempWorkerLease;

/** @implements Task<LocalMultipartPartWriteTaskResult, never, never> */
final readonly class LocalMultipartPartWriteTask implements Task
{
    public function __construct(
        private string $root,
        private string $source,
        private string $leasePath,
        private string $target,
        private string $bucket,
        private string $generation,
        private string $uploadId,
        private int $partNumber,
        private string $attemptId,
        private int $expectedSize,
        private string $expectedSha256,
        private ?LocalObjectWriteIo $io = null,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): LocalMultipartPartWriteTaskResult
    {
        $tracking = new TrackingMultipartPartIo($this->io ?? new NativeLocalObjectWriteIo());
        $lease = null;
        try {
            $cancellation->throwIfRequested();
            $lease = StorageTempWorkerLease::acquire($this->leasePath, $this->source);
            $result = (new LocalMultipartPartWriteFence($this->root, $tracking))->publish(
                $this->source,
                $this->target,
                $this->bucket,
                $this->generation,
                $this->uploadId,
                $this->partNumber,
                $this->attemptId,
                $this->expectedSize,
                $this->expectedSha256,
            );
            return new LocalMultipartPartWriteTaskResult($result, true);
        } catch (\Throwable $error) {
            return new LocalMultipartPartWriteTaskResult(
                null,
                $tracking->publicationAttempted(),
                $error::class,
                $error->getMessage(),
            );
        } finally {
            $lease?->close();
        }
    }
}
