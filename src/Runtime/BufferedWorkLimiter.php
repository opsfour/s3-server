<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Sync\Lock;
use OpsFour\S3Server\Exception\SlowDownException;

/**
 * Process-wide guard for operations that must buffer a complete object.
 */
final class BufferedWorkLimiter
{
    private static bool $active = false;

    public static function acquire(): Lock
    {
        // A LocalSemaphore queues every waiting fiber without a limit or a way
        // to cancel on client disconnect. Reject contention before it can retain
        // request state or a staged object in memory.
        if (self::$active) {
            throw new SlowDownException('The server is busy processing a buffered object.');
        }
        self::$active = true;

        return new Lock(static function (): void {
            self::$active = false;
        });
    }
}
