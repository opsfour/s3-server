<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use GuzzleHttp\Client;
use PHPUnit\Framework\TestCase;

final class TlsRuntimeTest extends TestCase
{
    private string $tempDir = '';

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (!extension_loaded('openssl')) {
            $this->markTestSkipped('OpenSSL extension is required for the TLS runtime test.');
        }

        $this->tempDir = sys_get_temp_dir() . '/s3-tls-' . bin2hex(random_bytes(6));
        mkdir($this->tempDir, 0o700, true);
    }

    protected function tearDown(): void
    {
        $this->stopServer();
        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    public function test_tls_configuration_serves_https_and_stops_cleanly(): void
    {
        [$certPath, $keyPath] = $this->createCertificate();
        $port = $this->findFreePort();
        $this->startServer($port, $certPath, $keyPath);

        $client = new Client([
            'verify' => false,
            'http_errors' => false,
            'connect_timeout' => 1,
            'timeout' => 5,
        ]);
        $url = "https://127.0.0.1:{$port}/.health";

        $response = $this->waitForHttps($client, $url);
        $this->assertSame(200, $response->getStatusCode());
        $this->assertStringContainsString('"status":"ok"', (string) $response->getBody());

        $startedAt = microtime(true);
        $this->stopServer();
        $this->assertLessThan(5.0, microtime(true) - $startedAt);
    }

    /**
     * @return array{string, string}
     */
    private function createCertificate(): array
    {
        $privateKey = openssl_pkey_new([
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ]);
        $this->assertNotFalse($privateKey);

        $csr = openssl_csr_new(['commonName' => 'localhost'], $privateKey, ['digest_alg' => 'sha256']);
        if (!$csr instanceof \OpenSSLCertificateSigningRequest) {
            self::fail('Failed to create the TLS test certificate request.');
        }
        $certificate = openssl_csr_sign($csr, null, $privateKey, 1, ['digest_alg' => 'sha256']);
        $this->assertNotFalse($certificate);

        $this->assertTrue(openssl_x509_export($certificate, $certPem));
        $this->assertTrue(openssl_pkey_export($privateKey, $keyPem));

        $certPath = $this->tempDir . '/server.crt';
        $keyPath = $this->tempDir . '/server.key';
        file_put_contents($certPath, $certPem);
        file_put_contents($keyPath, $keyPem);
        chmod($keyPath, 0o600);

        return [$certPath, $keyPath];
    }

    private function startServer(int $port, string $certPath, string $keyPath): void
    {
        $stdout = $this->tempDir . '/stdout.log';
        $stderr = $this->tempDir . '/stderr.log';
        $bin = realpath(__DIR__ . '/../../bin/s3-server');
        $this->assertNotFalse($bin);

        $command = sprintf(
            'exec php %s --host=127.0.0.1 --port=%d --storage-path=%s'
            . ' --access-key=testAccessKey --secret-key=testSecretKey --tls-cert=%s --tls-key=%s',
            escapeshellarg($bin),
            $port,
            escapeshellarg($this->tempDir . '/storage'),
            escapeshellarg($certPath),
            escapeshellarg($keyPath),
        );

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $stdout, 'a'],
            2 => ['file', $stderr, 'a'],
        ], $this->pipes);
        if (!is_resource($process)) {
            self::fail('Failed to start the TLS test server.');
        }
        $this->process = $process;

        fclose($this->pipes[0]);
        unset($this->pipes[0]);
    }

    private function waitForHttps(Client $client, string $url): \Psr\Http\Message\ResponseInterface
    {
        if (!is_resource($this->process)) {
            self::fail('TLS test server process is not running.');
        }
        $process = $this->process;
        $lastError = null;
        for ($attempt = 0; $attempt < 100; $attempt++) {
            $status = proc_get_status($process);
            if (!$status['running']) {
                $stderr = @file_get_contents($this->tempDir . '/stderr.log') ?: '';
                $this->fail("TLS server stopped before becoming ready: {$stderr}");
            }

            try {
                return $client->get($url);
            } catch (\Throwable $e) {
                $lastError = $e;
                usleep(50_000);
            }
        }

        throw new \RuntimeException('TLS server did not become ready.', previous: $lastError);
    }

    private function stopServer(): void
    {
        if (!is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, SIGTERM);
            for ($attempt = 0; $attempt < 50; $attempt++) {
                usleep(50_000);
                if (!proc_get_status($this->process)['running']) {
                    break;
                }
            }
        }

        $status = proc_get_status($this->process);
        if ($status['running']) {
            proc_terminate($this->process, SIGKILL);
        }
        proc_close($this->process);
        $this->process = null;
        $this->pipes = [];
    }

    private function findFreePort(): int
    {
        $socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        $this->assertNotFalse($socket);
        $this->assertTrue(socket_bind($socket, '127.0.0.1', 0));
        $this->assertTrue(socket_getsockname($socket, $address, $port));
        socket_close($socket);

        return $port;
    }

    private function deleteDirectory(string $path): void
    {
        $items = scandir($path);
        if ($items === false) {
            return;
        }
        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $child = $path . '/' . $item;
            is_dir($child) ? $this->deleteDirectory($child) : @unlink($child);
        }
        @rmdir($path);
    }
}
