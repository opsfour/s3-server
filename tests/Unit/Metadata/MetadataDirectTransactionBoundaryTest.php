<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Metadata\SharedAuthorizationLockStore;
use PHPUnit\Framework\TestCase;

final class MetadataDirectTransactionBoundaryTest extends TestCase
{
    public function test_transaction_end_without_active_transaction_fails_without_discarding_store(): void
    {
        foreach (['commit', 'rollback'] as $method) {
            $store = new SqliteMetadataStore(':memory:');
            $store->initialize();

            try {
                $store->{$method}();
                self::fail("{$method}() without an active transaction must fail closed.");
            } catch (\LogicException $error) {
                self::assertStringContainsString('active transaction', $error->getMessage());
            }

            $store->transaction(static function () use ($store, $method): void {
                $store->createBucket('owner', "after-rejected-{$method}", 'us-east-1');
            });
            self::assertSame('owner', $store->getBucketOwner("after-rejected-{$method}"));
        }
    }

    public function test_owner_lock_requires_active_transaction_and_remains_usable_after_rejection(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();

        try {
            $store->lockOwnerForUpdate('owner');
            self::fail('Owner lock without an active transaction must fail closed.');
        } catch (\LogicException $error) {
            self::assertStringContainsString('active transaction', $error->getMessage());
        }

        $store->transaction(static function () use ($store): void {
            $store->lockOwnerForUpdate('owner');
            $store->createBucket('owner', 'after-rejected-lock', 'us-east-1');
        });
        self::assertSame('owner', $store->getBucketOwner('after-rejected-lock'));
    }

    public function test_sqlite_shared_read_locks_require_a_transaction_and_read_named_policies(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        self::assertInstanceOf(SharedAuthorizationLockStore::class, $store);

        // Preparation is intentionally a read-only compatible no-op on SQLite.
        $store->prepareOwnerReadLock('owner');

        foreach ([
            static fn() => $store->lockOwnerForRead('owner'),
            static fn() => $store->lockNamedPoliciesForRead(['policy']),
        ] as $lock) {
            try {
                $lock();
                self::fail('Shared lock without an active transaction must fail closed.');
            } catch (\LogicException $error) {
                self::assertStringContainsString('active transaction', $error->getMessage());
            }
        }

        $store->putNamedPolicy('policy-b', '{"Statement":[]}');
        $store->putNamedPolicy('policy-a', '{"Statement":[]}');
        $policies = $store->transaction(static function () use ($store): array {
            $store->lockOwnerForRead('owner');

            return $store->lockNamedPoliciesForRead(['policy-b', '', 'missing', 'policy-a', 'policy-b']);
        });

        self::assertSame([
            'policy-a' => '{"Statement":[]}',
            'policy-b' => '{"Statement":[]}',
        ], $policies);
    }

    public function test_sqlite_owner_read_lock_supports_a_read_only_store(): void
    {
        $path = sys_get_temp_dir() . '/s3-read-lock-' . bin2hex(random_bytes(8)) . '.sqlite';
        try {
            $writer = new SqliteMetadataStore($path);
            $writer->initialize();
            $writer->putNamedPolicy('policy', '{"Statement":[]}');

            $reader = new SqliteMetadataStore($path, readOnly: true);
            $reader->prepareOwnerReadLock('owner');
            $policies = $reader->transaction(static function () use ($reader): array {
                $reader->lockOwnerForRead('owner');

                return $reader->lockNamedPoliciesForRead(['policy']);
            });

            self::assertSame(['policy' => '{"Statement":[]}'], $policies);
        } finally {
            unset($reader, $writer);
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_queue_claims_reject_an_outer_transaction_before_sql_work(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $claims = [
            static fn() => $store->claimDueMultipartWriteIntents(1, 1.0, 2.0),
            static fn() => $store->dequeueTierTransitionJobs(1),
            static fn() => $store->dequeueRestoreJobs(1),
            static fn() => $store->dequeueNotifications(1),
            static fn() => $store->dequeueStorageGarbage(1),
        ];

        $store->transaction(static function () use ($store, $claims): void {
            foreach ($claims as $claim) {
                try {
                    $claim();
                    self::fail('Queue claim inside an outer transaction must fail closed.');
                } catch (\LogicException $error) {
                    self::assertSame('Queue claims require their own committed transaction.', $error->getMessage());
                }
            }
            $store->createBucket('owner', 'after-rejected-claims', 'us-east-1');
        });

        self::assertSame('owner', $store->getBucketOwner('after-rejected-claims'));
    }

    public function test_rejected_nested_begin_leaves_outer_transaction_usable_and_owned(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();

        try {
            $store->transaction(function () use ($store): void {
                $store->createBucket('owner', 'before-rejected-begin', 'us-east-1');
                try {
                    $store->beginTransaction();
                    self::fail('Nested direct beginTransaction() must fail closed.');
                } catch (\LogicException $error) {
                    self::assertStringContainsString('Nested beginTransaction()', $error->getMessage());
                }
                $store->createBucket('owner', 'after-rejected-begin', 'us-east-1');
                throw new \RuntimeException('roll back owning transaction');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('roll back owning transaction', $error->getMessage());
        }

        self::assertNull($store->getBucket('before-rejected-begin'));
        self::assertNull($store->getBucket('after-rejected-begin'));
    }
}
