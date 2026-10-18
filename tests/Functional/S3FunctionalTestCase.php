<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\S3Client;
use OpsFour\S3Server\Metadata\SqliteMetadataPathResolver;
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

    protected static bool $lastServerAccessLogFlushFailed = false;

    protected static string $storagePath = '';

    private static bool $removeStoragePathAfterClass = true;

    protected static string $host = '127.0.0.1';

    protected static int $port = 0;

    protected static string $accessKey = 'testAccessKey';

    protected static string $secretKey = 'testSecretKey';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        self::$removeStoragePathAfterClass = true;
        $configuredStoragePath = getenv('S3_TEST_STORAGE_PATH');
        if ($configuredStoragePath === false || trim($configuredStoragePath) === '') {
            self::$storagePath = sys_get_temp_dir() . '/s3-test-' . uniqid();
            mkdir(self::$storagePath, 0o755, true);
        } else {
            self::$storagePath = self::validateConfiguredStoragePath(trim($configuredStoragePath));
            self::$removeStoragePathAfterClass = false;
        }

        // Find a free port.
        self::$port = self::findFreePort();

        // Start the S3 server in the background.
        self::startServer();

        // Wait for the server to be ready.
        self::waitForServer();

        // Create the AWS SDK client.
        self::$s3 = self::makeClient();
    }

    protected static function makeClient(
        ?int $timeout = null,
        string $requestChecksumCalculation = 'when_supported',
    ): S3Client {
        return new S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'request_checksum_calculation' => $requestChecksumCalculation,
            // A local functional failure must expose the first transport or
            // protocol error; SDK retries previously hid malformed responses
            // until a repeated successful DeleteBucket returned 404.
            'retries' => 0,
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

    protected static function restartServer(?int $clientTimeout = null, int $shutdownGraceSeconds = 35): void
    {
        self::stopServer($shutdownGraceSeconds, requireGraceful: true);
        self::startServer();
        self::waitForServer();
        self::$s3 = self::makeClient($clientTimeout);
    }

    protected static function sqliteMetadataPath(): string
    {
        $configuredPath = getenv('S3_METADATA_PATH');
        $storageDriver = getenv('S3_TEST_SERVER_STORAGE_DRIVER') ?: 'filesystem';
        $storagePath = $storageDriver === 'flysystem'
            ? self::$storagePath . '/.tmp'
            : self::$storagePath;

        return SqliteMetadataPathResolver::resolve(
            $storagePath,
            $configuredPath === false ? null : trim($configuredPath),
        );
    }

    public static function tearDownAfterClass(): void
    {
        self::stopServer();

        // Clean up the storage directory.
        if (self::$removeStoragePathAfterClass && self::$storagePath !== '' && is_dir(self::$storagePath)) {
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

    private static function stopServer(int $shutdownGraceSeconds = 35, bool $requireGraceful = false): void
    {
        $forcedKill = false;
        $exitCode = null;
        $shutdownDiagnostics = '';
        $processDiagnostics = '';
        self::$lastServerAccessLogFlushFailed = false;
        if (self::$serverProcess !== null && is_resource(self::$serverProcess)) {
            // Close pipes first.
            foreach (self::$serverPipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            self::$serverPipes = [];

            // The command uses exec, so this PID belongs to the PHP server.
            $status = proc_get_status(self::$serverProcess);
            if ($status['running']) {
                $pid = (int) $status['pid'];
                $termAccepted = proc_terminate(self::$serverProcess, SIGTERM);
                // Server shutdown drains background workers and flushes access
                // logs. A fixed 500 ms delay could kill it before the flush.
                $deadline = microtime(true) + $shutdownGraceSeconds;
                do {
                    usleep(50_000);
                    $status = proc_get_status(self::$serverProcess);
                } while ($status['running'] && microtime(true) < $deadline);
                if ($status['running']) {
                    $forcedKill = true;
                    if ($requireGraceful) {
                        $processDiagnostics = self::shutdownProcessDiagnostics($pid, $termAccepted);
                    }
                    proc_terminate(self::$serverProcess, SIGKILL);
                } else {
                    $exitCode = $status['exitcode'];
                }
            }
            proc_close(self::$serverProcess);
            self::$serverProcess = null;
        }

        if ($requireGraceful) {
            $shutdownOutput = self::serverLogContents(self::$serverStdoutPath)
                . self::serverLogContents(self::$serverStderrPath);
            self::$lastServerAccessLogFlushFailed = stripos($shutdownOutput, 'Failed to flush access logs') !== false
                || stripos($shutdownOutput, 'Access log flush failed during shutdown') !== false;
            $shutdownDiagnostics = self::shutdownDiagnostics($shutdownOutput);
        }

        if (self::$serverStdoutPath !== '') {
            @unlink(self::$serverStdoutPath);
            self::$serverStdoutPath = '';
        }
        if (self::$serverStderrPath !== '') {
            @unlink(self::$serverStderrPath);
            self::$serverStderrPath = '';
        }
        if ($forcedKill && $requireGraceful) {
            self::fail(sprintf(
                'S3 server did not finish graceful shutdown within %d seconds; access-log flush cannot be assumed complete. %s %s',
                $shutdownGraceSeconds,
                $shutdownDiagnostics,
                $processDiagnostics,
            ));
        }
        if ($requireGraceful && $exitCode !== null && $exitCode !== 0) {
            self::fail(sprintf('S3 server exited with code %d during graceful restart. %s', $exitCode, $shutdownDiagnostics));
        }
    }

    private static function shutdownDiagnostics(string $output): string
    {
        // Only fixed markers are reported. Server logs can contain request
        // details, storage paths, or exception messages with secrets.
        $markers = [
            'signal_received' => 'Received signal ',
            'server_stop_started' => 'Stopping S3 server (drain timeout:',
            'http_drain_timeout' => 'Shutdown drain timeout exceeded (',
            'background_timeout' => 'Background task did not stop within ',
            'access_log_flush_failed' => 'Access log flush failed during shutdown.',
            'server_stop_finished' => 'S3 server stopped.',
            'command_finished' => 'Server stopped gracefully.',
        ];
        $seen = [];
        foreach ($markers as $name => $message) {
            if (str_contains($output, $message)) {
                $seen[] = $name;
            }
        }

        return 'Shutdown stages seen: ' . ($seen === [] ? 'none' : implode(', ', $seen)) . '.';
    }

    private static function shutdownProcessDiagnostics(int $pid, bool $termAccepted): string
    {
        $details = [
            'pid=' . $pid,
            'sigterm_accepted=' . ($termAccepted ? 'yes' : 'no'),
        ];

        $status = @file_get_contents("/proc/{$pid}/status", false, null, 0, 16_384);
        if (is_string($status)) {
            foreach (['State' => '[A-Z]', 'SigBlk' => '[0-9a-fA-F]{1,32}',
                'SigIgn' => '[0-9a-fA-F]{1,32}', 'SigCgt' => '[0-9a-fA-F]{1,32}'] as $name => $pattern) {
                if (preg_match('/^' . $name . ':\\s*(' . $pattern . ')/m', $status, $matches) === 1) {
                    $details[] = strtolower($name) . '=' . $matches[1];
                }
            }
        } else {
            $details[] = 'proc_status=unavailable';
        }

        $wchan = @file_get_contents("/proc/{$pid}/wchan", false, null, 0, 128);
        if (is_string($wchan) && preg_match('/\A([a-zA-Z0-9_]{1,127})\s*\z/', $wchan, $matches) === 1) {
            $details[] = 'wchan=' . $matches[1];
        } else {
            $details[] = 'wchan=unavailable';
        }

        $details[] = 'live_probe=' . self::shutdownLiveProbe();

        return 'Process state before SIGKILL: ' . implode(', ', $details) . '.';
    }

    private static function shutdownLiveProbe(): string
    {
        $socket = @stream_socket_client(
            sprintf('tcp://%s:%d', self::$host, self::$port),
            $errno,
            $error,
            0.5,
        );
        if (!is_resource($socket)) {
            return 'connect_failed';
        }

        try {
            stream_set_timeout($socket, 1);
            $request = sprintf("GET /.live HTTP/1.1\r\nHost: %s:%d\r\nConnection: close\r\n\r\n", self::$host, self::$port);
            if (@fwrite($socket, $request) !== strlen($request)) {
                return 'write_failed';
            }
            $statusLine = @fgets($socket, 128);
            if (is_string($statusLine) && preg_match('/\AHTTP\/1\.[01] ([0-9]{3}) /', $statusLine, $matches) === 1) {
                return 'http_' . $matches[1];
            }

            return stream_get_meta_data($socket)['timed_out'] ? 'response_timeout' : 'no_http_status';
        } finally {
            fclose($socket);
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
                '--storage-driver=flysystem --allow-unfenced-storage-for-tests=true --storage-temp-dir=%s --flysystem-workers=%d',
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
        $maxWaitMs = self::serverStartTimeoutMs();
        $startedAt = microtime(true);
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
                if (getenv('S3_TEST_REPORT_STARTUP_TIME') === '1') {
                    fwrite(STDERR, sprintf("First S3 HTTP node ready in %.3f seconds.\n", microtime(true) - $startedAt));
                }
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

    protected static function serverStartTimeoutMs(): int
    {
        $configured = getenv('S3_TEST_SERVER_START_TIMEOUT_MS');
        if ($configured === false || $configured === '') {
            return 10_000;
        }

        $parsed = filter_var($configured, FILTER_VALIDATE_INT, [
            'options' => ['min_range' => 1_000, 'max_range' => 120_000],
        ]);
        if ($parsed === false) {
            throw new \InvalidArgumentException('S3_TEST_SERVER_START_TIMEOUT_MS must be between 1000 and 120000.');
        }

        return $parsed;
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

    private static function validateConfiguredStoragePath(string $path): string
    {
        if (! str_starts_with($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('S3_TEST_STORAGE_PATH must be an absolute path.');
        }
        if (is_link($path) || ! is_dir($path) || ! is_writable($path)) {
            throw new \InvalidArgumentException('S3_TEST_STORAGE_PATH must be a writable, existing directory and not a symbolic link.');
        }

        $resolved = realpath($path);
        if ($resolved === false || $resolved === DIRECTORY_SEPARATOR) {
            throw new \InvalidArgumentException('S3_TEST_STORAGE_PATH must resolve to a dedicated directory below the filesystem root.');
        }
        if ($resolved !== rtrim($path, DIRECTORY_SEPARATOR)) {
            throw new \InvalidArgumentException('S3_TEST_STORAGE_PATH must not traverse symbolic links or parent-directory segments.');
        }

        $entries = scandir($resolved);
        if ($entries === false) {
            throw new \RuntimeException('Unable to inspect S3_TEST_STORAGE_PATH.');
        }
        if (array_values(array_diff($entries, ['.', '..'])) !== []) {
            throw new \InvalidArgumentException('S3_TEST_STORAGE_PATH must be empty before the test starts.');
        }

        return $resolved;
    }

    protected static function serverLogs(): string
    {
        return sprintf(
            "stdout:\n%s\nstderr:\n%s",
            self::serverLogContents(self::$serverStdoutPath),
            self::serverLogContents(self::$serverStderrPath),
        );
    }

    protected static function deleteBucketWithDiagnostics(string $bucket): void
    {
        try {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        } catch (\Throwable $error) {
            $lines = array_values(array_filter(
                preg_split('/\R/', self::serverLogs()) ?: [],
                static fn(string $line): bool => str_contains($line, $bucket),
            ));
            throw new \RuntimeException(
                'DeleteBucket cleanup failed for ' . $bucket . ". Matching server log lines:\n"
                . implode("\n", array_slice($lines, -20)),
                previous: $error,
            );
        }
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
