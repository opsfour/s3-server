<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerPool;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\MasterKeyProvider;
use OpsFour\S3Server\Observability\MetricsCollector;

/**
 * Async encryption service that offloads CPU-bound encrypt/decrypt
 * to worker processes for large payloads.
 *
 * Below the size threshold, operations run inline (no IPC overhead).
 * Above the threshold, operations are submitted to a worker pool.
 */
final class ParallelEncryptionService implements EncryptionServiceInterface
{
    private readonly WorkerPool $pool;

    private readonly EncryptionService $localService;

    /**
     * @param  MasterKeyProvider|null  $masterKeyProvider  For inline (below-threshold) operations.
     * @param  int  $workerLimit  Max worker processes for encryption.
     * @param  int  $sizeThreshold  Payloads below this size (bytes) run inline.
     */
    public function __construct(
        ?MasterKeyProvider $masterKeyProvider = null,
        int $workerLimit = 4,
        private readonly int $sizeThreshold = 65_536,
        private readonly ?MetricsCollector $metrics = null,
        private readonly string $poolName = 'encryption',
    ) {
        $this->pool = new ContextWorkerPool($workerLimit);
        $this->localService = new EncryptionService($masterKeyProvider);
        $this->metrics?->registerWorkerPool($this->poolName, $workerLimit);
    }

    public function encryptSseS3(string $plaintext): array
    {
        if (strlen($plaintext) < $this->sizeThreshold) {
            return $this->localService->encryptSseS3($plaintext);
        }

        return $this->submitTask('encryptSseS3', [$plaintext]);
    }

    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
    {
        if (strlen($ciphertext) < $this->sizeThreshold) {
            return $this->localService->decryptSseS3($ciphertext, $encryptedDataKeyB64, $ivB64, $tagB64);
        }

        return $this->submitTask('decryptSseS3', [$ciphertext, $encryptedDataKeyB64, $ivB64, $tagB64]);
    }

    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        if (strlen($plaintext) < $this->sizeThreshold) {
            return $this->localService->encryptSseC($plaintext, $customerKey);
        }

        return $this->submitTask('encryptSseC', [$plaintext, $customerKey]);
    }

    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
    {
        if (strlen($ciphertext) < $this->sizeThreshold) {
            return $this->localService->decryptSseC($ciphertext, $customerKey, $ivB64, $tagB64);
        }

        return $this->submitTask('decryptSseC', [$ciphertext, $customerKey, $ivB64, $tagB64]);
    }

    public function shutdown(): void
    {
        try {
            $this->pool->shutdown();
        } catch (\Throwable $e) {
            $this->metrics?->recordWorkerPoolShutdownFailure($this->poolName, $e::class);
            throw $e;
        }
    }

    /** @param list<mixed> $args */
    private function submitTask(string $method, array $args): mixed
    {
        $task = new EncryptionTask($method, $args);

        try {
            $result = $this->pool->submit($task)->await();
            $this->metrics?->recordWorkerPoolTask($this->poolName, $method);

            return $result;
        } catch (TaskFailureThrowable $e) {
            $this->metrics?->recordWorkerPoolTask($this->poolName, $method, false, $e->getOriginalClassName());
            throw new \RuntimeException(
                $e->getOriginalClassName() . ': ' . $e->getOriginalMessage(),
                0,
                $e,
            );
        } catch (\Throwable $e) {
            $this->metrics?->recordWorkerPoolTask($this->poolName, $method, false, $e::class);
            throw $e;
        }
    }
}
