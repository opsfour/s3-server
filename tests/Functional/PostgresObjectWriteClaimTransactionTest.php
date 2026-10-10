<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use PHPUnit\Framework\TestCase;

final class PostgresObjectWriteClaimTransactionTest extends TestCase
{
    public function test_collector_claim_rejects_outer_transaction(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_METADATA_DSN');
        if ($dsn === false || ! preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn)) {
            self::markTestSkipped('Set S3_TEST_POSTGRES_METADATA_DSN to an isolated s3audit_ PostgreSQL database.');
        }

        $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
        try {
            $database = $pool->query('SELECT current_database() AS database_name')->fetchRow();
            if (! is_string($database['database_name'] ?? null)
                || preg_match('/^s3audit_[a-z0-9_]+$/i', $database['database_name']) !== 1) {
                throw new \RuntimeException('The PostgreSQL claim gate requires an actual disposable s3audit_ database.');
            }

            $store = new PostgresMetadataStore($pool);
            $store->initialize();

            try {
                $store->transaction(function () use ($store): void {
                    $store->claimDueObjectWriteIntents('STANDARD', 1, 60.0);
                    self::fail('Claim inside an outer transaction must be rejected.');
                });
            } catch (\LogicException $error) {
                self::assertSame('Physical write claims require their own transaction.', $error->getMessage());
            }

        } finally {
            $pool->close();
        }
    }
}
