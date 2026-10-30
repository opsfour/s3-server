<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableBuffer;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Parallel\Worker\ContextWorkerPool;
use League\Uri\Http;
use OpsFour\S3Server\Checksum\Crc64NvmeDigest;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Middleware\ContentMd5Middleware;
use OpsFour\S3Server\Middleware\RequestBodySpool;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class ContentMd5MiddlewareTest extends TestCase
{
    public function test_supported_checksum_required_mutations_reject_missing_digests(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-sdk-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempDir, 0o700));
        $pool = new ContextWorkerPool(1);
        $middleware = new ContentMd5Middleware(new RequestBodySpool($pool, $tempDir));
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                throw new \LogicException('A checksum-required request without a digest reached its handler.');
            }
        };
        $operations = [
            S3Operation::DeleteObjects,
            S3Operation::PutBucketAcl,
            S3Operation::PutBucketCors,
            S3Operation::PutBucketEncryption,
            S3Operation::PutBucketLifecycle,
            S3Operation::PutBucketLogging,
            S3Operation::PutBucketOwnershipControls,
            S3Operation::PutBucketPolicy,
            S3Operation::PutBucketTagging,
            S3Operation::PutBucketVersioning,
            S3Operation::PutBucketWebsite,
            S3Operation::PutObjectAcl,
            S3Operation::PutObjectLegalHold,
            S3Operation::PutObjectLockConfig,
            S3Operation::PutObjectRetention,
            S3Operation::PutObjectTagging,
            S3Operation::PutPublicAccessBlock,
        ];

        try {
            foreach ($operations as $operation) {
                $request = new Request(
                    $this->createMock(Client::class),
                    $operation === S3Operation::DeleteObjects ? 'POST' : 'PUT',
                    Http::new('http://127.0.0.1/bucket'),
                    [],
                    new ReadableBuffer('<Body/>'),
                );
                $request->setAttribute('s3.operation', $operation);
                try {
                    $middleware->handleRequest($request, $handler);
                    self::fail("{$operation->value} accepted a missing request checksum.");
                } catch (InvalidRequestException $error) {
                    self::assertStringContainsString('checksum is required', $error->getMessage());
                }
            }
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }

    public function test_bucket_notification_does_not_require_a_separate_request_checksum(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-sdk-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempDir, 0o700));
        $pool = new ContextWorkerPool(1);
        $middleware = new ContentMd5Middleware(new RequestBodySpool($pool, $tempDir));
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(200);
            }
        };

        try {
            $request = new Request(
                $this->createMock(Client::class),
                'PUT',
                Http::new('http://127.0.0.1/bucket?notification'),
                [],
                new ReadableBuffer('<NotificationConfiguration/>'),
            );
            $request->setAttribute('s3.operation', S3Operation::PutBucketNotification);

            self::assertSame(200, $middleware->handleRequest($request, $handler)->getStatus());
            self::assertSame(1, $calls);
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }

    /**
     * @param non-empty-string $header
     * @param list<string> $values
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('repeatedDigestHeaders')]
    public function test_repeated_digest_singletons_are_rejected_before_dispatch(string $header, array $values): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-sdk-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempDir, 0o700));
        $pool = new ContextWorkerPool(1);
        $middleware = new ContentMd5Middleware(new RequestBodySpool($pool, $tempDir));
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();

                return new Response(204);
            }
        };
        $body = '<Delete><Object><Key>keep</Key></Object></Delete>';
        $request = new Request(
            $this->createMock(Client::class),
            'POST',
            Http::new('http://127.0.0.1/bucket?delete'),
            [$header => $values],
            new ReadableBuffer($body),
        );
        $request->setAttribute('s3.operation', S3Operation::DeleteObjects);

        try {
            $this->expectException(InvalidArgumentException::class);
            try {
                $middleware->handleRequest($request, $handler);
            } finally {
                self::assertSame(0, $calls);
            }
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }

    /** @return iterable<string, array{non-empty-string, list<string>}> */
    public static function repeatedDigestHeaders(): iterable
    {
        $body = '<Delete><Object><Key>keep</Key></Object></Delete>';

        yield 'Content-MD5' => [
            'Content-MD5',
            [base64_encode(md5($body, true)), base64_encode(str_repeat("\0", 16))],
        ];
        yield 'checksum value' => [
            'x-amz-checksum-crc32',
            [base64_encode(hash('crc32b', $body, true)), base64_encode("\0\0\0\0")],
        ];
        yield 'declared checksum algorithm' => [
            'x-amz-sdk-checksum-algorithm',
            ['CRC32', 'SHA256'],
        ];
    }

    public function test_delete_objects_rejects_checksum_header_that_disagrees_with_declared_sdk_algorithm(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-sdk-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempDir, 0o700));
        $pool = new ContextWorkerPool(1);
        $middleware = new ContentMd5Middleware(new RequestBodySpool($pool, $tempDir));
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();
                return new Response(204);
            }
        };
        $body = '<Delete><Object><Key>keep</Key></Object></Delete>';

        try {
            $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/bucket?delete'), [], new ReadableBuffer($body));
            $request->setAttribute('s3.operation', S3Operation::DeleteObjects);
            $request->setHeader('x-amz-sdk-checksum-algorithm', 'CRC32');
            $request->setHeader('x-amz-checksum-sha256', base64_encode(hash('sha256', $body, true)));

            $this->expectException(BadDigestException::class);
            try {
                $middleware->handleRequest($request, $handler);
            } finally {
                self::assertSame(0, $calls, 'A valid but differently named digest must not dispatch the delete.');
            }
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }

    public function test_delete_objects_accepts_matching_declared_sdk_checksum_and_rejects_a_bad_value(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-sdk-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempDir, 0o700));
        $pool = new ContextWorkerPool(1);
        $middleware = new ContentMd5Middleware(new RequestBodySpool($pool, $tempDir));
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();
                return new Response(204);
            }
        };
        $body = '<Delete><Object><Key>keep</Key></Object></Delete>';
        $request = function (string $checksum) use ($body): Request {
            $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/bucket?delete'), [], new ReadableBuffer($body));
            $request->setAttribute('s3.operation', S3Operation::DeleteObjects);
            $request->setHeader('x-amz-sdk-checksum-algorithm', 'CRC32');
            $request->setHeader('x-amz-checksum-crc32', $checksum);
            return $request;
        };

        try {
            self::assertSame(204, $middleware->handleRequest($request(base64_encode(hash('crc32b', $body, true))), $handler)->getStatus());
            self::assertSame(1, $calls);
            try {
                $middleware->handleRequest($request(base64_encode("\0\0\0\0")), $handler);
                self::fail('A bad checksum value unexpectedly reached the delete handler.');
            } catch (BadDigestException) {
                self::assertSame(1, $calls);
            }
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }

    public function test_delete_objects_accepts_crc64nvme_and_rejects_noncanonical_or_mismatched_values(): void
    {
        $tempDir = sys_get_temp_dir() . '/s3-sdk-checksum-' . bin2hex(random_bytes(6));
        self::assertTrue(mkdir($tempDir, 0o700));
        $pool = new ContextWorkerPool(1);
        $middleware = new ContentMd5Middleware(new RequestBodySpool($pool, $tempDir));
        $calls = 0;
        $handler = new class (static function () use (&$calls): void {
            ++$calls;
        }) implements RequestHandler {
            public function __construct(private readonly \Closure $onCall) {}

            public function handleRequest(Request $request): Response
            {
                ($this->onCall)();
                return new Response(204);
            }
        };
        $body = '<Delete><Object><Key>crc64</Key></Object></Delete>';
        $digest = new Crc64NvmeDigest();
        $digest->update($body);
        $checksum = $digest->finalizeBase64();
        $request = function (string $value) use ($body): Request {
            $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/bucket?delete'), [], new ReadableBuffer($body));
            $request->setAttribute('s3.operation', S3Operation::DeleteObjects);
            $request->setHeader('x-amz-sdk-checksum-algorithm', 'CRC64NVME');
            $request->setHeader('x-amz-checksum-crc64nvme', $value);
            return $request;
        };

        try {
            self::assertSame(204, $middleware->handleRequest($request($checksum), $handler)->getStatus());
            self::assertSame(1, $calls);
            foreach (['AAAAAAAAAAA=', rtrim($checksum, '=')] as $invalid) {
                try {
                    $middleware->handleRequest($request($invalid), $handler);
                    self::fail('An invalid CRC64NVME value unexpectedly reached the delete handler.');
                } catch (BadDigestException) {
                    self::assertSame(1, $calls);
                }
            }
        } finally {
            $pool->shutdown();
            @rmdir($tempDir);
        }
    }
}
