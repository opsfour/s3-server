<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Admin;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\Response;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class AdminQuotaApiHandlerTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-admin-quota-' . bin2hex(random_bytes(4)) . '.sqlite';
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->initialize();
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');

        parent::tearDown();
    }

    public function test_requires_admin_bearer_token(): void
    {
        $response = $this->handler()->handleRequest($this->request('GET', '/.admin/quotas'));

        $this->assertSame(401, $response->getStatus());
        $this->assertSame(['error' => 'unauthorized'], $this->json($response));
    }

    public function test_put_get_list_and_delete_account_quota(): void
    {
        $handler = $this->handler();

        $put = $handler->handleRequest($this->request('PUT', '/.admin/quotas/tenant%3Aacme', [
            'maxBucketsPerOwner' => 2,
            'maxObjectsPerBucket' => 100,
            'maxBytesPerBucket' => 1024,
            'maxBytesPerOwner' => 2048,
        ], $this->authHeaders()));
        $putPayload = $this->json($put);

        $this->assertSame(200, $put->getStatus());
        $this->assertSame('tenant:acme', $putPayload['ownerId']);
        $this->assertSame(2, $putPayload['maxBucketsPerOwner']);
        $this->assertSame(100, $putPayload['maxObjectsPerBucket']);
        $this->assertSame(1024, $putPayload['maxBytesPerBucket']);
        $this->assertSame(2048, $putPayload['maxBytesPerOwner']);

        $get = $handler->handleRequest($this->request('GET', '/.admin/quotas/tenant%3Aacme', '', $this->authHeaders()));
        $this->assertSame($putPayload, $this->json($get));

        $list = $handler->handleRequest($this->request('GET', '/.admin/quotas', '', $this->authHeaders()));
        $this->assertSame([$putPayload], $this->json($list)['quotas']);

        $delete = $handler->handleRequest($this->request('DELETE', '/.admin/quotas/tenant%3Aacme', '', $this->authHeaders()));
        $this->assertSame(204, $delete->getStatus());

        $missing = $handler->handleRequest($this->request('GET', '/.admin/quotas/tenant%3Aacme', '', $this->authHeaders()));
        $this->assertSame(404, $missing->getStatus());
        $this->assertSame(['error' => 'not_found'], $this->json($missing));
    }

    public function test_put_accepts_short_aliases_for_cli_compatibility(): void
    {
        $response = $this->handler()->handleRequest($this->request('PUT', '/.admin/quotas/tenant-a', [
            'maxBuckets' => 3,
            'maxBytes' => 4096,
        ], $this->authHeaders()));
        $payload = $this->json($response);

        $this->assertSame(200, $response->getStatus());
        $this->assertSame(3, $payload['maxBucketsPerOwner']);
        $this->assertSame(0, $payload['maxObjectsPerBucket']);
        $this->assertSame(0, $payload['maxBytesPerBucket']);
        $this->assertSame(4096, $payload['maxBytesPerOwner']);
    }

    public function test_put_rejects_invalid_values(): void
    {
        $response = $this->handler()->handleRequest($this->request('PUT', '/.admin/quotas/tenant-a', [
            'maxBucketsPerOwner' => -1,
        ], $this->authHeaders()));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_request', $this->json($response)['error']);
    }

    private function handler(): AdminQuotaApiHandler
    {
        return new AdminQuotaApiHandler($this->metadata, 'admin-secret');
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return ['authorization' => 'Bearer admin-secret'];
    }

    /**
     * @param array<string, string> $headers
     */
    private function request(string $method, string $path, array|string $body = '', array $headers = []): Request
    {
        if (is_array($body)) {
            $body = json_encode($body, JSON_THROW_ON_ERROR);
            $headers = ['content-type' => 'application/json'] + $headers;
        }

        return new Request(
            new AdminQuotaApiTestClient(),
            $method,
            Http::new('http://127.0.0.1' . $path),
            $headers,
            $body,
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function json(Response $response): array
    {
        $decoded = json_decode(\Amp\ByteStream\buffer($response->getBody()), true, 512, JSON_THROW_ON_ERROR);
        $this->assertIsArray($decoded);

        return $decoded;
    }
}

final class AdminQuotaApiTestClient implements Client
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
