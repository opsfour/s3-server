<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Logging;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\RecoverableObjectWriteBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class AccessLogWriterTest extends TestCase
{
    #[DataProvider('targetVersioningCases')]
    public function test_log_delivery_persists_its_actual_default_tier_and_reads_through_get_and_head(bool $versioned): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        if ($versioned) {
            $metadata->setBucketVersioning('log-target', 'Enabled');
        }
        $metadata->putBucketLogging('source', 'log-target', 'access/');

        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $hot),
            new StorageTier('COLD', $cold, defaultWriteTier: true),
        ]);
        $writer = new AccessLogWriter($metadata, $tiers->defaultBackend(), storageTier: $tiers->defaultTier()->name);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $object = $metadata->getCurrentObjectVersion('log-target', $objects[0]->key);
        self::assertNotNull($object);
        self::assertSame('COLD', $object->storageTier);
        self::assertSame('STANDARD', $object->storageClass);
        self::assertSame($object->systemMetadata['storagePath'], $metadata->getObjectMetadataByVersion('log-target', $object->key, $object->versionId ?? 'null')?->systemMetadata['storagePath']);

        foreach (['GET', 'HEAD'] as $method) {
            $uri = 'http://127.0.0.1/log-target/' . $object->key;
            if ($versioned) {
                self::assertNotNull($object->versionId);
                $uri .= '?versionId=' . rawurlencode($object->versionId);
            }
            $request = new Request($this->createMock(Client::class), $method, Http::new($uri));
            $request->setAttribute('s3.bucket', 'log-target');
            $request->setAttribute('s3.key', $object->key);
            $request->setAttribute('ownerId', 'alice');
            $handler = $method === 'GET'
                ? new GetObjectHandler($metadata, $hot, storageTiers: $tiers)
                : new HeadObjectHandler($metadata);
            $response = $handler->handleRequest($request);
            self::assertSame(200, $response->getStatus());
            if ($method === 'GET') {
                self::assertStringContainsString('private-key', \Amp\ByteStream\buffer($response->getBody()));
            }
        }
    }

    /** @return iterable<string, array{bool}> */
    public static function targetVersioningCases(): iterable
    {
        yield 'unversioned' => [false];
        yield 'versioned' => [true];
    }

    #[DataProvider('commitAcknowledgementCases')]
    public function test_lost_commit_ack_does_not_delete_a_potentially_published_log_object(bool $versioned, bool $lookupFails): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $generation = str_repeat('a', 32);
        $metadata->method('getBucket')->willReturnCallback(
            static fn(string $bucket): BucketInfo => new BucketInfo($bucket, 'alice', generation: $generation),
        );
        $metadata->method('getBucketLogging')->willReturn(['targetBucket' => 'log-target', 'targetPrefix' => 'access/']);
        $metadata->method('getBucketVersioning')->willReturn($versioned ? 'Enabled' : 'Disabled');
        $publication = new class {
            public ?ObjectInfo $object = null;
        };
        $metadata->method('getObjectMetadata')->willReturnCallback(
            static function (string $bucket, string $key) use ($publication, $lookupFails): ?ObjectInfo {
                if ($lookupFails && $publication->object !== null) {
                    throw new \RuntimeException('metadata read unavailable');
                }

                return $publication->object;
            },
        );
        $metadata->method('getObjectMetadataByVersion')->willReturnCallback(
            static function (string $bucket, string $key, string $versionId) use ($publication): ?ObjectInfo {
                self::assertSame('version-1', $versionId);

                return $publication->object;
            },
        );
        $metadata->method('putObjectMetadata')->willReturnCallback(
            static function (string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath) use ($publication): void {
                $publication->object = new ObjectInfo($bucket, $key, $size, $etag, ownerId: $ownerId, systemMetadata: ['storagePath' => $storagePath]);
            },
        );
        $metadata->method('putObjectVersioned')->willReturnCallback(
            static function (string $bucket, string $key, string $ownerId, int $size, string $etag, string $contentType, string $storagePath) use ($publication): string {
                $publication->object = new ObjectInfo($bucket, $key, $size, $etag, ownerId: $ownerId, versionId: 'version-1', systemMetadata: ['storagePath' => $storagePath]);

                return 'version-1';
            },
        );
        $metadata->method('transaction')->willReturnCallback(static function (callable $callback): never {
            $callback();
            throw new \RuntimeException('commit acknowledgement lost');
        });
        $delegate = new InMemoryBackend();
        $storage = $this->createMock(RecoverableObjectWriteBackend::class);
        $storage->method('planObjectWritePath')->willReturnCallback($delegate->planObjectWritePath(...));
        $storage->method('putObjectAtPath')->willReturnCallback($delegate->putObjectAtPath(...));
        $storage->expects(self::never())->method('deleteObjectByPath');
        $writer = new AccessLogWriter($metadata, $storage);

        $writer->log('source', 'private-key', 'GetObject', 200, 12);
        $writer->flush();

        self::assertSame($lookupFails ? 1 : 0, $writer->getBufferCount());
        self::assertInstanceOf(ObjectInfo::class, $publication->object);
        self::assertStringContainsString(
            'private-key',
            \Amp\ByteStream\buffer($delegate->getObjectByPath($publication->object->systemMetadata['storagePath'])),
        );
    }

    /** @return iterable<string, array{bool, bool}> */
    public static function commitAcknowledgementCases(): iterable
    {
        yield 'unversioned' => [false, false];
        yield 'versioned' => [true, false];
        yield 'metadata read unavailable' => [false, true];
    }

    public function test_planned_log_write_rejects_an_alias_result_and_cleans_only_its_own_path(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $delegate = new InMemoryBackend();
        $storage = $this->createMock(RecoverableObjectWriteBackend::class);
        $plannedPath = null;
        $sentinelPath = null;
        $storage->method('planObjectWritePath')->willReturnCallback(
            static function (string $bucket, string $key, string $generation, string $attemptId) use ($delegate, &$plannedPath): string {
                return $plannedPath = $delegate->planObjectWritePath($bucket, $key, $generation, $attemptId);
            },
        );
        $storage->expects(self::once())->method('putObjectAtPath')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body, string $path) use ($delegate, &$sentinelPath): StorageWriteResult {
                $payload = \Amp\ByteStream\buffer($body);
                $sentinel = $delegate->putObject($bucket, 'existing-object', new ReadableBuffer($payload));
                $sentinelPath = $sentinel->path;

                return $sentinel;
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->willReturnCallback(
            static function (string $path, string $bucket) use (&$plannedPath, $delegate): void {
                self::assertSame($plannedPath, $path);
                self::assertSame('log-target', $bucket);
                $delegate->deleteObjectByPath($path, $bucket);
            },
        );
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 12);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
        self::assertNotNull($sentinelPath);
        self::assertStringContainsString('private-key', \Amp\ByteStream\buffer($delegate->getObjectByPath($sentinelPath)));
    }

    public function test_planned_log_write_exception_cleans_a_partially_written_path(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $delegate = new InMemoryBackend();
        $storage = $this->createMock(RecoverableObjectWriteBackend::class);
        $plannedPath = null;
        $storage->method('planObjectWritePath')->willReturnCallback(
            static function (string $bucket, string $key, string $generation, string $attemptId) use ($delegate, &$plannedPath): string {
                return $plannedPath = $delegate->planObjectWritePath($bucket, $key, $generation, $attemptId);
            },
        );
        $storage->method('putObjectAtPath')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body, string $path) use ($delegate): never {
                $delegate->putObjectAtPath($bucket, $key, $body, $path);
                throw new \RuntimeException('write result lost');
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->willReturnCallback(
            static function (string $path, string $bucket) use (&$plannedPath, $delegate): void {
                self::assertSame($plannedPath, $path);
                $delegate->deleteObjectByPath($path, $bucket);
            },
        );

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 12);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
        self::assertNotNull($plannedPath);
        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        \Amp\ByteStream\buffer($delegate->getObjectByPath($plannedPath));
    }

    public function test_observed_custom_backend_without_planned_paths_uses_legacy_write(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $delegate = new InMemoryBackend();
        $custom = $this->createMock(StorageBackend::class);
        $custom->expects(self::once())->method('putObject')->willReturnCallback(
            static fn(string $bucket, string $key, ReadableStream $body): StorageWriteResult => $delegate->putObject($bucket, $key, $body),
        );
        $storage = new ObservedStorageBackend($custom, new MetricsCollector(), 'custom');
        $writer = new AccessLogWriter($metadata, $storage);

        $writer->log('source', 'key', 'GetObject', 200, 1);
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        self::assertCount(1, $metadata->listObjects('log-target')->objects);
    }

    #[DataProvider('storageWriteCorruptions')]
    public function test_inconsistent_storage_write_preserves_log_buffer_for_successful_retry(string $corruption): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $delegate = new InMemoryBackend();
        $storage = $this->createMock(StorageBackend::class);
        $writes = 0;
        $storage->method('putObject')->willReturnCallback(static function (string $bucket, string $key, ReadableStream $body) use ($delegate, &$writes, $corruption): StorageWriteResult {
            $payload = \Amp\ByteStream\buffer($body);
            $writes++;
            if ($writes === 1) {
                $payload = $corruption === 'short' ? substr($payload, 0, -1) : 'X' . substr($payload, 1);
            }

            return $delegate->putObject($bucket, $key, new ReadableBuffer($payload));
        });
        $storage->expects(self::never())->method('deleteObjectByPath');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'object.txt', 'GetObject', 200, 10);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());

        $writer->flush();

        self::assertSame(2, $writes);
        self::assertSame(0, $writer->getBufferCount());
        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $body = \Amp\ByteStream\buffer($delegate->getObjectByPath($objects[0]->systemMetadata['storagePath']));
        self::assertSame($objects[0]->size, strlen($body));
        self::assertSame(trim($objects[0]->etag, '"'), md5($body));
        self::assertStringContainsString(' GetObject object.txt ', $body);
    }

    /** @return iterable<string, array{string}> */
    public static function storageWriteCorruptions(): iterable
    {
        yield 'short' => ['short'];
        yield 'same size, changed bytes' => ['changed'];
    }

    public function test_inconsistent_result_path_does_not_delete_an_existing_object(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $delegate = new InMemoryBackend();
        $sentinel = $delegate->putObject('log-target', 'existing-object', new ReadableBuffer('do not delete'));
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturn(new StorageWriteResult($sentinel->path, $sentinel->size, $sentinel->md5Hex));
        $storage->expects(self::never())->method('deleteObjectByPath');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'object.txt', 'GetObject', 200, 10);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
        self::assertSame('do not delete', \Amp\ByteStream\buffer($delegate->getObjectByPath($sentinel->path)));
    }

    public function test_storage_and_metadata_failure_logs_omit_dependency_secrets(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        };
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException(new \RuntimeException('database-password-private-test-token'));
        (new AccessLogWriter($metadata, new InMemoryBackend(), $logger))
            ->log('source', 'key', 'GetObject', 200, 0);

        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willThrowException(new \RuntimeException('storage-password-private-test-token'));
        $writer = new AccessLogWriter($metadata, $storage, $logger);
        $writer->log('source', 'key', 'GetObject', 200, 0);
        $writer->flush();

        self::assertCount(2, $logger->records);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertSame(\RuntimeException::class, $logger->records[1]['context']['exception']);
        $rendered = json_encode($logger->records, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('private-test-token', $rendered);
    }

    public function test_throwing_logger_does_not_escape_best_effort_recording(): void
    {
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willThrowException(new \RuntimeException('metadata unavailable'));

        $writer = new AccessLogWriter($metadata, new InMemoryBackend(), $logger);
        $writer->log('source', 'key', 'GetObject', 200, 0);

        self::assertSame(0, $writer->getBufferCount());
    }

    public function test_failed_log_object_cleanup_logs_only_a_storage_path_hash(): void
    {
        $logger = new class extends AbstractLogger {
            /** @var list<array{message: string|\Stringable, context: array<string, mixed>}> */
            public array $records = [];

            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['message' => $message, 'context' => $context];
            }
        };
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucket')->willReturnCallback(static fn(string $bucket): BucketInfo => new BucketInfo($bucket, 'owner'));
        $metadata->method('getBucketLogging')->willReturn(['targetBucket' => 'log-target', 'targetPrefix' => 'access/']);
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->method('putObjectMetadata')->willThrowException(new \RuntimeException('database-private-test-token'));
        $metadata->method('enqueueStorageGarbage')->willThrowException(new \RuntimeException('queue-private-test-token'));
        $storagePath = 's3://storage-private-test-token/log-object';
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('putObject')->willReturnCallback(static fn(string $bucket, string $key, ReadableStream $body): StorageWriteResult => self::writeResultForBody($storagePath, $body));
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('delete-private-test-token'));

        $writer = new AccessLogWriter($metadata, $storage, $logger);
        $writer->log('source', 'key', 'GetObject', 200, 0);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertCount(2, $logger->records);
        self::assertSame(hash('sha256', $storagePath), $logger->records[0]['context']['storage_path_hash']);
        self::assertSame(\RuntimeException::class, $logger->records[0]['context']['exception']);
        self::assertStringNotContainsString('private-test-token', json_encode($logger->records, JSON_THROW_ON_ERROR));
    }

    public function test_access_log_buffer_is_bounded_by_bytes_as_well_as_entry_count(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage, maxBufferBytes: 2_000);

        foreach (['first', 'second', 'third'] as $key) {
            $writer->log('source', $key, 'GetObject', 200, 0, requestUri: 'GET /' . str_repeat('x', 800) . ' HTTP/1.1');
        }

        self::assertSame(2, $writer->getBufferCount());
        $writer->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringNotContainsString(' GetObject first ', $body);
        self::assertStringContainsString(' GetObject second ', $body);
        self::assertStringContainsString(' GetObject third ', $body);
    }

    public function test_oversized_untrusted_access_log_field_is_truncated_before_formatting(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        $writer->log('source', 'key', 'GetObject', 200, 0, requestUri: str_repeat('x', 1_000_000));
        self::assertSame(1, $writer->getBufferCount());
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertLessThan(10_000, strlen($body));
        self::assertStringContainsString('...[truncated]', $body);
    }

    public function test_record_fields_follow_s3_order_and_untrusted_values_cannot_shift_fields(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log(
            bucket: 'source',
            key: "folder/with space\nnext.txt",
            operation: 'GetObject',
            httpStatus: 404,
            bytesTransferred: 0,
            remoteIp: '192.0.2.1',
            requesterId: 'bob',
            requestId: 'request-123',
            requestUri: "GET /source/folder/with%20space?x=\"quoted\"\nnext HTTP/1.1",
            errorCode: 'NoSuchKey',
            receivedAt: new \DateTimeImmutable('2024-01-01T12:00:00+02:00'),
            hostId: 'host-123',
            userAgent: "aws-sdk/1 \"test\"\nnext",
            referer: "https://example.test/path?x=\"quoted\"\nnext",
            hostHeader: "host.example:9000\ninjected",
            versionId: 'version-123',
            signatureVersion: 'SigV4',
            cipherSuite: 'TLS_AES_128_GCM_SHA256',
            authenticationType: 'AuthHeader',
            tlsVersion: 'TLSv1.3',
        );
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = trim(\Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath'])));
        preg_match_all('/\[[^\]]+\]|"(?:\\\\.|[^"\\\\])*"|[^\s]+/', $line, $matches);
        $fields = $matches[0];
        self::assertCount(27, $fields);
        self::assertSame('alice', $fields[0]);
        self::assertSame('source', $fields[1]);
        self::assertSame('[01/Jan/2024:10:00:00 +0000]', $fields[2]);
        self::assertSame('192.0.2.1', $fields[3]);
        self::assertSame('bob', $fields[4]);
        self::assertSame('request-123', $fields[5]);
        self::assertSame('GetObject', $fields[6]);
        self::assertSame('folder/with%20space%0Anext.txt', $fields[7]);
        self::assertStringContainsString('GET /source/folder/with%20space', $fields[8]);
        self::assertStringContainsString('\\"quoted\\"\\x0Anext', $fields[8]);
        self::assertSame('404', $fields[9]);
        self::assertSame('NoSuchKey', $fields[10]);
        self::assertSame('-', $fields[11]);
        self::assertSame('"https://example.test/path?x=\\"quoted\\"\\x0Anext"', $fields[15]);
        self::assertSame('"aws-sdk/1 \\"test\\"\\x0Anext"', $fields[16]);
        self::assertSame('version-123', $fields[17]);
        self::assertSame('host-123', $fields[18]);
        self::assertSame('SigV4', $fields[19]);
        self::assertSame('TLS_AES_128_GCM_SHA256', $fields[20]);
        self::assertSame('AuthHeader', $fields[21]);
        self::assertSame('host.example:9000%0Ainjected', $fields[22]);
        self::assertSame('TLSv1.3', $fields[23]);
        self::assertCount(1, explode("\n", $line));
    }

    public function test_metadata_failure_deletes_or_queues_written_log_object_and_retains_buffer(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $storage = $this->createMock(StorageBackend::class);

        $metadata->method('getBucketLogging')->willReturn([
            'targetBucket' => 'log-target',
            'targetPrefix' => 'access/',
        ]);
        $metadata->method('getBucketOwner')->willReturn('owner');
        $metadata->method('getBucket')->willReturnCallback(static fn(string $bucket): BucketInfo => new BucketInfo($bucket, 'owner'));
        $metadata->method('transaction')->willReturnCallback(static fn(callable $callback): mixed => $callback());
        $metadata->method('putObjectMetadata')->willThrowException(new \RuntimeException('metadata unavailable'));
        $storage->method('putObject')->willReturnCallback(static fn(string $bucket, string $key, ReadableStream $body): StorageWriteResult => self::writeResultForBody('/remote/log-object', $body));
        $storage->method('deleteObjectByPath')->willThrowException(new \RuntimeException('storage unavailable'));
        $metadata->expects(self::once())
            ->method('enqueueStorageGarbage')
            ->with('log-target', 'HOT', '/remote/log-object');

        $writer = new AccessLogWriter($metadata, $storage, storageTier: 'HOT');
        $writer->log('source', 'object.txt', 'GetObject', 200, 10);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
    }

    public function test_reused_logging_target_owned_by_another_account_receives_no_logs(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->deleteBucket('alice', 'log-target');
        $metadata->createBucket('bob', 'log-target', 'us-east-1');

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_missing_target_at_record_time_cannot_receive_logs_after_name_reuse(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->deleteBucket('alice', 'log-target');

        $writer = new AccessLogWriter($metadata, new InMemoryBackend());
        $writer->log('source', 'old-request', 'REST.GET.OBJECT', 200, 7, bucketOwnerId: 'alice');
        self::assertSame(0, $writer->getBufferCount());

        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $writer->flush();
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_reused_source_name_does_not_deliver_old_account_logs_to_new_owner(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'alice-logs', 'us-east-1');
        $metadata->putBucketLogging('source', 'alice-logs', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'alice-private-key', 'GetObject', 200, 42, bucketOwnerId: 'alice');

        $metadata->deleteBucket('alice', 'source');
        $metadata->createBucket('bob', 'source', 'us-east-1');
        $metadata->createBucket('bob', 'bob-logs', 'us-east-1');
        $metadata->putBucketLogging('source', 'bob-logs', 'access/');
        $writer->log('source', 'bob-key', 'GetObject', 200, 3, bucketOwnerId: 'bob');
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('alice-logs')->objects);
        $bobLogs = $metadata->listObjects('bob-logs')->objects;
        self::assertCount(1, $bobLogs);
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($bobLogs[0]->systemMetadata['storagePath']));
        self::assertStringContainsString('bob-key', $body);
        self::assertStringNotContainsString('alice-private-key', $body);
    }

    public function test_same_owner_source_recreation_discards_old_generation_logs(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $oldGeneration = $metadata->getBucket('source')?->generation;
        self::assertNotNull($oldGeneration);
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'old-private-key', 'GetObject', 200, 1, bucketOwnerId: 'alice', bucketGeneration: $oldGeneration);

        $metadata->deleteBucket('alice', 'source');
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $newGeneration = $metadata->getBucket('source')?->generation;
        self::assertNotNull($newGeneration);
        self::assertNotSame($oldGeneration, $newGeneration);

        // A request that began before the name was reused must not adopt the
        // new generation even if its response is logged only afterward.
        $writer->log('source', 'in-flight-old-key', 'GetObject', 200, 1, bucketOwnerId: 'alice', bucketGeneration: $oldGeneration);
        $writer->log('source', 'new-key', 'GetObject', 200, 1, bucketOwnerId: 'alice', bucketGeneration: $newGeneration);
        $writer->flush();

        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($objects[0]->systemMetadata['storagePath']));
        self::assertStringContainsString('new-key', $body);
        self::assertStringNotContainsString('old-private-key', $body);
        self::assertStringNotContainsString('in-flight-old-key', $body);
    }

    public function test_same_owner_target_recreation_discards_old_target_generation_logs(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'old-private-key', 'GetObject', 200, 1, bucketOwnerId: 'alice');

        $oldGeneration = $metadata->getBucket('log-target')?->generation;
        $metadata->deleteBucket('alice', 'log-target');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        self::assertNotSame($oldGeneration, $metadata->getBucket('log-target')?->generation);
        $writer->log('source', 'new-key', 'GetObject', 200, 1, bucketOwnerId: 'alice');
        $writer->flush();

        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($objects[0]->systemMetadata['storagePath']));
        self::assertStringContainsString('new-key', $body);
        self::assertStringNotContainsString('old-private-key', $body);
    }

    public function test_enabling_logging_does_not_capture_requests_recorded_earlier(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        $writer->log('source', 'before-enabling', 'GetObject', 200, 1);
        self::assertSame(0, $writer->getBufferCount());

        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $writer->log('source', 'after-enabling', 'GetObject', 200, 1);
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString('after-enabling', $body);
        self::assertStringNotContainsString('before-enabling', $body);
    }

    public function test_buffered_entries_keep_the_logging_target_active_when_recorded(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'old-target', 'us-east-1');
        $metadata->createBucket('alice', 'new-target', 'us-east-1');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        $metadata->putBucketLogging('source', 'old-target', 'before/');
        $writer->log('source', 'old-request', 'GetObject', 200, 1);
        $metadata->putBucketLogging('source', 'new-target', 'after/');
        $writer->log('source', 'new-request', 'GetObject', 200, 1);
        $writer->flush();

        $oldObject = $metadata->listObjects('old-target')->objects[0];
        $newObject = $metadata->listObjects('new-target')->objects[0];
        self::assertStringStartsWith('before/', $oldObject->key);
        self::assertStringStartsWith('after/', $newObject->key);
        $oldBody = \Amp\ByteStream\buffer($storage->getObjectByPath($oldObject->systemMetadata['storagePath']));
        $newBody = \Amp\ByteStream\buffer($storage->getObjectByPath($newObject->systemMetadata['storagePath']));
        self::assertStringContainsString('old-request', $oldBody);
        self::assertStringNotContainsString('new-request', $oldBody);
        self::assertStringContainsString('new-request', $newBody);
        self::assertStringNotContainsString('old-request', $newBody);
    }

    public function test_matching_owner_and_region_deliver_log_object(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        self::assertSame('alice', $objects[0]->ownerId);
        self::assertStringStartsWith('access/', $objects[0]->key);
        self::assertMatchesRegularExpression(
            '/^access\/\d{4}-\d{2}-\d{2}-\d{2}-\d{2}-\d{2}-[a-f0-9]{16}$/',
            $objects[0]->key,
        );
        self::assertGreaterThan(0, $objects[0]->size);
    }

    public function test_numeric_account_id_stays_a_string_when_logs_are_grouped(): void
    {
        $owner = '123456789012';
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket($owner, 'source', 'us-east-1');
        $metadata->createBucket($owner, 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        $writer->log('source', 'key', 'GetObject', 200, 1, bucketOwnerId: $owner);
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        self::assertSame($owner, $objects[0]->ownerId);
    }

    public function test_numeric_bucket_name_stays_a_string_when_logs_are_grouped(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', '12345', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('12345', 'log-target', 'access/');
        $writer = new AccessLogWriter($metadata, new InMemoryBackend());

        $writer->log('12345', 'key', 'GetObject', 200, 1, bucketOwnerId: 'alice');
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        self::assertCount(1, $metadata->listObjects('log-target')->objects);
    }

    public function test_delivered_log_object_triggers_target_bucket_notifications(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putBucketNotification('log-target', [[
            'id' => 'log-created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/logs',
            'filterRules' => [['name' => 'prefix', 'value' => 'access/']],
        ]]);
        $notifications = new NotificationDispatcher($metadata);
        $received = [];
        $notifications->listen('s3:ObjectCreated:*', static function (S3Event $event) use (&$received): void {
            $received[] = $event;
        });

        $writer = new AccessLogWriter($metadata, new InMemoryBackend(), notifications: $notifications);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();
        $notifications->shutdown();

        $object = $metadata->listObjects('log-target')->objects[0];
        $queued = $metadata->dequeueNotifications(10);
        self::assertCount(1, $queued);
        self::assertSame('s3:ObjectCreated:Put', $queued[0]['event_name']);
        self::assertSame($object->key, $queued[0]['key_name']);
        $record = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        self::assertSame('log-created', $record['s3']['configurationId']);
        self::assertSame($object->key, urldecode($record['s3']['object']['key']));
        self::assertSame($object->size, $record['s3']['object']['size']);
        self::assertCount(1, $received);
        self::assertSame($object->key, $received[0]->key);
    }

    public function test_failed_log_notification_rolls_back_log_metadata_and_retains_buffer(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-log-notify-');
        self::assertNotFalse($path);

        try {
            $metadata = new SqliteMetadataStore($path);
            $metadata->initialize();
            $metadata->createBucket('alice', 'source', 'us-east-1');
            $metadata->createBucket('alice', 'log-target', 'us-east-1');
            $metadata->putBucketLogging('source', 'log-target', 'access/');
            $metadata->putBucketNotification('log-target', [[
                'id' => 'log-created',
                'events' => ['s3:ObjectCreated:*'],
                'destinationType' => 'Topic',
                'destinationArn' => 'https://notifications.example.test/logs',
                'filterRules' => null,
            ]]);
            $connection = new \PDO('sqlite:' . $path);
            $connection->exec("CREATE TRIGGER fail_log_enqueue BEFORE INSERT ON s3_notification_queue
                BEGIN SELECT RAISE(FAIL, 'notification queue unavailable'); END");

            $storage = $this->createMock(StorageBackend::class);
            $storage->method('putObject')->willReturnCallback(static fn(string $bucket, string $key, ReadableStream $body): StorageWriteResult => self::writeResultForBody('/stored/log', $body));
            $storage->expects(self::once())->method('deleteObjectByPath')->with('/stored/log');
            $writer = new AccessLogWriter(
                $metadata,
                $storage,
                notifications: new NotificationDispatcher($metadata),
            );
            $writer->log('source', 'private-key', 'GetObject', 200, 42);
            $writer->flush();

            self::assertSame(1, $writer->getBufferCount());
            self::assertSame([], $metadata->listObjects('log-target')->objects);
            self::assertSame([], $metadata->dequeueNotifications(10));
        } finally {
            unlink($path);
        }
    }

    public function test_reused_logging_target_in_another_region_receives_no_logs(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->deleteBucket('alice', 'log-target');
        $metadata->createBucket('alice', 'log-target', 'eu-west-1');

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_access_log_respects_target_bucket_sse_s3_default(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putBucketEncryption('log-target', 'AES256');
        $storage = new InMemoryBackend();
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));

        $writer = new AccessLogWriter($metadata, $storage, encryption: $encryption);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $object = $objects[0];
        self::assertSame('AES256', $object->userMetadata['__sse-algorithm']);
        $path = $object->systemMetadata['storagePath'];
        $ciphertext = \Amp\ByteStream\buffer($storage->getObjectByPath($path));
        self::assertStringNotContainsString('private-key', $ciphertext);
        $plaintext = $encryption->decryptSseS3(
            $ciphertext,
            $object->userMetadata['__sse-key'],
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        );
        self::assertStringContainsString('private-key', $plaintext);
        self::assertSame(strlen($plaintext), $object->size);
        self::assertSame('"' . md5($plaintext) . '"', $object->etag);
    }

    public function test_encrypted_target_without_key_service_does_not_receive_plaintext_logs(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putBucketEncryption('log-target', 'AES256');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_encryption_enabled_during_storage_write_discards_staged_plaintext_and_retries(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body) use ($metadata): StorageWriteResult {
                $metadata->putBucketEncryption('log-target', 'AES256');

                return self::writeResultForBody('staged-path', $body);
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->with('staged-path', 'log-target');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_source_recreated_during_storage_write_discards_staged_log(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body) use ($metadata): StorageWriteResult {
                $metadata->deleteBucket('alice', 'source');
                $metadata->createBucket('alice', 'source', 'us-east-1');

                return self::writeResultForBody('staged-path', $body);
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->with('staged-path', 'log-target');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_log_delivery_creates_a_version_in_versioned_target_bucket(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->setBucketVersioning('log-target', 'Enabled');
        $metadata->putBucketLogging('source', 'log-target', 'access/');

        $writer = new AccessLogWriter($metadata, new InMemoryBackend());
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        self::assertNotNull($objects[0]->versionId);
        self::assertNotSame('null', $objects[0]->versionId);
        self::assertNotEmpty($metadata->getAcl(
            'object',
            ObjectVersionResolver::aclResourceName('log-target', $objects[0]->key, $objects[0]->versionId),
        ));
    }

    public function test_log_delivery_respects_target_object_quota(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putObjectMetadata('log-target', 'existing.txt', 'alice', 1, '"etag"', 'text/plain', 'existing-path');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');
        $quotas = QuotaManager::fromGlobalConfig($metadata, new QuotaConfig(maxObjectsPerBucket: 1));

        $writer = new AccessLogWriter($metadata, $storage, quotas: $quotas);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertCount(1, $metadata->listObjects('log-target')->objects);
    }

    public function test_target_bucket_policy_deny_blocks_log_delivery_before_storage_write(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putBucketPolicy('log-target', self::denyLogWritesPolicy());
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_bucket_owner_only_policy_deny_does_not_block_internal_log_delivery(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putBucketPolicy('log-target', self::denyLogWritesPolicy('alice'));

        $writer = new AccessLogWriter($metadata, new InMemoryBackend());
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(0, $writer->getBufferCount());
        self::assertCount(1, $metadata->listObjects('log-target')->objects);
    }

    public function test_internal_logging_principal_policy_deny_blocks_log_delivery(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putBucketPolicy('log-target', self::denyLogWritesPolicy('logging.s3.amazonaws.com'));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_target_bucket_policy_deny_added_during_storage_write_discards_staged_log(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('putObject')->willReturnCallback(
            static function (string $bucket, string $key, ReadableStream $body) use ($metadata): StorageWriteResult {
                $metadata->putBucketPolicy('log-target', self::denyLogWritesPolicy());

                return self::writeResultForBody('staged-path', $body);
            },
        );
        $storage->expects(self::once())->method('deleteObjectByPath')->with('staged-path', 'log-target');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    private static function denyLogWritesPolicy(string $principal = '*'): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Deny',
                'Principal' => $principal,
                'Action' => 's3:PutObject',
                'Resource' => 'arn:aws:s3:::log-target/access/*',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private static function writeResultForBody(string $path, ReadableStream $body): StorageWriteResult
    {
        $payload = \Amp\ByteStream\buffer($body);

        return new StorageWriteResult($path, strlen($payload), md5($payload));
    }

    public function test_object_lock_enabled_after_logging_configuration_blocks_delivery(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $metadata->putObjectLockConfig('log-target', ['objectLockEnabled' => 'Enabled']);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('putObject');

        $writer = new AccessLogWriter($metadata, $storage);
        $writer->log('source', 'private-key', 'GetObject', 200, 42);
        $writer->flush();

        self::assertSame(1, $writer->getBufferCount());
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }
}
