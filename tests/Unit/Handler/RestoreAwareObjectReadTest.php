<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Handler\Object\RestoreObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class RestoreAwareObjectReadTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $hot;

    private InMemoryBackend $cold;

    private StorageTierRegistry $tiers;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-restore-read-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');

        $this->hot = new InMemoryBackend();
        $this->cold = new InMemoryBackend();
        $this->hot->createBucket('bucket');
        $this->cold->createBucket('bucket');
        $this->tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $this->cold, restoreRequired: true),
        ]);
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_get_cold_object_without_restore_throws_invalid_object_state(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);

        $this->expectException(InvalidObjectStateException::class);

        $this->getHandler()->handleRequest($this->request('GET', '/bucket/archive.bin'));
    }

    public function test_get_object_during_transition_reads_existing_source_placement(): void
    {
        $hotWrite = $this->hot->putObject('bucket', 'moving.bin', new ReadableBuffer('still-hot'));
        $this->putMetadata('moving.bin', $hotWrite->path, $hotWrite->size, $hotWrite->md5Hex);
        $this->metadata->updateObjectPlacement(
            bucket: 'bucket',
            key: 'moving.bin',
            versionId: null,
            storageClass: 'STANDARD',
            storageTier: 'STANDARD',
            storagePath: $hotWrite->path,
            transitionStatus: 'processing',
            transitionTargetTier: 'GLACIER',
        );

        $response = $this->getHandler()->handleRequest($this->request('GET', '/bucket/moving.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('still-hot', \Amp\ByteStream\buffer($response->getBody()));
    }

    public function test_get_cold_object_reads_valid_restored_hot_copy(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $hotWrite = $this->hot->putObject('bucket', 'archive.bin', new ReadableBuffer('restored-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState(
            bucket: 'bucket',
            key: 'archive.bin',
            versionId: null,
            restoreStatus: 'restored',
            restoredStoragePath: $hotWrite->path,
            restoreExpiresAt: new \DateTimeImmutable('+1 day', new \DateTimeZone('UTC')),
        );

        $response = $this->getHandler()->handleRequest($this->request('GET', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('restored-data', \Amp\ByteStream\buffer($response->getBody()));
        self::assertStringContainsString('ongoing-request="false"', $response->getHeader('x-amz-restore') ?? '');
    }

    public function test_head_cold_object_reports_pending_restore_header(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $response = (new HeadObjectHandler($this->metadata))->handleRequest($this->request('HEAD', '/bucket/archive.bin'));

        self::assertSame(200, $response->getStatus());
        self::assertSame('ongoing-request="true"', $response->getHeader('x-amz-restore'));
        self::assertSame('GLACIER', $response->getHeader('x-amz-storage-class'));
    }

    public function test_restore_object_enqueues_restore_job_and_marks_object_pending(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);

        $body = '<RestoreRequest><Days>3</Days><GlacierJobParameters><Tier>Standard</Tier></GlacierJobParameters></RestoreRequest>';
        $response = (new RestoreObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?restore', $body));

        $object = $this->metadata->getObjectMetadata('bucket', 'archive.bin');
        $jobs = $this->metadata->dequeueRestoreJobs(1);

        self::assertSame(202, $response->getStatus());
        self::assertNotNull($object);
        self::assertSame('pending', $object->restoreStatus);
        self::assertCount(1, $jobs);
        self::assertSame('archive.bin', $jobs[0]['key']);
        self::assertSame(3, $jobs[0]['restoreDays']);
    }

    public function test_restore_object_rejects_duplicate_pending_restore(): void
    {
        $coldWrite = $this->cold->putObject('bucket', 'archive.bin', new ReadableBuffer('cold-data'));
        $this->putMetadata('archive.bin', $coldWrite->path, $coldWrite->size, $coldWrite->md5Hex);
        $this->metadata->updateObjectPlacement('bucket', 'archive.bin', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreState('bucket', 'archive.bin', null, 'pending');

        $this->expectException(OperationAbortedException::class);

        (new RestoreObjectHandler($this->metadata, $this->tiers))
            ->handleRequest($this->request('POST', '/bucket/archive.bin?restore', '<RestoreRequest><Days>3</Days></RestoreRequest>'));
    }

    private function getHandler(): GetObjectHandler
    {
        return new GetObjectHandler($this->metadata, $this->hot, storageTiers: $this->tiers);
    }

    private function putMetadata(string $key, string $path, int $size, string $md5): void
    {
        $this->metadata->putObjectMetadata(
            bucket: 'bucket',
            key: $key,
            ownerId: 'owner',
            size: $size,
            etag: '"' . $md5 . '"',
            contentType: 'application/octet-stream',
            storagePath: $path,
        );
    }

    private function request(string $method, string $path, string $body = ''): Request
    {
        $request = new Request(
            new RestoreReadTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $urlPath = parse_url($path, PHP_URL_PATH);
        $request->setAttribute('s3.key', ltrim(substr((string) $urlPath, strlen('/bucket/')), '/'));
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}

final class RestoreReadTestClient implements Client
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
