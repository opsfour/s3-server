<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Mysql\SocketMysqlConnector;
use Amp\Postgres\DefaultPostgresConnector;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use Amp\Socket\DnsSocketConnector;
use Amp\Socket\RetrySocketConnector;
use Amp\Sql\Common\RetrySqlConnector;
use OpsFour\S3Server\Metadata\MysqlMetadataStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntent;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;

/**
 * A separate, bounded Amp connection pool for local write lease renewals.
 * It is shared by active attempts in one process; it never opens one
 * connection per upload. Callers must separately bound admitted attempts and
 * wait for an outstanding renewal to finish before releasing its admission.
 * No runtime path uses this adapter until the arm/commit guard is integrated.
 */
final readonly class DedicatedAmpObjectWriteLeaseRenewer implements LocalObjectWriteLeaseRenewer
{
    private function __construct(
        private ObjectWriteIntentStore $store,
        private PostgresConnectionPool|MysqlConnectionPool $pool,
    ) {}

    public static function forPostgres(string $dsn, int $maxConnections = 2): self
    {
        $maxConnections = self::validatePoolSize($maxConnections);
        if (! extension_loaded('pq')) {
            throw new \RuntimeException('Local PostgreSQL write leases require pecl-pq.');
        }
        $pool = new PostgresConnectionPool(
            PostgresConfig::fromString($dsn),
            maxConnections: $maxConnections,
            connector: new RetrySqlConnector(new DefaultPostgresConnector()),
        );

        return new self(new PostgresMetadataStore($pool), $pool);
    }

    public static function forMysql(string $dsn, int $maxConnections = 2): self
    {
        $maxConnections = self::validatePoolSize($maxConnections);
        $pool = new MysqlConnectionPool(
            MysqlConfig::fromString($dsn),
            maxConnections: $maxConnections,
            connector: new RetrySqlConnector(new SocketMysqlConnector(new RetrySocketConnector(new DnsSocketConnector()))),
        );

        return new self(new MysqlMetadataStore($pool), $pool);
    }

    public function renew(ObjectWriteIntent $current, float $leaseSeconds): ?ObjectWriteIntent
    {
        if ($current->state !== 'active') {
            throw new \InvalidArgumentException('Only an active local write intent can be renewed.');
        }
        $renewed = $this->store->renewObjectWriteIntent($current->attemptId, $current->leaseUntil, $leaseSeconds);
        if ($renewed === null) {
            return null;
        }
        if ($renewed->attemptId !== $current->attemptId
            || $renewed->bucket !== $current->bucket
            || $renewed->bucketGeneration !== $current->bucketGeneration
            || $renewed->ownerId !== $current->ownerId
            || $renewed->storageTier !== $current->storageTier
            || $renewed->storagePath !== $current->storagePath
            || $renewed->purpose !== $current->purpose
            || $renewed->dispatchId !== $current->dispatchId
            || $renewed->state !== 'active'
            || $renewed->leaseUntil <= $current->leaseUntil) {
            throw new \RuntimeException('Local write lease renewal returned a different intent.');
        }

        return $renewed;
    }

    public function close(): void
    {
        $this->pool->close();
    }

    /** @return positive-int */
    private static function validatePoolSize(int $maxConnections): int
    {
        if ($maxConnections < 1 || $maxConnections > 8) {
            throw new \InvalidArgumentException('Local write renewal pool size must be between 1 and 8.');
        }

        return $maxConnections;
    }
}
