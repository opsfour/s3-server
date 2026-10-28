<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectWriteIntentStoreTest extends TestCase
{
    private string $databasePath;

    protected function setUp(): void
    {
        parent::setUp();
        $path = tempnam(sys_get_temp_dir(), 's3-physical-intent-');
        self::assertIsString($path);
        $this->databasePath = $path;
    }

    protected function tearDown(): void
    {
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
        parent::tearDown();
    }

    public function test_upgrade_preserves_existing_rows_and_installs_pathwise_table(): void
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $schema = new SchemaManager($pdo);
        $schema->migrate(26);
        $pdo->exec("INSERT INTO s3_multipart_write_intents
            (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
             assembly_path, final_path, part_digest, lease_until, next_attempt_at)
            VALUES ('legacy', 'upload', 'bucket', 'generation', 'owner', 'STANDARD',
             'assembly', 'final', 'digest', 0, 0)");

        $schema->migrate();
        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $oldRows = $pdo->query('SELECT COUNT(*) FROM s3_multipart_write_intents');
        $newRows = $pdo->query('SELECT COUNT(*) FROM s3_object_write_intents');
        self::assertInstanceOf(\PDOStatement::class, $oldRows);
        self::assertInstanceOf(\PDOStatement::class, $newRows);
        self::assertSame(1, (int) $oldRows->fetchColumn());
        self::assertSame(0, (int) $newRows->fetchColumn());

        foreach ([
            [PostgresSchema::getMigrationStatements(27), PostgresSchema::getCreateStatements()],
            [MysqlSchema::getMigrationStatements(29), MysqlSchema::getCreateStatements()],
        ] as [$upgrade, $fresh]) {
            self::assertTrue(self::containsObjectWriteTable($upgrade));
            self::assertTrue(self::containsObjectWriteTable($fresh));
        }
    }

    public function test_upgrade_marks_old_attempts_as_unknown_in_flight_and_new_attempts_as_undispatched(): void
    {
        $pdo = new \PDO('sqlite:' . $this->databasePath);
        $schema = new SchemaManager($pdo);
        $schema->migrate(27);
        $pdo->exec("INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
             path_digest, purpose, state, lease_until)
            VALUES ('11111111111111111111111111111111', 'bucket', 'ffffffffffffffffffffffffffffffff',
                    'owner', 'STANDARD', 'objects/legacy', 'digest', 'object', 'active', 0)");
        $schema->migrate();
        $legacy = $pdo->query("SELECT dispatch_id FROM s3_object_write_intents WHERE storage_path = 'objects/legacy'");
        self::assertInstanceOf(\PDOStatement::class, $legacy);
        self::assertSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $legacy->fetchColumn());

        $store = new SqliteMetadataStore($this->databasePath);
        $store->initialize();
        $new = $store->reserveObjectWriteIntent($this->intent('2', 'objects/new'), 60.0);
        self::assertNull($new->dispatchId);
        $claimed = $store->claimDueObjectWriteIntents('STANDARD', 10, 60.0);
        self::assertCount(1, $claimed);
        self::assertSame(ObjectWriteIntent::LEGACY_UNKNOWN_DISPATCH, $claimed[0]->dispatchId);
        self::assertSame('reconciling', $claimed[0]->state);
        self::assertNull($store->getObjectWriteIntent($new->attemptId)?->dispatchId);
    }

    public function test_one_shot_dispatch_rejects_rearm_and_untokened_consume(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('3', 'objects/one-shot'), 60.0);
        $dispatch = str_repeat('a', 32);
        $armed = $store->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, $dispatch, 60.0);
        self::assertNotNull($armed);
        self::assertSame($dispatch, $armed->dispatchId);
        self::assertNull($store->armObjectWriteIntent($reserved->attemptId, $armed->leaseUntil, str_repeat('b', 32), 60.0));
        self::assertFalse($store->retireUndispatchedObjectWriteIntent($reserved->attemptId));
        self::assertFalse($store->retireTerminalPreLinkObjectWriteIntent($reserved->attemptId, str_repeat('b', 32)));
        self::assertFalse($store->consumeObjectWriteIntent($reserved->attemptId));
        self::assertFalse($store->consumeArmedObjectWriteIntent($reserved->attemptId, str_repeat('b', 32)));
        $store->beginTransaction();
        self::assertTrue($store->consumeArmedObjectWriteIntent($reserved->attemptId, $dispatch));
        $store->rollback();
        self::assertSame($dispatch, $store->getObjectWriteIntent($reserved->attemptId)?->dispatchId);
        self::assertTrue($store->retireTerminalPreLinkObjectWriteIntent($reserved->attemptId, $dispatch));
        self::assertNull($store->getObjectWriteIntent($reserved->attemptId));
    }

    public function test_dispatch_requires_independent_commit_before_worker_submission(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('5', 'objects/not-committed'), 60.0);
        $store->beginTransaction();
        try {
            $this->expectException(\LogicException::class);
            $store->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, str_repeat('c', 32), 60.0);
        } finally {
            $store->rollback();
            self::assertNull($store->getObjectWriteIntent($reserved->attemptId)?->dispatchId);
        }
    }

    public function test_expired_undispatched_attempt_is_retired_without_a_collector_claim(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('4', 'objects/never-started'), 60.0);
        $store->abandonObjectWriteIntent($reserved->attemptId);
        self::assertSame([], $store->claimDueObjectWriteIntents('STANDARD', 10, 60.0));
        self::assertSame(1, $store->retireExpiredUndispatchedObjectWriteIntents('STANDARD', 10));
        self::assertNull($store->getObjectWriteIntent($reserved->attemptId));
    }

    public function test_publish_consume_rolls_back_with_metadata_transaction_and_collector_cannot_win_afterward(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('a', 'objects/a'), 60.0);
        self::assertSame('active', $reserved->state);
        self::assertGreaterThan(0.0, $reserved->leaseUntil);

        $store->beginTransaction();
        self::assertTrue($store->consumeObjectWriteIntent($reserved->attemptId));
        $store->rollback();
        self::assertNotNull($store->getObjectWriteIntent($reserved->attemptId));

        $store->beginTransaction();
        self::assertTrue($store->consumeObjectWriteIntent($reserved->attemptId));
        $store->commit();
        self::assertNull($store->getObjectWriteIntent($reserved->attemptId));
        self::assertSame([], $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
    }

    public function test_reservation_requires_a_separate_committed_transaction(): void
    {
        $store = $this->store();
        $intent = $this->intent('a', 'objects/separate');

        $store->beginTransaction();
        try {
            $store->reserveObjectWriteIntent($intent, 60.0);
            self::fail('Admission inside an outer transaction must be rejected.');
        } catch (\LogicException $error) {
            self::assertSame('Physical write admission requires its own transaction.', $error->getMessage());
        } finally {
            $store->rollback();
        }

        self::assertNull($store->getObjectWriteIntent($intent->attemptId));
        self::assertSame($intent->attemptId, $store->reserveObjectWriteIntent($intent, 60.0)->attemptId);
    }

    public function test_collector_claim_requires_a_separate_committed_transaction(): void
    {
        $store = $this->store();
        $intent = $store->reserveObjectWriteIntent($this->intent('b', 'objects/claim'), 60.0);
        $intent = $this->arm($store, $intent);
        self::assertTrue($store->abandonObjectWriteIntent($intent->attemptId));

        $store->beginTransaction();
        try {
            $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
            self::fail('Claim inside an outer transaction must be rejected.');
        } catch (\LogicException $error) {
            self::assertSame('Physical write claims require their own transaction.', $error->getMessage());
        } finally {
            $store->rollback();
        }

        self::assertSame('active', $store->getObjectWriteIntent($intent->attemptId)?->state);
        self::assertSame($intent->attemptId, $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0)[0]->attemptId);
    }

    public function test_claim_selects_only_its_tier_even_when_another_tier_is_due_first(): void
    {
        $store = $this->store();
        $other = $store->reserveObjectWriteIntent($this->intent('a', 'objects/other', 'REMOTE'), 60.0);
        $other = $this->arm($store, $other);
        self::assertTrue($store->abandonObjectWriteIntent($other->attemptId));
        $local = $store->reserveObjectWriteIntent($this->intent('b', 'objects/local'), 60.0);
        $local = $this->arm($store, $local);
        self::assertTrue($store->abandonObjectWriteIntent($local->attemptId));

        $localClaim = $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $localClaim);
        self::assertSame($local->attemptId, $localClaim[0]->attemptId);
        self::assertSame('active', $store->getObjectWriteIntent($other->attemptId)?->state);
        self::assertSame([], $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0));

        $otherClaim = $store->claimDueObjectWriteIntents('REMOTE', 1, 60.0);
        self::assertCount(1, $otherClaim);
        self::assertSame($other->attemptId, $otherClaim[0]->attemptId);
    }

    public function test_expired_attempt_claim_blocks_late_publish_and_stale_collector(): void
    {
        $first = $this->store();
        $second = new SqliteMetadataStore($this->databasePath);
        $second->initialize();
        $reserved = $first->reserveObjectWriteIntent($this->intent('b', 'objects/b'), 60.0);
        $reserved = $this->arm($first, $reserved);

        $this->expire($reserved->attemptId);
        self::assertFalse($first->consumeObjectWriteIntent($reserved->attemptId));
        self::assertNull($first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 60.0));

        $firstClaim = $second->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $firstClaim);
        self::assertSame('reconciling', $firstClaim[0]->state);
        self::assertSame(1, $firstClaim[0]->claimGeneration);
        self::assertSame([], $first->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
        self::assertFalse($first->consumeObjectWriteIntent($reserved->attemptId));

        $this->expire($reserved->attemptId);
        $third = new SqliteMetadataStore($this->databasePath);
        $third->initialize();
        $secondClaim = $third->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $secondClaim);
        self::assertSame(2, $secondClaim[0]->claimGeneration);
        self::assertFalse($second->sealObjectWriteIntent($reserved->attemptId, $firstClaim[0]->claimGeneration));
        self::assertTrue($third->sealObjectWriteIntent($reserved->attemptId, $secondClaim[0]->claimGeneration));
        self::assertSame('sealed', $first->getObjectWriteIntent($reserved->attemptId)?->state);
    }

    public function test_sealed_path_cannot_be_reserved_again_and_counts_toward_owner_cap(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('c', 'objects/c'), 60.0);
        $reserved = $this->arm($store, $reserved);
        self::assertTrue($store->abandonObjectWriteIntent($reserved->attemptId));
        $claim = $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claim);
        self::assertTrue($store->sealObjectWriteIntent($reserved->attemptId, $claim[0]->claimGeneration));

        try {
            $store->reserveObjectWriteIntent($this->intent('d', 'objects/c'), 60.0);
            self::fail('Reusing a sealed physical path must fail.');
        } catch (\PDOException) {
            // The full tier/path digest is unique, even after sealing.
        }

        $pdo = $this->pdo();
        $insert = $pdo->prepare("INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier,
             storage_path, path_digest, purpose, state, lease_until)
            VALUES (?, 'bucket', ?, 'owner', 'STANDARD', ?, ?, 'object', 'sealed', 0)");
        $pdo->beginTransaction();
        for ($i = 1; $i < ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER; $i++) {
            $path = 'sealed/' . $i;
            $insert->execute([str_pad(dechex($i), 32, '0', STR_PAD_LEFT), str_repeat('f', 32), $path, hash('sha256', $path)]);
        }
        $pdo->commit();

        $this->expectException(SlowDownException::class);
        $store->reserveObjectWriteIntent($this->intent('e', 'objects/e'), 60.0);
    }

    public function test_writer_and_collector_leases_only_extend_with_matching_cas_tokens(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('f', 'objects/f'), 60.0);
        $reserved = $this->arm($store, $reserved);

        self::assertNull($store->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 1.0));
        $renewed = $store->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0);
        self::assertNotNull($renewed);
        self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);
        self::assertNull($store->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 180.0));

        $this->expire($reserved->attemptId);
        $claims = $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claims);
        $claim = $claims[0];
        self::assertNull($store->renewObjectWriteClaim($claim->attemptId, $claim->claimGeneration, $claim->leaseUntil, 1.0));
        $renewedClaim = $store->renewObjectWriteClaim($claim->attemptId, $claim->claimGeneration, $claim->leaseUntil, 120.0);
        self::assertNotNull($renewedClaim);
        self::assertGreaterThan($claim->leaseUntil, $renewedClaim->leaseUntil);
        self::assertNull($store->renewObjectWriteClaim($claim->attemptId, $claim->claimGeneration, $claim->leaseUntil, 180.0));
        self::assertTrue($store->sealObjectWriteIntent($claim->attemptId, $claim->claimGeneration));
        self::assertNull($store->renewObjectWriteClaim($claim->attemptId, $claim->claimGeneration, $renewedClaim->leaseUntil, 180.0));
    }

    public function test_armed_writer_lease_refresh_uses_database_time_and_rejects_expired_or_mismatched_dispatch(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('8', 'objects/armed-refresh'), 60.0);
        $armed = $this->arm($store, $reserved);
        self::assertNotNull($armed->dispatchId);

        $renewed = $store->renewArmedObjectWriteIntent(
            $armed->attemptId,
            $armed->dispatchId,
            $armed->leaseUntil,
            120.0,
        );
        self::assertNotNull($renewed);
        self::assertGreaterThan($armed->leaseUntil, $renewed->leaseUntil);
        self::assertSame($armed->dispatchId, $renewed->dispatchId);
        self::assertNull($store->renewArmedObjectWriteIntent(
            $renewed->attemptId,
            str_repeat('9', 32),
            $renewed->leaseUntil,
            120.0,
        ));

        $this->expire($renewed->attemptId);
        self::assertNull($store->renewArmedObjectWriteIntent(
            $renewed->attemptId,
            $renewed->dispatchId ?? '',
            0.0,
            120.0,
        ));
    }

    public function test_exact_armed_dispatch_can_be_made_due_without_expiring_another_dispatch(): void
    {
        $store = $this->store();
        $reserved = $store->reserveObjectWriteIntent($this->intent('7', 'objects/ambiguous-due'), 60.0);
        $armed = $this->arm($store, $reserved);
        self::assertNotNull($armed->dispatchId);

        self::assertFalse($store->expireArmedObjectWriteIntent($armed->attemptId, str_repeat('9', 32)));
        self::assertSame([], $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
        self::assertTrue($store->expireArmedObjectWriteIntent($armed->attemptId, $armed->dispatchId));
        /** @var list<ObjectWriteIntent> $claimed */
        $claimed = $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claimed);
        $claim = $claimed[0] ?? throw new \LogicException('Exact due intent was not claimed.');
        self::assertSame($armed->dispatchId, $claim->dispatchId);
        self::assertFalse($store->expireArmedObjectWriteIntent($armed->attemptId, $armed->dispatchId));
    }

    public function test_sqlite_renewal_reads_its_cas_result_before_other_connections_can_see_it(): void
    {
        $first = $this->store();
        $second = new SqliteMetadataStore($this->databasePath);
        $second->initialize();
        $reserved = $first->reserveObjectWriteIntent($this->intent('9', 'objects/atomic-renew'), 60.0);
        $reserved = $this->arm($first, $reserved);

        $connection = (new \ReflectionProperty($first, 'pdo'))->getValue($first);
        self::assertInstanceOf(\PDO::class, $connection);
        self::assertTrue($connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [InterleavingObjectIntentStatement::class, []]));

        try {
            InterleavingObjectIntentStatement::$afterRenewUpdate = static function () use ($second, $reserved): void {
                self::assertSame(
                    $reserved->leaseUntil,
                    $second->getObjectWriteIntent($reserved->attemptId)?->leaseUntil,
                    'A second connection must not see the renewal before its returned snapshot is read.',
                );
            };
            $renewed = $first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0);
            self::assertNotNull($renewed);
            self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);

            InterleavingObjectIntentStatement::$afterRenewUpdate = null;
            self::assertTrue($first->abandonObjectWriteIntent($reserved->attemptId));
            $claim = $first->claimDueObjectWriteIntents('STANDARD', 1, 60.0)[0];
            InterleavingObjectIntentStatement::$afterRenewUpdate = static function () use ($second, $claim): void {
                self::assertSame(
                    $claim->leaseUntil,
                    $second->getObjectWriteIntent($claim->attemptId)?->leaseUntil,
                    'A second connection must not see the claim renewal before its returned snapshot is read.',
                );
            };
            $renewedClaim = $first->renewObjectWriteClaim($claim->attemptId, $claim->claimGeneration, $claim->leaseUntil, 120.0);
            self::assertNotNull($renewedClaim);
            self::assertSame($claim->claimGeneration, $renewedClaim->claimGeneration);
            self::assertGreaterThan($claim->leaseUntil, $renewedClaim->leaseUntil);
        } finally {
            InterleavingObjectIntentStatement::$afterRenewUpdate = null;
        }
    }

    public function test_reservation_rechecks_capacity_after_concurrent_commit_invalidates_its_wal_snapshot(): void
    {
        $first = $this->store();
        $second = new SqliteMetadataStore($this->databasePath);
        $second->initialize();
        $connection = (new \ReflectionProperty($first, 'pdo'))->getValue($first);
        self::assertInstanceOf(\PDO::class, $connection);
        self::assertTrue($connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [InterleavingObjectIntentStatement::class, []]));

        try {
            InterleavingObjectIntentStatement::$afterOwnerCountExecute = static function () use ($second): void {
                $second->reserveObjectWriteIntent(new ObjectWriteIntent(
                    attemptId: str_repeat('1', 32),
                    bucket: 'bucket',
                    bucketGeneration: str_repeat('f', 32),
                    ownerId: 'owner',
                    storageTier: 'STANDARD',
                    storagePath: 'objects/concurrent',
                    purpose: 'object',
                ), 60.0);
            };
            $reserved = $first->reserveObjectWriteIntent($this->intent('2', 'objects/after-concurrent'), 60.0);
            self::assertSame(str_repeat('2', 32), $reserved->attemptId);
            $rows = $connection->query('SELECT COUNT(*) FROM s3_object_write_intents');
            self::assertInstanceOf(\PDOStatement::class, $rows);
            self::assertSame(2, (int) $rows->fetchColumn());
        } finally {
            InterleavingObjectIntentStatement::$afterOwnerCountExecute = null;
        }
    }

    public function test_concurrent_last_owner_slot_is_rechecked_after_snapshot_retry(): void
    {
        $first = $this->store();
        $second = new SqliteMetadataStore($this->databasePath);
        $second->initialize();
        $pdo = $this->pdo();
        $insert = $pdo->prepare("INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier,
             storage_path, path_digest, purpose, state, lease_until)
            VALUES (?, 'bucket', ?, 'owner', 'STANDARD', ?, ?, 'object', 'sealed', 0)");
        self::assertInstanceOf(\PDOStatement::class, $insert);
        $pdo->beginTransaction();
        for ($i = 1; $i < ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER; $i++) {
            $path = 'sealed/cap/' . $i;
            $insert->execute([str_pad(dechex($i), 32, '0', STR_PAD_LEFT), str_repeat('f', 32), $path, hash('sha256', $path)]);
        }
        $pdo->commit();

        $connection = (new \ReflectionProperty($first, 'pdo'))->getValue($first);
        self::assertInstanceOf(\PDO::class, $connection);
        self::assertTrue($connection->setAttribute(\PDO::ATTR_STATEMENT_CLASS, [InterleavingObjectIntentStatement::class, []]));

        try {
            InterleavingObjectIntentStatement::$afterOwnerCountExecute = static function () use ($second): void {
                $second->reserveObjectWriteIntent(new ObjectWriteIntent(
                    attemptId: str_repeat('a', 32),
                    bucket: 'bucket',
                    bucketGeneration: str_repeat('f', 32),
                    ownerId: 'owner',
                    storageTier: 'STANDARD',
                    storagePath: 'objects/final-owner-slot',
                    purpose: 'object',
                ), 60.0);
            };
            $first->reserveObjectWriteIntent($this->intent('b', 'objects/over-owner-cap'), 60.0);
            self::fail('The reservation must recheck the owner limit after a snapshot conflict.');
        } catch (SlowDownException) {
            self::assertNull($first->getObjectWriteIntent(str_repeat('b', 32)));
            $rows = $connection->query("SELECT COUNT(*) FROM s3_object_write_intents WHERE owner_id = 'owner'");
            self::assertInstanceOf(\PDOStatement::class, $rows);
            self::assertSame(
                ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER,
                (int) $rows->fetchColumn(),
            );
        } finally {
            InterleavingObjectIntentStatement::$afterOwnerCountExecute = null;
        }
    }

    public function test_aliasing_or_noncanonical_physical_paths_are_rejected_before_reservation(): void
    {
        $store = $this->store();
        foreach (['objects/../sealed', 'objects//sealed', 'objects/./sealed', 'objects\\sealed'] as $path) {
            try {
                $store->reserveObjectWriteIntent($this->intent('a', $path), 60.0);
                self::fail('Noncanonical physical path was accepted.');
            } catch (\InvalidArgumentException) {
                // Different spellings must not bypass the permanent path seal.
            }
        }
    }

    public function test_aws_reservation_charges_both_pools_and_verified_seal_releases_open_once(): void
    {
        $store = $this->store();
        $planned = new ObjectWriteIntent(
            attemptId: str_repeat('e', 32),
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: 'owner',
            storageTier: 'AWS',
            storagePath: 'aws/attempt',
            purpose: 'object',
            sealReserved: true,
        );
        $reserved = $store->reserveObjectWriteIntent($planned, 60.0);
        self::assertSame(['object' => 1, 'object_seal' => 1, 'part' => 0], $store->getWriteIntentCapacityCounts());
        $armed = $store->armObjectWriteIntent(
            $reserved->attemptId,
            $reserved->leaseUntil,
            str_repeat('d', 32),
            60.0,
            4,
            hash('sha256', 'body'),
        );
        self::assertNotNull($armed);
        self::assertSame(4, $armed->expectedSize);
        self::assertSame(hash('sha256', 'body'), $armed->expectedSha256);
        self::assertTrue($store->abandonObjectWriteIntent($armed->attemptId));
        $claimed = $store->claimDueObjectWriteIntents('AWS', 1, 60.0)[0];
        self::assertTrue($store->sealObjectWriteIntent($claimed->attemptId, $claimed->claimGeneration));
        self::assertSame(['object' => 0, 'object_seal' => 1, 'part' => 0], $store->getWriteIntentCapacityCounts());
        self::assertFalse($store->sealObjectWriteIntent($claimed->attemptId, $claimed->claimGeneration));
        self::assertSame(['object' => 0, 'object_seal' => 1, 'part' => 0], $store->getWriteIntentCapacityCounts());
    }

    public function test_seal_reserved_state_machine_rejects_bypass_and_reentry(): void
    {
        $store = $this->store();
        $planned = new ObjectWriteIntent(
            attemptId: str_repeat('a', 32),
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: 'owner',
            storageTier: 'AWS',
            storagePath: 'aws/state-guard',
            purpose: 'object',
            sealReserved: true,
        );
        $reserved = $store->reserveObjectWriteIntent($planned, 60.0);
        $pdo = $this->pdo();
        try {
            $pdo->exec("UPDATE s3_object_write_intents SET state = 'sealed' WHERE attempt_id = '{$reserved->attemptId}'");
            self::fail('An active AWS intent bypassed reconciliation.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('state transition is invalid', $error->getMessage());
        }

        $armed = $store->armObjectWriteIntent(
            $reserved->attemptId,
            $reserved->leaseUntil,
            str_repeat('d', 32),
            60.0,
            4,
            hash('sha256', 'body'),
        );
        self::assertNotNull($armed);
        self::assertTrue($store->abandonObjectWriteIntent($armed->attemptId));
        $claimed = $store->claimDueObjectWriteIntents('AWS', 1, 60.0)[0];
        self::assertTrue($store->sealObjectWriteIntent($claimed->attemptId, $claimed->claimGeneration));
        try {
            $pdo->exec("UPDATE s3_object_write_intents SET state = 'reconciling' WHERE attempt_id = '{$reserved->attemptId}'");
            self::fail('A permanent AWS seal re-entered reconciliation.');
        } catch (\PDOException $error) {
            self::assertStringContainsString('state transition is invalid', $error->getMessage());
        }
    }

    public function test_full_owner_seal_pool_rejects_only_that_owner(): void
    {
        $store = $this->store();
        $pdo = $this->pdo();
        $insert = $pdo->prepare("INSERT INTO s3_object_write_intents
            (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
             path_digest, purpose, state, lease_until, seal_reserved)
            VALUES (?, 'bucket', ?, 'seal-full-owner', 'AWS', ?, ?, 'object', 'active', 0, 1)");
        self::assertInstanceOf(\PDOStatement::class, $insert);
        $pdo->beginTransaction();
        for ($i = 0; $i < ObjectWriteIntentStore::MAX_SEALS_PER_OWNER; ++$i) {
            $attemptId = str_pad(dechex($i + 1), 32, '0', STR_PAD_LEFT);
            $path = 'aws/seal-cap/' . $attemptId;
            $insert->execute([$attemptId, str_repeat('f', 32), $path, hash('sha256', $path)]);
        }
        $pdo->exec("UPDATE s3_object_write_intents SET state = 'reconciling' WHERE owner_id = 'seal-full-owner'");
        $pdo->exec("UPDATE s3_object_write_intents SET state = 'sealed' WHERE owner_id = 'seal-full-owner'");
        $pdo->commit();
        self::assertSame(
            ['object' => 0, 'object_seal' => ObjectWriteIntentStore::MAX_SEALS_PER_OWNER, 'part' => 0],
            $store->getWriteIntentCapacityCounts(),
        );

        try {
            $store->reserveObjectWriteIntent(new ObjectWriteIntent(
                attemptId: str_repeat('e', 32),
                bucket: 'bucket',
                bucketGeneration: str_repeat('f', 32),
                ownerId: 'seal-full-owner',
                storageTier: 'AWS',
                storagePath: 'aws/seal-cap/rejected',
                purpose: 'object',
                sealReserved: true,
            ), 60.0);
            self::fail('The next seal reservation for a full owner was accepted.');
        } catch (SlowDownException) {
        }

        $other = $store->reserveObjectWriteIntent(new ObjectWriteIntent(
            attemptId: str_repeat('d', 32),
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: 'another-owner',
            storageTier: 'AWS',
            storagePath: 'aws/seal-cap/other-owner',
            purpose: 'object',
            sealReserved: true,
        ), 60.0);
        self::assertSame('another-owner', $other->ownerId);
        self::assertTrue($store->retireUndispatchedObjectWriteIntent($other->attemptId));
    }

    private function arm(SqliteMetadataStore $store, ObjectWriteIntent $reserved): ObjectWriteIntent
    {
        return $store->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, bin2hex(random_bytes(16)), 60.0)
            ?? throw new \RuntimeException('Fixture dispatch was not armed.');
    }

    private function store(): SqliteMetadataStore
    {
        $store = new SqliteMetadataStore($this->databasePath);
        $store->initialize();

        return $store;
    }

    private function pdo(): \PDO
    {
        return new \PDO('sqlite:' . $this->databasePath);
    }

    private function expire(string $attemptId): void
    {
        $stmt = $this->pdo()->prepare('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?');
        self::assertInstanceOf(\PDOStatement::class, $stmt);
        $stmt->execute([$attemptId]);
    }

    private function intent(string $attemptSuffix, string $path, string $storageTier = 'STANDARD'): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: str_repeat($attemptSuffix, 32),
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: 'owner',
            storageTier: $storageTier,
            storagePath: $path,
            purpose: 'object',
        );
    }

    /** @param list<string> $statements */
    private static function containsObjectWriteTable(array $statements): bool
    {
        foreach ($statements as $statement) {
            if (str_contains($statement, 'CREATE TABLE IF NOT EXISTS s3_object_write_intents')) {
                return true;
            }
        }

        return false;
    }
}

final class InterleavingObjectIntentStatement extends \PDOStatement
{
    public static ?\Closure $afterRenewUpdate = null;

    public static ?\Closure $afterOwnerCountExecute = null;

    protected function __construct() {}

    /** @param array<int|string, mixed>|null $params */
    public function execute(?array $params = null): bool
    {
        $result = parent::execute($params);
        if ($result && str_contains($this->queryString, 'UPDATE s3_object_write_intents')
            && str_contains($this->queryString, 'SET lease_until = (julianday')) {
            (self::$afterRenewUpdate)?->__invoke();
        }
        if ($result && str_contains($this->queryString, 'SELECT COUNT(*) FROM s3_object_write_intents WHERE owner_id = ?')) {
            $callback = self::$afterOwnerCountExecute;
            self::$afterOwnerCountExecute = null;
            $callback?->__invoke();
        }

        return $result;
    }
}
