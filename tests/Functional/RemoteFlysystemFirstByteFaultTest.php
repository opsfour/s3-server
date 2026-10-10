<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Client\HttpClient;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Http\Client\Response;
use Aws\S3\S3Client;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FlysystemBackend;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Tests\Support\SlowLocalFlysystemFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Amp\async;
use function Amp\delay;

/**
 * Exercises the complete HTTP GET path while a remote-style synchronous
 * Flysystem adapter is blocked before its first byte. No external service is used.
 */
final class RemoteFlysystemFirstByteFaultTest extends TestCase
{
    public function test_stalled_remote_get_returns_503_keeps_liveness_and_recovers_bounded_read_slots(): void
    {
        $root = sys_get_temp_dir() . '/s3-remote-get-fault-' . bin2hex(random_bytes(6));
        $remote = $root . '/remote';
        $temp = $root . '/temp';
        mkdir($remote, 0o755, true);
        mkdir($temp, 0o755, true);
        $runtime = null;

        try {
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'fault-gate', 'us-east-1');
            $direct = new FlysystemBackend((new SlowLocalFlysystemFactory($remote))->createFilesystem(), $temp);
            $direct->createBucket('fault-gate');
            $slow = $direct->putObject('fault-gate', 'slow', new ReadableBuffer('slow-body'));
            $fast = $direct->putObject('fault-gate', 'fast', new ReadableBuffer('fast-body'));
            foreach (['slow' => $slow, 'fast' => $fast] as $key => $write) {
                $metadata->putObjectMetadata('fault-gate', $key, 'owner', strlen($key . '-body'), '"' . $write->md5Hex . '"', 'text/plain', $write->path);
            }

            $metrics = new MetricsCollector();
            $backend = new ParallelFlysystemBackend(
                new SlowLocalFlysystemFactory($remote, readDelayMicroseconds: 2_500_000, readDelayPath: $slow->path),
                $temp,
                1,
                metrics: $metrics,
                firstByteTimeoutSeconds: 1,
            );
            $port = self::unusedPort();
            $runtime = (new S3ServerRuntimeFactory())->create(
                config: new S3ServerConfig(
                    host: '127.0.0.1',
                    port: $port,
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
                'endpoint' => "http://127.0.0.1:{$port}",
                'use_path_style_endpoint' => true,
                'credentials' => ['key' => 'fault-key', 'secret' => 'fault-secret'],
            ]);
            $slowUrl = (string) $sdk->createPresignedRequest(
                $sdk->getCommand('GetObject', ['Bucket' => 'fault-gate', 'Key' => 'slow']),
                '+5 minutes',
            )->getUri();
            $fastUrl = (string) $sdk->createPresignedRequest(
                $sdk->getCommand('GetObject', ['Bucket' => 'fault-gate', 'Key' => 'fast']),
                '+5 minutes',
            )->getUri();

            $slowRequest = async(static fn(): Response => self::get($client, $slowUrl));
            delay(0.15);
            $liveStarted = microtime(true);
            $live = self::get($client, "http://127.0.0.1:{$port}/.live");
            self::assertSame(200, $live->getStatus());
            self::assertLessThan(2.0, microtime(true) - $liveStarted);

            $queued = [];
            for ($index = 0; $index < 12; ++$index) {
                $queued[] = async(static fn(): Response => self::get($client, $fastUrl));
            }
            delay(0.1);
            $slots = new \ReflectionProperty($backend, 'activeReadSlots');
            $waiters = new \ReflectionProperty($backend, 'readWaiters');
            self::assertLessThanOrEqual(1, $slots->getValue($backend));
            self::assertLessThanOrEqual(1, count($waiters->getValue($backend)));

            foreach ([$slowRequest, ...$queued] as $request) {
                $response = $request->await(new \Amp\TimeoutCancellation(3));
                self::assertSame(503, $response->getStatus());
                self::assertStringContainsString('<Code>SlowDown</Code>', \Amp\ByteStream\buffer($response->getBody()));
            }
            self::assertSame([], $waiters->getValue($backend));

            // The adapter itself is synchronous. Once it returns, the
            // cancelled read must free its worker for a new successful GET.
            self::waitForReadSlotDrain($slots, $backend);
            $recovered = self::get($client, $fastUrl);
            self::assertSame(200, $recovered->getStatus());
            self::assertSame('fast-body', \Amp\ByteStream\buffer($recovered->getBody()));
            self::waitForReadSlotDrain($slots, $backend);

            $renderedMetrics = $metrics->renderPrometheus();
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="ready"} 1', $renderedMetrics);
            self::assertStringContainsString('s3_server_flysystem_read_first_byte_seconds_count{result="first_byte_timeout"} 1', $renderedMetrics);
            self::assertMatchesRegularExpression('/s3_server_flysystem_read_first_byte_seconds_count\{result="admission_timeout"\} [1-9][0-9]*/', $renderedMetrics);
            self::assertMatchesRegularExpression('/s3_server_flysystem_read_first_byte_seconds_count\{result="admission_rejected"\} [1-9][0-9]*/', $renderedMetrics);
        } finally {
            $runtime?->stop();
            self::removeDirectory($root);
        }
    }

    private static function get(HttpClient $client, string $url): Response
    {
        $request = new Request($url);
        $request->setTransferTimeout(3);

        return $client->request($request);
    }

    private static function waitForReadSlotDrain(\ReflectionProperty $slots, ParallelFlysystemBackend $backend): void
    {
        $deadline = microtime(true) + 5;
        while ($slots->getValue($backend) !== 0 && microtime(true) < $deadline) {
            delay(0.05);
        }
        self::assertSame(0, $slots->getValue($backend));
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
        if (! is_dir($directory)) {
            return;
        }
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
