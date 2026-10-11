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
use OpsFour\S3Server\Metadata\MetadataStore;
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
        $this->assertSame('no-store', $response->getHeader('cache-control'));
        $this->assertSame('Bearer', $response->getHeader('www-authenticate'));
        $this->assertSame(['error' => 'unauthorized'], $this->json($response));
    }

    public function test_handler_rejects_whitespace_admin_token(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('non-empty admin token');

        new AdminQuotaApiHandler($this->metadata, " \t ");
    }

    public function test_duplicate_authorization_headers_are_rejected(): void
    {
        $request = $this->request('GET', '/.admin/quotas');
        $request->setHeader('authorization', ['Bearer admin-secret', 'Bearer admin-secret']);

        $response = $this->handler()->handleRequest($request);

        self::assertSame(401, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
    }

    public function test_invalid_utf8_owner_is_rejected_before_quota_mutation(): void
    {
        $response = $this->handler()->handleRequest($this->request(
            'PUT',
            '/.admin/quotas/%FF',
            $this->fullQuota(),
            $this->authHeaders(),
        ));

        self::assertSame(400, $response->getStatus());
        self::assertSame('no-store', $response->getHeader('cache-control'));
        self::assertSame([], $this->metadata->listAccountQuotas());
    }

    public function test_control_character_owner_is_rejected_before_quota_lookup_or_mutation(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getAccountQuota');
        $metadata->expects(self::never())->method('putAccountQuota');
        $handler = new AdminQuotaApiHandler($metadata, 'admin-secret');

        foreach (['GET', 'PUT'] as $method) {
            $response = $handler->handleRequest($this->request(
                $method,
                '/.admin/quotas/tenant%00hidden',
                $method === 'PUT' ? $this->fullQuota() : '',
                $this->authHeaders(),
            ));

            self::assertSame(400, $response->getStatus());
            self::assertSame('invalid_request', $this->json($response)['error']);
        }
    }

    public function test_put_get_list_and_delete_account_quota(): void
    {
        $handler = $this->handler();

        $put = $handler->handleRequest($this->request('PUT', '/.admin/quotas/tenant%3Aacme', $this->fullQuota([
            'maxBucketsPerOwner' => 2,
            'maxObjectsPerBucket' => 100,
            'maxBytesPerBucket' => 1024,
            'maxBytesPerOwner' => 2048,
        ]), $this->authHeaders()));
        $putPayload = $this->json($put);

        $this->assertSame(200, $put->getStatus());
        $this->assertSame('no-store', $put->getHeader('cache-control'));
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
        $this->assertSame('no-store', $delete->getHeader('cache-control'));

        $missing = $handler->handleRequest($this->request('GET', '/.admin/quotas/tenant%3Aacme', '', $this->authHeaders()));
        $this->assertSame(404, $missing->getStatus());
        $this->assertSame(['error' => 'not_found'], $this->json($missing));
    }

    public function test_put_accepts_short_aliases_for_cli_compatibility(): void
    {
        $response = $this->handler()->handleRequest($this->request('PUT', '/.admin/quotas/tenant-a', [
            'maxBuckets' => 3,
            'maxBytes' => 4096,
            'maxObjectsPerBucket' => 0,
            'maxBytesPerBucket' => 0,
            'maxMultipartUploadsPerBucket' => 0,
            'maxMultipartUploadsPerOwner' => 0,
            'maxMultipartBytesPerBucket' => 0,
            'maxMultipartBytesPerOwner' => 0,
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

    public function test_invalid_quota_documents_do_not_replace_existing_limits(): void
    {
        $handler = $this->handler();
        $path = '/.admin/quotas/tenant-a';
        $handler->handleRequest($this->request('PUT', $path, $this->fullQuota(['maxBucketsPerOwner' => 2]), $this->authHeaders()));

        foreach ([
            '{"maxBucketsPerOwnr":5}',
            '{}',
            '[{"maxBucketsPerOwner":5}]',
            '{"maxBucketsPerOwner":5,"maxBuckets":6}',
        ] as $body) {
            $response = $handler->handleRequest($this->request('PUT', $path, $body, $this->authHeaders()));
            $this->assertSame(400, $response->getStatus());
            $this->assertSame('invalid_request', $this->json($response)['error']);

            $stored = $handler->handleRequest($this->request('GET', $path, '', $this->authHeaders()));
            $this->assertSame(2, $this->json($stored)['maxBucketsPerOwner']);
        }
    }

    public function test_put_requires_every_quota_field_and_does_not_clear_existing_limits(): void
    {
        $handler = $this->handler();
        $path = '/.admin/quotas/tenant-a';
        $initial = $this->fullQuota(['maxBytesPerOwner' => 4096]);
        self::assertSame(200, $handler->handleRequest($this->request('PUT', $path, $initial, $this->authHeaders()))->getStatus());

        foreach (array_keys($initial) as $missing) {
            $partial = $initial;
            unset($partial[$missing]);
            $response = $handler->handleRequest($this->request('PUT', $path, $partial, $this->authHeaders()));
            self::assertSame(400, $response->getStatus(), $missing);
            self::assertSame('invalid_request', $this->json($response)['error']);
            self::assertSame(4096, $this->metadata->getAccountQuota('tenant-a')?->maxBytesPerOwner);
        }

        $nullField = $initial;
        $nullField['maxBytesPerOwner'] = null;
        self::assertSame(400, $handler->handleRequest($this->request('PUT', $path, $nullField, $this->authHeaders()))->getStatus());
        self::assertSame(4096, $this->metadata->getAccountQuota('tenant-a')?->maxBytesPerOwner);
    }

    public function test_list_uses_bounded_cursor_pages_and_rejects_invalid_query(): void
    {
        $handler = $this->handler();
        foreach (['tenant:1', 'tenant:2', 'tenant:3'] as $ownerId) {
            $this->metadata->putAccountQuota($ownerId, new \OpsFour\S3Server\Quota\QuotaConfig(maxBucketsPerOwner: 1));
        }

        $first = $this->json($handler->handleRequest($this->request('GET', '/.admin/quotas?limit=2', '', $this->authHeaders())));
        self::assertSame(['tenant:1', 'tenant:2'], array_column($first['quotas'], 'ownerId'));
        self::assertIsString($first['nextCursor']);

        $second = $this->json($handler->handleRequest($this->request('GET', '/.admin/quotas?limit=2&cursor=' . $first['nextCursor'], '', $this->authHeaders())));
        self::assertSame(['tenant:3'], array_column($second['quotas'], 'ownerId'));
        self::assertNull($second['nextCursor']);

        $pastEnd = $this->json($handler->handleRequest($this->request('GET', '/.admin/quotas?cursor=' . rtrim(strtr(base64_encode('tenant:3'), '+/', '-_'), '='), '', $this->authHeaders())));
        self::assertSame([], $pastEnd['quotas']);
        self::assertNull($pastEnd['nextCursor']);

        foreach (['limit=0', 'limit=1001', 'limit=1&limit=2', 'cursor=!', 'foo=1'] as $query) {
            $response = $handler->handleRequest($this->request('GET', '/.admin/quotas?' . $query, '', $this->authHeaders()));
            self::assertSame(400, $response->getStatus(), $query);
        }
    }

    public function test_unpaged_list_requires_pagination_above_one_thousand_rows(): void
    {
        $quota = new \OpsFour\S3Server\Quota\QuotaConfig();
        for ($index = 0; $index < 1000; $index++) {
            $this->metadata->putAccountQuota(sprintf('owner-%04d', $index), $quota);
        }

        $compatible = $this->json($this->handler()->handleRequest($this->request('GET', '/.admin/quotas', '', $this->authHeaders())));
        self::assertCount(1000, $compatible['quotas']);
        self::assertSame(['quotas'], array_keys($compatible));

        $this->metadata->putAccountQuota('owner-1000', $quota);

        $response = $this->handler()->handleRequest($this->request('GET', '/.admin/quotas', '', $this->authHeaders()));
        self::assertSame(400, $response->getStatus());
        self::assertSame('pagination_required', $this->json($response)['error']);

        $paged = $this->json($this->handler()->handleRequest($this->request('GET', '/.admin/quotas?limit=1000', '', $this->authHeaders())));
        self::assertCount(1000, $paged['quotas']);
        self::assertIsString($paged['nextCursor']);
    }

    public function test_put_rejects_oversized_body_without_throwing_buffer_exception(): void
    {
        $response = $this->handler()->handleRequest($this->request(
            'PUT',
            '/.admin/quotas/tenant-a',
            str_repeat('x', 4097),
            $this->authHeaders(),
        ));

        $this->assertSame(400, $response->getStatus());
        $this->assertSame('invalid_request', $this->json($response)['error']);
    }

    public function test_quota_mutations_share_the_owner_lock_with_object_commits(): void
    {
        $events = [];
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('transaction')->willReturnCallback(function (callable $callback) use (&$events): mixed {
            $events[] = 'begin';
            $result = $callback();
            $events[] = 'commit';

            return $result;
        });
        $metadata->method('lockOwnerForUpdate')->willReturnCallback(function (string $ownerId) use (&$events): void {
            $events[] = 'lock:' . $ownerId;
        });
        $metadata->method('putAccountQuota')->willReturnCallback(function () use (&$events): void {
            $events[] = 'put';
        });
        $metadata->method('deleteAccountQuota')->willReturnCallback(function () use (&$events): void {
            $events[] = 'delete';
        });
        $handler = new AdminQuotaApiHandler($metadata, 'admin-secret');

        self::assertSame(200, $handler->handleRequest($this->request('PUT', '/.admin/quotas/tenant-a', [
            'maxBytesPerOwner' => 1,
        ] + $this->fullQuota(), $this->authHeaders()))->getStatus());
        self::assertSame(204, $handler->handleRequest($this->request('DELETE', '/.admin/quotas/tenant-a', '', $this->authHeaders()))->getStatus());
        self::assertSame([
            'begin', 'lock:tenant-a', 'put', 'commit',
            'begin', 'lock:tenant-a', 'delete', 'commit',
        ], $events);
    }

    private function handler(): AdminQuotaApiHandler
    {
        return new AdminQuotaApiHandler($this->metadata, 'admin-secret');
    }

    /** @param array<string, int> $overrides
     *  @return array<string, int>
     */
    private function fullQuota(array $overrides = []): array
    {
        return $overrides + [
            'maxBucketsPerOwner' => 0,
            'maxObjectsPerBucket' => 0,
            'maxBytesPerBucket' => 0,
            'maxBytesPerOwner' => 0,
            'maxMultipartUploadsPerBucket' => 0,
            'maxMultipartUploadsPerOwner' => 0,
            'maxMultipartBytesPerBucket' => 0,
            'maxMultipartBytesPerOwner' => 0,
        ];
    }

    /** @return array{authorization: string} */
    private function authHeaders(): array
    {
        return ['authorization' => 'Bearer admin-secret'];
    }

    /**
     * @param non-empty-string $method
     * @param array<string, mixed>|string $body
     * @param array<non-empty-string, string> $headers
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
