<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Process\Process;

final class MultipartIntentClockSkewTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function externalDrivers(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('externalDrivers')]
    public function test_renewal_fences_stale_claim_and_collector_takeover(string $driver, string $dsnVariable): void
    {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || ! str_contains($dsn, 'dbname=s3audit_mpu_clock')) {
            self::markTestSkipped('Requires the isolated s3audit_mpu_clock database.');
        }

        $writer = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $collector = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $writer);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $collector);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $attempt = bin2hex(random_bytes(16));

        try {
            $writer->createBucket('owner', 'bucket', 'us-east-1');
            $writer->createMultipartUpload('clock-' . $attempt, 'bucket', 'key', 'owner');
            $bucketInfo = $writer->getBucket('bucket');
            self::assertNotNull($bucketInfo);
            $generation = $bucketInfo->generation;
            $now = $writer->multipartWriteIntentNow();
            $writer->createMultipartWriteIntent(self::intent($attempt, $generation, $now + 10));
            self::assertFalse($collector->renewMultipartWriteIntent($attempt, $now + 9, $now + 20));
            self::assertTrue($writer->renewMultipartWriteIntent($attempt, $now + 10, $now + 20));
            self::assertFalse($collector->renewMultipartWriteIntent($attempt, $now + 10, $now + 30));
            self::assertSame([], $collector->claimDueMultipartWriteIntents(1, $now + 11, $now + 311));

            $pool->execute(
                $driver === 'postgres'
                ? 'UPDATE s3_multipart_write_intents SET lease_until = $1, next_attempt_at = $1 WHERE attempt_id = $2'
                : 'UPDATE s3_multipart_write_intents SET lease_until = ?, next_attempt_at = ? WHERE attempt_id = ?',
                $driver === 'postgres' ? [$now - 1, $attempt] : [$now - 1, $now - 1, $attempt],
            );
            self::assertFalse($writer->renewMultipartWriteIntent($attempt, $now - 1, $now + 30));
            $claimed = $collector->claimDueMultipartWriteIntents(1, $now, $now + 300);
            self::assertSame([$attempt], array_map(static fn(MultipartWriteIntent $row): string => $row->attemptId, $claimed));
            self::assertFalse($writer->renewMultipartWriteIntent($attempt, $now - 1, $now + 40));
            self::assertSame('abandoned', $writer->getMultipartWriteIntent($attempt)?->state);
        } finally {
            $pool->execute($driver === 'postgres'
                ? 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1'
                : 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = ?', [$attempt]);
            $writer->deleteBucket('owner', 'bucket');
            $pool->close();
        }
    }

    #[DataProvider('externalDrivers')]
    public function test_skewed_nodes_cannot_expire_or_extend_other_nodes_completion_claims(string $driver, string $dsnVariable): void
    {
        $dsn = getenv($dsnVariable);
        if ($dsn === false || ! str_contains($dsn, 'dbname=s3audit_mpu_clock')) {
            self::markTestSkipped('Requires the isolated s3audit_mpu_clock database.');
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartWriteIntentStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $first = bin2hex(random_bytes(16));
        $expired = bin2hex(random_bytes(16));
        $committable = bin2hex(random_bytes(16));
        $skewedCreator = bin2hex(random_bytes(16));
        $aborted = bin2hex(random_bytes(16));

        try {
            $metadata->createBucket('owner', 'bucket', 'us-east-1');
            foreach ([$first, $expired, $committable, $skewedCreator, $aborted] as $attempt) {
                $metadata->createMultipartUpload('clock-' . $attempt, 'bucket', 'key', 'owner');
            }
            $bucketInfo = $metadata->getBucket('bucket');
            self::assertNotNull($bucketInfo);
            $generation = $bucketInfo->generation;
            $metadata->createMultipartWriteIntent(self::intent($first, $generation, microtime(true) + 600));
            $fastClaim = $this->worker($driver, $dsn, 'claim', $first, 3600);
            $firstState = $metadata->getMultipartWriteIntent($first)?->state;

            $metadata->createMultipartWriteIntent(self::intent($expired, $generation, microtime(true) - 1));
            $slowClaim = $this->worker($driver, $dsn, 'claim', $expired, -3600);
            $expiredState = $metadata->getMultipartWriteIntent($expired)?->state;

            $metadata->createMultipartWriteIntent(self::intent($committable, $generation, microtime(true) + 600));
            $consume = $this->worker($driver, $dsn, 'consume', $committable, 3600);
            $committableState = $metadata->getMultipartWriteIntent($committable)?->state;

            $created = $this->worker($driver, $dsn, 'create', $skewedCreator, 3600);
            $createdIntent = $metadata->getMultipartWriteIntent($skewedCreator);
            self::assertNotNull($createdIntent);
            self::assertEqualsWithDelta($metadata->multipartWriteIntentNow() + 600, $createdIntent->leaseUntil, 5.0);

            $metadata->createMultipartWriteIntent(self::intent($aborted, $generation, microtime(true) + 600));
            $abandon = $this->worker($driver, $dsn, 'abandon', $aborted, 3600);
            $due = $metadata->claimDueMultipartWriteIntents(
                10,
                $metadata->multipartWriteIntentNow(),
                $metadata->multipartWriteIntentNow() + 300,
            );
            self::assertSame([$aborted], array_map(static fn($intent): string => $intent->attemptId, $due));
            $retry = $this->worker($driver, $dsn, 'retry', $aborted, -3600);
            $retriedIntent = $metadata->getMultipartWriteIntent($aborted);
            self::assertNotNull($retriedIntent);
            self::assertEqualsWithDelta($metadata->multipartWriteIntentNow() + 60, $retriedIntent->nextAttemptAt, 5.0);

            self::assertSame(
                [[], 'active', [$expired], 'abandoned', 'consumed', null, 'created', 'abandoned', 'retried'],
                [$fastClaim, $firstState, $slowClaim, $expiredState, $consume, $committableState, $created, $abandon, $retry],
            );
        } finally {
            $pool->execute("DELETE FROM s3_multipart_write_intents
                WHERE attempt_id IN ('{$first}', '{$expired}', '{$committable}', '{$skewedCreator}', '{$aborted}')");
            $metadata->deleteBucket('owner', 'bucket');
            $pool->close();
        }
    }

    /** @return list<string>|string */
    private function worker(string $driver, string $dsn, string $action, string $attemptId, int $offset): array|string
    {
        $process = new Process(
            [PHP_BINARY, dirname(__DIR__) . '/Support/multipart-intent-clock-worker.php', $driver, $action, $attemptId],
            null,
            ['S3_TEST_MULTIPART_CLOCK_DSN' => $dsn, 'S3_TEST_MULTIPART_CLOCK_OFFSET' => (string) $offset],
        );
        $process->mustRun();
        $output = trim($process->getOutput());

        if ($action !== 'claim') {
            return $output;
        }

        /** @var list<string> $claimed */
        $claimed = json_decode($output, true, 16, JSON_THROW_ON_ERROR);

        return $claimed;
    }

    private static function intent(string $attemptId, string $generation, float $deadline): MultipartWriteIntent
    {
        return new MultipartWriteIntent(
            attemptId: $attemptId,
            uploadId: 'clock-' . $attemptId,
            bucket: 'bucket',
            bucketGeneration: $generation,
            ownerId: 'owner',
            storageTier: 'STANDARD',
            assemblyPath: 'assembly/' . $attemptId,
            finalPath: 'final/' . $attemptId,
            partDigest: str_repeat('b', 64),
            sseMode: null,
            leaseUntil: $deadline,
            nextAttemptAt: $deadline,
        );
    }
}
