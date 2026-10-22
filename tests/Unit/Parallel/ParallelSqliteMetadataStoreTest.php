<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Observability\MetricsCollector;
use OpsFour\S3Server\Parallel\ParallelSqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class ParallelSqliteMetadataStoreTest extends TestCase
{
    private string $dbPath;

    private ParallelSqliteMetadataStore $store;

    private MetricsCollector $metrics;

    protected function setUp(): void
    {
        $this->dbPath = tempnam(sys_get_temp_dir(), 's3meta_') . '.sqlite';
        $this->metrics = new MetricsCollector();
        $this->store = new ParallelSqliteMetadataStore($this->dbPath, 2, $this->metrics);
        $this->store->initialize();
    }

    protected function tearDown(): void
    {
        try {
            $this->store->shutdown();
        } catch (\Throwable) {
        }

        if (file_exists($this->dbPath)) {
            @unlink($this->dbPath);
        }
        // WAL and SHM files.
        @unlink($this->dbPath . '-wal');
        @unlink($this->dbPath . '-shm');
    }

    public function test_create_and_get_bucket(): void
    {
        $this->store->createBucket('owner1', 'test-bucket', 'us-east-1');

        $bucket = $this->store->getBucket('test-bucket');
        $this->assertNotNull($bucket);
        $this->assertSame('test-bucket', $bucket->name);
        $this->assertSame('owner1', $bucket->ownerId);
        $this->assertSame('us-east-1', $bucket->region);
    }

    public function test_nested_notification_enqueue_stays_in_outer_transaction(): void
    {
        $this->store->createBucket('owner1', 'test-bucket', 'us-east-1');
        $this->store->putBucketNotification('test-bucket', [[
            'id' => 'created',
            'events' => ['s3:ObjectCreated:*'],
            'destinationType' => 'Topic',
            'destinationArn' => 'https://example.test/hook',
            'filterRules' => null,
        ]]);
        $generation = $this->store->getBucket('test-bucket')?->generation;
        self::assertNotNull($generation);

        try {
            $this->store->transaction(function () use ($generation): void {
                (new NotificationDispatcher($this->store))->dispatch('s3:ObjectCreated:Put', 'test-bucket', 'key', bucketGeneration: $generation);
                if ($this->store->getBucketOwner('test-bucket') === 'owner1') {
                    throw new \RuntimeException('roll back outer transaction');
                }
            });
            self::fail('Expected the outer transaction to fail.');
        } catch (\RuntimeException $e) {
            self::assertSame('roll back outer transaction', $e->getMessage());
        }

        self::assertCount(0, $this->store->dequeueNotifications(1));
        (new NotificationDispatcher($this->store))->dispatch('s3:ObjectCreated:Put', 'test-bucket', 'key', bucketGeneration: $generation);
        $queued = $this->store->dequeueNotifications(1);
        self::assertCount(1, $queued);
        $record = json_decode($queued[0]['payload_json'], true, 512, JSON_THROW_ON_ERROR)['Records'][0];
        self::assertMatchesRegularExpression('/^[0-9A-F]{16,}$/', $record['s3']['object']['sequencer']);
    }

    public function test_rejected_direct_nested_begin_does_not_end_outer_transaction(): void
    {
        try {
            $this->store->transaction(function (): void {
                $this->store->createBucket('owner1', 'before-rejected-begin', 'us-east-1');
                try {
                    $this->store->beginTransaction();
                    self::fail('Nested direct beginTransaction() must fail closed.');
                } catch (\LogicException $error) {
                    self::assertStringContainsString('Nested beginTransaction()', $error->getMessage());
                }
                $this->store->createBucket('owner1', 'after-rejected-begin', 'us-east-1');
                throw new \RuntimeException('roll back owning transaction');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('roll back owning transaction', $error->getMessage());
        }

        self::assertNull($this->store->getBucket('before-rejected-begin'));
        self::assertNull($this->store->getBucket('after-rejected-begin'));
    }

    public function test_transaction_end_without_active_fiber_transaction_fails_and_store_remains_usable(): void
    {
        foreach (['commit', 'rollback'] as $method) {
            try {
                \Amp\async(fn() => $this->store->{$method}())->await();
                self::fail("{$method}() without an active transaction must fail closed.");
            } catch (\LogicException $error) {
                self::assertStringContainsString('active transaction', $error->getMessage());
            }
        }

        $this->store->transaction(function (): void {
            $this->store->createBucket('owner1', 'after-rejected-end', 'us-east-1');
        });
        self::assertSame('owner1', $this->store->getBucketOwner('after-rejected-end'));
    }

    public function test_owner_lock_requires_active_fiber_transaction(): void
    {
        try {
            \Amp\async(fn() => $this->store->lockOwnerForUpdate('owner1'))->await();
            self::fail('Owner lock without an active transaction must fail closed.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('active transaction', $error->getMessage());
        }

        $this->store->transaction(function (): void {
            $this->store->lockOwnerForUpdate('owner1');
            $this->store->createBucket('owner1', 'after-rejected-lock', 'us-east-1');
        });
        self::assertSame('owner1', $this->store->getBucketOwner('after-rejected-lock'));
    }

    public function test_owner_write_lock_reaches_pinned_worker_before_metadata_mutation(): void
    {
        try {
            $this->store->transaction(function (): void {
                $this->store->createBucket('owner1', 'before-late-lock', 'us-east-1');
                $this->store->lockOwnerForUpdate('owner1');
            });
            self::fail('A late owner write lock must be rejected by the pinned SQLite worker.');
        } catch (\LogicException $error) {
            self::assertSame(
                'lockOwnerForUpdate() must be acquired before transactional metadata mutations.',
                $error->getMessage(),
            );
        }

        self::assertNull($this->store->getBucket('before-late-lock'));
    }

    public function test_queue_claims_reject_pinned_outer_transaction(): void
    {
        $this->store->transaction(function (): void {
            $claims = [
                fn() => $this->store->claimDueMultipartWriteIntents(1, 1.0, 2.0),
                fn() => $this->store->dequeueTierTransitionJobs(1),
                fn() => $this->store->dequeueRestoreJobs(1),
                fn() => $this->store->dequeueNotifications(1),
                fn() => $this->store->dequeueStorageGarbage(1),
            ];
            foreach ($claims as $claim) {
                try {
                    $claim();
                    self::fail('Queue claim inside an outer transaction must fail closed.');
                } catch (\LogicException $error) {
                    self::assertSame('Queue claims require their own committed transaction.', $error->getMessage());
                }
            }
            $this->store->createBucket('owner1', 'after-rejected-claims', 'us-east-1');
        });

        self::assertSame('owner1', $this->store->getBucketOwner('after-rejected-claims'));
    }

    public function test_bucket_exists(): void
    {
        $this->assertFalse($this->store->bucketExists('nonexistent'));

        $this->store->createBucket('owner1', 'exists', 'us-east-1');
        $this->assertTrue($this->store->bucketExists('exists'));
    }

    public function test_list_buckets(): void
    {
        $this->store->createBucket('owner1', 'bucket-a', 'us-east-1');
        $this->store->createBucket('owner1', 'bucket-b', 'us-east-1');
        $this->store->createBucket('owner2', 'bucket-c', 'us-east-1');

        $buckets = $this->store->listBuckets('owner1');
        $this->assertCount(2, $buckets);
    }

    public function test_part_page_runs_inside_sqlite_worker(): void
    {
        $this->store->createBucket('owner1', 'parts-bucket', 'us-east-1');
        $this->store->createMultipartUpload('parts-upload', 'parts-bucket', 'key', 'owner1');
        foreach ([1, 3, 10] as $partNumber) {
            $this->store->putPart('parts-upload', $partNumber, 'etag', 1, 'part-' . $partNumber);
        }

        self::assertSame([3, 10], array_column($this->store->listPartsPage('parts-upload', 1, 2), 'part_number'));
        self::assertSame([], $this->store->listPartsPage('parts-upload', 10, 1));
    }

    public function test_object_write_intent_contract_crosses_worker_and_fences_stale_claims(): void
    {
        self::assertInstanceOf(ObjectWriteIntentStore::class, $this->store);

        $intent = $this->objectWriteIntent('a', 'objects/attempt-a');
        $reserved = $this->store->reserveObjectWriteIntent($intent, 60.0);
        self::assertSame('active', $reserved->state);
        self::assertGreaterThan(0.0, $reserved->leaseUntil);
        self::assertEquals($reserved, $this->store->getObjectWriteIntent($intent->attemptId));

        self::assertNull($this->store->renewObjectWriteIntent($intent->attemptId, $reserved->leaseUntil + 1.0, 60.0));
        $renewed = $this->store->renewObjectWriteIntent($intent->attemptId, $reserved->leaseUntil, 120.0);
        self::assertNotNull($renewed);
        self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);
        $renewed = $this->store->armObjectWriteIntent($intent->attemptId, $renewed->leaseUntil, str_repeat('a', 32), 120.0);
        self::assertNotNull($renewed);
        self::assertTrue($this->store->abandonObjectWriteIntent($intent->attemptId));
        self::assertFalse($this->store->consumeObjectWriteIntent($intent->attemptId));

        $claimed = $this->store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claimed);
        self::assertSame($intent->attemptId, $claimed[0]->attemptId);
        self::assertSame('reconciling', $claimed[0]->state);
        self::assertNull($this->store->renewObjectWriteClaim($intent->attemptId, $claimed[0]->claimGeneration + 1, $claimed[0]->leaseUntil, 60.0));
        $renewedClaim = $this->store->renewObjectWriteClaim($intent->attemptId, $claimed[0]->claimGeneration, $claimed[0]->leaseUntil, 120.0);
        self::assertNotNull($renewedClaim);
        self::assertFalse($this->store->sealObjectWriteIntent($intent->attemptId, $claimed[0]->claimGeneration + 1));
        self::assertTrue($this->store->sealObjectWriteIntent($intent->attemptId, $claimed[0]->claimGeneration));
        self::assertSame('sealed', $this->store->getObjectWriteIntent($intent->attemptId)?->state);
    }

    public function test_object_write_intent_consumption_and_metadata_publish_share_pinned_worker_transaction(): void
    {
        $this->store->createBucket('owner1', 'tx-bucket', 'us-east-1');
        $rollback = $this->store->reserveObjectWriteIntent($this->objectWriteIntent('b', 'objects/rollback'), 60.0);

        try {
            $this->store->transaction(function () use ($rollback): void {
                self::assertTrue($this->store->consumeObjectWriteIntent($rollback->attemptId));
                $this->store->putObjectMetadata('tx-bucket', 'rollback', 'owner1', 1, '"etag"', 'text/plain', 'objects/rollback');
                throw new \RuntimeException('roll back object publish');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('roll back object publish', $e->getMessage());
        }

        self::assertNotNull($this->store->getObjectWriteIntent($rollback->attemptId));
        self::assertNull($this->store->getObjectMetadata('tx-bucket', 'rollback'));

        $commit = $this->store->reserveObjectWriteIntent($this->objectWriteIntent('c', 'objects/commit'), 60.0);
        $this->store->transaction(function () use ($commit): void {
            self::assertTrue($this->store->consumeObjectWriteIntent($commit->attemptId));
            $this->store->putObjectMetadata('tx-bucket', 'commit', 'owner1', 1, '"etag"', 'text/plain', 'objects/commit');
        });

        self::assertNull($this->store->getObjectWriteIntent($commit->attemptId));
        self::assertNotNull($this->store->getObjectMetadata('tx-bucket', 'commit'));
    }

    public function test_object_write_intent_reservation_rejects_pinned_outer_transaction(): void
    {
        $intent = $this->objectWriteIntent('d', 'objects/separate');

        try {
            $this->store->transaction(function () use ($intent): void {
                $this->store->reserveObjectWriteIntent($intent, 60.0);
                self::fail('Admission inside an outer transaction must be rejected.');
            });
        } catch (\LogicException $error) {
            self::assertSame('Physical write admission requires its own transaction.', $error->getMessage());
        }

        self::assertNull($this->store->getObjectWriteIntent($intent->attemptId));
        self::assertSame($intent->attemptId, $this->store->reserveObjectWriteIntent($intent, 60.0)->attemptId);
    }

    public function test_object_write_intent_claim_rejects_pinned_outer_transaction(): void
    {
        $intent = $this->store->reserveObjectWriteIntent($this->objectWriteIntent('e', 'objects/claim'), 60.0);
        $intent = $this->store->armObjectWriteIntent($intent->attemptId, $intent->leaseUntil, str_repeat('e', 32), 60.0);
        self::assertNotNull($intent);
        self::assertTrue($this->store->abandonObjectWriteIntent($intent->attemptId));

        try {
            $this->store->transaction(function (): void {
                $this->store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
                self::fail('Claim inside an outer transaction must be rejected.');
            });
        } catch (\LogicException $error) {
            self::assertSame('Physical write claims require their own transaction.', $error->getMessage());
        }

        self::assertSame('active', $this->store->getObjectWriteIntent($intent->attemptId)?->state);
        self::assertSame($intent->attemptId, $this->store->claimDueObjectWriteIntents('STANDARD', 1, 60.0)[0]->attemptId);
    }

    private function objectWriteIntent(string $suffix, string $path): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: str_repeat($suffix, 32),
            bucket: 'tx-bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: 'owner1',
            storageTier: 'STANDARD',
            storagePath: $path,
            purpose: 'object',
        );
    }

    public function test_duplicate_bucket_throws(): void
    {
        $this->store->createBucket('owner1', 'dup-bucket', 'us-east-1');

        try {
            $this->store->createBucket('owner1', 'dup-bucket', 'us-east-1');
            self::fail('Expected duplicate bucket creation to fail.');
        } catch (BucketAlreadyExistsException) {
            $rendered = $this->metrics->renderPrometheus();
            $this->assertStringContainsString('s3_server_worker_pool_configured_workers{pool="sqlite_metadata"} 2', $rendered);
            $this->assertStringContainsString('s3_server_worker_pool_tasks_total{operation="createBucket",pool="sqlite_metadata",status="success"} 1', $rendered);
            $this->assertStringContainsString('s3_server_worker_pool_tasks_total{operation="createBucket",pool="sqlite_metadata",status="failure"} 1', $rendered);
            $this->assertStringContainsString(
                's3_server_worker_pool_task_failures_total{exception="' . str_replace('\\', '\\\\', BucketAlreadyExistsException::class) . '",operation="createBucket",pool="sqlite_metadata"} 1',
                $rendered,
            );
        }
    }

    public function test_put_and_get_object_metadata(): void
    {
        $this->store->createBucket('owner1', 'test-bucket', 'us-east-1');

        $this->store->putObjectMetadata(
            'test-bucket',
            'key1',
            'owner1',
            1024,
            '"abc123"',
            'application/octet-stream',
            '/data/key1',
        );

        $obj = $this->store->getObjectMetadata('test-bucket', 'key1');
        $this->assertNotNull($obj);
        $this->assertSame('key1', $obj->key);
        $this->assertSame(1024, $obj->size);
        $this->assertSame('"abc123"', $obj->etag);
    }

    public function test_delete_bucket_with_objects_throws(): void
    {
        $this->store->createBucket('owner1', 'test-bucket', 'us-east-1');
        $this->store->putObjectMetadata(
            'test-bucket',
            'key1',
            'owner1',
            100,
            '"abc"',
            'text/plain',
            '/data/key1',
        );

        $this->expectException(\Throwable::class);
        $this->store->deleteBucket('owner1', 'test-bucket');
    }

    public function test_count_objects(): void
    {
        $this->store->createBucket('owner1', 'test-bucket', 'us-east-1');
        $this->assertSame(0, $this->store->countObjects('test-bucket'));

        $this->store->putObjectMetadata(
            'test-bucket',
            'key1',
            'owner1',
            100,
            '"abc"',
            'text/plain',
            '/data/key1',
        );
        $this->assertSame(1, $this->store->countObjects('test-bucket'));
    }

    public function test_transaction_commit(): void
    {
        $this->store->createBucket('owner1', 'tx-bucket', 'us-east-1');

        // Transactions require a Fiber for worker pinning.
        $fiber = new \Fiber(function () {
            $this->store->transaction(function () {
                $this->store->putObjectMetadata(
                    'tx-bucket',
                    'tx-key',
                    'owner1',
                    50,
                    '"tx"',
                    'text/plain',
                    '/tx',
                );
            });
        });
        $fiber->start();

        $this->assertTrue($this->store->objectExists('tx-bucket', 'tx-key'));
    }

    public function test_transaction_rollback(): void
    {
        $this->store->createBucket('owner1', 'tx-bucket', 'us-east-1');

        // Transactions require a Fiber for worker pinning.
        $fiber = new \Fiber(function () {
            try {
                $this->store->transaction(function () {
                    $this->store->putObjectMetadata(
                        'tx-bucket',
                        'rollback-key',
                        'owner1',
                        50,
                        '"rb"',
                        'text/plain',
                        '/rb',
                    );
                    throw new \RuntimeException('Force rollback');
                });
            } catch (\RuntimeException) {
            }
        });
        $fiber->start();

        $this->assertFalse($this->store->objectExists('tx-bucket', 'rollback-key'));
    }

    public function test_manual_rollback_inside_transaction_preserves_error_and_releases_worker(): void
    {
        $caught = null;
        try {
            $this->store->transaction(function (): never {
                $this->store->rollback();
                throw new \RuntimeException('original parallel transaction failure');
            });
        } catch (\RuntimeException $error) {
            $caught = $error;
        }

        self::assertInstanceOf(\RuntimeException::class, $caught);
        self::assertSame('original parallel transaction failure', $caught->getMessage());
        $this->store->createBucket('owner1', 'after-manual-rollback', 'us-east-1');
        self::assertSame('owner1', $this->store->getBucketOwner('after-manual-rollback'));
    }

    public function test_s3_exception_propagation(): void
    {
        $this->expectException(NoSuchBucketException::class);
        $this->store->deleteBucket('owner1', 'nonexistent-bucket');
    }

    public function test_begin_transaction_outside_fiber_throws(): void
    {
        // beginTransaction() must be called from within a Fiber for worker pinning.
        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('must be called from within a Fiber');
        $this->store->beginTransaction();
    }
}
