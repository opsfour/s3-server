<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Parallel;

use Amp\Cancellation;
use Amp\Parallel\Worker\Task;
use Amp\Sync\Channel;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\DatabaseCredentialProvider;

/**
 * Execute one credential database operation outside the API event loop.
 *
 * @implements Task<mixed, never, never>
 */
final class DatabaseCredentialTask implements Task
{
    /** @var array<string, DatabaseCredentialProvider> */
    private static array $providers = [];

    /**
     * @param list<mixed> $args
     */
    public function __construct(
        private readonly string $dsn,
        private readonly ?string $username,
        private readonly ?string $password,
        private readonly float $cacheTtlSeconds,
        private readonly string $method,
        private readonly array $args = [],
    ) {}

    public function run(Channel $channel, Cancellation $cancellation): mixed
    {
        $cancellation->throwIfRequested();
        $provider = self::$providers[$this->connectionKey()] ??= DatabaseCredentialProvider::fromDsn(
            $this->dsn,
            $this->username,
            $this->password,
            $this->cacheTtlSeconds,
        );

        return match ($this->method) {
            'getCredential' => $provider->getCredential($this->stringArg(0)),
            'assertCredentialStoreAvailable' => $provider->assertCredentialStoreAvailable(),
            'listCredentials' => $provider->listCredentials(),
            'putCredential' => $provider->putCredential($this->credentialArg(0)),
            'createCredentialIfAbsent' => $provider->createCredentialIfAbsent($this->credentialArg(0)),
            'deleteCredential' => $provider->deleteCredential($this->stringArg(0)),
            'deleteCredentialIfCurrent' => $provider->deleteCredentialIfCurrent($this->credentialArg(0)),
            'setActiveIfCurrent' => $provider->setActiveIfCurrent($this->credentialArg(0), $this->boolArg(1)),
            'pruneExpiredCredentials' => $provider->pruneExpiredCredentials($this->dateTimeArg(0), $this->positiveIntArg(1)),
            default => throw new \LogicException('Unsupported credential database worker operation.'),
        };
    }

    private function connectionKey(): string
    {
        return hash('sha256', serialize([
            $this->dsn,
            $this->username,
            $this->password,
            $this->cacheTtlSeconds,
        ]));
    }

    private function stringArg(int $index): string
    {
        $value = $this->args[$index] ?? null;
        if (! is_string($value)) {
            throw new \LogicException('Invalid credential database worker argument.');
        }

        return $value;
    }

    private function credentialArg(int $index): Credential
    {
        $value = $this->args[$index] ?? null;
        if (! $value instanceof Credential) {
            throw new \LogicException('Invalid credential database worker argument.');
        }

        return $value;
    }

    private function boolArg(int $index): bool
    {
        $value = $this->args[$index] ?? null;
        if (! is_bool($value)) {
            throw new \LogicException('Invalid credential database worker argument.');
        }

        return $value;
    }

    private function dateTimeArg(int $index): \DateTimeImmutable
    {
        $value = $this->args[$index] ?? null;
        if (! $value instanceof \DateTimeImmutable) {
            throw new \LogicException('Invalid credential database worker argument.');
        }

        return $value;
    }

    private function positiveIntArg(int $index): int
    {
        $value = $this->args[$index] ?? null;
        if (! is_int($value) || $value < 1) {
            throw new \LogicException('Invalid credential database worker argument.');
        }

        return $value;
    }
}
