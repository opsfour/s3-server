<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class CopyObjectValidationOrderTest extends TestCase
{
    public function test_invalid_legacy_source_tags_are_rejected_before_storage_or_target_mutation(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'source', 'us-east-1');
        $metadata->createBucket('owner', 'target', 'us-east-1');
        $metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            7,
            '"321c3cf486ed509164edec1e1981fec8"',
            'text/plain',
            'source/physical',
        );
        $metadata->putObjectTagging('source', 'key', [
            ['key' => 'legacy', 'value' => "control\u{000B}"],
        ]);

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $storage->expects(self::never())->method('putObject');
        $storage->expects(self::never())->method('copyObject');

        $this->expectException(InternalErrorException::class);
        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request());
        } finally {
            self::assertNull($metadata->getObjectMetadata('target', 'copied'));
            self::assertSame([], $metadata->getObjectTagging('target', 'copied'));
        }
    }

    public function test_invalid_replacement_metadata_is_rejected_before_storage_is_read_or_written(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'source', 'us-east-1');
        $metadata->createBucket('owner', 'target', 'us-east-1');
        $metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            7,
            '"321c3cf486ed509164edec1e1981fec8"',
            'text/plain',
            'source/physical',
        );

        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $storage->expects(self::never())->method('putObject');
        $storage->expects(self::never())->method('copyObject');

        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://localhost/target/copied'),
            [
                'x-amz-copy-source' => '/source/key',
                'x-amz-metadata-directive' => 'REPLACE',
                'x-amz-meta-__internal' => 'forbidden',
            ],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidArgumentException::class);
        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($request);
        } finally {
            self::assertNull($metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_repeated_copy_source_is_rejected_before_storage_is_read_or_written(): void
    {
        [$metadata, $storage] = $this->sourceWithStorage([]);
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://localhost/target/copied'),
            ['x-amz-copy-source' => ['/source/key', '/source/other']],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('x-amz-copy-source must be supplied at most once.');
        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($request);
        } finally {
            self::assertNull($metadata->getObjectMetadata('target', 'copied'));
        }
    }

    public function test_copy_directive_preserves_source_expires_metadata(): void
    {
        [$metadata, $storage] = $this->sourceWithStorage([
            '__expires' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        ]);

        (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request());

        self::assertSame(
            'Wed, 21 Oct 2026 07:28:00 GMT',
            $metadata->getObjectMetadata('target', 'copied')?->userMetadata['__expires'] ?? null,
        );
    }

    public function test_replace_directive_stores_request_expires_metadata(): void
    {
        [$metadata, $storage] = $this->sourceWithStorage([
            '__expires' => 'Wed, 21 Oct 2026 07:28:00 GMT',
        ]);

        (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request([
            'x-amz-metadata-directive' => 'REPLACE',
            'expires' => 'Thu, 22 Oct 2026 08:29:00 GMT',
        ]));

        self::assertSame(
            'Thu, 22 Oct 2026 08:29:00 GMT',
            $metadata->getObjectMetadata('target', 'copied')?->userMetadata['__expires'] ?? null,
        );
    }

    public function test_explicit_delete_marker_copy_source_is_an_invalid_request(): void
    {
        [$metadata, $storage] = $this->sourceWithStorage([]);
        $metadata->setBucketVersioning('source', 'Enabled');
        $deleteMarker = $metadata->deleteObjectVersioned('source', 'key', 'owner');

        $this->expectException(InvalidRequestException::class);
        try {
            (new CopyObjectHandler($metadata, $storage))->handleRequest($this->request([
                'x-amz-copy-source' => '/source/key?versionId=' . rawurlencode($deleteMarker),
            ]));
        } finally {
            self::assertNull($metadata->getObjectMetadata('target', 'copied'));
        }
    }

    /**
     * @param array<string, string> $userMetadata
     * @return array{SqliteMetadataStore, InMemoryBackend}
     */
    private function sourceWithStorage(array $userMetadata): array
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('owner', 'source', 'us-east-1');
        $metadata->createBucket('owner', 'target', 'us-east-1');
        $storage = new InMemoryBackend();
        $storage->createBucket('source');
        $storage->createBucket('target');
        $write = $storage->putObject('source', 'key', new ReadableBuffer('payload'));
        $metadata->putObjectMetadata(
            'source',
            'key',
            'owner',
            $write->size,
            '"' . $write->md5Hex . '"',
            'text/plain',
            $write->path,
            userMetadata: $userMetadata,
        );

        return [$metadata, $storage];
    }

    /** @param array<non-empty-string, string> $headers */
    private function request(array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://localhost/target/copied'),
            ['x-amz-copy-source' => '/source/key', ...$headers],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
