<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class MultipartCompletionRetryTest extends TestCase
{
    private string $databasePath;

    private SqliteMetadataStore $metadata;

    private InMemoryBackend $storage;

    private EncryptionService $encryption;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-multipart-retry-' . bin2hex(random_bytes(5)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->databasePath);
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->storage = new InMemoryBackend();
        $this->storage->createBucket('bucket');
        $this->encryption = new EncryptionService(
            new ConfigMasterKeyProvider(base64_encode(random_bytes(32))),
        );
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_failed_sse_c_completion_keeps_parts_for_successful_retry(): void
    {
        $customerKey = random_bytes(32);
        $sseHeaders = [
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
            'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
        ];
        $create = (new CreateMultipartUploadHandler($this->metadata, $this->encryption))
            ->handleRequest($this->request('POST', '/bucket/retry.bin?uploads', '', $sseHeaders));
        $createXml = new \SimpleXMLElement(\Amp\ByteStream\buffer($create->getBody()));
        $uploadId = (string) $createXml->UploadId;
        self::assertNotSame('', $uploadId);

        $part = (new UploadPartHandler($this->metadata, $this->storage))
            ->handleRequest($this->request(
                'PUT',
                '/bucket/retry.bin?partNumber=1&uploadId=' . $uploadId,
                'retry payload',
            ));
        $etag = $part->getHeader('ETag');
        self::assertNotNull($etag);
        $completeBody = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . htmlspecialchars($etag, ENT_XML1)
            . '</ETag></Part></CompleteMultipartUpload>';
        $handler = new CompleteMultipartUploadHandler($this->metadata, $this->storage, $this->encryption);

        try {
            $handler->handleRequest($this->request(
                'POST',
                '/bucket/retry.bin?uploadId=' . $uploadId,
                $completeBody,
            ));
            self::fail('Expected missing SSE-C headers to reject completion.');
        } catch (InvalidArgumentException) {
            self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
            self::assertCount(1, $this->metadata->getParts($uploadId));
        }

        $completed = $handler->handleRequest($this->request(
            'POST',
            '/bucket/retry.bin?uploadId=' . $uploadId,
            $completeBody,
            $sseHeaders,
        ));
        self::assertSame(200, $completed->getStatus());
        self::assertNull($this->metadata->getMultipartUpload($uploadId));

        $get = (new GetObjectHandler($this->metadata, $this->storage, $this->encryption))
            ->handleRequest($this->request('GET', '/bucket/retry.bin', '', $sseHeaders));
        self::assertSame('retry payload', \Amp\ByteStream\buffer($get->getBody()));
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string> $headers
     */
    private function request(string $method, string $path, string $body = '', array $headers = []): Request
    {
        $request = new Request(
            new MultipartRetryTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'retry.bin');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }
}

final class MultipartRetryTestClient implements Client
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
