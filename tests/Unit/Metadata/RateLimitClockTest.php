<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class RateLimitClockTest extends TestCase
{
    public function test_sqlite_does_not_create_negative_token_debt_or_regress_refill_time(): void
    {
        $path = sys_get_temp_dir() . '/s3-rate-clock-' . bin2hex(random_bytes(8)) . '.sqlite';
        $metadata = new SqliteMetadataStore($path);
        $metadata->initialize();
        $pdo = new \PDO('sqlite:' . $path);
        $future = microtime(true) + 60.0;

        try {
            $pdo->prepare('INSERT INTO s3_rate_limit_buckets (ip, tokens, last_refill_at) VALUES (?, ?, ?)')
                ->execute(['skewed', 1.5, $future]);

            self::assertTrue($metadata->rateLimitCheck('skewed', 2.0, 1.0));
            $statement = $pdo->query("SELECT tokens, last_refill_at FROM s3_rate_limit_buckets WHERE ip = 'skewed'");
            if ($statement === false) {
                self::fail('The SQLite rate-limit bucket could not be read.');
            }
            $row = $statement->fetch(\PDO::FETCH_ASSOC);
            $statement->closeCursor();
            self::assertIsArray($row);
            self::assertEqualsWithDelta(0.5, (float) $row['tokens'], 0.01);
            self::assertEqualsWithDelta($future, (float) $row['last_refill_at'], 0.01);
            self::assertFalse($metadata->rateLimitCheck('skewed', 2.0, 1.0));

            $pdo->exec("UPDATE s3_rate_limit_buckets SET last_refill_at = last_refill_at - 61 WHERE ip = 'skewed'");
            self::assertTrue($metadata->rateLimitCheck('skewed', 2.0, 1.0));

            $pdo->exec("UPDATE s3_rate_limit_buckets SET tokens = 5, last_refill_at = last_refill_at - 2 WHERE ip = 'skewed'");
            self::assertTrue($metadata->rateLimitCheck('skewed', 2.0, 1.0));
            $capped = $pdo->query("SELECT tokens FROM s3_rate_limit_buckets WHERE ip = 'skewed'");
            if ($capped === false) {
                self::fail('The SQLite rate-limit bucket could not be read.');
            }
            self::assertEqualsWithDelta(1.0, (float) $capped->fetchColumn(), 0.01);
            $capped->closeCursor();
        } finally {
            unset($statement, $capped, $metadata, $pdo);
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /** @return iterable<string, array{string, string}> */
    public static function externalDrivers(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('externalDrivers')]
    public function test_two_nodes_with_skewed_application_clocks_share_one_bucket(string $driver, string $dsnVariable): void
    {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || $dsn === '') {
            self::markTestSkipped("Set {$dsnVariable} for a disposable metadata database.");
        }
        if (preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn) !== 1) {
            self::fail('The rate-limit clock test requires a disposable s3audit_ database.');
        }

        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $ip = 'rate-clock-' . bin2hex(random_bytes(8));
        $timestamps = [];

        try {
            foreach ([60, -60, 60] as $offset) {
                if (count($timestamps) === 2) {
                    $cleanup = new Process(
                        [PHP_BINARY, dirname(__DIR__, 2) . '/Support/rate-limit-skew-worker.php', $driver, $ip, 'cleanup'],
                        null,
                        ['S3_TEST_RATE_LIMIT_DSN' => $dsn, 'S3_TEST_RATE_LIMIT_CLOCK_OFFSET' => '60'],
                    );
                    $cleanup->mustRun();
                }

                $process = new Process(
                    [PHP_BINARY, dirname(__DIR__, 2) . '/Support/rate-limit-skew-worker.php', $driver, $ip],
                    null,
                    ['S3_TEST_RATE_LIMIT_DSN' => $dsn, 'S3_TEST_RATE_LIMIT_CLOCK_OFFSET' => (string) $offset],
                );
                $process->mustRun();
                self::assertSame(count($timestamps) < 2 ? 'allowed' : 'denied', trim($process->getOutput()));

                $result = $driver === 'postgres'
                    ? $pool->execute('SELECT tokens, last_refill_at, EXTRACT(EPOCH FROM clock_timestamp()) AS database_now FROM s3_rate_limit_buckets WHERE ip = $1', [$ip])
                    : $pool->execute('SELECT tokens, last_refill_at, UNIX_TIMESTAMP(CURRENT_TIMESTAMP(6)) AS database_now FROM s3_rate_limit_buckets WHERE ip = ?', [$ip]);
                $row = $result->fetchRow();
                self::assertNotNull($row);
                $timestamps[] = (float) $row['last_refill_at'];
                self::assertGreaterThanOrEqual(0.0, (float) $row['tokens']);
                self::assertEqualsWithDelta((float) $row['database_now'], (float) $row['last_refill_at'], 5.0);
            }

            self::assertGreaterThanOrEqual($timestamps[0], $timestamps[1]);
            self::assertGreaterThanOrEqual($timestamps[1], $timestamps[2]);
        } finally {
            if ($driver === 'postgres') {
                $pool->execute('DELETE FROM s3_rate_limit_buckets WHERE ip = $1', [$ip]);
            } else {
                $pool->execute('DELETE FROM s3_rate_limit_buckets WHERE ip = ?', [$ip]);
            }
        }
    }
}
