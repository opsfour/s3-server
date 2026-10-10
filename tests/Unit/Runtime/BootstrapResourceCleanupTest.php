<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\BootstrapResourceCleanup;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class BootstrapResourceCleanupTest extends TestCase
{
    public function test_shared_command_owned_storage_backend_is_shutdown_once(): void
    {
        $backend = new CallbackStorageBackend(new InMemoryBackend());
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $backend, defaultWriteTier: true),
            new StorageTier('COLD', $backend),
        ]);

        BootstrapResourceCleanup::release(null, $tiers);

        self::assertSame(1, $backend->shutdownCalls);
    }
}
