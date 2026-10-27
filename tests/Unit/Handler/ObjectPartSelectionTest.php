<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidRangeException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Exception\PreconditionFailedException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;

final class ObjectPartSelectionTest extends TestCase
{
    private string $metadataPath = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->metadataPath = sys_get_temp_dir() . '/s3-part-selection-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->metadataPath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->metadataPath);
        @unlink($this->metadataPath . '-wal');
        @unlink($this->metadataPath . '-shm');
    }

    public function test_get_and_head_select_completion_ordinal_and_emit_part_headers(): void
    {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 9, [
            new ObjectPart(1, 8, 0, 4),
            new ObjectPart(2, 3, 4, 5),
        ]);
        $this->publish('key', 'objects/key', 'abcdefghi', $layout, [
            'checksum-sha256' => base64_encode(hash('sha256', 'abcdefghi', true)),
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/key', 4, 5)
            ->willReturn(new ReadableBuffer('efghi'));

        $get = (new GetObjectHandler($this->metadata, $storage))->handleRequest(
            $this->request('GET', '/bucket/key?partNumber=2', ['x-amz-checksum-mode' => 'ENABLED']),
        );
        $head = (new HeadObjectHandler($this->metadata))->handleRequest(
            $this->request('HEAD', '/bucket/key?partNumber=2', ['x-amz-checksum-mode' => 'ENABLED']),
        );

        self::assertSame(206, $get->getStatus());
        self::assertSame('efghi', \Amp\ByteStream\buffer($get->getBody()));
        self::assertSame('bytes 4-8/9', $get->getHeader('Content-Range'));
        self::assertSame('5', $get->getHeader('Content-Length'));
        self::assertSame('2', $get->getHeader('x-amz-mp-parts-count'));
        self::assertNull($get->getHeader('x-amz-checksum-sha256'));
        self::assertNull($get->getHeader('x-amz-checksum-type'));

        self::assertSame(200, $head->getStatus());
        self::assertSame('', \Amp\ByteStream\buffer($head->getBody()));
        self::assertSame('5', $head->getHeader('Content-Length'));
        self::assertSame('2', $head->getHeader('x-amz-mp-parts-count'));
        self::assertNull($head->getHeader('Content-Range'));
        self::assertNull($head->getHeader('x-amz-checksum-sha256'));
    }

    public function test_one_part_multipart_emits_count_while_known_single_does_not(): void
    {
        $multipart = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 3, [
            new ObjectPart(1, 17, 0, 3),
        ]);
        $this->publish('multipart', 'objects/multipart', 'abc', $multipart);
        $this->publish('single', 'objects/single', 'xyz', ObjectPartLayout::knownSingle(3));

        $multipartHead = (new HeadObjectHandler($this->metadata))->handleRequest(
            $this->request('HEAD', '/bucket/multipart?partNumber=1'),
        );
        $singleHead = (new HeadObjectHandler($this->metadata))->handleRequest(
            $this->request('HEAD', '/bucket/single?partNumber=1'),
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/single', 0, 3)
            ->willReturn(new ReadableBuffer('xyz'));
        $singleGet = (new GetObjectHandler($this->metadata, $storage))->handleRequest(
            $this->request('GET', '/bucket/single?partNumber=1'),
        );

        self::assertSame('1', $multipartHead->getHeader('x-amz-mp-parts-count'));
        self::assertNull($singleHead->getHeader('x-amz-mp-parts-count'));
        self::assertSame('3', $singleHead->getHeader('Content-Length'));
        self::assertSame(206, $singleGet->getStatus());
        self::assertSame('xyz', \Amp\ByteStream\buffer($singleGet->getBody()));
        self::assertSame('bytes 0-2/3', $singleGet->getHeader('Content-Range'));
        self::assertSame('3', $singleGet->getHeader('Content-Length'));
        self::assertNull($singleGet->getHeader('x-amz-mp-parts-count'));
    }

    /** @return iterable<string, array{'GET'|'HEAD', string, class-string<\Throwable>}> */
    public static function invalidRequests(): iterable
    {
        $cases = [
            'empty' => ['/bucket/key?partNumber=', InvalidArgumentException::class],
            'non-decimal' => ['/bucket/key?partNumber=1x', InvalidArgumentException::class],
            'zero' => ['/bucket/key?partNumber=0', InvalidArgumentException::class],
            'above maximum' => ['/bucket/key?partNumber=10001', InvalidArgumentException::class],
            'range conflict' => ['/bucket/key?partNumber=1', InvalidRequestException::class],
        ];
        foreach (['GET', 'HEAD'] as $method) {
            foreach ($cases as $name => [$uri, $exception]) {
                yield $method . ' ' . $name => [$method, $uri, $exception];
            }
        }
    }

    /**
     * @param 'GET'|'HEAD' $method
     * @param class-string<\Throwable> $expected
     */
    #[DataProvider('invalidRequests')]
    public function test_invalid_part_requests_fail_before_metadata_or_storage(string $method, string $uri, string $expected): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $headers = $expected === InvalidRequestException::class ? ['range' => 'bytes=0-0'] : [];

        $this->expectException($expected);
        ($method === 'GET' ? new GetObjectHandler($metadata, $storage) : new HeadObjectHandler($metadata))
            ->handleRequest($this->request($method, $uri, $headers));
    }

    /** @return iterable<string, array{'GET'|'HEAD'}> */
    public static function methods(): iterable
    {
        yield 'GET' => ['GET'];
        yield 'HEAD' => ['HEAD'];
    }

    /** @return iterable<string, array{'GET'|'HEAD', string}> */
    public static function unknownLayouts(): iterable
    {
        foreach (['GET', 'HEAD'] as $method) {
            yield $method . ' generic unknown' => [$method, ObjectPartLayout::UNKNOWN];
            yield $method . ' unknown multipart' => [$method, ObjectPartLayout::UNKNOWN_MULTIPART];
        }
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('unknownLayouts')]
    public function test_unknown_layout_fails_closed_before_storage(string $method, string $kind): void
    {
        $layout = $kind === ObjectPartLayout::UNKNOWN_MULTIPART
            ? new ObjectPartLayout($kind, 6, compatibilityPartCount: 2)
            : new ObjectPartLayout($kind, 6);
        $this->publish('legacy', 'objects/legacy', 'legacy', $layout);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(NotImplementedException::class);
        ($method === 'GET' ? new GetObjectHandler($this->metadata, $storage) : new HeadObjectHandler($this->metadata))
            ->handleRequest($this->request($method, '/bucket/legacy?partNumber=1'));
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('methods')]
    public function test_layout_size_mismatch_fails_before_storage_or_response(string $method): void
    {
        $layout = ObjectPartLayout::knownSingle(3);
        $object = new ObjectInfo(
            'bucket',
            'key',
            4,
            '"etag"',
            systemMetadata: ['storagePath' => 'objects/key'],
            partLayout: $layout->summary(1),
        );
        $metadata = $this->layoutMetadata($object, $layout);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(InternalErrorException::class);
        ($method === 'GET' ? new GetObjectHandler($metadata, $storage) : new HeadObjectHandler($metadata))
            ->handleRequest($this->request($method, '/bucket/key?partNumber=1'));
    }

    public function test_out_of_count_and_zero_length_parts_return_precise_invalid_range(): void
    {
        $this->publish('single', 'objects/single', 'abc', ObjectPartLayout::knownSingle(3));
        $this->publish('empty', 'objects/empty', '', ObjectPartLayout::knownSingle(0));
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        foreach (['GET', 'HEAD'] as $method) {
            foreach ([['/bucket/single?partNumber=2', 'bytes */3'], ['/bucket/empty?partNumber=1', 'bytes */0']] as [$uri, $range]) {
                try {
                    ($method === 'GET' ? new GetObjectHandler($this->metadata, $storage) : new HeadObjectHandler($this->metadata))
                        ->handleRequest($this->request($method, $uri));
                    self::fail('The unavailable part must return InvalidRange.');
                } catch (InvalidRangeException $error) {
                    self::assertSame($range, $error->getExtraHeaders()['Content-Range'] ?? null);
                }
            }
        }
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('methods')]
    public function test_complete_object_conditions_run_before_encrypted_story_boundary(string $method): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $encrypted = $encryption->encryptSseS3('secret');
        $this->publish(
            'encrypted',
            'objects/encrypted',
            'secret',
            ObjectPartLayout::knownSingle(6),
            userMetadata: [
                '__sse-algorithm' => 'AES256',
                '__sse-key' => $encrypted['encryptedDataKey'],
                '__sse-iv' => $encrypted['iv'],
                '__sse-tag' => $encrypted['tag'],
            ],
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $handler = $method === 'GET'
            ? new GetObjectHandler($this->metadata, $storage, $encryption)
            : new HeadObjectHandler($this->metadata);

        try {
            $handler->handleRequest($this->request($method, '/bucket/encrypted?partNumber=1', ['if-match' => '"wrong"']));
            self::fail('The complete-object condition must be evaluated first.');
        } catch (PreconditionFailedException) {
        }

        $this->expectException(NotImplementedException::class);
        $handler->handleRequest($this->request($method, '/bucket/encrypted?partNumber=1'));
    }

    public function test_failed_complete_object_condition_precedes_layout_child_read(): void
    {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3),
        ]);
        $this->publish('conditional', 'objects/conditional', 'abcdef', $layout);
        $pdo = new \PDO('sqlite:' . $this->metadataPath);
        $pdo->exec("DELETE FROM s3_object_parts WHERE object_id = (SELECT id FROM s3_objects WHERE bucket = 'bucket' AND key_name = 'conditional')");
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(PreconditionFailedException::class);
        (new GetObjectHandler($this->metadata, $storage))->handleRequest(
            $this->request('GET', '/bucket/conditional?partNumber=1', ['if-match' => '"wrong"']),
        );
    }

    /** @param 'GET'|'HEAD' $method */
    #[DataProvider('methods')]
    public function test_sse_c_validation_and_conditions_precede_encrypted_story_boundary(string $method): void
    {
        $customerKey = random_bytes(32);
        $keyMd5 = base64_encode(md5($customerKey, true));
        $this->publish(
            'customer',
            'objects/customer',
            'secret',
            ObjectPartLayout::knownSingle(6),
            userMetadata: [
                '__sse-algorithm' => 'SSE-C',
                '__sse-customer-key-md5' => $keyMd5,
            ],
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $handler = $method === 'GET'
            ? new GetObjectHandler($this->metadata, $storage)
            : new HeadObjectHandler($this->metadata);

        try {
            $handler->handleRequest($this->request($method, '/bucket/customer?partNumber=1'));
            self::fail('SSE-C validation must remain authoritative.');
        } catch (InvalidArgumentException $error) {
            self::assertStringContainsString('SSE-C headers are required', $error->getMessage());
        }

        $headers = [
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
            'x-amz-server-side-encryption-customer-key-md5' => $keyMd5,
        ];
        try {
            $handler->handleRequest($this->request($method, '/bucket/customer?partNumber=1', [
                ...$headers,
                'if-match' => '"wrong"',
            ]));
            self::fail('The complete-object condition must precede the encrypted boundary.');
        } catch (PreconditionFailedException) {
        }

        $this->expectException(NotImplementedException::class);
        $handler->handleRequest($this->request($method, '/bucket/customer?partNumber=1', $headers));
    }

    public function test_explicit_numbered_and_literal_null_versions_use_their_own_layouts(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $oldWrite = $storage->putObject('bucket', 'key', new ReadableBuffer('aaaBBBB'));
        $oldVersion = '';
        $oldLayout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 9, 0, 3),
            new ObjectPart(2, 4, 3, 4),
        ]);
        $this->metadata->transaction(function () use (&$oldVersion, $oldWrite, $oldLayout): void {
            $oldVersion = $this->metadata->putObjectVersioned('bucket', 'key', 'owner', 7, '"old"', 'text/plain', $oldWrite->path);
            $this->metadata->putObjectPartLayout('bucket', 'key', $oldVersion, $oldLayout);
        });
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $nullWrite = $storage->putObject('bucket', 'key', new ReadableBuffer('ccDDDDD'));
        $nullLayout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 2, 0, 2),
            new ObjectPart(2, 7, 2, 5),
        ]);
        $this->metadata->transaction(function () use ($nullWrite, $nullLayout): void {
            $this->metadata->putObjectMetadata('bucket', 'key', 'owner', 7, '"null"', 'text/plain', $nullWrite->path);
            $this->metadata->putObjectPartLayout('bucket', 'key', 'null', $nullLayout);
        });
        $handler = new GetObjectHandler($this->metadata, $storage);

        $current = $handler->handleRequest($this->request('GET', '/bucket/key?partNumber=2'));
        $literalNull = $handler->handleRequest($this->request('GET', '/bucket/key?versionId=null&partNumber=2'));
        $numbered = $handler->handleRequest($this->request('GET', '/bucket/key?versionId=' . rawurlencode($oldVersion) . '&partNumber=2'));
        $headHandler = new HeadObjectHandler($this->metadata);
        $literalNullHead = $headHandler->handleRequest($this->request('HEAD', '/bucket/key?versionId=null&partNumber=2'));
        $numberedHead = $headHandler->handleRequest($this->request('HEAD', '/bucket/key?versionId=' . rawurlencode($oldVersion) . '&partNumber=2'));

        self::assertSame('DDDDD', \Amp\ByteStream\buffer($current->getBody()));
        self::assertSame('DDDDD', \Amp\ByteStream\buffer($literalNull->getBody()));
        self::assertSame('BBBB', \Amp\ByteStream\buffer($numbered->getBody()));
        self::assertSame('null', $current->getHeader('x-amz-version-id'));
        self::assertSame('null', $literalNull->getHeader('x-amz-version-id'));
        self::assertSame($oldVersion, $numbered->getHeader('x-amz-version-id'));
        self::assertSame(200, $literalNullHead->getStatus());
        self::assertSame('5', $literalNullHead->getHeader('Content-Length'));
        self::assertSame('2', $literalNullHead->getHeader('x-amz-mp-parts-count'));
        self::assertSame('null', $literalNullHead->getHeader('x-amz-version-id'));
        self::assertNull($literalNullHead->getHeader('Content-Range'));
        self::assertSame('', \Amp\ByteStream\buffer($literalNullHead->getBody()));
        self::assertSame(200, $numberedHead->getStatus());
        self::assertSame('4', $numberedHead->getHeader('Content-Length'));
        self::assertSame('2', $numberedHead->getHeader('x-amz-mp-parts-count'));
        self::assertSame($oldVersion, $numberedHead->getHeader('x-amz-version-id'));
        self::assertNull($numberedHead->getHeader('Content-Range'));
        self::assertSame('', \Amp\ByteStream\buffer($numberedHead->getBody()));
    }

    public function test_restored_tier_reads_only_the_selected_part(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $coldWrite = $cold->putObject('bucket', 'archive', new ReadableBuffer('wrong-data'));
        $hotWrite = $hot->putObject('bucket', 'archive', new ReadableBuffer('abcDEFG'));
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 4),
        ]);
        $this->publish('archive', $coldWrite->path, 'abcDEFG', $layout);
        $this->metadata->updateObjectPlacement('bucket', 'archive', null, 'GLACIER', 'GLACIER', $coldWrite->path);
        $this->metadata->updateObjectRestoreStateWithTier(
            'bucket',
            'archive',
            null,
            'restored',
            $hotWrite->path,
            new \DateTimeImmutable('+1 day'),
            'STANDARD',
        );
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $cold, restoreRequired: true),
        ]);

        $response = (new GetObjectHandler($this->metadata, $hot, storageTiers: $tiers))->handleRequest(
            $this->request('GET', '/bucket/archive?partNumber=2'),
        );

        self::assertSame('DEFG', \Amp\ByteStream\buffer($response->getBody()));
        self::assertSame('bytes 3-6/7', $response->getHeader('Content-Range'));
    }

    /**
     * @param array<string, string> $systemMetadata
     * @param array<string, string> $userMetadata
     */
    private function publish(
        string $key,
        string $storagePath,
        string $body,
        ObjectPartLayout $layout,
        array $systemMetadata = [],
        array $userMetadata = [],
    ): void {
        $this->metadata->transaction(function () use ($key, $storagePath, $body, $layout, $systemMetadata, $userMetadata): void {
            $this->metadata->putObjectMetadata(
                'bucket',
                $key,
                'owner',
                strlen($body),
                '"' . md5($body) . '"',
                'text/plain',
                $storagePath,
                userMetadata: $userMetadata,
                checksumCrc32: $systemMetadata['checksum-crc32'] ?? null,
                checksumCrc32c: $systemMetadata['checksum-crc32c'] ?? null,
                checksumSha1: $systemMetadata['checksum-sha1'] ?? null,
                checksumSha256: $systemMetadata['checksum-sha256'] ?? null,
            );
            $this->metadata->putObjectPartLayout('bucket', $key, 'null', $layout);
        });
    }

    /** @return MetadataStore&ObjectPartLayoutStore&MockObject */
    private function layoutMetadata(ObjectInfo $object, ObjectPartLayout $layout): MetadataStore&ObjectPartLayoutStore&MockObject
    {
        $metadata = $this->createMockForIntersectionOfInterfaces([MetadataStore::class, ObjectPartLayoutStore::class]);
        if (! $metadata instanceof MetadataStore || ! $metadata instanceof ObjectPartLayoutStore) {
            throw new \LogicException('Intersection mock is missing a requested interface.');
        }
        $metadata->method('supportsObjectPartLayouts')->willReturn(true);
        $metadata->method('getBucket')->willReturn(new BucketInfo('bucket', 'owner'));
        $metadata->method('getCurrentObjectVersion')->willReturn($object);
        $metadata->method('getBucketVersioning')->willReturn('');
        $metadata->method('getObjectPartLayout')->willReturn($layout);

        return $metadata;
    }

    /**
     * @param 'GET'|'HEAD' $method
     * @param array<non-empty-string, string|list<string>> $headers
     */
    private function request(string $method, string $uri, array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1' . $uri), $headers);
        $path = parse_url($uri, PHP_URL_PATH);
        self::assertIsString($path);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', ltrim(substr($path, strlen('/bucket')), '/'));
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}
