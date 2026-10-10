<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\Request as ServerRequest;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

final class EfsApiAdmissionTest extends TestCase
{
    public function test_lost_evidence_rejects_s3_before_auth_while_probes_remain_available(): void
    {
        $port = self::unusedPort();
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $evidence = new class implements EfsHostEvidence {
            public bool $fresh = true;

            public int $checks = 0;

            public function assertFresh(): void
            {
                $this->checks++;
                if (! $this->fresh) {
                    throw new \RuntimeException('private-evidence-path');
                }
            }
        };
        $server = new S3Server(
            new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: sys_get_temp_dir(),
                allowUnfencedStorageForTests: true,
                perClientRateLimit: 0,
                notificationWorkerEnabled: false,
            ),
            $metadata,
            new InMemoryBackend(),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setEfsApiAdmissionForTests($evidence);

        try {
            $server->start();
            $client = (new HttpClientBuilder())->retry(0)->build();
            $endpoint = "http://127.0.0.1:{$port}";
            self::assertNotSame(503, $client->request(new Request($endpoint . '/'))->getStatus());
            self::assertSame(1, $evidence->checks);

            $evidence->fresh = false;
            foreach (['GET /', 'POST /', 'GET /bucket/key', 'OPTIONS /bucket'] as $case) {
                [$method, $path] = explode(' ', $case, 2);
                if ($method === '') {
                    throw new \LogicException('The test request method is empty.');
                }
                $response = $client->request(new Request($endpoint . $path, $method));
                self::assertSame(503, $response->getStatus(), $case);
                $body = ByteStream\buffer($response->getBody());
                self::assertStringContainsString('<Code>SlowDown</Code>', $body);
                self::assertStringNotContainsString('private-evidence-path', $body);
            }
            self::assertSame(2, $evidence->checks, 'A failed check must latch closed.');

            $evidence->fresh = true;
            self::assertSame(503, $client->request(new Request($endpoint . '/'))->getStatus());
            self::assertSame(2, $evidence->checks);
            self::assertSame(200, $client->request(new Request($endpoint . '/.live'))->getStatus());
            self::assertSame(503, $client->request(new Request($endpoint . '/.health'))->getStatus());
            self::assertSame(2, $evidence->checks, 'Readiness must share the latched admission state.');
        } finally {
            $server->stop();
        }
    }

    public function test_attachment_requires_the_explicit_unsafe_profile(): void
    {
        $server = new S3Server(new S3ServerConfig(storagePath: sys_get_temp_dir()));
        $evidence = $this->createMock(EfsHostEvidence::class);
        $evidence->expects(self::never())->method('assertFresh');

        $this->expectException(\LogicException::class);
        $server->setEfsApiAdmissionForTests($evidence);
    }

    public function test_shutdown_skips_buffered_access_log_write_after_admission_loss(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->expects(self::never())->method('getBucket');
        $evidence = new class implements EfsHostEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                $this->checks++;
                throw new \RuntimeException('host evidence lost');
            }
        };
        $server = new S3Server(
            new S3ServerConfig(storagePath: sys_get_temp_dir(), allowUnfencedStorageForTests: true),
            $metadata,
            new InMemoryBackend(),
        );
        $server->setEfsApiAdmissionForTests($evidence);
        $accessLogs = (new \ReflectionProperty($server, 'accessLogs'))->getValue($server);
        self::assertInstanceOf(AccessLogWriter::class, $accessLogs);
        (new \ReflectionProperty($accessLogs, 'buffer'))->setValue($accessLogs, [[
            'bucket' => 'source',
            'owner' => 'owner',
            'generation' => 'generation',
            'targetBucket' => 'target',
            'targetGeneration' => 'generation',
            'targetPrefix' => '',
            'line' => 'pending access log',
        ]]);

        $server->stop();

        self::assertSame(1, $evidence->checks);
    }

    public function test_lost_evidence_rejects_admin_mutations_before_their_handlers(): void
    {
        $port = self::unusedPort();
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->putAccountQuota('owner', new QuotaConfig(maxBucketsPerOwner: 2));
        $evidence = new class implements EfsHostEvidence {
            public bool $fresh = true;

            public int $checks = 0;

            public function assertFresh(): void
            {
                $this->checks++;
                if (! $this->fresh) {
                    throw new \RuntimeException('private-admin-evidence');
                }
            }
        };
        $credentials = new class implements RequestHandler {
            public int $calls = 0;

            public function handleRequest(ServerRequest $request): Response
            {
                $this->calls++;

                return new Response(status: 201);
            }
        };
        $server = new S3Server(
            new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: sys_get_temp_dir(),
                allowUnfencedStorageForTests: true,
                perClientRateLimit: 0,
                notificationWorkerEnabled: false,
            ),
            $metadata,
            new InMemoryBackend(),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));
        $server->setEfsApiAdmissionForTests($evidence);
        $server->setAdminQuotaApiHandler(new AdminQuotaApiHandler($metadata, 'admin-secret'));
        $server->setAdminCredentialApiHandler($credentials);

        try {
            $server->start();
            $client = (new HttpClientBuilder())->retry(0)->build();
            $endpoint = "http://127.0.0.1:{$port}";
            $credentialRequest = new Request($endpoint . '/.admin/credentials', 'POST');
            self::assertSame(201, $client->request($credentialRequest)->getStatus());
            self::assertSame(1, $credentials->calls);
            self::assertSame(1, $evidence->checks);

            $evidence->fresh = false;
            foreach (['PUT /.admin/quotas/owner', 'DELETE /.admin/quotas/owner', 'POST /.admin/credentials', 'DELETE /.admin/credentials/key'] as $case) {
                [$method, $path] = explode(' ', $case, 2);
                if ($method === '') {
                    throw new \LogicException('The test request method is empty.');
                }
                $request = new Request($endpoint . $path, $method, '{}');
                $request->setHeader('authorization', 'Bearer admin-secret');
                $response = $client->request($request);
                self::assertSame(503, $response->getStatus(), $case);
                $body = ByteStream\buffer($response->getBody());
                self::assertStringContainsString('<Code>SlowDown</Code>', $body);
                self::assertStringNotContainsString('private-admin-evidence', $body);
            }

            self::assertSame(2, $evidence->checks, 'Admin requests share the latched API admission.');
            self::assertSame(1, $credentials->calls);
            self::assertSame(2, $metadata->getAccountQuota('owner')?->maxBucketsPerOwner);

            $evidence->fresh = true;
            self::assertSame(503, $client->request(new Request($endpoint . '/.admin/quotas', 'GET'))->getStatus());
            self::assertSame(2, $evidence->checks);
            self::assertSame(200, $client->request(new Request($endpoint . '/.live'))->getStatus());
            self::assertSame(503, $client->request(new Request($endpoint . '/.health'))->getStatus());
            self::assertSame(200, $client->request(new Request($endpoint . '/.metrics'))->getStatus());
            self::assertSame(2, $evidence->checks, 'Readiness must not retry revoked admission.');
        } finally {
            $server->stop();
        }
    }

    private static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $address = stream_socket_get_name($socket, false);
        self::assertIsString($address);
        fclose($socket);

        return (int) substr($address, strrpos($address, ':') + 1);
    }
}
