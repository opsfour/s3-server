<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\StorageCleanupCapacityException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Revolt\EventLoop;

final class S3ServerRuntimeFactoryTest extends TestCase
{
    private string $storagePath = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->storagePath = sys_get_temp_dir() . '/s3-runtime-factory-' . bin2hex(random_bytes(4));
        mkdir($this->storagePath, 0o755, true);
    }

    protected function tearDown(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY');
        putenv('S3_ENCRYPTION_MASTER_KEYS');

        if ($this->storagePath !== '' && is_dir($this->storagePath)) {
            @rmdir($this->storagePath);
        }

        parent::tearDown();
    }

    public function test_create_wires_server_notifications_and_handlers(): void
    {
        $runtime = $this->createRuntime();

        self::assertInstanceOf(S3Server::class, $runtime->server);
        self::assertNull($runtime->encryption);
        self::assertInstanceOf(\Amp\Parallel\Worker\WorkerPool::class, $runtime->selectWorkerPool);
        self::assertTrue($runtime->server->getHandlerRegistry()->has(S3Operation::PutObject));
        self::assertTrue($runtime->server->getHandlerRegistry()->has(S3Operation::RestoreObject));
        self::assertTrue($runtime->server->getHandlerRegistry()->has(S3Operation::SelectObjectContent));
    }

    public function test_create_builds_encryption_from_environment(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('a', 32)));

        $runtime = $this->createRuntime();

        self::assertInstanceOf(EncryptionService::class, $runtime->encryption);
    }

    public function test_create_fails_closed_for_explicitly_empty_master_key_environment(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY=');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_ENCRYPTION_MASTER_KEY or S3_ENCRYPTION_MASTER_KEYS environment variable is required.');

        $this->createRuntime();
    }

    public function test_create_does_not_fall_back_from_zero_multi_key_environment(): void
    {
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('a', 32)));
        putenv('S3_ENCRYPTION_MASTER_KEYS=0');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('S3_ENCRYPTION_MASTER_KEYS must be a non-empty JSON object');

        $this->createRuntime();
    }

    public function test_create_registers_notification_listeners(): void
    {
        $events = [];
        $runtime = $this->createRuntime([
            [
                'pattern' => 's3:ObjectCreated:*',
                'listener' => static function (\OpsFour\S3Server\Event\S3Event $event) use (&$events): void {
                    $events[] = $event;
                },
            ],
        ]);

        $runtime->notifications->dispatch('s3:ObjectCreated:Put', 'bucket', 'key.txt');
        $this->runEventLoopTick();

        self::assertCount(1, $events);
        self::assertSame('s3:ObjectCreated:Put', $events[0]->name);
        self::assertSame('key.txt', $events[0]->key);
    }

    public function test_create_binds_cleanup_rejections_to_the_serving_metrics_collector(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 's3-runtime-observed-');
        self::assertIsString($databasePath);
        $store = new SqliteMetadataStore($databasePath);
        $store->initialize();
        $upstreamMetrics = new MetricsCollector();
        $servingMetrics = new MetricsCollector();
        $observed = new ObservedMetadataStore($store, $upstreamMetrics, 'sqlite');
        $runtime = $this->createRuntimeWithMetadata($observed, $servingMetrics);

        try {
            $runtimeMetadata = $this->runtimeMetadata($runtime->server);
            self::assertInstanceOf(ObservedMetadataStore::class, $runtimeMetadata);
            self::assertNotSame($observed, $runtimeMetadata);

            $pdo = new \PDO('sqlite:' . $databasePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 900000 WHERE id = 1');

            try {
                $runtimeMetadata->enqueueStorageGarbage('bucket', 'STANDARD', 'full');
                self::fail('Cleanup capacity rejection was not propagated.');
            } catch (StorageCleanupCapacityException) {
            }

            foreach ([$upstreamMetrics, $servingMetrics] as $metrics) {
                self::assertStringContainsString(
                    's3_server_storage_cleanup_admission_rejections_total{admission="normal"} 1',
                    $metrics->renderPrometheus(),
                );
            }
        } finally {
            $runtime->stop();
            @unlink($databasePath);
        }
    }

    public function test_create_does_not_bind_the_same_metrics_collector_twice(): void
    {
        $databasePath = tempnam(sys_get_temp_dir(), 's3-runtime-observed-');
        self::assertIsString($databasePath);
        $store = new SqliteMetadataStore($databasePath);
        $store->initialize();
        $metrics = new MetricsCollector();
        $observed = new ObservedMetadataStore($store, $metrics, 'sqlite');
        $runtime = $this->createRuntimeWithMetadata($observed, $metrics);

        try {
            $runtimeMetadata = $this->runtimeMetadata($runtime->server);
            self::assertSame($observed, $runtimeMetadata);

            $pdo = new \PDO('sqlite:' . $databasePath);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $pdo->exec('UPDATE s3_storage_cleanup_capacity SET retained_count = 900000 WHERE id = 1');

            try {
                $runtimeMetadata->enqueueStorageGarbage('bucket', 'STANDARD', 'full');
                self::fail('Cleanup capacity rejection was not propagated.');
            } catch (StorageCleanupCapacityException) {
            }

            $rendered = $metrics->renderPrometheus();
            self::assertStringContainsString(
                's3_server_storage_cleanup_admission_rejections_total{admission="normal"} 1',
                $rendered,
            );
            self::assertStringNotContainsString(
                's3_server_storage_cleanup_admission_rejections_total{admission="normal"} 2',
                $rendered,
            );
        } finally {
            $runtime->stop();
            @unlink($databasePath);
        }
    }

    public function test_runtime_stop_shuts_down_encryption_service_when_supported(): void
    {
        $encryption = new RuntimeFactoryRecordingEncryptionService();
        $runtime = $this->createRuntime(encryption: $encryption);

        $runtime->stop();

        self::assertTrue($encryption->shutdownCalled);
    }

    public function test_runtime_stop_releases_storage_even_before_server_start(): void
    {
        $storage = new CallbackStorageBackend(new InMemoryBackend());
        $runtime = $this->createRuntime(storage: $storage);

        $runtime->stop();
        $runtime->stop();

        self::assertSame(1, $storage->shutdownCalls);
    }

    public function test_runtime_shutdown_failure_can_be_retried_without_reporting_success(): void
    {
        $encryption = new RuntimeFactoryRecordingEncryptionService(throwOnShutdown: true);
        $runtime = $this->createRuntime(encryption: $encryption);

        for ($attempt = 1; $attempt <= 2; $attempt++) {
            try {
                $runtime->stop();
                self::fail('An incomplete encryption worker shutdown was reported as successful.');
            } catch (\RuntimeException $error) {
                self::assertSame('S3 runtime shutdown incomplete; dependencies remain owned.', $error->getMessage());
            }
        }

        self::assertSame(2, $encryption->shutdownCalls);
    }

    public function test_factory_failure_releases_transferred_runtime_resources(): void
    {
        $storage = new CallbackStorageBackend(new InMemoryBackend());
        $encryption = new RuntimeFactoryRecordingEncryptionService();

        try {
            $this->createRuntime(
                notificationListeners: [['pattern' => '', 'listener' => static function (): void {}]],
                encryption: $encryption,
                storage: $storage,
            );
            self::fail('Expected invalid notification listener configuration.');
        } catch (\InvalidArgumentException $e) {
            self::assertSame('Notification listener pattern must be a non-empty string.', $e->getMessage());
        }

        self::assertSame(1, $storage->shutdownCalls);
        self::assertSame(1, $encryption->shutdownCalls);
    }

    public function test_factory_preserves_bootstrap_error_when_runtime_cleanup_and_logger_fail(): void
    {
        $storage = new CallbackStorageBackend(new InMemoryBackend());
        $encryption = new RuntimeFactoryRecordingEncryptionService(throwOnShutdown: true);
        $logger = new class extends NullLogger {
            /** @param array<string, mixed> $context */
            public function warning(string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('Injected logger failure.');
            }
        };

        try {
            $this->createRuntime(
                notificationListeners: [['pattern' => '', 'listener' => static function (): void {}]],
                encryption: $encryption,
                storage: $storage,
                logger: $logger,
            );
            self::fail('Expected invalid notification listener configuration.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Notification listener pattern must be a non-empty string.', $error->getMessage());
        }

        self::assertSame(1, $storage->shutdownCalls);
        self::assertSame(2, $encryption->shutdownCalls);
    }

    public function test_factory_retries_transient_storage_cleanup_before_losing_runtime_owner(): void
    {
        $storageCalls = 0;
        /** @var \OpsFour\S3Server\Storage\StorageBackend&\OpsFour\S3Server\Storage\ShutdownAwareStorageBackend&\PHPUnit\Framework\MockObject\MockObject $storage */
        $storage = $this->createMockForIntersectionOfInterfaces([
            \OpsFour\S3Server\Storage\StorageBackend::class,
            \OpsFour\S3Server\Storage\ShutdownAwareStorageBackend::class,
        ]);
        $storage->method('shutdown')->willReturnCallback(static function () use (&$storageCalls): void {
            if (++$storageCalls === 1) {
                throw new \RuntimeException('transient cleanup failure');
            }
        });
        $encryption = new RuntimeFactoryRecordingEncryptionService();

        try {
            $this->createRuntime(
                notificationListeners: [['pattern' => '', 'listener' => static function (): void {}]],
                encryption: $encryption,
                storage: $storage,
            );
            self::fail('Expected invalid notification listener configuration.');
        } catch (\InvalidArgumentException $error) {
            self::assertSame('Notification listener pattern must be a non-empty string.', $error->getMessage());
        }

        self::assertSame(2, $storageCalls);
        self::assertSame(1, $encryption->shutdownCalls);
    }

    /**
     * @param list<array{pattern: string, listener: callable}> $notificationListeners
     */
    private function createRuntime(
        array $notificationListeners = [],
        ?EncryptionServiceInterface $encryption = null,
        ?\OpsFour\S3Server\Storage\StorageBackend $storage = null,
        ?LoggerInterface $logger = null,
    ): \OpsFour\S3Server\Runtime\S3ServerRuntime {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();

        return (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(storagePath: $this->storagePath),
            metadata: $metadata,
            storage: $storage ?? new InMemoryBackend(),
            credentialProvider: new InMemoryCredentialProvider(
                new Credential('test-key', 'test-secret', 'test-owner'),
            ),
            logger: $logger ?? new NullLogger(),
            metrics: new MetricsCollector(),
            encryption: $encryption,
            notificationListeners: $notificationListeners,
        );
    }

    private function createRuntimeWithMetadata(MetadataStore $metadata, MetricsCollector $metrics): \OpsFour\S3Server\Runtime\S3ServerRuntime
    {
        return (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(storagePath: $this->storagePath),
            metadata: $metadata,
            storage: new InMemoryBackend(),
            credentialProvider: new InMemoryCredentialProvider(
                new Credential('test-key', 'test-secret', 'test-owner'),
            ),
            logger: new NullLogger(),
            metrics: $metrics,
        );
    }

    private function runtimeMetadata(S3Server $server): MetadataStore
    {
        $property = new \ReflectionProperty(S3Server::class, 'metadata');
        $metadata = $property->getValue($server);
        self::assertInstanceOf(MetadataStore::class, $metadata);

        return $metadata;
    }

    private function runEventLoopTick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }
}

final class RuntimeFactoryRecordingEncryptionService implements EncryptionServiceInterface
{
    public bool $shutdownCalled = false;

    public int $shutdownCalls = 0;

    public function __construct(
        private readonly bool $throwOnShutdown = false,
    ) {}

    public function encryptSseS3(string $plaintext): array
    {
        return [
            'ciphertext' => $plaintext,
            'encryptedDataKey' => '',
            'iv' => '',
            'tag' => '',
        ];
    }

    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
    {
        return $ciphertext;
    }

    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        return [
            'ciphertext' => $plaintext,
            'iv' => '',
            'tag' => '',
        ];
    }

    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
    {
        return $ciphertext;
    }

    public function shutdown(): void
    {
        $this->shutdownCalled = true;
        $this->shutdownCalls++;
        if ($this->throwOnShutdown) {
            throw new \RuntimeException('expected shutdown failure');
        }
    }
}
