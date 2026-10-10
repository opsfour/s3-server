<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request as ClientRequest;
use Amp\Http\Client\Response as ClientResponse;
use Aws\S3\S3Client;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use OpsFour\S3Server\Tests\Support\LocalTlsFlysystemFactory;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;

use function Amp\delay;

/** Opt-in full API GET through an actual TLS relay and worker. */
final class ProviderPathTlsFaultTest extends TestCase
{
    public function test_synthetic_tls_provider_path_fault_and_recovery(): void
    {
        if (getenv('S3_TEST_TLS_RELAY') !== '1') {
            self::markTestSkipped('Set S3_TEST_TLS_RELAY=1 for the local Python/OpenSSL TLS relay gate.');
        }

        $temp = sys_get_temp_dir() . '/s3-provider-tls-' . bin2hex(random_bytes(6));
        mkdir($temp, 0o700, true);
        $externalRelay = getenv('S3_TEST_TLS_RELAY_EXTERNAL') === '1';
        $realProvider = $externalRelay && getenv('S3_TEST_TLS_RELAY_REAL') === '1';
        $cert = $externalRelay ? (string) getenv('S3_TEST_TLS_RELAY_CA') : $temp . '/relay.pem';
        $key = $temp . '/relay.key';
        $evidence = $externalRelay ? (string) getenv('S3_TEST_TLS_RELAY_EVIDENCE') : $temp . '/relay-events.jsonl';
        $endpoint = $externalRelay ? (string) getenv('S3_TEST_TLS_RELAY_ENDPOINT') : '';
        $origin = null;
        $proxy = null;
        $runtime = null;

        try {
            if ($externalRelay) {
                self::assertSame($realProvider ? (string) getenv('S3_GATE_ORIGIN_HOST') : 'provider.invalid', parse_url($endpoint, PHP_URL_HOST));
                self::assertIsReadable($cert);
                self::assertSame((string) getenv('S3_GATE_RELAY_IP'), gethostbyname((string) parse_url($endpoint, PHP_URL_HOST)));
            } else {
                self::runOpenSsl($cert, $key);
                $script = dirname(__DIR__) . '/Support/provider_path_tls_proxy.py';
                $origin = self::startProcess([
                    'python3', $script, '--synthetic-origin', '--cert', $cert, '--key', $key,
                    '--listen-port', '0',
                ], $temp . '/origin');
                $originPort = self::waitReadyPort($temp . '/origin.stdout', 'synthetic TLS origin ready', $origin);
                $proxy = self::startProcess([
                    'python3', $script, '--listen-ip', '127.0.0.1', '--listen-port', '0',
                    '--origin-host', 'localhost', '--request-host', 'localhost:0',
                    '--origin-ip', '127.0.0.1', '--origin-port', (string) $originPort,
                    '--cert', $cert, '--key', $key, '--synthetic-origin-ca', $cert,
                    '--evidence', $evidence, '--hold-seconds', '2.5',
                ], $temp . '/proxy');
                $proxyPort = self::waitReadyPort($temp . '/proxy.stdout', 'provider-path TLS relay ready on loopback', $proxy);
                $endpoint = "https://localhost:{$proxyPort}";
            }

            $apiPort = self::unusedPort();

            $body = str_repeat('x', 65_536);
            $metadata = new SqliteMetadataStore(':memory:');
            $metadata->initialize();
            $metadata->createBucket('owner', 'fault-gate', 'us-east-1');
            $storagePath = $realProvider ? (string) getenv('S3_GATE_REMOTE_PREFIX') . 'fault.bin' : 'fault-gate/item';
            $metadata->putObjectMetadata('fault-gate', 'item', 'owner', strlen($body), '"' . md5($body) . '"', 'application/octet-stream', $storagePath);
            $metrics = new MetricsCollector();
            $readTimeout = $realProvider ? 30 : 5;
            $backend = new ParallelFlysystemBackend(
                new LocalTlsFlysystemFactory(
                    $endpoint,
                    $cert,
                    $realProvider ? (string) getenv('S3_GATE_BACKING_BUCKET') : 'remote-bucket',
                    $realProvider ? (string) getenv('S3_GATE_BACKING_REGION') : 'us-east-1',
                    $realProvider ? (string) getenv('S3_GATE_BACKING_ACCESS_KEY') : 'local-key',
                    $realProvider ? (string) getenv('S3_GATE_BACKING_SECRET_KEY') : 'local-secret',
                    $readTimeout,
                ),
                $temp,
                1,
                metrics: $metrics,
                readIdleTimeoutSeconds: $readTimeout,
                firstByteTimeoutSeconds: $realProvider ? 20 : 1,
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
            $url = (string) $sdk->createPresignedRequest(
                $sdk->getCommand('GetObject', ['Bucket' => 'fault-gate', 'Key' => 'item']),
                '+5 minutes',
            )->getUri();

            $started = microtime(true);
            $faultStartedNs = hrtime(true);
            $fault = new ClientRequest($url);
            $fault->setTransferTimeout($realProvider ? 30 : 5);
            $fault->setInactivityTimeout($realProvider ? 30 : 5);
            $first = $client->request($fault);
            $faultHeadersMs = round((hrtime(true) - $faultStartedNs) / 1_000_000, 3);
            [$faultBody, $faultFirstBodyMs] = self::readTimedBody($first, $faultStartedNs);
            $faultTotalMs = round((hrtime(true) - $faultStartedNs) / 1_000_000, 3);
            $safeEvents = is_file($evidence) ? (string) file_get_contents($evidence) : 'no relay events';
            $safeSamples = array_filter(
                explode("\n", $metrics->renderPrometheus()),
                static fn(string $line): bool => str_starts_with($line, 's3_server_flysystem_read_first_byte_seconds_count')
                    || str_starts_with($line, 's3_server_flysystem_read_stage_seconds_count'),
            );
            self::assertSame(503, $first->getStatus(), implode("\n", $safeSamples) . "\n" . $safeEvents);
            self::assertStringContainsString('<Code>SlowDown</Code>', $faultBody);
            self::assertLessThan($realProvider ? 24.0 : 2.5, microtime(true) - $started);
            self::assertSame(200, $client->request(new ClientRequest("http://127.0.0.1:{$apiPort}/.live"))->getStatus());

            delay($realProvider ? 5.5 : 2.7);
            $recoveryStartedNs = hrtime(true);
            $recovery = new ClientRequest($url);
            $recovery->setTransferTimeout($realProvider ? 30 : 5);
            $recovery->setInactivityTimeout($realProvider ? 30 : 5);
            $second = $client->request($recovery);
            $recoveryHeadersMs = round((hrtime(true) - $recoveryStartedNs) / 1_000_000, 3);
            [$recoveryBody, $recoveryFirstBodyMs] = self::readTimedBody($second, $recoveryStartedNs);
            $recoveryTotalMs = round((hrtime(true) - $recoveryStartedNs) / 1_000_000, 3);
            self::assertSame(200, $second->getStatus());
            self::assertSame($body, $recoveryBody);

            $samples = $metrics->renderPrometheus();
            self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="ready",stage="admission"} 2', $samples);
            self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="timeout",stage="worker"} 1', $samples);
            self::assertStringContainsString('s3_server_flysystem_read_stage_seconds_count{result="ready",stage="worker"} 1', $samples);
            $events = file($evidence, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            self::assertIsArray($events);
            self::assertSame(1, count(array_filter($events, static fn(string $line): bool => str_contains($line, '"event":"fault_before_body"'))));
            self::assertSame(2, count(array_filter($events, static fn(string $line): bool => str_contains($line, '"event":"origin_headers_received"'))));
            self::assertStringNotContainsString('Authorization', implode("\n", $events));
            self::assertStringNotContainsString('fault-gate/item', implode("\n", $events));
            if ($realProvider) {
                self::saveRealProviderEvidence($metrics, $evidence, [
                    'fault_status' => $first->getStatus(),
                    'fault_headers_ms' => $faultHeadersMs,
                    'fault_first_body_ms' => $faultFirstBodyMs,
                    'fault_total_ms' => $faultTotalMs,
                    'recovery_status' => $second->getStatus(),
                    'recovery_headers_ms' => $recoveryHeadersMs,
                    'recovery_first_body_ms' => $recoveryFirstBodyMs,
                    'recovery_total_ms' => $recoveryTotalMs,
                    'recovery_body_bytes' => strlen($recoveryBody),
                    'recovery_sha256' => hash('sha256', $recoveryBody),
                ]);
            }
        } finally {
            $runtime?->stop();
            self::stopProcess($proxy);
            self::stopProcess($origin);
            self::removeDirectory($temp);
        }
    }

    /** @return array{string, ?float} */
    private static function readTimedBody(ClientResponse $response, int $startedAtNs): array
    {
        $body = '';
        $firstMs = null;
        while (($chunk = $response->getBody()->read()) !== null) {
            if ($firstMs === null) {
                $firstMs = round((hrtime(true) - $startedAtNs) / 1_000_000, 3);
            }
            $body .= $chunk;
        }

        return [$body, $firstMs];
    }

    /** @param array<string, int|float|null|string> $timings */
    private static function saveRealProviderEvidence(MetricsCollector $metrics, string $relayEvents, array $timings): void
    {
        $directory = getenv('S3_GATE_EVIDENCE_DIR');
        if ($directory === false || $directory === '') {
            self::fail('Missing private real-provider evidence directory.');
        }
        $record = [
            'utc' => gmdate('Y-m-d\TH:i:s\Z'),
            'relay_event_count' => count(file($relayEvents, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: []),
        ] + $timings;
        if (file_put_contents($directory . '/gate-result.json', json_encode($record, JSON_THROW_ON_ERROR) . "\n", LOCK_EX) === false) {
            self::fail('Could not persist real-provider gate result.');
        }
        chmod($directory . '/gate-result.json', 0o600);
        $safeLines = array_filter(
            explode("\n", $metrics->renderPrometheus()),
            static fn(string $line): bool => str_starts_with($line, 's3_server_flysystem_read_first_byte_seconds_')
                || str_starts_with($line, 's3_server_flysystem_read_stage_seconds_'),
        );
        if (file_put_contents($directory . '/read-metrics.prom', implode("\n", $safeLines) . "\n", LOCK_EX) === false) {
            self::fail('Could not persist real-provider read metrics.');
        }
        chmod($directory . '/read-metrics.prom', 0o600);
    }

    private static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0');
        self::assertIsResource($socket);
        $name = stream_socket_get_name($socket, false);
        self::assertIsString($name);
        fclose($socket);

        return (int) substr($name, strrpos($name, ':') + 1);
    }

    private static function runOpenSsl(string $cert, string $key): void
    {
        $process = proc_open([
            'openssl', 'req', '-x509', '-newkey', 'rsa:2048', '-nodes', '-days', '1',
            '-subj', '/CN=localhost', '-addext', 'subjectAltName=DNS:localhost',
            '-keyout', $key, '-out', $cert,
        ], [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], $pipes);
        self::assertIsResource($process);
        self::assertSame(0, proc_close($process));
    }

    /** @param list<string> $command */
    private static function startProcess(array $command, string $logPrefix): mixed
    {
        $environment = getenv();
        self::assertIsArray($environment);
        unset($environment['SSL_CERT_FILE'], $environment['SSL_CERT_DIR']);
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', $logPrefix . '.stdout', 'w'],
            2 => ['file', $logPrefix . '.stderr', 'w'],
        ], $pipes, null, $environment);
        self::assertIsResource($process);

        return $process;
    }

    private static function waitReadyPort(string $log, string $message, mixed $process): int
    {
        $deadline = microtime(true) + 5;
        while (microtime(true) < $deadline) {
            if (is_file($log) && preg_match('/' . preg_quote($message, '/') . ' port=([0-9]{1,5})\b/', (string) file_get_contents($log), $matches) === 1) {
                $port = (int) $matches[1];
                self::assertGreaterThan(0, $port);
                self::assertLessThanOrEqual(65535, $port);

                return $port;
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail('Synthetic TLS helper exited before readiness.');
            }
            usleep(20_000);
        }
        self::fail('Synthetic TLS helper did not become ready.');
    }

    private static function stopProcess(mixed $process): void
    {
        if (is_resource($process)) {
            proc_terminate($process);
            proc_close($process);
        }
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
