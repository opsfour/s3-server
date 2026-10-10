<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class MysqlObjectWriteIntentTest extends TestCase
{
    private MysqlConnectionPool $pool;

    private MysqlMetadataStore $first;

    private MysqlMetadataStore $second;

    private string $ownerA;

    private string $ownerB;

    protected function setUp(): void
    {
        $dsn = getenv('S3_TEST_MYSQL_METADATA_DSN');
        if ($dsn === false || ! preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn)) {
            self::markTestSkipped('Set S3_TEST_MYSQL_METADATA_DSN to an isolated s3audit_ MySQL database.');
        }

        $this->pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $database = $this->pool->query('SELECT DATABASE() AS database_name')->fetchRow();
        if (! is_string($database['database_name'] ?? null)
            || preg_match('/^s3audit_[a-z0-9_]+$/i', $database['database_name']) !== 1) {
            $this->pool->close();
            unset($this->pool);
            throw new \RuntimeException('The MySQL intent gate requires an actual disposable s3audit_ database.');
        }
        $this->first = MysqlMetadataStore::fromDsn($dsn);
        $this->second = MysqlMetadataStore::fromDsn($dsn);
        $this->first->initialize();
        $suffix = bin2hex(random_bytes(8));
        $this->ownerA = 'object-write-a-' . $suffix;
        $this->ownerB = 'object-write-b-' . $suffix;
    }

    protected function tearDown(): void
    {
        if (isset($this->pool)) {
            $this->pool->execute('DELETE FROM s3_object_write_intents WHERE owner_id IN (?, ?)', [$this->ownerA, $this->ownerB]);
            $this->pool->close();
        }
    }

    public function test_publish_rollback_expiry_claim_and_permanent_seal(): void
    {
        $reserved = $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'publish'), 60.0);
        self::assertSame('active', $reserved->state);
        self::assertGreaterThan(0.0, $reserved->leaseUntil);

        try {
            $this->first->transaction(function () use ($reserved): void {
                self::assertTrue($this->first->consumeObjectWriteIntent($reserved->attemptId));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $e) {
            self::assertSame('rollback', $e->getMessage());
        }
        self::assertNotNull($this->second->getObjectWriteIntent($reserved->attemptId));
        $reserved = $this->arm($reserved);
        self::assertFalse($this->first->consumeObjectWriteIntent($reserved->attemptId));

        $renewed = $this->first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0);
        self::assertNotNull($renewed);
        self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);
        self::assertNull($this->first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0));

        $this->pool->execute('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?', [$reserved->attemptId]);
        self::assertFalse($this->first->consumeObjectWriteIntent($reserved->attemptId));
        self::assertNull($this->first->renewObjectWriteIntent($reserved->attemptId, $renewed->leaseUntil, 120.0));
        $firstClaim = $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $firstClaim);
        self::assertSame(1, $firstClaim[0]->claimGeneration);
        self::assertSame('reconciling', $firstClaim[0]->state);
        self::assertSame([], $this->first->claimDueObjectWriteIntents('STANDARD', 1, 60.0));

        $renewedClaim = $this->second->renewObjectWriteClaim(
            $reserved->attemptId,
            1,
            $firstClaim[0]->leaseUntil,
            120.0,
        );
        self::assertNotNull($renewedClaim);
        self::assertNull($this->second->renewObjectWriteClaim(
            $reserved->attemptId,
            1,
            $firstClaim[0]->leaseUntil,
            120.0,
        ));
        $this->pool->execute('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = ?', [$reserved->attemptId]);
        $secondClaim = $this->first->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $secondClaim);
        self::assertSame(2, $secondClaim[0]->claimGeneration);
        self::assertFalse($this->second->sealObjectWriteIntent($reserved->attemptId, 1));
        self::assertTrue($this->first->sealObjectWriteIntent($reserved->attemptId, 2));
        self::assertSame('sealed', $this->second->getObjectWriteIntent($reserved->attemptId)?->state);
        self::assertFalse($this->first->consumeObjectWriteIntent($reserved->attemptId));

        $this->expectException(\Amp\Sql\SqlQueryError::class);
        $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'publish'), 60.0);
    }

    public function test_admission_across_different_owners_waits_on_one_transactional_lock(): void
    {
        $lock = $this->pool->beginTransaction();
        try {
            self::assertNotNull($lock->execute(
                'SELECT kind FROM s3_write_intent_capacity WHERE kind = ? FOR UPDATE',
                ['object'],
            )->fetchRow());
            $first = async(fn(): ObjectWriteIntent => $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'race-a'), 60.0));
            $second = async(fn(): ObjectWriteIntent => $this->second->reserveObjectWriteIntent($this->intent($this->ownerB, 'race-b'), 60.0));
            delay(0.1);
            self::assertFalse($first->isComplete(), 'First owner must wait for global admission lock.');
            self::assertFalse($second->isComplete(), 'Different owner must wait for the same global admission lock.');
        } finally {
            $lock->commit();
        }

        self::assertSame($this->ownerA, $first->await()->ownerId);
        self::assertSame($this->ownerB, $second->await()->ownerId);
        $count = $this->pool->execute(
            'SELECT COUNT(*) AS n FROM s3_object_write_intents WHERE owner_id IN (?, ?)',
            [$this->ownerA, $this->ownerB],
        )->fetchRow();
        self::assertSame(2, (int) ($count['n'] ?? 0));
    }

    public function test_claim_excludes_due_intents_on_other_storage_tiers(): void
    {
        $remote = $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'remote', 'REMOTE'), 60.0);
        $remote = $this->arm($remote);
        self::assertTrue($this->first->abandonObjectWriteIntent($remote->attemptId));
        $local = $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'local'), 60.0);
        $local = $this->arm($local);
        self::assertTrue($this->first->abandonObjectWriteIntent($local->attemptId));

        $claimed = $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claimed);
        self::assertSame($local->attemptId, $claimed[0]->attemptId);
        self::assertSame('active', $this->first->getObjectWriteIntent($remote->attemptId)?->state);
        self::assertSame([], $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
        self::assertSame($remote->attemptId, $this->second->claimDueObjectWriteIntents('REMOTE', 1, 60.0)[0]->attemptId);
    }

    public function test_renew_returns_its_own_row_when_a_waiting_writer_abandons_immediately_afterward(): void
    {
        $reserved = $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'renew-return-race'), 60.0);
        $blocker = $this->pool->beginTransaction();
        try {
            self::assertNotNull($blocker->execute(
                'SELECT attempt_id FROM s3_object_write_intents WHERE attempt_id = ? FOR UPDATE',
                [$reserved->attemptId],
            )->fetchRow());

            $renew = async(fn(): ?ObjectWriteIntent => $this->first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0));
            delay(0.1);
            self::assertFalse($renew->isComplete(), 'Renewal must wait for the held row lock.');

            $abandon = async(fn(): bool => $this->second->abandonObjectWriteIntent($reserved->attemptId));
            delay(0.1);
            self::assertFalse($abandon->isComplete(), 'Abandonment must wait behind the renewal.');
        } finally {
            $blocker->commit();
        }

        $renewed = $renew->await();
        self::assertNotNull($renewed);
        self::assertSame('active', $renewed->state);
        self::assertSame(0, $renewed->claimGeneration);
        self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);
        self::assertTrue($abandon->await());
        self::assertLessThan($renewed->leaseUntil, $this->second->getObjectWriteIntent($reserved->attemptId)?->leaseUntil);
    }

    public function test_collector_claim_rejects_outer_transaction(): void
    {
        $intent = $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'claim-transaction'), 60.0);
        $intent = $this->arm($intent);
        self::assertTrue($this->first->abandonObjectWriteIntent($intent->attemptId));

        try {
            $this->first->transaction(function (): void {
                $this->first->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
                self::fail('Claim inside an outer transaction must be rejected.');
            });
        } catch (\LogicException $error) {
            self::assertSame('Physical write claims require their own transaction.', $error->getMessage());
        }

        self::assertSame('active', $this->first->getObjectWriteIntent($intent->attemptId)?->state);
        self::assertSame($intent->attemptId, $this->first->claimDueObjectWriteIntents('STANDARD', 1, 60.0)[0]->attemptId);
    }

    public function test_sealed_rows_count_toward_owner_cap(): void
    {
        $rows = [];
        for ($i = 0; $i < ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER; $i++) {
            $path = 'sealed/' . $this->ownerA . '/' . $i;
            $rows[] = "('" . bin2hex(random_bytes(16)) . "', 'bucket', '" . str_repeat('f', 32) . "', '" . $this->ownerA
                . "', 'STANDARD', '" . $path . "', '" . hash('sha256', $path) . "', 'object', 'sealed', 0)";
            if (count($rows) === 200 || $i === ObjectWriteIntentStore::MAX_OUTSTANDING_PER_OWNER - 1) {
                $this->pool->execute('INSERT INTO s3_object_write_intents
                    (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                     path_digest, purpose, state, lease_until) VALUES ' . implode(',', $rows));
                $rows = [];
            }
        }

        $this->expectException(SlowDownException::class);
        $this->first->reserveObjectWriteIntent($this->intent($this->ownerA, 'over-cap'), 60.0);
    }

    private function arm(ObjectWriteIntent $reserved): ObjectWriteIntent
    {
        return $this->first->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, bin2hex(random_bytes(16)), 60.0)
            ?? throw new \RuntimeException('Fixture dispatch was not armed.');
    }

    private function intent(string $owner, string $key, string $storageTier = 'STANDARD'): ObjectWriteIntent
    {
        return new ObjectWriteIntent(
            attemptId: bin2hex(random_bytes(16)),
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: $owner,
            storageTier: $storageTier,
            storagePath: 'objects/' . $key,
            purpose: 'object',
        );
    }
}
