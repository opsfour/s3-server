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
    protected static S3Client $s3;

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
        self::$storagePath = sys_get_temp_dir() . '/s3-test-' . uniqid();
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

        $processes = shell_exec('ps -axo pid=,ppid=,rss=');
        if ($processes === null || $processes === false || trim($processes) === '') {
            return null;
        }

        /** @var array<int, array{parent: int, rss: int}> $rows */
        $rows = [];
        foreach (preg_split('/\R/', trim($processes)) ?: [] as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(\d+)\s*$/', $line, $matches) !== 1) {
                continue;
            }
            $rows[(int) $matches[1]] = [
                'parent' => (int) $matches[2],
                'rss' => (int) $matches[3],
            ];
        }
        if (! isset($rows[$pid])) {
            return null;
        }

        $processIds = [$pid => true];
        do {
            $changed = false;
            foreach ($rows as $processId => $row) {
                if (! isset($processIds[$processId]) && isset($processIds[$row['parent']])) {
                    $processIds[$processId] = true;
                    $changed = true;
                }
            }
        } while ($changed);

        $rssKb = 0;
        foreach (array_keys($processIds) as $processId) {
            $rssKb += $rows[$processId]['rss'] ?? 0;
        }

        return $rssKb * 1024;
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
        if ($sock === false) {
            self::fail('Failed to create a socket for the test server.');
        }
        if (!socket_bind($sock, '127.0.0.1', 0)
            || !socket_getsockname($sock, $addr, $port)
            || !is_int($port)) {
            socket_close($sock);
            self::fail('Failed to reserve a port for the test server.');
        }
        socket_close($sock);

        return $port;
    }

    private static function startServer(): void
    {
        $binPath = realpath(__DIR__ . '/../../bin/s3-server');
        if ($binPath === false) {
            self::fail('Unable to resolve the S3 server executable.');
        }

        self::$serverStdoutPath = tempnam(sys_get_temp_dir(), 's3-server-stdout-') ?: '';
        self::$serverStderrPath = tempnam(sys_get_temp_dir(), 's3-server-stderr-') ?: '';

        $descriptors = [
            0 => ['pipe', 'r'],  // stdin
            1 => ['file', self::$serverStdoutPath, 'a'],
            2 => ['file', self::$serverStderrPath, 'a'],
        ];

        $storageDriver = getenv('S3_TEST_SERVER_STORAGE_DRIVER') ?: 'filesystem';
        $storageArguments = match ($storageDriver) {
            'filesystem' => sprintf(
                '--storage-driver=filesystem --storage-path=%s',
                escapeshellarg(self::$storagePath),
            ),
            'flysystem' => sprintf(
                '--storage-driver=flysystem --storage-temp-dir=%s --flysystem-workers=%d',
                escapeshellarg(self::$storagePath . '/.tmp'),
                max(1, (int) (getenv('S3_FLYSYSTEM_WORKERS') ?: 4)),
            ),
            'memory' => '--storage-driver=memory',
            default => self::fail("Unsupported S3_TEST_SERVER_STORAGE_DRIVER: {$storageDriver}"),
        };

        // Use exec to replace the shell process so we can terminate the PHP process directly.
        $memoryLimit = getenv('S3_TEST_SERVER_MEMORY_LIMIT') ?: '-1';
        $cmd = sprintf(
            'exec php -d memory_limit=%s %s --host=%s --port=%d %s --access-key=%s --secret-key=%s --enforce-min-part-size=false',
            escapeshellarg($memoryLimit),
            escapeshellarg($binPath),
            escapeshellarg(self::$host),
            self::$port,
            $storageArguments,
            escapeshellarg(self::$accessKey),
            escapeshellarg(self::$secretKey),
        );

        $process = proc_open(
            $cmd,
            $descriptors,
            self::$serverPipes,
        );

        if (!is_resource($process)) {
            self::fail('Failed to start S3 server process.');
        }
        self::$serverProcess = $process;

        // Close stdin — the server doesn't need it.
        fclose(self::$serverPipes[0]);
        unset(self::$serverPipes[0]);

        unset(self::$serverPipes[1], self::$serverPipes[2]);
    }

    private static function waitForServer(): void
    {
        if (!is_resource(self::$serverProcess)) {
            self::fail('S3 server process is not running.');
        }
        $process = self::$serverProcess;
        $maxWaitMs = 10_000;
        $intervalMs = 50;
        $elapsed = 0;

        while ($elapsed < $maxWaitMs) {
            // Check if process is still running.
            $status = proc_get_status($process);
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
            if ($items === false) {
                throw new \RuntimeException("Unable to scan temporary test directory: {$path}");
            }

            foreach ($items as $item) {
                if ($item === '.' || $item === '..') {
                    continue;
                }

                self::recursiveDelete($path . '/' . $item);
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
