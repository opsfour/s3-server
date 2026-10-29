<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\ByteStream\ReadableStream;
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
use OpsFour\S3Server\Auth\ChunkedSignatureVerifier;
use OpsFour\S3Server\Auth\PostObjectSpoolCoordinator;
use OpsFour\S3Server\Auth\UnsignedChunkedTrailerDecoder;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\SignatureDoesNotMatchException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Middleware\ChecksumValidationMiddleware;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\Future\await;

final class RequestBodySpoolTest extends TestCase
{
    public function test_append_rejects_a_replacement_inode_without_mutating_it(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-append-identity-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(1);
        $coordinator = new PostObjectSpoolCoordinator($tempDir, runInline: true, budgetBytes: 100_000, filesystemReserveBytes: 0);
        $spool = new RequestBodySpool($pool, $tempDir, 100_000, $coordinator);
        $path = $spool->create('s3-md5-', 65_536);

        try {
            self::assertTrue(unlink($path));
            self::assertSame(11, file_put_contents($path, 'replacement'));
            self::assertTrue(chmod($path, 0o600));

            try {
                $spool->append($path, str_repeat('x', 65_536));
                self::fail('A replacement checksum spool must not receive append data.');
            } catch (\Throwable $error) {
                self::assertStringContainsString('identity changed', $error->getMessage());
                self::assertSame('replacement', file_get_contents($path));
            }
        } finally {
            $spool->delete($path);
            $spool->assertIdle();
            @unlink($path);
            $coordinator->recoverAbandoned();
            $coordinator->close();
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_read_rejects_a_replacement_inode_without_reading_it(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-read-identity-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(1);
        $coordinator = new PostObjectSpoolCoordinator($tempDir, runInline: true, budgetBytes: 100_000, filesystemReserveBytes: 0);
        $spool = new RequestBodySpool($pool, $tempDir, 100_000, $coordinator);
        $path = $spool->create('s3-md5-', 4);

        try {
            $spool->append($path, 'body');
            $readable = $spool->openReadable($path);
            self::assertTrue(unlink($path));
            self::assertSame(11, file_put_contents($path, 'replacement'));
            self::assertTrue(chmod($path, 0o600));

            try {
                \Amp\ByteStream\buffer($readable);
                self::fail('A replacement checksum spool must not be read.');
            } catch (\Throwable $error) {
                self::assertStringContainsString('identity changed', $error->getMessage());
                self::assertSame('replacement', file_get_contents($path));
            }
        } finally {
            $spool->delete($path);
            $spool->assertIdle();
            @unlink($path);
            $coordinator->recoverAbandoned();
            $coordinator->close();
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    public function test_cleanup_never_deletes_a_replacement_inode(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-replacement-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = new ContextWorkerPool(1);
        $coordinator = new PostObjectSpoolCoordinator(
            $tempDir,
            runInline: true,
            budgetBytes: 1024,
            filesystemReserveBytes: 0,
        );
        $spool = new RequestBodySpool($pool, $tempDir, 1024, $coordinator);
        $path = $spool->create('s3-md5-');

        try {
            self::assertTrue(unlink($path));
            self::assertSame(11, file_put_contents($path, 'replacement'));
            $spool->delete($path);
            self::assertSame('replacement', file_get_contents($path));
        } finally {
            @unlink($path);
            $coordinator->recoverAbandoned();
            $coordinator->close();
            $pool->shutdown();
            self::removeTempDir($tempDir);
        }
    }

    private const string EMPTY_SHA256 = 'e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855';

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
    public function test_checksum_consumer_cleanup_does_not_delegate_identity_deletion_to_file_worker(string $middlewareClass): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::exactly(2))->method('submit')->willReturnOnConsecutiveCalls(
            new Execution($this->createMock(Task::class), $this->createMock(Channel::class), Future::complete('0')),
            new Execution($this->createMock(Task::class), $this->createMock(Channel::class), Future::complete(null)),
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
            self::assertSame(204, $middleware->handleRequest($this->checksumRequest('abc'), $handler)->getStatus());
        } finally {
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
        yield 'empty unsigned payload' => ['', ['x-amz-content-sha256' => 'UNSIGNED-PAYLOAD'], true];
        yield 'non-empty unsigned payload' => ['payload', ['x-amz-content-sha256' => 'UNSIGNED-PAYLOAD'], false];
        yield 'empty payload without a hash' => ['', [], true];
        yield 'non-empty payload without a hash' => ['payload', [], false];
        yield 'empty aws-chunked payload' => ['', [
            'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
            'x-amz-decoded-content-length' => '0',
        ], true];
        yield 'non-zero aws-chunked decoded length' => ['', [
            'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
            'x-amz-decoded-content-length' => '1',
        ], false];
    }

    public function test_bodyless_real_aws_chunked_decoders_dispatch_only_valid_empty_payloads(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $middleware = new ChecksumValidationMiddleware(new RequestBodySpool($pool, $tempDir));
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
        $key = random_bytes(32);
        $timestamp = '20261026T120000Z';
        $scope = '20261026/us-east-1/s3/aws4_request';
        $seed = str_repeat('a', 64);
        $checksum = base64_encode(hash('sha256', '', true));
        $signedVerifier = new ChunkedSignatureVerifier();

        $cases = [
            'signed' => [
                'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
                $signedVerifier->createVerifiedStream(
                    new ReadableBuffer($this->signedEmptyBody($key, $timestamp, $scope, $seed)),
                    $key,
                    $timestamp,
                    $scope,
                    $seed,
                ),
            ],
            'signed trailer' => [
                'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
                $signedVerifier->createVerifiedStream(
                    new ReadableBuffer($this->signedEmptyTrailerBody($key, $timestamp, $scope, $seed, $checksum)),
                    $key,
                    $timestamp,
                    $scope,
                    $seed,
                    ['x-amz-checksum-sha256'],
                ),
            ],
            'unsigned trailer' => [
                'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
                (new UnsignedChunkedTrailerDecoder())->createDecodedStream(
                    new ReadableBuffer("0\r\nx-amz-checksum-sha256:{$checksum}\r\n\r\n"),
                    ['x-amz-checksum-sha256'],
                ),
            ],
        ];

        try {
            foreach ($cases as $label => [$marker, $body]) {
                $request = $this->bodylessRequest($body, [
                    'x-amz-content-sha256' => $marker,
                    'x-amz-decoded-content-length' => '0',
                ]);
                self::assertSame(
                    204,
                    $middleware->handleRequest($request, $handler)->getStatus(),
                    "The valid empty {$label} body must dispatch.",
                );
            }
            self::assertSame(3, $handlerCalls);
        } finally {
            self::removeTempDir($tempDir);
        }
    }

    public function test_bodyless_real_aws_chunked_decoder_failures_never_dispatch(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $middleware = new ChecksumValidationMiddleware(new RequestBodySpool($pool, $tempDir));
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
        $key = random_bytes(32);
        $timestamp = '20261026T120000Z';
        $scope = '20261026/us-east-1/s3/aws4_request';
        $seed = str_repeat('b', 64);
        $badChecksum = base64_encode(hash('sha256', 'not-empty', true));
        $signedVerifier = new ChunkedSignatureVerifier();
        $failures = [
            'signature' => [
                'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
                $signedVerifier->createVerifiedStream(
                    new ReadableBuffer('0;chunk-signature=' . str_repeat('0', 64) . "\r\n\r\n"),
                    $key,
                    $timestamp,
                    $scope,
                    $seed,
                ),
                SignatureDoesNotMatchException::class,
            ],
            'terminal chunk' => [
                'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
                $signedVerifier->createVerifiedStream(
                    new ReadableBuffer(''),
                    $key,
                    $timestamp,
                    $scope,
                    $seed,
                ),
                InternalErrorException::class,
            ],
            'signed trailer checksum' => [
                'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
                $signedVerifier->createVerifiedStream(
                    new ReadableBuffer($this->signedEmptyTrailerBody($key, $timestamp, $scope, $seed, $badChecksum)),
                    $key,
                    $timestamp,
                    $scope,
                    $seed,
                    ['x-amz-checksum-sha256'],
                ),
                BadDigestException::class,
            ],
            'unsigned trailer checksum' => [
                'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
                (new UnsignedChunkedTrailerDecoder())->createDecodedStream(
                    new ReadableBuffer("0\r\nx-amz-checksum-sha256:{$badChecksum}\r\n\r\n"),
                    ['x-amz-checksum-sha256'],
                ),
                BadDigestException::class,
            ],
        ];

        try {
            foreach ($failures as $label => [$marker, $body, $expectedException]) {
                $request = $this->bodylessRequest($body, [
                    'x-amz-content-sha256' => $marker,
                    'x-amz-decoded-content-length' => '0',
                ]);
                try {
                    $middleware->handleRequest($request, $handler);
                    self::fail("The {$label} failure must prevent dispatch.");
                } catch (\Throwable $error) {
                    self::assertInstanceOf($expectedException, $error);
                    self::assertSame(0, $handlerCalls);
                }
            }
        } finally {
            self::removeTempDir($tempDir);
        }
    }

    public function test_bodyless_payload_rejection_closes_streams_promptly(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $middleware = new ChecksumValidationMiddleware(new RequestBodySpool($pool, $tempDir));
        $handler = $this->createMock(RequestHandler::class);
        $handler->expects(self::never())->method('handleRequest');

        try {
            $nonzeroCloseCalls = 0;
            $nonzero = $this->createMock(ReadableStream::class);
            $nonzero->expects(self::never())->method('read');
            $nonzero->expects(self::atLeastOnce())->method('close')->willReturnCallback(
                static function () use (&$nonzeroCloseCalls): void {
                    $nonzeroCloseCalls++;
                },
            );
            try {
                $middleware->handleRequest($this->bodylessRequest($nonzero, [
                    'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
                    'x-amz-decoded-content-length' => '1',
                ]), $handler);
                self::fail('A non-zero decoded length must be rejected.');
            } catch (BadDigestException) {
                self::assertGreaterThanOrEqual(1, $nonzeroCloseCalls);
            }

            $nonemptyCloseCalls = 0;
            $nonempty = $this->createMock(ReadableStream::class);
            $nonempty->expects(self::once())->method('read')->willReturn('payload');
            $nonempty->expects(self::atLeastOnce())->method('close')->willReturnCallback(
                static function () use (&$nonemptyCloseCalls): void {
                    $nonemptyCloseCalls++;
                },
            );
            try {
                $middleware->handleRequest($this->bodylessRequest($nonempty, [
                    'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
                ]), $handler);
                self::fail('A non-empty decoded body must be rejected.');
            } catch (BadDigestException) {
                self::assertGreaterThanOrEqual(1, $nonemptyCloseCalls);
            }
        } finally {
            self::removeTempDir($tempDir);
        }
    }

    public function test_bodyless_digest_validation_accepts_empty_stream_chunks_before_eof(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::never())->method('submit');
        $spool = new RequestBodySpool($pool, $tempDir);
        $checksum = new ChecksumValidationMiddleware($spool);
        $terminal = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
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
        $request = $this->bodylessRequest(new ReadableIterableStream(['', '']), [
            'content-md5' => base64_encode(md5('', binary: true)),
            'x-amz-content-sha256' => hash('sha256', ''),
        ]);

        try {
            self::assertSame(204, (new ContentMd5Middleware($spool))->handleRequest($request, $inner)->getStatus());
        } finally {
            self::removeTempDir($tempDir);
        }
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

    public function test_delete_uses_coordinator_identity_cleanup_instead_of_file_worker(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-spool-test-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($tempDir, 0o700, true));
        $pool = $this->createMock(WorkerPool::class);
        $pool->expects(self::once())->method('submit')->willReturn(
            new Execution(
                $this->createMock(Task::class),
                $this->createMock(Channel::class),
                Future::complete('0'),
            ),
        );
        $spool = new RequestBodySpool(
            $pool,
            $tempDir,
            coordinator: new PostObjectSpoolCoordinator($tempDir, runInline: true),
        );
        $path = $spool->create('s3-md5-');

        try {
            $spool->delete($path);
            $spool->assertIdle();
            self::assertFileDoesNotExist($path);
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
        $postPath = $tempDir . '/s3-post-' . bin2hex(random_bytes(16)) . '.tmp';
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
                self::assertSame(0, filesize($secondPath), 'Denied encrypted frame bytes must never be written.');
                self::assertSame('xy', \Amp\ByteStream\buffer($second->openReadable($secondPath)));
                clearstatcache(true, $secondPath);
                self::assertSame(34, filesize($secondPath));
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
                $spool->assertIdle();
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
        $readyPath = $tempDir . '-worker-ready';
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
                    $spool->openReadable($path)->close();
                    $bytes = file_get_contents($path);
                    self::assertIsString($bytes);
                    self::assertSame(42, strlen($bytes));
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

    public function test_sweep_preserves_unaccounted_old_file_even_after_its_lock_owner_crashes(): void
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
            try {
                $first->create('s3-md5-');
                self::fail('An unaccounted live spool file did not close admission.');
            } catch (SlowDownException) {
            }
            self::assertFileExists($oldPath, 'A live old spool must survive another process sweep.');

            proc_terminate($process, 9);
            $deadline = microtime(true) + 5;
            while (proc_get_status($process)['running'] && microtime(true) < $deadline) {
                usleep(10_000);
            }
            self::assertFalse(proc_get_status($process)['running']);

            $second = new RequestBodySpool($pool, $tempDir);
            try {
                $second->create('s3-md5-');
                self::fail('An unaccounted crashed spool file did not close admission.');
            } catch (SlowDownException) {
            }
            self::assertFileExists(
                $oldPath,
                'An unaccounted file must not be deleted from age and lock evidence alone.',
            );
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
        $readyPath = $tempDir . '-ready';
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

    public function test_first_create_preserves_unaccounted_spool_files_and_closes_admission(): void
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
            try {
                $spool->create('s3-md5-');
                self::fail('Unaccounted managed spool files did not close admission.');
            } catch (SlowDownException) {
            }
            self::assertFileExists($stale, 'Age alone is not recovery identity.');
            self::assertFileExists($stalePost, 'Unknown browser spool files must not be deleted.');
            self::assertFileExists($recent);
            self::assertFileExists($foreign);
        } finally {
            $pool->shutdown();
            @unlink($stale);
            @unlink($stalePost);
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

    private function signedEmptyBody(string $key, string $timestamp, string $scope, string $seed): string
    {
        $terminalSignature = self::chunkSignature('', $key, $timestamp, $scope, $seed);

        return "0;chunk-signature={$terminalSignature}\r\n\r\n";
    }

    private function signedEmptyTrailerBody(
        string $key,
        string $timestamp,
        string $scope,
        string $seed,
        string $checksum,
    ): string {
        $terminalSignature = self::chunkSignature('', $key, $timestamp, $scope, $seed);
        $canonical = "x-amz-checksum-sha256:{$checksum}\n";
        $trailerSignature = hash_hmac('sha256', implode("\n", [
            'AWS4-HMAC-SHA256-TRAILER',
            $timestamp,
            $scope,
            $terminalSignature,
            hash('sha256', $canonical),
        ]), $key);

        return "0;chunk-signature={$terminalSignature}\r\n"
            . "x-amz-checksum-sha256:{$checksum}\r\n"
            . "x-amz-trailer-signature:{$trailerSignature}\r\n\r\n";
    }

    private static function chunkSignature(
        string $payload,
        string $key,
        string $timestamp,
        string $scope,
        string $previous,
    ): string {
        return hash_hmac('sha256', implode("\n", [
            'AWS4-HMAC-SHA256-PAYLOAD',
            $timestamp,
            $scope,
            $previous,
            self::EMPTY_SHA256,
            hash('sha256', $payload),
        ]), $key);
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
    private function bodylessRequest(string|ReadableStream $body, array $headers): Request
    {
        $request = new Request(
            $this->createMock(Client::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            [],
            is_string($body) ? new ReadableBuffer($body) : $body,
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
