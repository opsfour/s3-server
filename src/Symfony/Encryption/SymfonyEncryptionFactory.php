<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Symfony\Encryption;

use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelEncryptionService;
use OpsFour\S3Server\S3ServerConfig;

final class SymfonyEncryptionFactory
{
    public function create(
        S3ServerConfig $config,
        MetricsCollector $metrics,
        ?EncryptionServiceInterface $service = null,
        ?MasterKeyProvider $masterKeyProvider = null,
    ): ?EncryptionServiceInterface {
        if ($service !== null) {
            return $service;
        }

        if ($masterKeyProvider === null) {
            return null;
        }

        if ($config->encryptionWorkerPoolSize > 0) {
            return new ParallelEncryptionService(
                $masterKeyProvider,
                $config->encryptionWorkerPoolSize,
                $config->encryptionParallelThreshold,
                $metrics,
            );
        }

        return new EncryptionService($masterKeyProvider);
    }
}
