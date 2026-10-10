<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class MultipartCompletionIntentOwnerCapTest extends TestCase
{
    public function test_renewal_requires_the_current_unexpired_active_lease(): void
    {
        $path = sys_get_temp_dir() . '/s3-completion-renew-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            $store->createMultipartUpload('upload', 'bucket', 'key', 'owner');
            $store->createMultipartUpload('other-upload', 'bucket', 'other-key', 'owner');
            $bucketInfo = $store->getBucket('bucket');
            self::assertNotNull($bucketInfo);
            $generation = $bucketInfo->generation;
            $now = $store->multipartWriteIntentNow();
            $intent = new MultipartWriteIntent(
                'attempt',
                'upload',
                'bucket',
                $generation,
                'owner',
                'STANDARD',
                'assembly',
                'final',
                'digest',
                null,
                $now + 10,
                $now + 10,
            );
            $store->createMultipartWriteIntent($intent);
            self::assertFalse($store->renewMultipartWriteIntent('attempt', $now + 9, $now + 20));
            self::assertTrue($store->renewMultipartWriteIntent('attempt', $now + 10, $now + 20));
            self::assertFalse($store->renewMultipartWriteIntent('attempt', $now + 10, $now + 30));
            self::assertSame([], $store->claimDueMultipartWriteIntents(1, $now + 11, $now + 31));
            self::assertTrue($store->abandonMultipartWriteIntent('attempt', $now));
            self::assertFalse($store->renewMultipartWriteIntent('attempt', $now + 20, $now + 30));

            $expired = new MultipartWriteIntent(
                'expired',
                'other-upload',
                'bucket',
                $generation,
                'owner',
                'STANDARD',
                'assembly2',
                'final2',
                'digest',
                null,
                $now - 1,
                $now - 1,
            );
            $store->createMultipartWriteIntent($expired);
            self::assertFalse($store->renewMultipartWriteIntent('expired', $now - 1, $now + 20));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_abandoned_completion_attempts_count_against_only_their_owner(): void
    {
        $path = sys_get_temp_dir() . '/s3-completion-cap-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = new SqliteMetadataStore($path);
            $first->initialize();
            $second = new SqliteMetadataStore($path);
            $second->initialize();
            $database = new \PDO('sqlite:' . $path);
            $database->beginTransaction();
            $insert = $database->prepare(
                "INSERT INTO s3_multipart_write_intents
                    (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                     assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
                 VALUES (?, 'old-upload', 'bucket', 'generation', 'owner-a', 'STANDARD',
                         'assembly', 'final', 'digest', 'abandoned', 0, 0)",
            );
            self::assertInstanceOf(\PDOStatement::class, $insert);
            for ($i = 0; $i < 4095; $i++) {
                $insert->execute([sprintf('%032x', $i)]);
            }
            $database->commit();

            $first->createMultipartWriteIntent($this->intent($first, 'last-slot', 'owner-a'));
            try {
                $second->createMultipartWriteIntent($this->intent($second, 'over-cap', 'owner-a'));
                self::fail('A second connection exceeded the completion recovery cap.');
            } catch (SlowDownException) {
                self::assertNull($second->getMultipartWriteIntent('over-cap'));
            }
            $second->createMultipartWriteIntent($this->intent($second, 'other-owner', 'owner-b'));
            self::assertNotNull($first->getMultipartWriteIntent('other-owner'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_global_counter_rejects_other_owners_and_rolls_back_with_outer_transaction(): void
    {
        $path = sys_get_temp_dir() . '/s3-completion-global-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $first = new SqliteMetadataStore($path);
            $first->initialize();
            $second = new SqliteMetadataStore($path);
            $second->initialize();
            $database = new \PDO('sqlite:' . $path);
            $database->exec('UPDATE s3_multipart_completion_capacity SET retained_count = 999999 WHERE id = 1');

            $ownerA = $this->intent($first, 'global-a', 'owner-a');
            $ownerB = $this->intent($second, 'global-b', 'owner-b');
            $first->createMultipartWriteIntent($ownerA);
            self::assertSame(MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS_GLOBAL, self::sqliteCount($database));
            try {
                $second->createMultipartWriteIntent($ownerB);
                self::fail('Another owner exceeded the global completion recovery cap.');
            } catch (SlowDownException) {
                self::assertNull($second->getMultipartWriteIntent('global-b'));
            }

            $database->exec("DELETE FROM s3_multipart_write_intents WHERE attempt_id = 'global-a'");
            self::assertSame(999999, self::sqliteCount($database));
            try {
                $second->transaction(static function () use ($second, $ownerB): void {
                    $second->createMultipartWriteIntent($ownerB);
                    throw new \RuntimeException('roll back the outer transaction');
                });
            } catch (\RuntimeException $error) {
                self::assertSame('roll back the outer transaction', $error->getMessage());
            }
            self::assertNull($first->getMultipartWriteIntent('global-b'));
            self::assertSame(999999, self::sqliteCount($database));
            $second->createMultipartWriteIntent($ownerB);
            self::assertNotNull($first->getMultipartWriteIntent('global-b'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    private function intent(SqliteMetadataStore $store, string $attemptId, string $ownerId): MultipartWriteIntent
    {
        $bucket = 'bucket-' . $ownerId;
        if ($store->getBucket($bucket) === null) {
            $store->createBucket($ownerId, $bucket, 'us-east-1');
        }
        $uploadId = 'upload-' . $attemptId;
        $store->createMultipartUpload($uploadId, $bucket, 'key', $ownerId);
        $bucketInfo = $store->getBucket($bucket);
        self::assertNotNull($bucketInfo);

        return new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: $uploadId,
            bucket: $bucket,
            bucketGeneration: $bucketInfo->generation,
            ownerId: $ownerId,
            storageTier: 'STANDARD',
            assemblyPath: 'assembly-' . $attemptId,
            finalPath: 'final-' . $attemptId,
            partDigest: 'digest',
            sseMode: 'AES256',
            leaseUntil: 1000,
            nextAttemptAt: 1000,
        );
    }

    private static function sqliteCount(\PDO $database): int
    {
        $statement = $database->query('SELECT retained_count FROM s3_multipart_completion_capacity');
        self::assertInstanceOf(\PDOStatement::class, $statement);

        return (int) $statement->fetchColumn();
    }
}
