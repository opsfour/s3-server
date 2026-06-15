<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Lifecycle;

use Amp\ByteStream\ReadableBuffer;
use OpsFour\S3Server\Event\S3Event;
use OpsFour\S3Server\Lifecycle\LifecycleExecutor;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Storage\FilesystemBackend;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Revolt\EventLoop;

final class LifecycleExecutorTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $metadata;

    private FilesystemBackend $storage;

    protected function setUp(): void
    {
        parent::setUp();

        $this->path = sys_get_temp_dir() . '/s3-lifecycle-' . bin2hex(random_bytes(4));
        mkdir($this->path, 0o755, true);

        $this->metadata = new SqliteMetadataStore($this->path . '/metadata.sqlite');
        $this->metadata->initialize();
        $this->storage = new FilesystemBackend($this->path . '/objects');
    }

    protected function tearDown(): void
    {
        if ($this->path !== '' && is_dir($this->path)) {
            $this->recursiveDelete($this->path);
        }

        parent::tearDown();
    }

    public function test_expiration_deletes_current_objects_and_storage_by_prefix(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/old.txt', 'expired');
        $keptByPrefix = $this->putObject('bucket', 'data/old.txt', 'kept-prefix');
        $keptByAge = $this->putObject('bucket', 'logs/new.txt', 'kept-age');

        $this->ageObject('bucket', 'logs/old.txt', 5);
        $this->ageObject('bucket', 'data/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'data/old.txt'));
        $this->assertFileExists($keptByPrefix->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/new.txt'));
        $this->assertFileExists($keptByAge->path);
    }

    public function test_noncurrent_version_expiration_deletes_only_old_noncurrent_versions(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $oldVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v1');
        $latestVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v2');
        $this->ageVersion('bucket', 'versioned.txt', $oldVersion['versionId'], 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => ['noncurrentDays' => 1],
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $oldVersion['versionId']));
        $this->assertFileDoesNotExist($oldVersion['path']);
        $this->assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $latestVersion['versionId']));
        $this->assertFileExists($latestVersion['path']);
    }

    public function test_abort_incomplete_multipart_upload_removes_metadata_and_part_files(): void
    {
        $this->createBucket('bucket');
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, 'bucket', 'large.bin', 'owner');
        $part = $this->storage->putPart('bucket', 'large.bin', $uploadId, 1, new ReadableBuffer('part-data'));
        $this->metadata->putPart($uploadId, 1, '"' . md5('part-data') . '"', $part->size, $part->path);
        $this->ageMultipartUpload($uploadId, 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'abort-old-mpu',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => 1,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getMultipartUpload($uploadId));
        $this->assertSame([], $this->metadata->getParts($uploadId));
        $this->assertFileDoesNotExist($part->path);
    }

    public function test_abort_incomplete_multipart_upload_respects_prefix_filter(): void
    {
        $this->createBucket('bucket');
        $expiredUploadId = $this->createMultipartUploadWithPart('bucket', 'logs/large.bin');
        $keptUploadId = $this->createMultipartUploadWithPart('bucket', 'data/large.bin');
        $this->ageMultipartUpload($expiredUploadId, 5);
        $this->ageMultipartUpload($keptUploadId, 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'abort-old-logs-mpu',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => ['and' => ['prefix' => 'logs/']],
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => 1,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getMultipartUpload($expiredUploadId));
        $this->assertNotNull($this->metadata->getMultipartUpload($keptUploadId));
    }

    public function test_expiration_tag_filter_deletes_only_matching_objects(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'tmp/delete.txt', 'expired');
        $kept = $this->putObject('bucket', 'tmp/keep.txt', 'kept');
        $this->ageObject('bucket', 'tmp/delete.txt', 5);
        $this->ageObject('bucket', 'tmp/keep.txt', 5);
        $this->metadata->putObjectTagging('bucket', 'tmp/delete.txt', [['key' => 'class', 'value' => 'tmp']]);
        $this->metadata->putObjectTagging('bucket', 'tmp/keep.txt', [['key' => 'class', 'value' => 'keep']]);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-tmp-tag',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => ['tag' => ['key' => 'class', 'value' => 'tmp']],
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'tmp/delete.txt'));
        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'tmp/keep.txt'));
        $this->assertFileExists($kept->path);
    }

    public function test_expiration_and_filter_requires_prefix_and_all_tags(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/prod-hot.txt', 'expired');
        $keptByTag = $this->putObject('bucket', 'logs/prod-cold.txt', 'kept-tag');
        $keptByPrefix = $this->putObject('bucket', 'data/prod-hot.txt', 'kept-prefix');

        foreach (['logs/prod-hot.txt', 'logs/prod-cold.txt', 'data/prod-hot.txt'] as $key) {
            $this->ageObject('bucket', $key, 5);
        }

        $this->metadata->putObjectTagging('bucket', 'logs/prod-hot.txt', [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => 'tier', 'value' => 'hot'],
        ]);
        $this->metadata->putObjectTagging('bucket', 'logs/prod-cold.txt', [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => 'tier', 'value' => 'cold'],
        ]);
        $this->metadata->putObjectTagging('bucket', 'data/prod-hot.txt', [
            ['key' => 'env', 'value' => 'prod'],
            ['key' => 'tier', 'value' => 'hot'],
        ]);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-prod-hot-logs',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => [
                'and' => [
                    'prefix' => 'logs/',
                    'tags' => [
                        ['key' => 'env', 'value' => 'prod'],
                        ['key' => 'tier', 'value' => 'hot'],
                    ],
                ],
            ],
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/prod-hot.txt'));
        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/prod-cold.txt'));
        $this->assertFileExists($keptByTag->path);
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'data/prod-hot.txt'));
        $this->assertFileExists($keptByPrefix->path);
    }

    public function test_lifecycle_run_stops_at_configured_action_budget(): void
    {
        $this->createBucket('bucket');

        for ($i = 1; $i <= 5; $i++) {
            $this->putObject('bucket', "logs/old-{$i}.txt", 'expired');
            $this->ageObject('bucket', "logs/old-{$i}.txt", 5);
        }

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $executor = new LifecycleExecutor($this->metadata, $this->storage, batchSize: 2, maxActionsPerRun: 3);
        $executor->processBucket('bucket');

        $remaining = 0;
        for ($i = 1; $i <= 5; $i++) {
            if ($this->metadata->getObjectMetadata('bucket', "logs/old-{$i}.txt") !== null) {
                $remaining++;
            }
        }

        $this->assertSame(2, $remaining);
    }

    public function test_lifecycle_checkpoint_resumes_large_backlog_across_sweeps(): void
    {
        $this->createBucket('bucket');

        for ($i = 1; $i <= 5; $i++) {
            $this->putObject('bucket', "logs/old-{$i}.txt", 'expired');
            $this->ageObject('bucket', "logs/old-{$i}.txt", 5);
        }

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $executor = new LifecycleExecutor($this->metadata, $this->storage, batchSize: 2, maxActionsPerRun: 2);

        $executor->processBucket('bucket');
        $this->assertSame(
            ['cursorKey' => 'logs/old-2.txt', 'cursorVersionId' => null, 'cursorUploadId' => null],
            $this->metadata->getLifecycleCheckpoint('bucket', 'expire-logs', 'expire_current'),
        );
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-1.txt'));
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-2.txt'));
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old-3.txt'));

        $executor->processBucket('bucket');
        $this->assertSame(
            ['cursorKey' => 'logs/old-4.txt', 'cursorVersionId' => null, 'cursorUploadId' => null],
            $this->metadata->getLifecycleCheckpoint('bucket', 'expire-logs', 'expire_current'),
        );
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-3.txt'));
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-4.txt'));
        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old-5.txt'));

        $executor->processBucket('bucket');
        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old-5.txt'));
        $this->assertNull($this->metadata->getLifecycleCheckpoint('bucket', 'expire-logs', 'expire_current'));
    }

    public function test_execute_skips_when_another_node_holds_lifecycle_lock(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->assertTrue($this->metadata->acquireLock('lifecycle:global', 'node-a', 60));
        $metrics = new MetricsCollector;

        $executor = new LifecycleExecutor(
            $this->metadata,
            $this->storage,
            lockOwnerId: 'node-b',
            metrics: $metrics,
        );
        $executor->execute();

        $this->assertNotNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        $this->assertStringContainsString('s3_server_lifecycle_sweeps_total{status="skipped_lock"} 1', $metrics->renderPrometheus());

        $this->metadata->releaseLock('lifecycle:global', 'node-a');
        $executor->execute();

        $this->assertNull($this->metadata->getObjectMetadata('bucket', 'logs/old.txt'));
        $rendered = $metrics->renderPrometheus();
        $this->assertStringContainsString('s3_server_lifecycle_sweeps_total{status="completed"} 1', $rendered);
        $this->assertStringContainsString('s3_server_lifecycle_actions_total{action="expire_current"} 1', $rendered);
    }

    public function test_lifecycle_action_logs_include_structured_context(): void
    {
        $this->createBucket('bucket');
        $expired = $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $logger = new LifecycleArrayLogger;
        $executor = new LifecycleExecutor($this->metadata, $this->storage, $logger);

        $executor->processBucket('bucket');

        $this->assertFileDoesNotExist($expired->path);
        $this->assertNotNull($logger->findContext('action_completed', [
            'component' => 'lifecycle',
            'bucket' => 'bucket',
            'rule_id' => 'expire-logs',
            'action' => 'expire_current',
            'status' => 'completed',
            'count' => 1,
            'expiration_mode' => 'days',
            'expiration_days' => 1,
        ]));
        $this->assertNotNull($logger->findContext('rule_completed', [
            'component' => 'lifecycle',
            'bucket' => 'bucket',
            'rule_id' => 'expire-logs',
            'applied_actions' => 1,
        ]));
    }

    public function test_lifecycle_action_dispatches_internal_event_without_webhook_enqueue(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'logs/old.txt', 'expired');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'expire-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => null,
            'expiration' => ['days' => 1],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);
        $this->metadata->putBucketNotification('bucket', [[
            'id' => 'catch-all-webhook',
            'events' => ['s3:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://notifications.example.test/s3',
            'filterRules' => null,
        ]]);

        $notifications = new NotificationDispatcher($this->metadata);
        $received = [];
        $notifications->listen('s3:Lifecycle:*', static function (S3Event $event) use (&$received): void {
            $received[] = $event;
        });

        $executor = new LifecycleExecutor($this->metadata, $this->storage, notifications: $notifications);
        $executor->processBucket('bucket');
        $this->runEventLoopTick();

        $this->assertCount(1, $received);
        $this->assertSame('s3:Lifecycle:ActionApplied', $received[0]->name);
        $this->assertSame('bucket', $received[0]->bucket);
        $this->assertSame('', $received[0]->key);
        $this->assertSame('expire-logs', $received[0]->attributes['rule_id'] ?? null);
        $this->assertSame('expire_current', $received[0]->attributes['lifecycle_action'] ?? null);
        $this->assertSame(1, $received[0]->attributes['count'] ?? null);
        $this->assertSame([], $this->metadata->dequeueNotifications(10));
    }

    public function test_expired_object_delete_marker_removes_only_orphaned_delete_markers(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $orphanMarker = $this->metadata->deleteObjectVersioned('bucket', 'orphan.txt', 'owner');
        $keptVersion = $this->putVersionedObject('bucket', 'has-version.txt', 'v1');
        $keptMarker = $this->metadata->deleteObjectVersioned('bucket', 'has-version.txt', 'owner');

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'delete-orphan-markers',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => ['expiredObjectDeleteMarker' => true],
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $this->assertNull($this->metadata->getObjectMetadataByVersion('bucket', 'orphan.txt', $orphanMarker));
        $this->assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'has-version.txt', $keptVersion['versionId']));
        $this->assertNotNull($this->metadata->getObjectMetadataByVersion('bucket', 'has-version.txt', $keptMarker));
    }

    public function test_transition_rule_enqueues_durable_job_idempotently(): void
    {
        $this->createBucket('bucket');
        $this->putObject('bucket', 'logs/old.txt', 'archive me');
        $this->putObject('bucket', 'logs/new.txt', 'not yet');
        $this->ageObject('bucket', 'logs/old.txt', 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition-logs',
            'status' => 'Enabled',
            'prefix' => 'logs/',
            'filter' => null,
            'transitions' => [['days' => 1, 'storageClass' => 'GLACIER']],
            'expiration' => null,
            'noncurrentTransitions' => null,
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');
        $this->executor()->processBucket('bucket');

        $jobs = $this->tierTransitionJobs();
        $object = $this->metadata->getObjectMetadata('bucket', 'logs/old.txt');
        $newObject = $this->metadata->getObjectMetadata('bucket', 'logs/new.txt');

        $this->assertCount(1, $jobs);
        $this->assertSame('bucket', $jobs[0]['bucket']);
        $this->assertSame('logs/old.txt', $jobs[0]['key_name']);
        $this->assertNull($jobs[0]['version_id']);
        $this->assertSame('STANDARD', $jobs[0]['source_tier']);
        $this->assertSame('GLACIER', $jobs[0]['target_tier']);
        $this->assertSame('GLACIER', $jobs[0]['target_storage_class']);
        $this->assertNotNull($object);
        $this->assertSame('pending', $object->transitionStatus);
        $this->assertSame('GLACIER', $object->transitionTargetTier);
        $this->assertNotNull($newObject);
        $this->assertSame('available', $newObject->transitionStatus);
    }

    public function test_noncurrent_transition_rule_enqueues_versioned_job(): void
    {
        $this->createBucket('bucket');
        $this->metadata->setBucketVersioning('bucket', 'Enabled');

        $oldVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v1');
        $latestVersion = $this->putVersionedObject('bucket', 'versioned.txt', 'v2');
        $this->ageVersion('bucket', 'versioned.txt', $oldVersion['versionId'], 5);

        $this->metadata->putBucketLifecycle('bucket', [[
            'id' => 'transition-noncurrent',
            'status' => 'Enabled',
            'prefix' => null,
            'filter' => null,
            'transitions' => null,
            'expiration' => null,
            'noncurrentTransitions' => [['noncurrentDays' => 1, 'storageClass' => 'GLACIER']],
            'noncurrentExpiration' => null,
            'abortIncompleteDays' => null,
        ]]);

        $this->executor()->processBucket('bucket');

        $jobs = $this->tierTransitionJobs();
        $old = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $oldVersion['versionId']);
        $latest = $this->metadata->getObjectMetadataByVersion('bucket', 'versioned.txt', $latestVersion['versionId']);

        $this->assertCount(1, $jobs);
        $this->assertSame($oldVersion['versionId'], $jobs[0]['version_id']);
        $this->assertSame('GLACIER', $jobs[0]['target_storage_class']);
        $this->assertNotNull($old);
        $this->assertSame('pending', $old->transitionStatus);
        $this->assertSame('GLACIER', $old->transitionTargetTier);
        $this->assertNotNull($latest);
        $this->assertSame('available', $latest->transitionStatus);
    }

    private function executor(): LifecycleExecutor
    {
        return new LifecycleExecutor($this->metadata, $this->storage);
    }

    private function createBucket(string $bucket): void
    {
        $this->metadata->createBucket('owner', $bucket, 'us-east-1');
        $this->storage->createBucket($bucket);
    }

    private function putObject(string $bucket, string $key, string $body): \OpsFour\S3Server\Storage\StorageWriteResult
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($body));
        $this->metadata->putObjectMetadata(
            bucket: $bucket,
            key: $key,
            ownerId: 'owner',
            size: $write->size,
            etag: '"' . $write->md5Hex . '"',
            contentType: 'text/plain',
            storagePath: $write->path,
        );

        return $write;
    }

    /**
     * @return array{versionId: string, path: string}
     */
    private function putVersionedObject(string $bucket, string $key, string $body): array
    {
        $write = $this->storage->putObject($bucket, $key, new ReadableBuffer($body));
        $versionId = $this->metadata->putObjectVersioned(
            bucket: $bucket,
            key: $key,
            ownerId: 'owner',
            size: $write->size,
            etag: '"' . $write->md5Hex . '"',
            contentType: 'text/plain',
            storagePath: $write->path,
        );

        return ['versionId' => $versionId, 'path' => $write->path];
    }

    private function createMultipartUploadWithPart(string $bucket, string $key): string
    {
        $uploadId = bin2hex(random_bytes(8));
        $this->metadata->createMultipartUpload($uploadId, $bucket, $key, 'owner');
        $part = $this->storage->putPart($bucket, $key, $uploadId, 1, new ReadableBuffer('part-data'));
        $this->metadata->putPart($uploadId, 1, '"' . md5('part-data') . '"', $part->size, $part->path);

        return $uploadId;
    }

    private function ageObject(string $bucket, string $key, int $days): void
    {
        $this->sql(
            'UPDATE s3_objects SET created_at = ?, updated_at = ? WHERE bucket = ? AND key_name = ?',
            [$this->past($days), $this->past($days), $bucket, $key],
        );
    }

    private function ageVersion(string $bucket, string $key, string $versionId, int $days): void
    {
        $this->sql(
            'UPDATE s3_objects SET created_at = ?, updated_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?',
            [$this->past($days), $this->past($days), $bucket, $key, $versionId],
        );
    }

    private function ageMultipartUpload(string $uploadId, int $days): void
    {
        $this->sql(
            'UPDATE s3_multipart_uploads SET created_at = ? WHERE upload_id = ?',
            [$this->past($days), $uploadId],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function tierTransitionJobs(): array
    {
        $stmt = $this->pdo()->query('SELECT * FROM s3_tier_transition_jobs ORDER BY id ASC');

        return $stmt !== false ? $stmt->fetchAll(\PDO::FETCH_ASSOC) : [];
    }

    /**
     * @param list<mixed> $params
     */
    private function sql(string $sql, array $params): void
    {
        $stmt = $this->pdo()->prepare($sql);
        $stmt->execute($params);
    }

    private function pdo(): \PDO
    {
        $reflection = new \ReflectionClass($this->metadata);
        $method = $reflection->getMethod('connection');
        $method->setAccessible(true);
        /** @var \PDO $pdo */
        return $method->invoke($this->metadata);
    }

    private function past(int $days): string
    {
        return (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->modify("-{$days} days")
            ->format('Y-m-d\TH:i:s\Z');
    }

    private function runEventLoopTick(): void
    {
        $suspension = EventLoop::getSuspension();
        EventLoop::delay(0.01, static function () use ($suspension): void {
            $suspension->resume();
        });
        $suspension->suspend();
    }

    private function recursiveDelete(string $path): void
    {
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $file) {
            if ($file->isDir()) {
                @rmdir($file->getPathname());
            } else {
                @unlink($file->getPathname());
            }
        }

        @rmdir($path);
    }
}

final class LifecycleArrayLogger extends AbstractLogger
{
    /**
     * @var list<array{level: mixed, message: string|\Stringable, context: array<string, mixed>}>
     */
    public array $records = [];

    /**
     * @param array<string, mixed> $context
     */
    public function log($level, string|\Stringable $message, array $context = []): void
    {
        $this->records[] = [
            'level' => $level,
            'message' => $message,
            'context' => $context,
        ];
    }

    /**
     * @param array<string, mixed> $expected
     *
     * @return array<string, mixed>|null
     */
    public function findContext(string $event, array $expected): ?array
    {
        foreach ($this->records as $record) {
            $context = $record['context'];
            if (($context['event'] ?? null) !== $event) {
                continue;
            }

            foreach ($expected as $key => $value) {
                if (($context[$key] ?? null) !== $value) {
                    continue 2;
                }
            }

            return $context;
        }

        return null;
    }
}
