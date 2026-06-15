<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\S3Server;

/**
 * Fully wired S3 server runtime plus optional runtime services.
 */
final readonly class S3ServerRuntime
{
    public function __construct(
        public S3Server $server,
        public NotificationDispatcher $notifications,
        public ?EncryptionServiceInterface $encryption = null,
        public ?WorkerPool $selectWorkerPool = null,
    ) {}

    public function stop(): void
    {
        $this->server->stop();

        if ($this->encryption !== null && method_exists($this->encryption, 'shutdown')) {
            $this->encryption->shutdown();
        }
    }
}
