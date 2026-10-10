<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Runtime\S3ServerRuntime;
use OpsFour\S3Server\Runtime\ServeRuntimeShutdown;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class ServeRuntimeShutdownTest extends TestCase
{
    public function test_failed_shutdown_returns_safe_cli_message_and_keeps_retryable_resources(): void
    {
        $calls = 0;
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->method('shutdown')->willReturnCallback(static function () use (&$calls): void {
            if (++$calls === 1) {
                throw new \RuntimeException('private-storage-secret');
            }
        });
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                if ((string) $message === 'S3 server shutdown failed.') {
                    throw new \RuntimeException('private-logger-secret');
                }
            }
        };
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $runtime = new S3ServerRuntime(
            new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), storage: $storage),
            new NotificationDispatcher($metadata),
        );

        self::assertNull(ServeRuntimeShutdown::stop($runtime, $logger));
        self::assertSame(2, $calls);
    }

    public function test_persistent_shutdown_failure_is_sanitized_after_one_retry(): void
    {
        $calls = 0;
        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->method('shutdown')->willReturnCallback(static function () use (&$calls): void {
            ++$calls;
            throw new \RuntimeException('private-storage-secret');
        });
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $runtime = new S3ServerRuntime(
            new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()), storage: $storage),
            new NotificationDispatcher($metadata),
        );

        $message = ServeRuntimeShutdown::stop($runtime, new \Psr\Log\NullLogger());

        self::assertSame('S3 server shutdown failed (RuntimeException).', $message);
        self::assertStringNotContainsString('private-', $message);
        self::assertSame(2, $calls);
    }
}
