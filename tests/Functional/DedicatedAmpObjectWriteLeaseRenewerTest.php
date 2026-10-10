<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Storage\DedicatedAmpObjectWriteLeaseRenewer;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

use function Amp\async;
use function Amp\delay;

final class DedicatedAmpObjectWriteLeaseRenewerTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function drivers(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('drivers')]
    public function test_dedicated_renewal_yields_to_loop_and_preserves_cas(string $driver, string $envName): void
    {
        $dsn = getenv($envName);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set the disposable metadata DSN to run the dedicated lease gate.');
        }
        if ($driver === 'postgres' && ! extension_loaded('pq')) {
            self::markTestSkipped('The PostgreSQL API lease gate requires pecl-pq.');
        }

        $dsn = trim($dsn);
        $postgres = $driver === 'postgres';
        if ($postgres) {
            $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
            $store = new PostgresMetadataStore($pool);
        } else {
            $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
            $store = new MysqlMetadataStore($pool);
        }
        $renewer = $postgres
            ? DedicatedAmpObjectWriteLeaseRenewer::forPostgres($dsn, 1)
            : DedicatedAmpObjectWriteLeaseRenewer::forMysql($dsn, 1);
        $attempt = bin2hex(random_bytes(16));
        $reserved = null;
        try {
            $row = $pool->query($postgres ? 'SELECT current_database() AS name' : 'SELECT DATABASE() AS name')->fetchRow();
            self::assertIsString($row['name'] ?? null);
            self::assertMatchesRegularExpression('/^s3audit_[a-z0-9_]+$/i', $row['name']);
            $store->initialize();
            $reserved = $store->reserveObjectWriteIntent(new ObjectWriteIntent(
                attemptId: $attempt,
                bucket: 'bucket',
                bucketGeneration: str_repeat('f', 32),
                ownerId: 's3audit-lease-' . $attempt,
                storageTier: 'STANDARD',
                storagePath: 'objects/' . $attempt,
                purpose: 'put',
            ), 60.0);

            $blocker = $pool->beginTransaction();
            try {
                $blocker->execute(
                    $postgres
                        ? 'SELECT attempt_id FROM s3_object_write_intents WHERE attempt_id = $1 FOR UPDATE'
                        : 'SELECT attempt_id FROM s3_object_write_intents WHERE attempt_id = ? FOR UPDATE',
                    [$attempt],
                )->fetchRow();
                $renewal = async(fn(): ?ObjectWriteIntent => $renewer->renew($reserved, 120.0));
                delay(0.05);
                self::assertFalse($renewal->isComplete(), 'Renewal must wait on the row without blocking the event loop.');
            } finally {
                $blocker->commit();
            }
            $renewed = $renewal->await();
            self::assertNotNull($renewed);
            self::assertGreaterThan($reserved->leaseUntil, $renewed->leaseUntil);
            self::assertNull($renewer->renew($reserved, 120.0), 'A stale lease CAS must fail.');

            $armed = $store->armObjectWriteIntent($attempt, $renewed->leaseUntil, bin2hex(random_bytes(16)), 60.0);
            self::assertNotNull($armed);
            $renewedArmed = $renewer->renew($armed, 120.0);
            self::assertNotNull($renewedArmed);
            self::assertSame($armed->dispatchId, $renewedArmed->dispatchId);
        } finally {
            if ($reserved !== null) {
                $pool->execute(
                    $postgres
                        ? 'DELETE FROM s3_object_write_intents WHERE attempt_id = $1'
                        : 'DELETE FROM s3_object_write_intents WHERE attempt_id = ?',
                    [$attempt],
                );
            }
            $renewer->close();
            $pool->close();
        }
    }

    public function test_pool_size_is_bounded_before_any_connection(): void
    {
        $rejected = 0;
        foreach ([0, 9] as $size) {
            try {
                DedicatedAmpObjectWriteLeaseRenewer::forMysql('host=127.0.0.1;dbname=unused', $size);
                self::fail('Invalid renewal connection limit was accepted.');
            } catch (\InvalidArgumentException) {
                // No connection is opened for an invalid limit.
                $rejected++;
            }
        }
        self::assertSame(2, $rejected);
    }
}
