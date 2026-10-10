<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ShutdownAwareStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Symfony\Command\S3ServerServeCommand;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class SymfonyServeShutdownTest extends TestCase
{
    public function test_runtime_and_shutdown_failures_return_failure_without_dependency_secret(): void
    {
        $occupied = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($occupied);
        $address = stream_socket_get_name($occupied, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);

        /** @var StorageBackend&ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([StorageBackend::class, ShutdownAwareStorageBackend::class]);
        $storage->method('shutdown')->willThrowException(new \RuntimeException('private-storage-secret'));
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $command = new S3ServerServeCommand(
            new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: sys_get_temp_dir(),
                allowUnfencedStorageForTests: true,
                notificationWorkerEnabled: false,
            ),
            $metadata,
            $storage,
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            new MetricsCollector(),
            StorageTierRegistry::single($storage),
            new S3ServerRuntimeFactory(),
        );

        try {
            $tester = new CommandTester($command);
            $exit = $tester->execute([]);
            self::assertSame(Command::FAILURE, $exit);
            self::assertStringContainsString('runtime failed', $tester->getDisplay());
            self::assertStringContainsString('shutdown failed', $tester->getDisplay());
            self::assertStringNotContainsString('private-storage-secret', $tester->getDisplay());
        } finally {
            fclose($occupied);
        }
    }
}
