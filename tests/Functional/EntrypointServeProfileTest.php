<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use Aws\S3\S3Client;
use GuzzleHttp\Client;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

/**
 * Release gate for the three documented ways of running the server.
 *
 * Each profile boots the real command in a separate process with filesystem
 * storage and a persistent SQLite database, then exercises the same HTTP and
 * signed S3 journey before requiring a graceful SIGTERM shutdown.
 */
final class EntrypointServeProfileTest extends TestCase
{
    private const ACCESS_KEY = 'entrypointAccessKey';
    private const SECRET_KEY = 'entrypointSecretKey';

    /** @var resource|null */
    private $process = null;

    /** @var array<int, resource> */
    private array $pipes = [];

    private string $tempDir = '';
    private string $stdoutPath = '';
    private string $stderrPath = '';
    private int $port = 0;

    protected function setUp(): void
    {
        parent::setUp();

        if (! function_exists('proc_open') || ! defined('SIGTERM')) {
            self::markTestSkipped('The entrypoint release gate requires process control and POSIX signals.');
        }

        $this->tempDir = sys_get_temp_dir() . '/s3-entrypoint-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($this->tempDir, 0o700, true));
        self::assertTrue(mkdir($this->storagePath(), 0o700));
        $this->stdoutPath = $this->tempDir . '/stdout.log';
        $this->stderrPath = $this->tempDir . '/stderr.log';
        $this->port = $this->unusedPort();
    }

    protected function tearDown(): void
    {
        $this->stopProcess(requireGraceful: false);

        if ($this->tempDir !== '' && is_dir($this->tempDir)) {
            $this->deleteDirectory($this->tempDir);
        }

        parent::tearDown();
    }

    /** @return iterable<string, array{string}> */
    public static function serveProfiles(): iterable
    {
        yield 'standalone console' => ['standalone'];
        yield 'Laravel Artisan' => ['laravel'];
        yield 'Symfony Console' => ['symfony'];
    }

    #[DataProvider('serveProfiles')]
    public function test_real_entrypoint_serves_persistent_local_profile_and_stops_cleanly(string $profile): void
    {
        $this->startProcess($profile);

        $http = new Client([
            'base_uri' => $this->endpoint(),
            'connect_timeout' => 0.2,
            'timeout' => 2,
            'http_errors' => false,
        ]);
        $this->waitUntilLive($http);

        self::assertSame(200, $http->get('/.live')->getStatusCode());
        self::assertSame(200, $http->get('/.health')->getStatusCode());
        self::assertSame(403, $http->get('/')->getStatusCode(), 'Unsigned S3 access must be rejected.');

        try {
            $s3 = $this->s3Client();
            $bucket = 'entrypoint-' . $profile;
            $body = "entrypoint profile {$profile}\n";
            self::assertSame(200, $s3->createBucket(['Bucket' => $bucket])['@metadata']['statusCode']);
            self::assertSame(200, $s3->putObject([
                'Bucket' => $bucket,
                'Key' => 'probe.txt',
                'Body' => $body,
                'ContentType' => 'text/plain',
            ])['@metadata']['statusCode']);
            $object = $s3->getObject(['Bucket' => $bucket, 'Key' => 'probe.txt']);
            self::assertSame(200, $object['@metadata']['statusCode']);
            self::assertSame($body, (string) $object['Body']);
            self::assertSame('text/plain', $object['ContentType']);

            // The release entrypoints must expose SSE-C even when the child
            // process has no server-managed encryption key.
            $s3->getHandlerList()->remove('s3.ssec');
            $customerKey = hash('sha256', 'entrypoint-sse-c-' . $profile, true);
            $sseC = [
                'SSECustomerAlgorithm' => 'AES256',
                'SSECustomerKey' => base64_encode($customerKey),
                'SSECustomerKeyMD5' => base64_encode(md5($customerKey, true)),
            ];
            self::assertSame(200, $s3->putObject([
                'Bucket' => $bucket,
                'Key' => 'sse-c.txt',
                'Body' => $body,
            ] + $sseC)['@metadata']['statusCode']);
            $encrypted = $s3->getObject([
                'Bucket' => $bucket,
                'Key' => 'sse-c.txt',
            ] + $sseC);
            self::assertSame($body, (string) $encrypted['Body']);

            try {
                $s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => 'unavailable-sse-s3.txt',
                    'Body' => $body,
                    'ServerSideEncryption' => 'AES256',
                ]);
                self::fail('SSE-S3 was accepted without a server master key.');
            } catch (S3Exception $error) {
                self::assertSame(501, $error->getStatusCode());
                self::assertSame('NotImplemented', $error->getAwsErrorCode());
            }
        } catch (\Throwable $error) {
            self::fail($error->getMessage() . "\n" . $this->logs());
        }

        self::assertFileExists($this->metadataPath());
        self::assertGreaterThan(0, filesize($this->metadataPath()));

        $this->stopProcess(requireGraceful: true);
        $this->waitUntilListenerClosed();
    }

    private function startProcess(string $profile): void
    {
        $command = match ($profile) {
            'standalone' => $this->standaloneCommand(),
            'laravel' => [PHP_BINARY, $this->writeLauncher('laravel', $this->laravelLauncher()), 's3:serve'],
            'symfony' => [PHP_BINARY, $this->writeLauncher('symfony', $this->symfonyLauncher())],
            default => throw new \InvalidArgumentException("Unknown serve profile: {$profile}"),
        };

        $environment = getenv();
        self::assertIsArray($environment);
        unset($environment['S3_ENCRYPTION_MASTER_KEY'], $environment['S3_ENCRYPTION_MASTER_KEYS']);
        $environment['S3_MASTER_KEY_PROVIDER'] = 'config';

        $process = proc_open($command, [
            0 => ['pipe', 'r'],
            1 => ['file', $this->stdoutPath, 'a'],
            2 => ['file', $this->stderrPath, 'a'],
        ], $this->pipes, dirname(__DIR__, 2), $environment);
        if (! is_resource($process)) {
            self::fail("Failed to start the {$profile} serve profile.");
        }
        $this->process = $process;

        fclose($this->pipes[0]);
        unset($this->pipes[0], $this->pipes[1], $this->pipes[2]);
    }

    /** @return list<string> */
    private function standaloneCommand(): array
    {
        $bin = realpath(dirname(__DIR__, 2) . '/bin/s3-server');
        self::assertIsString($bin);

        return [
            PHP_BINARY,
            $bin,
            'serve',
            '--host=127.0.0.1',
            '--port=' . $this->port,
            '--storage-driver=filesystem',
            '--storage-path=' . $this->storagePath(),
            '--metadata-driver=sqlite',
            '--metadata-path=' . $this->metadataPath(),
            '--metadata-cache-ttl=0',
            '--access-key=' . self::ACCESS_KEY,
            '--secret-key=' . self::SECRET_KEY,
            '--owner-id=entrypoint-owner',
            '--display-name=Entrypoint Owner',
            '--request-body-spool-workers=1',
            '--notification-worker=false',
        ];
    }

    private function laravelLauncher(): string
    {
        $autoload = $this->autoloadPath();
        $config = [
            'host' => '127.0.0.1',
            'port' => $this->port,
            'region' => 'us-east-1',
            'shutdown_drain_timeout' => 5,
            'storage' => [
                'driver' => 'filesystem',
                'path' => $this->storagePath(),
                'tiers' => [],
            ],
            'metadata' => [
                'driver' => 'sqlite',
                'path' => $this->metadataPath(),
                'cache_ttl' => 0.0,
            ],
            'credentials' => [
                'driver' => 'memory',
                'access_key' => self::ACCESS_KEY,
                'secret_key' => self::SECRET_KEY,
                'owner_id' => 'entrypoint-owner',
                'display_name' => 'Entrypoint Owner',
            ],
            'parallel' => [
                'sqlite_workers' => 0,
                'encryption_workers' => 0,
                'select_workers' => 0,
                'request_body_spool_workers' => 1,
            ],
            'notifications' => ['worker_enabled' => false],
        ];

        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

require %s;

use Illuminate\Config\Repository;
use Illuminate\Console\Application as ArtisanApplication;
use Illuminate\Events\Dispatcher;
use Illuminate\Foundation\Application;
use OpsFour\S3Server\Laravel\S3ServerServiceProvider;

ArtisanApplication::forgetBootstrappers();
$app = new Application(%s);
$app->instance('config', new Repository(['s3-server' => %s]));
$app->register(S3ServerServiceProvider::class);
$app->boot();
$artisan = new ArtisanApplication($app, new Dispatcher($app), 'test');
exit($artisan->run());
PHP,
            var_export($autoload, true),
            var_export($this->tempDir . '/laravel', true),
            var_export($config, true),
        );
    }

    private function symfonyLauncher(): string
    {
        $autoload = $this->autoloadPath();
        $config = [
            'server' => [
                'host' => '127.0.0.1',
                'port' => $this->port,
                'shutdown_drain_timeout' => 5,
            ],
            'storage' => [
                'driver' => 'filesystem',
                'path' => $this->storagePath(),
            ],
            'metadata' => [
                'driver' => 'sqlite',
                'path' => $this->metadataPath(),
                'cache_ttl' => 0.0,
            ],
            'credentials' => [
                'driver' => 'memory',
                'access_key' => self::ACCESS_KEY,
                'secret_key' => self::SECRET_KEY,
                'owner_id' => 'entrypoint-owner',
                'display_name' => 'Entrypoint Owner',
            ],
            'parallel' => [
                'sqlite_workers' => 0,
                'encryption_workers' => 0,
                'select_workers' => 0,
                'request_body_spool_workers' => 1,
            ],
            'notifications' => ['worker_enabled' => false],
        ];

        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

require %s;

use OpsFour\S3Server\Symfony\Command\S3ServerServeCommand;
use OpsFour\S3Server\Symfony\DependencyInjection\S3ServerExtension;
use Symfony\Component\Console\Application;
use Symfony\Component\DependencyInjection\ContainerBuilder;

$container = new ContainerBuilder();
(new S3ServerExtension())->load([%s], $container);
$container->compile();
$app = new Application('opsfour S3 Symfony Entrypoint Gate');
$registerCommand = method_exists($app, 'addCommand') ? 'addCommand' : 'add';
$app->{$registerCommand}($container->get(S3ServerServeCommand::class));
$app->setDefaultCommand('opsfour:s3:serve', true);
exit($app->run());
PHP,
            var_export($autoload, true),
            var_export($config, true),
        );
    }

    private function writeLauncher(string $profile, string $contents): string
    {
        $path = $this->tempDir . '/' . $profile . '.php';
        self::assertNotFalse(file_put_contents($path, $contents));

        return $path;
    }

    private function autoloadPath(): string
    {
        $autoload = realpath(dirname(__DIR__, 2) . '/vendor/autoload.php');
        self::assertIsString($autoload);

        return $autoload;
    }

    private function waitUntilLive(Client $http): void
    {
        $deadline = microtime(true) + 10.0;
        $lastError = null;
        do {
            if (! is_resource($this->process)) {
                self::fail('Serve process was not created.');
            }
            $status = proc_get_status($this->process);
            if (! $status['running']) {
                self::fail(sprintf(
                    "Serve process exited before becoming live (exit code %d).\n%s",
                    $status['exitcode'],
                    $this->logs(),
                ));
            }

            try {
                $response = $http->get('/.live');
                if ($response->getStatusCode() === 200) {
                    return;
                }
            } catch (\Throwable $error) {
                $lastError = $error;
            }
            usleep(50_000);
        } while (microtime(true) < $deadline);

        self::fail(
            'Serve process did not become live.'
            . ($lastError === null ? '' : ' Last error: ' . $lastError::class)
            . "\n"
            . $this->logs(),
        );
    }

    private function stopProcess(bool $requireGraceful): void
    {
        if (! is_resource($this->process)) {
            return;
        }

        $status = proc_get_status($this->process);
        $exitCode = $status['exitcode'];
        $forced = false;
        if (! $status['running'] && $requireGraceful) {
            $logs = $this->logs();
            proc_close($this->process);
            $this->process = null;
            self::fail("Serve process exited before SIGTERM (exit code {$exitCode}). {$logs}");
        }
        if ($status['running']) {
            $accepted = proc_terminate($this->process, SIGTERM);
            $deadline = microtime(true) + 10.0;
            do {
                usleep(50_000);
                $status = proc_get_status($this->process);
            } while ($status['running'] && microtime(true) < $deadline);
            $exitCode = $status['exitcode'];

            if ($status['running']) {
                $forced = true;
                proc_terminate($this->process, SIGKILL);
            }

            if ($requireGraceful) {
                self::assertTrue($accepted, 'SIGTERM was not accepted. ' . $this->logs());
                self::assertFalse($forced, 'Serve process did not stop after SIGTERM. ' . $this->logs());
                self::assertSame(0, $exitCode, 'Serve process returned a failure exit code. ' . $this->logs());
            }
        }

        foreach ($this->pipes as $pipe) {
            if (is_resource($pipe)) {
                fclose($pipe);
            }
        }
        $this->pipes = [];
        proc_close($this->process);
        $this->process = null;
    }

    private function waitUntilListenerClosed(): void
    {
        $deadline = microtime(true) + 2.0;
        do {
            $socket = @fsockopen('127.0.0.1', $this->port, $errorCode, $errorMessage, 0.1);
            if ($socket === false) {
                return;
            }
            fclose($socket);
            usleep(25_000);
        } while (microtime(true) < $deadline);

        self::fail('Listener remained reachable after graceful shutdown. ' . $this->logs());
    }

    private function s3Client(): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => $this->endpoint(),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => 'when_supported',
            'retries' => 0,
            'credentials' => [
                'key' => self::ACCESS_KEY,
                'secret' => self::SECRET_KEY,
            ],
            'http' => [
                'connect_timeout' => 1,
                'timeout' => 5,
            ],
        ]);
    }

    private function endpoint(): string
    {
        return 'http://127.0.0.1:' . $this->port;
    }

    private function storagePath(): string
    {
        return $this->tempDir . '/storage';
    }

    private function metadataPath(): string
    {
        return $this->tempDir . '/metadata.sqlite';
    }

    private function logs(): string
    {
        return sprintf(
            "stdout:\n%s\nstderr:\n%s",
            is_file($this->stdoutPath) ? file_get_contents($this->stdoutPath) : '',
            is_file($this->stderrPath) ? file_get_contents($this->stderrPath) : '',
        );
    }

    private function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errorCode, $errorMessage);
        self::assertIsResource($socket, "Unable to reserve a port: {$errorCode} {$errorMessage}");
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);
        self::assertMatchesRegularExpression('/:(\d+)$/', $address);

        return (int) substr($address, strrpos($address, ':') + 1);
    }

    private function deleteDirectory(string $path): void
    {
        foreach (new \FilesystemIterator($path, \FilesystemIterator::SKIP_DOTS) as $item) {
            if (! $item instanceof \SplFileInfo) {
                continue;
            }
            if ($item->isDir() && ! $item->isLink()) {
                $this->deleteDirectory($item->getPathname());
            } else {
                @unlink($item->getPathname());
            }
        }
        @rmdir($path);
    }
}
