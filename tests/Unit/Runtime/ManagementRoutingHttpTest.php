<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Runtime;

use Amp\ByteStream;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use PHPUnit\Framework\TestCase;

final class ManagementRoutingHttpTest extends TestCase
{
    public function test_reserved_paths_keep_their_management_method_matrix_and_error_format(): void
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        $port = (int) substr($address, strrpos($address, ':') + 1);
        fclose($reservation);

        $admin = new ManagementRoutingTestHandler();
        $server = new S3Server(new S3ServerConfig(
            host: '127.0.0.1',
            port: $port,
            storagePath: sys_get_temp_dir(),
            perClientRateLimit: 0,
            notificationWorkerEnabled: false,
        ));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('access-key', 'secret-key', 'owner')),
            'us-east-1',
        ));
        $server->setAdminCredentialApiHandler($admin);
        $server->setAdminQuotaApiHandler($admin);

        try {
            $server->start();
            $client = (new HttpClientBuilder())->retry(0)->build();
            $endpoint = "http://127.0.0.1:{$port}";

            foreach ([
                ['POST', '/.live', 405, 'GET', 'application/json'],
                ['DELETE', '/.health', 405, 'GET', 'application/json'],
                ['HEAD', '/.metrics', 405, 'GET', 'text/plain'],
                ['PUT', '/.admin/credentials', 405, 'POST', 'application/json'],
                ['GET', '/.admin/credentials/key', 405, 'DELETE', 'application/json'],
                ['POST', '/.admin/quotas', 405, 'GET', 'application/json'],
                ['POST', '/.admin/quotas/owner', 405, 'GET, PUT, DELETE', 'application/json'],
                ['POST', '/.admin/quotas/tenant/owner', 405, 'GET, PUT, DELETE', 'application/json'],
                ['DELETE', '/.live/child', 404, null, 'application/json'],
                ['DELETE', '/.%6cive', 405, 'GET', 'application/json'],
                ['GET', '/.metrics/child', 404, null, 'text/plain'],
                ['PUT', '/.metrics%2Fchild', 404, null, 'text/plain'],
                ['PUT', '/.admin/credentials/key/extra', 404, null, 'application/json'],
                ['PUT', '/.%61dmin/credentials/key/extra', 404, null, 'application/json'],
                ['DELETE', '/.admin/unknown', 404, null, 'application/json'],
            ] as [$method, $path, $status, $allow, $contentType]) {
                $response = $client->request(new ClientRequest($endpoint . $path, $method));
                self::assertSame($status, $response->getStatus(), "$method $path");
                self::assertSame($allow, $response->getHeader('allow'), "$method $path Allow");
                self::assertSame('no-store', $response->getHeader('cache-control'), "$method $path cache");
                self::assertStringStartsWith($contentType, (string) $response->getHeader('content-type'), "$method $path type");
                self::assertStringNotContainsString('<Error>', ByteStream\buffer($response->getBody()), "$method $path body");
            }
            self::assertSame([], $admin->handled, 'Rejected management paths must not reach S3 or admin handlers.');

            foreach ([
                ['POST', '/.admin/credentials'],
                ['DELETE', '/.admin/credentials/key'],
                ['GET', '/.admin/quotas'],
                ['GET', '/.admin/quotas/owner'],
                ['PUT', '/.admin/quotas/owner'],
                ['DELETE', '/.admin/quotas/owner'],
                ['GET', '/.admin/quotas/tenant/owner'],
            ] as [$method, $path]) {
                $response = $client->request(new ClientRequest($endpoint . $path, $method));
                self::assertSame(200, $response->getStatus(), "$method $path");
                self::assertSame('no-store', $response->getHeader('cache-control'));
            }
            self::assertCount(7, $admin->handled);

            $admin->fail = true;
            $response = $client->request(new ClientRequest($endpoint . '/.admin/quotas', 'GET'));
            self::assertSame(500, $response->getStatus());
            self::assertSame('no-store', $response->getHeader('cache-control'));
            self::assertSame('application/json', $response->getHeader('content-type'));
            self::assertSame('{"error":"internal_error"}', ByteStream\buffer($response->getBody()));
        } finally {
            $server->stop();
        }
    }
}

final class ManagementRoutingTestHandler implements RequestHandler
{
    /** @var list<string> */
    public array $handled = [];

    public bool $fail = false;

    public function handleRequest(Request $request): Response
    {
        $this->handled[] = $request->getMethod() . ' ' . $request->getUri()->getPath();
        if ($this->fail) {
            throw new \RuntimeException('private metadata://credential@example');
        }

        return new Response(200, ['content-type' => 'application/json'], '{}');
    }
}
