<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Cancellation;
use OpsFour\S3Server\Metadata\MetadataStore;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

final readonly class StorageGarbageCollector
{
    public function __construct(
        private MetadataStore $metadata,
        private StorageTierRegistry $tiers,
        private LoggerInterface $logger = new NullLogger(),
    ) {}

    public function collect(int $limit = 100, ?Cancellation $cancellation = null): int
    {
        $completed = 0;
        $processed = 0;
        $limit = max(1, $limit);

        while ($processed < $limit) {
            $cancellation?->throwIfRequested();
            $items = $this->metadata->dequeueStorageGarbage(1);
            if ($items === []) {
                break;
            }

            $item = $items[0];
            $processed++;
            try {
                $this->tiers->tier($item['storage_tier'])->backend
                    ->deleteObjectByPath($item['storage_path'], $item['bucket']);
                $this->metadata->completeStorageGarbage($item['id']);
                $completed++;
            } catch (\Throwable $e) {
                $attempts = $item['attempts'] + 1;
                $this->metadata->retryStorageGarbage(
                    $item['id'],
                    $e->getMessage(),
                    microtime(true) + min(300, 2 ** min($attempts, 8)),
                );
                $this->logger->warning('Physical storage garbage deletion failed and will be retried.', [
                    'component' => 'storage_gc',
                    'bucket' => $item['bucket'],
                    'storage_tier' => $item['storage_tier'],
                    'storage_path' => $item['storage_path'],
                    'attempts' => $attempts,
                    'exception' => $e::class,
                    'error' => $e->getMessage(),
                ]);
            }
            $cancellation?->throwIfRequested();
        }

        return $completed;
    }
}
