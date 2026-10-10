<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartPartSealGuard;
use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\LocalMultipartCompletionIntentStore;
use OpsFour\S3Server\Metadata\MultipartCompletionCapacityReader;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageGarbageCollector;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class ExternalMultipartPartIntentTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_local_completion_retirement_requires_an_unarmed_or_matching_active_attempt(
        string $driver,
        string $dsnEnv,
    ): void {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} local completion-retirement gate.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(LocalMultipartCompletionIntentStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $owner = 'completion-retire-owner-' . $suffix;
        $bucket = 'completion-retire-' . $suffix;
        $uploads = [];
        $metadata->createBucket($owner, $bucket, 'us-east-1');
        $newIntent = static function (string $label) use ($metadata, $owner, $bucket, &$uploads): MultipartWriteIntent {
            $uploadId = bin2hex(random_bytes(16));
            $uploads[] = $uploadId;
            $metadata->createMultipartUpload($uploadId, $bucket, $label, $owner);
            $generation = $metadata->getBucket($bucket)?->generation;
            self::assertNotNull($generation);
            $leaseUntil = $metadata->multipartWriteIntentNow() + 600.0;

            return new MultipartWriteIntent(
                attemptId: bin2hex(random_bytes(16)),
                uploadId: $uploadId,
                bucket: $bucket,
                bucketGeneration: $generation,
                ownerId: $owner,
                storageTier: 'STANDARD',
                assemblyPath: "assembly/{$label}",
                finalPath: "final/{$label}",
                partDigest: str_repeat('a', 64),
                sseMode: null,
                leaseUntil: $leaseUntil,
                nextAttemptAt: $leaseUntil,
            );
        };

        try {
            $unarmed = $newIntent('unarmed');
            $metadata->createMultipartWriteIntent($unarmed);
            self::assertTrue($metadata->retireLocalMultipartWriteIntentBeforeDispatch(
                $unarmed->attemptId,
                bin2hex(random_bytes(16)),
            ));
            self::assertNull($metadata->getMultipartWriteIntent($unarmed->attemptId));

            $matching = $newIntent('matching');
            $matchingToken = bin2hex(random_bytes(16));
            $metadata->createMultipartWriteIntent($matching);
            self::assertTrue($metadata->armMultipartWriteIntent(
                $matching->attemptId,
                $matchingToken,
                $matching->leaseUntil,
                $matching->leaseUntil + 60.0,
            ));
            self::assertTrue($metadata->retireLocalMultipartWriteIntentBeforeDispatch(
                $matching->attemptId,
                $matchingToken,
            ));
            self::assertNull($metadata->getMultipartWriteIntent($matching->attemptId));

            $foreign = $newIntent('foreign');
            $foreignToken = bin2hex(random_bytes(16));
            $metadata->createMultipartWriteIntent($foreign);
            self::assertTrue($metadata->armMultipartWriteIntent(
                $foreign->attemptId,
                $foreignToken,
                $foreign->leaseUntil,
                $foreign->leaseUntil + 60.0,
            ));
            self::assertFalse($metadata->retireLocalMultipartWriteIntentBeforeDispatch(
                $foreign->attemptId,
                bin2hex(random_bytes(16)),
            ));
            $foreignRetained = $metadata->getMultipartWriteIntent($foreign->attemptId);
            self::assertNotNull($foreignRetained);
            self::assertSame('active', $foreignRetained->state);
            self::assertSame($foreignToken, $foreignRetained->dispatchId);

            $abandoned = $newIntent('abandoned');
            $metadata->createMultipartWriteIntent($abandoned);
            self::assertTrue($metadata->abandonMultipartWriteIntent(
                $abandoned->attemptId,
                $metadata->multipartWriteIntentNow() + 600.0,
            ));
            self::assertFalse($metadata->retireLocalMultipartWriteIntentBeforeDispatch(
                $abandoned->attemptId,
                bin2hex(random_bytes(16)),
            ));
            self::assertSame('abandoned', $metadata->getMultipartWriteIntent($abandoned->attemptId)?->state);

            $claimed = $newIntent('claimed');
            $metadata->createMultipartWriteIntent($claimed);
            self::assertTrue($metadata->abandonMultipartWriteIntent($claimed->attemptId, -1_000_000_000_000.0));
            $claims = $metadata->claimDueMultipartWriteIntents(
                1,
                $metadata->multipartWriteIntentNow(),
                $metadata->multipartWriteIntentNow() + 600.0,
            );
            self::assertCount(1, $claims);
            self::assertSame($claimed->attemptId, $claims[0]->attemptId);
            self::assertSame(1, $claims[0]->claimGeneration);
            self::assertFalse($metadata->retireLocalMultipartWriteIntentBeforeDispatch(
                $claimed->attemptId,
                bin2hex(random_bytes(16)),
            ));
            self::assertSame(1, $metadata->getMultipartWriteIntent($claimed->attemptId)?->claimGeneration);
        } finally {
            $pool->execute(
                $driver === 'postgres'
                    ? 'DELETE FROM s3_multipart_write_intents WHERE owner_id = $1'
                    : 'DELETE FROM s3_multipart_write_intents WHERE owner_id = ?',
                [$owner],
            );
            foreach ($uploads as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            $metadata->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_due_undispatched_local_completion_retirement_honors_limit_tier_and_fences(
        string $driver,
        string $dsnEnv,
    ): void {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} due completion-retirement gate.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(LocalMultipartCompletionIntentStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $owner = 'completion-due-owner-' . $suffix;
        $bucket = 'completion-due-' . $suffix;
        $uploads = [];
        $metadata->createBucket($owner, $bucket, 'us-east-1');
        $newIntent = static function (
            string $label,
            string $tier,
            float $leaseUntil,
        ) use ($metadata, $owner, $bucket, &$uploads): MultipartWriteIntent {
            $uploadId = bin2hex(random_bytes(16));
            $uploads[] = $uploadId;
            $metadata->createMultipartUpload($uploadId, $bucket, $label, $owner);
            $generation = $metadata->getBucket($bucket)?->generation;
            self::assertNotNull($generation);

            return new MultipartWriteIntent(
                attemptId: bin2hex(random_bytes(16)),
                uploadId: $uploadId,
                bucket: $bucket,
                bucketGeneration: $generation,
                ownerId: $owner,
                storageTier: $tier,
                assemblyPath: "assembly/{$label}",
                finalPath: "final/{$label}",
                partDigest: str_repeat('b', 64),
                sseMode: null,
                leaseUntil: $leaseUntil,
                nextAttemptAt: $leaseUntil,
            );
        };

        try {
            $now = $metadata->multipartWriteIntentNow();
            $due = [
                $newIntent('due-a', 'STANDARD', $now - 40.0),
                $newIntent('due-b', 'STANDARD', $now - 30.0),
                $newIntent('due-c', 'STANDARD', $now - 20.0),
            ];
            foreach ($due as $intent) {
                $metadata->createMultipartWriteIntent($intent);
            }
            $abandoned = $newIntent('due-abandoned', 'STANDARD', $now + 600.0);
            $metadata->createMultipartWriteIntent($abandoned);
            self::assertTrue($metadata->abandonMultipartWriteIntent($abandoned->attemptId, $now - 10.0));

            $otherTier = $newIntent('other-tier', 'ARCHIVE', $now - 10.0);
            $metadata->createMultipartWriteIntent($otherTier);
            $upperTier = $newIntent('upper-tier', 'HOT', $now - 10.0);
            $lowerTier = $newIntent('lower-tier', 'hot', $now - 10.0);
            $metadata->createMultipartWriteIntent($upperTier);
            $metadata->createMultipartWriteIntent($lowerTier);
            $notDue = $newIntent('not-due', 'STANDARD', $now + 600.0);
            $metadata->createMultipartWriteIntent($notDue);

            $armed = $newIntent('armed', 'STANDARD', $now + 600.0);
            $armedToken = bin2hex(random_bytes(16));
            $metadata->createMultipartWriteIntent($armed);
            self::assertTrue($metadata->armMultipartWriteIntent(
                $armed->attemptId,
                $armedToken,
                $armed->leaseUntil,
                $armed->leaseUntil + 60.0,
            ));
            $pool->execute(
                $driver === 'postgres'
                    ? 'UPDATE s3_multipart_write_intents SET lease_until = $1, next_attempt_at = $1 WHERE attempt_id = $2'
                    : 'UPDATE s3_multipart_write_intents SET lease_until = ?, next_attempt_at = ? WHERE attempt_id = ?',
                $driver === 'postgres'
                    ? [$now - 5.0, $armed->attemptId]
                    : [$now - 5.0, $now - 5.0, $armed->attemptId],
            );

            self::assertSame(2, $metadata->retireDueUndispatchedLocalMultipartWriteIntents('STANDARD', 2));
            $removedAfterFirstPass = 0;
            foreach ([...$due, $abandoned] as $intent) {
                if ($metadata->getMultipartWriteIntent($intent->attemptId) === null) {
                    ++$removedAfterFirstPass;
                }
            }
            self::assertSame(2, $removedAfterFirstPass);

            self::assertSame(2, $metadata->retireDueUndispatchedLocalMultipartWriteIntents('STANDARD', 10));
            foreach ([...$due, $abandoned] as $intent) {
                self::assertNull($metadata->getMultipartWriteIntent($intent->attemptId));
            }
            self::assertNotNull($metadata->getMultipartWriteIntent($otherTier->attemptId));
            self::assertNotNull($metadata->getMultipartWriteIntent($notDue->attemptId));
            $armedRetained = $metadata->getMultipartWriteIntent($armed->attemptId);
            self::assertNotNull($armedRetained);
            self::assertSame($armedToken, $armedRetained->dispatchId);

            self::assertSame(1, $metadata->retireDueUndispatchedLocalMultipartWriteIntents('ARCHIVE', 10));
            self::assertNull($metadata->getMultipartWriteIntent($otherTier->attemptId));
            self::assertSame(1, $metadata->retireDueUndispatchedLocalMultipartWriteIntents('HOT', 10));
            self::assertNull($metadata->getMultipartWriteIntent($upperTier->attemptId));
            self::assertNotNull($metadata->getMultipartWriteIntent($lowerTier->attemptId));
            self::assertSame(1, $metadata->retireDueUndispatchedLocalMultipartWriteIntents('hot', 10));
            self::assertNull($metadata->getMultipartWriteIntent($lowerTier->attemptId));
            self::assertSame(0, $metadata->retireDueUndispatchedLocalMultipartWriteIntents('STANDARD', 10));
        } finally {
            $pool->execute(
                $driver === 'postgres'
                    ? 'DELETE FROM s3_multipart_write_intents WHERE owner_id = $1'
                    : 'DELETE FROM s3_multipart_write_intents WHERE owner_id = ?',
                [$owner],
            );
            foreach ($uploads as $uploadId) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            $metadata->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_claimed_local_part_seal_refuses_referenced_or_duplicate_exact_path(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} part-seal reference gate.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(LocalMultipartPartSealGuard::class, $metadata);
        self::assertInstanceOf(FencedMultipartPartWriteIntentStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $owner = 'part-seal-owner-' . $suffix;
        $bucket = 'part-seal-' . $suffix;
        $uploadId = bin2hex(random_bytes(16));
        $dispatchId = bin2hex(random_bytes(16));
        $metadata->createBucket($owner, $bucket, 'us-east-1');
        $metadata->createMultipartUpload($uploadId, $bucket, 'key', $owner);
        $generation = $metadata->getBucket($bucket)?->generation;
        self::assertNotNull($generation);
        $path = '/local/.parts/' . $uploadId . '/1-' . $generation . '-' . bin2hex(random_bytes(16));
        try {
            $metadata->reserveLocalMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $path);
            self::assertTrue($metadata->armLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId));
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE bucket = $1 AND storage_path = $2'
                : 'UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE bucket = ? AND BINARY storage_path = BINARY ?', [$bucket, $path]);
            $claims = $metadata->dequeueStorageGarbage(1);
            self::assertCount(1, $claims);
            $claim = $claims[0];
            self::assertSame($path, $claim['storage_path']);
            $leaseGeneration = $claim['lease_generation'] ?? null;
            self::assertIsInt($leaseGeneration);
            self::assertTrue($metadata->canSealLocalMultipartPartClaim($claim['id'], $path, $leaseGeneration));
            self::assertFalse($metadata->canSealLocalMultipartPartClaim($claim['id'], $path, $leaseGeneration - 1));

            // The real handler rolls this transaction back if its exact
            // pending intent cannot be consumed after a collector claim.
            try {
                $metadata->transaction(function () use ($metadata, $uploadId, $path, $bucket, $dispatchId): void {
                    $metadata->putPart($uploadId, 1, '"etag"', 4, $path);
                    if (! $metadata->consumeLocalMultipartPartWriteIntent($bucket, $uploadId, $path, $dispatchId)) {
                        throw new \RuntimeException('Claimed part intent cannot commit.');
                    }
                });
                self::fail('Claimed part intent committed a reference.');
            } catch (\RuntimeException $error) {
                self::assertSame('Claimed part intent cannot commit.', $error->getMessage());
            }
            self::assertSame([], $metadata->getParts($uploadId));

            $pool->execute(
                $driver === 'postgres'
                ? "INSERT INTO s3_storage_garbage
                    (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
                    VALUES ($1, 'STANDARD', $2, 'part_write_intent', 'key', $3, $4, 0, $5)"
                : "INSERT INTO s3_storage_garbage
                    (bucket, storage_tier, storage_path, operation, key_name, upload_id, owner_id, next_attempt_at, dispatch_id)
                    VALUES (?, 'STANDARD', ?, 'part_write_intent', 'key', ?, ?, 0, ?)",
                [$bucket, $path, $uploadId, $owner, bin2hex(random_bytes(16))],
            );
            self::assertFalse($metadata->canSealLocalMultipartPartClaim($claim['id'], $path, $leaseGeneration));

            $pool->execute(
                $driver === 'postgres'
                ? "DELETE FROM s3_storage_garbage WHERE bucket = $1 AND operation = 'part_write_intent' AND id != $2"
                : "DELETE FROM s3_storage_garbage WHERE bucket = ? AND operation = 'part_write_intent' AND id != ?",
                [$bucket, $claim['id']],
            );
            $metadata->putPart($uploadId, 1, '"etag"', 4, $path);
            self::assertFalse($metadata->canSealLocalMultipartPartClaim($claim['id'], $path, $leaseGeneration));
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_storage_garbage WHERE bucket = $1'
                : 'DELETE FROM s3_storage_garbage WHERE bucket = ?', [$bucket]);
            $metadata->deleteMultipartUpload($uploadId);
            $metadata->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_completion_global_capacity_serializes_owners_and_ignores_stale_mysql_snapshot(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} global completion-capacity gate.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $first);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $second);
        self::assertInstanceOf(MultipartCompletionCapacityReader::class, $first);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(5));
        $ownerA = 'global-owner-a-' . $suffix;
        $ownerB = 'global-owner-b-' . $suffix;
        $bucketA = 'global-cap-a-' . $suffix;
        $bucketB = 'global-cap-b-' . $suffix;
        $first->createBucket($ownerA, $bucketA, 'us-east-1');
        $first->createBucket($ownerB, $bucketB, 'us-east-1');
        $newIntent = static function (string $owner, string $bucket) use ($first): MultipartWriteIntent {
            $uploadId = bin2hex(random_bytes(16));
            $first->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            $generation = $first->getBucket($bucket)?->generation;
            self::assertNotNull($generation);
            $now = $first->multipartWriteIntentNow();

            return new MultipartWriteIntent(
                bin2hex(random_bytes(16)),
                $uploadId,
                $bucket,
                $generation,
                $owner,
                'STANDARD',
                'assembly',
                'final',
                str_repeat('a', 64),
                null,
                $now + 60,
                $now + 60,
            );
        };

        try {
            $pool->execute('UPDATE s3_multipart_completion_capacity SET retained_count = 999999 WHERE id = 1');
            $attemptA = $newIntent($ownerA, $bucketA);
            $attemptB = $newIntent($ownerB, $bucketB);
            $admit = static function (MultipartWriteIntentStore $store, MultipartWriteIntent $intent): string {
                try {
                    $store->createMultipartWriteIntent($intent);

                    return 'accepted';
                } catch (SlowDownException) {
                    return 'full';
                }
            };
            $futures = [
                async(fn(): string => $admit($first, $attemptA)),
                async(fn(): string => $admit($second, $attemptB)),
            ];
            $results = [$futures[0]->await(), $futures[1]->await()];
            sort($results);
            self::assertSame(['accepted', 'full'], $results);
            self::assertSame(1, (int) ($pool->query("SELECT COUNT(*) AS count FROM s3_multipart_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')")->fetchRow()['count'] ?? -1));
            self::assertSame(1000000, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));
            self::assertSame(1000000, $first->getMultipartCompletionIntentCount());

            $pool->execute("DELETE FROM s3_multipart_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')");
            self::assertSame(999999, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));
            $rollback = $newIntent($ownerA, $bucketA);
            try {
                $first->transaction(static function () use ($first, $rollback): void {
                    $first->createMultipartWriteIntent($rollback);
                    throw new \RuntimeException('rollback counter reservation');
                });
            } catch (\RuntimeException $error) {
                self::assertSame('rollback counter reservation', $error->getMessage());
            }
            self::assertNull($second->getMultipartWriteIntent($rollback->attemptId));
            self::assertSame(999999, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));

            $stale = $newIntent($ownerA, $bucketA);
            $winner = $newIntent($ownerB, $bucketB);
            try {
                $first->transaction(static function () use ($first, $second, $bucketA, $stale, $winner): void {
                    self::assertNotNull($first->getBucket($bucketA)); // Establish the MySQL repeatable-read snapshot.
                    $second->createMultipartWriteIntent($winner);
                    $first->createMultipartWriteIntent($stale);
                });
                self::fail('A stale snapshot admitted another owner above the global cap.');
            } catch (SlowDownException) {
                self::assertNull($first->getMultipartWriteIntent($stale->attemptId));
            }
            self::assertSame(1000000, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));
            try {
                $pool->execute('UPDATE s3_multipart_completion_capacity SET retained_count = 1000001 WHERE id = 1');
                self::fail('The database accepted a counter above the global limit.');
            } catch (\Throwable $error) {
                self::assertStringContainsString('constraint', strtolower($error->getMessage()));
            }
            $pool->execute('DELETE FROM s3_multipart_completion_capacity WHERE id = 1');
            try {
                $first->createMultipartWriteIntent($newIntent($ownerA, $bucketA));
                self::fail('A missing global counter row admitted a completion.');
            } catch (SlowDownException) {
                self::assertSame(1, (int) ($pool->query("SELECT COUNT(*) AS count FROM s3_multipart_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')")->fetchRow()['count'] ?? -1));
            } finally {
                $pool->execute('INSERT INTO s3_multipart_completion_capacity (id, retained_count) VALUES (1, 1000000)');
            }
            $candidate = $newIntent($ownerA, $bucketA);
            $delete = async(fn() => $pool->execute("DELETE FROM s3_multipart_write_intents WHERE attempt_id = '{$winner->attemptId}'"));
            $admission = async(fn(): string => $admit($first, $candidate));
            $delete->await();
            self::assertContains($admission->await(), ['accepted', 'full']);
            $physicalCount = (int) ($pool->query("SELECT COUNT(*) AS count FROM s3_multipart_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')")->fetchRow()['count'] ?? -1);
            self::assertContains($physicalCount, [0, 1]);
            self::assertSame(999999 + $physicalCount, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));
        } finally {
            $pool->execute("DELETE FROM s3_multipart_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')");
            $pool->execute('UPDATE s3_multipart_completion_capacity SET retained_count = (SELECT COUNT(*) FROM s3_multipart_write_intents) WHERE id = 1');
            $first->deleteBucket($ownerA, $bucketA);
            $first->deleteBucket($ownerB, $bucketB);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_completion_capacity_migration_backfills_retained_rows(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} completion-capacity migration gate.");
        }

        MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $version = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
        $beforeCompletionVersion = $driver === 'postgres' ? 34 : 36;
        $attempt = bin2hex(random_bytes(16));
        $before = (int) ($pool->query('SELECT COUNT(*) AS intent_count FROM s3_multipart_write_intents')->fetchRow()['intent_count'] ?? -1);
        try {
            $pool->query('DROP TRIGGER IF EXISTS s3_multipart_completion_capacity_insert' . ($driver === 'postgres' ? ' ON s3_multipart_write_intents' : ''));
            $pool->query('DROP TRIGGER IF EXISTS s3_multipart_completion_capacity_delete' . ($driver === 'postgres' ? ' ON s3_multipart_write_intents' : ''));
            $pool->query('DROP TABLE s3_multipart_completion_capacity');
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_schema_version WHERE version > $1'
                : 'DELETE FROM s3_schema_version WHERE version > ?', [$beforeCompletionVersion]);
            $pool->execute("INSERT INTO s3_schema_version (version, description)
                SELECT {$beforeCompletionVersion}, 'Completion capacity migration fixture'
                WHERE NOT EXISTS (SELECT 1 FROM s3_schema_version WHERE version = {$beforeCompletionVersion})");
            $pool->execute("INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
                VALUES ('{$attempt}', 'upload', 'bucket', 'generation', 'owner', 'STANDARD',
                 'assembly', 'final', 'digest', 'abandoned', 0, 0)");

            MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)], migrate: true);
            self::assertSame($version, (int) ($pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow()['version'] ?? -1));
            self::assertSame($before + 1, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));
            $pool->execute("DELETE FROM s3_multipart_write_intents WHERE attempt_id = '{$attempt}'");
            self::assertSame($before, (int) ($pool->query('SELECT retained_count FROM s3_multipart_completion_capacity WHERE id = 1')->fetchRow()['retained_count'] ?? -1));
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_schema_version WHERE version > $1'
                : 'DELETE FROM s3_schema_version WHERE version > ?', [$beforeCompletionVersion]);
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_multipart_completion_capacity SET retained_count = $1 WHERE id = 1'
                : 'UPDATE s3_multipart_completion_capacity SET retained_count = ? WHERE id = 1', [$before + 1]);
            try {
                MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)], migrate: true);
                self::fail('The migration accepted a drifted counter row.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('capacity counter is inconsistent', $error->getMessage());
            }
            self::assertSame($beforeCompletionVersion, (int) ($pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow()['version'] ?? -1));
            $pool->execute($driver === 'postgres'
                ? 'UPDATE s3_multipart_completion_capacity SET retained_count = $1 WHERE id = 1'
                : 'UPDATE s3_multipart_completion_capacity SET retained_count = ? WHERE id = 1', [$before]);
            MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)], migrate: true);
            self::assertSame($version, (int) ($pool->query('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow()['version'] ?? -1));
        } finally {
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_completion_intent_cap_serializes_two_nodes_and_isolates_owners(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} completion-intent admission gate.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $first);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $second);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(5));
        $ownerA = 'completion-owner-a-' . $suffix;
        $ownerB = 'completion-owner-b-' . $suffix;
        $bucketA = 'completion-cap-a-' . $suffix;
        $bucketB = 'completion-cap-b-' . $suffix;
        $first->createBucket($ownerA, $bucketA, 'us-east-1');
        $first->createBucket($ownerB, $bucketB, 'us-east-1');

        try {
            $valueRows = [];
            for ($i = 0; $i < MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS - 1; $i++) {
                $valueRows[] = "('" . bin2hex(random_bytes(16)) . "', 'retired', '{$bucketA}', 'generation', '{$ownerA}', 'STANDARD',
                    'assembly', 'final', 'digest', 'abandoned', 0, 0)";
                if (count($valueRows) === 250) {
                    $pool->execute("INSERT INTO s3_multipart_write_intents
                        (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                         assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
                        VALUES " . implode(',', $valueRows));
                    $valueRows = [];
                }
            }
            if ($valueRows !== []) {
                $pool->execute("INSERT INTO s3_multipart_write_intents
                    (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                     assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
                    VALUES " . implode(',', $valueRows));
            }

            $newIntent = static function (string $owner, string $bucket) use ($first): MultipartWriteIntent {
                $uploadId = bin2hex(random_bytes(16));
                $first->createMultipartUpload($uploadId, $bucket, 'key', $owner);
                $bucketInfo = $first->getBucket($bucket);
                self::assertNotNull($bucketInfo);

                return new MultipartWriteIntent(
                    attemptId: bin2hex(random_bytes(16)),
                    uploadId: $uploadId,
                    bucket: $bucket,
                    bucketGeneration: $bucketInfo->generation,
                    ownerId: $owner,
                    storageTier: 'STANDARD',
                    assemblyPath: 'assembly',
                    finalPath: 'final',
                    partDigest: str_repeat('a', 64),
                    sseMode: 'AES256',
                    leaseUntil: 1000,
                    nextAttemptAt: 1000,
                );
            };
            $attemptA = $newIntent($ownerA, $bucketA);
            $attemptB = $newIntent($ownerA, $bucketA);
            $admit = static function (MultipartWriteIntentStore $store, MultipartWriteIntent $intent): string {
                try {
                    $store->createMultipartWriteIntent($intent);

                    return 'accepted';
                } catch (SlowDownException) {
                    return 'full';
                }
            };
            $futures = [
                async(fn(): string => $admit($first, $attemptA)),
                async(fn(): string => $admit($second, $attemptB)),
            ];
            $results = [$futures[0]->await(), $futures[1]->await()];
            sort($results);
            self::assertSame(['accepted', 'full'], $results);
            $count = $pool->execute("SELECT COUNT(*) AS intent_count FROM s3_multipart_write_intents WHERE owner_id = '{$ownerA}'")->fetchRow();
            self::assertSame(MultipartWriteIntentStore::MAX_OUTSTANDING_COMPLETION_INTENTS, (int) ($count['intent_count'] ?? 0));
            $first->createMultipartWriteIntent($newIntent($ownerB, $bucketB));
            $otherCount = $pool->execute("SELECT COUNT(*) AS intent_count FROM s3_multipart_write_intents WHERE owner_id = '{$ownerB}'")->fetchRow();
            self::assertSame(1, (int) ($otherCount['intent_count'] ?? 0));
        } finally {
            $pool->execute("DELETE FROM s3_multipart_write_intents WHERE owner_id IN ('{$ownerA}', '{$ownerB}')");
            $first->deleteBucket($ownerA, $bucketA);
            $first->deleteBucket($ownerB, $bucketB);
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_two_connections_fence_committed_and_late_written_parts(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} part-intent gate.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $first);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $second);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'part-intent-' . bin2hex(random_bytes(5));
        $owner = 'owner-' . bin2hex(random_bytes(5));
        $uploadId = bin2hex(random_bytes(16));
        $storage = new InMemoryBackend();

        try {
            $first->createBucket($owner, $bucket, 'us-east-1');
            $storage->createBucket($bucket);
            $first->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            $generation = $first->getBucket($bucket)?->generation;
            self::assertNotNull($generation);

            $committed = $storage->planMultipartPartPath($bucket, $generation, $uploadId, 1, bin2hex(random_bytes(16)));
            $first->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $committed);
            $storage->putPartAtPath($bucket, 'key', $uploadId, 1, new ReadableBuffer('committed'), $committed);
            $second->transaction(function () use ($second, $owner, $bucket, $uploadId, $committed): void {
                $second->lockOwnerForUpdate($owner);
                $second->putPart($uploadId, 1, '"' . md5('plaintext') . '"', 9, $committed);
                self::assertTrue($second->consumeMultipartPartWriteIntent($bucket, $uploadId, $committed));
            });
            self::assertSame($committed, $first->getParts($uploadId)[0]['storage_path']);

            $orphan = $storage->planMultipartPartPath($bucket, $generation, $uploadId, 2, bin2hex(random_bytes(16)));
            $first->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $orphan);
            $storage->putPartAtPath($bucket, 'key', $uploadId, 2, new ReadableBuffer('orphan'), $orphan);
            $pool->execute("UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = '{$orphan}'");
            self::assertSame(1, (new StorageGarbageCollector($second, StorageTierRegistry::single($storage)))->collect(1));
            self::assertSame('committed', \Amp\ByteStream\buffer($storage->getObjectByPath($committed)));
            $this->assertMissing($storage, $orphan);

            $storage->putPartAtPath($bucket, 'key', $uploadId, 2, new ReadableBuffer('late orphan'), $orphan);
            $pool->execute("UPDATE s3_storage_garbage SET next_attempt_at = 0 WHERE storage_path = '{$orphan}'");
            self::assertSame(1, (new StorageGarbageCollector($first, StorageTierRegistry::single($storage)))->collect(1));
            $this->assertMissing($storage, $orphan);
            self::assertSame($committed, $second->getParts($uploadId)[0]['storage_path']);
        } finally {
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_owner_cap_is_atomic_across_connections_and_indexed_amid_garbage(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} owner-admission gate.");
        }

        $first = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $second = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $first);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $second);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucketA = 'part-owner-a-' . bin2hex(random_bytes(5));
        $bucketB = 'part-owner-b-' . bin2hex(random_bytes(5));
        $ownerSuffix = bin2hex(random_bytes(5));
        $ownerA = 'Owner-' . $ownerSuffix;
        $ownerB = 'owner-' . $ownerSuffix;
        $uploadOne = bin2hex(random_bytes(16));
        $uploadTwo = bin2hex(random_bytes(16));
        $uploadB = bin2hex(random_bytes(16));
        $storage = new InMemoryBackend();

        try {
            $first->createBucket($ownerA, $bucketA, 'us-east-1');
            $first->createBucket($ownerB, $bucketB, 'us-east-1');
            $first->createMultipartUpload($uploadOne, $bucketA, 'key', $ownerA);
            $first->createMultipartUpload($uploadTwo, $bucketA, 'key', $ownerA);
            $first->createMultipartUpload($uploadB, $bucketB, 'key', $ownerB);
            $generationA = $first->getBucket($bucketA)?->generation;
            $generationB = $first->getBucket($bucketB)?->generation;
            self::assertNotNull($generationA);
            self::assertNotNull($generationB);

            $value = "('{$bucketA}', 'STANDARD', 'retained', 'part_write_intent', '{$ownerA}', 9999999999)";
            $pool->execute('INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, owner_id, next_attempt_at) VALUES '
                . implode(',', array_fill(0, MultipartPartWriteIntentStore::MAX_OUTSTANDING_INTENTS - 1, $value)));
            $pathOne = $storage->planMultipartPartPath($bucketA, $generationA, $uploadOne, 1, bin2hex(random_bytes(16)));
            $pathTwo = $storage->planMultipartPartPath($bucketA, $generationA, $uploadTwo, 1, bin2hex(random_bytes(16)));

            $reserve = static function (MultipartPartWriteIntentStore $store, string $uploadId, string $path) use ($bucketA, $generationA): bool {
                try {
                    $store->createMultipartPartWriteIntent($bucketA, $generationA, 'STANDARD', $uploadId, $path);

                    return true;
                } catch (SlowDownException) {
                    return false;
                }
            };
            $one = async(fn(): bool => $reserve($first, $uploadOne, $pathOne));
            $two = async(fn(): bool => $reserve($second, $uploadTwo, $pathTwo));
            self::assertSame(1, (int) $one->await() + (int) $two->await());

            $pathB = $storage->planMultipartPartPath($bucketB, $generationB, $uploadB, 1, bin2hex(random_bytes(16)));
            $second->createMultipartPartWriteIntent($bucketB, $generationB, 'STANDARD', $uploadB, $pathB);
            $ownerCountSql = $driver === 'postgres'
                ? "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = $1"
                : "SELECT COUNT(*) AS n FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = ?";
            self::assertSame(
                MultipartPartWriteIntentStore::MAX_OUTSTANDING_INTENTS,
                (int) ($pool->execute($ownerCountSql, [$ownerA])->fetchRow()['n'] ?? -1),
            );
            self::assertSame(1, (int) ($pool->execute($ownerCountSql, [$ownerB])->fetchRow()['n'] ?? -1));

            $ordinary = "('{$bucketA}', 'STANDARD', 'ordinary', 9999999999)";
            $pool->execute('INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, next_attempt_at) VALUES '
                . implode(',', array_fill(0, 10_000, $ordinary)));
            if ($driver === 'postgres') {
                $pool->execute('ANALYZE s3_storage_garbage');
                $planRows = $pool->execute("EXPLAIN SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = '{$ownerB}'");
                $plan = '';
                while (($row = $planRows->fetchRow()) !== null) {
                    $step = $row['QUERY PLAN'] ?? null;
                    if (is_scalar($step)) {
                        $plan .= ' ' . $step;
                    }
                }
                self::assertStringContainsString('idx_s3_storage_gc_operation_owner', $plan);
            } else {
                $pool->execute('ANALYZE TABLE s3_storage_garbage');
                $plan = $pool->execute("EXPLAIN SELECT COUNT(*) FROM s3_storage_garbage WHERE operation = 'part_write_intent' AND owner_id = '{$ownerB}'")->fetchRow();
                self::assertSame('idx_s3_storage_gc_operation_owner', $plan['key'] ?? null);
            }
        } finally {
            $pool->close();
        }
    }

    #[DataProvider('databases')]
    public function test_upgrade_backfills_only_matching_live_part_intents(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} part-intent upgrade gate.");
        }

        $store = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $bucket = 'part-upgrade-' . bin2hex(random_bytes(5));
        $owner = 'owner-upgrade-' . bin2hex(random_bytes(5));
        $uploadId = bin2hex(random_bytes(16));
        $abortedId = bin2hex(random_bytes(16));
        $storage = new InMemoryBackend();

        try {
            $store->createBucket($owner, $bucket, 'us-east-1');
            $store->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            $generation = $store->getBucket($bucket)?->generation;
            self::assertNotNull($generation);
            $oldGeneration = bin2hex(random_bytes(16));
            $live = $storage->planMultipartPartPath($bucket, $generation, $uploadId, 1, bin2hex(random_bytes(16)));
            $reused = $storage->planMultipartPartPath($bucket, $oldGeneration, $uploadId, 2, bin2hex(random_bytes(16)));
            $aborted = $storage->planMultipartPartPath($bucket, $oldGeneration, $abortedId, 1, bin2hex(random_bytes(16)));
            foreach ([[$live, $uploadId], [$reused, $uploadId], [$aborted, $abortedId]] as [$path, $id]) {
                $sql = $driver === 'postgres'
                    ? "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at)
                       VALUES ($1, 'STANDARD', $2, 'part_write_intent', $3, 9999999999)"
                    : "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at)
                       VALUES (?, 'STANDARD', ?, 'part_write_intent', ?, 9999999999)";
                $pool->execute($sql, [$bucket, $path, $id]);
            }

            if ($driver === 'postgres') {
                $pool->execute('DROP INDEX idx_s3_storage_gc_operation_owner');
                $pool->execute('ALTER TABLE s3_storage_garbage DROP COLUMN owner_id');
            } else {
                $pool->execute('ALTER TABLE s3_storage_garbage DROP INDEX idx_s3_storage_gc_operation_owner');
                $pool->execute('ALTER TABLE s3_storage_garbage DROP COLUMN owner_id');
            }
            // Reapply the exact owner-backfill migration statements to the
            // deliberately downgraded table. The rest of this disposable
            // database remains at the current schema version.
            $ownerMigration = $driver === 'postgres'
                ? PostgresSchema::getMigrationStatements(24)
                : MysqlSchema::getMigrationStatements(26);
            $ownerMigration = array_slice($ownerMigration, 0, 3);
            self::assertCount(3, $ownerMigration);
            foreach ($ownerMigration as $statement) {
                $pool->execute($statement);
            }

            $lookup = $driver === 'postgres'
                ? 'SELECT owner_id FROM s3_storage_garbage WHERE storage_path = $1'
                : 'SELECT owner_id FROM s3_storage_garbage WHERE storage_path = ?';
            self::assertSame($owner, $pool->execute($lookup, [$live])->fetchRow()['owner_id'] ?? null);
            self::assertNull($pool->execute($lookup, [$reused])->fetchRow()['owner_id'] ?? null);
            self::assertNull($pool->execute($lookup, [$aborted])->fetchRow()['owner_id'] ?? null);
            $version = $driver === 'postgres' ? PostgresSchema::VERSION : MysqlSchema::VERSION;
            self::assertSame($version, (int) ($pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow()['version'] ?? -1));

            $completionAttempt = bin2hex(random_bytes(16));
            $pool->execute("INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, state, lease_until, next_attempt_at)
                VALUES ('{$completionAttempt}', '{$uploadId}', '{$bucket}', '{$generation}', '{$owner}',
                        'STANDARD', 'assembly', 'final', 'digest', 'abandoned', 0, 0)");
            $pool->execute($driver === 'postgres'
                ? 'DROP INDEX idx_s3_multipart_intents_owner'
                : 'ALTER TABLE s3_multipart_write_intents DROP INDEX idx_s3_multipart_intents_owner');
            $previousVersion = $driver === 'postgres' ? 26 : 28;
            $completionMigration = $driver === 'postgres'
                ? PostgresSchema::getMigrationStatements($previousVersion)
                : MysqlSchema::getMigrationStatements($previousVersion);
            self::assertStringContainsString('idx_s3_multipart_intents_owner', $completionMigration[0]);
            $pool->execute($completionMigration[0]);
            $index = $driver === 'postgres'
                ? $pool->execute("SELECT indexname AS name FROM pg_indexes
                    WHERE tablename = 's3_multipart_write_intents'
                      AND indexname = 'idx_s3_multipart_intents_owner'")->fetchRow()
                : $pool->execute("SELECT INDEX_NAME AS name FROM information_schema.statistics
                    WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 's3_multipart_write_intents'
                      AND INDEX_NAME = 'idx_s3_multipart_intents_owner'")->fetchRow();
            self::assertSame('idx_s3_multipart_intents_owner', $index['name'] ?? null);
            $retained = $pool->execute("SELECT state FROM s3_multipart_write_intents WHERE attempt_id = '{$completionAttempt}'")->fetchRow();
            self::assertSame('abandoned', $retained['state'] ?? null);
            self::assertSame($version, (int) ($pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow()['version'] ?? -1));
        } finally {
            $pool->close();
        }
    }

    private function assertMissing(InMemoryBackend $storage, string $path): void
    {
        try {
            $storage->getObjectByPath($path);
            self::fail('Uncommitted part is still readable.');
        } catch (NoSuchKeyException) {
            return;
        }
    }
}
