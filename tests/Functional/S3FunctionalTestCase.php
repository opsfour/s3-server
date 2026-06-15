<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\S3Client;
use PHPUnit\Framework\TestCase;

/**
 * Base test case for functional S3 tests.
 *
 * Starts the S3 server in a background process, creates an AWS SDK
 * client, and tears everything down after the test suite completes.
 */
abstract class S3FunctionalTestCase extends TestCase
{
    protected static ?S3Client $s3 = null;

    /** @var resource|null */
    protected static $serverProcess = null;

    /** @var array<int, resource> */
    protected static array $serverPipes = [];

    protected static string $serverStdoutPath = '';

    protected static string $serverStderrPath = '';

    protected static string $storagePath = '';

    protected static string $host = '127.0.0.1';

    protected static int $port = 0;

    protected static string $accessKey = 'testAccessKey';

    protected static string $secretKey = 'testSecretKey';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        // Create a temporary storage directory.
        self::$storagePath = sys_get_temp_dir().'/s3-test-'.uniqid();
        mkdir(self::$storagePath, 0o755, true);

        // Find a free port.
        self::$port = self::findFreePort();

        // Start the S3 server in the background.
        self::startServer();

        // Wait for the server to be ready.
        self::waitForServer();

        // Create the AWS SDK client.
        self::$s3 = self::makeClient();
    }

    protected static function makeClient(?int $timeout = null): S3Client
    {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => self::$accessKey,
                'secret' => self::$secretKey,
            ],
            'http' => [
                'connect_timeout' => 5,
                'timeout' => $timeout ?? 30,
            ],
        ]);
    }

    protected static function restartServer(?int $clientTimeout = null): void
    {
        self::stopServer();
        self::startServer();
        self::waitForServer();
        self::$s3 = self::makeClient($clientTimeout);
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        // Clean up the storage directory.
        if (self::$storagePath !== '' && is_dir(self::$storagePath)) {
            self::recursiveDelete(self::$storagePath);
        }

        self::$s3 = null;

        parent::tearDownAfterClass();
    }

    protected static function serverPid(): ?int
    {
        if (self::$serverProcess === null || !is_resource(self::$serverProcess)) {
            return null;
        }

        $status = proc_get_status(self::$serverProcess);

        return $status['running'] ? (int) $status['pid'] : null;
    }

    protected static function serverRssBytes(): ?int
    {
        $pid = self::serverPid();
        if ($pid === null) {
            return null;
        }

        $rssKb = trim((string) shell_exec('ps -o rss= -p ' . (int) $pid));
        if ($rssKb === '' || !ctype_digit($rssKb)) {
            return null;
        }

        return (int) $rssKb * 1024;
    }

    private static function stopServer(): void
    {
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            // Close pipes first.
            foreach (self::$serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::$serverPipes = [];

            // Send SIGTERM to the process group.
            $status = proc_get_status(self::$serverProcess);
            if ($status['running']) {
                // Use exec prefix so proc_terminate kills the PHP process directly.
                proc_terminate(self::$serverProcess, SIGTERM);
                // Wait a bit for graceful shutdown.
                usleep(500_000);
                // Force kill if still running.
                $status = proc_get_status(self::$serverProcess);
                if ($status['running']) {
                    proc_terminate(self::$serverProcess, SIGKILL);
                }
            }
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }

        if (self::$serverStdoutPath !== '') {
            @unlink(self::$serverStdoutPath);
            self::$serverStdoutPath = '';
        }
        if (self::$serverStderrPath !== '') {
            @unlink(self::$serverStderrPath);
            self::$serverStderrPath = '';
        }
    }

    private static function findFreePort(): int
    {
        $sock = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        socket_bind($sock, '127.0.0.1', 0);
        socket_getsockname($sock, $addr, $port);
        socket_close($sock);

        return $port;
    }

    private static function startServer(): void
    {
        $binPath = realpath(__DIR__.'/../../bin/s3-server');

        self::$serverStdoutPath = tempnam(sys_get_temp_dir(), 's3-server-stdout-') ?: '';
        self::$serverStderrPath = tempnam(sys_get_temp_dir(), 's3-server-stderr-') ?: '';

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['file', self::$serverStdoutPath, 'a'],
            2 => ['file', self::$serverStderrPath, 'a'],
        ];

        // Use exec to replace the shell process so we can terminate the PHP process directly.
        $cmd = sprintf(
            'exec php %s --host=%s --port=%d --storage-path=%s --access-key=%s --secret-key=%s',
            escapeshellarg($binPath),
            escapeshellarg(self::$host),
            self::$port,
            escapeshellarg(self::$storagePath),
            escapeshellarg(self::$accessKey),
            escapeshellarg(self::$secretKey),
        );

        self::$serverProcess = proc_open(
            $cmd,
            $descriptors,
            self::$serverPipes,
        );

        if (! is_resource(self::$serverProcess)) {
            self::fail('Failed to start S3 server process.');
        }

        // Close stdin — the server doesn't need it.
        fclose(self::$serverPipes[0]);
        unset(self::$serverPipes[0]);

        unset(self::$serverPipes[1], self::$serverPipes[2]);
    }

    private static function waitForServer(): void
    {
        $maxWaitMs = 10_000;
        $intervalMs = 50;
        $elapsed = 0;

        while ($elapsed < $maxWaitMs) {
            // Check if process is still running.
            $status = proc_get_status(self::$serverProcess);
            if (! $status['running']) {
                // Read stderr for error details.
                $stderr = self::serverLogContents(self::$serverStderrPath);
                $stdout = self::serverLogContents(self::$serverStdoutPath);
                self::fail(sprintf(
                    "S3 server process died (exit code %d).\nstdout: %s\nstderr: %s",
                    $status['exitcode'],
                    $stdout,
                    $stderr,
                ));
            }

            $sock = @fsockopen(self::$host, self::$port, $errno, $errstr, 0.1);

            if ($sock !== false) {
                fclose($sock);
                // Give it a tiny bit more time to fully initialize.
                usleep(100_000);

                return;
            }

            usleep($intervalMs * 1000);
            $elapsed += $intervalMs;
        }

        // If we get here, dump output for debugging.
        $stderr = self::serverLogContents(self::$serverStderrPath);
        $stdout = self::serverLogContents(self::$serverStdoutPath);

        self::fail(sprintf(
            "S3 server failed to start within %d ms on %s:%d\nstdout: %s\nstderr: %s",
            $maxWaitMs,
            self::$host,
            self::$port,
            $stdout,
            $stderr,
        ));
    }

    private static function recursiveDelete(string $path): void
    {
        if (is_dir($path)) {
            $items = scandir($path);

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                self::recursiveDelete($path.'/'.$item);
            }

            rmdir($path);
        } elseif (file_exists($path)) {
            unlink($path);
        }
    }

    protected static function serverLogs(): string
    {
        return sprintf(
            "stdout:\n%s\nstderr:\n%s",
            self::serverLogContents(self::$serverStdoutPath),
            self::serverLogContents(self::$serverStderrPath),
        );
    }

    private static function serverLogContents(string $path): string
    {
        if ($path === '' || !is_file($path)) {
            return '';
        }

        $contents = file_get_contents($path);

        return $contents === false ? '' : $contents;
    }
}
