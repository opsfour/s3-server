<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class NotificationRetentionBackendTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_terminal_retention_starts_after_a_delayed_delivery(string $driver, string $dsnVariable): void
    {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || ! str_contains($dsn, 'dbname=s3audit_notification_retention')) {
            self::markTestSkipped('Requires an isolated disposable notification-retention database.');
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));

        try {
            // The exact database name above is reserved for this disposable gate.
            $pool->execute('DELETE FROM s3_notification_queue');
            foreach (['sent', 'dead_letter'] as $status) {
                $id = $metadata->enqueueNotification(
                    'bucket',
                    $status . '.txt',
                    's3:ObjectCreated:Put',
                    'https://93.184.216.34/webhook',
                    '{}',
                );
                $pool->execute("UPDATE s3_notification_queue SET created_at = '2000-01-01 00:00:00' WHERE id = {$id}");
                $leased = $metadata->dequeueNotifications(1)[0];
                self::assertSame($id, $leased['id']);
                self::assertTrue($metadata->updateNotificationStatus(
                    $id,
                    $status,
                    expectedLeaseExpiresAt: $leased['lease_expires_at'],
                ));
            }

            $metadata->cleanupOldNotifications(86400);
            $stats = $metadata->getNotificationQueueStats();
            ksort($stats);
            self::assertSame(['dead_letter' => 1, 'sent' => 1], $stats);

            $pool->execute("UPDATE s3_notification_queue SET next_attempt_at = 0 WHERE status IN ('sent', 'dead_letter')");
            $metadata->cleanupOldNotifications(86400);
            self::assertSame([], $metadata->getNotificationQueueStats());
        } finally {
            $pool->close();
        }
    }
}
