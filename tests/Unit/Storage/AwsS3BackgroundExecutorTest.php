<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use Amp\Parallel\Worker\ContextWorkerPool;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Encryption\ConfigMasterKeyProvider;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\AwsS3GarbageClaimStore;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\StorageGarbageLeaseStore;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Logging\AccessLogWriter;
use OpsFour\S3Server\Storage\AwsS3FencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeBinding;
use OpsFour\S3Server\Storage\AwsS3TierRuntimeRegistry;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTempBudgetCoordinator;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Tests\Support\AwsS3OperationalStateFixture;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3ProviderProbe;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3ReadExecutor;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3IntentSummaryStore;
use OpsFour\S3Server\Tests\Support\FixtureAwsS3OperationalSummary;
use OpsFour\S3Server\Observability\MetricsCollector;
use PHPUnit\Framework\TestCase;
use Psr\Log\NullLogger;
use Psr\Http\Message\RequestInterface;

final class AwsS3BackgroundExecutorTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private MockHandler $mock;

    private AwsS3StorageBackend $aws;

    private AwsS3ProviderValidatedBinding $binding;

    private AwsS3ForegroundObjectWriter $writer;

    private AwsS3FencedBackgroundObjectWriter $background;

    private ?FixtureAwsS3IntentSummaryStore $runtimeMetadata = null;

    /** @var list<RequestInterface> */
    private array $requests = [];

    /** @var list<string> */
    private array $putBodies = [];

    /** @var array<string, string|false> */
    private array $oldEnvironment = [];

    protected function setUp(): void
    {
        foreach (['AWS_ACCESS_KEY_ID' => 'test', 'AWS_SECRET_ACCESS_KEY' => 'test'] as $name => $value) {
            $this->oldEnvironment[$name] = getenv($name);
            putenv($name . '=' . $value);
        }
        $this->base = sys_get_temp_dir() . '/s3-aws-background-' . bin2hex(random_bytes(8));
        self::assertTrue(mkdir($this->base, 0700));
        self::assertTrue(mkdir($this->base . '/scratch', 0700));
        $this->metadata = new SqliteMetadataStore($this->base . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
        $this->mock = new MockHandler([
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        ]);
        $binding = AwsS3ProviderValidatedBinding::fromConfiguration([
            'bucket' => 'backing-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'objects',
            'reserved_prefix' => '.opsfour/write-attempts',
        ], new AwsS3ProviderValidator(function (RequestInterface $request, array $options) {
            $this->requests[] = $request;
            if ($request->getMethod() === 'PUT') {
                $body = $request->getBody();
                $this->putBodies[] = (string) $body;
                $body->rewind();
            }

            return ($this->mock)($request, $options);
        }));
        self::assertTrue($binding->admission->providerAdmitted);
        $this->binding = $binding;
        $this->aws = new AwsS3StorageBackend($binding->admission, $binding);
    }

    protected function tearDown(): void
    {
        if (isset($this->writer)) {
            $this->writer->shutdown();
        }
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

    public function test_access_log_uses_one_create_only_attempt_and_consumes_it_with_metadata(): void
    {
        $this->metadata->createBucket('owner', 'log-target', 'us-east-1');
        $this->metadata->putBucketLogging('bucket', 'log-target', 'access/');
        $this->bind('STANDARD');
        $this->mock->append(new Response(200, ['ETag' => '"log"']));

        $logs = new AccessLogWriter($this->metadata, $this->aws, storageTier: 'STANDARD', awsWriter: $this->writer);
        $logs->log('bucket', 'private-key', 'GetObject', 200, 12);
        $logs->flush();
        $logs->flush(); // Delivery itself must not recursively enqueue another access log.

        $objects = $this->metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        self::assertSame([], $this->activeAttemptIds());
        self::assertSame(['PUT'], $this->mutationMethods());
        $put = array_values(array_filter(array_slice($this->requests, 2), static fn(RequestInterface $request): bool => $request->getMethod() === 'PUT'))[0];
        self::assertSame('*', $put->getHeaderLine('If-None-Match'));
        self::assertStringContainsString('/.opsfour/write-attempts/log-target/', $put->getUri()->getPath());
    }

    public function test_access_log_rechecks_target_ownership_before_aws_publication(): void
    {
        $this->metadata->createBucket('owner', 'log-target', 'us-east-1');
        $this->metadata->putBucketLogging('bucket', 'log-target', 'access/');
        $this->bind('STANDARD');

        $logs = new AccessLogWriter($this->metadata, $this->aws, storageTier: 'STANDARD', awsWriter: $this->writer);
        $logs->log('bucket', 'private-key', 'GetObject', 200, 12);
        $this->metadata->deleteBucket('owner', 'log-target');
        $this->metadata->createBucket('foreign-owner', 'log-target', 'us-east-1');
        $logs->flush();

        self::assertSame([], $this->metadata->listObjects('log-target')->objects);
        self::assertSame([], $this->activeAttemptIds());
        self::assertSame([], $this->mutationMethods());
    }

    public function test_access_log_encrypts_body_before_create_only_aws_publication(): void
    {
        $this->metadata->createBucket('owner', 'log-target', 'us-east-1');
        $this->metadata->putBucketLogging('bucket', 'log-target', 'access/');
        $this->metadata->putBucketEncryption('log-target', 'AES256');
        $this->bind('STANDARD');
        $this->mock->append(new Response(200, ['ETag' => '"encrypted-log"']));
        $encryption = new EncryptionService(new ConfigMasterKeyProvider(base64_encode(random_bytes(32))));

        $logs = new AccessLogWriter(
            $this->metadata,
            $this->aws,
            storageTier: 'STANDARD',
            encryption: $encryption,
            awsWriter: $this->writer,
        );
        $logs->log('bucket', 'private-key', 'GetObject', 200, 12);
        $logs->flush();

        $objects = $this->metadata->listObjects('log-target')->objects;
        self::assertCount(1, $objects);
        $object = $objects[0];
        self::assertSame('AES256', $object->userMetadata['__sse-algorithm'] ?? null);
        $puts = array_values(array_filter(
            array_slice($this->requests, 2),
            static fn(RequestInterface $request): bool => $request->getMethod() === 'PUT',
        ));
        self::assertCount(1, $puts);
        self::assertCount(1, $this->putBodies);
        $ciphertext = $this->putBodies[0];
        self::assertStringNotContainsString('private-key', $ciphertext);
        self::assertStringContainsString('private-key', $encryption->decryptSseS3(
            $ciphertext,
            $object->userMetadata['__sse-key'],
            $object->userMetadata['__sse-iv'],
            $object->userMetadata['__sse-tag'],
        ));
    }

    public function test_ambiguous_access_log_stays_in_attempt_metadata_without_log_visibility(): void
    {
        $this->metadata->createBucket('owner', 'log-target', 'us-east-1');
        $this->metadata->putBucketLogging('bucket', 'log-target', 'access/');
        $this->bind('STANDARD');
        $this->mock->append(new ConnectException('lost response', new Request('PUT', 'https://backing-private.s3.us-east-1.amazonaws.com')));

        $logs = new AccessLogWriter($this->metadata, $this->aws, storageTier: 'STANDARD', awsWriter: $this->writer);
        $logs->log('bucket', 'private-key', 'GetObject', 200, 12);
        $logs->flush();

        self::assertCount(0, $this->metadata->listObjects('log-target')->objects);
        self::assertCount(1, $this->activeAttemptIds());
        self::assertSame(['PUT'], $this->mutationMethods());
    }

    public function test_exact_owner_bound_aws_garbage_delete_confirms_absence(): void
    {
        $profile = $this->aws->admittedProfile();
        self::assertNotNull($profile);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $attemptId = bin2hex(random_bytes(16));
        $path = AwsS3WriteAttemptPath::forProfileObject($profile, 'bucket', 'key', $bucket->generation, 'STANDARD', $attemptId);
        $this->mock->append(new Response(200, [
            'Content-Length' => '4',
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => '4',
        ]));
        $this->mock->append(new Response(204));
        $this->mock->append(new Response(404, ['content-type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));
        $this->mock->append(new Response(404, ['content-type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>'));

        $this->deleteClaimedGarbage($path);
        self::assertSame(['DELETE'], $this->mutationMethods());
        $providerRequests = array_values(array_filter(
            array_slice($this->requests, 2),
            static fn(RequestInterface $request): bool => in_array($request->getMethod(), ['HEAD', 'GET', 'DELETE'], true),
        ));
        self::assertSame(['HEAD', 'DELETE', 'HEAD', 'GET'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            $providerRequests,
        ));
        $encodedKey = '/' . implode('/', array_map('rawurlencode', explode('/', $path)));
        foreach ($providerRequests as $request) {
            self::assertSame($encodedKey, $request->getUri()->getPath());
            self::assertSame('123456789012', $request->getHeaderLine('x-amz-expected-bucket-owner'));
        }
    }

    public function test_aws_garbage_rejects_foreign_owner_marker_before_delete(): void
    {
        $profile = $this->aws->admittedProfile();
        self::assertNotNull($profile);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $attemptId = bin2hex(random_bytes(16));
        $path = AwsS3WriteAttemptPath::forProfileObject($profile, 'bucket', 'key', $bucket->generation, 'STANDARD', $attemptId);
        $headers = [
            'Content-Length' => '4',
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'other'),
            'x-amz-meta-opsfour-content-size' => '4',
        ];
        $this->mock->append(new Response(200, $headers));

        $this->expectException(\RuntimeException::class);
        try {
            $this->deleteClaimedGarbage($path);
        } finally {
            self::assertSame([], $this->mutationMethods());
        }
    }

    public function test_stale_or_referenced_aws_garbage_claim_never_contacts_provider(): void
    {
        $this->ensureGarbageOperationalState();
        $claims = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            FencedStorageGarbageStore::class,
            StorageGarbageLeaseStore::class,
            AwsS3GarbageClaimStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $claims);
        $path = '.opsfour/write-attempts/bucket/' . str_repeat('a', 32) . '/5354414e44415244/' . str_repeat('b', 64) . '/' . str_repeat('c', 32);
        $claims->expects(self::once())->method('dequeueStorageGarbage')->willReturn([[
            'id' => 7,
            'bucket' => 'bucket',
            'storage_tier' => 'STANDARD',
            'storage_path' => $path,
            'operation' => 'delete_path',
            'key_name' => null,
            'upload_id' => null,
            'owner_id' => 'owner',
            'attempts' => 0,
            'lease_expires_at' => microtime(true) + 300,
            'lease_generation' => 2,
            'dispatch_id' => null,
        ]]);
        $claims->expects(self::once())->method('deleteAwsGarbageClaim')->willReturn(false);
        $claims->expects(self::once())->method('retryStorageGarbageLease')->willReturn(true);

        self::assertSame(0, (new StorageGarbageCollector(
            $claims,
            StorageTierRegistry::single($this->aws),
        ))->collect(1));
        self::assertSame([], $this->mutationMethods());
    }

    public function test_aws_garbage_operation_cannot_be_issued_outside_the_real_backend(): void
    {
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('authority is invalid');
        (new \OpsFour\S3Server\Storage\AwsS3GarbageDeletion(
            $this->aws,
            new \stdClass(),
            'bucket',
            'STANDARD',
            'path',
            'owner',
        ))->execute();
    }

    public function test_aws_garbage_collector_completes_only_after_provider_absence(): void
    {
        $this->ensureGarbageOperationalState();
        $profile = $this->aws->admittedProfile();
        self::assertNotNull($profile);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $attemptId = bin2hex(random_bytes(16));
        $path = AwsS3WriteAttemptPath::forProfileObject($profile, 'bucket', 'key', $bucket->generation, 'STANDARD', $attemptId);
        $claims = $this->createMockForIntersectionOfInterfaces([
            MetadataStore::class,
            FencedStorageGarbageStore::class,
            StorageGarbageLeaseStore::class,
            AwsS3GarbageClaimStore::class,
        ]);
        self::assertInstanceOf(MetadataStore::class, $claims);
        $claims->method('dequeueStorageGarbage')->willReturn([[
            'id' => 8,
            'bucket' => 'bucket',
            'storage_tier' => 'STANDARD',
            'storage_path' => $path,
            'operation' => 'delete_path',
            'key_name' => null,
            'upload_id' => null,
            'owner_id' => 'owner',
            'attempts' => 0,
            'lease_expires_at' => microtime(true) + 300,
            'lease_generation' => 3,
            'dispatch_id' => null,
        ]]);
        $claims->expects(self::once())->method('deleteAwsGarbageClaim')->willReturnCallback(
            function (int $id, string $bucket, string $tier, string $claimedPath, string $ownerId, int $generation, \Closure $currentLeaseUntil, \Closure $stopRenewal, \OpsFour\S3Server\Storage\AwsS3GarbageDeletion $deletion): bool {
                self::assertTrue($deletion->matches($bucket, $tier, $claimedPath, $ownerId));
                $deletion->execute();
                try {
                    $deletion->execute();
                    self::fail('A provider-bound garbage deletion operation was executed twice.');
                } catch (\LogicException $error) {
                    self::assertSame('AWS garbage deletion operation is no longer active.', $error->getMessage());
                }
                $stopRenewal();
                self::assertGreaterThan(microtime(true), $currentLeaseUntil());
                self::assertSame(['DELETE'], $this->mutationMethods());

                return true;
            },
        );
        $this->mock->append(new Response(200, [
            'Content-Length' => '4',
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => '4',
        ]));
        $this->mock->append(new Response(204));
        $missing = new Response(404, ['content-type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>');
        $this->mock->append($missing);
        $this->mock->append($missing);

        self::assertSame(1, (new StorageGarbageCollector($claims, StorageTierRegistry::single($this->aws)))->collect(1));
    }

    public function test_aws_garbage_operation_is_revoked_when_claim_coordination_returns(): void
    {
        $this->ensureGarbageOperationalState();
        $captured = null;
        $claims = $this->createMock(AwsS3GarbageClaimStore::class);
        $claims->method('deleteAwsGarbageClaim')->willReturnCallback(
            static function (int $id, string $bucket, string $tier, string $path, string $ownerId, int $generation, \Closure $currentLeaseUntil, \Closure $stopRenewal, \OpsFour\S3Server\Storage\AwsS3GarbageDeletion $deletion) use (&$captured): bool {
                $captured = $deletion;

                return false;
            },
        );

        self::assertFalse($this->aws->deleteClaimedGarbage(
            $claims,
            1,
            'bucket',
            'STANDARD',
            'path',
            'owner',
            1,
            static fn(): float => microtime(true) + 300,
            static function (): void {},
        ));
        self::assertInstanceOf(\OpsFour\S3Server\Storage\AwsS3GarbageDeletion::class, $captured);
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('AWS garbage deletion operation is no longer active.');
        $captured->execute();
    }

    public function test_aws_garbage_not_found_confirms_absence_without_delete(): void
    {
        $profile = $this->aws->admittedProfile();
        self::assertNotNull($profile);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $path = AwsS3WriteAttemptPath::forProfileObject($profile, 'bucket', 'key', $bucket->generation, 'STANDARD', bin2hex(random_bytes(16)));
        $missing = new Response(404, ['content-type' => 'application/xml'], '<Error><Code>NoSuchKey</Code></Error>');
        $this->mock->append($missing);
        $this->mock->append($missing);

        $this->deleteClaimedGarbage($path);
        self::assertSame([], $this->mutationMethods());
    }

    public function test_lost_aws_delete_response_remains_ambiguous(): void
    {
        $profile = $this->aws->admittedProfile();
        self::assertNotNull($profile);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $attemptId = bin2hex(random_bytes(16));
        $path = AwsS3WriteAttemptPath::forProfileObject($profile, 'bucket', 'key', $bucket->generation, 'STANDARD', $attemptId);
        $this->mock->append(new Response(200, [
            'Content-Length' => '4',
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => '4',
        ]));
        $this->mock->append(new ConnectException('lost response', new Request('DELETE', 'https://backing-private.s3.us-east-1.amazonaws.com')));

        $this->expectException(\Throwable::class);
        try {
            $this->deleteClaimedGarbage($path);
        } finally {
            self::assertSame(['DELETE'], $this->mutationMethods());
        }
    }

    public function test_transition_reserves_before_source_read_and_consumes_dispatch_with_placement(): void
    {
        $this->bind('ARCHIVE');
        $source = new InMemoryBackend();
        $source->createBucket('bucket');
        $stored = $source->putObject('bucket', 'key', new ReadableBuffer('transition bytes'));
        $this->putObject('key', 'HOT', $stored->path, 'transition bytes');
        $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'key',
            null,
            'HOT',
            'ARCHIVE',
            'GLACIER',
            $stored->path,
        );
        $reservedBeforeRead = false;
        $observed = new CallbackStorageBackend(
            $source,
            beforeRead: function () use (&$reservedBeforeRead): void {
                $reservedBeforeRead = $this->activeAttemptIds() !== [];
            },
        );
        $this->mock->append(new Response(200, ['ETag' => '"aws-transition"']));

        $stats = (new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('HOT', $observed, defaultWriteTier: true),
                new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertTrue($reservedBeforeRead);
        $object = $this->metadata->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame('ARCHIVE', $object->storageTier);
        self::assertStringStartsWith('.opsfour/write-attempts/', $object->systemMetadata['storagePath']);
        self::assertSame([], $this->activeAttemptIds());
        self::assertSame(['PUT'], $this->mutationMethods());
    }

    public function test_exact_two_tier_registry_routes_transition_and_restore_through_distinct_aws_writers(): void
    {
        $this->mock->append(
            new Response(200, ['x-amz-bucket-region' => 'us-east-1']),
            new Response(200, [], '<VersioningConfiguration xmlns="http://s3.amazonaws.com/doc/2006-03-01/"/>'),
        );
        $coldBinding = AwsS3ProviderValidatedBinding::fromConfiguration([
            'bucket' => 'backing-private',
            'region' => 'us-east-1',
            'expected_owner_id' => '123456789012',
            'data_prefix' => 'archive',
            'reserved_prefix' => '.opsfour/archive-attempts',
        ], new AwsS3ProviderValidator(function (RequestInterface $request, array $options) {
            $this->requests[] = $request;
            if ($request->getMethod() === 'PUT') {
                $body = $request->getBody();
                $this->putBodies[] = (string) $body;
                $body->rewind();
            }

            return ($this->mock)($request, $options);
        }));
        $cold = new AwsS3StorageBackend($coldBinding->admission, $coldBinding);
        $summary = new FixtureAwsS3OperationalSummary();
        $runtimeMetadata = new FixtureAwsS3IntentSummaryStore(
            $this->metadata,
            static fn(string $tier): array => $summary->getAwsS3OperationalSummary($tier),
        );
        $metrics = new MetricsCollector();
        class_exists(AwsS3OperationalStateFixture::class);
        $probe = new FixtureAwsS3ProviderProbe($this->binding->admission);
        $readExecutor = new FixtureAwsS3ReadExecutor(
            $this->binding->client ?? throw new \LogicException('Fixture AWS client is unavailable.'),
        );
        self::assertTrue(mkdir($this->base . '/temp-budget', 0700));
        $tempBudget = new StorageTempBudgetCoordinator($this->base . '/temp-budget', 16 * 1024 * 1024, 0);
        $stagePool = new ContextWorkerPool(2);
        $budgetPool = new ContextWorkerPool(2);
        $hotState = AwsS3OperationalStateFixture::attach(
            $this->aws,
            $this->binding,
            'HOT',
            $runtimeMetadata,
            $probe,
            $readExecutor,
        );
        $coldState = AwsS3OperationalStateFixture::attach(
            $cold,
            $coldBinding,
            'COLD',
            $runtimeMetadata,
            $probe,
            $readExecutor,
        );
        self::assertTrue(mkdir($this->base . '/scratch/hot', 0700));
        self::assertTrue(mkdir($this->base . '/scratch/cold', 0700));
        $hotWriter = $this->aws->createForegroundObjectWriter(
            $runtimeMetadata,
            $this->base . '/scratch/hot',
            'HOT',
            16 * 1024 * 1024,
            filesystemReserveBytes: 0,
            tempBudget: $tempBudget,
            stagePool: $stagePool,
            budgetPool: $budgetPool,
        );
        $coldWriter = $cold->createForegroundObjectWriter(
            $runtimeMetadata,
            $this->base . '/scratch/cold',
            'COLD',
            16 * 1024 * 1024,
            filesystemReserveBytes: 0,
            tempBudget: $tempBudget,
            stagePool: $stagePool,
            budgetPool: $budgetPool,
        );
        $hotRuntime = new AwsS3TierRuntimeBinding(
            'HOT',
            $this->aws,
            $runtimeMetadata,
            $hotState,
            $hotWriter,
            new AwsS3FencedBackgroundObjectWriter($hotWriter, $this->aws, 'HOT'),
            $this->aws->createObjectWriteIntentCollector(
                $runtimeMetadata,
                'HOT',
                new NullLogger(),
                $metrics,
            ),
        );
        $coldRuntime = new AwsS3TierRuntimeBinding(
            'COLD',
            $cold,
            $runtimeMetadata,
            $coldState,
            $coldWriter,
            new AwsS3FencedBackgroundObjectWriter($coldWriter, $cold, 'COLD'),
            $cold->createObjectWriteIntentCollector(
                $runtimeMetadata,
                'COLD',
                new NullLogger(),
                $metrics,
            ),
        );
        $registry = new AwsS3TierRuntimeRegistry([$hotRuntime, $coldRuntime], 'HOT', $runtimeMetadata);
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $this->aws, defaultWriteTier: true),
            new StorageTier('COLD', $cold, restoreRequired: true),
        ]);
        $payload = 'two exact AWS tiers';
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $hotAttemptId = str_repeat('a', 32);
        $hotPath = AwsS3WriteAttemptPath::forProfileObject(
            $hotWriter->profile(),
            'bucket',
            'tiered',
            $bucket->generation,
            'HOT',
            $hotAttemptId,
        );
        $this->putObject('tiered', 'HOT', $hotPath, $payload);
        $transitionId = $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'tiered',
            null,
            'HOT',
            'COLD',
            'GLACIER',
            $hotPath,
        );
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $hotAttemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
        ], $payload));
        $this->mock->append(new Response(200, ['ETag' => '"cold"']));

        try {
            $transitionStats = (new TierTransitionExecutor(
                $this->metadata,
                $tiers,
                deleteSourceAfterCommit: false,
                awsTierRuntimes: $registry,
            ))->processNext(1);
            self::assertSame(
                ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
                $transitionStats,
                json_encode($this->metadata->getTierTransitionJob($transitionId), JSON_THROW_ON_ERROR),
            );
            $archived = $this->metadata->getObjectMetadata('bucket', 'tiered');
            self::assertNotNull($archived);
            self::assertSame('COLD', $archived->storageTier);
            $coldPath = (string) $archived->systemMetadata['storagePath'];
            self::assertStringStartsWith('.opsfour/archive-attempts/', $coldPath);

            $this->metadata->updateObjectRestoreState('bucket', 'tiered', 'null', 'pending');
            $this->metadata->enqueueRestoreJob('bucket', 'tiered', null, 'COLD', $coldPath, 1);
            $coldAttemptId = basename($coldPath);
            $this->mock->append(new Response(200, [
                'Content-Length' => (string) strlen($payload),
                'x-amz-meta-opsfour-write-attempt' => 'v2',
                'x-amz-meta-opsfour-attempt-id' => $coldAttemptId,
                'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
                'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
            ], $payload));
            $this->mock->append(new Response(200, ['ETag' => '"hot"']));

            self::assertSame(
                ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
                (new RestoreExecutor(
                    $this->metadata,
                    $tiers,
                    awsTierRuntimes: $registry,
                ))->processNext(1),
            );
            $restored = $this->metadata->getObjectMetadata('bucket', 'tiered');
            self::assertNotNull($restored);
            self::assertSame('restored', $restored->restoreStatus);
            self::assertStringStartsWith('.opsfour/write-attempts/', $restored->restoredStoragePath ?? '');

            self::assertTrue($hotState->mutationReady());
            self::assertTrue($coldState->mutationReady());
            $this->metadata->enqueueStorageGarbage('bucket', 'COLD', 'invalid-cold-garbage-path');
            self::assertSame(0, (new StorageGarbageCollector(
                $this->metadata,
                $tiers,
                awsTierRuntimes: $registry,
            ))->collect(1));
            self::assertTrue($hotState->mutationReady());
            self::assertFalse($coldState->mutationReady());
            $closedSnapshot = $registry->snapshot();
            self::assertFalse($closedSnapshot['mutation_ready']);
            self::assertSame('collector', $closedSnapshot['reason']);

            $failedMetadata = $this->createMockForIntersectionOfInterfaces([
                MetadataStore::class,
                FencedStorageGarbageStore::class,
                StorageGarbageLeaseStore::class,
            ]);
            self::assertInstanceOf(MetadataStore::class, $failedMetadata);
            $failedMetadata->method('dequeueStorageGarbage')->willThrowException(
                new \RuntimeException('metadata unavailable'),
            );
            try {
                (new StorageGarbageCollector(
                    $failedMetadata,
                    $tiers,
                    awsTierRuntimes: $registry,
                ))->collect(1);
                self::fail('A global garbage queue failure was not reported.');
            } catch (\RuntimeException $error) {
                self::assertSame('metadata unavailable', $error->getMessage());
            }
            self::assertFalse($hotState->mutationReady());
            self::assertFalse($coldState->mutationReady());

            self::assertSame(0, (new StorageGarbageCollector(
                $this->metadata,
                $tiers,
                awsTierRuntimes: $registry,
            ))->collect(1));
            self::assertTrue($hotState->mutationReady());
            self::assertTrue($coldState->mutationReady());
            self::assertFalse($registry->hasBoundTier($this->aws, 'COLD'));
            try {
                $registry->backgroundWriterFor($this->aws, 'COLD');
                self::fail('Wrong AWS backend/tier lookup was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('exact admitted backend and tier', $error->getMessage());
            }

            $this->metadata->enqueueStorageGarbage('bucket', 'COLD', 'wrong-exact-runtime-path');
            self::assertSame(0, (new StorageGarbageCollector(
                $this->metadata,
                StorageTierRegistry::single($this->aws, 'COLD'),
                awsTierRuntimes: $registry,
            ))->collect(1));
            self::assertFalse($hotState->mutationReady());
            self::assertFalse($coldState->mutationReady());
        } finally {
            $hotWriter->shutdown();
            $coldWriter->shutdown();
            $stagePool->shutdown();
            $budgetPool->shutdown();
            $tempBudget->close();
        }
    }

    public function test_restore_into_aws_consumes_dispatch_with_restored_copy_metadata(): void
    {
        $this->bind('STANDARD');
        $cold = new InMemoryBackend();
        $cold->createBucket('bucket');
        $stored = $cold->putObject('bucket', 'archive', new ReadableBuffer('restore bytes'));
        $this->putObject('archive', 'GLACIER', $stored->path, 'restore bytes');
        $this->metadata->updateObjectRestoreState('bucket', 'archive', 'null', 'pending');
        $this->metadata->enqueueRestoreJob('bucket', 'archive', null, 'GLACIER', $stored->path, 1);
        $this->mock->append(new Response(200, ['ETag' => '"aws-restore"']));

        $stats = (new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $this->aws, defaultWriteTier: true),
                new StorageTier('GLACIER', $cold, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        $object = $this->metadata->getObjectMetadata('bucket', 'archive');
        self::assertNotNull($object);
        self::assertSame('restored', $object->restoreStatus);
        self::assertSame('STANDARD', $object->restoredStorageTier);
        self::assertStringStartsWith('.opsfour/write-attempts/', $object->restoredStoragePath ?? '');
        self::assertSame([], $this->activeAttemptIds());
        self::assertSame(['PUT'], $this->mutationMethods());
    }

    public function test_ambiguous_aws_restore_preserves_pending_source_and_durable_recovery_attempt(): void
    {
        $this->bind('STANDARD');
        $state = $this->aws->operationalState();
        self::assertNotNull($state);
        $metrics = new MetricsCollector();
        $runtimeMetadata = $this->runtimeMetadata
            ?? throw new \LogicException('AWS runtime metadata fixture is unavailable.');
        $registry = new AwsS3TierRuntimeRegistry([
            new AwsS3TierRuntimeBinding(
                'STANDARD',
                $this->aws,
                $runtimeMetadata,
                $state,
                $this->writer,
                $this->background,
                $this->aws->createObjectWriteIntentCollector(
                    $runtimeMetadata,
                    'STANDARD',
                    new NullLogger(),
                    $metrics,
                ),
            ),
        ], 'STANDARD', $runtimeMetadata);
        $cold = new InMemoryBackend();
        $cold->createBucket('bucket');
        $payload = 'ambiguous restore bytes';
        $stored = $cold->putObject('bucket', 'ambiguous-restore', new ReadableBuffer($payload));
        $this->putObject('ambiguous-restore', 'GLACIER', $stored->path, $payload);
        $this->metadata->updateObjectRestoreState('bucket', 'ambiguous-restore', 'null', 'pending');
        $jobId = $this->metadata->enqueueRestoreJob(
            'bucket',
            'ambiguous-restore',
            null,
            'GLACIER',
            $stored->path,
            1,
            maxAttempts: 3,
        );
        $this->mock->append(new ConnectException(
            'provider acknowledgement lost',
            new Request('PUT', 'https://s3.mock.invalid/private'),
        ));

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
            (new RestoreExecutor(
                $this->metadata,
                new StorageTierRegistry([
                    new StorageTier('STANDARD', $this->aws, defaultWriteTier: true),
                    new StorageTier('GLACIER', $cold, restoreRequired: true),
                ]),
                awsTierRuntimes: $registry,
            ))->processNext(1),
        );

        $unrestored = $this->metadata->getObjectMetadata('bucket', 'ambiguous-restore');
        self::assertNotNull($unrestored);
        self::assertSame('pending', $unrestored->restoreStatus);
        self::assertSame('GLACIER', $unrestored->storageTier);
        self::assertSame($stored->path, $unrestored->systemMetadata['storagePath']);
        self::assertNull($unrestored->restoredStorageTier);
        self::assertNull($unrestored->restoredStoragePath);
        $job = $this->metadata->getRestoreJob($jobId);
        self::assertNotNull($job);
        self::assertSame('pending', $job['status']);
        self::assertSame(1, $job['attempts']);
        self::assertNull($job['restoredStoragePath']);

        $attemptIds = $this->activeAttemptIds();
        self::assertCount(1, $attemptIds, json_encode([
            'requests' => array_map(
                static fn(RequestInterface $request): string => $request->getMethod(),
                array_slice($this->requests, 2),
            ),
            'state' => $state->snapshot(),
            'job' => $job,
        ], JSON_THROW_ON_ERROR));
        $attempt = $this->metadata->getObjectWriteIntent($attemptIds[0]);
        self::assertNotNull($attempt);
        self::assertSame('STANDARD', $attempt->storageTier);
        self::assertSame('restore_copy', $attempt->purpose);
        self::assertNotNull($attempt->dispatchId);
        self::assertSame('integrity', $state->snapshot()['reason']);
        self::assertTrue($state->recoveryReady());
        $due = $this->metadata->claimDueObjectWriteIntents('STANDARD', 1, 300.0);
        self::assertCount(1, $due);
        self::assertSame($attempt->attemptId, $due[0]->attemptId);
        self::assertSame(['PUT'], $this->mutationMethods());
        self::assertSame(['PUT'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->requests, 2),
        ));
    }

    public function test_transition_from_aws_requires_current_v2_evidence_and_never_calls_generic_delete(): void
    {
        $this->bind('ARCHIVE');
        $state = $this->aws->operationalState();
        self::assertNotNull($state);
        $state->markCollectorFailure('storage_garbage');
        self::assertFalse($state->mutationReady());
        self::assertTrue($state->recoveryReady());
        $target = new InMemoryBackend();
        $target->createBucket('bucket');
        $payload = 'aws source bytes';
        $attemptId = str_repeat('a', 32);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $path = AwsS3WriteAttemptPath::forProfileObject(
            $this->writer->profile(),
            'bucket',
            'source',
            $bucket->generation,
            'ARCHIVE',
            $attemptId,
        );
        $this->putObject('source', 'ARCHIVE', $path, $payload);
        $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'source',
            null,
            'ARCHIVE',
            'HOT',
            'STANDARD',
            $path,
        );
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
        ], $payload));

        $stats = (new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('HOT', $target, defaultWriteTier: true),
                new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        self::assertSame('HOT', $this->metadata->getObjectMetadata('bucket', 'source')?->storageTier);
        self::assertSame(['GET'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->requests, 2),
        ));
    }

    public function test_closed_aws_publication_does_not_block_a_local_only_transition_job(): void
    {
        $this->bind('ARCHIVE');
        $state = $this->aws->operationalState();
        self::assertNotNull($state);
        $state->markCollectorFailure('storage_garbage');
        self::assertFalse($state->mutationReady());

        $hot = new InMemoryBackend();
        $cold = new InMemoryBackend();
        $hot->createBucket('bucket');
        $cold->createBucket('bucket');
        $source = $hot->putObject('bucket', 'local-only', new ReadableBuffer('local bytes'));
        $this->putObject('local-only', 'HOT', $source->path, 'local bytes');
        $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'local-only',
            null,
            'HOT',
            'COLD',
            'GLACIER',
            $source->path,
        );

        $stats = (new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('HOT', $hot, defaultWriteTier: true),
                new StorageTier('COLD', $cold, restoreRequired: true),
                new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        $moved = $this->metadata->getObjectMetadata('bucket', 'local-only');
        self::assertNotNull($moved);
        self::assertSame('COLD', $moved->storageTier);
        self::assertSame('local bytes', $cold->getObjectByPath(
            (string) $moved->systemMetadata['storagePath'],
        )->read());
        self::assertCount(2, $this->requests);
    }

    public function test_restore_from_aws_reads_exact_v2_source_before_publishing_restored_metadata(): void
    {
        $this->bind('ARCHIVE');
        $target = new InMemoryBackend();
        $target->createBucket('bucket');
        $payload = 'exact AWS restore source';
        $attemptId = str_repeat('c', 32);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $path = AwsS3WriteAttemptPath::forProfileObject(
            $this->writer->profile(),
            'bucket',
            'restore-source',
            $bucket->generation,
            'ARCHIVE',
            $attemptId,
        );
        $this->putObject('restore-source', 'ARCHIVE', $path, $payload);
        $this->metadata->updateObjectRestoreState('bucket', 'restore-source', 'null', 'pending');
        $this->metadata->enqueueRestoreJob('bucket', 'restore-source', null, 'ARCHIVE', $path, 1);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
        ], $payload));

        $stats = (new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $target, defaultWriteTier: true),
                new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        $restored = $this->metadata->getObjectMetadata('bucket', 'restore-source');
        self::assertNotNull($restored);
        self::assertSame('restored', $restored->restoreStatus);
        self::assertSame('STANDARD', $restored->restoredStorageTier);
        self::assertNotNull($restored->restoredStoragePath);
        self::assertSame($payload, $target->getObjectByPath($restored->restoredStoragePath)->read());
        self::assertSame(['GET'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->requests, 2),
        ));
    }

    public function test_restore_from_aws_rejects_invalid_v2_evidence_before_restored_metadata_is_published(): void
    {
        $this->bind('ARCHIVE');
        $target = new InMemoryBackend();
        $target->createBucket('bucket');
        $payload = 'foreign AWS restore source';
        $attemptId = str_repeat('d', 32);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $path = AwsS3WriteAttemptPath::forProfileObject(
            $this->writer->profile(),
            'bucket',
            'invalid-restore-source',
            $bucket->generation,
            'ARCHIVE',
            $attemptId,
        );
        $this->putObject('invalid-restore-source', 'ARCHIVE', $path, $payload);
        $this->metadata->updateObjectRestoreState('bucket', 'invalid-restore-source', 'null', 'pending');
        $this->metadata->enqueueRestoreJob('bucket', 'invalid-restore-source', null, 'ARCHIVE', $path, 1);
        $this->mock->append(new Response(200, [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'foreign-owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
        ], $payload));

        $stats = (new RestoreExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('STANDARD', $target, defaultWriteTier: true),
                new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0], $stats);
        $unrestored = $this->metadata->getObjectMetadata('bucket', 'invalid-restore-source');
        self::assertNotNull($unrestored);
        self::assertSame('pending', $unrestored->restoreStatus);
        self::assertNull($unrestored->restoredStoragePath);
        self::assertNull($unrestored->restoredStorageTier);
        self::assertSame(['GET'], array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_slice($this->requests, 2),
        ));
    }

    public function test_stale_transition_retains_confirmed_unlinked_attempt_and_retry_uses_fresh_identity(): void
    {
        $this->bind('ARCHIVE');
        $source = new InMemoryBackend();
        $source->createBucket('bucket');
        $stored = $source->putObject('bucket', 'stale', new ReadableBuffer('stale bytes'));
        $this->putObject('stale', 'HOT', $stored->path, 'stale bytes');
        $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'stale',
            null,
            'HOT',
            'ARCHIVE',
            'GLACIER',
            $stored->path,
        );
        $racingSource = new CallbackStorageBackend(
            $source,
            beforeRead: function (): void {
                $this->metadata->updateObjectPlacement(
                    'bucket',
                    'stale',
                    null,
                    'STANDARD',
                    'HOT',
                    'replacement-path',
                );
            },
        );
        $this->mock->append(new Response(200, ['ETag' => '"aws-unlinked"']));

        $stats = (new TierTransitionExecutor(
            $this->metadata,
            new StorageTierRegistry([
                new StorageTier('HOT', $racingSource, defaultWriteTier: true),
                new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
            ]),
            awsFencedWriter: $this->background,
        ))->processNext(1);

        self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $stats);
        $prior = $this->activeAttemptIds();
        self::assertCount(1, $prior);
        self::assertSame(['PUT'], $this->mutationMethods());
        $current = $this->metadata->getObjectMetadata('bucket', 'stale');
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($current);
        self::assertNotNull($bucket);
        $fresh = $this->background->reserve(
            $current,
            $bucket,
            $this->aws,
            'ARCHIVE',
            'tier_transition',
        );
        $freshPath = $this->writer->plannedPath($fresh);
        self::assertNotSame($prior[0], basename($freshPath));
        $this->background->retireBeforeDispatch($fresh);
        self::assertSame($prior, $this->activeAttemptIds());
    }

    public function test_ambiguous_transition_is_due_immediately_and_closes_same_process_retry(): void
    {
        $this->bind('ARCHIVE');
        $source = new InMemoryBackend();
        $source->createBucket('bucket');
        $payload = 'ambiguous transition bytes';
        $stored = $source->putObject('bucket', 'ambiguous', new ReadableBuffer($payload));
        $this->putObject('ambiguous', 'HOT', $stored->path, $payload);
        $jobId = $this->metadata->enqueueTierTransitionJob(
            'bucket',
            'ambiguous',
            null,
            'HOT',
            'ARCHIVE',
            'GLACIER',
            $stored->path,
            maxAttempts: 3,
        );
        $tiers = new StorageTierRegistry([
            new StorageTier('HOT', $source, defaultWriteTier: true),
            new StorageTier('ARCHIVE', $this->aws, restoreRequired: true),
        ]);
        $executor = new TierTransitionExecutor(
            $this->metadata,
            $tiers,
            awsFencedWriter: $this->background,
        );
        $this->mock->append(new ConnectException(
            'provider acknowledgement lost',
            new Request('PUT', 'https://s3.mock.invalid/private'),
        ));

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
            $executor->processNext(1),
        );
        $unpublished = $this->metadata->getObjectMetadata('bucket', 'ambiguous');
        self::assertNotNull($unpublished);
        self::assertSame('HOT', $unpublished->storageTier);
        self::assertSame($stored->path, $unpublished->systemMetadata['storagePath']);
        $prior = $this->activeAttemptIds();
        self::assertCount(1, $prior);
        self::assertNotNull($this->metadata->getObjectWriteIntent($prior[0])?->dispatchId);
        self::assertSame('integrity', $this->aws->operationalState()?->snapshot()['reason']);
        $dueIntent = $this->metadata->claimDueObjectWriteIntents('ARCHIVE', 1, 300.0);
        self::assertCount(1, $dueIntent);
        self::assertSame($prior[0], $dueIntent[0]->attemptId);
        self::assertSame(['PUT'], $this->mutationMethods());

        $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
        $due = $pdo->prepare('UPDATE s3_tier_transition_jobs SET next_attempt_at = 0 WHERE id = ?');
        self::assertTrue($due->execute([$jobId]));
        $this->mock->append(new Response(200, ['ETag' => '"aws-retry"']));

        self::assertSame(
            ['processed' => 1, 'completed' => 0, 'retried' => 1, 'deadLetter' => 0],
            $executor->processNext(1),
        );
        $published = $this->metadata->getObjectMetadata('bucket', 'ambiguous');
        self::assertNotNull($published);
        self::assertSame('HOT', $published->storageTier);
        self::assertSame($prior, $this->activeAttemptIds());
        self::assertSame(['PUT'], $this->mutationMethods());
        $providerWrites = array_values(array_filter(
            array_slice($this->requests, 2),
            static fn(RequestInterface $request): bool => $request->getMethod() === 'PUT',
        ));
        self::assertCount(1, $providerWrites);
    }

    public function test_committed_source_rejects_legacy_foreign_malformed_oversized_and_short_evidence(): void
    {
        $this->bind('ARCHIVE');
        $payload = 'verified bytes';
        $attemptId = str_repeat('b', 32);
        $bucket = $this->metadata->getBucket('bucket');
        self::assertNotNull($bucket);
        $path = AwsS3WriteAttemptPath::forProfileObject(
            $this->writer->profile(),
            'bucket',
            'source',
            $bucket->generation,
            'ARCHIVE',
            $attemptId,
        );
        $this->putObject('source', 'ARCHIVE', $path, $payload);
        $object = $this->metadata->getObjectMetadata('bucket', 'source');
        self::assertNotNull($object);
        $base = [
            'Content-Length' => (string) strlen($payload),
            'x-amz-meta-opsfour-write-attempt' => 'v2',
            'x-amz-meta-opsfour-attempt-id' => $attemptId,
            'x-amz-meta-opsfour-owner-digest' => hash('sha256', 'owner'),
            'x-amz-meta-opsfour-content-size' => (string) strlen($payload),
        ];
        $responses = [
            new Response(200, array_replace($base, ['x-amz-meta-opsfour-write-attempt' => 'v1']), $payload),
            new Response(200, array_replace($base, ['x-amz-meta-opsfour-owner-digest' => hash('sha256', 'foreign')]), $payload),
            new Response(200, array_replace($base, ['x-amz-meta-opsfour-content-size' => 'not-a-size']), $payload),
            new Response(200, array_replace($base, ['Content-Length' => (string) (strlen($payload) + 1)]), $payload . '!'),
            new Response(200, $base, 'short'),
        ];

        foreach ($responses as $response) {
            $this->mock->append($response);
            try {
                \Amp\ByteStream\buffer($this->background->readCommittedSource(
                    $bucket,
                    $object,
                    $this->aws,
                    'ARCHIVE',
                    $path,
                ));
                self::fail('Invalid AWS committed-source evidence was accepted.');
            } catch (InternalErrorException) {
            }
        }
    }

    private function bind(string $tier): void
    {
        $summary = new FixtureAwsS3OperationalSummary();
        $this->runtimeMetadata = new FixtureAwsS3IntentSummaryStore(
            $this->metadata,
            static fn(string $storageTier): array => $summary->getAwsS3OperationalSummary($storageTier),
        );
        AwsS3OperationalStateFixture::attach($this->aws, $this->binding, $tier, $this->runtimeMetadata);
        $this->writer = $this->aws->createForegroundObjectWriter(
            $this->runtimeMetadata,
            $this->base . '/scratch',
            $tier,
            16 * 1024 * 1024,
        );
        $this->background = new AwsS3FencedBackgroundObjectWriter($this->writer, $this->aws, $tier);
    }

    private function deleteClaimedGarbage(string $path): void
    {
        $this->ensureGarbageOperationalState();
        $claims = $this->createMock(AwsS3GarbageClaimStore::class);
        $claims->method('deleteAwsGarbageClaim')->willReturnCallback(
            static function (int $id, string $bucket, string $tier, string $claimedPath, string $ownerId, int $generation, \Closure $currentLeaseUntil, \Closure $stopRenewal, \OpsFour\S3Server\Storage\AwsS3GarbageDeletion $deletion): bool {
                self::assertTrue($deletion->matches($bucket, $tier, $claimedPath, $ownerId));
                $deletion->execute();
                $stopRenewal();

                return true;
            },
        );
        $this->aws->deleteClaimedGarbage(
            $claims,
            1,
            'bucket',
            'STANDARD',
            $path,
            'owner',
            1,
            static fn(): float => microtime(true) + 300,
            static function (): void {},
        );
    }

    private function ensureGarbageOperationalState(): void
    {
        if ($this->aws->operationalState() === null) {
            AwsS3OperationalStateFixture::attach($this->aws, $this->binding, 'STANDARD');
        }
    }

    private function putObject(string $key, string $tier, string $path, string $payload): void
    {
        $this->metadata->putObjectMetadata(
            'bucket',
            $key,
            'owner',
            strlen($payload),
            '"' . md5($payload) . '"',
            'application/octet-stream',
            $path,
        );
        $this->metadata->updateObjectPlacement('bucket', $key, null, 'STANDARD', $tier, $path);
    }

    /** @return list<string> */
    private function activeAttemptIds(): array
    {
        $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
        $statement = $pdo->query('SELECT attempt_id FROM s3_object_write_intents ORDER BY attempt_id');
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return array_values(array_map('strval', $statement->fetchAll(\PDO::FETCH_COLUMN)));
    }

    /** @return list<string> */
    private function mutationMethods(): array
    {
        return array_values(array_map(
            static fn(RequestInterface $request): string => $request->getMethod(),
            array_filter(
                array_slice($this->requests, 2),
                static fn(RequestInterface $request): bool => in_array($request->getMethod(), ['PUT', 'POST', 'DELETE'], true),
            ),
        ));
    }
}
