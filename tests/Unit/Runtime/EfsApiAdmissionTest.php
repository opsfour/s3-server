<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream;
use Amp\ByteStream\ReadableBuffer;
use Amp\CancelledException;
use Amp\DeferredFuture;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request as ServerRequest;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\TimeoutCancellation;
use League\Uri\Http;
use OpsFour\S3Server\Admin\AdminQuotaApiHandler;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Middleware\EfsApiAdmissionMiddleware;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Runtime\EfsHostEvidence;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\TestCase;

use function Amp\async;

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
            self::assertSame(2, $evidence->checks);

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
                self::assertSame('application/json', $response->getHeader('content-type'));
                self::assertSame('{"error":"service_unavailable"}', $body);
                self::assertStringNotContainsString('private-admin-evidence', $body);
            }

            self::assertSame(3, $evidence->checks, 'Admin requests share the latched API admission.');
            self::assertSame(1, $credentials->calls);
            self::assertSame(2, $metadata->getAccountQuota('owner')?->maxBucketsPerOwner);

            $evidence->fresh = true;
            self::assertSame(503, $client->request(new Request($endpoint . '/.admin/quotas', 'GET'))->getStatus());
            self::assertSame(3, $evidence->checks);
            self::assertSame(200, $client->request(new Request($endpoint . '/.live'))->getStatus());
            self::assertSame(503, $client->request(new Request($endpoint . '/.health'))->getStatus());
            self::assertSame(200, $client->request(new Request($endpoint . '/.metrics'))->getStatus());
            self::assertSame(3, $evidence->checks, 'Readiness must not retry revoked admission.');
        } finally {
            $server->stop();
        }
    }

    public function test_mutating_s3_and_admin_responses_are_withheld_after_evidence_loss_during_handler_yield(): void
    {
        foreach ([
            ['PUT', '/bucket/key'],
            ['POST', '/.admin/credentials'],
        ] as [$method, $path]) {
            $evidence = new class implements EfsHostEvidence {
                public bool $fresh = true;

                public int $checks = 0;

                public function assertFresh(): void
                {
                    $this->checks++;
                    if (! $this->fresh) {
                        throw new \RuntimeException('private-path');
                    }
                }
            };
            $entered = new DeferredFuture();
            $release = new DeferredFuture();
            $body = new ReadableBuffer('must-not-be-sent');
            $handler = new class ($entered, $release, $body) implements RequestHandler {
                /**
                 * @param DeferredFuture<void> $entered
                 * @param DeferredFuture<void> $release
                 */
                public function __construct(
                    private readonly DeferredFuture $entered,
                    private readonly DeferredFuture $release,
                    private readonly ReadableBuffer $body,
                ) {}

                public function handleRequest(ServerRequest $request): Response
                {
                    $this->entered->complete();
                    $this->release->getFuture()->await(new TimeoutCancellation(1));

                    return new Response(status: 200, body: $this->body);
                }
            };
            $admission = new EfsApiAdmissionMiddleware($evidence);
            $request = new ServerRequest($this->createMock(Client::class), $method, Http::new('http://127.0.0.1' . $path));
            $response = async(static fn() => $admission->handleRequest($request, $handler));
            $entered->getFuture()->await(new TimeoutCancellation(1));
            $evidence->fresh = false;
            $release->complete();

            try {
                $response->await(new TimeoutCancellation(1));
                self::fail($path . ' returned a response after admission loss.');
            } catch (SlowDownException $error) {
                self::assertSame('Storage host evidence is unavailable.', $error->getMessage());
            }

            self::assertSame(2, $evidence->checks);
            self::assertFalse($body->isReadable(), 'The stale response body remained publishable.');

            $evidence->fresh = true;
            try {
                $admission->assertFresh();
                self::fail('A failed commit check did not latch admission closed.');
            } catch (\RuntimeException $error) {
                self::assertSame('Storage host evidence is unavailable.', $error->getMessage());
            }
            self::assertSame(2, $evidence->checks);
        }
    }

    public function test_mutation_commit_check_preserves_cancellation_and_does_not_revoke_admission(): void
    {
        $cancellation = new CancelledException();
        $evidence = new class ($cancellation) implements EfsHostEvidence {
            public int $checks = 0;

            public bool $cancelNext = false;

            public function __construct(private readonly CancelledException $cancellation) {}

            public function assertFresh(): void
            {
                $this->checks++;
                if ($this->cancelNext) {
                    $this->cancelNext = false;
                    throw $this->cancellation;
                }
            }
        };
        $body = new ReadableBuffer('must-not-be-sent');
        $cancelCommitCheck = static function () use ($evidence): void {
            $evidence->cancelNext = true;
        };
        $handler = new class ($cancelCommitCheck, $body) implements RequestHandler {
            public int $calls = 0;

            public function __construct(
                private readonly \Closure $cancelCommitCheck,
                private readonly ReadableBuffer $body,
            ) {}

            public function handleRequest(ServerRequest $request): Response
            {
                $this->calls++;
                ($this->cancelCommitCheck)();

                return new Response(status: 204, body: $this->body);
            }
        };
        $admission = new EfsApiAdmissionMiddleware($evidence);
        $request = new ServerRequest($this->createMock(Client::class), 'PUT', Http::new('http://127.0.0.1/bucket/key'));

        try {
            $admission->handleRequest($request, $handler);
            self::fail('A cancelled commit check returned a response.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }

        self::assertFalse($body->isReadable());
        self::assertSame(2, $evidence->checks);
        self::assertSame(1, $handler->calls);

        $read = new ServerRequest($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket/key'));
        self::assertSame(204, $admission->handleRequest($read, new class implements RequestHandler {
            public function handleRequest(ServerRequest $request): Response
            {
                return new Response(status: 204);
            }
        })->getStatus());
        self::assertSame(3, $evidence->checks, 'Cancellation must not trip the shared admission latch.');
        self::assertSame(1, $handler->calls);
    }

    public function test_concurrent_revocation_wins_over_an_older_suspended_evidence_check(): void
    {
        $entered = new DeferredFuture();
        $release = new DeferredFuture();
        $evidence = new class ($entered, $release) implements EfsHostEvidence {
            public int $checks = 0;

            /**
             * @param DeferredFuture<void> $entered
             * @param DeferredFuture<void> $release
             */
            public function __construct(
                private readonly DeferredFuture $entered,
                private readonly DeferredFuture $release,
            ) {}

            public function assertFresh(): void
            {
                $this->checks++;
                if ($this->checks === 1) {
                    $this->entered->complete();
                    $this->release->getFuture()->await(new TimeoutCancellation(1));

                    return;
                }

                throw new \RuntimeException('newer evidence loss');
            }
        };
        $handler = new class implements RequestHandler {
            public int $calls = 0;

            public function handleRequest(ServerRequest $request): Response
            {
                $this->calls++;

                return new Response(status: 204);
            }
        };
        $admission = new EfsApiAdmissionMiddleware($evidence);
        $olderRequest = new ServerRequest($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket/older'));
        $newerRequest = new ServerRequest($this->createMock(Client::class), 'GET', Http::new('http://127.0.0.1/bucket/newer'));
        $older = async(static fn() => $admission->handleRequest($olderRequest, $handler));
        $entered->getFuture()->await(new TimeoutCancellation(1));

        try {
            $admission->handleRequest($newerRequest, $handler);
            self::fail('A failed concurrent evidence check was admitted.');
        } catch (SlowDownException $error) {
            self::assertSame('Storage host evidence is unavailable.', $error->getMessage());
        }

        $release->complete();
        try {
            $older->await(new TimeoutCancellation(1));
            self::fail('An older suspended evidence check reopened admission.');
        } catch (SlowDownException $error) {
            self::assertSame('Storage host evidence is unavailable.', $error->getMessage());
        }

        self::assertSame(2, $evidence->checks);
        self::assertSame(0, $handler->calls);
    }

    public function test_handler_cancellation_is_preserved_without_a_second_evidence_check(): void
    {
        $evidence = new class implements EfsHostEvidence {
            public int $checks = 0;

            public function assertFresh(): void
            {
                $this->checks++;
            }
        };
        $cancellation = new CancelledException();
        $handler = new class ($cancellation) implements RequestHandler {
            public function __construct(private readonly CancelledException $cancellation) {}

            public function handleRequest(ServerRequest $request): Response
            {
                throw $this->cancellation;
            }
        };
        $admission = new EfsApiAdmissionMiddleware($evidence);
        $request = new ServerRequest($this->createMock(Client::class), 'DELETE', Http::new('http://127.0.0.1/bucket/key'));

        try {
            $admission->handleRequest($request, $handler);
            self::fail('Handler cancellation returned normally.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
        }

        self::assertSame(1, $evidence->checks);
        $admission->assertFresh();
        self::assertSame(2, $evidence->checks, 'Handler cancellation must not revoke shared admission.');
    }

    public function test_mutating_handler_failure_is_not_serialized_after_admission_loss(): void
    {
        $evidence = new class implements EfsHostEvidence {
            public bool $fresh = true;

            public int $checks = 0;

            public function assertFresh(): void
            {
                $this->checks++;
                if (! $this->fresh) {
                    throw new \RuntimeException('private-path');
                }
            }
        };
        $handler = new class (static function () use ($evidence): void {
            $evidence->fresh = false;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $revokeEvidence) {}

            public function handleRequest(ServerRequest $request): Response
            {
                ($this->revokeEvidence)();
                throw new \LogicException('handler-error');
            }
        };
        $admission = new EfsApiAdmissionMiddleware($evidence);
        $request = new ServerRequest($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/bucket?delete'));

        try {
            $admission->handleRequest($request, $handler);
            self::fail('A handler error escaped after admission loss.');
        } catch (SlowDownException $error) {
            self::assertSame('Storage host evidence is unavailable.', $error->getMessage());
            self::assertStringNotContainsString('handler-error', $error->getMessage());
        }

        self::assertSame(2, $evidence->checks);
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
