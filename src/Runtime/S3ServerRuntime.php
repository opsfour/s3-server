<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\S3Server;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fully wired S3 server runtime plus optional runtime services.
 */
final class S3ServerRuntime
{
    private bool $stopped = false;

    public function __construct(
        public readonly S3Server $server,
        public readonly NotificationDispatcher $notifications,
        public readonly ?EncryptionServiceInterface $encryption = null,
        public readonly ?WorkerPool $selectWorkerPool = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {}

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        $this->stopped = true;

        try {
            $this->server->stop();
        } catch (\Throwable $e) {
            $this->logger->warning('Server shutdown error: {error}', ['error' => $e->getMessage()]);
        }

        try {
            $this->notifications->shutdown($this->server->getConfig()->shutdownDrainTimeout);
        } catch (\Throwable $e) {
            $this->logger->warning('Notification shutdown error: {error}', ['error' => $e->getMessage()]);
        }

        if ($this->encryption !== null && method_exists($this->encryption, 'shutdown')) {
            try {
                $this->encryption->shutdown();
            } catch (\Throwable $e) {
                $this->logger->warning('Encryption worker shutdown error: {error}', ['error' => $e->getMessage()]);
            }
        }
    }
}
