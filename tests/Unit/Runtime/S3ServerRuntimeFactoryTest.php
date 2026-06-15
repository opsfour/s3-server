<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;
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
        self::assertNull($runtime->selectWorkerPool);
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

    public function test_runtime_stop_shuts_down_encryption_service_when_supported(): void
    {
        $encryption = new RuntimeFactoryRecordingEncryptionService();
        $runtime = $this->createRuntime(encryption: $encryption);

        $runtime->stop();

        self::assertTrue($encryption->shutdownCalled);
    }

    /**
     * @param list<array{pattern: string, listener: callable}> $notificationListeners
     */
    private function createRuntime(
        array $notificationListeners = [],
        ?EncryptionServiceInterface $encryption = null,
    ): \OpsFour\S3Server\Runtime\S3ServerRuntime {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();

        return (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(storagePath: $this->storagePath),
            metadata: $metadata,
            storage: new InMemoryBackend(),
            credentialProvider: new InMemoryCredentialProvider(
                new Credential('test-key', 'test-secret', 'test-owner'),
            ),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
            encryption: $encryption,
            notificationListeners: $notificationListeners,
        );
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
    }
}
