<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableStream;
use Amp\ByteStream\ReadableStreamIteratorAggregate;
use Amp\ByteStream\ReadableBuffer;
use Amp\Cancellation;
use Amp\Http\Server\Driver\Client as ServerClient;
use Amp\Http\Server\Request;
use Amp\Parallel\Worker\TaskFailureException;
use OpsFour\S3Server\Auth\Credential;
use OpsFour\S3Server\Auth\InMemoryCredentialProvider;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\Uri\Http;
use OpsFour\S3Server\Auth\PostObjectForm;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Encryption\SseS3Capability;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\EntityTooLargeException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\QuotaExceededException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Object\CopyObjectHandler;
use OpsFour\S3Server\Handler\Object\PostObjectHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Factory\StorageBackendFactory;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Observability\ObservedStorageBackend;
use OpsFour\S3Server\Quota\QuotaConfig;
use OpsFour\S3Server\Quota\QuotaManager;
use OpsFour\S3Server\Quota\QuotaProvider;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Runtime\S3ServerRuntimeFactory;
use OpsFour\S3Server\Runtime\BufferedWorkLimiter;
use OpsFour\S3Server\S3ServerConfig;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\LocalFencedObjectWriter;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuard;
use OpsFour\S3Server\Storage\LocalObjectWriteLeaseGuardFactory;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;
use Psr\Log\NullLogger;

final class AwsS3OrdinaryObjectFenceTest extends TestCase
{
    private string $base;
    private SqliteMetadataStore $metadata;
    private MockHandler $mock;
    private AwsS3StorageBackend $backend;
    private AwsS3ForegroundObjectWriter $writer;
    /** @var list<RequestInterface> */
    private array $requests = [];
    /** @var list<string> */
    private array $providerBodies = [];
    /** @var array<string, string|false> */
    private array $oldEnvironment = [];
    private ?\Closure $beforeProviderRequest = null;

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'test', 'AWS_SECRET_ACCESS_KEY' => 'test'] as $name => $value) {
            $this->oldEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->base = sys_get_temp_dir() . '/s3-aws-ordinary-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0700));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->metadata->createBucket('owner', 'target', 'us-east-1');
        $this->mock = new MockHandler([
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ]);
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create($this->mock)]));
        $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
            'bucket' => 'backing-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/write-attempts',
        ], new AwsS3ProviderValidator(function (RequestInterface $request, array $options) use ($handler) {
            ($this->beforeProviderRequest)?->__invoke($request);
            if ($request->getMethod() === 'PUT') {
                $this->providerBodies[] = (string) $request->getBody();
            }
            $this->requests[] = $request;
            return $handler($request, $options)->then(static function ($response) use ($request) {
                if ($request->getMethod() !== 'GET' || $response->getStatusCode() >= 300
                    || ! $response->hasHeader('x-amz-meta-opsfour-write-attempt')) {
                    return $response;
                }
                $range = $response->getHeaderLine('Content-Range');
                $contentSize = preg_match('/\/(\d+)$/D', $range, $matches) === 1
                    ? $matches[1] : $response->getHeaderLine('Content-Length');

                return $response
                    ->withHeader('x-amz-meta-opsfour-owner-digest', hash('sha256', 'owner'))
                    ->withHeader('x-amz-meta-opsfour-content-size', $contentSize);
            });
        }));
        self::assertTrue($binding->admission->providerAdmitted);
        $this->backend = new AwsS3StorageBackend($binding->admission, $binding);
        $this->writer = $this->backend->createForegroundObjectWriter($this->metadata, $this->base . '/scratch', 'STANDARD', 1_048_576);
    }

    protected function tearDown(): void
    {
        $this->writer->shutdown();
        foreach ($this->oldEnvironment as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        $entries = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($this->base, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );
        foreach ($entries as $entry) {
            $entry->isDir() ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
        }
        @rmdir($this->base);
    }

    public function test_plain_put_commits_exact_dispatch_and_logical_md5(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        $payload = 'ordinary AWS payload';
        $response = $this->put()->handleRequest($this->putRequest($payload, [
            'x-amz-checksum-sha256' => base64_encode(hash('sha256', $payload, true)),
        ]));

        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('"' . md5($payload) . '"', $object->etag);
        self::assertSame('STANDARD', $object->storageTier);
        $path = $object->systemMetadata['storagePath'];
        self::assertStringStartsWith('.opsfour/write-attempts/bucket/', $path);
        self::assertNull($this->metadata->getObjectWriteIntent(substr($path, -32)));
        self::assertSame('PUT', $this->requests[2]->getMethod());
        self::assertSame('*', $this->requests[2]->getHeaderLine('If-None-Match'));
        self::assertSame('123456789012', $this->requests[2]->getHeaderLine('x-amz-expected-bucket-owner'));
    }

    public function test_put_immediately_discards_replaced_non_aws_tier_data(): void
    {
        $warm = new InMemoryBackend();
        $old = $warm->putObject('bucket', 'key', new ReadableBuffer('old payload'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            strlen('old payload'),
            '"' . md5('old payload') . '"',
            'text/plain',
            $old->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'key', null, 'STANDARD', 'WARM', $old->path);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->backend, defaultWriteTier: true),
            new StorageTier('WARM', $warm),
        ]);
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));

        $response = (new PutObjectHandler(
            $this->metadata,
            $this->backend,
            storageTiers: $tiers,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->putRequest('new payload'));

        self::assertSame(200, $response->getStatus());
        self::assertStringStartsWith(
            '.opsfour/write-attempts/bucket/',
            $this->metadata->getObjectMetadata('bucket', 'key')?->systemMetadata['storagePath'] ?? '',
        );
        try {
            $warm->getObjectByPath($old->path);
            self::fail('The replaced non-AWS path was not immediately discarded.');
        } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
        }
    }

    public function test_put_preserves_primary_validation_failure_when_scratch_release_fails(): void
    {
        $payload = str_repeat('p', 65_536);
        $body = new AwsS3TestReadableStream([$payload], function (int $read): void {
            if ($read === 2) {
                $this->corruptScratchBudgetState();
            }
        });
        $request = $this->putRequestWithBody($body, strlen($payload));
        $request->setHeader('x-amz-checksum-sha256', base64_encode(str_repeat("\0", 32)));

        try {
            $this->put()->handleRequest($request);
            self::fail('The invalid client checksum was accepted.');
        } catch (BadDigestException $error) {
            self::assertStringStartsWith('Checksum mismatch:', $error->getMessage());
        }

        self::assertCount(2, $this->requests);
        self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
    }

    public function test_versioned_put_consumes_exact_dispatch_and_stores_immutable_attempt_path(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));

        $response = $this->put()->handleRequest($this->putRequest('versioned AWS payload'));

        self::assertSame(200, $response->getStatus());
        $versionId = $response->getHeader('x-amz-version-id');
        self::assertNotNull($versionId);
        self::assertNotSame('null', $versionId);
        $current = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($current);
        self::assertSame($versionId, $current->versionId);
        $version = $this->metadata->getObjectMetadataByVersion('bucket', 'key', $versionId);
        self::assertNotNull($version);
        $path = $version->systemMetadata['storagePath'];
        self::assertSame($path, $current->systemMetadata['storagePath']);
        self::assertStringStartsWith('.opsfour/write-attempts/bucket/', $path);
        self::assertSame($path, ltrim(rawurldecode($this->requests[2]->getUri()->getPath()), '/'));
        self::assertNull($this->metadata->getObjectWriteIntent(basename($path)));
    }

    public function test_nested_observed_default_aws_backend_executes_put_and_copy_through_the_exact_writer(): void
    {
        $innerObserved = new ObservedStorageBackend($this->backend, new MetricsCollector(), 'aws-s3-inner');
        $observed = new ObservedStorageBackend($innerObserved, new MetricsCollector(), 'aws-s3-outer');
        $tiers = StorageTierRegistry::single($observed);
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));

        $put = new PutObjectHandler(
            $this->metadata,
            $observed,
            storageTiers: $tiers,
            awsObjectWriter: $this->writer,
        );
        self::assertSame(200, $put->handleRequest($this->putRequest('observed AWS payload'))->getStatus());

        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame(
            $object->systemMetadata['storagePath'],
            ltrim(rawurldecode($this->requests[2]->getUri()->getPath()), '/'),
        );
        self::assertNull($this->metadata->getObjectWriteIntent(basename($object->systemMetadata['storagePath'])));

        $attempt = basename($object->systemMetadata['storagePath']);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen('observed AWS payload'),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attempt,
        ], 'observed AWS payload'));
        $this->mock->append(new Response(200, ['ETag' => '"copy-etag"']));
        $copy = new CopyObjectHandler(
            $this->metadata,
            $observed,
            storageTiers: $tiers,
            awsObjectWriter: $this->writer,
        );
        self::assertSame(200, $copy->handleRequest($this->copyRequest())->getStatus());
        $copied = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($copied);
        self::assertNull($this->metadata->getObjectWriteIntent(basename($copied->systemMetadata['storagePath'])));
    }

    public function test_copy_from_non_aws_source_tier_publishes_selected_bytes_and_discards_replaced_tier_data(): void
    {
        $warm = new InMemoryBackend();
        $payload = 'warm source payload';
        $source = $warm->putObject('bucket', 'key', new ReadableBuffer($payload));
        $old = $warm->putObject('target', 'copied', new ReadableBuffer('old destination'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            strlen($payload),
            '"' . md5($payload) . '"',
            'text/plain',
            $source->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'key', null, 'STANDARD', 'WARM', $source->path);
        $this->metadata->putObjectMetadata(
            'target',
            'copied',
            'owner',
            strlen('old destination'),
            '"' . md5('old destination') . '"',
            'text/plain',
            $old->path,
        );
        $this->metadata->updateObjectPlacement('target', 'copied', null, 'STANDARD', 'WARM', $old->path);
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->backend, defaultWriteTier: true),
            new StorageTier('WARM', $warm),
        ]);
        $this->mock->append(new Response(200, ['ETag' => '"copy-etag"']));

        $response = (new CopyObjectHandler(
            $this->metadata,
            $this->backend,
            storageTiers: $tiers,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->copyRequest());

        self::assertSame(200, $response->getStatus());
        self::assertSame($payload, end($this->providerBodies));
        $copied = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($copied);
        self::assertSame('STANDARD', $copied->storageTier);
        self::assertStringStartsWith('.opsfour/write-attempts/target/', $copied->systemMetadata['storagePath']);
        self::assertSame($payload, $warm->getObjectByPath($source->path)->read());
        try {
            $warm->getObjectByPath($old->path);
            self::fail('The replaced non-AWS Copy destination was not immediately discarded.');
        } catch (\OpsFour\S3Server\Exception\NoSuchKeyException) {
        }
    }

    public function test_copy_preserves_primary_source_failure_when_scratch_release_fails(): void
    {
        $payload = str_repeat('c', 65_536);
        $sourceBackend = $this->createMock(StorageBackend::class);
        $sourceBackend->expects(self::once())->method('getObjectByPath')->willReturn(
            new AwsS3TestReadableStream([$payload], function (int $read): void {
                if ($read === 2) {
                    $this->corruptScratchBudgetState();
                }
            }),
        );
        $this->metadata->putObjectMetadata(
            'bucket',
            'key',
            'owner',
            strlen($payload),
            '"' . md5($payload) . '"',
            'text/plain',
            'mock://bucket/key',
            checksumSha256: base64_encode(str_repeat("\0", 32)),
        );
        $this->metadata->updateObjectPlacement('bucket', 'key', null, 'STANDARD', 'WARM', 'mock://bucket/key');
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->backend, defaultWriteTier: true),
            new StorageTier('WARM', $sourceBackend),
        ]);

        try {
            (new CopyObjectHandler(
                $this->metadata,
                $this->backend,
                storageTiers: $tiers,
                awsObjectWriter: $this->writer,
            ))->handleRequest($this->copyRequest());
            self::fail('The invalid source checksum was accepted.');
        } catch (InternalErrorException $error) {
            self::assertSame('Copy source checksum does not match its payload.', $error->getMessage());
        }

        self::assertCount(2, $this->requests);
        self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
    }

    public function test_runtime_factory_propagates_its_writer_through_the_registrar_without_reusing_post_budget(): void
    {
        $metadata = new SqliteMetadataStore($this->base . '/runtime.sqlite');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $metadata->createBucket('owner', 'target', 'us-east-1');
        $factoryBackend = $this->factoryBackend();
        $runtime = (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(storagePath: $this->base, postSpoolBudgetBytes: 1),
            metadata: $metadata,
            storage: $factoryBackend,
            credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
        );
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        try {
            $registry = $runtime->server->getHandlerRegistry();
            $put = $registry->get(S3Operation::PutObject);
            self::assertInstanceOf(PutObjectHandler::class, $put);
            $sourcePayload = 'larger than one byte';
            self::assertSame(200, $put->handleRequest($this->putRequest($sourcePayload))->getStatus());
            $object = $metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);
            self::assertNull($metadata->getObjectWriteIntent(basename($object->systemMetadata['storagePath'])));

            $this->mock->append(new Response(200, ['ETag' => '"post-etag"']));
            $postRequest = $this->putRequest('registered POST payload');
            $postRequest->setAttribute('s3.key', 'posted');
            $postRequest->setAttribute('s3.operation', S3Operation::PostObject);
            $postRequest->setAttribute(PostObjectForm::class, new PostObjectForm(
                fields: ['key' => 'posted'],
                key: 'posted',
                filename: 'upload.txt',
                filePath: '/unused',
                fileSize: 23,
                spoolKey: str_repeat('r', 32),
                credential: null,
                policy: null,
            ));
            $post = $registry->get(S3Operation::PostObject);
            self::assertInstanceOf(PostObjectHandler::class, $post);
            self::assertSame(204, $post->handleRequest($postRequest)->getStatus());
            $posted = $metadata->getObjectMetadata('bucket', 'posted');
            self::assertNotNull($posted);
            self::assertNull($metadata->getObjectWriteIntent(basename($posted->systemMetadata['storagePath'])));

            $sourceAttempt = basename($object->systemMetadata['storagePath']);
            $this->mock->append(new Response(200, [
                'Content-Length' => (string) strlen($sourcePayload),
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
            ], $sourcePayload));
            $this->mock->append(new Response(200, ['ETag' => '"copy-etag"']));
            $copy = $registry->get(S3Operation::CopyObject);
            self::assertInstanceOf(CopyObjectHandler::class, $copy);
            self::assertSame(200, $copy->handleRequest($this->copyRequest())->getStatus());
            $copied = $metadata->getObjectMetadata('target', 'copied');
            self::assertNotNull($copied);
            self::assertNull($metadata->getObjectWriteIntent(basename($copied->systemMetadata['storagePath'])));
        } finally {
            $runtime->stop();
        }
    }

    public function test_runtime_owns_the_exact_registered_writer_and_shuts_down_its_stager(): void
    {
        $metadata = new SqliteMetadataStore($this->base . '/runtime-lifecycle.sqlite');
        $metadata->initialize();
        $metadata->createBucket('owner', 'bucket', 'us-east-1');
        $backend = $this->factoryBackend();
        $runtime = (new S3ServerRuntimeFactory())->create(
            config: new S3ServerConfig(storagePath: $this->base),
            metadata: $metadata,
            storage: $backend,
            credentialProvider: new InMemoryCredentialProvider(new Credential('key', 'secret', 'owner')),
            logger: new NullLogger(),
            metrics: new MetricsCollector(),
        );
        $runtimeWriterProperty = new \ReflectionProperty($runtime, 'awsObjectWriter');
        $runtimeWriter = $runtimeWriterProperty->getValue($runtime);
        self::assertInstanceOf(AwsS3ForegroundObjectWriter::class, $runtimeWriter);
        $accessLogs = (new \ReflectionProperty($runtime->server, 'accessLogs'))->getValue($runtime->server);
        self::assertInstanceOf(\OpsFour\S3Server\Logging\AccessLogWriter::class, $accessLogs);
        self::assertSame(
            $runtimeWriter,
            (new \ReflectionProperty($accessLogs, 'awsWriter'))->getValue($accessLogs),
            'The factory-created server must wire its exact owned AWS writer into access-log delivery.',
        );
        $put = $runtime->server->getHandlerRegistry()->get(S3Operation::PutObject);
        self::assertInstanceOf(PutObjectHandler::class, $put);
        $handlerWriter = (new \ReflectionProperty($put, 'awsObjectWriter'))->getValue($put);
        self::assertSame($runtimeWriter, $handlerWriter);
        foreach ([
            [S3Operation::CreateMultipartUpload, CreateMultipartUploadHandler::class],
            [S3Operation::UploadPart, UploadPartHandler::class],
            [S3Operation::UploadPartCopy, UploadPartCopyHandler::class],
            [S3Operation::CompleteMultipartUpload, CompleteMultipartUploadHandler::class],
        ] as [$operation, $handlerClass]) {
            $multipart = $runtime->server->getHandlerRegistry()->get($operation);
            self::assertInstanceOf($handlerClass, $multipart);
            self::assertSame(
                $runtimeWriter,
                (new \ReflectionProperty($multipart, 'awsObjectWriter'))->getValue($multipart),
            );
        }
        $stager = (new \ReflectionProperty($runtimeWriter, 'stager'))->getValue($runtimeWriter);
        self::assertFalse((new \ReflectionProperty($stager, 'shuttingDown'))->getValue($stager));

        $runtime->stop();

        self::assertNull($runtimeWriterProperty->getValue($runtime));
        self::assertTrue((new \ReflectionProperty($stager, 'shuttingDown'))->getValue($stager));
    }

    public function test_reservation_is_durable_before_the_first_request_body_read(): void
    {
        $observed = null;
        $body = new AwsS3TestReadableStream(['payload'], function (int $read) use (&$observed): void {
            if ($read !== 1) {
                return;
            }
            $observed = $this->onlyOutstandingIntent();
            self::assertNull($observed->dispatchId);
            self::assertGreaterThan(0.0, $observed->leaseUntil);
        });
        $request = $this->putRequestWithBody($body, 7);
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));

        self::assertSame(200, $this->put()->handleRequest($request)->getStatus());
        self::assertNotNull($observed);
    }

    public function test_slow_staging_renews_the_exact_undispatched_reservation_on_a_bounded_cadence(): void
    {
        self::assertTrue(mkdir($this->base . '/renew-scratch', 0700));
        $renewingWriter = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/renew-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $initialLease = null;
        $providerLease = null;
        $body = new AwsS3TestReadableStream(['slow ', 'payload'], function (int $read) use (&$initialLease): void {
            if ($read === 1) {
                $initialLease = $this->onlyOutstandingIntent()->leaseUntil;
            } elseif ($read === 2) {
                \Amp\delay(0.03);
            }
        });
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$providerLease): void {
            if ($request->getMethod() === 'PUT') {
                $providerLease = $this->onlyOutstandingIntent()->leaseUntil;
            }
        };
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        try {
            $handler = new PutObjectHandler($this->metadata, $this->backend, awsObjectWriter: $renewingWriter);
            self::assertSame(200, $handler->handleRequest($this->putRequestWithBody($body, 12))->getStatus());
            self::assertIsFloat($initialLease);
            self::assertIsFloat($providerLease);
            self::assertGreaterThan($initialLease, $providerLease);
        } finally {
            $renewingWriter->shutdown();
        }
    }

    public function test_sse_preprocessing_renews_the_same_undispatched_reservation(): void
    {
        self::assertTrue(mkdir($this->base . '/sse-renew-scratch', 0700));
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/sse-renew-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $initialLease = null;
        $providerLease = null;
        $body = new AwsS3TestReadableStream(['slow ', 'secret'], function (int $read) use (&$initialLease): void {
            if ($read === 1) {
                $initialLease = $this->onlyOutstandingIntent()->leaseUntil;
            } elseif ($read === 2) {
                \Amp\delay(0.03);
            }
        });
        $request = $this->putRequestWithBody($body, 11);
        $request->setHeader('x-amz-server-side-encryption', 'AES256');
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$providerLease): void {
            if ($request->getMethod() === 'PUT') {
                $providerLease = $this->onlyOutstandingIntent()->leaseUntil;
            }
        };
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        try {
            $handler = new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $writer);
            self::assertSame(200, $handler->handleRequest($request)->getStatus());
            self::assertIsFloat($initialLease);
            self::assertIsFloat($providerLease);
            self::assertGreaterThan($initialLease, $providerLease);
        } finally {
            $writer->shutdown();
        }
    }

    public function test_yielding_sse_transform_is_renewed_before_the_worker_returns(): void
    {
        self::assertTrue(mkdir($this->base . '/sse-transform-scratch', 0700));
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/sse-transform-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $renewedWhileYielding = false;
        $encryption = new AwsS3YieldingEncryptionService(
            new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32)))),
            function () use (&$renewedWhileYielding): void {
                $before = $this->onlyOutstandingIntent()->leaseUntil;
                \Amp\delay(0.03);
                $renewedWhileYielding = $this->onlyOutstandingIntent()->leaseUntil > $before;
            },
        );
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        try {
            $handler = new PutObjectHandler(
                $this->metadata,
                $this->backend,
                encryption: $encryption,
                awsObjectWriter: $writer,
            );
            self::assertSame(200, $handler->handleRequest($this->putRequest('secret', [
                'x-amz-server-side-encryption' => 'AES256',
            ]))->getStatus());
            self::assertTrue($renewedWhileYielding);
        } finally {
            $writer->shutdown();
        }
    }

    public function test_shared_yielding_transform_boundary_reports_lost_renewal_authority(): void
    {
        self::assertTrue(mkdir($this->base . '/transform-authority-scratch', 0700));
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/transform-authority-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $reservation = $writer->reserve($bucket, 'lost-transform', 'owner');
        try {
            try {
                $writer->transform($reservation, function (): void {
                    $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
                    self::assertSame(1, $pdo->exec('UPDATE s3_object_write_intents SET lease_until = 0'));
                    \Amp\delay(0.03);
                });
                self::fail('A yielding transform retained authority after its renewal CAS was lost.');
            } catch (\OpsFour\S3Server\Storage\AwsS3WriteAttemptArmException) {
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'lost-transform'));
            }
        } finally {
            $writer->retireBeforeDispatch($reservation);
            $writer->shutdown();
        }
        self::assertCount(2, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
    }

    public function test_yielding_put_transform_authority_loss_prevents_provider_dispatch_and_visibility(): void
    {
        self::assertTrue(mkdir($this->base . '/put-transform-authority-scratch', 0700));
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/put-transform-authority-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $encryption = new AwsS3YieldingEncryptionService(
            new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32)))),
            function (): void {
                $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
                self::assertSame(1, $pdo->exec('UPDATE s3_object_write_intents SET lease_until = 0'));
                \Amp\delay(0.03);
            },
        );
        try {
            try {
                (new PutObjectHandler(
                    $this->metadata,
                    $this->backend,
                    encryption: $encryption,
                    awsObjectWriter: $writer,
                ))->handleRequest($this->putRequest('secret', [
                    'x-amz-server-side-encryption' => 'AES256',
                ]));
                self::fail('Lost transform-renewal authority reached provider dispatch.');
            } catch (\OpsFour\S3Server\Storage\AwsS3WriteAttemptArmException) {
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
            }
            self::assertCount(2, $this->requests);
            self::assertSame(0, $this->outstandingIntentCount());
            self::assertSame([], glob($this->base . '/put-transform-authority-scratch/s3fw-put-*'));
        } finally {
            $writer->shutdown();
        }
    }

    public function test_sse_buffer_capacity_is_released_before_provider_dispatch(): void
    {
        $capacityReleased = false;
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$capacityReleased): void {
            if ($request->getMethod() !== 'PUT') {
                return;
            }
            $lock = BufferedWorkLimiter::acquire();
            $capacityReleased = true;
            $lock->release();
        };
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $handler = new PutObjectHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        );

        self::assertSame(200, $handler->handleRequest($this->putRequest('secret', [
            'x-amz-server-side-encryption' => 'AES256',
        ]))->getStatus());
        self::assertTrue($capacityReleased);
    }

    public function test_sse_buffer_capacity_is_acquired_before_aws_reservation(): void
    {
        $lock = BufferedWorkLimiter::acquire();
        $request = $this->putRequest('secret', ['x-amz-server-side-encryption' => 'AES256']);
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        try {
            $handler = new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer);
            try {
                $handler->handleRequest($request);
                self::fail('Busy buffered-work capacity must reject before AWS reservation.');
            } catch (SlowDownException) {
                self::assertSame(0, $this->outstandingIntentCount());
                self::assertCount(2, $this->requests);
            }
        } finally {
            $lock->release();
        }
    }

    public function test_undispatched_renewal_failure_aborts_before_provider_io_and_retires_safely(): void
    {
        self::assertTrue(mkdir($this->base . '/renew-failure-scratch', 0700));
        $renewingWriter = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/renew-failure-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $body = new AwsS3TestReadableStream(['slow ', 'payload'], function (int $read): void {
            if ($read !== 2) {
                return;
            }
            $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
            self::assertSame(1, $pdo->exec('UPDATE s3_object_write_intents SET lease_until = 0'));
            \Amp\delay(0.03);
        });
        try {
            $handler = new PutObjectHandler($this->metadata, $this->backend, awsObjectWriter: $renewingWriter);
            try {
                $handler->handleRequest($this->putRequestWithBody($body, 12));
                self::fail('Lost undispatched renewal authority must abort before provider I/O.');
            } catch (\OpsFour\S3Server\Storage\AwsS3WriteAttemptArmException) {
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
            }
            self::assertCount(2, $this->requests);
            self::assertSame(0, $this->outstandingIntentCount());
        } finally {
            $renewingWriter->shutdown();
        }
    }

    public function test_handlers_reject_a_writer_bound_to_another_intent_store(): void
    {
        $other = new SqliteMetadataStore($this->base . '/other.sqlite');
        $other->initialize();

        foreach ([PutObjectHandler::class, CopyObjectHandler::class] as $handlerClass) {
            try {
                new $handlerClass($other, $this->backend, awsObjectWriter: $this->writer);
                self::fail('A handler accepted an AWS writer bound to another intent store.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_handlers_reject_a_writer_from_another_backend_even_when_the_admitted_profile_is_shared(): void
    {
        $property = new \ReflectionProperty(AwsS3StorageBackend::class, 'binding');
        $binding = $property->getValue($this->backend);
        self::assertInstanceOf(AwsS3ProviderValidatedBinding::class, $binding);
        $otherBackend = new AwsS3StorageBackend($binding->admission, $binding);
        self::assertTrue(mkdir($this->base . '/other-backend-scratch', 0700));
        $otherWriter = $otherBackend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/other-backend-scratch',
            'STANDARD',
        );
        try {
            foreach ([PutObjectHandler::class, CopyObjectHandler::class] as $handlerClass) {
                try {
                    new $handlerClass($this->metadata, $this->backend, awsObjectWriter: $otherWriter);
                    self::fail('A handler accepted an AWS writer from another backend.');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            $otherWriter->shutdown();
        }
    }

    public function test_handlers_reject_a_writer_with_a_different_tier_name(): void
    {
        self::assertTrue(mkdir($this->base . '/other-tier-scratch', 0700));
        $otherTierWriter = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/other-tier-scratch',
            'ARCHIVE',
        );
        try {
            foreach ([PutObjectHandler::class, CopyObjectHandler::class] as $handlerClass) {
                try {
                    new $handlerClass($this->metadata, $this->backend, awsObjectWriter: $otherTierWriter);
                    self::fail('A handler accepted an AWS writer for another tier name.');
                } catch (\InvalidArgumentException) {
                    self::addToAssertionCount(1);
                }
            }
        } finally {
            $otherTierWriter->shutdown();
        }
    }

    public function test_known_oversized_sse_put_rejects_before_buffer_and_intent_capacity(): void
    {
        $lock = BufferedWorkLimiter::acquire();
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $handler = new PutObjectHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            maxEncryptedObjectSize: 4,
            awsObjectWriter: $this->writer,
        );
        try {
            foreach ([
                [],
                [
                    'x-amz-content-sha256' => 'STREAMING-AWS4-HMAC-SHA256-PAYLOAD',
                    'x-amz-decoded-content-length' => '5',
                ],
            ] as $headers) {
                try {
                    $handler->handleRequest($this->putRequest('12345', [
                        'x-amz-server-side-encryption' => 'AES256',
                    ] + $headers));
                    self::fail('A known oversized SSE payload must be rejected before resource admission.');
                } catch (EntityTooLargeException) {
                    self::assertSame(0, $this->outstandingIntentCount());
                }
            }
            self::assertCount(2, $this->requests);
        } finally {
            $lock->release();
        }
    }

    public function test_bad_client_checksum_rejects_before_provider_dispatch_and_retires_reservation(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        try {
            $this->put()->handleRequest($this->putRequest('payload', [
                'x-amz-checksum-sha256' => base64_encode(str_repeat('x', 32)),
            ]));
            self::fail('Expected checksum rejection.');
        } catch (BadDigestException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertCount(2, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
        self::assertSame([], glob($this->base . '/scratch/s3fw-put-*'));
    }

    public function test_declared_length_mismatch_rejects_before_provider_dispatch(): void
    {
        try {
            $this->put()->handleRequest($this->putRequestWithBody(
                new AwsS3TestReadableStream(['payload'], static function (): void {}),
                8,
            ));
            self::fail('Expected declared-length rejection.');
        } catch (\OpsFour\S3Server\Exception\IncompleteBodyException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertCount(2, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
        self::assertSame([], glob($this->base . '/scratch/s3fw-put-*'));
    }

    public function test_unknown_length_uses_exact_staged_size_for_quota_before_provider_dispatch(): void
    {
        $provider = $this->createMock(QuotaProvider::class);
        $provider->method('quotaForOwner')->willReturn(new QuotaConfig(maxBytesPerBucket: 6));
        $provider->method('hasQuotaForOwner')->willReturn(true);
        $handler = new PutObjectHandler(
            $this->metadata,
            $this->backend,
            quotas: new QuotaManager($this->metadata, $provider),
            awsObjectWriter: $this->writer,
        );

        try {
            $handler->handleRequest($this->putRequestWithoutLength('payload'));
            self::fail('An unknown request length exceeded quota after staging.');
        } catch (QuotaExceededException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertCount(2, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
        self::assertSame([], glob($this->base . '/scratch/s3fw-put-*'));
    }

    public function test_post_commit_scratch_cleanup_failure_does_not_replace_success(): void
    {
        $corrupted = false;
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$corrupted): void {
            if ($request->getMethod() !== 'PUT') {
                return;
            }
            $states = glob($this->base . '/scratch/.opsfour-s3-storage-budget/state-*');
            self::assertIsArray($states);
            self::assertCount(1, $states);
            self::assertNotFalse(file_put_contents($states[0], '{corrupt'));
            $corrupted = true;
        };
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));

        $response = $this->put()->handleRequest($this->putRequest('committed payload'));

        self::assertTrue($corrupted);
        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertNull($this->metadata->getObjectWriteIntent(basename($object->systemMetadata['storagePath'])));

        $this->beforeProviderRequest = null;
        $this->mock->append(new Response(200, ['ETag' => '"next-provider-etag"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest('next payload'))->getStatus());
        self::assertSame('"' . md5('next payload') . '"', $this->metadata->getObjectMetadata('bucket', 'key')?->etag);
    }

    public function test_handlers_reject_simultaneous_local_and_aws_coordinators(): void
    {
        $local = (new \ReflectionClass(LocalFencedObjectWriter::class))->newInstanceWithoutConstructor();
        $leases = new class implements LocalObjectWriteLeaseGuardFactory {
            public function reserve(\OpsFour\S3Server\Metadata\ObjectWriteIntent $planned): LocalObjectWriteLeaseGuard
            {
                throw new \LogicException('Not reached.');
            }
        };

        foreach ([PutObjectHandler::class, CopyObjectHandler::class] as $handler) {
            try {
                new $handler(
                    $this->metadata,
                    $this->backend,
                    localFencedWriter: $local,
                    localLeaseFactory: $leases,
                    awsObjectWriter: $this->writer,
                );
                self::fail('Handler accepted simultaneous local and AWS coordinators.');
            } catch (\InvalidArgumentException) {
                self::addToAssertionCount(1);
            }
        }
    }

    public function test_conflict_and_ambiguous_provider_results_leave_armed_recovery_evidence(): void
    {
        foreach ([
            new Response(412, [], '<Error><Code>PreconditionFailed</Code></Error>'),
            new Response(503, [], '<Error><Code>ServiceUnavailable</Code></Error>'),
        ] as $response) {
            $this->mock->append($response);
            try {
                $this->put()->handleRequest($this->putRequest('payload'));
                self::fail('Unconfirmed publication became visible.');
            } catch (InternalErrorException) {
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
            }
            $request = end($this->requests);
            self::assertInstanceOf(RequestInterface::class, $request);
            self::assertNotNull($this->metadata->getObjectWriteIntent(basename($request->getUri()->getPath()))?->dispatchId);
        }
    }

    public function test_same_tier_copy_reads_exact_source_and_publishes_once(): void
    {
        $payload = 'copy source';
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $sourceAttempt = basename($source->systemMetadata['storagePath']);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
        ], $payload));
        $this->mock->append(new Response(200, ['ETag' => '"provider-destination"']));
        $request = new Request(
            $this->createMock(ServerClient::class),
            'PUT',
            Http::new('http://127.0.0.1/target/copied'),
            ['x-amz-copy-source' => '/bucket/key'],
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');
        $response = (new CopyObjectHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer))->handleRequest($request);
        self::assertSame(200, $response->getStatus());
        $copy = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($copy);
        self::assertSame('"' . md5($payload) . '"', $copy->etag);
        self::assertNull($this->metadata->getObjectWriteIntent(basename($copy->systemMetadata['storagePath'])));
        self::assertSame(['HEAD', 'GET', 'PUT', 'GET', 'PUT'], array_map(static fn(RequestInterface $provider): string => $provider->getMethod(), $this->requests));
        self::assertSame('123456789012', $this->requests[3]->getHeaderLine('x-amz-expected-bucket-owner'));
    }

    public function test_versioned_copy_consumes_exact_dispatch_and_stores_immutable_attempt_path(): void
    {
        $payload = 'versioned copy source';
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $sourceAttempt = basename($source->systemMetadata['storagePath']);
        $this->metadata->setBucketVersioning('target', 'Enabled');
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
        ], $payload));
        $this->mock->append(new Response(200, ['ETag' => '"provider-destination"']));

        $response = $this->copy()->handleRequest($this->copyRequest());

        self::assertSame(200, $response->getStatus());
        $versionId = $response->getHeader('x-amz-version-id');
        self::assertNotNull($versionId);
        self::assertNotSame('null', $versionId);
        $current = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($current);
        self::assertSame($versionId, $current->versionId);
        $version = $this->metadata->getObjectMetadataByVersion('target', 'copied', $versionId);
        self::assertNotNull($version);
        $path = $version->systemMetadata['storagePath'];
        self::assertSame($path, $current->systemMetadata['storagePath']);
        self::assertStringStartsWith('.opsfour/write-attempts/target/', $path);
        self::assertSame($path, ltrim(rawurldecode($this->requests[4]->getUri()->getPath()), '/'));
        self::assertNull($this->metadata->getObjectWriteIntent(basename($path)));
    }

    public function test_terminal_rejection_retires_only_the_exact_armed_attempt(): void
    {
        $this->mock->append(new Response(403, [], '<Error><Code>AccessDenied</Code></Error>'));
        try {
            $this->put()->handleRequest($this->putRequest('payload'));
            self::fail('A provider refusal must not publish metadata.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertNull($this->metadata->getObjectWriteIntent(basename($this->requests[2]->getUri()->getPath())));
        self::assertCount(3, $this->requests);
    }

    public function test_staging_capacity_failure_prevents_provider_request_and_retires_reservation(): void
    {
        try {
            $this->put()->handleRequest($this->putRequest(str_repeat('x', 1_048_577)));
            self::fail('The scratch budget must reject this payload.');
        } catch (TaskFailureException $error) {
            self::assertStringContainsString('Storage staging budget is full', $error->getMessage());
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertCount(2, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
        self::assertSame([], glob($this->base . '/scratch/s3fw-put-*'));
    }

    public function test_commit_guard_failure_retains_confirmed_attempt_without_visibility(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        $this->beforeProviderRequest = function (RequestInterface $request): void {
            if ($request->getMethod() === 'PUT') {
                $this->metadata->putBucketEncryption('bucket', 'AES256');
            }
        };
        try {
            $this->put()->handleRequest($this->putRequest('payload'));
            self::fail('Changed bucket encryption must abort metadata commit.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertNotNull($this->metadata->getObjectWriteIntent(basename($this->requests[2]->getUri()->getPath()))?->dispatchId);
        self::assertCount(3, $this->requests);
    }

    public function test_consume_cas_loss_rolls_back_metadata_and_retains_recovery_evidence(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        $attemptId = null;
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$attemptId): void {
            if ($request->getMethod() !== 'PUT') {
                return;
            }
            $attemptId = basename($request->getUri()->getPath());
            self::assertTrue($this->metadata->abandonObjectWriteIntent($attemptId));
        };

        try {
            $this->put()->handleRequest($this->putRequest('payload'));
            self::fail('A lost consume CAS must reject metadata publication.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }

        self::assertIsString($attemptId);
        self::assertNotNull($this->metadata->getObjectWriteIntent($attemptId));
        self::assertCount(3, $this->requests);
    }

    public function test_sse_s3_and_sse_c_put_stage_ciphertext_but_commit_plaintext_checksums(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $key = random_bytes(32);
        foreach ([
            ['x-amz-server-side-encryption' => 'AES256'],
            [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode($key),
                'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($key, true)),
            ],
        ] as $index => $headers) {
            $payload = 'secret payload ' . $index;
            $headers['x-amz-checksum-sha256'] = base64_encode(hash('sha256', $payload, true));
            $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
            $handler = new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer);
            self::assertSame(200, $handler->handleRequest($this->putRequest($payload, $headers))->getStatus());
            $object = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);
            self::assertSame('"' . md5($payload) . '"', $object->etag);
            self::assertSame($headers['x-amz-checksum-sha256'], $object->systemMetadata['checksum-sha256']);
            $ciphertext = end($this->providerBodies);
            self::assertIsString($ciphertext);
            self::assertNotSame($payload, $ciphertext);
            $plaintext = $index === 0
                ? $encryption->decryptSseS3($ciphertext, $object->userMetadata['__sse-key'], $object->userMetadata['__sse-iv'], $object->userMetadata['__sse-tag'])
                : $encryption->decryptSseC($ciphertext, $key, $object->userMetadata['__sse-iv'], $object->userMetadata['__sse-tag']);
            self::assertSame($payload, $plaintext);
        }
    }

    public function test_browser_post_delegates_through_the_same_aws_fence(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-etag"']));
        $payload = 'browser payload';
        $request = $this->putRequest($payload);
        $request->setAttribute('s3.operation', S3Operation::PostObject);
        $request->setAttribute(PostObjectForm::class, new PostObjectForm(
            fields: ['key' => 'key'],
            key: 'key',
            filename: 'upload.txt',
            filePath: '/unused',
            fileSize: strlen($payload),
            spoolKey: str_repeat('p', 32),
            credential: null,
            policy: null,
        ));

        $response = (new PostObjectHandler($this->metadata, $this->put()))->handleRequest($request);

        self::assertSame(204, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('"' . md5($payload) . '"', $object->etag);
        self::assertNull($this->metadata->getObjectWriteIntent(basename($object->systemMetadata['storagePath'])));
        self::assertCount(3, $this->requests);
    }

    public function test_invalid_copy_source_marker_rejects_before_destination_dispatch(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest('payload'))->getStatus());
        $this->mock->append(new Response(200, ['Content-Length' => '7', 'x-amz-meta-opsfour-write-attempt' => 'v2'], 'payload'));
        try {
            $this->copy()->handleRequest($this->copyRequest());
            self::fail('Missing attempt marker must reject copy.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
        self::assertCount(4, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
    }

    public function test_foreign_logical_copy_source_path_rejects_before_provider_io(): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest('payload'))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        $bucket = $this->metadata->getBucket('bucket');
        $profile = $this->backend->admittedProfile();
        self::assertNotNull($source);
        self::assertNotNull($bucket);
        self::assertNotNull($profile);
        $foreignPath = AwsS3WriteAttemptPath::forProfileObject(
            $profile,
            'bucket',
            'foreign-key',
            $bucket->generation,
            $source->storageTier,
            basename($source->systemMetadata['storagePath']),
        );
        $this->metadata->updateObjectPlacement(
            'bucket',
            'key',
            $source->versionId,
            $source->storageClass,
            $source->storageTier,
            $foreignPath,
        );

        try {
            $this->copy()->handleRequest($this->copyRequest());
            self::fail('A foreign logical source path must be rejected before provider I/O.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
        self::assertCount(3, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
    }

    public function test_copy_commit_guard_failure_retains_armed_destination_without_visibility(): void
    {
        $this->preparePlainCopySource('copy guard');
        $this->beforeProviderRequest = function (RequestInterface $request): void {
            if ($request->getMethod() === 'PUT' && str_contains(rawurldecode($request->getUri()->getPath()), '/target/')) {
                $this->metadata->putBucketEncryption('target', 'AES256');
            }
        };
        try {
            $this->copy()->handleRequest($this->copyRequest());
            self::fail('Changed destination encryption must abort CopyObject metadata commit.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
        $attempt = basename($this->requests[4]->getUri()->getPath());
        self::assertNotNull($this->metadata->getObjectWriteIntent($attempt)?->dispatchId);
    }

    public function test_copy_consume_cas_loss_rolls_back_metadata_and_retains_destination_evidence(): void
    {
        $this->preparePlainCopySource('copy cas');
        $attempt = null;
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$attempt): void {
            if ($request->getMethod() === 'PUT' && str_contains(rawurldecode($request->getUri()->getPath()), '/target/')) {
                $attempt = basename($request->getUri()->getPath());
                self::assertTrue($this->metadata->abandonObjectWriteIntent($attempt));
            }
        };
        try {
            $this->copy()->handleRequest($this->copyRequest());
            self::fail('Lost CopyObject consume CAS must abort metadata publication.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
        self::assertIsString($attempt);
        self::assertNotNull($this->metadata->getObjectWriteIntent($attempt));
    }

    #[DataProvider('invalidCopySourceProvider')]
    public function test_copy_source_boundary_failures_reject_and_retire_the_undispatched_destination(
        string $kind,
    ): void {
        $payload = 'boundary';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $attempt = basename($source->systemMetadata['storagePath']);
        $response = match ($kind) {
            'length' => new Response(200, [
                'Content-Length' => '9',
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $attempt,
            ], $payload),
            'short' => new Response(200, [
                'Content-Length' => '8',
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $attempt,
            ], 'short'),
            'excess' => new Response(200, [
                'Content-Length' => '8',
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $attempt,
            ], $payload . 'extra'),
            'attempt' => new Response(200, [
                'Content-Length' => '8',
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => str_repeat('f', 32),
            ], $payload),
            'get' => new Response(503, [], '<Error><Code>ServiceUnavailable</Code></Error>'),
            default => throw new \LogicException('Unknown boundary case.'),
        };
        $this->mock->append($response);

        try {
            $this->copy()->handleRequest($this->copyRequest());
            self::fail('Invalid AWS CopyObject source evidence was accepted.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
        self::assertSame(0, $this->outstandingIntentCount());
        self::assertCount(4, $this->requests);
    }

    public function test_same_length_copy_source_corruption_rejects_before_destination_dispatch(): void
    {
        $payload = 'original';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $attempt = basename($source->systemMetadata['storagePath']);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attempt,
        ], 'corrupt!'));

        try {
            $this->copy()->handleRequest($this->copyRequest());
            self::fail('Same-length source corruption reached destination dispatch.');
        } catch (InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
        }
        self::assertCount(4, $this->requests);
        self::assertSame(0, $this->outstandingIntentCount());
    }

    /** @return iterable<string, array{string}> */
    public static function invalidCopySourceProvider(): iterable
    {
        foreach (['length', 'short', 'excess', 'attempt', 'get'] as $kind) {
            yield $kind => [$kind];
        }
    }

    public function test_sse_s3_and_sse_c_copy_use_verified_ciphertext_source_length(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $key = random_bytes(32);
        foreach ([
            ['x-amz-server-side-encryption' => 'AES256'],
            [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode($key),
                'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($key, true)),
            ],
        ] as $index => $headers) {
            $payload = 'copy secret ' . $index;
            $this->mock->append(new Response(200, ['ETag' => '"source"']));
            $put = new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer);
            self::assertSame(200, $put->handleRequest($this->putRequest($payload, $headers))->getStatus());
            $source = $this->metadata->getObjectMetadata('bucket', 'key');
            self::assertNotNull($source);
            $ciphertext = end($this->providerBodies);
            self::assertIsString($ciphertext);
            self::assertSame(strlen($payload), strlen($ciphertext));
            $sourceAttempt = basename($source->systemMetadata['storagePath']);
            $this->mock->append(new Response(200, [
                'Content-Length' => (string) strlen($ciphertext),
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
            ], $ciphertext));
            $this->mock->append(new Response(200, ['ETag' => '"copy"']));
            $copyHeaders = $index === 0 ? [] : [
                'x-amz-copy-source-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-copy-source-server-side-encryption-customer-key' => base64_encode($key),
                'x-amz-copy-source-server-side-encryption-customer-key-md5' => base64_encode(md5($key, true)),
            ];
            self::assertSame(200, (new CopyObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer))
                ->handleRequest($this->copyRequest($copyHeaders))->getStatus());
            $copy = $this->metadata->getObjectMetadata('target', 'copied');
            self::assertNotNull($copy);
            self::assertSame('"' . md5($payload) . '"', $copy->etag);
            self::assertSame($payload, end($this->providerBodies));
        }
    }

    public function test_sse_s3_to_sse_c_copy_commits_verified_encrypted_destination(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $payload = 'encrypted source and destination';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, (new PutObjectHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->putRequest($payload, [
            'x-amz-server-side-encryption' => 'AES256',
        ]))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $sourceCiphertext = end($this->providerBodies);
        self::assertIsString($sourceCiphertext);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($sourceCiphertext),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => basename($source->systemMetadata['storagePath']),
        ], $sourceCiphertext));
        $this->mock->append(new Response(200, ['ETag' => '"destination"']));

        $capacityReleased = false;
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$capacityReleased): void {
            if ($request->getMethod() !== 'PUT'
                || ! str_contains(rawurldecode($request->getUri()->getPath()), '/target/')) {
                return;
            }
            $lock = BufferedWorkLimiter::acquire();
            $capacityReleased = true;
            $lock->release();
        };
        $customerKey = random_bytes(32);
        $customerKeyMd5 = base64_encode(md5($customerKey, true));
        $response = (new CopyObjectHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->copyRequest([
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
            'x-amz-server-side-encryption-customer-key-MD5' => $customerKeyMd5,
        ]));

        self::assertSame(200, $response->getStatus());
        self::assertTrue($capacityReleased);
        $destination = $this->metadata->getObjectMetadata('target', 'copied');
        self::assertNotNull($destination);
        self::assertSame('"' . md5($payload) . '"', $destination->etag);
        self::assertSame('SSE-C', $destination->userMetadata['__sse-algorithm']);
        self::assertSame($customerKeyMd5, $destination->userMetadata['__sse-customer-key-md5']);
        $destinationCiphertext = end($this->providerBodies);
        self::assertIsString($destinationCiphertext);
        self::assertNotSame($payload, $destinationCiphertext);
        self::assertSame(
            $payload,
            $encryption->decryptSseC(
                $destinationCiphertext,
                $customerKey,
                $destination->userMetadata['__sse-iv'],
                $destination->userMetadata['__sse-tag'],
            ),
        );
    }

    public function test_encrypted_copy_preprocessing_renews_the_same_destination_reservation(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $payload = 'renew encrypted copy';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        $put = new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer);
        self::assertSame(200, $put->handleRequest($this->putRequest($payload, [
            'x-amz-server-side-encryption' => 'AES256',
        ]))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $ciphertext = end($this->providerBodies);
        self::assertIsString($ciphertext);
        $sourceAttempt = basename($source->systemMetadata['storagePath']);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($ciphertext),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
        ], $ciphertext));
        $this->mock->append(new Response(200, ['ETag' => '"destination"']));
        self::assertTrue(mkdir($this->base . '/copy-renew-scratch', 0700));
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/copy-renew-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $sourceLease = null;
        $providerLease = null;
        $this->beforeProviderRequest = function (RequestInterface $request) use (&$sourceLease, &$providerLease): void {
            if ($request->getMethod() === 'GET') {
                $sourceLease = $this->onlyOutstandingIntent()->leaseUntil;
                \Amp\delay(0.03);
            } elseif ($request->getMethod() === 'PUT'
                && str_contains(rawurldecode($request->getUri()->getPath()), '/target/')) {
                $providerLease = $this->onlyOutstandingIntent()->leaseUntil;
            }
        };
        try {
            $copy = new CopyObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $writer);
            self::assertSame(200, $copy->handleRequest($this->copyRequest())->getStatus());
            self::assertIsFloat($sourceLease);
            self::assertIsFloat($providerLease);
            self::assertGreaterThan($sourceLease, $providerLease);
        } finally {
            $writer->shutdown();
        }
    }

    public function test_yielding_encrypted_copy_transform_is_renewed_before_the_worker_returns(): void
    {
        $delegate = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $payload = 'yielding encrypted copy';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, (new PutObjectHandler(
            $this->metadata,
            $this->backend,
            encryption: $delegate,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->putRequest($payload, [
            'x-amz-server-side-encryption' => 'AES256',
        ]))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $ciphertext = end($this->providerBodies);
        self::assertIsString($ciphertext);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($ciphertext),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => basename($source->systemMetadata['storagePath']),
        ], $ciphertext));
        $this->mock->append(new Response(200, ['ETag' => '"destination"']));
        self::assertTrue(mkdir($this->base . '/copy-transform-scratch', 0700));
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/copy-transform-scratch',
            'STANDARD',
            1_048_576,
            36.0,
            0.01,
        );
        $renewedWhileYielding = false;
        $encryption = new AwsS3YieldingEncryptionService(
            $delegate,
            function () use (&$renewedWhileYielding): void {
                $before = $this->onlyOutstandingIntent()->leaseUntil;
                \Amp\delay(0.03);
                $renewedWhileYielding = $this->onlyOutstandingIntent()->leaseUntil > $before;
            },
        );
        try {
            $copy = new CopyObjectHandler(
                $this->metadata,
                $this->backend,
                encryption: $encryption,
                awsObjectWriter: $writer,
            );
            self::assertSame(200, $copy->handleRequest($this->copyRequest())->getStatus());
            self::assertTrue($renewedWhileYielding);
        } finally {
            $writer->shutdown();
        }
    }

    public function test_encrypted_copy_buffer_capacity_is_acquired_before_destination_reservation(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        $put = new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer);
        self::assertSame(200, $put->handleRequest($this->putRequest('secret', [
            'x-amz-server-side-encryption' => 'AES256',
        ]))->getStatus());

        $lock = BufferedWorkLimiter::acquire();
        try {
            $copy = new CopyObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer);
            try {
                $copy->handleRequest($this->copyRequest());
                self::fail('Busy buffered-work capacity must reject before destination reservation.');
            } catch (SlowDownException) {
                self::assertSame(0, $this->outstandingIntentCount());
                self::assertCount(3, $this->requests);
            }
        } finally {
            $lock->release();
        }
    }

    public function test_known_oversized_encrypted_copy_rejects_before_buffer_and_intent_capacity(): void
    {
        $payload = '12345';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $copy = new CopyObjectHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            maxEncryptedObjectSize: 4,
            awsObjectWriter: $this->writer,
        );
        $lock = BufferedWorkLimiter::acquire();
        try {
            try {
                $copy->handleRequest($this->copyRequest([
                    'x-amz-server-side-encryption' => 'AES256',
                ]));
                self::fail('A known oversized encrypted Copy must reject before resource admission.');
            } catch (EntityTooLargeException) {
                self::assertNull($this->metadata->getObjectMetadata('target', 'copied'));
            }
            self::assertSame(0, $this->outstandingIntentCount());
            self::assertCount(3, $this->requests);
        } finally {
            $lock->release();
        }
    }

    public function test_copy_sse_s3_and_sse_c_destinations_publish_only_final_ciphertext(): void
    {
        $payload = 'plain copy source';
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $sourceAttempt = basename($source->systemMetadata['storagePath']);
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $customerKey = random_bytes(32);

        foreach ([
            ['x-amz-server-side-encryption' => 'AES256'],
            [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
                'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
            ],
        ] as $index => $headers) {
            $this->mock->append(new Response(200, [
                'Content-Length' => (string) strlen($payload),
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
            ], $payload));
            $this->mock->append(new Response(200, ['ETag' => '"copy"']));

            self::assertSame(200, (new CopyObjectHandler(
                $this->metadata,
                $this->backend,
                encryption: $encryption,
                awsObjectWriter: $this->writer,
            ))->handleRequest($this->copyRequest($headers))->getStatus());

            $copy = $this->metadata->getObjectMetadata('target', 'copied');
            self::assertNotNull($copy);
            self::assertSame('"' . md5($payload) . '"', $copy->etag);
            $ciphertext = end($this->providerBodies);
            self::assertIsString($ciphertext);
            self::assertNotSame($payload, $ciphertext);
            $plaintext = $index === 0
                ? $encryption->decryptSseS3(
                    $ciphertext,
                    $copy->userMetadata['__sse-key'],
                    $copy->userMetadata['__sse-iv'],
                    $copy->userMetadata['__sse-tag'],
                )
                : $encryption->decryptSseC(
                    $ciphertext,
                    $customerKey,
                    $copy->userMetadata['__sse-iv'],
                    $copy->userMetadata['__sse-tag'],
                );
            self::assertSame($payload, $plaintext);
            self::assertNull($this->metadata->getObjectWriteIntent(basename($copy->systemMetadata['storagePath'])));
        }
    }

    private function put(): PutObjectHandler
    {
        return new PutObjectHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer);
    }

    private function factoryBackend(): AwsS3StorageBackend
    {
        $this->mock->append(
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        );
        $handler = new GuzzleHandler(new Client(['handler' => HandlerStack::create($this->mock)]));
        $backend = StorageBackendFactory::create(
            'aws-s3',
            [
                'bucket' => 'backing-private',
                'region' => 'us-east-1',
                'expected_owner_id' => '123456789012',
                'data_prefix' => 'objects',
                'reserved_prefix' => '.opsfour/write-attempts',
            ],
            awsS3ProviderValidator: new AwsS3ProviderValidator(
                function (RequestInterface $request, array $options) use ($handler) {
                    ($this->beforeProviderRequest)?->__invoke($request);
                    if ($request->getMethod() === 'PUT') {
                        $this->providerBodies[] = (string) $request->getBody();
                    }
                    $this->requests[] = $request;
                    return $handler($request, $options)->then(static function ($response) use ($request) {
                        if ($request->getMethod() !== 'GET' || $response->getStatusCode() >= 300
                            || ! $response->hasHeader('x-amz-meta-opsfour-write-attempt')) {
                            return $response;
                        }
                        $range = $response->getHeaderLine('Content-Range');
                        $contentSize = preg_match('/\/(\d+)$/D', $range, $matches) === 1
                            ? $matches[1] : $response->getHeaderLine('Content-Length');

                        return $response
                            ->withHeader('x-amz-meta-opsfour-owner-digest', hash('sha256', 'owner'))
                            ->withHeader('x-amz-meta-opsfour-content-size', $contentSize);
                    });
                },
            ),
        );
        self::assertInstanceOf(AwsS3StorageBackend::class, $backend);

        return $backend;
    }

    private function copy(): CopyObjectHandler
    {
        return new CopyObjectHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer);
    }

    /** @param array<non-empty-string, string> $headers */
    private function copyRequest(array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(ServerClient::class),
            'PUT',
            Http::new('http://127.0.0.1/target/copied'),
            ['x-amz-copy-source' => '/bucket/key'] + $headers,
        );
        $request->setAttribute('s3.bucket', 'target');
        $request->setAttribute('s3.key', 'copied');
        $request->setAttribute('ownerId', 'owner');
        return $request;
    }

    /** @param array<non-empty-string, string> $headers */
    private function putRequest(string $payload, array $headers = []): Request
    {
        $request = new Request(
            $this->createMock(ServerClient::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['content-length' => (string) strlen($payload)] + $headers,
            $payload,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        return $request;
    }

    private function putRequestWithBody(ReadableStream $body, int $length): Request
    {
        $request = new Request(
            $this->createMock(ServerClient::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            ['content-length' => (string) $length],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);
        return $request;
    }

    private function putRequestWithoutLength(string $payload): Request
    {
        $request = new Request(
            $this->createMock(ServerClient::class),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key'),
            [],
            $payload,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', S3Operation::PutObject);

        return $request;
    }

    private function corruptScratchBudgetState(): void
    {
        $states = array_values(array_filter(
            glob($this->base . '/scratch/.opsfour-s3-storage-budget/state-*') ?: [],
            static fn(string $path): bool => ! str_ends_with($path, '.tmp'),
        ));
        self::assertCount(1, $states);
        self::assertNotFalse(file_put_contents($states[0], 'corrupt'));
    }

    private function onlyOutstandingIntent(): \OpsFour\S3Server\Metadata\ObjectWriteIntent
    {
        $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
        $statement = $pdo->query('SELECT attempt_id FROM s3_object_write_intents ORDER BY attempt_id');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $ids = $statement->fetchAll(\PDO::FETCH_COLUMN);
        self::assertCount(1, $ids);
        $intent = $this->metadata->getObjectWriteIntent((string) $ids[0]);
        self::assertNotNull($intent);
        return $intent;
    }

    private function preparePlainCopySource(string $payload): void
    {
        $this->mock->append(new Response(200, ['ETag' => '"source"']));
        self::assertSame(200, $this->put()->handleRequest($this->putRequest($payload))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);
        $attempt = basename($source->systemMetadata['storagePath']);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attempt,
        ], $payload));
        $this->mock->append(new Response(200, ['ETag' => '"destination"']));
    }

    private function outstandingIntentCount(): int
    {
        $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
        $statement = $pdo->query('SELECT COUNT(*) FROM s3_object_write_intents');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $count = $statement->fetchColumn();
        self::assertIsNumeric($count);

        return (int) $count;
    }
}

/** @implements \IteratorAggregate<int, string> */
final class AwsS3TestReadableStream implements \IteratorAggregate, ReadableStream
{
    use ReadableStreamIteratorAggregate;

    private int $offset = 0;
    private bool $closed = false;

    /** @var list<\Closure(): void> */
    private array $closeCallbacks = [];

    /** @param list<string> $chunks */
    public function __construct(private array $chunks, private \Closure $beforeRead) {}

    public function read(?Cancellation $cancellation = null): ?string
    {
        if ($this->closed) {
            return null;
        }
        $cancellation?->throwIfRequested();
        ($this->beforeRead)($this->offset + 1);
        if (! isset($this->chunks[$this->offset])) {
            $this->close();
            return null;
        }
        return $this->chunks[$this->offset++];
    }

    public function isReadable(): bool
    {
        return ! $this->closed;
    }

    public function close(): void
    {
        if ($this->closed) {
            return;
        }
        $this->closed = true;
        foreach ($this->closeCallbacks as $callback) {
            $callback();
        }
        $this->closeCallbacks = [];
    }

    public function isClosed(): bool
    {
        return $this->closed;
    }

    public function onClose(\Closure $onClose): void
    {
        if ($this->closed) {
            $onClose();
        } else {
            $this->closeCallbacks[] = $onClose;
        }
    }
}

final readonly class AwsS3YieldingEncryptionService implements EncryptionServiceInterface, SseS3Capability
{
    public function __construct(
        private EncryptionServiceInterface $delegate,
        private \Closure $whileYielding,
    ) {}

    public function supportsSseS3(): bool
    {
        return true;
    }

    public function encryptSseS3(string $plaintext): array
    {
        ($this->whileYielding)();

        return $this->delegate->encryptSseS3($plaintext);
    }

    public function decryptSseS3(
        string $ciphertext,
        string $encryptedDataKeyB64,
        string $ivB64,
        string $tagB64,
    ): string {
        ($this->whileYielding)();

        return $this->delegate->decryptSseS3($ciphertext, $encryptedDataKeyB64, $ivB64, $tagB64);
    }

    public function encryptSseC(string $plaintext, string $customerKey): array
    {
        ($this->whileYielding)();

        return $this->delegate->encryptSseC($plaintext, $customerKey);
    }

    public function decryptSseC(
        string $ciphertext,
        string $customerKey,
        string $ivB64,
        string $tagB64,
    ): string {
        ($this->whileYielding)();

        return $this->delegate->decryptSseC($ciphertext, $customerKey, $ivB64, $tagB64);
    }
}
