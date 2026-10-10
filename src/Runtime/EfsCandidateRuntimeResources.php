<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Lifecycle\LifecycleRunner;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\AmpLocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\LocalFencedMultipartPartWriter;
use OpsFour\S3Server\Storage\LocalFencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteIntentCollector;
use OpsFour\S3Server\Storage\MultipartWriteIntentCollector;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\RestoreGarbageCollector;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use Psr\Log\LoggerInterface;

/**
 * Owns an inert, fully qualified EFS resource graph. No serving handler
 * receives it. The caller must invoke shutdown() in a finally block; worker
 * shutdown can block or fail, so it must never be hidden in a destructor.
 *
 * @internal This is not a production EFS serving capability.
 */
final class EfsCandidateRuntimeResources
{
    public readonly LifecycleRunner $lifecycleRunner;

    private bool $lifecycleClosed = false;

    private bool $partClosed = false;

    private bool $objectClosed = false;

    private bool $collectorClosed = false;

    private bool $renewerClosed = false;

    private bool $backendClosed = false;

    private bool $readPoolClosed = false;

    private bool $notificationsClosed = false;

    private bool $encryptionClosed = false;

    private ?S3Server $serverOwner = null;

    private bool $shutdownInProgress = false;

    public function __construct(
        public readonly EfsQualificationContext $qualification,
        public readonly EfsNodeLocalScratch $scratch,
        public readonly MetadataStore $metadata,
        public readonly PostgresMetadataStore|MysqlMetadataStore $rawMetadata,
        public readonly FilesystemBackend $backend,
        public readonly StorageTierRegistry $tiers,
        public readonly LocalFencedObjectWriter $objectWriter,
        public readonly LocalFencedMultipartPartWriter $partWriter,
        public readonly LocalObjectWriteIntentCollector $objectCollector,
        public readonly StorageGarbageCollector $storageGarbageCollector,
        public readonly MultipartWriteIntentCollector $multipartIntentCollector,
        public readonly DedicatedAmpObjectWriteLeaseRenewer $objectRenewer,
        public readonly AmpLocalObjectWriteLeaseGuardFactory $objectLeaseFactory,
        public readonly ContextWorkerPool $readPool,
        public readonly LocalFencedBackgroundObjectWriter $backgroundWriter,
        public readonly TierTransitionExecutor $transitionExecutor,
        public readonly RestoreExecutor $restoreExecutor,
        public readonly RestoreGarbageCollector $restoreGarbageCollector,
        public readonly LifecycleExecutor $lifecycleExecutor,
        public readonly NotificationDispatcher $notifications,
        public readonly QuotaManager $accessLogQuotas,
        public readonly AccessLogWriter $accessLogs,
        public readonly ?EncryptionServiceInterface $accessLogEncryption,
        float $lifecycleIntervalSeconds,
        LoggerInterface $logger,
        public readonly ?S3ServerConfig $buildConfig = null,
    ) {
        if ($metadata !== $rawMetadata
            && (! $metadata instanceof ObservedMetadataStore || $metadata->innerStore() !== $rawMetadata)) {
            throw new \InvalidArgumentException('EFS candidate metadata must be the raw SQL store or its exact observed facade.');
        }
        $reader = $qualification->hostEvidenceReader();
        EfsCandidateRecoveryCapabilities::assertBackgroundReady($metadata);
        $backend->assertQualifiedEfsReadConfiguration($readPool);
        $this->notifications->assertBoundTo($metadata, $reader);
        $objectWriter->assertBoundTo($backend, $tiers->defaultTier()->name, $reader);
        $objectLeaseFactory->assertBoundTo($rawMetadata, $objectRenewer);
        $this->accessLogs->assertBoundTo(
            $metadata,
            $backend,
            $tiers->defaultTier()->name,
            $objectWriter,
            $objectLeaseFactory,
            $reader,
            $notifications,
            $accessLogQuotas,
            $accessLogEncryption,
        );
        $this->backgroundWriter->assertBoundTo($objectWriter, $objectLeaseFactory);
        $this->transitionExecutor->assertBoundTo($metadata, $tiers, $reader, $backgroundWriter);
        $this->restoreExecutor->assertBoundTo($metadata, $tiers, $reader, $backgroundWriter, $notifications);
        $this->restoreGarbageCollector->assertBoundTo($metadata, $tiers, $reader);
        $this->storageGarbageCollector->assertBoundTo($metadata, $tiers, $reader);
        $this->multipartIntentCollector->assertBoundTo(EfsCandidateRecoveryCapabilities::assertReady($metadata), $tiers, $reader);
        $this->lifecycleExecutor->assertBoundTo($metadata, $backend, $tiers, $reader);
        $this->lifecycleExecutor->assertNotificationDispatcher($notifications);
        // Construct the timer here so it cannot be paired with another executor.
        // It is deliberately not started by this inert candidate graph.
        $this->lifecycleRunner = new LifecycleRunner($lifecycleExecutor, $lifecycleIntervalSeconds, $logger);
    }

    /** Close in dependency order; a failed close can be retried. */
    public function shutdown(): void
    {
        if ($this->serverOwner !== null) {
            throw new \LogicException('EFS candidate runtime graph is owned by its server.');
        }
        $this->shutdownOwnedResources();
    }

    /** @internal Only the attached server may close this graph after request drain. */
    public function shutdownForServer(S3Server $server): void
    {
        if ($this->serverOwner !== $server) {
            throw new \LogicException('EFS candidate runtime graph has another owner.');
        }
        $this->shutdownOwnedResources();
    }

    /** @internal A direct transfer cannot strand a graph outside its server. */
    public function assertAttachableTo(S3Server $server): void
    {
        if ($this->serverOwner !== null || $this->shutdownInProgress
            || $this->buildConfig !== $server->getConfig()
            || $this->lifecycleClosed || $this->partClosed || $this->objectClosed
            || $this->collectorClosed || $this->renewerClosed || $this->backendClosed
            || $this->readPoolClosed || $this->notificationsClosed || $this->encryptionClosed
            || ! $this->readPool->isRunning()) {
            throw new \LogicException('EFS candidate runtime graph is closed, owned or configured for another server.');
        }
    }

    /** @internal A direct transfer cannot strand a graph outside its server. */
    public function transferOwnershipTo(S3Server $server): void
    {
        $this->assertAttachableTo($server);
        if (! $server->ownsQualifiedEfsCandidateGraph($this)) {
            throw new \LogicException('EFS candidate runtime graph cannot transfer ownership.');
        }
        $this->serverOwner = $server;
    }

    /** @internal Reject admission once any owned component begins closing. */
    public function assertOpenForAdmission(): void
    {
        if ($this->shutdownInProgress || $this->lifecycleClosed || $this->partClosed
            || $this->objectClosed || $this->collectorClosed || $this->renewerClosed
            || $this->backendClosed || $this->readPoolClosed || $this->notificationsClosed
            || $this->encryptionClosed || ! $this->readPool->isRunning()) {
            throw new \RuntimeException('EFS candidate runtime graph is unavailable.');
        }
    }

    private function shutdownOwnedResources(): void
    {
        if ($this->shutdownInProgress) {
            throw new \LogicException('EFS candidate shutdown is already in progress.');
        }
        $this->shutdownInProgress = true;
        try {
            $this->stopOwnedResources();
        } finally {
            $this->shutdownInProgress = false;
        }
    }

    private function stopOwnedResources(): void
    {
        if (! $this->lifecycleClosed) {
            // Never close SQL, storage or workers while an admitted sweep may
            // still run. stop() waits for completion even after its timeout.
            $this->lifecycleRunner->stop();
            $this->lifecycleClosed = true;
        }
        if (! $this->notificationsClosed) {
            $this->notifications->shutdown($this->buildConfig !== null ? $this->buildConfig->shutdownDrainTimeout : 30);
            $this->notificationsClosed = true;
        }
        $firstError = null;
        if (! $this->partClosed) {
            try {
                $this->partWriter->shutdown();
                $this->partClosed = true;
            } catch (\Throwable $error) {
                $firstError = $error;
            }
        }
        if (! $this->objectClosed) {
            try {
                $this->objectWriter->shutdown();
                $this->objectClosed = true;
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }
        if (! $this->collectorClosed) {
            try {
                $this->objectCollector->shutdown();
                $this->collectorClosed = true;
            } catch (\Throwable $error) {
                $firstError ??= $error;
            }
        }
        // A failed worker stop leaves its submissions uncertain. It may still
        // need the renewal connection, backend and read pool on retry.
        if ($firstError !== null) {
            throw $firstError;
        }
        if (! $this->renewerClosed) {
            try {
                $this->objectRenewer->close();
                $this->renewerClosed = true;
            } catch (\Throwable $error) {
                throw $error;
            }
        }
        if (! $this->backendClosed) {
            try {
                $this->backend->shutdown();
                $this->backendClosed = true;
            } catch (\Throwable $error) {
                throw $error;
            }
        }
        if (! $this->readPoolClosed) {
            $this->readPool->shutdown();
            $this->readPoolClosed = true;
        }
        if (! $this->encryptionClosed && $this->accessLogEncryption !== null && method_exists($this->accessLogEncryption, 'shutdown')) {
            $this->accessLogEncryption->shutdown();
            $this->encryptionClosed = true;
        }
    }
}
