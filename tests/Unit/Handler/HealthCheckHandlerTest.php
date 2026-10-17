<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\CancelledException;
use Amp\File\Filesystem;
use Amp\File\FilesystemDriver;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use League\Uri\Http;
use OpsFour\S3Server\Contracts\CredentialHealthProbe;
use OpsFour\S3Server\Handler\HealthCheckHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class HealthCheckHandlerTest extends TestCase
{
    public function test_liveness_without_dependencies_is_ok(): void
    {
        $response = (new HealthCheckHandler())->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
        self::assertSame('{"status":"ok"}', $response->getBody()->read());
    }

    public function test_readiness_probes_storage_without_writing(): void
    {
        $storage = new CallbackStorageBackend(new InMemoryBackend());
        $handler = new HealthCheckHandler(
            storageTiers: StorageTierRegistry::single($storage),
        );

        $response = $handler->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
        self::assertNull($storage->lastWrite);
    }

    public function test_readiness_is_degraded_when_storage_probe_fails(): void
    {
        $logger = new HealthCheckArrayLogger();
        $storage = new CallbackStorageBackend(
            new InMemoryBackend(),
            beforeBucketExists: static function (): never {
                throw new \RuntimeException('storage unavailable');
            },
        );
        $handler = new HealthCheckHandler(
            logger: $logger,
            storageTiers: StorageTierRegistry::single($storage),
        );

        $response = $handler->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
        self::assertSame('{"status":"degraded"}', $response->getBody()->read());
        self::assertSame('storage:STANDARD', $logger->records[0]['context']['component']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
    }

    public function test_storage_probe_propagates_the_original_cancellation(): void
    {
        $cancellation = new CancelledException();
        $logger = new HealthCheckArrayLogger();
        $storage = new CallbackStorageBackend(
            new InMemoryBackend(),
            beforeBucketExists: static function () use ($cancellation): never {
                throw $cancellation;
            },
        );
        $handler = new HealthCheckHandler(
            logger: $logger,
            storageTiers: StorageTierRegistry::single($storage),
        );

        try {
            $handler->handleRequest($this->request());
            self::fail('Expected readiness cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
        self::assertSame([], $logger->records);
    }

    public function test_filesystem_probe_remains_active_without_evidence(): void
    {
        $probeCount = 0;
        $storage = $this->filesystemProbeBackend($probeCount);
        $response = (new HealthCheckHandler(storageTiers: StorageTierRegistry::single($storage)))
            ->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame(1, $probeCount);
    }

    public function test_local_filesystem_readiness_detects_read_only_root_and_recovers(): void
    {
        $this->assertLocalFilesystemWriteabilityReadiness(false);
    }

    public function test_observed_local_filesystem_readiness_detects_read_only_root_and_recovers(): void
    {
        $this->assertLocalFilesystemWriteabilityReadiness(true);
    }

    public function test_readiness_is_degraded_when_metadata_probe_fails(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException(new \RuntimeException('metadata unavailable: password=private-test-token'));
        $logger = new HealthCheckArrayLogger();

        $response = (new HealthCheckHandler(metadata: $metadata, logger: $logger))
            ->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame('{"status":"degraded"}', $response->getBody()->read());
        self::assertSame('metadata', $logger->records[0]['context']['component']);
        self::assertStringNotContainsString('private-test-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_metadata_probe_propagates_the_original_cancellation(): void
    {
        $cancellation = new CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException($cancellation);
        $logger = new HealthCheckArrayLogger();

        try {
            (new HealthCheckHandler(metadata: $metadata, logger: $logger))->handleRequest($this->request());
            self::fail('Expected readiness cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
        self::assertSame([], $logger->records);
    }

    public function test_throwing_logger_cannot_replace_a_degraded_readiness_response(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException(new \RuntimeException('metadata unavailable'));
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };

        $response = (new HealthCheckHandler(metadata: $metadata, logger: $logger))
            ->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame('{"status":"degraded"}', $response->getBody()->read());
    }

    public function test_readiness_degrades_when_credential_store_probe_fails_while_liveness_stays_ok(): void
    {
        $logger = new HealthCheckArrayLogger();
        $probe = new HealthCheckCredentialProbe();
        $handler = new HealthCheckHandler(logger: $logger, credentialHealthProbe: $probe);

        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());

        $probe->failure = new \RuntimeException('credential database unavailable: password=private-test-token');
        $response = $handler->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame('{"status":"degraded"}', $response->getBody()->read());
        self::assertSame('credentials', $logger->records[0]['context']['component']);
        self::assertStringNotContainsString('private-test-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertSame(200, (new HealthCheckHandler())->handleRequest($this->request())->getStatus());

        $probe->failure = null;
        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(3, $probe->checks);
    }

    public function test_credential_probe_propagates_the_original_cancellation(): void
    {
        $cancellation = new CancelledException();
        $probe = new HealthCheckCredentialProbe();
        $probe->failure = $cancellation;
        $logger = new HealthCheckArrayLogger();

        try {
            (new HealthCheckHandler(logger: $logger, credentialHealthProbe: $probe))
                ->handleRequest($this->request());
            self::fail('Expected readiness cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }
        self::assertSame([], $logger->records);
    }

    public function test_readiness_degrades_when_configured_rate_limit_query_fails(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::exactly(2))->method('getBucket')->willReturn(null);
        $metadata->expects(self::once())->method('rateLimitCheck')
            ->with('__rate_limit_health__', 1.0, 1.0)
            ->willThrowException(new \RuntimeException('s3_rate_limit_buckets missing: secret'));
        $logger = new HealthCheckArrayLogger();

        $handler = new HealthCheckHandler(metadata: $metadata, logger: $logger, rateLimitEnabled: true);
        $response = $handler->handleRequest($this->request());

        self::assertSame(503, $response->getStatus());
        self::assertSame('{"status":"degraded"}', $response->getBody()->read());
        self::assertSame('rate_limit', $logger->records[0]['context']['component']);
        self::assertStringNotContainsString('secret', json_encode($logger->records, JSON_THROW_ON_ERROR));
        self::assertSame(503, $handler->handleRequest($this->request())->getStatus());
        self::assertCount(1, $logger->records);
    }

    public function test_readiness_accepts_a_regular_rate_limit_denial_as_healthy(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getBucket')->willReturn(null);
        $metadata->expects(self::once())->method('rateLimitCheck')->willReturn(false);

        $response = (new HealthCheckHandler(metadata: $metadata, rateLimitEnabled: true))
            ->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
    }

    public function test_readiness_does_not_query_rate_limit_when_disabled(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('getBucket')->willReturn(null);
        $metadata->expects(self::never())->method('rateLimitCheck');

        $response = (new HealthCheckHandler(metadata: $metadata, rateLimitEnabled: false))
            ->handleRequest($this->request());

        self::assertSame(200, $response->getStatus());
    }

    public function test_readiness_retries_failed_rate_limit_probe_and_recovers(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $calls = 0;
        $metadata->method('rateLimitCheck')->willReturnCallback(static function () use (&$calls): bool {
            if (++$calls === 1) {
                throw new \RuntimeException('temporary rate-limit failure');
            }

            return true;
        });
        $handler = new HealthCheckHandler(metadata: $metadata, rateLimitEnabled: true, rateLimitCheckIntervalSeconds: 0);

        self::assertSame(503, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(2, $calls);
    }

    public function test_rate_limit_cancellation_does_not_poison_or_throttle_the_health_cache(): void
    {
        $cancellation = new CancelledException();
        $metadata = $this->createMock(MetadataStore::class);
        $calls = 0;
        $metadata->method('rateLimitCheck')->willReturnCallback(static function () use (&$calls, $cancellation): bool {
            if (++$calls === 1) {
                throw $cancellation;
            }

            return true;
        });
        $logger = new HealthCheckArrayLogger();
        $handler = new HealthCheckHandler(metadata: $metadata, logger: $logger, rateLimitEnabled: true);

        try {
            $handler->handleRequest($this->request());
            self::fail('Expected readiness cancellation.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }

        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(2, $calls);
        self::assertSame([], $logger->records);
    }

    public function test_readiness_detects_and_recovers_from_post_start_tier_drift(): void
    {
        $references = [];
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('listReferencedStorageTiers')->willReturnCallback(static function () use (&$references): array {
            return $references;
        });
        $logger = new HealthCheckArrayLogger();
        $handler = new HealthCheckHandler(
            metadata: $metadata,
            logger: $logger,
            storageTiers: StorageTierRegistry::single(new InMemoryBackend()),
            tierReferenceCheckIntervalSeconds: 0,
        );

        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        $references = ['GLACIER'];
        self::assertSame(503, $handler->handleRequest($this->request())->getStatus());
        self::assertSame('storage:GLACIER', $logger->records[0]['context']['component']);
        $references = [];
        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
    }

    public function test_readiness_degrades_when_tier_reference_query_fails(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('listReferencedStorageTiers')->willThrowException(new \RuntimeException('query failed'));
        $logger = new HealthCheckArrayLogger();
        $handler = new HealthCheckHandler(
            metadata: $metadata,
            logger: $logger,
            storageTiers: StorageTierRegistry::single(new InMemoryBackend()),
        );

        self::assertSame(503, $handler->handleRequest($this->request())->getStatus());
        self::assertSame('metadata', $logger->records[0]['context']['component']);
    }

    public function test_readiness_caches_tier_reference_scan_between_probes(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::once())->method('listReferencedStorageTiers')->willReturn([]);
        $handler = new HealthCheckHandler(
            metadata: $metadata,
            storageTiers: StorageTierRegistry::single(new InMemoryBackend()),
        );

        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
    }

    public function test_readiness_detects_new_persisted_tier_on_shared_metadata(): void
    {
        $path = sys_get_temp_dir() . '/s3-health-tier-' . bin2hex(random_bytes(5)) . '.sqlite';
        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $handler = new HealthCheckHandler(
                metadata: $metadata,
                storageTiers: StorageTierRegistry::single(new InMemoryBackend()),
                tierReferenceCheckIntervalSeconds: 0,
            );

            self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
            $metadata->enqueueStorageGarbage('bucket', 'GLACIER', 'old-part');
            self::assertSame(503, $handler->handleRequest($this->request())->getStatus());
            $metadata->discardStorageGarbage('bucket', 'GLACIER', 'old-part');
            self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /** @phpstan-impure */
    private function request(): Request
    {
        return new Request(
            new HealthCheckHandlerTestClient(),
            'GET',
            Http::new('http://127.0.0.1/.health'),
        );
    }

    private function filesystemProbeBackend(int &$probeCount): FilesystemBackend
    {
        $driver = $this->createMock(FilesystemDriver::class);
        $driver->method('getStatus')->willReturnCallback(static function (string $_path) use (&$probeCount): never {
            $probeCount++;
            throw new \RuntimeException('A filesystem probe could block indefinitely.');
        });
        $filesystem = new Filesystem($driver);

        return new FilesystemBackend('/unused-health-probe-root', $filesystem, $filesystem);
    }

    private function assertLocalFilesystemWriteabilityReadiness(bool $observed): void
    {
        $root = sys_get_temp_dir() . '/s3-health-write-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        try {
            $backend = new FilesystemBackend($root);
            $storage = $observed
                ? new ObservedStorageBackend($backend, new MetricsCollector(), 'filesystem')
                : $backend;
            $handler = new HealthCheckHandler(storageTiers: StorageTierRegistry::single($storage));

            self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
            self::assertTrue(chmod($root, 0500));
            self::assertSame(503, $handler->handleRequest($this->request())->getStatus());
            self::assertTrue(chmod($root, 0700));
            self::assertSame(200, $handler->handleRequest($this->request())->getStatus());
        } finally {
            @chmod($root, 0700);
            @rmdir($root);
        }
    }
}

final class HealthCheckHandlerTestClient implements Client
{
    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return null;
    }

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}

final class HealthCheckCredentialProbe implements CredentialHealthProbe
{
    public int $checks = 0;

    public ?\Throwable $failure = null;

    public function assertCredentialStoreAvailable(): void
    {
        $this->checks++;
        if ($this->failure !== null) {
            throw $this->failure;
        }
    }
}

final class HealthCheckArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }
}
