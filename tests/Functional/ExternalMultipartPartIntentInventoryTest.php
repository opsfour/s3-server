<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Console\MultipartPartIntentCommand;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentInventoryStore;
use OpsFour\S3Server\Metadata\MultipartPartWriteIntentStore;
use OpsFour\S3Server\Storage\StoragePath;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class ExternalMultipartPartIntentInventoryTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_owner_scoped_page_contains_only_path_hash(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for the disposable {$driver} inventory gate.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        self::assertInstanceOf(MultipartPartWriteIntentStore::class, $metadata);
        self::assertInstanceOf(MultipartPartWriteIntentInventoryStore::class, $metadata);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $owner = 'inventory-owner-' . bin2hex(random_bytes(5));
        $bucket = 'inventory-' . bin2hex(random_bytes(5));
        $uploadId = bin2hex(random_bytes(16));
        $path = null;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->createMultipartUpload($uploadId, $bucket, 'key', $owner);
            $generation = $metadata->getBucket($bucket)?->generation;
            self::assertNotNull($generation);
            $path = StoragePath::forPlannedPart('', $bucket, $generation, $uploadId, 1, bin2hex(random_bytes(16)));
            $metadata->createMultipartPartWriteIntent($bucket, $generation, 'STANDARD', $uploadId, $path);

            $page = $metadata->listMultipartPartWriteIntents($owner, 0, 1);
            self::assertCount(1, $page);
            self::assertSame(hash('sha256', $path), $page[0]['path_sha256']);
            self::assertArrayNotHasKey('storage_path', $page[0]);
            self::assertSame([], $metadata->listMultipartPartWriteIntents($owner, $page[0]['id'], 1));
            self::assertSame([], $metadata->listMultipartPartWriteIntents($owner . '-other', 0, 1));

            $tester = new CommandTester(new MultipartPartIntentCommand());
            $exit = $tester->execute([
                'owner-id' => $owner,
                '--metadata-driver' => $driver,
                '--metadata-dsn' => $dsn,
                '--limit' => '1',
            ]);
            self::assertSame(Command::SUCCESS, $exit, $tester->getDisplay());
            self::assertStringContainsString(hash('sha256', $path), $tester->getDisplay());
            self::assertStringNotContainsString($path, $tester->getDisplay());
            self::assertStringNotContainsString($dsn, $tester->getDisplay());
        } finally {
            if ($path !== null) {
                $sql = $driver === 'postgres'
                    ? "DELETE FROM s3_storage_garbage WHERE storage_path = $1 AND operation = 'part_write_intent' AND owner_id = $2"
                    : "DELETE FROM s3_storage_garbage WHERE storage_path = ? AND operation = 'part_write_intent' AND owner_id = ?";
                $pool->execute($sql, [$path, $owner]);
            }
            if ($metadata->getMultipartUpload($uploadId) !== null) {
                $metadata->deleteMultipartUpload($uploadId);
            }
            if ($metadata->getBucket($bucket) !== null) {
                $metadata->deleteBucket($owner, $bucket);
            }
        }
    }
}
