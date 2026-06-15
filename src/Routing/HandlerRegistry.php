<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use Amp\Http\Server\RequestHandler;
use OpsFour\S3Server\Exception\NotImplementedException;

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

    /**
     * Register a handler for an S3 operation.
     *
     * @param  S3Operation  $operation  The S3 operation to register.
     * @param  RequestHandler  $handler  The Amp request handler for this operation.
     */
    public function register(S3Operation $operation, RequestHandler $handler): void
    {
        $this->handlers[$operation->value] = $handler;
    }

    /**
     * Retrieve the handler for an S3 operation.
     *
     * @param  S3Operation  $operation  The resolved S3 operation.
     * @return RequestHandler The registered handler.
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
}
