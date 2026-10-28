<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Storage;

use Amp\ByteStream\ReadableBuffer;
use GuzzleHttp\Exception\ConnectException;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\Psr7\Request;
use GuzzleHttp\Psr7\Response;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Storage\AwsS3FencedBackgroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ForegroundObjectWriter;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3ProviderValidator;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;
use OpsFour\S3Server\Storage\AwsS3WriteAttemptPath;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\RestoreExecutor;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\TestCase;
use Psr\Http\Message\RequestInterface;

final class AwsS3BackgroundExecutorTest extends TestCase
{
    private string $base;

    private SqliteMetadataStore $metadata;

    private MockHandler $mock;

    private AwsS3StorageBackend $aws;

    private AwsS3ForegroundObjectWriter $writer;

    private AwsS3FencedBackgroundObjectWriter $background;

    /** @var list<RequestInterface> */
    private array $requests = [];

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

            return ($this->mock)($request, $options);
        }));
        self::assertTrue($binding->admission->providerAdmitted);
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

    public function test_transition_from_aws_requires_current_v2_evidence_and_never_calls_generic_delete(): void
    {
        $this->bind('ARCHIVE');
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

    public function test_ambiguous_transition_retry_uses_fresh_attempt_and_keeps_prior_intent(): void
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
        self::assertSame(['PUT'], $this->mutationMethods());

        $pdo = new \PDO('sqlite:' . $this->base . '/metadata.sqlite');
        $due = $pdo->prepare('UPDATE s3_tier_transition_jobs SET next_attempt_at = 0 WHERE id = ?');
        self::assertTrue($due->execute([$jobId]));
        $this->mock->append(new Response(200, ['ETag' => '"aws-retry"']));

        self::assertSame(
            ['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0],
            $executor->processNext(1),
        );
        $published = $this->metadata->getObjectMetadata('bucket', 'ambiguous');
        self::assertNotNull($published);
        self::assertSame('ARCHIVE', $published->storageTier);
        $freshAttempt = basename($published->systemMetadata['storagePath']);
        self::assertNotSame($prior[0], $freshAttempt);
        self::assertSame($prior, $this->activeAttemptIds());
        self::assertSame(['PUT', 'PUT'], $this->mutationMethods());
        $providerWrites = array_values(array_filter(
            array_slice($this->requests, 2),
            static fn(RequestInterface $request): bool => $request->getMethod() === 'PUT',
        ));
        self::assertCount(2, $providerWrites);
        self::assertNotSame($providerWrites[0]->getUri()->getPath(), $providerWrites[1]->getUri()->getPath());
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
                self::assertTrue(true);
            }
        }
    }

    private function bind(string $tier): void
    {
        $this->writer = $this->aws->createForegroundObjectWriter(
            $this->metadata,
            $this->base . '/scratch',
            $tier,
            16 * 1024 * 1024,
        );
        $this->background = new AwsS3FencedBackgroundObjectWriter($this->writer, $this->aws, $tier);
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
