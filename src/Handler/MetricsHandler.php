<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

use Amp\Future;
use Amp\TimeoutCancellation;
use Amp\TimeoutException;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartCompletionCapacityReader;
use OpsFour\S3Server\Metadata\WriteIntentCapacityReader;
use OpsFour\S3Server\Metadata\StorageCleanupCapacityReader;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeRegistry;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Runtime\ClusterOperationalSnapshot;
use OpsFour\S3Server\Runtime\ClusterRoleController;

use function Amp\async;

final class MetricsHandler implements RequestHandler
{
    /**
     * @var Future<array{
     *     multipart: ?int,
     *     write: array{object: int, object_seal: int, part: int}|null,
     *     cleanup: ?int,
     *     snapshot: ClusterOperationalSnapshot
     * }>|null
     */
    private ?Future $clusterMetricsInFlight = null;

    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly ?MetadataStore $metadata = null,
        private readonly ?string $bearerToken = null,
        private readonly AwsS3OperationalState|AwsS3TierRuntimeRegistry|null $awsOperationalState = null,
        private readonly ?StorageTempBudgetCoordinator $tempBudget = null,
        private readonly ?ClusterRoleController $clusterRoles = null,
        private readonly float $clusterSnapshotTimeoutSeconds = 5.0,
    ) {
        if ($bearerToken !== null && trim($bearerToken) === '') {
            throw new \InvalidArgumentException('Metrics bearer token must not be empty when configured.');
        }
        if (! is_finite($clusterSnapshotTimeoutSeconds) || $clusterSnapshotTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Cluster metrics snapshot timeout must be finite and positive.');
        }
    }

    public function handleRequest(Request $request): Response
    {
        if ($this->bearerToken !== null && $this->bearerToken !== '') {
            $headers = $request->getHeaderArray('authorization');
            $authorization = count($headers) === 1 ? $headers[0] : '';
            $provided = str_starts_with(strtolower($authorization), 'bearer ')
                ? trim(substr($authorization, 7))
                : '';
            if (! hash_equals($this->bearerToken, $provided)) {
                return new Response(
                    status: 401,
                    headers: [
                        'Content-Type' => 'text/plain; charset=utf-8',
                        'Cache-Control' => 'no-store',
                        'WWW-Authenticate' => 'Bearer',
                    ],
                    body: 'Unauthorized',
                );
            }
        }

        if ($this->metadata !== null) {
            if ($this->clusterRoles === null) {
                if ($this->metadataIsObserved()) {
                    $this->metrics->setNotificationQueueStats($this->metadata->getNotificationQueueStats());
                } else {
                    $startedAt = hrtime(true);
                    try {
                        $this->metrics->setNotificationQueueStats($this->metadata->getNotificationQueueStats());
                        $this->metrics->recordBackendOperation(
                            backend: 'metadata',
                            driver: $this->metadataDriver(),
                            operation: 'getNotificationQueueStats',
                            durationNs: hrtime(true) - $startedAt,
                        );
                    } catch (\Throwable $e) {
                        $this->metrics->recordBackendOperation(
                            backend: 'metadata',
                            driver: $this->metadataDriver(),
                            operation: 'getNotificationQueueStats',
                            durationNs: hrtime(true) - $startedAt,
                            success: false,
                            exception: $e::class,
                        );

                        throw $e;
                    }
                }
            }
            if ($this->clusterRoles === null) {
                $this->refreshMultipartCompletionCapacity();
                $this->refreshWriteIntentCapacity();
                $this->refreshStorageCleanupCapacity();
            }
        }
        if ($this->clusterRoles !== null) {
            $this->refreshClusterMetrics();
        }
        if ($this->awsOperationalState !== null) {
            $this->metrics->setAwsOperationalSnapshot($this->awsOperationalState->snapshot());
        }
        if ($this->tempBudget !== null) {
            try {
                $this->metrics->setStorageTempCapacity($this->tempBudget->snapshot());
                $this->metrics->setStorageTempCapacitySnapshotSuccess(true);
            } catch (\Amp\CancelledException $error) {
                throw $error;
            } catch (\Throwable) {
                $this->metrics->setStorageTempCapacity(null);
                $this->metrics->setStorageTempCapacitySnapshotSuccess(false);
            }
        }

        return $this->response();
    }

    private function refreshClusterMetrics(): void
    {
        $future = $this->clusterMetricsInFlight;
        if ($future === null) {
            $future = $this->clusterMetricsInFlight = async($this->collectClusterMetrics(...));
        }

        try {
            $result = $future->await(new TimeoutCancellation($this->clusterSnapshotTimeoutSeconds));
        } catch (\Amp\CancelledException $error) {
            if (! $error->getPrevious() instanceof TimeoutException) {
                throw $error;
            }
            $future->ignore();
            $this->clearClusterDatabaseSamples();

            return;
        } catch (\Throwable) {
            $this->clearClusterDatabaseSamples();

            return;
        } finally {
            if ($future->isComplete() && $this->clusterMetricsInFlight === $future) {
                $this->clusterMetricsInFlight = null;
            }
        }

        foreach ([
            fn() => $this->metrics->setMultipartCompletionIntentCount($result['multipart']),
            fn() => $this->metrics->setWriteIntentCapacityCounts($result['write']),
            fn() => $this->metrics->setStorageCleanupCount($result['cleanup']),
        ] as $index => $apply) {
            try {
                $apply();
            } catch (\Throwable) {
                match ($index) {
                    0 => $this->metrics->setMultipartCompletionIntentCount(null),
                    1 => $this->metrics->setWriteIntentCapacityCounts(null),
                    2 => $this->metrics->setStorageCleanupCount(null),
                };
            }
        }

        try {
            $this->metrics->setClusterOperationalSnapshot($result['snapshot']);
            $this->metrics->setClusterSnapshotSuccess(true);
        } catch (\Throwable) {
            $this->clearClusterDatabaseSamples();
        }
    }

    /**
     * @return array{
     *     multipart: ?int,
     *     write: array{object: int, object_seal: int, part: int}|null,
     *     cleanup: ?int,
     *     snapshot: ClusterOperationalSnapshot
     * }
     */
    private function collectClusterMetrics(): array
    {
        $multipart = null;
        $write = null;
        $cleanup = null;
        try {
            $multipart = $this->readMultipartCompletionCapacity();
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // An unavailable optional capacity sample must not hide the
            // remaining capacities or the operational snapshot.
        }
        try {
            $write = $this->readWriteIntentCapacity();
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // See above.
        }
        try {
            $cleanup = $this->readStorageCleanupCapacity();
        } catch (\Amp\CancelledException $error) {
            throw $error;
        } catch (\Throwable) {
            // See above.
        }

        return [
            'multipart' => $multipart,
            'write' => $write,
            'cleanup' => $cleanup,
            'snapshot' => $this->clusterRoles?->operationalSnapshot()
                ?? throw new \LogicException('Cluster metrics require a role controller.'),
        ];
    }

    private function clearClusterDatabaseSamples(): void
    {
        $this->metrics->setMultipartCompletionIntentCount(null);
        $this->metrics->setWriteIntentCapacityCounts(null);
        $this->metrics->setStorageCleanupCount(null);
        $this->metrics->setClusterOperationalSnapshot(null);
        $this->metrics->setClusterSnapshotSuccess(false);
    }

    private function response(): Response
    {
        return new Response(
            status: 200,
            headers: [
                'Content-Type' => 'text/plain; version=0.0.4; charset=utf-8',
                'Cache-Control' => 'no-store',
            ],
            body: $this->metrics->renderPrometheus(),
        );
    }

    private function refreshMultipartCompletionCapacity(): void
    {
        $this->metrics->setMultipartCompletionIntentCount($this->readMultipartCompletionCapacity());
    }

    private function readMultipartCompletionCapacity(): ?int
    {
        if (! $this->metadata instanceof MultipartCompletionCapacityReader) {
            return null;
        }
        if ($this->metadataIsObserved()) {
            $count = $this->metadata->getMultipartCompletionIntentCount();
        } else {
            $startedAt = hrtime(true);
            try {
                $count = $this->metadata->getMultipartCompletionIntentCount();
                $this->metrics->recordBackendOperation(
                    backend: 'metadata',
                    driver: $this->metadataDriver(),
                    operation: 'getMultipartCompletionIntentCount',
                    durationNs: hrtime(true) - $startedAt,
                );
            } catch (\Throwable $e) {
                $this->metrics->recordBackendOperation(
                    backend: 'metadata',
                    driver: $this->metadataDriver(),
                    operation: 'getMultipartCompletionIntentCount',
                    durationNs: hrtime(true) - $startedAt,
                    success: false,
                    exception: $e::class,
                );

                throw $e;
            }
        }

        return $count;
    }

    private function refreshWriteIntentCapacity(): void
    {
        $this->metrics->setWriteIntentCapacityCounts($this->readWriteIntentCapacity());
    }

    /** @return array{object: int, object_seal: int, part: int}|null */
    private function readWriteIntentCapacity(): ?array
    {
        if (! $this->metadata instanceof WriteIntentCapacityReader) {
            return null;
        }
        if ($this->metadataIsObserved()) {
            $counts = $this->metadata->getWriteIntentCapacityCounts();
        } else {
            $startedAt = hrtime(true);
            try {
                $counts = $this->metadata->getWriteIntentCapacityCounts();
                $this->metrics->recordBackendOperation(
                    backend: 'metadata',
                    driver: $this->metadataDriver(),
                    operation: 'getWriteIntentCapacityCounts',
                    durationNs: hrtime(true) - $startedAt,
                );
            } catch (\Throwable $e) {
                $this->metrics->recordBackendOperation(
                    backend: 'metadata',
                    driver: $this->metadataDriver(),
                    operation: 'getWriteIntentCapacityCounts',
                    durationNs: hrtime(true) - $startedAt,
                    success: false,
                    exception: $e::class,
                );

                throw $e;
            }
        }

        return $counts;
    }

    private function metadataDriver(): string
    {
        if ($this->metadata === null) {
            return 'none';
        }

        $parts = explode('\\', $this->metadata::class);

        return $parts[array_key_last($parts)] ?? $this->metadata::class;
    }

    private function refreshStorageCleanupCapacity(): void
    {
        $this->metrics->setStorageCleanupCount($this->readStorageCleanupCapacity());
    }

    private function readStorageCleanupCapacity(): ?int
    {
        if (! $this->metadata instanceof StorageCleanupCapacityReader) {
            return null;
        }
        if ($this->metadataIsObserved()) {
            $count = $this->metadata->getStorageCleanupCount();
        } else {
            $startedAt = hrtime(true);
            try {
                $count = $this->metadata->getStorageCleanupCount();
                $this->metrics->recordBackendOperation('metadata', $this->metadataDriver(), 'getStorageCleanupCount', hrtime(true) - $startedAt);
            } catch (\Throwable $error) {
                $this->metrics->recordBackendOperation('metadata', $this->metadataDriver(), 'getStorageCleanupCount', hrtime(true) - $startedAt, false, exception: $error::class);
                throw $error;
            }
        }

        return $count;
    }

    private function metadataIsObserved(): bool
    {
        return $this->metadata !== null
            && ObservedMetadataStore::isObservedBy($this->metadata, $this->metrics);
    }
}
