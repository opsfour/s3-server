<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Fully wired S3 server runtime plus optional runtime services.
 */
final class S3ServerRuntime
{
    private bool $stopped = false;

    private bool $stopInProgress = false;

    private bool $notificationsClosed = false;

    private bool $encryptionClosed = false;

    private ?LocalFencedObjectWriter $localObjectWriter = null;

    private ?DedicatedAmpObjectWriteLeaseRenewer $localObjectRenewer = null;

    public function __construct(
        public readonly S3Server $server,
        public readonly NotificationDispatcher $notifications,
        public readonly ?EncryptionServiceInterface $encryption = null,
        public readonly ?WorkerPool $selectWorkerPool = null,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /** Transfer the isolated test profile's worker resources to runtime shutdown. */
    public function attachLocalObjectWriteTestResources(
        LocalFencedObjectWriter $writer,
        DedicatedAmpObjectWriteLeaseRenewer $renewer,
    ): void {
        if ($this->stopped || $this->stopInProgress || $this->localObjectWriter !== null || $this->localObjectRenewer !== null) {
            throw new \LogicException('Local object write test resources were already attached or runtime stopped.');
        }
        $this->localObjectWriter = $writer;
        $this->localObjectRenewer = $renewer;
    }

    public function stop(): void
    {
        if ($this->stopped) {
            return;
        }
        if ($this->stopInProgress) {
            throw new \LogicException('S3 runtime shutdown is already in progress.');
        }
        $this->stopInProgress = true;

        try {
            $this->stopOwnedResources();
            $this->stopped = true;
        } finally {
            $this->stopInProgress = false;
        }
    }

    private function stopOwnedResources(): void
    {
        try {
            $this->server->stop();
        } catch (\Throwable $e) {
            $this->logShutdownWarning('Server shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }

        try {
            $this->localObjectWriter?->shutdown();
            $this->localObjectWriter = null;
        } catch (\Throwable $e) {
            $this->logShutdownWarning('Local object write worker shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }
        try {
            $this->localObjectRenewer?->close();
            $this->localObjectRenewer = null;
        } catch (\Throwable $e) {
            $this->logShutdownWarning('Local object write renewal pool shutdown failed.', ['exception' => $e::class]);
            $this->abortIncompleteShutdown();
        }

        if (! $this->notificationsClosed) {
            try {
                $this->notifications->shutdown($this->server->getConfig()->shutdownDrainTimeout);
                $this->notificationsClosed = true;
            } catch (\Throwable $e) {
                $this->logShutdownWarning('Notification shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
        }

        if (! $this->encryptionClosed && $this->encryption !== null && method_exists($this->encryption, 'shutdown')) {
            try {
                $this->encryption->shutdown();
                $this->encryptionClosed = true;
            } catch (\Throwable $e) {
                $this->logShutdownWarning('Encryption worker shutdown failed.', ['exception' => $e::class]);
                $this->abortIncompleteShutdown();
            }
        }
    }

    private function abortIncompleteShutdown(): never
    {
        // Do not expose a nested worker exception that may contain secrets.
        throw new \RuntimeException('S3 runtime shutdown incomplete; dependencies remain owned.');
    }

    /** @param array<string, mixed> $context */
    private function logShutdownWarning(string $message, array $context): void
    {
        try {
            $this->logger->warning($message, $context);
        } catch (\Throwable) {
            // A failing logger must not replace the sanitized shutdown error.
        }
    }
}
