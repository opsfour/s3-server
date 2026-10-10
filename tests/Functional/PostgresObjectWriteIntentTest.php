<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Exception\SlowDownException;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use PHPUnit\Framework\TestCase;

use function Amp\async;

final class PostgresObjectWriteIntentTest extends TestCase
{
    private PostgresMetadataStore $first;

    private PostgresMetadataStore $second;

    private PostgresConnectionPool $pool;

    private PostgresConnectionPool $secondPool;

    /** @var list<string> */
    private array $attempts = [];

    protected function setUp(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the disposable PostgreSQL intent gate.');
        }
        $this->pool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $database = $this->pool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertIsString($database['database_name'] ?? null);
        self::assertStringStartsWith('s3audit_', $database['database_name'], 'The PostgreSQL intent tests require an isolated audit database.');
        $this->secondPool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $this->first = new PostgresMetadataStore($this->pool);
        $this->second = new PostgresMetadataStore($this->secondPool);
        $this->first->initialize();
    }

    protected function tearDown(): void
    {
        if (isset($this->pool)) {
            foreach ($this->attempts as $attempt) {
                $this->pool->execute('DELETE FROM s3_object_write_intents WHERE attempt_id = $1', [$attempt]);
            }
            $this->pool->close();
            if (isset($this->secondPool)) {
                $this->secondPool->close();
            }
        }
    }

    public function test_db_clock_publish_rollback_claim_cas_and_retained_seal(): void
    {
        $reserved = $this->first->reserveObjectWriteIntent($this->intent('owner-a'), 60.0);
        $this->attempts[] = $reserved->attemptId;
        self::assertSame('active', $reserved->state);
        self::assertGreaterThan(microtime(true), $reserved->leaseUntil);

        try {
            $this->first->transaction(function () use ($reserved): void {
                self::assertTrue($this->first->consumeObjectWriteIntent($reserved->attemptId));
                throw new \RuntimeException('rollback');
            });
        } catch (\RuntimeException $error) {
            self::assertSame('rollback', $error->getMessage());
        }
        self::assertNotNull($this->second->getObjectWriteIntent($reserved->attemptId));
        $reserved = $this->arm($reserved);
        self::assertFalse($this->first->consumeObjectWriteIntent($reserved->attemptId));

        $renewed = $this->first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0);
        self::assertNotNull($renewed);
        self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);
        self::assertNull($this->first->renewObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, 120.0));
        self::assertTrue($this->first->abandonObjectWriteIntent($reserved->attemptId));
        self::assertFalse($this->first->consumeObjectWriteIntent($reserved->attemptId));

        $claims = [
            async(fn(): array => $this->first->claimDueObjectWriteIntents('STANDARD', 1, 60.0)),
            async(fn(): array => $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0)),
        ];
        $results = [$claims[0]->await(), $claims[1]->await()];
        self::assertSame(1, count($results[0]) + count($results[1]));
        $claimed = $results[0][0] ?? $results[1][0];
        self::assertSame('reconciling', $claimed->state);
        self::assertSame(1, $claimed->claimGeneration);
        self::assertNull($this->first->renewObjectWriteClaim($claimed->attemptId, 0, $claimed->leaseUntil, 120.0));
        $renewedClaim = $this->first->renewObjectWriteClaim($claimed->attemptId, 1, $claimed->leaseUntil, 120.0);
        self::assertNotNull($renewedClaim);
        self::assertFalse($this->second->sealObjectWriteIntent($claimed->attemptId, 0));
        $this->pool->execute('UPDATE s3_object_write_intents SET lease_until = 0 WHERE attempt_id = $1', [$claimed->attemptId]);
        $reclaimed = $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $reclaimed);
        self::assertSame(2, $reclaimed[0]->claimGeneration);
        self::assertFalse($this->first->sealObjectWriteIntent($claimed->attemptId, 1));
        self::assertTrue($this->second->sealObjectWriteIntent($claimed->attemptId, 2));
        self::assertSame('sealed', $this->first->getObjectWriteIntent($claimed->attemptId)?->state);
        self::assertFalse($this->first->consumeObjectWriteIntent($claimed->attemptId));

        $duplicatePath = $this->intent('owner-b', $reserved->storagePath);
        $this->attempts[] = $duplicatePath->attemptId;
        $this->expectException(\Amp\Postgres\PostgresQueryError::class);
        $this->first->reserveObjectWriteIntent($duplicatePath, 60.0);
    }

    public function test_reservation_rejects_outer_transaction_and_invalid_paths(): void
    {
        $this->expectException(\LogicException::class);
        $this->first->transaction(function (): void {
            $this->first->reserveObjectWriteIntent($this->intent('owner-a'), 60.0);
        });
    }

    public function test_claim_excludes_due_intents_on_other_storage_tiers(): void
    {
        $remote = $this->first->reserveObjectWriteIntent($this->intent('owner-a', null, 'REMOTE'), 60.0);
        $remote = $this->arm($remote);
        $this->attempts[] = $remote->attemptId;
        self::assertTrue($this->first->abandonObjectWriteIntent($remote->attemptId));
        $local = $this->first->reserveObjectWriteIntent($this->intent('owner-a'), 60.0);
        $local = $this->arm($local);
        $this->attempts[] = $local->attemptId;
        self::assertTrue($this->first->abandonObjectWriteIntent($local->attemptId));

        $claimed = $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
        self::assertCount(1, $claimed);
        self::assertSame($local->attemptId, $claimed[0]->attemptId);
        self::assertSame('active', $this->first->getObjectWriteIntent($remote->attemptId)?->state);
        self::assertSame([], $this->second->claimDueObjectWriteIntents('STANDARD', 1, 60.0));
        self::assertSame($remote->attemptId, $this->second->claimDueObjectWriteIntents('REMOTE', 1, 60.0)[0]->attemptId);
    }

    public function test_invalid_path_is_rejected_before_database_insert(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->first->reserveObjectWriteIntent($this->intent('owner-a', 'objects/../other'), 60.0);
    }

    public function test_global_cap_serializes_different_owners(): void
    {
        if (getenv('S3_TEST_OBJECT_INTENT_GLOBAL_CAP') !== '1') {
            self::markTestSkipped('Set S3_TEST_OBJECT_INTENT_GLOBAL_CAP=1 only for an isolated disposable PostgreSQL database.');
        }
        $database = $this->pool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertSame(
            's3audit_object_intent_gate',
            $database['database_name'] ?? null,
            'The million-row gate requires its own named disposable database.',
        );
        $existing = $this->pool->query('SELECT COUNT(*) AS n FROM s3_object_write_intents')->fetchRow();
        self::assertSame(0, (int) ($existing['n'] ?? -1), 'The million-row gate requires its own empty database.');

        // One bulk statement is much cheaper than a million API calls, and is
        // deliberately restricted to this opt-in disposable-database gate.
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier,
                 storage_path, path_digest, purpose, state, lease_until)
            SELECT lpad(to_hex(n), 32, '0'), 'bucket', repeat('f', 32), 's3audit-object-intent-seed-' || (n % 300),
                   'STANDARD', 'seed/' || n, md5('seed/' || n), 'object', 'sealed', 0
            FROM generate_series(1, 999999) AS n
            SQL);
        try {
            $one = $this->intent('owner-one');
            $two = $this->intent('owner-two');
            $this->attempts[] = $one->attemptId;
            $this->attempts[] = $two->attemptId;
            $admit = static function (PostgresMetadataStore $store, ObjectWriteIntent $intent): string {
                try {
                    $store->reserveObjectWriteIntent($intent, 60.0);

                    return 'admitted';
                } catch (SlowDownException) {
                    return 'full';
                }
            };
            $futures = [
                async(fn(): string => $admit($this->first, $one)),
                async(fn(): string => $admit($this->second, $two)),
            ];
            $results = [$futures[0]->await(), $futures[1]->await()];
            sort($results);
            self::assertSame(['admitted', 'full'], $results);
            $count = $this->pool->query('SELECT COUNT(*) AS n FROM s3_object_write_intents')->fetchRow();
            self::assertSame(ObjectWriteIntentStore::MAX_OUTSTANDING_GLOBAL, (int) ($count['n'] ?? 0));
        } finally {
            $this->pool->query("DELETE FROM s3_object_write_intents WHERE owner_id LIKE 's3audit-object-intent-seed-%'");
        }
    }

    private function arm(ObjectWriteIntent $reserved): ObjectWriteIntent
    {
        return $this->first->armObjectWriteIntent($reserved->attemptId, $reserved->leaseUntil, bin2hex(random_bytes(16)), 60.0)
            ?? throw new \RuntimeException('Fixture dispatch was not armed.');
    }

    private function intent(string $owner, ?string $path = null, string $storageTier = 'STANDARD'): ObjectWriteIntent
    {
        $attempt = bin2hex(random_bytes(16));

        return new ObjectWriteIntent(
            attemptId: $attempt,
            bucket: 'bucket',
            bucketGeneration: str_repeat('f', 32),
            ownerId: $owner,
            storageTier: $storageTier,
            storagePath: $path ?? 'objects/' . $attempt,
            purpose: 'object',
        );
    }
}
