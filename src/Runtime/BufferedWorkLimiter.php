<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use Amp\Sync\LocalSemaphore;
use Amp\Sync\Lock;

/**
 * Process-wide guard for operations that must buffer a complete object.
 */
final class BufferedWorkLimiter
{
    private static ?LocalSemaphore $semaphore = null;

    public static function acquire(): Lock
    {
        self::$semaphore ??= new LocalSemaphore(1);

        return self::$semaphore->acquire();
    }
}
