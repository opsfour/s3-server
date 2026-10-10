<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\ObjectLockedException;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class SuspendedNullReplacementTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-suspended-null-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_replacing_an_older_null_version_keeps_both_numbered_payloads(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->put('numbered A');
        $first = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($first);
        self::assertNotNull($first->versionId);

        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->put('old null');
        $oldNull = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($oldNull);
        $oldNullPath = $oldNull->systemMetadata['storagePath'];

        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->put('numbered B');
        $second = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($second);
        self::assertNotNull($second->versionId);
        self::assertNotSame($first->versionId, $second->versionId);

        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->put('new null');
        $latest = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($latest);
        self::assertNull($latest->versionId);
        self::assertSame('new null', $this->read($latest->systemMetadata['storagePath']));
        self::assertSame('numbered A', $this->read($first->systemMetadata['storagePath']));
        self::assertSame('numbered B', $this->read($second->systemMetadata['storagePath']));
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'key', $first->versionId));
        self::assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'key', $second->versionId));

        $firstPage = $this->metadata->listObjectVersions('bucket', maxKeys: 1);
        self::assertSame([null], array_map(static fn($version) => $version->versionId, $firstPage['versions']));
        self::assertTrue($firstPage['isTruncated']);
        $secondPage = $this->metadata->listObjectVersions(
            'bucket',
            maxKeys: 1,
            keyMarker: $firstPage['nextKeyMarker'],
            versionIdMarker: $firstPage['nextVersionIdMarker'],
        );
        self::assertSame([$second->versionId], array_map(static fn($version) => $version->versionId, $secondPage['versions']));
        $thirdPage = $this->metadata->listObjectVersions(
            'bucket',
            maxKeys: 1,
            keyMarker: $secondPage['nextKeyMarker'],
            versionIdMarker: $secondPage['nextVersionIdMarker'],
        );
        self::assertSame([$first->versionId], array_map(static fn($version) => $version->versionId, $thirdPage['versions']));

        $delimitedFirst = $this->metadata->listObjectVersions('bucket', delimiter: '/', maxKeys: 1);
        self::assertSame([null], array_map(static fn($version) => $version->versionId, $delimitedFirst['versions']));
        $delimitedSecond = $this->metadata->listObjectVersions(
            'bucket',
            delimiter: '/',
            maxKeys: 1,
            keyMarker: $delimitedFirst['nextKeyMarker'],
            versionIdMarker: $delimitedFirst['nextVersionIdMarker'],
        );
        self::assertSame([$second->versionId], array_map(static fn($version) => $version->versionId, $delimitedSecond['versions']));

        $this->expectException(NoSuchKeyException::class);
        $this->storage->getObjectByPath($oldNullPath);
    }

    public function test_replacing_null_version_clears_its_old_retention_hold_tags_and_acl(): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            3,
            '"old"',
            'text/plain',
            'old-path',
            userMetadata: ['__sse-algorithm' => 'AES256', '__sse-key' => 'old-secret'],
        );
        $this->seedNullVersionState();

        $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 3, '"new"', 'text/plain', 'new-path');
        $this->assertNullVersionStateCleared();

        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            3,
            '"encrypted"',
            'text/plain',
            'encrypted-path',
            userMetadata: ['__sse-algorithm' => 'AES256', '__sse-key' => 'old-secret'],
        );
        $this->seedNullVersionState();
        $this->metadata->deleteObjectVersioned('bucket', 'key', 'owner', suspended: true);
        $this->assertNullVersionStateCleared();
        $marker = $this->metadata->getObjectMetadataByVersion('bucket', 'key', 'null');
        self::assertNotNull($marker);
        self::assertTrue($marker->isDeleteMarker);
        self::assertSame([], $marker->userMetadata);
        self::assertSame('STANDARD', $marker->storageTier);
        self::assertNull($marker->restoredStoragePath);
    }

    public function test_suspended_put_cannot_replace_a_protected_old_null_version(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->put('protected');
        $old = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($old);
        $this->metadata->putObjectLegalHold('bucket', 'key', 'ON', 'null');

        try {
            $this->put('replacement');
            self::fail('A protected null version must not be replaced.');
        } catch (ObjectLockedException) {
            self::assertSame('protected', $this->read($old->systemMetadata['storagePath']));
            self::assertSame($old->systemMetadata['storagePath'], $this->metadata->getObjectMetadata('bucket', 'key')?->systemMetadata['storagePath']);
        }
    }

    private function put(string $body): void
    {
        $request = new Request(new SuspendedNullClient(), 'PUT', Http::new('http://127.0.0.1/bucket/key'), [], $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        (new PutObjectHandler($this->metadata, $this->storage))->handleRequest($request);
    }

    private function read(string $path): string
    {
        return ByteStream\buffer($this->storage->getObjectByPath($path));
    }

    private function seedNullVersionState(): void
    {
        $this->metadata->putObjectRetention('bucket', 'key', 'COMPLIANCE', '2099-01-01T00:00:00Z', 'null');
        $this->metadata->putObjectLegalHold('bucket', 'key', 'ON', 'null');
        $this->metadata->putObjectTagging('bucket', 'key', [['key' => 'old', 'value' => 'tag']], 'null');
        $this->metadata->putAcl('object', ObjectVersionResolver::aclResourceName('bucket', 'key', 'null'), 'owner', [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'other', 'permission' => 'READ'],
        ]);
    }

    private function assertNullVersionStateCleared(): void
    {
        self::assertNull($this->metadata->getObjectRetention('bucket', 'key', 'null'));
        self::assertNull($this->metadata->getObjectLegalHold('bucket', 'key', 'null'));
        self::assertSame([], $this->metadata->getObjectTagging('bucket', 'key', 'null'));
        self::assertSame([], $this->metadata->getAcl('object', ObjectVersionResolver::aclResourceName('bucket', 'key', 'null')));
    }
}

final class SuspendedNullClient implements Client
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
