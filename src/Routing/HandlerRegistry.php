<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\RequestHandler;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundWriterConsumer;

/**
 * Maps S3Operation enum cases to Amp RequestHandler instances.
 *
 * The S3Router uses this registry to look up the handler for a resolved
 * operation. Handlers are registered by operation enum case and retrieved
 * in O(1) via array lookup on the operation value.
 */
final class HandlerRegistry
{
    /** @var array<string, RequestHandler> */
    private array $handlers = [];

    /** @var list<array{storage: StorageBackend, tiers: StorageTierRegistry}> */
    private array $storageRegistrations = [];

    private ?object $freezeToken = null;

    /**
     * Register a handler for an S3 operation.
     *
     * @param  S3Operation  $operation  The S3 operation to register.
     * @param  RequestHandler  $handler  The Amp request handler for this operation.
     */
    public function register(S3Operation $operation, RequestHandler $handler): void
    {
        if ($this->freezeToken !== null) {
            throw new \LogicException('S3 handlers cannot be changed after the server starts.');
        }

        $this->handlers[$operation->value] = $handler;
    }

    public function registerStorage(StorageBackend $storage, StorageTierRegistry $tiers): void
    {
        if ($this->freezeToken !== null) {
            throw new \LogicException('S3 handler storage cannot be changed after the server starts.');
        }

        $this->storageRegistrations[] = ['storage' => $storage, 'tiers' => $tiers];
    }

    /** @return list<array{storage: StorageBackend, tiers: StorageTierRegistry}> */
    public function storageRegistrations(): array
    {
        return $this->storageRegistrations;
    }

    public function freeze(): object
    {
        if ($this->freezeToken !== null) {
            throw new \LogicException('S3 handler registry is already frozen.');
        }

        return $this->freezeToken = new \stdClass();
    }

    /** Roll back only the freeze created by a failed startup attempt. */
    public function rollbackFreeze(object $token): void
    {
        if ($this->freezeToken !== $token) {
            throw new \LogicException('Invalid S3 handler registry startup token.');
        }

        $this->freezeToken = null;
    }

    /**
     * Retrieve the handler for an S3 operation.
     *
     * @param  S3Operation  $operation  The resolved S3 operation.
     * @return RequestHandler The registered handler.
     * @phpstan-impure
     *
     * @throws NotImplementedException If no handler is registered for the operation.
     */
    public function get(S3Operation $operation): RequestHandler
    {
        return $this->handlers[$operation->value]
            ?? throw new NotImplementedException(
                "Operation {$operation->value} is not implemented",
            );
    }

    /**
     * Check whether a handler is registered for the given operation.
     *
     * @param  S3Operation  $operation  The S3 operation to check.
     * @return bool True if a handler exists, false otherwise.
     */
    public function has(S3Operation $operation): bool
    {
        return isset($this->handlers[$operation->value]);
    }

    /** Prove that every registered AWS publication endpoint uses one exact writer. */
    public function assertAwsS3ForegroundWriterCoverage(AwsS3ForegroundObjectWriter $writer): void
    {
        foreach ([
            S3Operation::PutObject,
            S3Operation::PostObject,
            S3Operation::CopyObject,
            S3Operation::CreateMultipartUpload,
            S3Operation::UploadPart,
            S3Operation::UploadPartCopy,
            S3Operation::CompleteMultipartUpload,
        ] as $operation) {
            $handler = $this->handlers[$operation->value] ?? null;
            if (! $handler instanceof AwsS3ForegroundWriterConsumer
                || ! $handler->usesAwsS3ForegroundObjectWriter($writer)) {
                throw new \LogicException(sprintf(
                    'AWS publication handler %s is missing or does not use the exact qualified writer.',
                    $operation->value,
                ));
            }
        }
    }
}
