<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\DeferredCancellation;
use Amp\Future;

final class QueueLeaseHeartbeat
{
    private readonly DeferredCancellation $deferredCancellation;

    /** @var Future<void> */
    private readonly Future $future;

    private bool $stopped = false;

    /**
     * @param \Closure(float): bool $renew
     */
    public function __construct(
        \Closure $renew,
        float $intervalSeconds = QueueLease::RENEWAL_INTERVAL_SECONDS,
    ) {
        if ($intervalSeconds <= 0.0) {
            throw new \InvalidArgumentException('Queue lease renewal interval must be greater than zero.');
        }

        $this->deferredCancellation = new DeferredCancellation();
        $cancellation = $this->deferredCancellation->getCancellation();
        $this->future = \Amp\async(static function () use ($renew, $intervalSeconds, $cancellation): void {
            try {
                while (true) {
                    \Amp\delay($intervalSeconds, cancellation: $cancellation);
                    if (! $renew(QueueLease::expiresAt())) {
                        throw new \RuntimeException('Queue processing lease was lost.');
                    }
                }
            } catch (\Amp\CancelledException) {
                // Normal stop path.
            }
        });
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }

        $this->stopped = true;
        $this->deferredCancellation->cancel();
        $this->future->await();
    }

    public function __destruct()
    {
        $this->deferredCancellation->cancel();
    }
}
