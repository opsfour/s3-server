<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Runtime\BootstrapResourceCleanup;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;

final class BootstrapResourceCleanupTest extends TestCase
{
    public function test_observed_command_owned_storage_backend_is_shutdown_once(): void
    {
        $backend = new CallbackStorageBackend(new InMemoryBackend());
        $observed = new ObservedStorageBackend($backend, new MetricsCollector(), 'memory');
        $tiers = StorageTierRegistry::single($observed);

        BootstrapResourceCleanup::release(null, $tiers);

        self::assertSame(1, $backend->shutdownCalls);
    }
}
