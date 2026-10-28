<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use PHPUnit\Framework\TestCase;

final class PostgresAwsS3OperationalSummaryTest extends TestCase
{
    private PostgresConnectionPool $pool;
    private string $suffix;

    protected function setUp(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN for the PostgreSQL AWS aggregate gate.');
        }
        $this->pool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $database = $this->pool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertIsString($database['database_name'] ?? null);
        self::assertStringStartsWith('s3audit_', $database['database_name'], 'The AWS aggregate gate requires an isolated audit database.');
        (new PostgresMetadataStore($this->pool))->initialize();
        $this->suffix = bin2hex(random_bytes(8));
    }

    protected function tearDown(): void
    {
        if (! isset($this->pool, $this->suffix)) {
            return;
        }
        $like = '%story48-' . $this->suffix . '%';
        $this->pool->execute('DELETE FROM s3_object_write_intents WHERE storage_path LIKE $1', [$like]);
        $this->pool->execute('DELETE FROM s3_storage_garbage WHERE storage_path LIKE $1', [$like]);
        $this->pool->execute('DELETE FROM s3_multipart_write_intents WHERE assembly_path LIKE $1', [$like]);
        $this->pool->close();
    }

    public function test_summary_is_exactly_tier_scoped_and_uses_db_time_for_armed_ambiguity_and_owner_bounds(): void
    {
        $store = new PostgresMetadataStore($this->pool);
        $ownerA = 'owner-a-' . $this->suffix;
        $ownerB = 'owner-b-' . $this->suffix;
        $this->object('AWS', $ownerA, 'active', str_repeat('1', 32), true, 901.0);
        $this->object('AWS', $ownerA, 'reconciling', str_repeat('2', 32), true, 100.0);
        $this->object('AWS', $ownerB, 'active', null, true, 50.0);
        $this->object('AWS', 'owner-c-' . $this->suffix, 'sealed', str_repeat('3', 32), true, 5_000.0);
        $this->object('LOCAL', 'owner-local-' . $this->suffix, 'active', str_repeat('4', 32), true, 2_000.0);
        $this->multipart('AWS', 200.0);
        $this->multipart('LOCAL', 3_000.0);
        $this->garbage('AWS', 'part_write_intent', $ownerA, 1, 300.0);
        $this->garbage('AWS', 'delete_path', null, 1, 400.0);
        $this->garbage('LOCAL', 'part_write_intent', 'owner-local-' . $this->suffix, 1, 4_000.0);

        $summary = $store->getAwsS3OperationalSummary('AWS');

        self::assertSame(3, $summary['open']);
        self::assertSame(2, $summary['ambiguous']);
        self::assertSame(4, $summary['seal_reserved']);
        self::assertSame(1, $summary['sealed']);
        self::assertSame(1, $summary['multipart']);
        self::assertSame(1, $summary['part']);
        self::assertSame(1, $summary['cleanup']);
        self::assertSame(2, $summary['failures']);
        self::assertGreaterThanOrEqual(900.0, $summary['oldest_age_seconds']);
        self::assertLessThan(1_000.0, $summary['oldest_age_seconds']);
        self::assertSame(2, $summary['max_owner_open']);
        self::assertSame(2, $summary['max_owner_sealed']);
        self::assertSame(1, $summary['max_owner_part']);
    }

    private function object(
        string $tier,
        string $owner,
        string $state,
        ?string $dispatchId,
        bool $sealReserved,
        float $ageSeconds,
    ): void {
        $attempt = bin2hex(random_bytes(16));
        $path = 'story48-' . $this->suffix . '/object/' . $attempt;
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                 path_digest, purpose, state, lease_until, dispatch_id, seal_reserved, created_at)
            VALUES ($1, 'bucket', 'generation', $2, $3, $4, $5, 'put_object', 'active', 0, $6, $7,
                    clock_timestamp() - ($8::double precision * interval '1 second'))
            SQL, [$attempt, $owner, $tier, $path, hash('sha256', $path), $dispatchId, $sealReserved, $ageSeconds]);
        if (in_array($state, ['reconciling', 'sealed'], true)) {
            $this->pool->execute("UPDATE s3_object_write_intents SET state = 'reconciling' WHERE attempt_id = $1", [$attempt]);
        }
        if ($state === 'sealed') {
            $this->pool->execute("UPDATE s3_object_write_intents SET state = 'sealed' WHERE attempt_id = $1", [$attempt]);
        }
    }

    private function multipart(string $tier, float $ageSeconds): void
    {
        $attempt = bin2hex(random_bytes(16));
        $base = 'story48-' . $this->suffix . '/multipart/' . $attempt;
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, state, lease_until, next_attempt_at, dispatch_id, created_at)
            VALUES ($1, $2, 'bucket', 'generation', $3, $4, $5, $6, $7, 'active', 0, 0, NULL,
                    clock_timestamp() - ($8::double precision * interval '1 second'))
            SQL, [
            $attempt,
            bin2hex(random_bytes(16)),
            'owner-' . $this->suffix,
            $tier,
            $base . '/assembly',
            $base . '/final',
            hash('sha256', $base),
            $ageSeconds,
        ]);
    }

    private function garbage(string $tier, string $operation, ?string $owner, int $attempts, float $ageSeconds): void
    {
        $path = 'story48-' . $this->suffix . '/garbage/' . bin2hex(random_bytes(8));
        $this->pool->execute(<<<'SQL'
            INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, owner_id, attempts, next_attempt_at, created_at)
            VALUES ('bucket', $1, $2, $3, $4, $5, 0,
                    clock_timestamp() - ($6::double precision * interval '1 second'))
            SQL, [$tier, $path, $operation, $owner, $attempts, $ageSeconds]);
    }
}
