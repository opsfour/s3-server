<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Middleware;

use Amp\ByteStream\ReadableBuffer;
use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use Amp\Http\Server\Driver\Client;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use League\Uri\Http;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Exception\AccessControlListNotSupportedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchBucketPolicyException;
use OpsFour\S3Server\Auth\PostObjectAuthorizationPreflight;
use OpsFour\S3Server\Handler\Object\GetObjectHandler;
use OpsFour\S3Server\Handler\Object\HeadObjectHandler;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use OpsFour\S3Server\Middleware\LoggingMiddleware;
use OpsFour\S3Server\Middleware\RequestIdMiddleware;
use OpsFour\S3Server\Middleware\TrustedProxyMiddleware;
use OpsFour\S3Server\Middleware\WebsiteHostingMiddleware;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Routing\S3AttributeMiddleware;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Routing\OperationResolver;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;

final class LoggingMiddlewareTest extends TestCase
{
    public function test_copy_source_get_keeps_success_status_when_destination_later_fails(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        foreach (['source', 'destination', 'source-log', 'destination-log'] as $bucket) {
            $metadata->createBucket('alice', $bucket, 'us-east-1');
        }
        $source = $metadata->getBucket('source');
        $destination = $metadata->getBucket('destination');
        self::assertNotNull($source);
        self::assertNotNull($destination);
        $metadata->putBucketLogging('source', 'source-log', 'source/');
        $metadata->putBucketLogging('destination', 'destination-log', 'destination/');

        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = new Request(
            new LoggingMiddlewareTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/destination/copied.txt'),
        );
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.bucket', 'destination');
        $request->setAttribute('s3.key', 'copied.txt');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $destination->generation);
        $request->setAttribute('s3.operation', S3Operation::CopyObject);

        self::assertSame(409, (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest(
            $request,
            new class ($source->generation) implements RequestHandler {
                public function __construct(private readonly string $sourceGeneration) {}

                public function handleRequest(Request $request): Response
                {
                    $request->setAttribute('s3.copySourceBucket', 'source');
                    $request->setAttribute('s3.copySourceKey', 'original.txt');
                    $request->setAttribute('s3.copySourceBucketOwner', 'alice');
                    $request->setAttribute('s3.copySourceBucketGeneration', $this->sourceGeneration);
                    $request->setAttribute('s3.copySourceObjectSize', 7);
                    $request->setAttribute('s3.copySourceLogEligible', true);
                    $request->setAttribute('s3.copySourceReadComplete', true);

                    return new Response(409);
                }
            },
        )->getStatus());
        $writer->flush();

        $sourceLogs = $metadata->listObjects('source-log')->objects;
        $destinationLogs = $metadata->listObjects('destination-log')->objects;
        self::assertCount(1, $sourceLogs);
        self::assertCount(1, $destinationLogs);
        $sourceLine = \Amp\ByteStream\buffer($storage->getObjectByPath($sourceLogs[0]->systemMetadata['storagePath']));
        $destinationLine = \Amp\ByteStream\buffer($storage->getObjectByPath($destinationLogs[0]->systemMetadata['storagePath']));
        self::assertStringContainsString('REST.COPY.OBJECT_GET original.txt "GET /source/original.txt HTTP/1.1" 200 - 7 7', $sourceLine);
        self::assertStringContainsString('REST.COPY.OBJECT copied.txt "PUT /destination/copied.txt HTTP/1.1" 409', $destinationLine);
    }

    public function test_copy_source_log_is_not_delivered_after_source_bucket_name_reuse(): void
    {
        $this->assertCopySourceLogIsNotDeliveredAfterNameReuse(S3Operation::CopyObject);
    }

    public function test_part_copy_source_log_is_not_delivered_after_source_bucket_name_reuse(): void
    {
        $this->assertCopySourceLogIsNotDeliveredAfterNameReuse(S3Operation::UploadPartCopy);
    }

    private function assertCopySourceLogIsNotDeliveredAfterNameReuse(S3Operation $operation): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        foreach (['source', 'destination', 'source-log-old', 'source-log-new', 'destination-log'] as $bucket) {
            $metadata->createBucket('alice', $bucket, 'us-east-1');
        }
        $oldSource = $metadata->getBucket('source');
        $destination = $metadata->getBucket('destination');
        self::assertNotNull($oldSource);
        self::assertNotNull($destination);
        $metadata->putBucketLogging('source', 'source-log-old', 'old/');
        $metadata->putBucketLogging('destination', 'destination-log', 'destination/');

        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = new Request(
            new LoggingMiddlewareTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/destination/copied.txt'),
        );
        $request->setAttribute('requestId', 'copy-reuse');
        $request->setAttribute('ownerId', 'alice');
        $request->setAttribute('s3.bucket', 'destination');
        $request->setAttribute('s3.key', 'copied.txt');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $destination->generation);
        $request->setAttribute('s3.operation', $operation);

        $response = (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest(
            $request,
            new class ($metadata, $oldSource->generation) implements RequestHandler {
                public function __construct(
                    private readonly SqliteMetadataStore $metadata,
                    private readonly string $oldGeneration,
                ) {}

                public function handleRequest(Request $request): Response
                {
                    $request->setAttribute('s3.copySourceBucket', 'source');
                    $request->setAttribute('s3.copySourceKey', 'original.txt');
                    $request->setAttribute('s3.copySourceBucketOwner', 'alice');
                    $request->setAttribute('s3.copySourceBucketGeneration', $this->oldGeneration);
                    $request->setAttribute('s3.copySourceObjectSize', 7);
                    $request->setAttribute('s3.copySourceLogEligible', true);
                    $request->setAttribute('s3.copySourceReadComplete', true);
                    $this->metadata->deleteBucket('alice', 'source');
                    $this->metadata->createBucket('alice', 'source', 'us-east-1');
                    $this->metadata->putBucketLogging('source', 'source-log-new', 'new/');

                    return new Response(409);
                }
            },
        );
        self::assertSame(409, $response->getStatus());
        $writer->flush();

        self::assertCount(0, $metadata->listObjects('source-log-old')->objects);
        self::assertCount(0, $metadata->listObjects('source-log-new')->objects);
        $destinationLogs = $metadata->listObjects('destination-log')->objects;
        self::assertCount(1, $destinationLogs);
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($destinationLogs[0]->systemMetadata['storagePath']));
        $destinationOperation = $operation === S3Operation::UploadPartCopy ? 'REST.COPY.PART' : 'REST.COPY.OBJECT';
        self::assertSame(1, substr_count($line, $destinationOperation));
        self::assertStringNotContainsString($destinationOperation . '_GET', $line);
    }

    public function test_access_log_records_trusted_proxy_client_ip(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);

        $request = $this->request();
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.key', 'object.txt');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setHeader('X-Forwarded-For', '198.51.100.8');
        (new TrustedProxyMiddleware(['127.0.0.1/32']))->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204);
            }
        });
        (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        });
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString(' 198.51.100.8 ', $line);
    }

    public function test_successful_request_is_logged_with_response_status(): void
    {
        $logger = new LoggingMiddlewareArrayLogger();
        $middleware = new LoggingMiddleware($logger);

        $response = $middleware->handleRequest(
            $this->request(),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(204);
                }
            },
        );

        self::assertSame(204, $response->getStatus());
        self::assertSame(204, $logger->records[0]['context']['status']);
    }

    public function test_head_access_log_does_not_count_the_object_length_as_bytes_sent(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200, ['Content-Length' => '42']);
            }
        };

        foreach (['GET', 'HEAD'] as $method) {
            $request = $this->request($method);
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.key', 'object.txt');
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $middleware->handleRequest($request, $handler);
        }
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $lines = explode("\n", trim(\Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']))));
        self::assertCount(2, $lines);
        self::assertStringContainsString(' 200 - 42 - ', $lines[0]);
        self::assertStringContainsString(' 200 - - - ', $lines[1]);
    }

    public function test_buffered_response_without_content_length_logs_actual_bytes_and_remains_readable(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = $this->request();
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.key', 'listing');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('s3.operation', S3Operation::ListObjectsV2);

        $response = $this->handleWithErrorHandling(
            $request,
            new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, ['Content-Type' => 'application/xml'], '<Result/>');
                }
            },
        );

        self::assertNull($response->getHeader('Content-Length'));
        self::assertSame('<Result/>', \Amp\ByteStream\buffer($response->getBody()));
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertMatchesRegularExpression('/ REST\.GET\.BUCKET listing .* 200 - 9 - /', $line);
    }

    public function test_object_size_is_distinct_from_sent_bytes_for_range_head_and_empty_get(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();

        foreach (['full.txt' => 'abcdef', 'empty.txt' => ''] as $key => $body) {
            $write = $storage->putObject('source', $key, new ReadableBuffer($body));
            $metadata->putObjectMetadata(
                'source',
                $key,
                'alice',
                $write->size,
                '"' . $write->md5Hex . '"',
                'text/plain',
                $write->path,
            );
        }

        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);
        $cases = [
            ['GET', 'full.txt', 'bytes=1-2', new GetObjectHandler($metadata, $storage)],
            ['HEAD', 'full.txt', null, new HeadObjectHandler($metadata)],
            ['GET', 'empty.txt', null, new GetObjectHandler($metadata, $storage)],
        ];
        foreach ($cases as [$method, $key, $range, $handler]) {
            $request = $this->request($method);
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.key', $key);
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $request->setAttribute('ownerId', 'alice');
            if ($range !== null) {
                $request->setHeader('Range', $range);
            }
            $response = $middleware->handleRequest($request, $handler);
            if ($method === 'GET') {
                self::assertSame($key === 'full.txt' ? 'bc' : '', \Amp\ByteStream\buffer($response->getBody()));
            }
        }
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $lines = explode("\n", trim(\Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']))));
        self::assertCount(3, $lines);
        self::assertStringContainsString(' 206 - 2 6 ', $lines[0]);
        self::assertStringContainsString(' 200 - - 6 ', $lines[1]);
        self::assertStringContainsString(' 200 - - 0 ', $lines[2]);
    }

    public function test_streamed_access_log_counts_consumed_bytes_after_early_close(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);

        foreach ([false, true] as $finish) {
            $request = $this->request();
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.key', $finish ? 'complete.txt' : 'aborted.txt');
            $request->setAttribute('s3.operation', S3Operation::GetObject);
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $response = $this->handleWithErrorHandling($request, $middleware, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, ['Content-Length' => '6'], new ReadableIterableStream(['ab', 'cdef']));
                }
            });
            self::assertSame('6', $response->getHeader('Content-Length'));
            self::assertSame(0, $writer->getBufferCount());
            self::assertSame('ab', $response->getBody()->read());
            if ($finish) {
                self::assertSame('cdef', $response->getBody()->read());
                self::assertNull($response->getBody()->read());
                $response->getBody()->close();
            } else {
                $response->getBody()->close();
            }
            $response->getBody()->close();
            self::assertSame(1, $writer->getBufferCount());
            $writer->flush();
        }

        $lines = '';
        foreach ($metadata->listObjects('log-target')->objects as $object) {
            $lines .= \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        }
        self::assertMatchesRegularExpression('/REST\.GET\.OBJECT aborted\.txt .* 200 - 2 - /', $lines);
        self::assertMatchesRegularExpression('/REST\.GET\.OBJECT complete\.txt .* 200 - 6 - /', $lines);
    }

    public function test_stream_errors_and_cancellation_log_once_after_close(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);

        foreach ([new \RuntimeException('stream error'), new \Amp\CancelledException()] as $error) {
            $request = $this->request();
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.key', $error instanceof \Amp\CancelledException ? 'cancelled.txt' : 'failed.txt');
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $request->setAttribute('s3.operation', S3Operation::GetObject);
            $response = $middleware->handleRequest($request, new class ($error) implements RequestHandler {
                public function __construct(private readonly \Throwable $error) {}

                public function handleRequest(Request $request): Response
                {
                    $chunks = function (): \Generator {
                        yield 'ab';
                        throw $this->error;
                    };

                    return new Response(200, ['Content-Length' => '6'], new ReadableIterableStream($chunks()));
                }
            });
            self::assertSame('ab', $response->getBody()->read());
            try {
                $response->getBody()->read();
                self::fail('Expected the source stream to fail.');
            } catch (\Throwable $actual) {
                self::assertSame($error, $actual);
            }
            $response->getBody()->close();
            $response->getBody()->close();
        }

        self::assertSame(2, $writer->getBufferCount());
        $writer->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $lines = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertCount(2, array_filter(explode("\n", $lines)));
        self::assertMatchesRegularExpression('/REST\.GET\.OBJECT failed\.txt .* 200 - 2 - /', $lines);
        self::assertMatchesRegularExpression('/REST\.GET\.OBJECT cancelled\.txt .* 200 - 2 - /', $lines);
    }

    public function test_s3_error_response_logs_the_exact_generated_xml_bytes_with_request_context(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'test-bucket', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('test-bucket', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = $this->request();
        $request->setAttribute('requestId', 'error-request');
        $request->setAttribute('amzId2', 'extended-error-request');
        $request->setAttribute('s3.bucket', 'test-bucket');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('s3.operation', S3Operation::GetBucketPolicy);

        $response = $this->handleWithErrorHandling(
            $request,
            new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    throw new NoSuchBucketPolicyException();
                }
            },
        );
        $body = \Amp\ByteStream\buffer($response->getBody());

        self::assertSame(404, $response->getStatus());
        self::assertSame('error-request', $response->getHeader('x-amz-request-id'));
        self::assertStringContainsString('<Code>NoSuchBucketPolicy</Code>', $body);
        self::assertStringContainsString('<RequestId>error-request</RequestId>', $body);
        self::assertStringContainsString('<HostId>extended-error-request</HostId>', $body);
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString(' error-request REST.GET.BUCKETPOLICY ', $line);
        self::assertStringContainsString(
            ' 404 NoSuchBucketPolicy ' . strlen($body) . ' - ',
            $line,
        );
    }

    public function test_current_error_handling_order_propagates_cancellation_without_logging_a_response(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'test-bucket', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('test-bucket', 'log-target', 'access/');
        $bucket = $metadata->getBucket('test-bucket');
        self::assertNotNull($bucket);
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $logger = new LoggingMiddlewareArrayLogger();
        $request = $this->request();
        $request->setAttribute('requestId', 'cancelled-request');
        $request->setAttribute('s3.bucket', 'test-bucket');
        $request->setAttribute('s3.key', 'cancelled.txt');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('s3.cachedBucketGeneration', $bucket->generation);
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $cancellation = new \Amp\CancelledException();

        try {
            $this->handleWithErrorHandling(
                $request,
                new LoggingMiddleware($logger, $writer),
                new class ($cancellation) implements RequestHandler {
                    public function __construct(private readonly \Amp\CancelledException $cancellation) {}

                    public function handleRequest(Request $request): Response
                    {
                        throw $this->cancellation;
                    }
                },
            );
            self::fail('Cancellation must not become an S3 error response.');
        } catch (\Amp\CancelledException $error) {
            self::assertSame($cancellation, $error);
        }

        self::assertSame([], $logger->records);
        self::assertSame(0, $writer->getBufferCount());
        $writer->flush();
        self::assertSame([], $metadata->listObjects('log-target')->objects);
    }

    public function test_s3_exception_is_logged_before_it_is_rethrown(): void
    {
        $logger = new LoggingMiddlewareArrayLogger();
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'test-bucket', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('test-bucket', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $accessLogs = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware($logger, $accessLogs);

        try {
            $middleware->handleRequest(
                $this->request(),
                new class implements RequestHandler {
                    public function handleRequest(Request $request): Response
                    {
                        $request->setAttribute('s3.bucket', 'test-bucket');
                        $request->setAttribute('s3.cachedBucketOwner', 'alice');

                        throw new NoSuchBucketException();
                    }
                },
            );
            self::fail('Expected the downstream S3 exception.');
        } catch (NoSuchBucketException) {
            self::assertSame(404, $logger->records[0]['context']['status']);
            self::assertSame('GET', $logger->records[0]['context']['method']);
        }

        $accessLogs->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertMatchesRegularExpression('/ 404 NoSuchBucket [1-9][0-9]* - /', $line);
    }

    public function test_request_without_resolved_bucket_owner_is_not_buffered_for_a_future_bucket(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $writer = new AccessLogWriter($metadata, new InMemoryBackend());
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);
        $request = $this->request();
        $request->setAttribute('s3.bucket', 'unclaimed-name');
        $request->setAttribute('s3.cachedBucketOwner', null);

        $middleware->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(404);
            }
        });

        self::assertSame(0, $writer->getBufferCount());
    }

    public function test_early_browser_post_denial_keeps_access_log_bucket_and_key_context(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = new Request(new LoggingMiddlewareTestClient(), 'POST', Http::new('http://127.0.0.1/source'));
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.operation', S3Operation::PostObject);
        $request->setAttribute('requestId', 'post-request');

        try {
            (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest(
                $request,
                new class ($metadata) implements RequestHandler {
                    public function __construct(private MetadataStore $metadata) {}

                    public function handleRequest(Request $request): Response
                    {
                        (new PostObjectAuthorizationPreflight($this->metadata))->check($request, null, ['key' => 'denied.txt'], 'denied.txt');

                        return new Response(204);
                    }
                },
            );
            self::fail('Expected the private-bucket form to be denied.');
        } catch (\OpsFour\S3Server\Exception\AccessDeniedException) {
        }

        $writer->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString('alice source ', $line);
        self::assertStringContainsString(' post-request REST.POST.OBJECT denied.txt ', $line);
        self::assertStringContainsString(' 403 AccessDenied ', $line);
    }

    public function test_access_log_records_valid_header_signature_and_tls_details(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $tls = TlsInfo::fromMetaData([
            'protocol' => 'TLSv1.3',
            'cipher_name' => 'TLS_AES_128_GCM_SHA256',
            'cipher_bits' => 128,
            'cipher_version' => 'TLSv1.3',
        ], []);
        $request = new Request(new LoggingMiddlewareTestClient($tls), 'GET', Http::new('https://example.test/source/key?versionId=v1'));
        $request->setHeader('Authorization', 'AWS4-HMAC-SHA256 Credential=key/example');
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('s3.operation', S3Operation::GetObject);
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('ownerId', 'alice');

        (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        });
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString(' v1 - SigV4 TLS_AES_128_GCM_SHA256 AuthHeader ', $line);
        self::assertStringContainsString(' TLSv1.3 - - -', $line);
        self::assertStringNotContainsString('Credential=key/example', $line);
    }

    public function test_access_log_metadata_failure_does_not_change_a_successful_response(): void
    {
        $metadata = $this->createMock(MetadataStore::class);
        $metadata->method('getBucketLogging')->willThrowException(new \RuntimeException('metadata unavailable'));
        $writer = new AccessLogWriter($metadata, new InMemoryBackend());
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);
        $request = $this->request();
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');

        $response = $middleware->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        });

        self::assertSame(200, $response->getStatus());
        self::assertSame(0, $writer->getBufferCount());
    }

    public function test_anonymous_post_log_does_not_attribute_the_request_to_the_bucket_owner(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = new Request(
            new LoggingMiddlewareTestClient(),
            'POST',
            Http::new('http://127.0.0.1/source?X-Amz-Algorithm=AWS4-HMAC-SHA256'),
        );
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.key', 'upload.txt');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('credential', null);
        $request->setHeader('Referer', 'https://example.test/upload');

        (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    $request->setAttribute('ownerId', 'alice');

                    return new Response(204);
                }
            },
        );
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString(' 127.0.0.1 - - POST upload.txt ', $line);
        self::assertStringContainsString('"https://example.test/upload"', $line);
        self::assertStringContainsString('"-" - - - - - -', $line);
    }

    public function test_common_operations_use_s3_access_log_names(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);
        foreach ([
            [S3Operation::GetObject, 'GET'],
            [S3Operation::HeadObject, 'HEAD'],
            [S3Operation::PutObject, 'PUT'],
            [S3Operation::DeleteObject, 'DELETE'],
            [S3Operation::DeleteObjects, 'POST'],
            [S3Operation::HeadBucket, 'HEAD'],
        ] as [$operation, $method]) {
            $request = $this->request($method);
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.key', 'object.txt');
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $request->setAttribute('s3.operation', $operation);
            $middleware->handleRequest($request, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(204);
                }
            });
        }
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        foreach (['REST.GET.OBJECT', 'REST.HEAD.OBJECT', 'REST.PUT.OBJECT', 'REST.DELETE.OBJECT', 'BATCH.DELETE.OBJECT', 'REST.HEAD.BUCKET'] as $name) {
            self::assertStringContainsString(' ' . $name . ' object.txt ', $body);
        }
    }

    public function test_bucket_subresource_operation_names_survive_success_and_error_paths(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);

        foreach ([
            'versioning' => S3Operation::GetBucketVersioning,
            'logging' => S3Operation::GetBucketLogging,
        ] as $subresource => $operation) {
            $request = new Request(new LoggingMiddlewareTestClient(), 'GET', Http::new("http://127.0.0.1/source?{$subresource}"));
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $resolved = OperationResolver::resolve('GET', 'bucket', [$subresource => ''], []);
            self::assertSame($operation, $resolved);
            $request->setAttribute('s3.operation', $resolved);
            $middleware->handleRequest($request, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200);
                }
            });
        }

        $request = new Request(new LoggingMiddlewareTestClient(), 'GET', Http::new('http://127.0.0.1/source?policy'));
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $policyOperation = OperationResolver::resolve('GET', 'bucket', ['policy' => ''], []);
        self::assertSame(S3Operation::GetBucketPolicy, $policyOperation);
        $request->setAttribute('s3.operation', $policyOperation);
        try {
            $middleware->handleRequest($request, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    throw new NoSuchBucketPolicyException();
                }
            });
            self::fail('The missing policy must fail.');
        } catch (NoSuchBucketPolicyException) {
        }

        $writer->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        foreach (['REST.GET.VERSIONING', 'REST.GET.LOGGING_STATUS'] as $name) {
            self::assertStringContainsString(' ' . $name . ' - ', $body);
        }
        self::assertMatchesRegularExpression('/ REST\.GET\.BUCKETPOLICY - .* 404 NoSuchBucketPolicy /', $body);
        self::assertStringNotContainsString(' GetBucket', $body);
    }

    public function test_bucket_and_object_acl_writes_use_the_documented_access_log_operation_name(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);

        $bucketRequest = $this->request('PUT');
        $bucketRequest->setAttribute('s3.bucket', 'source');
        $bucketRequest->setAttribute('s3.cachedBucketOwner', 'alice');
        $bucketRequest->setAttribute('s3.operation', S3Operation::PutBucketAcl);
        $middleware->handleRequest($bucketRequest, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(200);
            }
        });

        $objectRequest = $this->request('PUT');
        $objectRequest->setAttribute('s3.bucket', 'source');
        $objectRequest->setAttribute('s3.key', 'object.txt');
        $objectRequest->setAttribute('s3.cachedBucketOwner', 'alice');
        $objectRequest->setAttribute('s3.operation', S3Operation::PutObjectAcl);
        try {
            $middleware->handleRequest($objectRequest, new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    throw new AccessControlListNotSupportedException();
                }
            });
            self::fail('The object ACL write must fail in bucket-owner-enforced mode.');
        } catch (AccessControlListNotSupportedException) {
        }

        $writer->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $lines = explode("\n", trim(\Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']))));
        self::assertCount(2, $lines);
        self::assertMatchesRegularExpression('/ REST\.PUT\.ACL - .* 200 - /', $lines[0]);
        self::assertMatchesRegularExpression(
            '/ REST\.PUT\.ACL object\.txt .* 400 AccessControlListNotSupported /',
            $lines[1],
        );
    }

    public function test_every_routed_operation_has_an_audited_access_log_name(): void
    {
        // Only names shown in AWS-owned log examples are mapped. The remaining
        // enum values are deliberately visible as unsupported parity work.
        $verified = [
            'GetObject' => 'REST.GET.OBJECT',
            'HeadObject' => 'REST.HEAD.OBJECT',
            'PutObject' => 'REST.PUT.OBJECT',
            'DeleteObject' => 'REST.DELETE.OBJECT',
            'PostObject' => 'REST.POST.OBJECT',
            'DeleteObjects' => 'BATCH.DELETE.OBJECT',
            'HeadBucket' => 'REST.HEAD.BUCKET',
            'GetBucketVersioning' => 'REST.GET.VERSIONING',
            'GetBucketLogging' => 'REST.GET.LOGGING_STATUS',
            'GetBucketPolicy' => 'REST.GET.BUCKETPOLICY',
            'PutBucketPolicy' => 'REST.PUT.BUCKETPOLICY',
            'PutBucketAcl' => 'REST.PUT.ACL',
            'PutObjectAcl' => 'REST.PUT.ACL',
            'ListObjects' => 'REST.GET.BUCKET',
            'ListObjectsV2' => 'REST.GET.BUCKET',
            'CopyObject' => 'REST.COPY.OBJECT',
            'UploadPartCopy' => 'REST.COPY.PART',
            'GetObjectAcl' => 'REST.GET.OBJECT_ACL',
            'GetObjectTagging' => 'REST.GET.OBJECT_TAGGING',
            'GetObjectAttributes' => 'REST.GET.OBJECT_ATTRIBUTES',
            'SelectObjectContent' => 'REST.SELECT.OBJECT_CONTENT',
            'GetPublicAccessBlock' => 'REST.GET.PUBLIC.ACCESS.BLOCK',
            'GetBucketPolicyStatus' => 'REST.GET.POLICY.STATUS',
        ];
        self::assertCount(69, S3Operation::cases());
        self::assertCount(23, $verified);

        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer);
        $handler = new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                return new Response(204);
            }
        };

        foreach (S3Operation::cases() as $operation) {
            $request = $this->request();
            $request->setAttribute('s3.bucket', 'source');
            $request->setAttribute('s3.key', $operation->value);
            $request->setAttribute('s3.cachedBucketOwner', 'alice');
            $request->setAttribute('s3.operation', $operation);
            $middleware->handleRequest($request, $handler);
        }
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $body = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertCount(69, explode("\n", trim($body)));
        foreach (S3Operation::cases() as $operation) {
            $expected = $verified[$operation->value] ?? $operation->value;
            self::assertStringContainsString(" {$expected} {$operation->value} ", $body, $operation->value);
        }
    }

    public function test_browser_form_log_ignores_unverified_authorization_header(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'source', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('source', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $writer = new AccessLogWriter($metadata, $storage);
        $request = $this->request('POST');
        $request->setAttribute('s3.bucket', 'source');
        $request->setAttribute('s3.key', 'signed-form');
        $request->setAttribute('s3.cachedBucketOwner', 'alice');
        $request->setAttribute('s3.operation', S3Operation::PostObject);
        $request->setAttribute('credential', new Credential('key', 'secret', 'bob', 'Bob', true));
        $request->setAttribute('ownerId', 'bob');
        $request->setAttribute('s3.signatureVersion', 'SigV4');
        $request->setHeader('Authorization', 'AWS4-HMAC-SHA256 unverified');

        (new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $writer))->handleRequest(
            $request,
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(204);
                }
            },
        );
        $writer->flush();

        $object = $metadata->listObjects('log-target')->objects[0];
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringContainsString(' bob - REST.POST.OBJECT signed-form ', $line);
        self::assertStringContainsString(' SigV4 - - ', $line);
        self::assertStringNotContainsString('AuthHeader', $line);
    }

    public function test_presigned_credentials_and_signatures_are_redacted(): void
    {
        $logger = new LoggingMiddlewareArrayLogger();
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'bucket', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('bucket', 'log-target', 'access/');
        $storage = new InMemoryBackend();
        $accessLogs = new AccessLogWriter($metadata, $storage);
        $middleware = new LoggingMiddleware($logger, $accessLogs);
        $request = new Request(
            new LoggingMiddlewareTestClient(),
            'GET',
            Http::new(
                'http://127.0.0.1/bucket/key'
                . '?X-Amz-Credential=AKIA%2Fscope'
                . '&X-Amz-Signature=secret'
                . '&X-Amz-Security-Token=session'
                . '&X-Amz-Algorithm=AWS4-HMAC-SHA256'
                . '&versionId=visible',
            ),
        );
        $request->setAttribute('requestId', 'request-123');
        $request->setAttribute('amzId2', 'host-id-123');
        $request->setHeader('User-Agent', 'aws-sdk-php/3 test');
        $request->setHeader('Host', 's3.example.test');
        $request->setProtocolVersion('2.0');

        $middleware->handleRequest($request, new class implements RequestHandler {
            public function handleRequest(Request $request): Response
            {
                $request->setAttribute('s3.bucket', 'bucket');
                $request->setAttribute('s3.key', 'key');
                $request->setAttribute('s3.cachedBucketOwner', 'alice');
                $request->setAttribute('ownerId', 'bob');
                $request->setAttribute('s3.operation', S3Operation::GetObject);

                return new Response(200);
            }
        });

        $path = (string) $logger->records[0]['context']['path'];
        self::assertStringNotContainsString('AKIA', $path);
        self::assertStringNotContainsString('secret', $path);
        self::assertStringNotContainsString('session', $path);
        self::assertStringContainsString('versionId=visible', $path);
        self::assertSame(3, substr_count($path, '%5BREDACTED%5D'));

        $accessLogs->flush();
        $object = $metadata->listObjects('log-target')->objects[0];
        $accessLine = \Amp\ByteStream\buffer($storage->getObjectByPath($object->systemMetadata['storagePath']));
        self::assertStringNotContainsString('AKIA', $accessLine);
        self::assertStringNotContainsString('secret', $accessLine);
        self::assertStringNotContainsString('session', $accessLine);
        self::assertStringContainsString('alice bucket ', $accessLine);
        self::assertStringContainsString(' 127.0.0.1 bob ', $accessLine);
        self::assertStringContainsString(' bob request-123 REST.GET.OBJECT ', $accessLine);
        self::assertStringContainsString('HTTP/2.0"', $accessLine);
        self::assertStringContainsString('"aws-sdk-php/3 test" visible host-id-123', $accessLine);
        self::assertStringContainsString(' visible host-id-123 SigV4 - QueryString ', $accessLine);
        self::assertStringContainsString(' s3.example.test - - - -', $accessLine);
        self::assertStringNotContainsString('127.0.0.1:12345', $accessLine);
    }

    public function test_website_request_is_logged_against_the_host_bucket(): void
    {
        $metadata = new SqliteMetadataStore(':memory:');
        $metadata->initialize();
        $metadata->createBucket('alice', 'site', 'us-east-1');
        $metadata->createBucket('alice', 'log-target', 'us-east-1');
        $metadata->putBucketLogging('site', 'log-target', 'access/');
        $metadata->putBucketWebsite('site', 'index.html', redirectAllHost: 'example.com');
        $storage = new InMemoryBackend();
        $accessLogs = new AccessLogWriter($metadata, $storage);
        $request = new Request(
            new LoggingMiddlewareTestClient(),
            'GET',
            Http::new('http://site.s3-website.test/docs'),
            ['host' => 'site.s3-website.test'],
        );
        $request->setAttribute('requestId', 'website-request');
        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    TestCase::fail('Website middleware should handle this request.');
                }
            },
            new LoggingMiddleware(new LoggingMiddlewareArrayLogger(), $accessLogs),
            new WebsiteHostingMiddleware($metadata, StorageTierRegistry::single($storage), '*.s3-website.test'),
            new S3AttributeMiddleware('s3-api.test'),
        );

        self::assertSame(301, $handler->handleRequest($request)->getStatus());
        $indexRequest = new Request(
            new LoggingMiddlewareTestClient(),
            'HEAD',
            Http::new('http://site.s3-website.test/'),
            ['host' => 'site.s3-website.test'],
        );
        self::assertSame(301, $handler->handleRequest($indexRequest)->getStatus());
        $accessLogs->flush();

        $objects = $metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $line = \Amp\ByteStream\buffer($storage->getObjectByPath($objects[0]->systemMetadata['storagePath']));
        self::assertStringContainsString('alice site ', $line);
        self::assertStringContainsString(' WEBSITE.GET.OBJECT docs ', $line);
        self::assertStringContainsString(' WEBSITE.HEAD.OBJECT index.html ', $line);
        self::assertCount(2, array_filter(explode("\n", $line)));

        $metadata->deleteBucketWebsite('site');
        $missingConfigRequest = new Request(
            new LoggingMiddlewareTestClient(),
            'GET',
            Http::new('http://site.s3-website.test/no-config'),
            ['host' => 'site.s3-website.test'],
        );
        self::assertSame(404, $handler->handleRequest($missingConfigRequest)->getStatus());
        $accessLogs->flush();
        $allLines = '';
        foreach ($metadata->listObjects('log-target')->objects as $logObject) {
            $allLines .= \Amp\ByteStream\buffer($storage->getObjectByPath($logObject->systemMetadata['storagePath']));
        }
        self::assertMatchesRegularExpression('/WEBSITE\.GET\.OBJECT no-config .* 404 /', $allLines);
    }

    public function test_throwing_logger_cannot_replace_a_successful_response(): void
    {
        $logger = new class extends AbstractLogger {
            /** @param array<string, mixed> $context */
            public function log($level, string|\Stringable $message, array $context = []): void
            {
                throw new \RuntimeException('logger unavailable');
            }
        };
        $middleware = new LoggingMiddleware($logger);

        $response = $middleware->handleRequest(
            $this->request('PUT'),
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(200, ['content-length' => '0']);
                }
            },
        );

        self::assertSame(200, $response->getStatus());
    }

    /** @param non-empty-string $method */
    private function request(string $method = 'GET'): Request
    {
        return new Request(
            new LoggingMiddlewareTestClient(),
            $method,
            Http::new('http://127.0.0.1/test-bucket/missing.txt'),
        );
    }

    private function handleWithErrorHandling(
        Request $request,
        LoggingMiddleware $logging,
        RequestHandler $terminal,
    ): Response {
        $loggingHandler = new class ($logging, $terminal) implements RequestHandler {
            public function __construct(
                private readonly LoggingMiddleware $logging,
                private readonly RequestHandler $terminal,
            ) {}

            public function handleRequest(Request $request): Response
            {
                return $this->logging->handleRequest($request, $this->terminal);
            }
        };
        $errorHandler = new ErrorHandlingMiddleware();

        return (new RequestIdMiddleware())->handleRequest(
            $request,
            new class ($errorHandler, $loggingHandler) implements RequestHandler {
                public function __construct(
                    private readonly ErrorHandlingMiddleware $errors,
                    private readonly RequestHandler $inner,
                ) {}

                public function handleRequest(Request $request): Response
                {
                    return $this->errors->handleRequest($request, $this->inner);
                }
            },
        );
    }
}

final class LoggingMiddlewareTestClient implements Client
{
    public function __construct(private readonly ?TlsInfo $tlsInfo = null) {}

    public function getId(): int
    {
        return 1;
    }

    public function getRemoteAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 12345);
    }

    public function getLocalAddress(): SocketAddress
    {
        return new InternetAddress('127.0.0.1', 9000);
    }

    public function getTlsInfo(): ?\Amp\Socket\TlsInfo
    {
        return $this->tlsInfo;
    }

    public function onClose(\Closure $onClose): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function close(): void {}
}

final class LoggingMiddlewareArrayLogger extends AbstractLogger
{
    /** @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}> */
    public array $records = [];

    /** @param array<string, mixed> $context */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = compact('level', 'message', 'context');
    }
}
