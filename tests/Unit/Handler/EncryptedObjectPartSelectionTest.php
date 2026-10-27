<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class EncryptedObjectPartSelectionTest extends TestCase
{
    private string $metadataPath = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->metadataPath = sys_get_temp_dir() . '/s3-encrypted-part-selection-' . bin2hex(random_bytes(5)) . '.sqlite';
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

    /** @return iterable<string, array{string, string, int}> */
    public static function checksumAlgorithms(): iterable
    {
        yield 'CRC32' => ['crc32', 'x-amz-checksum-crc32', 4];
        yield 'CRC32C' => ['crc32c', 'x-amz-checksum-crc32c', 4];
        yield 'SHA1' => ['sha1', 'x-amz-checksum-sha1', 20];
        yield 'SHA256' => ['sha256', 'x-amz-checksum-sha256', 32];
    }

    #[DataProvider('checksumAlgorithms')]
    public function test_get_and_head_emit_only_the_selected_verified_part_checksum(
        string $algorithm,
        string $expectedHeader,
        int $decodedLength,
    ): void {
        $selectedChecksum = base64_encode(str_repeat("\x01", $decodedLength));
        $objectChecksum = base64_encode(str_repeat("\x02", $decodedLength));
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3, $algorithm, $selectedChecksum),
        ]);
        $this->publish('checksummed', 'objects/checksummed', 'abcDEF', $layout, [
            'checksum-' . $algorithm => $objectChecksum,
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::exactly(2))->method('getObjectByPath')
            ->with('objects/checksummed', 3, 3)
            ->willReturnCallback(static fn(): ReadableBuffer => new ReadableBuffer('DEF'));
        $getHandler = new GetObjectHandler($this->metadata, $storage);
        $headHandler = new HeadObjectHandler($this->metadata);

        $get = $getHandler->handleRequest($this->request(
            'GET',
            '/bucket/checksummed?partNumber=2',
            ['x-amz-checksum-mode' => 'ENABLED'],
        ));
        $head = $headHandler->handleRequest($this->request(
            'HEAD',
            '/bucket/checksummed?partNumber=2',
            ['x-amz-checksum-mode' => 'ENABLED'],
        ));
        $getWithoutMode = $getHandler->handleRequest($this->request('GET', '/bucket/checksummed?partNumber=2'));
        $headWithoutMode = $headHandler->handleRequest($this->request('HEAD', '/bucket/checksummed?partNumber=2'));

        self::assertSame('DEF', \Amp\ByteStream\buffer($get->getBody()));
        self::assertSame($selectedChecksum, $get->getHeader($expectedHeader));
        self::assertSame($selectedChecksum, $head->getHeader($expectedHeader));
        self::assertNotSame($objectChecksum, $get->getHeader($expectedHeader));
        self::assertNull($get->getHeader('x-amz-checksum-type'));
        self::assertNull($head->getHeader('x-amz-checksum-type'));
        self::assertNoChecksumHeaders($getWithoutMode);
        self::assertNoChecksumHeaders($headWithoutMode);
    }

    public function test_checksum_mode_does_not_fall_back_to_complete_object_checksum(): void
    {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3),
        ]);
        $this->publish('missing-checksum', 'objects/missing-checksum', 'abcDEF', $layout, [
            'checksum-sha256' => base64_encode(hash('sha256', 'abcDEF', true)),
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/missing-checksum', 3, 3)
            ->willReturn(new ReadableBuffer('DEF'));

        $get = (new GetObjectHandler($this->metadata, $storage))->handleRequest($this->request(
            'GET',
            '/bucket/missing-checksum?partNumber=2',
            ['x-amz-checksum-mode' => 'ENABLED'],
        ));
        $head = (new HeadObjectHandler($this->metadata))->handleRequest($this->request(
            'HEAD',
            '/bucket/missing-checksum?partNumber=2',
            ['x-amz-checksum-mode' => 'ENABLED'],
        ));

        self::assertNoChecksumHeaders($get);
        self::assertNoChecksumHeaders($head);
    }

    /** @return iterable<string, array{string, string}> */
    public static function invalidPersistedChecksums(): iterable
    {
        yield 'invalid Base64' => ['sha256', '%%%'];
        yield 'short CRC32' => ['crc32', base64_encode(str_repeat("\0", 3))];
        yield 'long CRC32C' => ['crc32c', base64_encode(str_repeat("\0", 5))];
        yield 'short SHA1' => ['sha1', base64_encode(str_repeat("\0", 19))];
        yield 'long SHA256' => ['sha256', base64_encode(str_repeat("\0", 33))];
    }

    #[DataProvider('invalidPersistedChecksums')]
    public function test_corrupt_selected_part_checksum_fails_get_and_head(
        string $algorithm,
        string $checksum,
    ): void {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3, $algorithm, $checksum),
        ]);
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $write = $storage->putObject('bucket', 'corrupt-checksum', new ReadableBuffer('abcDEF'));
        $this->publish('corrupt-checksum', $write->path, 'abcDEF', $layout);

        foreach (['GET', 'HEAD'] as $method) {
            $handler = $method === 'GET'
                ? new GetObjectHandler($this->metadata, $storage)
                : new HeadObjectHandler($this->metadata);
            try {
                $handler->handleRequest($this->request(
                    $method,
                    '/bucket/corrupt-checksum?partNumber=2',
                    ['x-amz-checksum-mode' => 'ENABLED'],
                ));
                self::fail("{$method} accepted corrupt persisted part-checksum metadata.");
            } catch (InternalErrorException) {
            }
        }
    }

    /** @return iterable<string, array{'AES256'|'SSE-C'}> */
    public static function encryptionModes(): iterable
    {
        yield 'SSE-S3' => ['AES256'];
        yield 'SSE-C' => ['SSE-C'];
    }

    /** @param 'AES256'|'SSE-C' $mode */
    #[DataProvider('encryptionModes')]
    public function test_encrypted_get_and_head_match_unencrypted_selected_part(string $mode): void
    {
        $plaintext = 'abcDEFGH';
        $checksum = base64_encode(hash('sha256', 'DEFGH', true));
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 8, [
            new ObjectPart(1, 8, 0, 3),
            new ObjectPart(2, 3, 3, 5, 'sha256', $checksum),
        ]);
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $plainWrite = $storage->putObject('bucket', 'plain', new ReadableBuffer($plaintext));
        $this->publish('plain', $plainWrite->path, $plaintext, $layout);

        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $customerKey = hash('sha256', 'story-3.3-customer-key', true);
        $requestHeaders = ['x-amz-checksum-mode' => 'ENABLED'];
        if ($mode === 'SSE-C') {
            $encrypted = $encryption->encryptSseC($plaintext, $customerKey);
            $userMetadata = [
                '__sse-algorithm' => 'SSE-C',
                '__sse-customer-key-md5' => base64_encode(md5($customerKey, true)),
                '__sse-iv' => $encrypted['iv'],
                '__sse-tag' => $encrypted['tag'],
            ];
            $requestHeaders += self::sseCHeaders($customerKey);
        } else {
            $encrypted = $encryption->encryptSseS3($plaintext);
            $userMetadata = [
                '__sse-algorithm' => 'AES256',
                '__sse-key' => $encrypted['encryptedDataKey'],
                '__sse-iv' => $encrypted['iv'],
                '__sse-tag' => $encrypted['tag'],
            ];
        }
        $encryptedWrite = $storage->putObject('bucket', 'encrypted', new ReadableBuffer($encrypted['ciphertext']));
        $this->publish('encrypted', $encryptedWrite->path, $plaintext, $layout, userMetadata: $userMetadata);

        $getHandler = new GetObjectHandler($this->metadata, $storage, $encryption);
        $plainGet = $getHandler->handleRequest($this->request(
            'GET',
            '/bucket/plain?partNumber=2',
            ['x-amz-checksum-mode' => 'ENABLED'],
        ));
        $encryptedGet = $getHandler->handleRequest($this->request(
            'GET',
            '/bucket/encrypted?partNumber=2',
            $requestHeaders,
        ));
        $headHandler = new HeadObjectHandler($this->metadata);
        $plainHead = $headHandler->handleRequest($this->request(
            'HEAD',
            '/bucket/plain?partNumber=2',
            ['x-amz-checksum-mode' => 'ENABLED'],
        ));
        $encryptedHead = $headHandler->handleRequest($this->request(
            'HEAD',
            '/bucket/encrypted?partNumber=2',
            $requestHeaders,
        ));

        self::assertSame('DEFGH', \Amp\ByteStream\buffer($plainGet->getBody()));
        self::assertSame('DEFGH', \Amp\ByteStream\buffer($encryptedGet->getBody()));
        foreach (['Content-Range', 'Content-Length', 'x-amz-mp-parts-count', 'x-amz-checksum-sha256'] as $header) {
            self::assertSame($plainGet->getHeader($header), $encryptedGet->getHeader($header), $header);
        }
        self::assertSame(206, $encryptedGet->getStatus());
        self::assertSame(200, $encryptedHead->getStatus());
        foreach (['Content-Length', 'x-amz-mp-parts-count', 'x-amz-checksum-sha256'] as $header) {
            self::assertSame($plainHead->getHeader($header), $encryptedHead->getHeader($header), $header);
        }
        self::assertSame('', \Amp\ByteStream\buffer($encryptedHead->getBody()));
        self::assertNull($encryptedGet->getHeader('x-amz-checksum-type'));
        self::assertNull($encryptedHead->getHeader('x-amz-checksum-type'));
    }

    public function test_sse_c_part_selection_requires_the_correct_key_before_conditions_or_storage(): void
    {
        $customerKey = hash('sha256', 'correct-customer-key', true);
        $wrongKey = hash('sha256', 'wrong-customer-key', true);
        $encryption = new EncryptionService();
        $encrypted = $encryption->encryptSseC('secret', $customerKey);
        $layout = ObjectPartLayout::knownSingle(6);
        $this->publish('customer', 'objects/customer', 'secret', $layout, userMetadata: [
            '__sse-algorithm' => 'SSE-C',
            '__sse-customer-key-md5' => base64_encode(md5($customerKey, true)),
            '__sse-iv' => $encrypted['iv'],
            '__sse-tag' => $encrypted['tag'],
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        foreach (['GET', 'HEAD'] as $method) {
            $handler = $method === 'GET'
                ? new GetObjectHandler($this->metadata, $storage, $encryption)
                : new HeadObjectHandler($this->metadata);
            foreach ([[], self::sseCHeaders($wrongKey)] as $headers) {
                try {
                    $handler->handleRequest($this->request($method, '/bucket/customer?partNumber=1', $headers));
                    self::fail("{$method} accepted missing or incorrect SSE-C credentials.");
                } catch (InvalidArgumentException) {
                }
            }
        }
    }

    public function test_corrupt_stored_encryption_mode_fails_get_and_head_before_storage(): void
    {
        $this->publish(
            'unknown-mode',
            'objects/unknown-mode',
            'secret',
            ObjectPartLayout::knownSingle(6),
            userMetadata: ['__sse-algorithm' => 'aws:kms'],
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        foreach (['GET', 'HEAD'] as $method) {
            $handler = $method === 'GET'
                ? new GetObjectHandler($this->metadata, $storage)
                : new HeadObjectHandler($this->metadata);
            try {
                $handler->handleRequest($this->request($method, '/bucket/unknown-mode?partNumber=1'));
                self::fail("{$method} accepted an unknown stored encryption mode.");
            } catch (InternalErrorException) {
            }
        }
    }

    public function test_missing_stored_sse_c_key_md5_fails_before_request_key_validation(): void
    {
        $customerKey = hash('sha256', 'customer-key-with-missing-md5', true);
        $this->publish(
            'missing-customer-md5',
            'objects/missing-customer-md5',
            'secret',
            ObjectPartLayout::knownSingle(6),
            userMetadata: [
                '__sse-algorithm' => 'SSE-C',
                '__sse-iv' => 'iv',
                '__sse-tag' => 'tag',
            ],
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        foreach (['GET', 'HEAD'] as $method) {
            $handler = $method === 'GET'
                ? new GetObjectHandler($this->metadata, $storage, new EncryptionService())
                : new HeadObjectHandler($this->metadata);
            try {
                $handler->handleRequest($this->request(
                    $method,
                    '/bucket/missing-customer-md5?partNumber=1',
                    self::sseCHeaders($customerKey),
                ));
                self::fail("{$method} accepted missing stored SSE-C key metadata.");
            } catch (InternalErrorException) {
            }
        }
    }

    /** @return iterable<string, array{array<string, string>, array<non-empty-string, string>}> */
    public static function incompleteDecryptMetadata(): iterable
    {
        yield 'SSE-S3 key' => [[
            '__sse-algorithm' => 'AES256',
            '__sse-iv' => 'iv',
            '__sse-tag' => 'tag',
        ], []];
        yield 'SSE-S3 IV' => [[
            '__sse-algorithm' => 'AES256',
            '__sse-key' => 'key',
            '__sse-tag' => 'tag',
        ], []];
        yield 'SSE-S3 tag' => [[
            '__sse-algorithm' => 'AES256',
            '__sse-key' => 'key',
            '__sse-iv' => 'iv',
        ], []];

        $customerKey = hash('sha256', 'incomplete-decrypt-customer-key', true);
        $customerMd5 = base64_encode(md5($customerKey, true));
        yield 'SSE-C IV' => [[
            '__sse-algorithm' => 'SSE-C',
            '__sse-customer-key-md5' => $customerMd5,
            '__sse-tag' => 'tag',
        ], self::sseCHeaders($customerKey)];
        yield 'SSE-C tag' => [[
            '__sse-algorithm' => 'SSE-C',
            '__sse-customer-key-md5' => $customerMd5,
            '__sse-iv' => 'iv',
        ], self::sseCHeaders($customerKey)];
    }

    /**
     * @param array<string, string> $userMetadata
     * @param array<non-empty-string, string> $headers
     */
    #[DataProvider('incompleteDecryptMetadata')]
    public function test_incomplete_decrypt_metadata_fails_after_conditions_but_before_storage(
        array $userMetadata,
        array $headers,
    ): void {
        $this->publish(
            'incomplete-decrypt',
            'objects/incomplete-decrypt',
            'secret',
            ObjectPartLayout::knownSingle(6),
            userMetadata: $userMetadata,
        );
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');
        $handler = new GetObjectHandler($this->metadata, $storage, new EncryptionService());

        try {
            $handler->handleRequest($this->request(
                'GET',
                '/bucket/incomplete-decrypt?partNumber=1',
                [...$headers, 'if-match' => '"wrong"'],
            ));
            self::fail('The complete-object condition must precede decrypt-metadata validation.');
        } catch (\OpsFour\S3Server\Exception\PreconditionFailedException) {
        }

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored object encryption metadata is incomplete.');
        $handler->handleRequest($this->request(
            'GET',
            '/bucket/incomplete-decrypt?partNumber=1',
            $headers,
        ));
    }

    public function test_small_encrypted_part_does_not_bypass_complete_object_size_bound(): void
    {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 1),
            new ObjectPart(2, 2, 1, 5),
        ]);
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $this->publish('oversized', 'objects/oversized', 'abcdef', $layout, userMetadata: [
            '__sse-algorithm' => 'AES256',
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::never())->method('getObjectByPath');

        $this->expectException(EntityTooLargeException::class);
        (new GetObjectHandler($this->metadata, $storage, $encryption, maxEncryptedObjectSize: 5))->handleRequest(
            $this->request('GET', '/bucket/oversized?partNumber=1'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCiphertextBodies(): iterable
    {
        yield 'short ciphertext' => ['abcde'];
        yield 'long ciphertext' => ['abcdefg'];
    }

    #[DataProvider('invalidCiphertextBodies')]
    public function test_encrypted_part_rejects_ciphertext_size_mismatch_before_decryption(string $storedBody): void
    {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3),
        ]);
        $this->publish('corrupt-size', 'objects/corrupt-size', 'abcdef', $layout, userMetadata: [
            '__sse-algorithm' => 'AES256',
            '__sse-key' => 'wrapped-key',
            '__sse-iv' => 'iv',
            '__sse-tag' => 'tag',
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/corrupt-size')
            ->willReturn(new ReadableBuffer($storedBody));
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encryption->expects(self::never())->method('decryptSseS3');

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Stored encrypted object size does not match metadata.');
        (new GetObjectHandler($this->metadata, $storage, $encryption))->handleRequest(
            $this->request('GET', '/bucket/corrupt-size?partNumber=2'),
        );
    }

    public function test_encrypted_part_authenticates_complete_ciphertext_before_slicing(): void
    {
        $plaintext = 'abcdef';
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3),
        ]);
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $encrypted = $encryption->encryptSseS3($plaintext);
        $this->publish('bad-tag', 'objects/bad-tag', $plaintext, $layout, userMetadata: [
            '__sse-algorithm' => 'AES256',
            '__sse-key' => $encrypted['encryptedDataKey'],
            '__sse-iv' => $encrypted['iv'],
            '__sse-tag' => base64_encode(str_repeat("\0", 16)),
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/bad-tag')
            ->willReturn(new ReadableBuffer($encrypted['ciphertext']));

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Decryption failed.');
        (new GetObjectHandler($this->metadata, $storage, $encryption))->handleRequest(
            $this->request('GET', '/bucket/bad-tag?partNumber=2'),
        );
    }

    /** @return iterable<string, array{string}> */
    public static function wrongPlaintexts(): iterable
    {
        yield 'short plaintext' => ['abcde'];
        yield 'long plaintext' => ['abcdefg'];
    }

    #[DataProvider('wrongPlaintexts')]
    public function test_encrypted_part_rejects_decrypted_size_mismatch_before_slicing(string $plaintext): void
    {
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 6, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 3),
        ]);
        $this->publish('wrong-plaintext-size', 'objects/wrong-plaintext-size', 'abcdef', $layout, userMetadata: [
            '__sse-algorithm' => 'AES256',
            '__sse-key' => 'wrapped-key',
            '__sse-iv' => 'iv',
            '__sse-tag' => 'tag',
        ]);
        $storage = $this->createMock(StorageBackend::class);
        $storage->expects(self::once())->method('getObjectByPath')
            ->with('objects/wrong-plaintext-size')
            ->willReturn(new ReadableBuffer('abcdef'));
        $encryption = $this->createMock(EncryptionServiceInterface::class);
        $encryption->expects(self::once())->method('decryptSseS3')->willReturn($plaintext);

        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Decrypted object size does not match metadata.');
        (new GetObjectHandler($this->metadata, $storage, $encryption))->handleRequest(
            $this->request('GET', '/bucket/wrong-plaintext-size?partNumber=2'),
        );
    }

    public function test_numbered_encrypted_version_uses_its_own_layout_and_encryption_metadata(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $oldPlaintext = 'aaaBBBB';
        $newPlaintext = 'ccDDDDD';
        $oldEncrypted = $encryption->encryptSseS3($oldPlaintext);
        $newEncrypted = $encryption->encryptSseS3($newPlaintext);
        $oldWrite = $storage->putObject('bucket', 'old', new ReadableBuffer($oldEncrypted['ciphertext']));
        $newWrite = $storage->putObject('bucket', 'new', new ReadableBuffer($newEncrypted['ciphertext']));
        $oldLayout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 9, 0, 3),
            new ObjectPart(2, 4, 3, 4),
        ]);
        $newLayout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 2, 0, 2),
            new ObjectPart(2, 7, 2, 5),
        ]);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $oldVersion = '';
        $this->metadata->transaction(function () use (&$oldVersion, $oldPlaintext, $oldWrite, $oldEncrypted, $oldLayout): void {
            $oldVersion = $this->metadata->putObjectVersioned(
                'bucket',
                'versioned',
                'owner',
                strlen($oldPlaintext),
                '"old"',
                'text/plain',
                $oldWrite->path,
                userMetadata: self::sseS3Metadata($oldEncrypted),
            );
            $this->metadata->putObjectPartLayout('bucket', 'versioned', $oldVersion, $oldLayout);
        });
        $this->metadata->transaction(function () use ($newPlaintext, $newWrite, $newEncrypted, $newLayout): void {
            $version = $this->metadata->putObjectVersioned(
                'bucket',
                'versioned',
                'owner',
                strlen($newPlaintext),
                '"new"',
                'text/plain',
                $newWrite->path,
                userMetadata: self::sseS3Metadata($newEncrypted),
            );
            $this->metadata->putObjectPartLayout('bucket', 'versioned', $version, $newLayout);
        });
        $getHandler = new GetObjectHandler($this->metadata, $storage, $encryption);

        $current = $getHandler->handleRequest($this->request('GET', '/bucket/versioned?partNumber=2'));
        $old = $getHandler->handleRequest($this->request(
            'GET',
            '/bucket/versioned?versionId=' . rawurlencode($oldVersion) . '&partNumber=2',
        ));
        $oldHead = (new HeadObjectHandler($this->metadata))->handleRequest($this->request(
            'HEAD',
            '/bucket/versioned?versionId=' . rawurlencode($oldVersion) . '&partNumber=2',
        ));

        self::assertSame('DDDDD', \Amp\ByteStream\buffer($current->getBody()));
        self::assertSame('BBBB', \Amp\ByteStream\buffer($old->getBody()));
        self::assertSame('bytes 3-6/7', $old->getHeader('Content-Range'));
        self::assertSame($oldVersion, $old->getHeader('x-amz-version-id'));
        self::assertSame('4', $oldHead->getHeader('Content-Length'));
        self::assertSame($oldVersion, $oldHead->getHeader('x-amz-version-id'));
    }

    public function test_suspended_literal_null_encrypted_version_uses_its_own_layout_and_key(): void
    {
        $storage = new InMemoryBackend();
        $storage->createBucket('bucket');
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $numberedPlaintext = 'aaaBBBB';
        $nullPlaintext = 'ccDDDDD';
        $numberedEncrypted = $encryption->encryptSseS3($numberedPlaintext);
        $customerKey = hash('sha256', 'literal-null-customer-key', true);
        $nullEncrypted = $encryption->encryptSseC($nullPlaintext, $customerKey);
        $numberedWrite = $storage->putObject('bucket', 'numbered', new ReadableBuffer($numberedEncrypted['ciphertext']));
        $nullWrite = $storage->putObject('bucket', 'null', new ReadableBuffer($nullEncrypted['ciphertext']));
        $numberedLayout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 9, 0, 3),
            new ObjectPart(2, 4, 3, 4),
        ]);
        $nullLayout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 2, 0, 2),
            new ObjectPart(2, 7, 2, 5),
        ]);
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $numberedVersion = '';
        $this->metadata->transaction(function () use (
            &$numberedVersion,
            $numberedPlaintext,
            $numberedWrite,
            $numberedEncrypted,
            $numberedLayout,
        ): void {
            $numberedVersion = $this->metadata->putObjectVersioned(
                'bucket',
                'suspended',
                'owner',
                strlen($numberedPlaintext),
                '"numbered"',
                'text/plain',
                $numberedWrite->path,
                userMetadata: self::sseS3Metadata($numberedEncrypted),
            );
            $this->metadata->putObjectPartLayout('bucket', 'suspended', $numberedVersion, $numberedLayout);
        });
        $this->metadata->setBucketVersioning('bucket', 'Suspended');
        $this->metadata->transaction(function () use ($nullPlaintext, $nullWrite, $nullEncrypted, $customerKey, $nullLayout): void {
            $this->metadata->putObjectMetadata(
                'bucket',
                'suspended',
                'owner',
                strlen($nullPlaintext),
                '"null"',
                'text/plain',
                $nullWrite->path,
                userMetadata: [
                    '__sse-algorithm' => 'SSE-C',
                    '__sse-customer-key-md5' => base64_encode(md5($customerKey, true)),
                    '__sse-iv' => $nullEncrypted['iv'],
                    '__sse-tag' => $nullEncrypted['tag'],
                ],
            );
            $this->metadata->putObjectPartLayout('bucket', 'suspended', 'null', $nullLayout);
        });
        $getHandler = new GetObjectHandler($this->metadata, $storage, $encryption);
        $customerHeaders = self::sseCHeaders($customerKey);

        $literalNull = $getHandler->handleRequest($this->request(
            'GET',
            '/bucket/suspended?versionId=null&partNumber=2',
            $customerHeaders,
        ));
        $numbered = $getHandler->handleRequest($this->request(
            'GET',
            '/bucket/suspended?versionId=' . rawurlencode($numberedVersion) . '&partNumber=2',
        ));
        $literalNullHead = (new HeadObjectHandler($this->metadata))->handleRequest($this->request(
            'HEAD',
            '/bucket/suspended?versionId=null&partNumber=2',
            $customerHeaders,
        ));

        self::assertSame('DDDDD', \Amp\ByteStream\buffer($literalNull->getBody()));
        self::assertSame('bytes 2-6/7', $literalNull->getHeader('Content-Range'));
        self::assertSame('null', $literalNull->getHeader('x-amz-version-id'));
        self::assertSame('BBBB', \Amp\ByteStream\buffer($numbered->getBody()));
        self::assertSame($numberedVersion, $numbered->getHeader('x-amz-version-id'));
        self::assertSame('5', $literalNullHead->getHeader('Content-Length'));
        self::assertSame('null', $literalNullHead->getHeader('x-amz-version-id'));
    }

    public function test_restored_encrypted_object_uses_restored_tier_then_selects_plaintext_part(): void
    {
        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $encrypted = $encryption->encryptSseS3('abcDEFG');
        $coldWrite = $cold->putObject('bucket', 'archive-cold', new ReadableBuffer($encrypted['ciphertext']));
        $hotWrite = $hot->putObject('bucket', 'archive-hot', new ReadableBuffer($encrypted['ciphertext']));
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 7, [
            new ObjectPart(1, 1, 0, 3),
            new ObjectPart(2, 2, 3, 4),
        ]);
        $this->publish(
            'archive',
            $coldWrite->path,
            'abcDEFG',
            $layout,
            userMetadata: self::sseS3Metadata($encrypted),
        );
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

        $response = (new GetObjectHandler($this->metadata, $hot, $encryption, storageTiers: $tiers))->handleRequest(
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

    /**
     * @param array{ciphertext: string, encryptedDataKey: string, iv: string, tag: string} $encrypted
     * @return array{__sse-algorithm: string, __sse-key: string, __sse-iv: string, __sse-tag: string}
     */
    private static function sseS3Metadata(array $encrypted): array
    {
        return [
            '__sse-algorithm' => 'AES256',
            '__sse-key' => $encrypted['encryptedDataKey'],
            '__sse-iv' => $encrypted['iv'],
            '__sse-tag' => $encrypted['tag'],
        ];
    }

    /** @return list<non-empty-string> */
    private static function checksumHeaders(): array
    {
        return [
            'x-amz-checksum-crc32',
            'x-amz-checksum-crc32c',
            'x-amz-checksum-sha1',
            'x-amz-checksum-sha256',
            'x-amz-checksum-type',
        ];
    }

    private static function assertNoChecksumHeaders(\Amp\Http\Server\Response $response): void
    {
        foreach (self::checksumHeaders() as $header) {
            self::assertNull($response->getHeader($header), $header);
        }
    }

    /**
     * @return array{
     *     x-amz-server-side-encryption-customer-algorithm: 'AES256',
     *     x-amz-server-side-encryption-customer-key: string,
     *     x-amz-server-side-encryption-customer-key-md5: string
     * }
     */
    private static function sseCHeaders(string $key): array
    {
        return [
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($key),
            'x-amz-server-side-encryption-customer-key-md5' => base64_encode(md5($key, true)),
        ];
    }

    /**
     * @param 'GET'|'HEAD' $method
     * @param array<non-empty-string, string> $headers
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
