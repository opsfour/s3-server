<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Handler;

use Amp\ByteStream\ReadableIterableStream;
use Amp\Http\Server\Driver\Client as ServerClient;
use Amp\Http\Server\Request;
use Amp\Socket\InternetAddress;
use Amp\Socket\SocketAddress;
use Amp\Socket\TlsInfo;
use Aws\Handler\Guzzle\GuzzleHandler;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Psr7\Response;
use League\Uri\Http;
use OpsFour\S3Server\Handler\Multipart\AbortMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CompleteMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartHandler;
use OpsFour\S3Server\Handler\Multipart\UploadPartCopyHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Checksum\Crc64NvmeDigest;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Tests\Support\AwsS3OperationalStateFixture;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3MultipartFenceTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private MockHandler $mock;

    private AwsS3StorageBackend $backend;

    private AwsS3ForegroundObjectWriter $writer;

    /** @var list<RequestInterface> */
    private array $providerRequests = [];

    /** @var list<string> */
    private array $providerBodies = [];

    private ?\Closure $beforeProviderRequest = null;

    private bool $decorateProviderReadMetadata = true;

    /** @var array<string, string|false> */
    private array $oldEnvironment = [];

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'test', 'AWS_SECRET_ACCESS_KEY' => 'test'] as $name => $value) {
            $this->oldEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->base = sys_get_temp_dir() . '/s3-aws-multipart-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0700));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
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
            $this->providerRequests[] = $request;

            return $handler($request, $options)->then(function ($response) use ($request) {
                if (! $this->decorateProviderReadMetadata || $request->getMethod() !== 'GET' || $response->getStatusCode() >= 300
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
        AwsS3OperationalStateFixture::attach($this->backend, $binding, 'STANDARD');
        $this->writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/scratch',
            'STANDARD',
            33_554_432,
        );
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

    public function test_plain_part_and_completion_consume_both_exact_dispatches(): void
    {
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('multipart payload', crc64Nvme: true);
        $attemptId = substr($partPath, -32);
        self::assertNull($this->metadata->getObjectWriteIntent($attemptId));

        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
        ], $payload));
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
        ], $payload));
        $this->mock->append(new Response(200, ['ETag' => '"provider-final"']));
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . $etag . '</ETag></Part></CompleteMultipartUpload>';

        $response = $this->complete()->handleRequest(
            $this->request('POST', '/bucket/key?uploadId=' . $uploadId, $body),
        );

        self::assertSame(200, $response->getStatus());
        self::assertNull($this->metadata->getMultipartUpload($uploadId));
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame(self::crc64Nvme($payload), $object->systemMetadata['checksum-crc64nvme'] ?? null);
        self::assertStringContainsString(
            '<ChecksumCRC64NVME>' . self::crc64Nvme($payload) . '</ChecksumCRC64NVME>',
            \Amp\ByteStream\buffer($response->getBody()),
        );
        self::assertSame('"' . md5((string) hex2bin(md5($payload))) . '-1"', $object->etag);
        $finalPath = $object->systemMetadata['storagePath'];
        self::assertStringStartsWith('.opsfour/write-attempts/bucket/', $finalPath);
        self::assertNull($this->metadata->getObjectWriteIntent(substr($finalPath, -32)));
        self::assertSame(['PUT', 'GET', 'GET', 'PUT'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->providerRequests, 2),
        ));
        self::assertSame('*', $this->providerRequests[5]->getHeaderLine('If-None-Match'));
    }

    public function test_part_declared_length_mismatches_reject_before_provider_dispatch(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;

        foreach ([8, 6] as $declaredLength) {
            try {
                (new UploadPartHandler(
                    $this->metadata,
                    $this->backend,
                    awsObjectWriter: $this->writer,
                ))->handleRequest($this->request(
                    'PUT',
                    '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                    'payload',
                    ['content-length' => (string) $declaredLength],
                ));
                self::fail('Expected multipart declared-length rejection.');
            } catch (\OpsFour\S3Server\Exception\IncompleteBodyException) {
                self::assertSame([], $this->metadata->getParts($uploadId));
            }
            self::assertCount(2, $this->providerRequests);
            self::assertSame([], glob($this->base . '/scratch/s3fw-part-*') ?: []);
        }
    }

    public function test_abort_queues_exact_aws_part_without_provider_delete(): void
    {
        [$uploadId, , $partPath] = $this->uploadPart('abort payload');
        $providerCalls = count($this->providerRequests);

        $response = (new AbortMultipartUploadHandler($this->metadata, $this->backend))->handleRequest(
            $this->request('DELETE', '/bucket/key?uploadId=' . $uploadId),
        );

        self::assertSame(204, $response->getStatus());
        self::assertNull($this->metadata->getMultipartUpload($uploadId));
        self::assertSame($providerCalls, count($this->providerRequests));
        $query = (new \PDO('sqlite:' . $this->base . '/metadata.sqlite'))->prepare(
            'SELECT COUNT(*) FROM s3_storage_garbage WHERE storage_path = ?',
        );
        self::assertTrue($query->execute([$partPath]));
        self::assertSame(1, (int) $query->fetchColumn());
    }

    public function test_part_replacement_queues_the_previous_attempt_without_provider_delete(): void
    {
        [$uploadId, , $firstPath] = $this->uploadPart('first payload');
        $providerCalls = count($this->providerRequests);

        [, $replacementPath] = $this->uploadPartFor($uploadId, 'replacement payload');

        self::assertNotSame($firstPath, $replacementPath);
        self::assertSame($providerCalls + 1, count($this->providerRequests));
        self::assertNotContains('DELETE', array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            $this->providerRequests,
        ));
        $query = (new \PDO('sqlite:' . $this->base . '/metadata.sqlite'))->prepare(
            'SELECT COUNT(*) FROM s3_storage_garbage WHERE storage_path = ?',
        );
        self::assertTrue($query->execute([$firstPath]));
        self::assertSame(1, (int) $query->fetchColumn());
    }

    public function test_ambiguous_part_publication_keeps_armed_evidence_without_part_metadata(): void
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->mock->append(new Response(503, [], '<Error><Code>ServiceUnavailable</Code></Error>'));

        try {
            (new UploadPartHandler(
                $this->metadata,
                $this->backend,
                awsObjectWriter: $this->writer,
            ))->handleRequest($this->request(
                'PUT',
                '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                'uncertain payload',
                ['content-length' => '17'],
            ));
            self::fail('Ambiguous AWS part publication became visible.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }

        $providerRequest = $this->providerRequests[count($this->providerRequests) - 1];
        $attemptId = basename($providerRequest->getUri()->getPath());
        self::assertNotNull($this->metadata->getObjectWriteIntent($attemptId)?->dispatchId);
        self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
    }

    public function test_multipart_handlers_reject_mismatched_or_conflicting_coordinators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new UploadPartHandler(
            $this->metadata,
            new InMemoryBackend(),
            awsObjectWriter: $this->writer,
        );
    }

    public function test_completion_rejects_simultaneous_local_and_aws_coordinators(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new CompleteMultipartUploadHandler(
            $this->metadata,
            $this->backend,
            requireLocalFencedCompletion: true,
            awsObjectWriter: $this->writer,
        );
    }

    public function test_part_copy_reads_an_exact_aws_source_and_consumes_destination_dispatch(): void
    {
        $sourcePayload = 'exact AWS copy source';
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        $put = new PutObjectHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer);
        self::assertSame(200, $put->handleRequest($this->request(
            'PUT',
            '/bucket/source',
            $sourcePayload,
            ['content-length' => (string) strlen($sourcePayload)],
            'source',
        ))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'source');
        self::assertNotNull($source);
        $sourcePath = $source->systemMetadata['storagePath'];
        $sourceAttempt = substr($sourcePath, -32);

        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($sourcePayload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
        ], $sourcePayload));
        $this->mock->append(new Response(200, ['ETag' => '"provider-part-copy"']));

        $response = (new UploadPartCopyHandler(
            $this->metadata,
            $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            headers: ['x-amz-copy-source' => '/bucket/source'],
        ));

        self::assertSame(200, $response->getStatus());
        $parts = $this->metadata->getParts($uploadId);
        self::assertCount(1, $parts);
        self::assertStringStartsWith('.opsfour/write-attempts/bucket/', $parts[0]['storage_path']);
        self::assertNull($this->metadata->getObjectWriteIntent(substr($parts[0]['storage_path'], -32)));
        self::assertSame(['GET', 'PUT'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->providerRequests, -2),
        ));
    }

    public function test_known_part_copy_temp_capacity_failure_does_not_read_the_aws_source(): void
    {
        $sourcePayload = 'copy-body';
        $sourceBucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($sourceBucket);
        $this->metadata->putObjectMetadata(
            'bucket',
            'source',
            'owner',
            strlen($sourcePayload),
            '"' . md5($sourcePayload) . '"',
            'application/octet-stream',
            \OpsFour\S3Server\Storage\AwsS3WriteAttemptPath::forObject(
                'bucket',
                'source',
                $sourceBucket->generation,
                'STANDARD',
                str_repeat('a', 32),
                '.opsfour/write-attempts',
            ),
        );

        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $scratch = $this->base . '/part-copy-capacity';
        self::assertTrue(mkdir($scratch, 0700));
        $budget = new StorageTempBudgetCoordinator($scratch, 4, 0);
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $scratch,
            'STANDARD',
            4,
            filesystemReserveBytes: 0,
            tempBudget: $budget,
        );
        $providerRequestsBefore = count($this->providerRequests);

        try {
            try {
                (new UploadPartCopyHandler(
                    $this->metadata,
                    $this->backend,
                    awsObjectWriter: $writer,
                    tempBudget: $budget,
                ))->handleRequest($this->request(
                    'PUT',
                    '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                    headers: ['x-amz-copy-source' => '/bucket/source'],
                ));
                self::fail('Known part-copy bytes exceeded node temporary capacity.');
            } catch (SlowDownException) {
                self::assertCount($providerRequestsBefore, $this->providerRequests);
                self::assertSame([], $this->metadata->getParts($uploadId));
                self::assertSame(0, $budget->snapshot()->reservedBytes);
            }
        } finally {
            $writer->shutdown();
            $budget->close();
        }
    }

    public function test_known_part_copy_accepts_the_exact_node_temp_limit(): void
    {
        $sourcePayload = 'copy-body';
        $sourceAttempt = str_repeat('b', 32);
        $sourceBucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($sourceBucket);
        $this->metadata->putObjectMetadata(
            'bucket',
            'source',
            'owner',
            strlen($sourcePayload),
            '"' . md5($sourcePayload) . '"',
            'application/octet-stream',
            \OpsFour\S3Server\Storage\AwsS3WriteAttemptPath::forObject(
                'bucket',
                'source',
                $sourceBucket->generation,
                'STANDARD',
                $sourceAttempt,
                '.opsfour/write-attempts',
            ),
        );
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $scratch = $this->base . '/part-copy-exact-capacity';
        self::assertTrue(mkdir($scratch, 0700));
        $budget = new StorageTempBudgetCoordinator($scratch, strlen($sourcePayload), 0);
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $scratch,
            'STANDARD',
            strlen($sourcePayload),
            filesystemReserveBytes: 0,
            tempBudget: $budget,
        );
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($sourcePayload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
        ], $sourcePayload));
        $this->mock->append(new Response(200, ['ETag' => '"provider-part-copy"']));

        try {
            $response = (new UploadPartCopyHandler(
                $this->metadata,
                $this->backend,
                awsObjectWriter: $writer,
                tempBudget: $budget,
            ))->handleRequest($this->request(
                'PUT',
                '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                headers: ['x-amz-copy-source' => '/bucket/source'],
            ));

            self::assertSame(200, $response->getStatus());
            self::assertCount(1, $this->metadata->getParts($uploadId));
            self::assertSame(['GET', 'PUT'], array_map(
                static fn(RequestInterface $request): string => $request->getMethod(),
                array_slice($this->providerRequests, -2),
            ));
            self::assertSame(0, $budget->snapshot()->reservedBytes);
        } finally {
            $writer->shutdown();
            $budget->close();
        }
    }

    public function test_ranged_part_copy_requires_exact_aws_content_range(): void
    {
        $sourcePayload = str_repeat('r', 5 * 1024 * 1024 + 1);
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        (new PutObjectHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer))
            ->handleRequest($this->request(
                'PUT',
                '/bucket/source',
                $sourcePayload,
                ['content-length' => (string) strlen($sourcePayload)],
                'source',
            ));
        $source = $this->metadata->getObjectMetadata('bucket', 'source');
        self::assertNotNull($source);
        $sourceAttempt = substr($source->systemMetadata['storagePath'], -32);
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $range = substr($sourcePayload, 1, 16);
        $this->mock->append(new Response(206, [
            'Content-Length' => (string) strlen($range),
            'Content-Range' => 'bytes 1-16/' . strlen($sourcePayload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $sourceAttempt,
        ], $range));
        $this->mock->append(new Response(200, ['ETag' => '"provider-range-part"']));

        $response = (new UploadPartCopyHandler(
            $this->metadata,
            $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            headers: [
                'x-amz-copy-source' => '/bucket/source',
                'x-amz-copy-source-range' => 'bytes=1-16',
            ],
        ));

        self::assertSame(200, $response->getStatus());
        self::assertSame('bytes=1-16', $this->providerRequests[count($this->providerRequests) - 2]->getHeaderLine('Range'));
        self::assertSame(strlen($range), $this->metadata->getParts($uploadId)[0]['size']);
    }

    public function test_sse_s3_part_and_completion_stage_only_ciphertext(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $plaintext = 'encrypted multipart payload';
        $created = (new CreateMultipartUploadHandler(
            $this->metadata,
            $encryption,
            storage: $this->backend,
            awsObjectWriter: $this->writer,
        ))
            ->handleRequest($this->request(
                'POST',
                '/bucket/key?uploads',
                headers: ['x-amz-server-side-encryption' => 'AES256'],
            ));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-part"']));
        $partResponse = (new UploadPartHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            $plaintext,
            ['content-length' => (string) strlen($plaintext)],
        ));
        $partCiphertext = $this->providerBodies[0];
        self::assertNotSame($plaintext, $partCiphertext);
        $partPath = $this->metadata->getParts($uploadId)[0]['storage_path'];
        $partAttempt = substr($partPath, -32);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($partCiphertext),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $partAttempt,
        ], $partCiphertext));
        $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-final"']));
        $etag = $partResponse->getHeader('ETag') ?? throw new \RuntimeException('Part ETag missing.');
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . $etag . '</ETag></Part></CompleteMultipartUpload>';

        $response = (new CompleteMultipartUploadHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            $body,
        ));

        self::assertSame(200, $response->getStatus());
        self::assertNotSame($plaintext, $this->providerBodies[1]);
        self::assertSame('AES256', $this->metadata->getObjectMetadata('bucket', 'key')?->userMetadata['__sse-algorithm'] ?? null);
    }

    public function test_sse_part_temp_capacity_failure_does_not_read_request_body(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $created = (new CreateMultipartUploadHandler(
            $this->metadata,
            $encryption,
            storage: $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'POST',
            '/bucket/key?uploads',
            headers: ['x-amz-server-side-encryption' => 'AES256'],
        ));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $scratch = $this->base . '/capacity-scratch';
        self::assertTrue(mkdir($scratch, 0700));
        $budget = new StorageTempBudgetCoordinator($scratch, 4, 0);
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $scratch,
            'STANDARD',
            4,
            filesystemReserveBytes: 0,
            tempBudget: $budget,
        );
        $reads = 0;
        $body = new ReadableIterableStream((static function () use (&$reads): iterable {
            ++$reads;
            yield 'payload!!';
        })());
        $request = new Request(
            new AwsMultipartTestClient(),
            'PUT',
            Http::new('http://127.0.0.1/bucket/key?partNumber=1&uploadId=' . $uploadId),
            ['content-length' => '9'],
            $body,
        );
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', \OpsFour\S3Server\Routing\S3Operation::PutObject);

        try {
            try {
                (new UploadPartHandler(
                    $this->metadata,
                    $this->backend,
                    encryption: $encryption,
                    awsObjectWriter: $writer,
                    tempBudget: $budget,
                ))->handleRequest($request);
                self::fail('Insufficient temporary capacity accepted an encrypted multipart part.');
            } catch (SlowDownException) {
                self::assertSame(0, $reads);
                self::assertSame(0, $budget->snapshot()->reservedBytes);
            }
        } finally {
            $writer->shutdown();
            $budget->close();
        }
    }

    public function test_encrypted_completion_temp_capacity_failure_does_not_read_remote_parts(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $plaintext = 'encrypted completion';
        $created = (new CreateMultipartUploadHandler(
            $this->metadata,
            $encryption,
            storage: $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'POST',
            '/bucket/key?uploads',
            headers: ['x-amz-server-side-encryption' => 'AES256'],
        ));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-part"']));
        $part = (new UploadPartHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            $plaintext,
            ['content-length' => (string) strlen($plaintext)],
        ));
        $etag = $part->getHeader('ETag') ?? throw new \RuntimeException('Part ETag missing.');
        $scratch = $this->base . '/completion-capacity';
        self::assertTrue(mkdir($scratch, 0700));
        $budget = new StorageTempBudgetCoordinator($scratch, 4, 0);
        $writer = $this->backend->createForegroundObjectWriter(
            $this->metadata,
            $scratch,
            'STANDARD',
            4,
            filesystemReserveBytes: 0,
            tempBudget: $budget,
        );
        $providerRequestsBefore = count($this->providerRequests);
        $body = '<CompleteMultipartUpload><Part><PartNumber>1</PartNumber><ETag>'
            . $etag . '</ETag></Part></CompleteMultipartUpload>';

        try {
            try {
                (new CompleteMultipartUploadHandler(
                    $this->metadata,
                    $this->backend,
                    encryption: $encryption,
                    awsObjectWriter: $writer,
                    tempBudget: $budget,
                ))->handleRequest($this->request('POST', '/bucket/key?uploadId=' . $uploadId, $body));
                self::fail('Known completion bytes exceeded node temporary capacity.');
            } catch (SlowDownException) {
                self::assertCount($providerRequestsBefore, $this->providerRequests);
                self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
                self::assertSame(0, $budget->snapshot()->reservedBytes);
            }
        } finally {
            $writer->shutdown();
            $budget->close();
        }
    }

    public function test_completion_commit_guard_failure_keeps_dispatch_evidence_without_visibility(): void
    {
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('guarded completion payload');
        $attemptId = substr($partPath, -32);
        foreach ([1, 2] as $_) {
            $this->mock->append(new Response(200, [
                'Content-Length' => (string) strlen($payload),
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $attemptId,
            ], $payload));
        }
        $this->mock->append(new Response(200, ['ETag' => '"provider-final"']));
        $this->beforeProviderRequest = function (RequestInterface $request): void {
            if ($request->getMethod() === 'PUT') {
                $this->metadata->putBucketEncryption('bucket', 'AES256');
                $this->beforeProviderRequest = null;
            }
        };

        try {
            $this->complete()->handleRequest($this->request(
                'POST',
                '/bucket/key?uploadId=' . $uploadId,
                $this->completionBody($etag),
            ));
            self::fail('A changed completion guard published object metadata.');
        } catch (OperationAbortedException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        } finally {
            $this->beforeProviderRequest = null;
        }

        $finalRequest = $this->providerRequests[count($this->providerRequests) - 1];
        self::assertSame('PUT', $finalRequest->getMethod());
        $finalAttemptId = basename($finalRequest->getUri()->getPath());
        self::assertNotNull($this->metadata->getObjectWriteIntent($finalAttemptId)?->dispatchId);
        $intentQuery = (new \PDO('sqlite:' . $this->base . '/metadata.sqlite'))->prepare(
            'SELECT attempt_id, state FROM s3_multipart_write_intents WHERE upload_id = ?',
        );
        self::assertTrue($intentQuery->execute([$uploadId]));
        $intentRow = $intentQuery->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($intentRow);
        self::assertSame('abandoned', $intentRow['state']);
        $multipartIntent = $this->metadata->getMultipartWriteIntent((string) $intentRow['attempt_id']);
        self::assertNotNull($multipartIntent);
        self::assertSame($finalAttemptId, basename($multipartIntent->finalPath));
        self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
        self::assertNotContains('DELETE', array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            $this->providerRequests,
        ));
    }

    public function test_versioned_completion_commits_the_exact_final_attempt(): void
    {
        $this->metadata->setBucketVersioning('bucket', 'Enabled');
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('versioned completion payload');
        $attemptId = substr($partPath, -32);
        foreach ([1, 2] as $_) {
            $this->mock->append(new Response(200, [
                'Content-Length' => (string) strlen($payload),
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $attemptId,
            ], $payload));
        }
        $this->mock->append(new Response(200, ['ETag' => '"provider-final"']));

        $response = $this->complete()->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            $this->completionBody($etag),
        ));

        self::assertSame(200, $response->getStatus());
        $versionId = $response->getHeader('x-amz-version-id');
        self::assertNotNull($versionId);
        self::assertNotSame('null', $versionId);
        $object = $this->metadata->getObjectMetadataByVersion('bucket', 'key', $versionId);
        self::assertNotNull($object);
        self::assertStringStartsWith('.opsfour/write-attempts/bucket/', $object->systemMetadata['storagePath']);
        self::assertNull($this->metadata->getObjectWriteIntent(basename($object->systemMetadata['storagePath'])));
    }

    public function test_sse_c_part_and_completion_stage_only_ciphertext(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $customerKey = random_bytes(32);
        $headers = [
            'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
            'x-amz-server-side-encryption-customer-key' => base64_encode($customerKey),
            'x-amz-server-side-encryption-customer-key-MD5' => base64_encode(md5($customerKey, true)),
        ];
        $plaintext = 'customer encrypted multipart payload';
        $created = (new CreateMultipartUploadHandler(
            $this->metadata,
            $encryption,
            storage: $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request('POST', '/bucket/key?uploads', headers: $headers));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-part"']));
        $partResponse = (new UploadPartHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            $plaintext,
            $headers,
        ));
        $partCiphertext = $this->providerBodies[0];
        self::assertNotSame($plaintext, $partCiphertext);
        $partPath = $this->metadata->getParts($uploadId)[0]['storage_path'];
        $partAttempt = substr($partPath, -32);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($partCiphertext),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $partAttempt,
        ], $partCiphertext));
        $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-final"']));
        $etag = $partResponse->getHeader('ETag') ?? throw new \RuntimeException('Part ETag missing.');

        $response = (new CompleteMultipartUploadHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            $this->completionBody($etag),
            $headers,
        ));

        self::assertSame(200, $response->getStatus());
        self::assertSame($headers['x-amz-server-side-encryption-customer-key-MD5'], $response->getHeader('x-amz-server-side-encryption-customer-key-MD5'));
        self::assertNotSame($plaintext, $this->providerBodies[1]);
        self::assertSame('SSE-C', $this->metadata->getObjectMetadata('bucket', 'key')?->userMetadata['__sse-algorithm'] ?? null);
    }

    public function test_completed_multipart_object_is_an_exact_aws_part_copy_source(): void
    {
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('completed copy source');
        $this->appendPartRead($partPath, $payload);
        $this->appendPartRead($partPath, $payload);
        $this->mock->append(new Response(200, ['ETag' => '"provider-final"']));
        self::assertSame(200, $this->complete()->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            $this->completionBody($etag),
        ))->getStatus());
        $source = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($source);

        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/target?uploads', key: 'target'));
        $targetUploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->appendPartRead($source->systemMetadata['storagePath'], $payload);
        $this->mock->append(new Response(200, ['ETag' => '"provider-copy-part"']));

        $response = (new UploadPartCopyHandler(
            $this->metadata,
            $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/target?partNumber=1&uploadId=' . $targetUploadId,
            headers: ['x-amz-copy-source' => '/bucket/key'],
            key: 'target',
        ));

        self::assertSame(200, $response->getStatus());
        self::assertSame(['GET', 'PUT'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->providerRequests, -2),
        ));
        self::assertCount(1, $this->metadata->getParts($targetUploadId));
    }

    public function test_two_part_plain_completion_streams_in_order_through_provider_multipart(): void
    {
        $first = str_repeat('a', 8 * 1024 * 1024 + 1);
        $second = str_repeat('b', 8 * 1024 * 1024 + 1);
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        [$etag1, $path1] = $this->uploadPartNumberFor($uploadId, 1, $first);
        [$etag2, $path2] = $this->uploadPartNumberFor($uploadId, 2, $second);
        foreach ([[$path1, $first], [$path2, $second]] as [$path, $payload]) {
            $this->appendPartRead($path, $payload);
        }
        $this->mock->append(new Response(
            200,
            [],
            '<InitiateMultipartUploadResult><Bucket>backing-private</Bucket><Key>pending</Key>'
            . '<UploadId>provider-final</UploadId></InitiateMultipartUploadResult>',
        ));
        $providerChunks = [];
        $this->queueProviderMultipartResponses($providerChunks, [[$path1, $first], [$path2, $second]]);
        $response = $this->complete()->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            $this->completionBodyForParts([1 => $etag1, 2 => $etag2]),
        ));

        self::assertSame(200, $response->getStatus());
        self::assertSame($first . $second, implode('', $providerChunks));
        $providerMultipart = array_values(array_filter(
            $this->providerRequests,
            static fn(RequestInterface $request): bool => str_contains($request->getUri()->getQuery(), 'provider-final')
                || str_contains($request->getUri()->getQuery(), 'uploads'),
        ));
        self::assertGreaterThanOrEqual(4, count($providerMultipart));
        self::assertSame('POST', $providerMultipart[0]->getMethod());
        self::assertSame('POST', $providerMultipart[count($providerMultipart) - 1]->getMethod());
        self::assertContains('PUT', array_map(static fn(RequestInterface $request): string => $request->getMethod(), $providerMultipart));
    }

    public function test_mismatched_aws_content_range_is_rejected_before_destination_dispatch(): void
    {
        $sourcePayload = str_repeat('r', 5 * 1024 * 1024 + 1);
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        (new PutObjectHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer))
            ->handleRequest($this->request('PUT', '/bucket/source', $sourcePayload, [
                'content-length' => (string) strlen($sourcePayload),
            ], 'source'));
        $source = $this->metadata->getObjectMetadata('bucket', 'source');
        self::assertNotNull($source);
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request('POST', '/bucket/key?uploads'));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $range = substr($sourcePayload, 1, 16);
        $this->mock->append(new Response(206, [
            'Content-Length' => (string) strlen($range),
            'Content-Range' => 'bytes 2-17/' . strlen($sourcePayload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => basename($source->systemMetadata['storagePath']),
        ], $range));
        $before = count($this->providerRequests);

        try {
            (new UploadPartCopyHandler($this->metadata, $this->backend, awsObjectWriter: $this->writer))
                ->handleRequest($this->request(
                    'PUT',
                    '/bucket/key?partNumber=1&uploadId=' . $uploadId,
                    headers: [
                        'x-amz-copy-source' => '/bucket/source',
                        'x-amz-copy-source-range' => 'bytes=1-16',
                    ],
                ));
            self::fail('Mismatched AWS Content-Range reached destination dispatch.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertSame([], $this->metadata->getParts($uploadId));
        }
        self::assertSame($before + 1, count($this->providerRequests));
        self::assertSame('GET', $this->providerRequests[$before]->getMethod());
    }

    public function test_invalid_part_marker_and_path_are_rejected_before_final_dispatch(): void
    {
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('invalid source evidence');
        $this->appendPartRead($partPath, $payload, ['x-amz-meta-opsfour-attempt-id' => str_repeat('f', 32)]);
        $beforeMarker = count($this->providerRequests);
        try {
            $this->complete()->handleRequest($this->request(
                'POST',
                '/bucket/key?uploadId=' . $uploadId,
                $this->completionBody($etag),
            ));
            self::fail('Invalid AWS part marker reached final dispatch.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertSame($beforeMarker + 1, count($this->providerRequests));

        $update = (new \PDO('sqlite:' . $this->base . '/metadata.sqlite'))->prepare(
            'UPDATE s3_parts SET storage_path = ? WHERE upload_id = ? AND part_number = 1',
        );
        self::assertTrue($update->execute(['.opsfour/write-attempts/bucket/invalid', $uploadId]));
        $beforePath = count($this->providerRequests);
        try {
            $this->complete()->handleRequest($this->request(
                'POST',
                '/bucket/key?uploadId=' . $uploadId,
                $this->completionBody($etag),
            ));
            self::fail('Invalid AWS part path reached final dispatch.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }
        self::assertSame($beforePath, count($this->providerRequests));
    }

    public function test_part_provenance_rejects_missing_or_foreign_owner_and_content_size_before_final_dispatch(): void
    {
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('invalid provenance evidence');
        $attemptId = basename($partPath);
        $ownerDigest = hash('sha256', 'owner');
        $base = [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
        ];
        $invalidEvidence = [
            'missing owner digest' => $base + [
                'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
            ],
            'foreign owner digest' => $base + [
                'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'foreign-owner'),
                'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
            ],
            'missing content size' => $base + [
                'x-amz-meta-opsfour-owner-digest' => $ownerDigest,
            ],
            'foreign content size' => $base + [
                'x-amz-meta-opsfour-owner-digest' => $ownerDigest,
                'x-amz-meta-opsfour-content-size' => (string) (strlen($payload) + 1),
            ],
        ];
        $this->decorateProviderReadMetadata = false;

        foreach ($invalidEvidence as $case => $headers) {
            $this->mock->append(new Response(200, $headers, $payload));
            $before = count($this->providerRequests);
            try {
                $this->complete()->handleRequest($this->request(
                    'POST',
                    '/bucket/key?uploadId=' . $uploadId,
                    $this->completionBody($etag),
                ));
                self::fail($case . ' reached final dispatch.');
            } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
                self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'), $case);
            }
            self::assertSame($before + 1, count($this->providerRequests), $case);
            self::assertSame('GET', $this->providerRequests[$before]->getMethod(), $case);
        }
    }

    public function test_encrypted_upload_part_copy_decrypts_and_reencrypts_the_source(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $plaintext = 'encrypted copy source payload';
        $this->mock->append(new Response(200, ['ETag' => '"provider-source"']));
        (new PutObjectHandler($this->metadata, $this->backend, encryption: $encryption, awsObjectWriter: $this->writer))
            ->handleRequest($this->request('PUT', '/bucket/source', $plaintext, [
                'content-length' => (string) strlen($plaintext),
                'x-amz-server-side-encryption' => 'AES256',
            ], 'source'));
        $source = $this->metadata->getObjectMetadata('bucket', 'source');
        self::assertNotNull($source);
        $sourceCiphertext = $this->providerBodies[0];
        $created = (new CreateMultipartUploadHandler(
            $this->metadata,
            $encryption,
            storage: $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request('POST', '/bucket/key?uploads', headers: [
            'x-amz-server-side-encryption' => 'AES256',
        ]));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $this->appendPartRead($source->systemMetadata['storagePath'], $sourceCiphertext);
        $this->mock->append(new Response(200, ['ETag' => '"provider-copy-part"']));

        $response = (new UploadPartCopyHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            headers: ['x-amz-copy-source' => '/bucket/source'],
        ));

        self::assertSame(200, $response->getStatus());
        self::assertNotSame($plaintext, $this->providerBodies[1]);
        self::assertNotSame($sourceCiphertext, $this->providerBodies[1]);
        self::assertSame('"' . md5($plaintext) . '"', $this->metadata->getParts($uploadId)[0]['etag']);
    }

    public function test_ambiguous_final_publication_retains_both_completion_fences(): void
    {
        [$uploadId, $etag, $partPath, $payload] = $this->uploadPart('ambiguous completion payload');
        $this->appendPartRead($partPath, $payload);
        $this->appendPartRead($partPath, $payload);
        $this->mock->append(new Response(503, [], '<Error><Code>ServiceUnavailable</Code></Error>'));
        try {
            $this->complete()->handleRequest($this->request(
                'POST',
                '/bucket/key?uploadId=' . $uploadId,
                $this->completionBody($etag),
            ));
            self::fail('Ambiguous final publication became visible.');
        } catch (\OpsFour\S3Server\Exception\InternalErrorException) {
            self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        }

        $finalRequest = $this->providerRequests[count($this->providerRequests) - 1];
        self::assertSame('PUT', $finalRequest->getMethod());
        $finalAttempt = basename($finalRequest->getUri()->getPath());
        self::assertNotNull($this->metadata->getObjectWriteIntent($finalAttempt)?->dispatchId);
        self::assertSame('integrity', $this->backend->operationalState()?->snapshot()['reason']);
        $multipartIntent = $this->multipartIntent($uploadId);
        self::assertSame('abandoned', $multipartIntent['state']);
        self::assertSame($finalAttempt, basename($multipartIntent['final_path']));
        self::assertNotNull($this->metadata->getMultipartUpload($uploadId));
    }

    public function test_two_part_encrypted_completion_preserves_plaintext_order(): void
    {
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));
        $first = str_repeat('e', 5 * 1024 * 1024);
        $second = 'ordered encrypted tail';
        $created = (new CreateMultipartUploadHandler(
            $this->metadata,
            $encryption,
            storage: $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request('POST', '/bucket/key?uploads', headers: [
            'x-amz-server-side-encryption' => 'AES256',
        ]));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        $handler = new UploadPartHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        );
        $etags = [];
        foreach ([1 => $first, 2 => $second] as $partNumber => $payload) {
            $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-part"']));
            $response = $handler->handleRequest($this->request(
                'PUT',
                '/bucket/key?partNumber=' . $partNumber . '&uploadId=' . $uploadId,
                $payload,
                ['content-length' => (string) strlen($payload)],
            ));
            $etags[$partNumber] = $response->getHeader('ETag') ?? throw new \RuntimeException('Part ETag missing.');
        }
        $parts = $this->metadata->getParts($uploadId);
        $this->appendPartRead($parts[0]['storage_path'], $this->providerBodies[0]);
        $this->appendPartRead($parts[1]['storage_path'], $this->providerBodies[1]);
        $this->mock->append(new Response(200, ['ETag' => '"provider-encrypted-final"']));

        $response = (new CompleteMultipartUploadHandler(
            $this->metadata,
            $this->backend,
            encryption: $encryption,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'POST',
            '/bucket/key?uploadId=' . $uploadId,
            $this->completionBodyForParts($etags),
        ));

        self::assertSame(200, $response->getStatus());
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        $finalPlaintext = $encryption->decryptSseS3(
            $this->providerBodies[2],
            $object->userMetadata['__sse-key'],
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        );
        self::assertSame($first . $second, $finalPlaintext);
    }

    public function test_non_aws_upload_tier_does_not_use_the_injected_aws_writer(): void
    {
        $warm = new InMemoryBackend();
        $tiers = new StorageTierRegistry([
            new StorageTier('STANDARD', $this->backend, defaultWriteTier: true),
            new StorageTier('WARM', $warm),
        ]);
        $uploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUploadWithTier($uploadId, 'bucket', 'key', 'owner', 'WARM');
        $before = count($this->providerRequests);
        $response = (new UploadPartHandler(
            $this->metadata,
            $this->backend,
            storageTiers: $tiers,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/key?partNumber=1&uploadId=' . $uploadId,
            'warm payload',
            ['content-length' => '12'],
        ));
        self::assertSame(200, $response->getStatus());

        $sourceResult = $warm->putObject('bucket', 'source', new \Amp\ByteStream\ReadableBuffer('warm source'));
        $this->metadata->putObjectMetadata(
            'bucket',
            'source',
            'owner',
            11,
            '"' . md5('warm source') . '"',
            'application/octet-stream',
            $sourceResult->path,
        );
        $this->metadata->updateObjectPlacement('bucket', 'source', null, 'STANDARD', 'WARM', $sourceResult->path);
        $copyUploadId = bin2hex(random_bytes(16));
        $this->metadata->createMultipartUploadWithTier($copyUploadId, 'bucket', 'copy', 'owner', 'WARM');
        $copyResponse = (new UploadPartCopyHandler(
            $this->metadata,
            $this->backend,
            storageTiers: $tiers,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/copy?partNumber=1&uploadId=' . $copyUploadId,
            headers: ['x-amz-copy-source' => '/bucket/source'],
            key: 'copy',
        ));

        self::assertSame(200, $copyResponse->getStatus());
        self::assertSame($before, count($this->providerRequests));
        self::assertCount(1, $this->metadata->getParts($uploadId));
        self::assertCount(1, $this->metadata->getParts($copyUploadId));
    }

    /** @return array{string, string, string, string} */
    private function uploadPart(string $payload, bool $crc64Nvme = false): array
    {
        $created = (new CreateMultipartUploadHandler($this->metadata, storage: $this->backend))
            ->handleRequest($this->request(
                'POST',
                '/bucket/key?uploads',
                headers: $crc64Nvme ? ['x-amz-checksum-algorithm' => 'CRC64NVME'] : [],
            ));
        $uploadId = (string) (new \SimpleXMLElement(\Amp\ByteStream\buffer($created->getBody())))->UploadId;
        [$etag, $path] = $this->uploadPartFor($uploadId, $payload);

        return [$uploadId, $etag, $path, $payload];
    }

    private static function crc64Nvme(string $bytes): string
    {
        $digest = new Crc64NvmeDigest();
        $digest->update($bytes);

        return $digest->finalizeBase64();
    }

    /** @return array{string, string} */
    private function uploadPartFor(string $uploadId, string $payload): array
    {
        return $this->uploadPartNumberFor($uploadId, 1, $payload);
    }

    /** @return array{string, string} */
    private function uploadPartNumberFor(string $uploadId, int $partNumber, string $payload, string $key = 'key'): array
    {
        $this->mock->append(new Response(200, ['ETag' => '"provider-part"']));
        $response = (new UploadPartHandler(
            $this->metadata,
            $this->backend,
            awsObjectWriter: $this->writer,
        ))->handleRequest($this->request(
            'PUT',
            '/bucket/' . $key . '?partNumber=' . $partNumber . '&uploadId=' . $uploadId,
            $payload,
            ['content-length' => (string) strlen($payload)],
            $key,
        ));
        $etag = $response->getHeader('ETag') ?? throw new \RuntimeException('Part ETag missing.');
        $parts = array_values(array_filter(
            $this->metadata->getParts($uploadId),
            static fn(array $part): bool => $part['part_number'] === $partNumber,
        ));
        self::assertCount(1, $parts);

        return [$etag, $parts[0]['storage_path']];
    }

    private function completionBody(string $etag): string
    {
        return $this->completionBodyForParts([1 => $etag]);
    }

    /** @param array<int, string> $etags */
    private function completionBodyForParts(array $etags): string
    {
        $body = '<CompleteMultipartUpload>';
        foreach ($etags as $partNumber => $etag) {
            $body .= '<Part><PartNumber>' . $partNumber . '</PartNumber><ETag>'
                . htmlspecialchars($etag, ENT_XML1) . '</ETag></Part>';
        }

        return $body . '</CompleteMultipartUpload>';
    }

    /** @param array<string, string> $headers */
    private function appendPartRead(string $path, string $payload, array $headers = []): void
    {
        $this->mock->append(new Response(200, array_merge([
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => basename($path),
        ], $headers), $payload));
    }

    /**
     * @param list<string> $chunks
     * @param list<array{string, string}> $providerReads
     */
    private function queueProviderMultipartResponses(array &$chunks, array $providerReads): void
    {
        self::assertCount(2, $providerReads);
        [$firstRead, $secondRead] = $providerReads;
        $this->appendPartRead($firstRead[0], $firstRead[1]);
        $this->mock->append(new Response(200, ['ETag' => '"provider-final-part-1"']));
        $this->appendPartRead($secondRead[0], $secondRead[1]);
        $providerPartCount = (int) ceil((strlen($firstRead[1]) + strlen($secondRead[1])) / (5 * 1024 * 1024));
        for ($partNumber = 2; $partNumber <= $providerPartCount; ++$partNumber) {
            $this->mock->append(new Response(200, ['ETag' => '"provider-final-part"']));
        }
        $this->mock->append(function (RequestInterface $request): Response {
            $query = $request->getUri()->getQuery();
            $key = ltrim(rawurldecode($request->getUri()->getPath()), '/');
            self::assertSame('POST', $request->getMethod());
            self::assertStringContainsString('uploadId=provider-final', $query);

            return new Response(
                200,
                [],
                '<CompleteMultipartUploadResult xmlns="http://s3.amazonaws.com/doc/2006-03-01/">'
                . '<Bucket>backing-private</Bucket><Key>' . htmlspecialchars($key, ENT_XML1)
                . '</Key><ETag>"provider-final"</ETag></CompleteMultipartUploadResult>',
            );
        });
        $this->beforeProviderRequest = static function (RequestInterface $request) use (&$chunks): void {
            if ($request->getMethod() === 'PUT'
                && str_contains($request->getUri()->getQuery(), 'uploadId=provider-final')) {
                $chunks[] = (string) $request->getBody();
            }
        };
    }

    /** @return array{attempt_id: string, state: string, final_path: string} */
    private function multipartIntent(string $uploadId): array
    {
        $query = (new \PDO('sqlite:' . $this->base . '/metadata.sqlite'))->prepare(
            'SELECT attempt_id, state, final_path FROM s3_multipart_write_intents WHERE upload_id = ? ORDER BY rowid DESC LIMIT 1',
        );
        self::assertTrue($query->execute([$uploadId]));
        $row = $query->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);

        return [
            'attempt_id' => (string) $row['attempt_id'],
            'state' => (string) $row['state'],
            'final_path' => (string) $row['final_path'],
        ];
    }

    private function complete(): CompleteMultipartUploadHandler
    {
        return new CompleteMultipartUploadHandler(
            $this->metadata,
            $this->backend,
            awsObjectWriter: $this->writer,
        );
    }

    /**
     * @param non-empty-string $method
     * @param array<non-empty-string, string|list<string>> $headers
     */
    private function request(
        string $method,
        string $path,
        string $body = '',
        array $headers = [],
        string $key = 'key',
    ): Request {
        $request = new Request(new AwsMultipartTestClient(), $method, Http::new('http://127.0.0.1' . $path), $headers, $body);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', $key);
        $request->setAttribute('ownerId', 'owner');
        $request->setAttribute('s3.operation', \OpsFour\S3Server\Routing\S3Operation::PutObject);

        return $request;
    }
}

final class AwsMultipartTestClient implements ServerClient
{
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

    public function getTlsInfo(): ?TlsInfo
    {
        return null;
    }

    public function close(): void {}

    public function isClosed(): bool
    {
        return false;
    }

    public function onClose(\Closure $onClose): void {}
}
