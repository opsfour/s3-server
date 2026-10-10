<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream;
use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use League\Uri\Http;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Handler\Object\DeleteObjectsHandler;
use OpsFour\S3Server\Middleware\ErrorHandlingMiddleware;
use OpsFour\S3Server\Storage\InMemoryBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class DeleteObjectsDatabaseRollbackTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_later_database_failure_rolls_back_entire_delete_batch_and_maps_to_500(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} to run the disposable {$driver} rollback test.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => trim($dsn)]);
        $pool = $driver === 'postgres'
            ? new PostgresConnectionPool(PostgresConfig::fromString($dsn))
            : new MysqlConnectionPool(MysqlConfig::fromString($dsn));
        $suffix = bin2hex(random_bytes(6));
        $owner = 'owner-' . $suffix;
        $bucket = 'delete-rollback-' . $suffix;
        $goodKey = 'good-' . $suffix;
        $badKey = 'bad-' . $suffix;
        $trigger = 's3_delete_fault_' . $suffix;
        $function = 's3_delete_fault_fn_' . $suffix;
        $triggerInstalled = false;
        $bucketCreated = false;

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $bucketCreated = true;
            $metadata->setBucketVersioning($bucket, 'Enabled');
            $goodVersion = $metadata->putObjectVersioned($bucket, $goodKey, $owner, 1, 'etag', 'text/plain', 'good/path');
            $badVersion = $metadata->putObjectVersioned($bucket, $badKey, $owner, 1, 'etag', 'text/plain', 'bad/path');
            $metadata->putObjectTagging($bucket, $badKey, [['key' => 'fault', 'value' => 'trigger']], $badVersion);

            if ($driver === 'postgres') {
                $pool->execute("CREATE FUNCTION {$function}() RETURNS trigger LANGUAGE plpgsql AS '\n"
                    . "BEGIN RAISE EXCEPTION ''simulated delete failure''; END\n'");
                $pool->execute("CREATE TRIGGER {$trigger} BEFORE DELETE ON s3_tagging FOR EACH ROW "
                    . "WHEN (OLD.key_name = '{$badKey}') EXECUTE FUNCTION {$function}()");
            } else {
                $pool->query("CREATE TRIGGER {$trigger} BEFORE DELETE ON s3_tagging FOR EACH ROW "
                    . "BEGIN IF OLD.key_name = '{$badKey}' THEN SIGNAL SQLSTATE '45000' "
                    . "SET MESSAGE_TEXT = 'simulated delete failure'; END IF; END");
            }
            $triggerInstalled = true;

            $body = '<Delete><Object><Key>' . $goodKey . '</Key><VersionId>' . $goodVersion . '</VersionId></Object>'
                . '<Object><Key>' . $badKey . '</Key><VersionId>' . $badVersion . '</VersionId></Object></Delete>';
            $request = new Request($this->createMock(Client::class), 'POST', Http::new('http://127.0.0.1/' . $bucket . '?delete'), [], $body);
            $request->setAttribute('s3.bucket', $bucket);
            $request->setAttribute('ownerId', $owner);
            $request->setAttribute('requestId', 'delete-rollback-test');
            $handler = new DeleteObjectsHandler($metadata, StorageTierRegistry::single(new InMemoryBackend()));

            $response = (new ErrorHandlingMiddleware())->handleRequest($request, $handler);

            self::assertSame(500, $response->getStatus());
            self::assertStringContainsString('<Code>InternalError</Code>', ByteStream\buffer($response->getBody()));
            self::assertNotNull($metadata->getObjectMetadataByVersion($bucket, $goodKey, $goodVersion));
            self::assertNotNull($metadata->getObjectMetadataByVersion($bucket, $badKey, $badVersion));
            self::assertSame([['key' => 'fault', 'value' => 'trigger']], $metadata->getObjectTagging($bucket, $badKey, $badVersion));
        } finally {
            if ($triggerInstalled) {
                if ($driver === 'postgres') {
                    $pool->execute("DROP TRIGGER {$trigger} ON s3_tagging");
                } else {
                    $pool->query("DROP TRIGGER {$trigger}");
                }
            }
            if ($driver === 'postgres') {
                $pool->execute("DROP FUNCTION IF EXISTS {$function}()");
            }
            if ($bucketCreated) {
                $metadata->deleteObjectMetadata($bucket, $goodKey);
                $metadata->deleteObjectMetadata($bucket, $badKey);
                foreach ($metadata->listObjectVersions($bucket)['versions'] as $version) {
                    $metadata->deleteObjectVersion($bucket, $version->key, $version->versionId ?? 'null');
                }
                $metadata->deleteBucket($owner, $bucket);
            }
            $pool->close();
        }
    }
}
