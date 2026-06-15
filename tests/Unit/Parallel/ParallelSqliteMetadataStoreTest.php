<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Parallel;

use OpsFour\S3Server\Exception\BucketAlreadyExistsException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
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
