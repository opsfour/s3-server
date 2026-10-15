<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\DeferredFuture;
use Revolt\EventLoop;

/**
 * Arms process signal watchers before opening the HTTP listener.
 */
final class TerminationSignalTrap
{
    /** @var list<string> */
    private array $watcherIds = [];

    /** @var DeferredFuture<int> */
    private readonly DeferredFuture $signal;

    private bool $closed = false;

    public function __construct()
    {
        $this->signal = new DeferredFuture();
        $callback = function (string $_watcherId, int $signal): void {
            if (! $this->signal->isComplete()) {
                $this->signal->complete($signal);
            }
        };

        try {
            foreach ([\SIGINT, \SIGTERM] as $signal) {
                $this->watcherIds[] = EventLoop::onSignal($signal, $callback);
            }
        } catch (\Throwable $e) {
            $this->close();

            throw $e;
        }
    }

    /**
     * @param  \Closure(): void  $start
     */
    public function startAndWait(\Closure $start): int
    {
        if ($this->closed) {
            throw new \LogicException('Termination signal trap is closed.');
        }

        try {
            // Revolt activates newly registered signal watchers immediately
            // before the next event-loop tick. Yield once before opening the
            // listener so SIGTERM cannot land in an unarmed startup window.
            \Amp\delay(0);
            if (! $this->signal->isComplete()) {
                $start();
            }

            return $this->signal->getFuture()->await();
        } finally {
            $this->close();
        }
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        foreach ($this->watcherIds as $watcherId) {
            EventLoop::cancel($watcherId);
        }
        $this->watcherIds = [];
    }

}
