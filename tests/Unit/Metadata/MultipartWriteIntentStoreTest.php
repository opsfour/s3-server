<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class MultipartWriteIntentStoreTest extends TestCase
{
    public function test_one_active_attempt_per_upload_and_abandoned_paths_survive_retry(): void
    {
        $store = self::storeWithUpload();
        $first = self::intent($store, str_repeat('a', 32));
        $second = self::intent($store, str_repeat('b', 32));

        $store->createMultipartWriteIntent($first);
        self::assertEquals($first, $store->getMultipartWriteIntent($first->attemptId));

        try {
            $store->createMultipartWriteIntent($second);
            self::fail('Two active completion attempts claimed one upload.');
        } catch (\PDOException) {
            self::assertNull($store->getMultipartWriteIntent($second->attemptId));
        }

        self::assertTrue($store->abandonMultipartWriteIntent($first->attemptId, 100.0));
        self::assertFalse($store->consumeMultipartWriteIntent($first->attemptId));
        $store->createMultipartWriteIntent($second);
        $abandoned = $store->getMultipartWriteIntent($first->attemptId);
        self::assertNotNull($abandoned);
        self::assertSame('abandoned', $abandoned->state);
        self::assertSame($first->assemblyPath, $abandoned->assemblyPath);
        self::assertSame($first->finalPath, $abandoned->finalPath);
        self::assertTrue($store->consumeMultipartWriteIntent($second->attemptId));
        self::assertNull($store->getMultipartWriteIntent($second->attemptId));
    }

    public function test_intent_claim_rolls_back_with_owner_transaction(): void
    {
        $store = self::storeWithUpload();
        $intent = self::intent($store, str_repeat('c', 32));

        try {
            $store->transaction(static function () use ($store, $intent): void {
                $store->createMultipartWriteIntent($intent);
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException) {
            // The callback deliberately aborts the transaction.
        }
        self::assertNull($store->getMultipartWriteIntent($intent->attemptId));
    }

    public function test_upload_abort_wakeup_is_transactional_and_scoped_to_active_attempt(): void
    {
        $store = self::storeWithUpload();
        $intent = self::intent($store, str_repeat('a', 32));
        $store->createMultipartWriteIntent($intent);

        try {
            $store->transaction(static function () use ($store): void {
                self::assertSame(1, $store->abandonMultipartWriteIntentsForUpload('upload-id', 0.0));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        $active = $store->getMultipartWriteIntent($intent->attemptId);
        self::assertNotNull($active);
        self::assertSame('active', $active->state);

        self::assertSame(0, $store->abandonMultipartWriteIntentsForUpload('other-upload', 0.0));
        self::assertSame(1, $store->abandonMultipartWriteIntentsForUpload('upload-id', 0.0));
        self::assertSame(0, $store->abandonMultipartWriteIntentsForUpload('upload-id', 0.0));
        $abandoned = $store->getMultipartWriteIntent($intent->attemptId);
        self::assertNotNull($abandoned);
        self::assertSame('abandoned', $abandoned->state);
        self::assertFalse($store->consumeMultipartWriteIntent($intent->attemptId));
        self::assertCount(1, $store->claimDueMultipartWriteIntents(1, microtime(true), microtime(true) + 60));
    }

    public function test_expired_attempt_is_claimed_once_and_remains_reconcilable_after_retry(): void
    {
        $store = self::storeWithUpload();
        $intent = self::intent($store, str_repeat('f', 32), 123.0);
        $store->createMultipartWriteIntent($intent);

        self::assertFalse($store->consumeMultipartWriteIntent($intent->attemptId));
        self::assertNotNull($store->getMultipartWriteIntent($intent->attemptId));
        self::assertSame([], $store->claimDueMultipartWriteIntents(1, 100.0, 200.0));
        $claimed = $store->claimDueMultipartWriteIntents(1, 123.0, 200.0);
        self::assertCount(1, $claimed);
        self::assertSame('abandoned', $claimed[0]->state);
        self::assertSame($intent->assemblyPath, $claimed[0]->assemblyPath);
        self::assertSame($intent->finalPath, $claimed[0]->finalPath);
        self::assertSame([], $store->claimDueMultipartWriteIntents(1, 123.0, 201.0));
        self::assertFalse($store->consumeMultipartWriteIntent($intent->attemptId));
        self::assertFalse($store->retryMultipartWriteIntent($intent->attemptId, 199.0, 300.0));
        self::assertTrue($store->retryMultipartWriteIntent($intent->attemptId, 200.0, 300.0));
        self::assertSame([], $store->claimDueMultipartWriteIntents(1, 299.0, 400.0));
        self::assertCount(1, $store->claimDueMultipartWriteIntents(1, 300.0, 400.0));
    }

    public function test_before_dispatch_retirement_is_limited_to_unarmed_or_matching_active_attempts(): void
    {
        $store = self::storeWithUpload();
        $cleanupToken = str_repeat('1', 32);

        $unarmed = self::intent($store, str_repeat('a', 32));
        $store->createMultipartWriteIntent($unarmed);
        self::assertTrue($store->retireLocalMultipartWriteIntentBeforeDispatch($unarmed->attemptId, $cleanupToken));
        self::assertNull($store->getMultipartWriteIntent($unarmed->attemptId));

        $armed = self::intent($store, str_repeat('b', 32));
        $ownToken = str_repeat('2', 32);
        $store->createMultipartWriteIntent($armed);
        self::assertTrue($store->armMultipartWriteIntent($armed->attemptId, $ownToken, $armed->leaseUntil, $armed->leaseUntil + 60.0));
        self::assertTrue($store->retireLocalMultipartWriteIntentBeforeDispatch($armed->attemptId, $ownToken));
        self::assertNull($store->getMultipartWriteIntent($armed->attemptId));

        $protected = self::intent($store, str_repeat('c', 32));
        $protectedToken = str_repeat('3', 32);
        $foreignToken = str_repeat('4', 32);
        $store->createMultipartWriteIntent($protected);
        self::assertTrue($store->armMultipartWriteIntent($protected->attemptId, $protectedToken, $protected->leaseUntil, $protected->leaseUntil + 60.0));
        self::assertFalse($store->retireLocalMultipartWriteIntentBeforeDispatch($protected->attemptId, $foreignToken));
        $stillArmed = $store->getMultipartWriteIntent($protected->attemptId);
        self::assertNotNull($stillArmed);
        self::assertSame($protectedToken, $stillArmed->dispatchId);

        self::assertTrue($store->abandonMultipartWriteIntent($protected->attemptId, 0.0));
        self::assertFalse($store->retireLocalMultipartWriteIntentBeforeDispatch($protected->attemptId, $protectedToken));
        $abandoned = $store->getMultipartWriteIntent($protected->attemptId);
        self::assertNotNull($abandoned);
        self::assertSame('abandoned', $abandoned->state);
        self::assertSame(0, $abandoned->claimGeneration);

        $claimed = $store->claimDueMultipartWriteIntents(1, $store->multipartWriteIntentNow(), $store->multipartWriteIntentNow() + 60.0);
        self::assertCount(1, $claimed);
        self::assertSame(1, $claimed[0]->claimGeneration);
        self::assertFalse($store->retireLocalMultipartWriteIntentBeforeDispatch($protected->attemptId, $protectedToken));
        self::assertNotNull($store->getMultipartWriteIntent($protected->attemptId));

        foreach (['invalid', str_repeat('0', 32), str_repeat('A', 32)] as $invalidToken) {
            try {
                $store->retireLocalMultipartWriteIntentBeforeDispatch($protected->attemptId, $invalidToken);
                self::fail('Invalid dispatch token was accepted.');
            } catch (\InvalidArgumentException $error) {
                self::assertSame('Invalid multipart completion dispatch ID.', $error->getMessage());
            }
        }
        self::assertNotNull($store->getMultipartWriteIntent($protected->attemptId));
    }

    private static function storeWithUpload(): SqliteMetadataStore
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->createMultipartUpload('upload-id', 'bucket', 'key', 'owner');

        return $store;
    }

    private static function intent(SqliteMetadataStore $store, string $attemptId, ?float $leaseUntil = null): MultipartWriteIntent
    {
        $deadline = $leaseUntil ?? floor(microtime(true)) + 3600.0;
        $bucketInfo = $store->getBucket('bucket');
        self::assertNotNull($bucketInfo);

        return new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: 'upload-id',
            bucket: 'bucket',
            bucketGeneration: $bucketInfo->generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            assemblyPath: 'assembly/' . $attemptId,
            finalPath: 'final/' . $attemptId,
            partDigest: str_repeat('e', 64),
            sseMode: 'AES256',
            leaseUntil: $deadline,
            nextAttemptAt: $deadline,
        );
    }
}
