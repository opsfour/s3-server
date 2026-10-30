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
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Checksum\Crc64NvmeDigest;
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Object\GetObjectAttributesHandler;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\ObjectPartLayoutStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Multipart\MultipartAclSnapshot;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class LocalMultipartCompletionHandlerTest extends TestCase
{
    /** @return iterable<string, array{bool, bool}> */
    public static function modes(): iterable
    {
        yield 'plaintext assembly, literal null' => [false, false];
        yield 'plaintext assembly, versioned' => [false, true];
        yield 'SSE-S3 final, literal null' => [true, false];
        yield 'SSE-S3 final, versioned' => [true, true];
    }

    #[DataProvider('modes')]
    public function test_local_complete_commits_only_the_armed_physical_attempt(bool $encrypted, bool $versioned): void
    {
        $root = sys_get_temp_dir() . '/s3-local-complete-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $storage = new FilesystemBackend($root);
        try {
            $databasePath = $root . '/metadata.sqlite';
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            if ($versioned) {
                $metadata->setBucketVersioning('bucket', 'Enabled');
            }
            $storage->createBucket('bucket');
            $bucket = $metadata->getBucket('bucket');
            self::assertNotNull($bucket);
            $uploadId = bin2hex(random_bytes(16));
            $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
            $crc64Contract = $encrypted && ! $versioned;
            $userMetadata = $encrypted ? [
                ...self::currentPolicyContext(),
                '__sse-algorithm' => EncryptionRequestResolver::SSE_S3,
                '__mpu-part-format' => EncryptedMultipartPartCodec::FORMAT,
            ] : self::currentPolicyContext();
            if ($crc64Contract) {
                $userMetadata['__mpu-checksum-algorithm'] = 'crc64nvme';
                $userMetadata['__mpu-checksum-type'] = 'FULL_OBJECT';
            }
            $metadata->createMultipartUpload(
                $uploadId,
                'bucket',
                'key',
                'owner',
                userMetadata: $userMetadata,
            );
            $plaintextParts = [
                3 => 'local multipart first payload',
                8 => 'tail',
            ];
            $partEtags = [];
            $partChecksums = [];
            foreach ($plaintextParts as $partNumber => $plaintext) {
                $partBody = $encrypted
                    ? EncryptedMultipartPartCodec::encode(
                        $encryption,
                        EncryptionRequestResolver::SSE_S3,
                        $plaintext,
                        null,
                        $bucket->generation,
                        'bucket',
                        $uploadId,
                        $partNumber,
                    ) : $plaintext;
                $part = $storage->putPart('bucket', 'key', $uploadId, $partNumber, new ReadableBuffer($partBody));
                $partEtags[$partNumber] = md5($plaintext);
                $partChecksums[$partNumber] = $crc64Contract
                    ? self::crc64Nvme($plaintext)
                    : base64_encode(hash('sha256', $plaintext, true));
                $metadata->putPartWithChecksum(
                    $uploadId,
                    $partNumber,
                    $partEtags[$partNumber],
                    strlen($plaintext),
                    $part->path,
                    $crc64Contract ? 'crc64nvme' : 'SHA256',
                    $partChecksums[$partNumber],
                );
            }
            $request = self::completionRequestForParts($uploadId, $partEtags);

            $response = (new CompleteMultipartUploadHandler(
                $metadata,
                $storage,
                $encrypted ? $encryption : null,
                requireLocalFencedCompletion: true,
            ))->handleRequest($request);
            self::assertSame(200, $response->getStatus());
            $object = $metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);
            self::assertSame($versioned, $object->versionId !== null);
            self::assertSame(ObjectPartLayout::KNOWN_MULTIPART, $object->partLayout?->kind);
            $layout = $metadata->getObjectPartLayout($object);
            self::assertCount(2, $layout->parts);
            self::assertSame(1, $layout->parts[0]->ordinal);
            self::assertSame(3, $layout->parts[0]->uploadPartNumber);
            self::assertSame(0, $layout->parts[0]->offset);
            self::assertSame(strlen($plaintextParts[3]), $layout->parts[0]->size);
            self::assertSame($crc64Contract ? 'crc64nvme' : 'SHA256', $layout->parts[0]->checksumAlgorithm);
            self::assertSame($partChecksums[3], $layout->parts[0]->checksumValue);
            self::assertSame(2, $layout->parts[1]->ordinal);
            self::assertSame(8, $layout->parts[1]->uploadPartNumber);
            self::assertSame(strlen($plaintextParts[3]), $layout->parts[1]->offset);
            self::assertSame(strlen($plaintextParts[8]), $layout->parts[1]->size);
            self::assertSame($crc64Contract ? 'crc64nvme' : 'SHA256', $layout->parts[1]->checksumAlgorithm);
            self::assertSame($partChecksums[8], $layout->parts[1]->checksumValue);
            $attributesRequest = new Request(
                $request->getClient(),
                'GET',
                Http::new('http://127.0.0.1/bucket/key?attributes'),
                ['x-amz-object-attributes' => 'ObjectParts'],
            );
            $attributesRequest->setAttribute('s3.bucket', 'bucket');
            $attributesRequest->setAttribute('s3.key', 'key');
            $attributesRequest->setAttribute('ownerId', 'owner');
            $attributesBody = buffer((new GetObjectAttributesHandler($metadata))->handleRequest($attributesRequest)->getBody());
            self::assertStringContainsString('<PartsCount>2</PartsCount>', $attributesBody);
            self::assertArrayNotHasKey(
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY,
                $object->userMetadata,
            );
            self::assertArrayNotHasKey(
                PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY,
                $object->userMetadata,
            );
            $path = $object->systemMetadata['storagePath'] ?? null;
            self::assertIsString($path);
            self::assertStringEndsWith($encrypted ? '/final' : '/assembly', $path);
            $physical = buffer($storage->getObjectByPath($path));
            if ($encrypted) {
                self::assertNotSame(implode('', $plaintextParts), $physical);
            } else {
                self::assertSame(implode('', $plaintextParts), $physical);
            }
            if ($crc64Contract) {
                self::assertSame(
                    self::crc64Nvme(implode('', $plaintextParts)),
                    $object->systemMetadata['checksum-crc64nvme'] ?? null,
                );
            }
            self::assertNull($metadata->getMultipartUpload($uploadId));
            $intentCount = (new \PDO('sqlite:' . $databasePath))->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $intentCount);
            self::assertSame(0, (int) $intentCount->fetchColumn());
        } finally {
            $storage->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_local_fence_profile_rejects_unknown_filesystem_decorator_before_writing(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-complete-decorator-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $storage = new FilesystemBackend($root);
        try {
            $metadata = new SqliteMetadataStore($root . '/metadata.sqlite');
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $storage->createBucket('bucket');
            $uploadId = bin2hex(random_bytes(16));
            $metadata->createMultipartUpload(
                $uploadId,
                'bucket',
                'key',
                'owner',
                userMetadata: self::currentPolicyContext(),
            );
            $decorated = new CallbackStorageBackend($storage);

            try {
                (new CompleteMultipartUploadHandler(
                    $metadata,
                    $decorated,
                    requireLocalFencedCompletion: true,
                ))->handleRequest(self::completionRequest($uploadId, md5('not needed')));
                self::fail('An unqualified filesystem decorator entered the local completion path.');
            } catch (InternalErrorException) {
                self::assertSame(0, $decorated->putCalls);
                self::assertSame(0, $decorated->copyCalls);
                self::assertNotNull($metadata->getMultipartUpload($uploadId));
                self::assertDirectoryDoesNotExist($root . '/.assemblies');
            }
        } finally {
            $storage->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_lost_arm_acknowledgement_retires_attempt_before_worker_dispatch(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-complete-arm-ack-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $storage = new FilesystemBackend($root);
        try {
            $databasePath = $root . '/metadata.sqlite';
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $storage->createBucket('bucket');
            $uploadId = bin2hex(random_bytes(16));
            $metadata->createMultipartUpload(
                $uploadId,
                'bucket',
                'key',
                'owner',
                userMetadata: self::currentPolicyContext(),
            );
            $plaintext = 'retained after lost arm acknowledgement';
            $part = $storage->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer($plaintext));
            $etag = md5($plaintext);
            $metadata->putPart($uploadId, 1, $etag, strlen($plaintext), $part->path);

            $proxy = $this->createMockForIntersectionOfInterfaces([
                MetadataStore::class,
                LocalMultipartCompletionIntentStore::class,
                ObjectPartLayoutStore::class,
            ]);
            self::assertInstanceOf(MetadataStore::class, $proxy);
            self::assertInstanceOf(LocalMultipartCompletionIntentStore::class, $proxy);
            self::assertInstanceOf(ObjectPartLayoutStore::class, $proxy);
            $methodNames = [];
            foreach ([MetadataStore::class, LocalMultipartCompletionIntentStore::class, ObjectPartLayoutStore::class] as $interface) {
                foreach ((new \ReflectionClass($interface))->getMethods() as $method) {
                    $methodNames[$method->getName()] = true;
                }
            }
            foreach (array_keys($methodNames) as $methodName) {
                if ($methodName === 'armMultipartWriteIntent') {
                    continue;
                }
                $proxy->method($methodName)->willReturnCallback(
                    fn(mixed ...$arguments): mixed => $metadata->{$methodName}(...$arguments),
                );
            }
            $proxy->method('armMultipartWriteIntent')->willReturnCallback(
                static function (string $attemptId, string $dispatchId, float $expectedLeaseUntil, float $newLeaseUntil) use ($metadata): never {
                    self::assertTrue($metadata->armMultipartWriteIntent($attemptId, $dispatchId, $expectedLeaseUntil, $newLeaseUntil));

                    throw new \RuntimeException('simulated lost arm acknowledgement');
                },
            );

            try {
                (new CompleteMultipartUploadHandler(
                    $proxy,
                    $storage,
                    requireLocalFencedCompletion: true,
                ))->handleRequest(self::completionRequest($uploadId, $etag));
                self::fail('A lost arm acknowledgement must fail before worker dispatch.');
            } catch (\RuntimeException $error) {
                self::assertSame('simulated lost arm acknowledgement', $error->getMessage());
            }

            self::assertDirectoryDoesNotExist($root . '/.assemblies');
            self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
            self::assertNotNull($metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $metadata->getParts($uploadId));
            self::assertSame($plaintext, buffer($storage->getObjectByPath($part->path)));
            $count = (new \PDO('sqlite:' . $databasePath))->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $count);
            self::assertSame(0, (int) $count->fetchColumn());
        } finally {
            $storage->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_global_completion_capacity_rejects_before_assembly_and_preserves_upload(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-completion-cap-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $storage = new FilesystemBackend($root);
        try {
            $databasePath = $root . '/metadata.sqlite';
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $storage->createBucket('bucket');
            $uploadId = bin2hex(random_bytes(16));
            $metadata->createMultipartUpload(
                $uploadId,
                'bucket',
                'key',
                'owner',
                userMetadata: self::currentPolicyContext(),
            );
            $plaintext = 'retained multipart part';
            $part = $storage->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer($plaintext));
            $etag = md5($plaintext);
            $metadata->putPart($uploadId, 1, $etag, strlen($plaintext), $part->path);
            $uploadBefore = $metadata->getMultipartUpload($uploadId);
            $partsBefore = $metadata->getParts($uploadId);
            $pdo = new \PDO('sqlite:' . $databasePath);
            $pdo->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 1000000 WHERE id = 1');

            try {
                (new CompleteMultipartUploadHandler(
                    $metadata,
                    $storage,
                    requireLocalFencedCompletion: true,
                ))->handleRequest(self::completionRequest($uploadId, $etag));
                self::fail('Global completion capacity must reject before assembly.');
            } catch (SlowDownException $error) {
                self::assertSame(503, $error->getHttpStatus());
                self::assertSame('SlowDown', $error->getErrorCode());
            }

            self::assertDirectoryDoesNotExist($root . '/.assemblies');
            self::assertNull($metadata->getObjectMetadata('bucket', 'key'));
            self::assertSame($uploadBefore, $metadata->getMultipartUpload($uploadId));
            self::assertSame($partsBefore, $metadata->getParts($uploadId));
            self::assertSame($plaintext, buffer($storage->getObjectByPath($part->path)));
            $count = $pdo->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $count);
            self::assertSame(0, (int) $count->fetchColumn());
        } finally {
            $storage->shutdown();
            $this->removeDirectory($root);
        }
    }

    public function test_fenced_completion_is_limited_to_its_bound_local_tier(): void
    {
        $root = sys_get_temp_dir() . '/s3-local-complete-tier-' . bin2hex(random_bytes(8));
        $activeRoot = $root . '/active';
        $legacyRoot = $root . '/legacy';
        self::assertTrue(mkdir($activeRoot, 0700, true));
        self::assertTrue(mkdir($legacyRoot, 0700));
        $active = new FilesystemBackend($activeRoot);
        $legacy = new FilesystemBackend($legacyRoot);
        $remote = new InMemoryBackend();
        try {
            $metadata = new SqliteMetadataStore($root . '/metadata.sqlite');
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $active->createBucket('bucket');
            $legacy->createBucket('bucket');
            $remote->createBucket('bucket');
            $tiers = new StorageTierRegistry([
                new StorageTier('REMOTE', $remote),
                new StorageTier('LEGACY', $legacy),
                new StorageTier('ACTIVE', $active, defaultWriteTier: true),
            ]);
            $handler = new CompleteMultipartUploadHandler(
                $metadata,
                $active,
                storageTiers: $tiers,
                requireLocalFencedCompletion: true,
                localFencedCompletionTier: 'ACTIVE',
            );

            $remoteUpload = bin2hex(random_bytes(16));
            $metadata->createMultipartUploadWithTier(
                $remoteUpload,
                'bucket',
                'key',
                'owner',
                'REMOTE',
                userMetadata: self::currentPolicyContext(),
            );
            $remotePart = $remote->putPart('bucket', 'key', $remoteUpload, 1, new ReadableBuffer('remote part'));
            $remoteEtag = md5('remote part');
            $metadata->putPart($remoteUpload, 1, $remoteEtag, strlen('remote part'), $remotePart->path);
            self::assertSame(200, $handler->handleRequest(self::completionRequest($remoteUpload, $remoteEtag))->getStatus());

            $legacyUpload = bin2hex(random_bytes(16));
            $metadata->createMultipartUploadWithTier(
                $legacyUpload,
                'bucket',
                'key',
                'owner',
                'LEGACY',
                userMetadata: self::currentPolicyContext(),
            );
            $legacyPart = $legacy->putPart('bucket', 'key', $legacyUpload, 1, new ReadableBuffer('legacy part'));
            $legacyEtag = md5('legacy part');
            $metadata->putPart($legacyUpload, 1, $legacyEtag, strlen('legacy part'), $legacyPart->path);

            try {
                $handler->handleRequest(self::completionRequest($legacyUpload, $legacyEtag));
                self::fail('A different local tier used the active tier completion fence.');
            } catch (InternalErrorException $error) {
                self::assertSame('Local multipart completion fence is not bound to this upload tier.', $error->getMessage());
            }
            self::assertNotNull($metadata->getMultipartUpload($legacyUpload));
            self::assertCount(1, $metadata->getParts($legacyUpload));
        } finally {
            $active->shutdown();
            $legacy->shutdown();
            $this->removeDirectory($root);
        }
    }

    private static function completionRequest(string $uploadId, string $etag): Request
    {
        return self::completionRequestForParts($uploadId, [1 => $etag]);
    }

    /** @param array<int, string> $partEtags */
    private static function completionRequestForParts(string $uploadId, array $partEtags): Request
    {
        $body = '<CompleteMultipartUpload>';
        foreach ($partEtags as $partNumber => $etag) {
            $body .= '<Part><PartNumber>' . $partNumber . '</PartNumber><ETag>'
                . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }
        $body .= '</CompleteMultipartUpload>';
        $request = new Request(
            new class implements Client {
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
            },
            'POST',
            Http::new('http://127.0.0.1/bucket/key?uploadId=' . $uploadId),
            [],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::CompleteMultipartUpload);

        return $request;
    }

    /** @return array<string, string> */
    private static function currentPolicyContext(): array
    {
        return [
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION_KEY
                => PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_VERSION,
            PolicyEnforcementMiddleware::MULTIPART_POLICY_CONTEXT_HEADERS_KEY => '[]',
            MultipartAclSnapshot::METADATA_KEY => json_encode([[
                'granteeType' => 'CanonicalUser',
                'granteeId' => 'owner',
                'permission' => 'FULL_CONTROL',
            ]], JSON_THROW_ON_ERROR),
        ];
    }

    private static function crc64Nvme(string $bytes): string
    {
        $digest = new Crc64NvmeDigest();
        $digest->update($bytes);

        return $digest->finalizeBase64();
    }

    private function removeDirectory(string $directory): void
    {
        if (! is_dir($directory)) {
            return;
        }
        foreach (scandir($directory) ?: [] as $entry) {
            if ($entry === '.' || $entry === '..') {
                continue;
            }
            $path = $directory . '/' . $entry;
            if (is_dir($path) && ! is_link($path)) {
                $this->removeDirectory($path);
            } else {
                unlink($path);
            }
        }
        rmdir($directory);
    }
}
