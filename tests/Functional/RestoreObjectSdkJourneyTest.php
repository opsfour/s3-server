<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream;
use Amp\ByteStream\ReadableBuffer;
use Amp\Parallel\Worker\ContextWorkerPool;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\RestoreObjectSdkJourneyTask;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

final class RestoreObjectSdkJourneyTest extends TestCase
{
    public function test_sdk_restore_completes_and_serves_the_temporary_hot_copy(): void
    {
        $bucket = 'restore-sdk';
        $key = 'archive.bin';
        $payload = 'cold-object-payload';
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', $bucket, 'us-east-1');
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket($bucket);
        $cold->createBucket($bucket);
        $coldWrite = $cold->putObject($bucket, $key, new ReadableBuffer($payload));
        $metadata->putObjectMetadata(
            bucket: $bucket,
            key: $key,
            ownerId: 'owner',
            size: strlen($payload),
            etag: '"' . md5($payload) . '"',
            contentType: 'application/octet-stream',
            storagePath: $coldWrite->path,
        );
        $metadata->updateObjectPlacement(
            bucket: $bucket,
            key: $key,
            versionId: null,
            storageClass: 'GLACIER',
            storageTier: 'GLACIER',
            storagePath: $coldWrite->path,
        );

        $port = self::unusedPort();
        $runtime = (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: sys_get_temp_dir(),
                perClientRateLimit: 0,
                notificationWorkerEnabled: false,
                lifecycleIntervalSeconds: 0.02,
            ),
            metadata: $metadata,
            storage: $hot,
            credentialProvider: new InMemoryCredentialProvider(new Credential('restore-key', 'restore-secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
            storageTiers: new StorageTierRegistry([
                new StorageTier('STANDARD', $hot, defaultWriteTier: true),
                new StorageTier('GLACIER', $cold, restoreRequired: true),
            ]),
            encryption: new EncryptionService(new ConfigMasterKeyProvider(base64_encode(str_repeat('R', 32)))),
        );
        $sdkPool = new ContextWorkerPool(1);

        try {
            $runtime->server->start();
            $result = $sdkPool->submit(new RestoreObjectSdkJourneyTask(
                endpoint: "http://127.0.0.1:{$port}",
                accessKey: 'restore-key',
                secretKey: 'restore-secret',
                bucket: $bucket,
                key: $key,
            ))->getFuture()->await();

            self::assertSame(202, $result['restoreStatus']);
            self::assertSame(200, $result['renewedRestoreStatus']);
            self::assertSame(200, $result['headStatus']);
            self::assertSame(200, $result['getStatus']);
            self::assertSame('GLACIER', $result['storageClass']);
            self::assertMatchesRegularExpression(
                '/^ongoing-request="false", expiry-date="[A-Z][a-z]{2}, \d{2} [A-Z][a-z]{2} \d{4} \d{2}:\d{2}:\d{2} GMT"$/',
                $result['headRestore'],
            );
            self::assertSame($result['headRestore'], $result['getRestore']);
            self::assertSame($payload, $result['body']);

            $object = $metadata->getObjectMetadata($bucket, $key);
            self::assertNotNull($object);
            self::assertSame('restored', $object->restoreStatus);
            self::assertSame('STANDARD', $object->restoredStorageTier);
            self::assertNotNull($object->restoredStoragePath);
            self::assertNotSame('', $object->restoredStoragePath);
            self::assertSame($payload, ByteStream\buffer($hot->getObjectByPath($object->restoredStoragePath)));
            self::assertSame($payload, ByteStream\buffer($cold->getObjectByPath($coldWrite->path)));
        } finally {
            try {
                $sdkPool->shutdown();
            } finally {
                $runtime->stop();
            }
        }
    }

    private static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }
}
