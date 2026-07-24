<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Flysystem\Filesystem;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Tests\Support\InMemoryFlysystemAdapter;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await as awaitFutures;

final class FlysystemBackendTest extends TestCase
{
    private InMemoryFlysystemAdapter $adapter;

    private FlysystemBackend $backend;

    private string $tempDir;

    protected function setUp(): void
    {
        $this->adapter = new InMemoryFlysystemAdapter();
        $this->tempDir = sys_get_temp_dir() . '/s3server-flysystem-' . bin2hex(random_bytes(8));
        mkdir($this->tempDir, 0755, true);
        $this->backend = new FlysystemBackend(new Filesystem($this->adapter), $this->tempDir);
    }

    protected function tearDown(): void
    {
        if (is_dir($this->tempDir)) {
            foreach (glob($this->tempDir . '/*') ?: [] as $file) {
                if (is_file($file)) {
                    @unlink($file);
                }
            }
            rmdir($this->tempDir);
        }
    }

    public function test_put_get_delete_round_trip_uses_flysystem_streams(): void
    {
        $this->backend->createBucket('bucket');

        $result = $this->backend->putObject('bucket', 'path/object.txt', new ReadableBuffer('hello remote'));

        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame(1, $this->adapter->writeStreamCalls);
        self::assertSame(0, $this->adapter->writeCalls);
        self::assertSame(12, $result->size);
        self::assertSame('hello remote', $this->adapter->contents($result->path));

        $stream = $this->backend->getObjectByPath($result->path);
        self::assertSame('hello remote', self::readAll($stream));

        $range = $this->backend->getObjectByPath($result->path, 6, 6);
        self::assertSame('remote', self::readAll($range));

        $this->backend->deleteObjectByPath($result->path, 'bucket');
        $this->expectException(NoSuchKeyException::class);
        $this->backend->getObjectByPath($result->path);
    }

    public function test_multipart_assembly_uses_configured_temp_dir_and_cleans_up_parts(): void
    {
        $this->backend->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));

        $part1 = $this->backend->putPart('bucket', 'large.bin', $uploadId, 1, new ReadableBuffer('abc'));
        $part2 = $this->backend->putPart('bucket', 'large.bin', $uploadId, 2, new ReadableBuffer('def'));

        $before = scandir($this->tempDir);
        $result = $this->backend->assembleMultipartUpload('bucket', 'large.bin', $uploadId, [
            ['partNumber' => 1, 'etag' => $part1->md5Hex, 'storagePath' => $part1->path],
            ['partNumber' => 2, 'etag' => $part2->md5Hex, 'storagePath' => $part2->path],
        ]);
        $after = scandir($this->tempDir);

        self::assertSame($before, $after);
        self::assertSame('abcdef', self::readAll($this->backend->getObjectByPath($result->path)));
        self::assertSame(6, $result->size);
        self::assertStringEndsWith('-2', $result->md5Hex);
        self::assertNotSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, ".parts/{$uploadId}/"),
        )));

        $this->backend->abortMultipartUpload('bucket', 'large.bin', $uploadId);
        self::assertSame([], array_values(array_filter(
            $this->adapter->paths(),
            static fn(string $path): bool => str_starts_with($path, ".parts/{$uploadId}/"),
        )));
    }

    public function test_failed_part_replacement_can_delete_new_attempt_without_losing_previous_part(): void
    {
        $uploadId = 'upload-' . bin2hex(random_bytes(4));
        $previous = $this->backend->putPart(
            'bucket',
            'object.bin',
            $uploadId,
            1,
            new ReadableBuffer('previous'),
        );
        $replacement = $this->backend->putPart(
            'bucket',
            'object.bin',
            $uploadId,
            1,
            new ReadableBuffer('replacement'),
        );

        self::assertNotSame($previous->path, $replacement->path);
        $this->backend->deleteObjectByPath($replacement->path, 'bucket');

        $assembled = $this->backend->assembleMultipartUpload('bucket', 'object.bin', $uploadId, [[
            'partNumber' => 1,
            'etag' => $previous->md5Hex,
            'storagePath' => $previous->path,
        ]]);

        self::assertSame('previous', self::readAll($this->backend->getObjectByPath($assembled->path)));
    }

    public function test_copy_object_reads_and_writes_through_flysystem(): void
    {
        $this->backend->createBucket('bucket');
        $source = $this->backend->putObject('bucket', 'source.txt', new ReadableBuffer('copy me'));

        $copy = $this->backend->copyObject($source->path, 'bucket', 'copy.txt');

        self::assertSame([], self::temporaryFiles($this->tempDir));
        self::assertSame('copy me', self::readAll($this->backend->getObjectByPath($copy->path)));
        self::assertSame(1, $this->adapter->writeStreamCalls);
        self::assertSame(1, $this->adapter->copyCalls);
        self::assertSame(7, $copy->size);
        self::assertSame(hash('md5', 'copy me'), $copy->md5Hex);
    }

    public function test_remote_write_failure_is_reported_and_does_not_leave_partial_file(): void
    {
        $this->adapter->failNextWriteStreamReason = 'simulated remote outage';

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('simulated remote outage');

        try {
            $this->backend->putObject('bucket', 'broken.bin', new ReadableBuffer('payload'));
        } finally {
            self::assertSame([], $this->adapter->paths());
        }
    }

    /** @return list<string> */
    private static function temporaryFiles(string $directory): array
    {
        $entries = scandir($directory);
        self::assertIsArray($entries);

        return array_values(array_filter(
            $entries,
            static fn(string $entry): bool => str_starts_with($entry, 's3put_')
                || str_starts_with($entry, 's3part_')
                || str_starts_with($entry, 's3copy_')
                || str_starts_with($entry, 's3mpu_'),
        ));
    }

    public function test_concurrent_stream_uploads_stay_isolated(): void
    {
        $this->backend->createBucket('bucket');

        $futures = [];
        for ($i = 0; $i < 50; $i++) {
            $futures[] = async(function () use ($i) {
                return $this->backend->putObject(
                    'bucket',
                    "concurrent/{$i}.bin",
                    new ReadableBuffer(str_repeat(chr(65 + ($i % 26)), 4096)),
                );
            });
        }

        $results = awaitFutures($futures);

        self::assertCount(50, $results);
        self::assertSame(50, $this->adapter->writeStreamCalls);
        foreach ($results as $result) {
            self::assertSame(4096, $result->size);
            self::assertContains($result->path, $this->adapter->paths());
        }
    }

    public function test_sse_s3_round_trip_uses_opaque_flysystem_paths(): void
    {
        $databasePath = $this->tempDir . '/metadata.sqlite';
        $metadata = new SqliteMetadataStore($databasePath);
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->backend->createBucket('bucket');
        $encryption = new EncryptionService(
            new ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
        );
        $plaintext = str_repeat('remote encrypted payload ', 128);
        $putRequest = new Request(
            new FlysystemBackendTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/encrypted.bin'),
            ['x-amz-server-side-encryption' => 'AES256'],
            $plaintext,
        );
        $putRequest->setAttribute('s3.bucket', 'bucket');
        $putRequest->setAttribute('s3.key', 'encrypted.bin');
        $putRequest->setAttribute('s3.operation', S3Operation::PutObject);
        $putRequest->setAttribute('ownerId', 'owner');

        $putResponse = (new PutObjectHandler($metadata, $this->backend, $encryption))
            ->handleRequest($putRequest);

        self::assertSame(200, $putResponse->getStatus());
        $object = $metadata->getObjectMetadata('bucket', 'encrypted.bin');
        self::assertNotNull($object);
        $storagePath = $object->systemMetadata['storagePath'] ?? null;
        self::assertIsString($storagePath);
        self::assertNotSame($plaintext, $this->adapter->contents($storagePath));

        $getRequest = new Request(
            new FlysystemBackendTestClient(),
            'GET',
            Http::new('http://127.0.0.1/bucket/encrypted.bin'),
        );
        $getRequest->setAttribute('s3.bucket', 'bucket');
        $getRequest->setAttribute('s3.key', 'encrypted.bin');
        $getRequest->setAttribute('ownerId', 'owner');
        $getResponse = (new GetObjectHandler($metadata, $this->backend, $encryption))
            ->handleRequest($getRequest);

        self::assertSame(200, $getResponse->getStatus());
        self::assertSame($plaintext, self::readAll($getResponse->getBody()));
        self::assertSame([], self::temporaryFiles($this->tempDir));
        @unlink($databasePath);
        @unlink($databasePath . '-wal');
        @unlink($databasePath . '-shm');
    }

    private static function readAll(\Amp\ByteStream\ReadableStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }
}

final class FlysystemBackendTestClient implements Client
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

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
