<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Monotonic process-local admission state shared by probes and request gates. */
final class NodeDrainController
{
    private const string ACCEPTING = 'accepting';

    private const string DRAINING = 'draining';

    private const string STOPPED = 'stopped';

    private string $state = self::ACCEPTING;

    private ?float $drainStartedAt = null;

    /** @return bool True only for the first transition into drain. */
    public function beginDrain(): bool
    {
        if ($this->state !== self::ACCEPTING) {
            return false;
        }

        $this->state = self::DRAINING;

        return true;
    }

    /** Start the propagation window after synchronous readiness publication finishes. */
    public function startPropagationWindow(): void
    {
        if ($this->state === self::DRAINING && $this->drainStartedAt === null) {
            $this->drainStartedAt = hrtime(true) / 1e9;
        }
    }

    public function stop(): void
    {
        if ($this->state === self::ACCEPTING) {
            $this->beginDrain();
        }
        $this->state = self::STOPPED;
    }

    public function acceptsNewWork(): bool
    {
        return $this->state === self::ACCEPTING;
    }

    public function isDraining(): bool
    {
        return $this->state === self::DRAINING;
    }

    public function isStopped(): bool
    {
        return $this->state === self::STOPPED;
    }

    public function remainingPropagationDelay(int $configuredSeconds): float
    {
        if ($configuredSeconds < 0) {
            throw new \InvalidArgumentException('Readiness propagation delay must be non-negative.');
        }
        if ($this->state !== self::DRAINING) {
            return 0.0;
        }
        if ($this->drainStartedAt === null) {
            return (float) $configuredSeconds;
        }

        return max(0.0, $configuredSeconds - (hrtime(true) / 1e9 - $this->drainStartedAt));
    }
}
