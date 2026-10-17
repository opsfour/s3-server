<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\ByteStream\ReadableResourceStream;
use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Storage\LocalObjectWriteFence;
use OpsFour\S3Server\Storage\LocalObjectWriteIo;
use OpsFour\S3Server\Storage\NativeLocalObjectWriteIo;
use OpsFour\S3Server\Storage\StorageTempWorkerLease;

/** @implements Task<LocalObjectWriteTaskResult, never, never> */
final readonly class LocalObjectWriteTask implements Task
{
    public function __construct(
        private string $root,
        private ObjectWriteIntent $intent,
        private string $key,
        private string $stagePath,
        private string $stageLeasePath,
        private int $expectedSize,
        private string $expectedSha256,
        private ?LocalObjectWriteIo $io = null,
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): LocalObjectWriteTaskResult
    {
        $trackingIo = new TrackingLocalObjectWriteIo($this->io ?? new NativeLocalObjectWriteIo());
        $stream = null;
        $stageLease = null;
        try {
            $cancellation->throwIfRequested();
            $stageLease = StorageTempWorkerLease::acquire($this->stageLeasePath, $this->stagePath);
            clearstatcache(true, $this->stagePath);
            if (@filesize($this->stagePath) !== $this->expectedSize) {
                throw new \RuntimeException('Local object staging size changed before worker read.');
            }
            $resource = @fopen($this->stagePath, 'rb');
            if ($resource === false) {
                throw new \RuntimeException('Local object worker could not open staging file.');
            }
            $stream = new ReadableResourceStream($resource);
            $result = (new LocalObjectWriteFence($this->root, $trackingIo))
                ->write($this->intent, $this->key, $stream);
            if ($result->size !== $this->expectedSize
                || $result->sha256Base64 === null
                || ! hash_equals(base64_encode($this->expectedSha256), $result->sha256Base64)) {
                throw new \RuntimeException('Local object worker result does not match staged bytes.');
            }

            return new LocalObjectWriteTaskResult($result, true);
        } catch (\Throwable $error) {
            return new LocalObjectWriteTaskResult(
                null,
                $trackingIo->publicationAttempted(),
                $error::class,
                $error->getMessage(),
            );
        } finally {
            try {
                $stream?->close();
            } finally {
                $stageLease?->close();
            }
        }
    }
}

/** Tracks the first possibly ambiguous create-only syscall. */
final class TrackingLocalObjectWriteIo implements LocalObjectWriteIo
{
    private bool $publicationAttempted = false;

    public function __construct(private readonly LocalObjectWriteIo $inner) {}

    public function publicationAttempted(): bool
    {
        return $this->publicationAttempted;
    }

    public function syncFile($file): bool
    {
        return $this->inner->syncFile($file);
    }

    public function syncDirectory(string $path): bool
    {
        return $this->inner->syncDirectory($path);
    }

    public function link(string $source, string $target): bool
    {
        $this->publicationAttempted = true;
        return $this->inner->link($source, $target);
    }

    public function rename(string $source, string $target): bool
    {
        return $this->inner->rename($source, $target);
    }
}
