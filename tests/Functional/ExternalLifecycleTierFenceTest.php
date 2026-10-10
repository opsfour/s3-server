<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTier;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\TierTransitionExecutor;
use OpsFour\S3Server\Tests\Support\CallbackStorageBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalLifecycleTierFenceTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function externalDrivers(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('externalDrivers')]
    public function test_removed_rule_cancels_queued_lifecycle_job_before_copy_but_direct_job_runs(string $driver, string $dsnVariable): void
    {
        [$control, $worker, $owner, $bucket] = $this->stores($driver, $dsnVariable);
        $hot = new InMemoryBackend();
        $archive = new CallbackStorageBackend(new InMemoryBackend());
        $hot->createBucket($bucket);
        $archive->createBucket($bucket);
        $source = $hot->putObject($bucket, 'object.bin', new ReadableBuffer('payload'));

        try {
            $control->putObjectMetadata($bucket, 'object.bin', $owner, $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
            $control->putBucketLifecycle($bucket, [self::currentTransitionRule()]);
            $staleId = $control->enqueueTierTransitionJob($bucket, 'object.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: true);
            $control->updateObjectPlacement($bucket, 'object.bin', null, 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');

            // Change policy through another database connection after enqueue.
            $control->deleteBucketLifecycle($bucket);
            $executor = self::executor($worker, $hot, $archive);
            self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $executor->processNext(1));
            self::assertSame('completed', $control->getTierTransitionJob($staleId)['status'] ?? null);
            self::assertSame(0, $archive->putCalls);
            $object = $control->getObjectMetadata($bucket, 'object.bin');
            self::assertNotNull($object);
            self::assertSame('available', $object->transitionStatus);
            self::assertSame('STANDARD', $object->storageTier);
            self::assertSame('payload', $hot->getObjectByPath($source->path)->read());

            $directId = $control->enqueueTierTransitionJob($bucket, 'object.bin', null, 'STANDARD', 'GLACIER', 'GLACIER', $source->path);
            self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $executor->processNext(1));
            self::assertSame('direct', $control->getTierTransitionJob($directId)['origin'] ?? null);
            self::assertSame(1, $archive->putCalls);
            $transitioned = $control->getObjectMetadata($bucket, 'object.bin');
            self::assertNotNull($transitioned);
            self::assertSame('GLACIER', $transitioned->storageTier);
        } finally {
            $control->deleteObjectMetadata($bucket, 'object.bin');
            $control->deleteBucket($owner, $bucket);
        }
    }

    #[DataProvider('externalDrivers')]
    public function test_current_role_change_cancels_queued_lifecycle_job_before_copy(string $driver, string $dsnVariable): void
    {
        [$control, $worker, $owner, $bucket] = $this->stores($driver, $dsnVariable);
        $hot = new InMemoryBackend();
        $archive = new CallbackStorageBackend(new InMemoryBackend());
        $hot->createBucket($bucket);
        $archive->createBucket($bucket);
        $versions = [];

        try {
            $control->setBucketVersioning($bucket, 'Enabled');
            $source = $hot->putObject($bucket, 'object.bin', new ReadableBuffer('original'));
            $versions[] = $control->putObjectVersioned($bucket, 'object.bin', $owner, $source->size, '"' . $source->md5Hex . '"', 'application/octet-stream', $source->path);
            $control->putBucketLifecycle($bucket, [self::currentTransitionRule()]);
            $staleId = $control->enqueueTierTransitionJob($bucket, 'object.bin', $versions[0], 'STANDARD', 'GLACIER', 'GLACIER', $source->path, origin: 'lifecycle', lifecycleRuleId: 'transition', expectedIsLatest: true);
            $control->updateObjectPlacement($bucket, 'object.bin', $versions[0], 'STANDARD', 'STANDARD', $source->path, 'pending', 'GLACIER');

            $replacement = $hot->putObject($bucket, 'object.bin', new ReadableBuffer('replacement'));
            $versions[] = $control->putObjectVersioned($bucket, 'object.bin', $owner, $replacement->size, '"' . $replacement->md5Hex . '"', 'application/octet-stream', $replacement->path);
            $executor = self::executor($worker, $hot, $archive);
            self::assertSame(['processed' => 1, 'completed' => 1, 'retried' => 0, 'deadLetter' => 0], $executor->processNext(1));
            self::assertSame('completed', $control->getTierTransitionJob($staleId)['status'] ?? null);
            self::assertSame(0, $archive->putCalls);
            $noncurrent = $control->getObjectMetadataByVersion($bucket, 'object.bin', $versions[0]);
            $current = $control->getObjectMetadataByVersion($bucket, 'object.bin', $versions[1]);
            self::assertNotNull($noncurrent);
            self::assertNotNull($current);
            self::assertSame('available', $noncurrent->transitionStatus);
            self::assertSame('STANDARD', $noncurrent->storageTier);
            self::assertSame('STANDARD', $current->storageTier);
            self::assertSame('original', $hot->getObjectByPath($source->path)->read());
        } finally {
            foreach ($versions as $versionId) {
                $control->deleteObjectVersion($bucket, 'object.bin', $versionId);
            }
            $control->deleteBucket($owner, $bucket);
        }
    }

    /** @return array{MetadataStore, MetadataStore, string, string} */
    private function stores(string $driver, string $dsnVariable): array
    {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnVariable} for the disposable lifecycle tier-fence test.");
        }

        $config = ['dsn' => trim($dsn)];
        $control = MetadataStoreFactory::create($driver, $config);
        $worker = MetadataStoreFactory::create($driver, $config);
        $suffix = bin2hex(random_bytes(6));
        $owner = 'owner-' . $suffix;
        $bucket = 'lifecycle-tier-fence-' . $suffix;
        $control->createBucket($owner, $bucket, 'us-east-1');

        return [$control, $worker, $owner, $bucket];
    }

    /** @return array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int} */
    private static function currentTransitionRule(): array
    {
        return [
            'id' => 'transition',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => [['days' => 0, 'storageClass' => 'GLACIER']],
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ];
    }

    private static function executor(MetadataStore $worker, InMemoryBackend $hot, CallbackStorageBackend $archive): TierTransitionExecutor
    {
        return new TierTransitionExecutor($worker, new StorageTierRegistry([
            new StorageTier('STANDARD', $hot, defaultWriteTier: true),
            new StorageTier('GLACIER', $archive, restoreRequired: true),
        ]));
    }
}
