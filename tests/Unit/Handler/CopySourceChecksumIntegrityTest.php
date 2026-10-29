<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Checksum\ChecksumCalculator;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\BoundedCopyStorageBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use PHPUnit\Framework\TestCase;

interface CopySourceChecksumBoundedStorage extends StorageBackend, BoundedCopyStorageBackend {}

final class CopySourceChecksumIntegrityTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-copy-checksum-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'source', 'us-east-1');
        $this->metadata->createBucket('owner', 'target', 'us-east-1');

        // A multipart-style ETag cannot serve as a full-payload MD5 guard.
        $this->metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            7,
            '"' . md5('multipart parts') . '-2"',
            'text/plain',
            'memory://source/key',
            checksumSha256: base64_encode(hash('sha256', 'payload', true)),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_copy_rejects_same_size_corruption_when_source_etag_is_not_full_md5(): void
    {
        $calculator = new ChecksumCalculator();
        $calculator->update('corrupt');
        $corrupt = $calculator->finalize();
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturn(new ReadableBuffer('corrupt'));
        $storage->method('putObject')->willReturn(new StorageWriteResult(
            'memory://target/corrupt-copy',
            7,
            $corrupt->md5Hex,
            sha256Base64: $corrupt->sha256Base64(),
        ));
        $storage->expects(self::once())->method('deleteObjectByPath')->with('memory://target/corrupt-copy', 'target');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->copyRequest());
            self::fail('A copy must not preserve a source checksum for damaged bytes.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_copy_rehashes_when_custom_backend_omits_extended_checksums(): void
    {
        $storage = $this->createMock(StorageBackend::class);
        $storage->method('getObjectByPath')->willReturnCallback(static fn(): ReadableBuffer => new ReadableBuffer('payload'));
        $storage->method('putObject')->willReturn(new StorageWriteResult('memory://target/copied', 7, md5('payload')));

        $response = (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->copyRequest());

        self::assertSame(200, $response->getStatus());
        self::assertSame(
            base64_encode(hash('sha256', 'payload', true)),
            $this->metadata->getObjectMetadata('target', 'copied')?->systemMetadata['checksum-sha256'],
        );
    }

    public function test_copy_accepts_verified_known_multipart_source_without_aggregate_checksum(): void
    {
        $this->replaceSourceWithMultipartLayout('payload', ['pay', 'load']);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->willReturn(new ReadableBuffer('payload'));
        $storage->expects(self::once())->method('putObject')
            ->willReturnCallback(static function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $stream): StorageWriteResult {
                $payload = \Amp\ByteStream\buffer($stream);

                return new StorageWriteResult('memory://target/copied', strlen($payload), md5($payload));
            });

        $response = (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->copyRequest());

        self::assertSame(200, $response->getStatus());
        self::assertNotNull($this->metadata->getObjectMetadata('target', 'copied'));
    }

    public function test_copy_rejects_same_size_multipart_corruption_and_cleans_uncommitted_copy(): void
    {
        $this->replaceSourceWithMultipartLayout('payload', ['pay', 'load']);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->willReturn(new ReadableBuffer('corrupt'));
        $storage->expects(self::once())->method('putObject')
            ->willReturnCallback(static function (string $bucket, string $key, \Amp\ByteStream\ReadableStream $stream): StorageWriteResult {
                $payload = \Amp\ByteStream\buffer($stream);

                return new StorageWriteResult('memory://target/corrupt-copy', strlen($payload), md5($payload));
            });
        $storage->expects(self::once())->method('deleteObjectByPath')
            ->with('memory://target/corrupt-copy', 'target');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->copyRequest());
            self::fail('A copy must reject multipart bytes that do not reproduce the persisted ETag.');
        } catch (InternalErrorException $error) {
            self::assertSame('Copy source multipart integrity does not match object metadata.', $error->getMessage());
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_bounded_backend_copy_rehashes_uncommitted_destination_before_publication(): void
    {
        $this->replaceSourceWithMultipartLayout('payload', ['pay', 'load']);
        $storage = $this->createMock(CopySourceChecksumBoundedStorage::class);
        $storage->expects(self::once())->method('copyObjectBounded')
            ->with('memory://source/key', 'target', 'copied', 8)
            ->willReturn(new StorageWriteResult('memory://target/corrupt-copy', 7, md5('corrupt')));
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('memory://target/corrupt-copy')
            ->willReturn(new ReadableBuffer('corrupt'));
        $storage->expects(self::once())->method('deleteObjectByPath')
            ->with('memory://target/corrupt-copy', 'target');

        try {
            (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->copyRequest());
            self::fail('A bounded backend copy must be rehashed before metadata publication.');
        } catch (InternalErrorException $error) {
            self::assertSame('Copy source multipart integrity does not match object metadata.', $error->getMessage());
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_copy_rejects_unknown_multipart_layout_before_source_read_or_destination_write(): void
    {
        $etag = $this->multipartEtag(['pay', 'load']);
        $this->metadata->transaction(function () use ($etag): void {
            $this->metadata->putObjectMetadata(
                'source',
                'key',
                'owner',
                7,
                $etag,
                'text/plain',
                'memory://source/key',
            );
            $this->metadata->putObjectPartLayout(
                'source',
                'key',
                'null',
                ObjectPartLayout::legacy($etag, 7),
            );
        });
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $storage->expects(self::never())->method('putObject');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Copy source multipart layout cannot be verified.');

        (new CopyObjectHandler($this->metadata, $storage))->handleRequest($this->copyRequest());
    }

    private function copyRequest(): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/target/copied'),
            ['x-amz-copy-source' => '/source/key'],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    /** @param list<string> $parts */
    private function replaceSourceWithMultipartLayout(string $payload, array $parts): void
    {
        self::assertSame($payload, implode('', $parts));
        $offset = 0;
        $layoutParts = [];
        foreach ($parts as $index => $part) {
            $layoutParts[] = new ObjectPart($index + 1, $index + 1, $offset, strlen($part));
            $offset += strlen($part);
        }
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, strlen($payload), $layoutParts);
        $etag = $this->multipartEtag($parts);

        $this->metadata->transaction(function () use ($payload, $etag, $layout): void {
            $this->metadata->putObjectMetadata(
                'source',
                'key',
                'owner',
                strlen($payload),
                $etag,
                'text/plain',
                'memory://source/key',
            );
            $this->metadata->putObjectPartLayout('source', 'key', 'null', $layout);
        });
    }

    /** @param list<string> $parts */
    private function multipartEtag(array $parts): string
    {
        $partMd5s = '';
        foreach ($parts as $part) {
            $partMd5s .= md5($part, binary: true);
        }

        return '"' . md5($partMd5s) . '-' . count($parts) . '"';
    }
}
