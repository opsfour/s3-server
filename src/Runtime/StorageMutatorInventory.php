<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

/** Stable IDs for all enabled physical mutation entry points. */
final class StorageMutatorInventory
{
    public const string ATTEMPT = 'aws-attempt';
    public const string GARBAGE = 'aws-garbage';
    public const string SEAL = 'aws-same-key-seal';
    public const string UNSUPPORTED = 'aws-unsupported';

    /** @var array<string, string> */
    public const array REQUIRED = [
        'api.create-bucket' => self::UNSUPPORTED,
        'api.put-object' => self::ATTEMPT,
        'api.post-object' => self::ATTEMPT,
        'api.copy-object' => self::ATTEMPT,
        'api.upload-part' => self::ATTEMPT,
        'api.upload-part-copy' => self::ATTEMPT,
        'api.complete-multipart' => self::ATTEMPT,
        'api.delete-object' => self::GARBAGE,
        'api.delete-objects' => self::GARBAGE,
        'api.abort-multipart' => self::UNSUPPORTED,
        'api.delete-bucket' => self::GARBAGE,
        'worker.access-log' => self::ATTEMPT,
        'worker.tier-transition' => self::ATTEMPT,
        'worker.restore-copy' => self::ATTEMPT,
        'worker.restore-expiry' => self::GARBAGE,
        'worker.storage-garbage' => self::GARBAGE,
        'worker.same-key-seal-reconciliation' => self::SEAL,
        'worker.multipart-part-recovery' => self::UNSUPPORTED,
        'worker.multipart-completion-recovery' => self::UNSUPPORTED,
        'worker.lifecycle-multipart-abort' => self::UNSUPPORTED,
        'integration.generic-storage-mutation' => self::UNSUPPORTED,
        'integration.flysystem-worker-mutation' => self::UNSUPPORTED,
    ];

    /** @var array<string, string> */
    private array $registered = [];

    private bool $frozen = false;

    public function register(string $operationId, string $capability): void
    {
        if ($this->frozen || ! isset(self::REQUIRED[$operationId])
            || self::REQUIRED[$operationId] !== $capability || isset($this->registered[$operationId])) {
            throw new \LogicException('Storage mutator registration is unknown, duplicate, or has the wrong capability.');
        }
        $this->registered[$operationId] = $capability;
    }

    public function freeze(): void
    {
        if ($this->frozen || $this->registered != self::REQUIRED) {
            throw new \LogicException('Storage mutator inventory is incomplete or already frozen.');
        }
        $this->frozen = true;
    }

    /** The closed inventory is checked before any background workers are started. */
    /** @param iterable<array{id: string, capability: string}> $registrations */
    public static function forEnabledRuntime(iterable $registrations): self
    {
        $inventory = new self();
        foreach ($registrations as $registration) {
            $inventory->register($registration['id'], $registration['capability']);
        }
        $inventory->freeze();

        return $inventory;
    }

    public function assertAwsCapability(string $operationId): void
    {
        if (! $this->frozen || ! isset($this->registered[$operationId])
            || $this->registered[$operationId] === self::UNSUPPORTED) {
            throw new \LogicException('AWS storage mutation has no registered qualified capability.');
        }
    }

    public function isComplete(): bool
    {
        return $this->frozen && $this->registered == self::REQUIRED;
    }
}
