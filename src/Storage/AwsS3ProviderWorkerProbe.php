<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Parallel\Worker\WorkerPool;
use Amp\TimeoutCancellation;
use Amp\Sync\LocalMutex;
use OpsFour\S3Server\Parallel\AwsS3ProviderProbeTask;

/** Runs blocking AWS SDK admission probes outside the Amp event-loop thread. */
final readonly class AwsS3ProviderWorkerProbe implements AwsS3ProviderProbe
{
    private LocalMutex $mutex;

    public function __construct(
        private WorkerPool $pool,
        private float $timeoutSeconds = 245.0,
    ) {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0.0 || $timeoutSeconds > 300.0) {
            throw new \InvalidArgumentException('AWS provider probe timeout must be between 0 and 300 seconds.');
        }
        $this->mutex = new LocalMutex();
    }

    public function revalidate(AwsS3ProviderProfile $profile): AwsS3ProviderAdmission
    {
        $lock = $this->mutex->acquire();
        try {
            if (! $this->pool->isIdle()) {
                throw new \RuntimeException('AWS provider probe worker is busy.');
            }
            $execution = $this->pool->submit(new AwsS3ProviderProbeTask($profile));
            $result = $execution->getFuture()->await(new TimeoutCancellation($this->timeoutSeconds));
            if (! $result instanceof AwsS3ProviderAdmission) {
                throw new \RuntimeException('AWS provider probe worker returned an invalid result.');
            }

            return $result;
        } finally {
            $lock->release();
        }
    }

    public function usesPool(WorkerPool $pool): bool
    {
        return $this->pool === $pool;
    }
}
