<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableBuffer;
use Amp\CancelledException;
use Amp\Future;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\ContextWorkerPool;
use Amp\Parallel\Worker\Execution;
use Amp\Parallel\Worker\Task;
use Amp\Parallel\Worker\WorkerPool;
use Amp\Sync\Channel;
use Amp\TimeoutCancellation;
use League\Uri\Http;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Middleware\ChecksumValidationMiddleware;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await;

final class RequestBodySpoolTest extends TestCase
{
    public function test_stale_sweep_cancellation_is_not_ignored_before_create(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $cancellation = new CancelledException();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturn(new Execution(
            $this->createMock(Task::class),
            $this->createMock(Channel::class),
            Future::error($cancellation),
        ));

        try {
            try {
                (new RequestBodySpool($pool, $tempDir))->create('s3-md5-');
                self::fail('Sweep cancellation must stop request processing.');
            } catch (CancelledException $error) {
                self::assertSame($cancellation, $error);
            }
        } finally {
            self::removeTempDir($tempDir);
        }
    }

    /** @param class-string<ContentMd5Middleware|ChecksumValidationMiddleware> $middlewareClass */
    #[\PHPUnit\Framework\Attributes\DataProvider('checksumMiddlewareClasses')]
    public function test_checksum_consumer_propagates_spool_delete_cancellation(string $middlewareClass): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $path = $tempDir . '/active.tmp';
        self::assertSame(0, file_put_contents($path, ''));
        self::assertTrue(chmod($path, 0o600));
        $cancellation = new CancelledException();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(4))->method('submit')->willReturnOnConsecutiveCalls(
            new Execution($this->createMock(Task::class), $this->createMock(Channel::class), Future::complete('0')),
            new Execution($this->createMock(Task::class), $this->createMock(Channel::class), Future::complete($path)),
            new Execution($this->createMock(Task::class), $this->createMock(Channel::class), Future::complete(null)),
            new Execution($this->createMock(Task::class), $this->createMock(Channel::class), Future::error($cancellation)),
        );
        $middleware = new $middlewareClass(new RequestBodySpool(
            $pool,
            $tempDir,
            coordinator: new PostObjectSpoolCoordinator($tempDir, runInline: true),
        ));
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204);
            }
        };

        try {
            try {
                $middleware->handleRequest($this->checksumRequest('abc'), $handler);
                self::fail('Spool delete cancellation must propagate through its consumer.');
            } catch (CancelledException $error) {
                self::assertSame($cancellation, $error);
            }
        } finally {
            @unlink($path);
            self::removeTempDir($tempDir);
        }
    }

    /** @return iterable<string, array{class-string<ContentMd5Middleware|ChecksumValidationMiddleware>}> */
    public static function checksumMiddlewareClasses(): iterable
    {
        yield 'Content-MD5' => [ContentMd5Middleware::class];
        yield 'SigV4 SHA-256' => [ChecksumValidationMiddleware::class];
    }

    /** @param array<string, string> $headers */
    #[\PHPUnit\Framework\Attributes\DataProvider('bodylessDigestCases')]
    public function test_bodyless_digest_validation_never_spools(
        string $body,
        array $headers,
        bool $accepted,
    ): void {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $spool = new RequestBodySpool($pool, $tempDir);
        $checksum = new ChecksumValidationMiddleware($spool);
        $handlerCalled = false;
        $terminal = new class (static function () use (&$handlerCalled): void {
            $handlerCalled = true;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };
        $inner = new class ($checksum, $terminal) implements RequestHandler {
            public function __construct(
                private readonly ChecksumValidationMiddleware $checksum,
                private readonly RequestHandler $terminal,
            ) {}

            public function handleRequest(Request $request): Response
            {
                return $this->checksum->handleRequest($request, $this->terminal);
            }
        };

        try {
            try {
                $response = (new ContentMd5Middleware($spool))->handleRequest(
                    $this->bodylessRequest($body, $headers),
                    $inner,
                );
                if (! $accepted) {
                    self::fail('Invalid bodyless digest input must be rejected.');
                }
                self::assertSame(204, $response->getStatus());
                self::assertTrue($handlerCalled);
            } catch (BadDigestException) {
                if ($accepted) {
                    self::fail('Valid bodyless digest input must be accepted.');
                }
                self::assertFalse($handlerCalled);
            }
        } finally {
            self::removeTempDir($tempDir);
        }
    }

    /** @return iterable<string, array{string, array<string, string>, bool}> */
    public static function bodylessDigestCases(): iterable
    {
        $emptyMd5 = base64_encode(md5('', binary: true));
        $emptySha256 = hash('sha256', '');

        yield 'malformed SHA-256' => ['', ['x-amz-content-sha256' => 'not-a-digest'], false];
        yield 'wrong SHA-256' => ['', ['x-amz-content-sha256' => hash('sha256', 'payload')], false];
        yield 'correct empty SHA-256' => ['', ['x-amz-content-sha256' => $emptySha256], true];
        yield 'body with empty SHA-256' => ['payload', ['x-amz-content-sha256' => $emptySha256], false];
        yield 'malformed Content-MD5' => ['', ['content-md5' => 'not-base64'], false];
        yield 'wrong Content-MD5' => ['', ['content-md5' => base64_encode(md5('payload', binary: true))], false];
        yield 'body with empty Content-MD5' => ['payload', ['content-md5' => $emptyMd5], false];
        yield 'both real empty digests' => ['', [
            'content-md5' => $emptyMd5,
            'x-amz-content-sha256' => $emptySha256,
        ], true];
        yield 'special SHA-256 remains skipped' => ['ignored', ['x-amz-content-sha256' => 'UNSIGNED-PAYLOAD'], true];
    }

    public function test_create_worker_cancellation_is_not_retried_or_converted(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $cancellation = new CancelledException();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(2))->method('submit')->willReturnOnConsecutiveCalls(
            new Execution(
                $this->createMock(Task::class),
                $this->createMock(Channel::class),
                Future::complete('0'),
            ),
            new Execution(
                $this->createMock(Task::class),
                $this->createMock(Channel::class),
                Future::error($cancellation),
            ),
        );

        try {
            try {
                (new RequestBodySpool($pool, $tempDir))->create('s3-md5-');
                self::fail('Cancellation must not be retried or converted.');
            } catch (CancelledException $error) {
                self::assertSame($cancellation, $error);
            }
        } finally {
            self::removeTempDir($tempDir);
        }
    }

    public function test_delete_worker_cancellation_is_not_retried_or_converted_and_releases_state(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $path = $tempDir . '/s3-md5-existing.tmp';
        self::assertSame(0, file_put_contents($path, ''));
        self::assertTrue(chmod($path, 0o600));
        $cancellation = new CancelledException();
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(3))->method('submit')->willReturnOnConsecutiveCalls(
            new Execution(
                $this->createMock(Task::class),
                $this->createMock(Channel::class),
                Future::complete('0'),
            ),
            new Execution(
                $this->createMock(Task::class),
                $this->createMock(Channel::class),
                Future::complete($path),
            ),
            new Execution(
                $this->createMock(Task::class),
                $this->createMock(Channel::class),
                Future::error($cancellation),
            ),
        );
        $spool = new RequestBodySpool(
            $pool,
            $tempDir,
            coordinator: new PostObjectSpoolCoordinator($tempDir, runInline: true),
        );
        self::assertSame($path, $spool->create('s3-md5-'));

        try {
            $spool->delete($path);
            self::fail('Cancellation must not be retried or converted.');
        } catch (CancelledException $error) {
            self::assertSame($cancellation, $error);
            $spool->assertIdle();
            self::assertFileExists($path);
        } finally {
            @unlink($path);
            self::removeTempDir($tempDir);
        }
    }

    public function test_md5_sha256_and_browser_post_share_one_budget_across_spool_instances(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(2);
        $createdPostPath = tempnam($tempDir, 's3-post-');
        self::assertIsString($createdPostPath);
        $postPath = $tempDir . '/' . basename($createdPostPath);
        $postCoordinator = new PostObjectSpoolCoordinator($tempDir);
        $postReservation = $postCoordinator->start($postPath);
        $first = null;
        $second = null;
        $firstPath = null;
        $secondPath = null;

        try {
            $postCoordinator->reserve($postReservation, 3, 72);
            $first = new RequestBodySpool($pool, $tempDir, 72);
            $second = new RequestBodySpool($pool, $tempDir, 72);
            $firstPath = $first->create('s3-md5-');
            $first->append($firstPath, 'abc');
            $secondPath = $second->create('s3-sha256-');
            $second->append($secondPath, 'xy');

            try {
                $second->append($secondPath, 'z');
                self::fail('All three live files must count toward the shared physical-byte budget.');
            } catch (SlowDownException) {
                self::assertSame(34, filesize($secondPath), 'Denied encrypted frame bytes must never be written.');
                self::assertSame('xy', \Amp\ByteStream\buffer($second->openReadable($secondPath)));
            }

            $first->delete($firstPath);
            $firstPath = null;
            $second->append($secondPath, 'z');
            self::assertSame('xyz', \Amp\ByteStream\buffer($second->openReadable($secondPath)));
        } finally {
            if ($first !== null && $firstPath !== null) {
                $first->delete($firstPath);
            }
            if ($second !== null && $secondPath !== null) {
                $second->delete($secondPath);
            }
            @unlink($postPath);
            $postCoordinator->release($postReservation, true);
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_double_checksum_middleware_rejects_before_second_spool_write_and_cleans_up(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(2);
        $spool = new RequestBodySpool($pool, $tempDir, 69);
        $checksum = new ChecksumValidationMiddleware($spool);
        $contentMd5 = new ContentMd5Middleware($spool);
        $handlerCalls = 0;
        $handler = new class (static function () use (&$handlerCalls): void {
            $handlerCalls++;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };
        $inner = new class ($checksum, $handler) implements RequestHandler {
            public function __construct(
                private readonly ChecksumValidationMiddleware $checksum,
                private readonly RequestHandler $handler,
            ) {}

            public function handleRequest(Request $request): Response
            {
                return $this->checksum->handleRequest($request, $this->handler);
            }
        };

        try {
            $request = $this->checksumRequest('abc');
            try {
                $contentMd5->handleRequest($request, $inner);
                self::fail('Two full encrypted spools must exceed the physical-byte budget.');
            } catch (SlowDownException $error) {
                self::assertSame(503, $error->getHttpStatus());
                self::assertSame(0, $handlerCalls);
                self::assertSame([], glob($tempDir . '/s3-md5-*'));
                self::assertSame([], glob($tempDir . '/s3-sha256-*'));
            }

            self::assertSame(204, $contentMd5->handleRequest($this->checksumRequest('ab'), $inner)->getStatus());
            self::assertSame(1, $handlerCalls);
            self::assertSame([], glob($tempDir . '/s3-md5-*'));
            self::assertSame([], glob($tempDir . '/s3-sha256-*'));
        } finally {
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_other_process_checksum_reservation_blocks_spool_until_crash_recovery(): void
    {
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $readyPath = $tempDir . '/worker-ready';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/post-spool-reservation-worker.php', $tempDir, $readyPath, 's3-md5-', '36'],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pool = new ContextWorkerPool(1);
        $path = null;
        $spool = null;

        try {
            $deadline = microtime(true) + 5;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyPath, 'The checksum reservation worker did not become ready.');

            $spool = new RequestBodySpool($pool, $tempDir, 36);
            $path = $spool->create('s3-sha256-');
            try {
                $spool->append($path, 'xy');
                self::fail('The other process must count toward the checksum budget.');
            } catch (SlowDownException) {
                self::assertSame('', file_get_contents($path));
            }

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $spool->append($path, 'xy');
            self::assertSame('xy', \Amp\ByteStream\buffer($spool->openReadable($path)));
            self::assertFileDoesNotExist($tempDir . '/s3-md5-worker.tmp');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            if ($spool !== null && $path !== null) {
                $spool->delete($path);
            }
            $pool->shutdown();
            @unlink($readyPath);
            self::removeTempDir($tempDir);
        }
    }

    public function test_created_body_file_is_private_even_with_permissive_umask(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o755, true));
        $oldUmask = umask(0);
        $pool = null;
        $path = null;

        try {
            $pool = new ContextWorkerPool(1);
            $spool = new RequestBodySpool($pool, $tempDir);
            $path = $spool->create('s3-md5-');
            clearstatcache(true, $path);
            self::assertSame(0o600, fileperms($path) & 0o777);
            $spool->append($path, 'sensitive body');
            self::assertStringNotContainsString('sensitive body', (string) file_get_contents($path));
            self::assertSame('sensitive body', \Amp\ByteStream\buffer($spool->openReadable($path)));
            $spool->delete($path);
            self::assertFileDoesNotExist($path);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
            $pool?->shutdown();
            umask($oldUmask);
            self::removeTempDir($tempDir);
        }
    }

    public function test_encrypted_body_spool_rejects_tampering_truncation_reordering_and_trailing_data(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-auth-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(1);
        $spool = new RequestBodySpool($pool, $tempDir);

        try {
            foreach (['tamper', 'truncate', 'reorder', 'trailing'] as $fault) {
                $path = $spool->create('s3-md5-');
                try {
                    $spool->append($path, 'alpha');
                    $spool->append($path, 'bravo');
                    $bytes = file_get_contents($path);
                    self::assertIsString($bytes);
                    self::assertSame(74, strlen($bytes));
                    self::assertStringNotContainsString('alpha', $bytes);
                    self::assertStringNotContainsString('bravo', $bytes);
                    $changed = match ($fault) {
                        'tamper' => substr_replace($bytes, $bytes[40] ^ "\x01", 40, 1),
                        'truncate' => substr($bytes, 0, -1),
                        'reorder' => substr($bytes, 37) . substr($bytes, 0, 37),
                        'trailing' => $bytes . 'x',
                    };
                    file_put_contents($path, $changed);
                    try {
                        \Amp\ByteStream\buffer($spool->openReadable($path));
                        self::fail("The {$fault} spool must not yield a complete plaintext body.");
                    } catch (InternalErrorException) {
                        self::assertFileExists($path);
                    }
                } finally {
                    $spool->delete($path);
                    self::assertFileDoesNotExist($path);
                }
            }
        } finally {
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_append_does_not_recreate_a_removed_body_file(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o755, true));
        $pool = new ContextWorkerPool(1);
        $spool = new RequestBodySpool($pool, $tempDir);
        $path = null;

        try {
            $path = $spool->create('s3-md5-');
            $spool->delete($path);

            try {
                $spool->append($path, 'sensitive body');
                self::fail('Appending to a deleted spool must fail.');
            } catch (\Throwable $error) {
                self::assertNotInstanceOf(\PHPUnit\Framework\AssertionFailedError::class, $error);
                self::assertFileDoesNotExist($path);
            }
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_world_writable_non_sticky_directory_is_rejected(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        self::assertTrue(chmod($tempDir, 0o777));
        $pool = new ContextWorkerPool(1);

        try {
            $this->expectException(\OpsFour\S3Server\Exception\InternalErrorException::class);
            new RequestBodySpool($pool, $tempDir);
        } finally {
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_sweep_preserves_old_active_file_and_reclaims_it_after_owner_crashes(): void
    {
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $oldPath = tempnam($tempDir, 's3-md5-crash-');
        self::assertIsString($oldPath);
        self::assertSame(6, file_put_contents($oldPath, 'secret'));
        $readyPath = $tempDir . '/worker-ready';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/body-spool-lock-worker.php', $oldPath, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pool = new ContextWorkerPool(1);

        try {
            $deadline = microtime(true) + 5;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyPath, 'The spool owner did not acquire its file lock.');
            self::assertTrue(touch($oldPath, time() - 90_000));

            $first = new RequestBodySpool($pool, $tempDir);
            $probe = $first->create('s3-md5-');
            $first->delete($probe);
            self::assertFileExists($oldPath, 'A live old spool must survive another process sweep.');

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $second = new RequestBodySpool($pool, $tempDir);
            $probe = $second->create('s3-md5-');
            $second->delete($probe);
            self::assertFileDoesNotExist($oldPath, 'A crashed owner must not retain stale data.');
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            $pool->shutdown();
            @unlink($oldPath);
            @unlink($readyPath);
            self::removeTempDir($tempDir);
        }
    }

    public function test_crashed_encrypted_spool_keeps_no_plaintext_and_is_reclaimed(): void
    {
        if (!function_exists('proc_open') || !function_exists('proc_terminate')) {
            self::markTestSkipped('Process control is unavailable.');
        }

        $tempDir = sys_get_temp_dir() . '/s3-spool-crash-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $readyPath = $tempDir . '/ready';
        $process = proc_open(
            [PHP_BINARY, dirname(__DIR__, 2) . '/Support/encrypted-request-spool-worker.php', $tempDir, $readyPath],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );
        self::assertIsResource($process);
        $pool = new ContextWorkerPool(1);
        $path = null;

        try {
            $deadline = microtime(true) + 5;
            while (!is_file($readyPath) && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFileExists($readyPath);
            $path = (string) file_get_contents($readyPath);
            self::assertFileExists($path);
            self::assertStringNotContainsString('crash-only-sensitive-payload', (string) file_get_contents($path));

            $activeProbe = new RequestBodySpool($pool, $tempDir);
            $probe = $activeProbe->create('s3-sha256-');
            $activeProbe->delete($probe);
            self::assertFileExists($path, 'A live encrypted spool must survive stale cleanup.');

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $recovery = new RequestBodySpool($pool, $tempDir);
            $probe = $recovery->create('s3-sha256-');
            $recovery->delete($probe);
            self::assertFileDoesNotExist($path);
        } finally {
            if (proc_get_status($process)['running']) {
                proc_terminate($process, 9);
            }
            foreach ($pipes as $pipe) {
                if (is_resource($pipe)) {
                    fclose($pipe);
                }
            }
            proc_close($process);
            $pool->shutdown();
            if ($path !== null) {
                @unlink($path);
            }
            @unlink($readyPath);
            self::removeTempDir($tempDir);
        }
    }

    public function test_second_spool_preserves_active_checksum_file_with_old_mtime(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(1);
        $path = null;

        try {
            $first = new RequestBodySpool($pool, $tempDir);
            $path = $first->create('s3-sha256-');
            $first->append($path, 'live body');
            self::assertTrue(touch($path, time() - 90_000));

            $second = new RequestBodySpool($pool, $tempDir);
            $probe = $second->create('s3-md5-');
            $second->delete($probe);
            self::assertFileExists($path);
            self::assertSame('live body', \Amp\ByteStream\buffer($first->openReadable($path)));
            $first->delete($path);
            self::assertFileDoesNotExist($path);
        } finally {
            if ($path !== null) {
                @unlink($path);
            }
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_first_create_removes_only_stale_owned_spool_files(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0o755, true);
        $stale = $tempDir . '/s3-md5-stale.tmp';
        $stalePost = $tempDir . '/s3-post-abandoned.tmp';
        $recent = $tempDir . '/s3-sha256-recent.tmp';
        $foreign = $tempDir . '/foreign-stale.tmp';
        file_put_contents($stale, 'stale');
        file_put_contents($stalePost, 'abandoned');
        file_put_contents($recent, 'recent');
        file_put_contents($foreign, 'foreign');
        touch($stale, time() - 90_000);
        touch($stalePost, time() - 90_000);
        touch($foreign, time() - 90_000);

        $pool = new ContextWorkerPool(2);
        $spool = new RequestBodySpool($pool, $tempDir);

        try {
            $created = $spool->create('s3-md5-');
            self::assertFileDoesNotExist($stale);
            self::assertFileDoesNotExist($stalePost);
            self::assertFileExists($recent);
            self::assertFileExists($foreign);
            $spool->delete($created);
        } finally {
            $pool->shutdown();
            @unlink($recent);
            @unlink($foreign);
            self::removeTempDir($tempDir);
        }
    }

    public function test_concurrency_above_worker_count_does_not_exhaust_pool(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        mkdir($tempDir, 0o755, true);
        $pool = new ContextWorkerPool(2);
        $spool = new RequestBodySpool($pool, $tempDir);

        try {
            $futures = [];
            for ($i = 0; $i < 20; $i++) {
                $futures[] = async(static function () use ($spool, $i): string {
                    $payload = str_repeat("payload-{$i}-", 10_000);
                    $path = $spool->create('s3-md5-');

                    try {
                        $spool->append($path, $payload);
                        $stream = $spool->openReadable($path);
                        $actual = '';
                        while (($chunk = $stream->read()) !== null) {
                            $actual .= $chunk;
                        }

                        return $actual;
                    } finally {
                        $spool->delete($path);
                    }
                });
            }

            $results = await($futures, new TimeoutCancellation(15));
            self::assertCount(20, $results);
            foreach ($results as $i => $result) {
                self::assertSame(str_repeat("payload-{$i}-", 10_000), $result);
            }
            self::assertSame([], glob($tempDir . '/s3-md5-*'));
        } finally {
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    private function checksumRequest(string $body): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            [],
            new ReadableBuffer($body),
        );
        $request->setHeader('Content-MD5', base64_encode(md5($body, true)));
        $request->setHeader('x-amz-content-sha256', hash('sha256', $body));

        return $request;
    }

    /** @param array<string, string> $headers */
    private function bodylessRequest(string $body, array $headers): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            [],
            new ReadableBuffer($body),
        );
        foreach ($headers as $name => $value) {
            if ($name === '') {
                throw new \LogicException('Test request header name must not be empty.');
            }
            $request->setHeader($name, $value);
        }
        $request->setAttribute('s3.operation', S3Operation::CopyObject);

        return $request;
    }

    private static function removeTempDir(string $tempDir): void
    {
        if (!is_dir($tempDir)) {
            return;
        }
        $files = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($tempDir, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($files as $file) {
            if ($file->isDir() && !$file->isLink()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }
        @rmdir($tempDir);
    }
}
