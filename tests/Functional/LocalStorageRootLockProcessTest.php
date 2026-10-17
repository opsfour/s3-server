<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Auth\AuthMiddleware;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Runtime\S3ServerRuntime;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\FilesystemBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;

final class LocalStorageRootLockProcessTest extends TestCase
{
    public function test_second_process_fails_before_binding_its_listener_and_root_is_reusable_after_shutdown(): void
    {
        if (! function_exists('proc_open')) {
            self::markTestSkipped('proc_open is unavailable.');
        }

        $root = sys_get_temp_dir() . '/s3-root-lock-process-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o700));
        $firstPort = self::unusedPort();
        $secondPort = self::unusedPort();
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__) . '/Support/local-storage-root-lock-server.php', $root, (string) $firstPort],
            [
                0 => ['pipe', 'r'],
                1 => ['pipe', 'w'],
                2 => ['pipe', 'w'],
            ],
            $pipes,
            dirname(__DIR__, 2),
        );
        self::assertIsResource($process);
        stream_set_blocking($pipes[1], false);

        $loser = null;
        try {
            $line = self::awaitLine($process, $pipes[1], $pipes[2]);
            self::assertSame('READY', $line);

            $loser = self::server($root, $secondPort);
            try {
                $loser->start();
                self::fail('A second process started with an already owned local storage root.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'A configured local storage root is already owned by another S3 server process.',
                    $error->getMessage(),
                );
                self::assertNull((new \ReflectionProperty($loser, 'server'))->getValue($loser));
            }

            fwrite($pipes[0], "STOP\n");
            fclose($pipes[0]);
            $errorOutput = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            self::assertSame(0, proc_close($process), (string) $errorOutput);
            $process = null;

            $replacement = self::server($root, $secondPort);
            try {
                $replacement->start();
            } finally {
                $replacement->stop();
            }
        } finally {
            if (is_resource($process)) {
                @fwrite($pipes[0], "STOP\n");
                @fclose($pipes[0]);
                proc_terminate($process);
                proc_close($process);
            }
            if ($loser !== null) {
                try {
                    $loser->stop();
                } catch (\Throwable) {
                }
            }
            self::removeTree($root);
        }
    }

    public function test_runtime_keeps_root_owned_until_post_server_dependencies_finish(): void
    {
        $root = sys_get_temp_dir() . '/s3-runtime-root-lock-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($root, 0o700));
        $encryption = new FailingShutdownEncryptionService();
        $runtime = self::runtime($root, self::unusedPort(), $encryption);

        try {
            $runtime->server->start();
            try {
                $runtime->stop();
                self::fail('The injected post-server shutdown failure was not reported.');
            } catch (\RuntimeException $error) {
                self::assertSame('S3 runtime shutdown incomplete; dependencies remain owned.', $error->getMessage());
            }

            $contender = self::runtime($root, self::unusedPort());
            try {
                $contender->server->start();
                self::fail('A runtime acquired the root while an encryption dependency had not stopped.');
            } catch (\RuntimeException $error) {
                self::assertSame(
                    'A configured local storage root is already owned by another S3 server process.',
                    $error->getMessage(),
                );
            } finally {
                $contender->stop();
            }

            $encryption->failShutdown = false;
            $runtime->stop();

            $replacement = self::runtime($root, self::unusedPort());
            try {
                $replacement->server->start();
            } finally {
                $replacement->stop();
            }
        } finally {
            try {
                $runtime->stop();
            } catch (\Throwable) {
            }
            self::removeTree($root);
        }
    }

    public function test_direct_server_locks_server_tier_and_handler_roots(): void
    {
        $base = sys_get_temp_dir() . '/s3-direct-root-inventory-' . bin2hex(random_bytes(8));
        $roots = [$base . '/server', $base . '/tier', $base . '/handler'];
        foreach ($roots as $root) {
            self::assertTrue(mkdir($root, 0o700, true));
        }

        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $serverStorage = new FilesystemBackend($roots[0]);
        $tierStorage = new FilesystemBackend($roots[1]);
        $handlerStorage = new FilesystemBackend($roots[2]);
        $server = new S3Server(
            new S3ServerConfig(
                host: '127.0.0.1',
                port: self::unusedPort(),
                storagePath: $roots[0],
                notificationWorkerEnabled: false,
            ),
            $metadata,
            $serverStorage,
        );
        $server->setStorageTierRegistry(StorageTierRegistry::single($tierStorage));
        $server->getHandlerRegistry()->registerStorage($handlerStorage, StorageTierRegistry::single($handlerStorage));
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        try {
            $server->start();
            foreach ($roots as $root) {
                $contender = self::server($root, self::unusedPort());
                try {
                    $contender->start();
                    self::fail("Configured local root was not locked: {$root}");
                } catch (\RuntimeException $error) {
                    self::assertSame(
                        'A configured local storage root is already owned by another S3 server process.',
                        $error->getMessage(),
                    );
                } finally {
                    $contender->stop();
                }
            }
        } finally {
            $server->stop();
            self::removeTree($base);
        }
    }

    private static function server(string $root, int $port): S3Server
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $server = new S3Server(
            new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: $root,
                notificationWorkerEnabled: false,
            ),
            $metadata,
            new FilesystemBackend($root),
        );
        $server->addMiddleware(new AuthMiddleware(
            new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            'us-east-1',
        ));

        return $server;
    }

    private static function runtime(
        string $root,
        int $port,
        ?EncryptionServiceInterface $encryption = null,
    ): S3ServerRuntime {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();

        return (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(
                host: '127.0.0.1',
                port: $port,
                storagePath: $root,
                notificationWorkerEnabled: false,
            ),
            metadata: $metadata,
            storage: new FilesystemBackend($root),
            credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            logger: new \Psr\Log\NullLogger(),
            metrics: new MetricsCollector(),
            encryption: $encryption,
        );
    }

    /** @param resource $process */
    private static function awaitLine($process, mixed $stdout, mixed $stderr): string
    {
        if (! is_resource($stdout) || ! is_resource($stderr)) {
            throw new \LogicException('Lock holder pipes are unavailable.');
        }
        $deadline = microtime(true) + 10;
        $buffer = '';
        do {
            $chunk = stream_get_contents($stdout);
            if ($chunk !== false) {
                $buffer .= $chunk;
            }
            if (str_contains($buffer, "\n")) {
                return trim(strtok($buffer, "\n"));
            }
            $status = proc_get_status($process);
            if (! $status['running']) {
                self::fail('Lock holder exited before readiness: ' . stream_get_contents($stderr));
            }
            usleep(10_000);
        } while (microtime(true) < $deadline);

        self::fail('Timed out waiting for lock holder readiness: ' . stream_get_contents($stderr));
    }

    private static function unusedPort(): int
    {
        $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
        self::assertIsResource($socket, "Unable to reserve port: {$errno} {$error}");
        $address = stream_socket_get_name($socket, false);
        fclose($socket);
        self::assertIsString($address);

        $separator = strrpos($address, ':');
        if ($separator === false) {
            self::fail("Unexpected server address: {$address}");
        }

        return (int) substr($address, $separator + 1);
    }

    private static function removeTree(string $root): void
    {
        if (! is_dir($root)) {
            return;
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            if ($entry->isDir() && ! $entry->isLink()) {
                @rmdir($entry->getPathname());
            } else {
                @unlink($entry->getPathname());
            }
        }
        @rmdir($root);
    }
}

final class FailingShutdownEncryptionService implements EncryptionServiceInterface
{
    public bool $failShutdown = true;

    public function encryptSseS3(string $plaintext): array
    {
        return ['ciphertext' => $plaintext, 'encryptedDataKey' => '', 'iv' => '', 'tag' => ''];
    }

    public function decryptSseS3(string $ciphertext, string $encryptedDataKeyB64, string $ivB64, string $tagB64): string
    {
        return $ciphertext;
    }

    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        return ['ciphertext' => $plaintext, 'iv' => '', 'tag' => ''];
    }

    public function decryptSseC(string $ciphertext, string $customerKey, string $ivB64, string $tagB64): string
    {
        return $ciphertext;
    }

    public function shutdown(): void
    {
        if ($this->failShutdown) {
            throw new \RuntimeException('injected encryption shutdown failure');
        }
    }
}
