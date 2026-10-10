<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableIterableStream;
use Amp\DeferredFuture;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Client\Response as ClientResponse;
use Amp\Http\Server\DefaultErrorHandler;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\SocketHttpServer;
use Amp\Socket\InternetAddress;
use Aws\S3\S3Client;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;

/**
 * Runs the real AWS SDK / Flysystem adapter in a worker against an in-process
 * S3-shaped HTTP endpoints that delay the first or a subsequent GET body byte.
 */
final class RealAwsAdapterFirstByteFaultTest extends TestCase
{
    public function test_real_adapter_midstream_stall_truncates_response_and_releases_worker(): void
    {
        $temp = sys_get_temp_dir() . '/s3-real-adapter-midstream-' . bin2hex(random_bytes(6));
        mkdir($temp, 0o755, true);
        $logger = new NullLogger();
        $remote = SocketHttpServer::createForDirectAccess($logger, enableCompression: false);
        $remote->expose('127.0.0.1:0');
        $remote->start(new SyntheticS3MidstreamFaultHandler(), new DefaultErrorHandler());
        $remoteAddress = $remote->getServers()[0]->getAddress();
        self::assertInstanceOf(InternetAddress::class, $remoteAddress);
        $remotePort = $remoteAddress->getPort();
        $apiPort = self::unusedPort();
        self::assertNotSame($remotePort, $apiPort);
        $runtime = null;

        try {
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'fault-gate', 'us-east-1');
            $largeBody = str_repeat('a', 65_536) . str_repeat('b', 65_536);
            foreach (['midstream' => $largeBody, 'drip' => str_repeat('c', 65_536), 'before' => 'before-body', 'fast' => 'fast-body'] as $key => $body) {
                $metadata->putObjectMetadata('fault-gate', $key, 'owner', strlen($body), '"' . md5($body) . '"', 'application/octet-stream', 'fault-gate/' . $key);
            }

            $metrics = new MetricsCollector();
            $backend = new ParallelFlysystemBackend(
                new AwsS3FlysystemFilesystemFactory(
                    remoteBucket: 'remote-bucket',
                    region: 'us-east-1',
                    accessKeyId: 'local-key',
                    secretAccessKey: 'local-secret',
                    endpoint: "http://127.0.0.1:{$remotePort}",
                    pathStyle: true,
                    readTimeoutSeconds: 1,
                    allowInsecureHttpForTests: true,
                ),
                $temp,
                1,
                metrics: $metrics,
                firstByteTimeoutSeconds: 2,
            );
            $runtime = (new S3ServerRuntimeFactory())->create(
                config: new S3ServerConfig(
                    host: '127.0.0.1',
                    port: $apiPort,
                    storagePath: $temp,
                    allowUnfencedStorageForTests: true,
                    perClientRateLimit: 0,
                    notificationWorkerEnabled: false,
                ),
                metadata: $metadata,
                storage: $backend,
                credentialProvider: new InMemoryCredentialProvider(new Credential('fault-key', 'fault-secret', 'owner')),
                logger: $logger,
                metrics: $metrics,
            );
            $runtime->server->start();

            $client = (new HttpClientBuilder())->retry(0)->build();
            $sdk = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => "http://127.0.0.1:{$apiPort}",
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => 'fault-key', 'secret' => 'fault-secret'],
            ]);
            $request = new ClientRequest(self::presignedGet($sdk, 'midstream'));
            $request->setTransferTimeout(5);
            $response = $client->request($request);
            self::assertSame(200, $response->getStatus());
            self::assertSame((string) strlen($largeBody), $response->getHeader('content-length'));

            $started = microtime(true);
            $received = '';
            $bodyErrorClass = null;
            try {
                while (($chunk = $response->getBody()->read(new \Amp\TimeoutCancellation(5))) !== null) {
                    $received .= $chunk;
                }
            } catch (\Throwable $error) {
                $bodyErrorClass = $error::class;
                // An advertised Content-Length with an early backend failure
                // may surface as a client framing exception instead of EOF.
            }
            self::assertNotSame('', $received, 'The client must receive the first block before the backing read stalls.');
            self::assertSame(65_536, strlen($received), 'The failed response may contain exactly the first block, never the resumed bytes.');
            self::assertSame(0, substr_count($received, 'b'));
            self::assertNotNull($bodyErrorClass, 'The truncated Content-Length response must fail at the HTTP client.');
            self::assertLessThan(3.0, microtime(true) - $started, 'The backing read timeout must end the stream before the remote resumes.');

            $slots = new \ReflectionProperty($backend, 'activeReadSlots');
            $deadline = microtime(true) + 3;
            while ($slots->getValue($backend) !== 0 && microtime(true) < $deadline) {
                delay(0.05);
            }
            self::assertSame(0, $slots->getValue($backend));
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="ready"} 1', $metrics->renderPrometheus());

            $before = self::get($client, self::presignedGet($sdk, 'before'));
            self::assertSame(503, $before->getStatus());
            self::assertStringContainsString('<Code>SlowDown</Code>', buffer($before->getBody()));

            $dripStarted = microtime(true);
            $drip = self::get($client, self::presignedGet($sdk, 'drip'));
            self::assertSame(200, $drip->getStatus());
            self::assertSame(str_repeat('c', 65_536), buffer($drip->getBody()));
            self::assertGreaterThan(1.0, microtime(true) - $dripStarted, 'The per-read timeout must reset after each valid chunk.');

            $recovered = self::get($client, self::presignedGet($sdk, 'fast'));
            self::assertSame(200, $recovered->getStatus());
            self::assertSame('fast-body', buffer($recovered->getBody()));
        } finally {
            $runtime?->stop();
            $remote->stop();
            self::removeDirectory($temp);
        }
    }

    public function test_real_adapter_stall_keeps_api_alive_bounded_and_recovers(): void
    {
        $temp = sys_get_temp_dir() . '/s3-real-adapter-fault-' . bin2hex(random_bytes(6));
        mkdir($temp, 0o755, true);
        $entered = new DeferredFuture();
        $logger = new NullLogger();
        $remote = SocketHttpServer::createForDirectAccess($logger, enableCompression: false);
        $remote->expose('127.0.0.1:0');
        $remote->start(new SyntheticS3FaultHandler($entered), new DefaultErrorHandler());
        $remoteAddress = $remote->getServers()[0]->getAddress();
        self::assertInstanceOf(InternetAddress::class, $remoteAddress);
        $remotePort = $remoteAddress->getPort();
        $apiPort = self::unusedPort();
        self::assertNotSame($remotePort, $apiPort);
        $runtime = null;

        try {
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'fault-gate', 'us-east-1');
            foreach (['slow', 'fast'] as $key) {
                $body = $key . '-body';
                $metadata->putObjectMetadata('fault-gate', $key, 'owner', strlen($body), '"' . md5($body) . '"', 'text/plain', 'fault-gate/' . $key);
            }

            $metrics = new MetricsCollector();
            $backend = new ParallelFlysystemBackend(
                new AwsS3FlysystemFilesystemFactory(
                    remoteBucket: 'remote-bucket',
                    region: 'us-east-1',
                    accessKeyId: 'local-key',
                    secretAccessKey: 'local-secret',
                    endpoint: "http://127.0.0.1:{$remotePort}",
                    pathStyle: true,
                    allowInsecureHttpForTests: true,
                ),
                $temp,
                1,
                metrics: $metrics,
                firstByteTimeoutSeconds: 1,
            );
            $runtime = (new S3ServerRuntimeFactory())->create(
                config: new S3ServerConfig(
                    host: '127.0.0.1',
                    port: $apiPort,
                    storagePath: $temp,
                    allowUnfencedStorageForTests: true,
                    perClientRateLimit: 0,
                    notificationWorkerEnabled: false,
                ),
                metadata: $metadata,
                storage: $backend,
                credentialProvider: new InMemoryCredentialProvider(new Credential('fault-key', 'fault-secret', 'owner')),
                logger: $logger,
                metrics: $metrics,
            );
            $runtime->server->start();

            $client = (new HttpClientBuilder())->retry(0)->build();
            $sdk = new S3Client([
                'version' => 'latest',
                'region' => 'us-east-1',
                'endpoint' => "http://127.0.0.1:{$apiPort}",
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => 'fault-key', 'secret' => 'fault-secret'],
            ]);
            $slowUrl = self::presignedGet($sdk, 'slow');
            $fastUrl = self::presignedGet($sdk, 'fast');

            $slow = async(static fn(): ClientResponse => self::get($client, $slowUrl));
            $entered->getFuture()->await(new \Amp\TimeoutCancellation(3));

            $liveStarted = microtime(true);
            self::assertSame(200, self::get($client, "http://127.0.0.1:{$apiPort}/.live")->getStatus());
            self::assertLessThan(2.0, microtime(true) - $liveStarted);

            $burst = [];
            for ($index = 0; $index < 12; $index++) {
                $burst[] = async(static fn(): ClientResponse => self::get($client, $fastUrl));
            }
            delay(0.05);
            $slots = new \ReflectionProperty($backend, 'activeReadSlots');
            $waiters = new \ReflectionProperty($backend, 'readWaiters');
            self::assertLessThanOrEqual(1, $slots->getValue($backend));
            self::assertLessThanOrEqual(1, count($waiters->getValue($backend)));

            $statuses = [];
            foreach ([$slow, ...$burst] as $index => $request) {
                $response = $request->await(new \Amp\TimeoutCancellation(3));
                $statuses[$index] = $response->getStatus();
                if ($response->getStatus() === 503) {
                    self::assertStringContainsString('<Code>SlowDown</Code>', buffer($response->getBody()));
                } else {
                    self::assertSame(200, $response->getStatus(), 'Response index ' . $index);
                    self::assertSame('fast-body', buffer($response->getBody()));
                }
            }
            self::assertSame(503, $statuses[0], implode(',', $statuses));
            // A cancelled SDK read may free its worker before the delayed
            // body arrives; later fast requests can then legitimately pass.
            self::assertContains(503, array_slice($statuses, 1), implode(',', $statuses));
            self::assertSame([], $waiters->getValue($backend));

            $deadline = microtime(true) + 5;
            while ($slots->getValue($backend) !== 0 && microtime(true) < $deadline) {
                delay(0.05);
            }
            self::assertSame(0, $slots->getValue($backend));

            $recovered = self::get($client, $fastUrl);
            self::assertSame(200, $recovered->getStatus());
            self::assertSame('fast-body', buffer($recovered->getBody()));
        } finally {
            $runtime?->stop();
            $remote->stop();
            self::removeDirectory($temp);
        }
    }

    private static function get(HttpClient $client, string $url): ClientResponse
    {
        $request = new ClientRequest($url);
        $request->setTransferTimeout(3);

        return $client->request($request);
    }

    private static function presignedGet(S3Client $sdk, string $key): string
    {
        return (string) $sdk->createPresignedRequest(
            $sdk->getCommand('GetObject', ['Bucket' => 'fault-gate', 'Key' => $key]),
            '+5 minutes',
        )->getUri();
    }

    private static function unusedPort(): int
    {
        $reservation = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($reservation);
        $address = stream_socket_get_name($reservation, false);
        self::assertIsString($address);
        fclose($reservation);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private static function removeDirectory(string $directory): void
    {
        foreach (new \DirectoryIterator($directory) as $entry) {
            if ($entry->isDot()) {
                continue;
            }
            if ($entry->isDir()) {
                self::removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}

final readonly class SyntheticS3FaultHandler implements RequestHandler
{
    /** @param DeferredFuture<void> $entered */
    public function __construct(private DeferredFuture $entered) {}

    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() !== 'GET' || ! in_array($path, ['/remote-bucket/fault-gate/slow', '/remote-bucket/fault-gate/fast'], true)) {
            return new Response(404, body: '<Error><Code>NoSuchKey</Code></Error>');
        }

        $body = str_ends_with($path, '/slow') ? 'slow-body' : 'fast-body';
        if ($body === 'slow-body') {
            $this->entered->complete();
            $stream = new ReadableIterableStream((static function () use ($body): \Generator {
                delay(2.5);
                yield $body;
            })());

            return new Response(200, ['content-length' => (string) strlen($body)], $stream);
        }

        return new Response(200, body: $body);
    }
}

final readonly class SyntheticS3MidstreamFaultHandler implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        $path = $request->getUri()->getPath();
        if ($request->getMethod() !== 'GET' || ! in_array($path, ['/remote-bucket/fault-gate/midstream', '/remote-bucket/fault-gate/drip', '/remote-bucket/fault-gate/before', '/remote-bucket/fault-gate/fast'], true)) {
            return new Response(404, body: '<Error><Code>NoSuchKey</Code></Error>');
        }
        if (str_ends_with($path, '/fast')) {
            return new Response(200, body: 'fast-body');
        }
        if (str_ends_with($path, '/before')) {
            return new Response(200, ['content-length' => '11'], new ReadableIterableStream((static function (): \Generator {
                delay(3.5);
                yield 'before-body';
            })()));
        }
        if (str_ends_with($path, '/drip')) {
            return new Response(200, ['content-length' => '65536'], new ReadableIterableStream((static function (): \Generator {
                for ($chunk = 0; $chunk < 4; $chunk++) {
                    if ($chunk > 0) {
                        delay(0.4);
                    }
                    yield str_repeat('c', 16_384);
                }
            })()));
        }

        $stream = new ReadableIterableStream((static function (): \Generator {
            yield str_repeat('a', 65_536);
            delay(3.5);
            yield str_repeat('b', 65_536);
        })());

        return new Response(200, ['content-length' => '131072'], $stream);
    }
}
