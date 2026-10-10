<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\SqliteMetadataStore;
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
