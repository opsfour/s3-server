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
use OpsFour\S3Server\Encryption\EncryptedMultipartPartCodec;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageWriteResult;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\ByteStream\buffer;

final class LocalMultipartCompletionHandlerTest extends TestCase
{
    /** @return iterable<string, array{bool}> */
    public static function modes(): iterable
    {
        yield 'plaintext assembly' => [false];
        yield 'SSE-S3 final' => [true];
    }

    #[DataProvider('modes')]
    public function test_local_complete_commits_only_the_armed_physical_attempt(bool $encrypted): void
    {
        $root = sys_get_temp_dir() . '/s3-local-complete-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0700));
        $storage = new FilesystemBackend($root);
        try {
            $databasePath = $root . '/metadata.sqlite';
            $metadata = new SqliteMetadataStore($databasePath);
            $metadata->initialize();
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            $storage->createBucket('bucket');
            $bucket = $metadata->getBucket('bucket');
            self::assertNotNull($bucket);
            $uploadId = bin2hex(random_bytes(16));
            $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
            $metadata->createMultipartUpload(
                $uploadId,
                'bucket',
                'key',
                'owner',
                userMetadata: $encrypted ? [
                    ...self::currentPolicyContext(),
                    '__sse-algorithm' => EncryptionRequestResolver::SSE_S3,
                    '__mpu-part-format' => EncryptedMultipartPartCodec::FORMAT,
                ] : self::currentPolicyContext(),
            );
            $plaintext = 'local multipart payload';
            $partBody = $encrypted
                ? EncryptedMultipartPartCodec::encode(
                    $encryption,
                    EncryptionRequestResolver::SSE_S3,
                    $plaintext,
                    null,
                    $bucket->generation,
                    'bucket',
                    $uploadId,
                    1,
                ) : $plaintext;
            $part = $storage->putPart('bucket', 'key', $uploadId, 1, new ReadableBuffer($partBody));
            $partEtag = md5($plaintext);
            $metadata->putPart($uploadId, 1, $partEtag, strlen($plaintext), $part->path);
            $request = self::completionRequest($uploadId, $partEtag);

            $response = (new CompleteMultipartUploadHandler(
                $metadata,
                $storage,
                $encrypted ? $encryption : null,
                requireLocalFencedCompletion: true,
            ))->handleRequest($request);
            self::assertSame(200, $response->getStatus());
            $object = $metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);
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
                self::assertNotSame($plaintext, $physical);
            } else {
                self::assertSame($plaintext, $physical);
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
            ]);
            self::assertInstanceOf(MetadataStore::class, $proxy);
            self::assertInstanceOf(LocalMultipartCompletionIntentStore::class, $proxy);
            $methodNames = [];
            foreach ([MetadataStore::class, LocalMultipartCompletionIntentStore::class] as $interface) {
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

    public function test_encrypted_worker_response_requires_exact_attempt_and_encryption_metadata(): void
    {
        $handler = new CompleteMultipartUploadHandler($this->createMock(\OpsFour\S3Server\Metadata\MetadataStore::class), new \OpsFour\S3Server\Storage\InMemoryBackend());
        $validate = new \ReflectionMethod($handler, 'assertEncryptedWorkerResult');
        $path = '/qualified/.assemblies/bucket/generation/attempt/final';
        $etag = '"' . md5(md5('part', true)) . '-1"';
        $encrypted = (new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32)))))->encryptSseS3('part');
        $metadata = [
            'sse-algorithm' => 'AES256',
            'sse-key' => $encrypted['encryptedDataKey'],
            'sse-iv' => $encrypted['iv'],
            'sse-tag' => $encrypted['tag'],
        ];
        $write = new StorageWriteResult($path, 4, trim($etag, '"'));
        self::assertNull($validate->invoke($handler, $write, $metadata, $path, 4, $etag, 'AES256', null));

        foreach ([
            'foreign final path' => [new StorageWriteResult($path . '-other', 4, trim($etag, '"')), $metadata],
            'wrong logical size' => [new StorageWriteResult($path, 3, trim($etag, '"')), $metadata],
            'wrong multipart ETag' => [new StorageWriteResult($path, 4, md5('other') . '-1'), $metadata],
            'unexpected metadata' => [$write, $metadata + ['unexpected' => 'value']],
            'missing wrapped key' => [$write, array_diff_key($metadata, ['sse-key' => true])],
            'invalid IV' => [$write, array_replace($metadata, ['sse-iv' => base64_encode('short')])],
            'invalid tag' => [$write, array_replace($metadata, ['sse-tag' => base64_encode('short')])],
        ] as $case => [$candidateWrite, $candidateMetadata]) {
            try {
                $validate->invoke($handler, $candidateWrite, $candidateMetadata, $path, 4, $etag, 'AES256', null);
                self::fail('Encrypted completion accepted ' . $case . '.');
            } catch (InternalErrorException) {
            }
        }

        $customerKey = random_bytes(32);
        $customerMetadata = [
            'sse-algorithm' => 'SSE-C',
            'sse-customer-key-md5' => base64_encode(md5($customerKey, true)),
            'sse-iv' => $encrypted['iv'],
            'sse-tag' => $encrypted['tag'],
        ];
        self::assertNull($validate->invoke($handler, $write, $customerMetadata, $path, 4, $etag, 'SSE-C', $customerKey));
        try {
            $validate->invoke($handler, $write, array_replace($customerMetadata, ['sse-customer-key-md5' => 'wrong']), $path, 4, $etag, 'SSE-C', $customerKey);
            self::fail('Encrypted completion accepted a different SSE-C key digest.');
        } catch (InternalErrorException) {
        }
    }

    private static function completionRequest(string $uploadId, string $etag): Request
    {
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . $etag . '</ETag></Part></CompleteMultipartUpload>';
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
        ];
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
