<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Exception;

use OpsFour\S3Server\Observability\MetricsCollector;

/** Durable cleanup admission was rejected at its database-enforced boundary. */
final class StorageCleanupCapacityException extends S3Exception
{
    /** @var array<int, MetricsCollector> Strong references prevent object-id reuse while this failure propagates. */
    private array $metricObservers = [];

    public function __construct(string $message = 'Storage cleanup capacity is exhausted.', ?\Throwable $previous = null)
    {
        parent::__construct($message, $previous);
        $this->setExtraHeaders(['Retry-After' => '1']);
    }

    public function getErrorCode(): string
    {
        return 'SlowDown';
    }

    public function getHttpStatus(): int
    {
        return 503;
    }

    /** Claim one rejection metric per collector for this propagated failure. */
    public function markAdmissionRejectionObserved(string $admission, MetricsCollector $observer): bool
    {
        if (! in_array($admission, ['normal', 'recovery'], true)) {
            throw new \InvalidArgumentException('Unknown storage cleanup admission class.');
        }
        $observerId = spl_object_id($observer);
        if (isset($this->metricObservers[$observerId])) {
            return false;
        }
        $this->metricObservers[$observerId] = $observer;

        return true;
    }
}
