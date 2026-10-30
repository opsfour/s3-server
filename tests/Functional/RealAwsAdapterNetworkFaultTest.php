<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\DeferredFuture;
use Amp\Future;
use Amp\Parallel\Worker\ContextWorkerPool;
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
use Amp\Socket\ResourceServerSocket;
use Amp\Socket\Socket;
use Aws\S3\S3Client;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Tests\Support\QualifiedAwsReadFaultTask;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\ByteStream\buffer;
use function Amp\delay;
use function Amp\Socket\connect;
use function Amp\Socket\listen;

/** Real AWS SDK/Flysystem workers behind a byte-level TCP fault relay. */
final class RealAwsAdapterNetworkFaultTest extends TestCase
{
    public function test_qualified_payload_task_stalls_off_loop_and_unrelated_worker_progresses(): void
    {
        $origin = SocketHttpServer::createForDirectAccess(new NullLogger(), enableCompression: false);
        $origin->expose('127.0.0.1:0');
        $origin->start(new QualifiedAwsReadFaultOrigin(), new DefaultErrorHandler());
        $originAddress = $origin->getServers()[0]->getAddress();
        self::assertInstanceOf(InternetAddress::class, $originAddress);
        $proxy = new LocalGetFaultProxy('127.0.0.1:' . $originAddress->getPort());
        $pool = new ContextWorkerPool(2);
        $profile = new AwsS3ProviderProfile(
            'backing-private',
            'us-east-1',
            '123456789012',
            'objects',
            '.opsfour/write-attempts',
            1,
            1,
            1,
        );

        try {
            $stalled = $pool->submit(QualifiedAwsReadFaultTask::create(
                $profile,
                $proxy->address(),
                QualifiedAwsReadFaultTask::BEFORE_ATTEMPT,
            ));
            $proxy->waitForQueueStall();
            $tickStarted = microtime(true);
            delay(0.05);
            self::assertLessThan(0.5, microtime(true) - $tickStarted, 'A synchronous SDK stall blocked the event loop.');

            $fast = $pool->submit(QualifiedAwsReadFaultTask::create(
                $profile,
                $proxy->address(),
                QualifiedAwsReadFaultTask::FAST_ATTEMPT,
            ));
            self::assertSame('fast-body', $fast->getChannel()->receive(new \Amp\TimeoutCancellation(1)));
            $fast->getChannel()->send('next');
            self::assertNull($fast->getChannel()->receive(new \Amp\TimeoutCancellation(1)));
            $fast->await(new \Amp\TimeoutCancellation(1));

            $receiveError = null;
            try {
                $stalled->getChannel()->receive(new \Amp\TimeoutCancellation(3));
            } catch (\Throwable $error) {
                $receiveError = $error;
            }
            self::assertNotNull($receiveError, 'The pre-header stall was accepted as a payload.');

            $awaitError = null;
            try {
                $stalled->await(new \Amp\TimeoutCancellation(3));
            } catch (\Throwable $error) {
                $awaitError = $error;
            }
            self::assertNotNull($awaitError, 'The pre-header stall completed cleanly.');

            $midstream = $pool->submit(QualifiedAwsReadFaultTask::create(
                $profile,
                $proxy->address(),
                QualifiedAwsReadFaultTask::MIDSTREAM_ATTEMPT,
            ));
            $received = '';
            while (strlen($received) < 65_536) {
                $chunk = $midstream->getChannel()->receive(new \Amp\TimeoutCancellation(2));
                self::assertIsString($chunk);
                $received .= $chunk;
                $midstream->getChannel()->send('next');
            }
            self::assertSame(65_536, strlen($received));
            self::assertSame(0, substr_count($received, 'n'));
            $tickStarted = microtime(true);
            delay(0.05);
            self::assertLessThan(0.5, microtime(true) - $tickStarted, 'A midstream SDK stall blocked the event loop.');
            $midstreamError = null;
            try {
                $midstream->getChannel()->receive(new \Amp\TimeoutCancellation(3));
            } catch (\Throwable $error) {
                $midstreamError = $error;
            }
            self::assertNotNull($midstreamError, 'The stalled body was reported as clean EOF.');
        } finally {
            $pool->kill();
            $proxy->close();
            $origin->stop();
        }
    }

    public function test_network_stalls_distinguish_headers_first_byte_midstream_and_admission(): void
    {
        $temp = sys_get_temp_dir() . '/s3-network-fault-' . bin2hex(random_bytes(6));
        mkdir($temp, 0o755, true);
        $origin = SocketHttpServer::createForDirectAccess(new NullLogger(), enableCompression: false);
        $origin->expose('127.0.0.1:0');
        $origin->start(new LocalFaultOrigin(), new DefaultErrorHandler());
        $originAddress = $origin->getServers()[0]->getAddress();
        self::assertInstanceOf(InternetAddress::class, $originAddress);
        $originPort = $originAddress->getPort();
        $proxy = new LocalGetFaultProxy("127.0.0.1:{$originPort}");
        $apiPort = self::unusedPort();
        self::assertNotSame($originPort, $apiPort);
        $runtime = null;

        try {
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'fault-gate', 'us-east-1');
            foreach (LocalFaultOrigin::bodies() as $key => $body) {
                $metadata->putObjectMetadata('fault-gate', $key, 'owner', strlen($body), '"' . md5($body) . '"', 'application/octet-stream', 'fault-gate/' . $key);
            }

            $metrics = new MetricsCollector();
            $backend = new ParallelFlysystemBackend(
                new AwsS3FlysystemFilesystemFactory(
                    remoteBucket: 'remote-bucket',
                    region: 'us-east-1',
                    accessKeyId: 'local-key',
                    secretAccessKey: 'local-secret',
                    endpoint: 'http://' . $proxy->address(),
                    pathStyle: true,
                    readTimeoutSeconds: 1,
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
                logger: new NullLogger(),
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

            foreach (['before-header', 'before-body'] as $key) {
                $response = self::get($client, self::presignedGet($sdk, $key));
                self::assertSame(503, $response->getStatus(), $key);
                self::assertStringContainsString('<Code>SlowDown</Code>', buffer($response->getBody()));
                self::waitForReadSlot($backend);
            }

            $midstream = self::get($client, self::presignedGet($sdk, 'midstream'));
            self::assertSame(200, $midstream->getStatus());
            self::assertSame('131072', $midstream->getHeader('content-length'));
            $received = '';
            $bodyError = null;
            try {
                while (($chunk = $midstream->getBody()->read(new \Amp\TimeoutCancellation(5))) !== null) {
                    $received .= $chunk;
                }
            } catch (\Throwable $error) {
                $bodyError = $error::class;
            }
            self::assertSame(65_536, strlen($received));
            self::assertSame(0, substr_count($received, 'b'));
            self::assertNotNull($bodyError, 'A truncated Content-Length response must fail at the HTTP client.');
            self::waitForReadSlot($backend);

            $slow = async(static fn(): ClientResponse => self::get($client, self::presignedGet($sdk, 'queue-stall')));
            $proxy->waitForQueueStall();
            $waiting = async(static fn(): ClientResponse => self::get($client, self::presignedGet($sdk, 'fast')));
            delay(0.05);
            $rejected = self::get($client, self::presignedGet($sdk, 'fast'));
            self::assertSame(503, $rejected->getStatus());
            self::assertStringContainsString('<Code>SlowDown</Code>', buffer($rejected->getBody()));
            self::assertLessThanOrEqual(1, (new \ReflectionProperty($backend, 'activeReadSlots'))->getValue($backend));
            self::assertLessThanOrEqual(1, count((new \ReflectionProperty($backend, 'readWaiters'))->getValue($backend)));
            self::assertSame(503, $slow->await(new \Amp\TimeoutCancellation(5))->getStatus());
            $waitingResponse = $waiting->await(new \Amp\TimeoutCancellation(5));
            self::assertContains($waitingResponse->getStatus(), [200, 503]);
            buffer($waitingResponse->getBody());
            self::waitForReadSlot($backend);

            $fast = self::get($client, self::presignedGet($sdk, 'fast'));
            self::assertSame(200, $fast->getStatus());
            self::assertSame('fast-body', buffer($fast->getBody()));
            self::assertSame(200, self::get($client, "http://127.0.0.1:{$apiPort}/.live")->getStatus());
            $samples = $metrics->renderPrometheus();
            self::assertStringContainsString('result="first_byte_timeout"', $samples);
            self::assertStringContainsString('result="admission_rejected"', $samples);
            self::assertStringContainsString('result="ready"', $samples);
            self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="ready",stage="admission"}', $samples);
            self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="timeout",stage="worker"}', $samples);
        } finally {
            $runtime?->stop();
            $proxy->close();
            $origin->stop();
            self::removeDirectory($temp);
        }
    }

    private static function waitForReadSlot(ParallelFlysystemBackend $backend): void
    {
        $slots = new \ReflectionProperty($backend, 'activeReadSlots');
        $deadline = microtime(true) + 12;
        while ($slots->getValue($backend) !== 0 && microtime(true) < $deadline) {
            delay(0.05);
        }
        self::assertSame(0, $slots->getValue($backend));
    }

    private static function get(HttpClient $client, string $url): ClientResponse
    {
        $request = new ClientRequest($url);
        $request->setTransferTimeout(5);

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

final readonly class LocalFaultOrigin implements RequestHandler
{
    /** @return array<string, string> */
    public static function bodies(): array
    {
        return [
            'before-header' => 'header-body',
            'before-body' => 'first-body',
            'midstream' => str_repeat('a', 65_536) . str_repeat('b', 65_536),
            'queue-stall' => 'queue-body',
            'fast' => 'fast-body',
        ];
    }

    public function handleRequest(Request $request): Response
    {
        $key = basename($request->getUri()->getPath());
        $bodies = self::bodies();
        if ($request->getMethod() !== 'GET' || ! array_key_exists($key, $bodies)) {
            return new Response(404, body: '<Error><Code>NoSuchKey</Code></Error>');
        }
        $body = $bodies[$key];

        return new Response(200, ['content-length' => (string) strlen($body)], $body);
    }
}

/** Only loopback HTTP is accepted; response bytes are withheld after exact wire boundaries. */
final class LocalGetFaultProxy
{
    private readonly ResourceServerSocket $listener;

    /** @var Future<void> */
    private readonly Future $accepting;

    /** @var DeferredFuture<void> */
    private readonly DeferredFuture $queueStall;

    public function __construct(private readonly string $upstream)
    {
        $this->listener = listen('127.0.0.1:0');
        $this->queueStall = new DeferredFuture();
        $this->accepting = async(function (): void {
            while (($client = $this->listener->accept()) !== null) {
                async(fn() => $this->serve($client))->ignore();
            }
        });
    }

    public function address(): string
    {
        return (string) $this->listener->getAddress();
    }

    public function waitForQueueStall(): void
    {
        $this->queueStall->getFuture()->await(new \Amp\TimeoutCancellation(5));
    }

    public function close(): void
    {
        $this->listener->close();
        $this->accepting->await();
    }

    private function serve(Socket $client): void
    {
        $upstream = null;
        try {
            [$request] = self::readHeaders($client);
            $path = explode(' ', strtok($request, "\r\n") ?: '', 3)[1] ?? '';
            $key = basename(parse_url($path, PHP_URL_PATH) ?: '');
            $upstream = connect($this->upstream);
            $upstream->write($request);
            [$headers, $pending] = self::readHeaders($upstream);
            $qualifiedBefore = $key === QualifiedAwsReadFaultTask::BEFORE_ATTEMPT;
            $qualifiedMidstream = $key === QualifiedAwsReadFaultTask::MIDSTREAM_ATTEMPT;
            if ($key === 'before-header' || $key === 'queue-stall' || $qualifiedBefore) {
                if (($key === 'queue-stall' || $qualifiedBefore) && ! $this->queueStall->isComplete()) {
                    $this->queueStall->complete();
                }
                delay(2.5);
            }
            $client->write($headers);
            if ($key === 'before-body') {
                delay(2.5);
            }
            $length = self::contentLength($headers);
            if ($key === 'midstream' || $qualifiedMidstream) {
                self::forwardBody($upstream, $client, $pending, 65_536);
                delay(2.5);

                return;
            }
            self::forwardBody($upstream, $client, $pending, $length);
        } catch (\Throwable) {
            // The SDK normally closes the stalled connection after its deadline.
        } finally {
            $upstream?->close();
            $client->close();
        }
    }

    /** @return array{string, string} Header block and already-read body bytes. */
    private static function readHeaders(Socket $socket): array
    {
        $buffer = '';
        while (($at = strpos($buffer, "\r\n\r\n")) === false) {
            if (strlen($buffer) > 65_536) {
                throw new \RuntimeException('Oversized HTTP headers in fault proxy.');
            }
            $chunk = $socket->read(new \Amp\TimeoutCancellation(5));
            if ($chunk === null) {
                throw new \RuntimeException('Unexpected EOF in fault proxy headers.');
            }
            $buffer .= $chunk;
        }

        return [substr($buffer, 0, $at + 4), substr($buffer, $at + 4)];
    }

    private static function contentLength(string $headers): int
    {
        if (preg_match('/^content-length:\s*(\d+)\s*$/im', $headers, $matches) !== 1) {
            throw new \RuntimeException('Fault origin response lacks Content-Length.');
        }

        return (int) $matches[1];
    }

    private static function forwardBody(Socket $upstream, Socket $client, string $pending, int $length): void
    {
        $forwarded = 0;
        while ($forwarded < $length) {
            $chunk = $pending !== '' ? $pending : $upstream->read(new \Amp\TimeoutCancellation(5));
            $pending = '';
            if ($chunk === null) {
                throw new \RuntimeException('Unexpected EOF in fault origin body.');
            }
            $chunk = substr($chunk, 0, $length - $forwarded);
            $client->write($chunk);
            $forwarded += strlen($chunk);
        }
    }
}

final readonly class QualifiedAwsReadFaultOrigin implements RequestHandler
{
    public function handleRequest(Request $request): Response
    {
        $attempt = basename($request->getUri()->getPath());
        $body = match ($attempt) {
            QualifiedAwsReadFaultTask::BEFORE_ATTEMPT => 'before-body',
            QualifiedAwsReadFaultTask::MIDSTREAM_ATTEMPT => str_repeat('m', 65_536) . str_repeat('n', 65_536),
            QualifiedAwsReadFaultTask::FAST_ATTEMPT => 'fast-body',
            default => null,
        };
        if ($request->getMethod() !== 'GET' || $body === null) {
            return new Response(404, body: '<Error><Code>NoSuchKey</Code></Error>');
        }

        return new Response(200, [
            'content-length' => (string) strlen($body),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attempt,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($body),
        ], $body);
    }
}
