<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler;

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
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;

final class MetricsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetricsCollector $metrics,
        private readonly ?MetadataStore $metadata = null,
        private readonly ?string $bearerToken = null,
        private readonly ?AwsS3OperationalState $awsOperationalState = null,
        private readonly ?StorageTempBudgetCoordinator $tempBudget = null,
    ) {
        if ($bearerToken !== null && trim($bearerToken) === '') {
            throw new \InvalidArgumentException('Metrics bearer token must not be empty when configured.');
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
            $this->refreshMultipartCompletionCapacity();
            $this->refreshWriteIntentCapacity();
            $this->refreshStorageCleanupCapacity();
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
        if (! $this->metadata instanceof MultipartCompletionCapacityReader) {
            return;
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
        $this->metrics->setMultipartCompletionIntentCount($count);
    }

    private function refreshWriteIntentCapacity(): void
    {
        if (! $this->metadata instanceof WriteIntentCapacityReader) {
            $this->metrics->setWriteIntentCapacityCounts(null);

            return;
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
        $this->metrics->setWriteIntentCapacityCounts($counts);
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
        if (! $this->metadata instanceof StorageCleanupCapacityReader) {
            $this->metrics->setStorageCleanupCount(null);

            return;
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
        $this->metrics->setStorageCleanupCount($count);
    }

    private function metadataIsObserved(): bool
    {
        return $this->metadata !== null
            && ObservedMetadataStore::isObservedBy($this->metadata, $this->metrics);
    }
}
