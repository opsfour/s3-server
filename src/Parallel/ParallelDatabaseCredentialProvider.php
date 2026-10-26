<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\DeferredCancellation;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\TaskFailureThrowable;
use Amp\Parallel\Worker\WorkerPool;
use Amp\TimeoutException;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Contracts\AtomicCredentialCreator;
use OpsFour\S3Server\Contracts\AtomicCredentialDeleter;
use OpsFour\S3Server\Contracts\AtomicCredentialStatusUpdater;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Contracts\CredentialProvider;
use OpsFour\S3Server\Contracts\CredentialWorkerPoolOwner;
use OpsFour\S3Server\Contracts\AtomicExpiredCredentialPruner;
use Revolt\EventLoop;

/** Offload the synchronous PDO credential store from the API event loop. */
final class ParallelDatabaseCredentialProvider implements AtomicCredentialCreator, AtomicCredentialDeleter, AtomicCredentialStatusUpdater, CredentialHealthProbe, CredentialProvider, CredentialWorkerPoolOwner, AtomicExpiredCredentialPruner
{
    private readonly WorkerPool $pool;

    private bool $poolOwnershipTransferred = false;

    public function __construct(
        private readonly string $dsn,
        private readonly ?string $username = null,
        private readonly ?string $password = null,
        private readonly float $cacheTtlSeconds = 1.0,
        private readonly int $workerLimit = 4,
        private readonly float $operationTimeoutSeconds = 15.0,
        ?WorkerPool $pool = null,
    ) {
        if ($workerLimit < 1) {
            throw new \InvalidArgumentException('Credential database worker limit must be >= 1.');
        }
        if (! is_finite($operationTimeoutSeconds) || $operationTimeoutSeconds <= 0.0) {
            throw new \InvalidArgumentException('Credential database operation timeout must be finite and > 0.');
        }
        $this->pool = $pool ?? new ContextWorkerPool($workerLimit);

        try {
            $this->assertCredentialStoreAvailable();
        } catch (\Throwable $error) {
            try {
                $this->pool->shutdown();
            } catch (\Throwable) {
            }
            throw $error;
        }
    }

    public function transferCredentialWorkerPools(\Closure $receiver): void
    {
        if ($this->poolOwnershipTransferred) {
            throw new \LogicException('Credential database worker pool ownership was already transferred.');
        }
        $receiver($this->pool, $this->workerLimit);
        $this->poolOwnershipTransferred = true;
    }

    public function __destruct()
    {
        if ($this->poolOwnershipTransferred) {
            return;
        }
        try {
            $this->pool->shutdown();
        } catch (\Throwable) {
        }
    }

    public function getCredential(string $accessKeyId): ?Credential
    {
        $credential = $this->submit('getCredential', [$accessKeyId]);

        return $credential instanceof Credential ? $credential : null;
    }

    public function assertCredentialStoreAvailable(): void
    {
        $this->submit('assertCredentialStoreAvailable');
    }

    public function listCredentials(): array
    {
        /** @var list<Credential> */
        return $this->submit('listCredentials');
    }

    public function putCredential(Credential $credential): void
    {
        $this->submit('putCredential', [$credential]);
    }

    public function createCredentialIfAbsent(Credential $credential): bool
    {
        return (bool) $this->submit('createCredentialIfAbsent', [$credential]);
    }

    public function deleteCredential(string $accessKeyId): void
    {
        $this->submit('deleteCredential', [$accessKeyId]);
    }

    public function deleteCredentialIfCurrent(Credential $expected): bool
    {
        return (bool) $this->submit('deleteCredentialIfCurrent', [$expected]);
    }

    public function setActiveIfCurrent(Credential $expected, bool $active): bool
    {
        return (bool) $this->submit('setActiveIfCurrent', [$expected, $active]);
    }

    public function pruneExpiredCredentials(\DateTimeImmutable $now, int $limit): int
    {
        return (int) $this->submit('pruneExpiredCredentials', [$now, $limit]);
    }

    /** @param list<mixed> $args */
    private function submit(string $method, array $args = []): mixed
    {
        $timeout = new DeferredCancellation();
        $timer = EventLoop::delay($this->operationTimeoutSeconds, static function () use ($timeout): void {
            $timeout->cancel(new TimeoutException('Credential database operation timed out.'));
        });
        $cancellation = $timeout->getCancellation();
        $worker = null;
        try {
            while (! $this->pool->isIdle()) {
                \Amp\delay(0.01, cancellation: $cancellation);
            }
            $worker = $this->pool->getWorker();

            return $worker->submit(new DatabaseCredentialTask(
                $this->dsn,
                $this->username,
                $this->password,
                $this->cacheTtlSeconds,
                $method,
                $args,
            ), $cancellation)->await($cancellation);
        } catch (\Amp\CancelledException $error) {
            if ($error->getPrevious() instanceof TimeoutException) {
                $worker?->kill();
                throw new \RuntimeException('Credential database operation timed out.');
            }

            throw $error;
        } catch (TaskFailureThrowable $error) {
            $class = $error->getOriginalClassName();
            if (in_array($class, [\InvalidArgumentException::class, \UnexpectedValueException::class, \LogicException::class], true)) {
                throw new $class($error->getOriginalMessage());
            }

            // Database connection errors may contain endpoint or account data.
            throw new \RuntimeException('Credential database operation failed.');
        } catch (\Throwable) {
            throw new \RuntimeException('Credential database operation failed.');
        } finally {
            EventLoop::cancel($timer);
        }
    }
}
