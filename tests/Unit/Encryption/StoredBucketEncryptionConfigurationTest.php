<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Encryption;

use Amp\Http\Server\Driver\Client;
use Amp\Http\Server\Request;
use League\Uri\Http;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Console\MetadataMigrateCommand;
use OpsFour\S3Server\Handler\Bucket\GetBucketEncryptionHandler;
use OpsFour\S3Server\Handler\Multipart\CreateMultipartUploadHandler;
use OpsFour\S3Server\Handler\Object\PutObjectHandler;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use OpsFour\S3Server\Runtime\MetadataDriverStartupGuard;
use OpsFour\S3Server\Storage\InMemoryBackend;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

final class StoredBucketEncryptionConfigurationTest extends TestCase
{
    private string $path;

    private SqliteMetadataStore $metadata;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-legacy-encryption-' . bin2hex(random_bytes(6)) . '.sqlite';
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        (new SchemaManager($pdo))->migrate(34);
        $this->metadata = new SqliteMetadataStore($this->path);
        $this->metadata->createBucket('owner', 'bucket', 'us-east-1');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    /** @return iterable<string, array{string, ?string, int}> */
    public static function invalidRows(): iterable
    {
        yield 'AES256 with key ID' => ['AES256', 'alias/legacy', 0];
        yield 'AES256 with bucket key' => ['AES256', null, 1];
        yield 'AES256 with both' => ['AES256', 'alias/legacy', 1];
        yield 'unsupported KMS default' => ['aws:kms', 'alias/legacy', 0];
        yield 'nonboolean bucket key' => ['AES256', null, 2];
    }

    #[DataProvider('invalidRows')]
    public function test_legacy_row_blocks_start_migration_get_and_explicit_write_then_repair_allows_them(
        string $algorithm,
        ?string $keyId,
        int $bucketKey,
    ): void {
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $this->restoreLegacySchema($pdo);
        $insert = $pdo->prepare('INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled) VALUES (?, ?, ?, ?)');
        $insert->execute(['bucket', $algorithm, $keyId, $bucketKey]);

        $migration = new CommandTester(new MetadataMigrateCommand());
        self::assertSame(Command::FAILURE, $migration->execute([
            '--metadata-driver' => 'sqlite',
            '--metadata-path' => $this->path,
        ]));
        self::assertStringNotContainsString('alias/legacy', $migration->getDisplay());

        foreach ([
            fn() => (new SchemaManager($pdo))->migrate(),
            fn() => MetadataDriverStartupGuard::assertSchemaCurrent($this->metadata),
            fn() => $this->metadata->getBucketEncryption('bucket'),
            fn() => (new GetBucketEncryptionHandler($this->metadata))->handleRequest($this->request('GET')),
            fn() => EncryptionRequestResolver::resolveDestination($this->request('PUT', ['x-amz-server-side-encryption' => 'AES256']), $this->metadata, 'bucket', null),
            fn() => EncryptionRequestResolver::resolveDestination($this->request('PUT', [
                'x-amz-server-side-encryption-customer-algorithm' => 'AES256',
                'x-amz-server-side-encryption-customer-key' => base64_encode(str_repeat('K', 32)),
                'x-amz-server-side-encryption-customer-key-md5' => base64_encode(md5(str_repeat('K', 32), true)),
            ]), $this->metadata, 'bucket', null),
        ] as $operation) {
            try {
                $operation();
                self::fail('Invalid stored encryption configuration was accepted.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Stored bucket encryption configuration is unsupported', $error->getMessage());
                self::assertStringNotContainsString('alias/legacy', $error->getMessage());
            }
        }

        $pdo->exec("UPDATE s3_encryption_configs SET sse_algorithm = 'AES256', kms_master_key_id = NULL, bucket_key_enabled = 0 WHERE bucket = 'bucket'");
        self::assertSame(Command::SUCCESS, $migration->execute([
            '--metadata-driver' => 'sqlite',
            '--metadata-path' => $this->path,
        ]));
        (new SchemaManager($pdo))->migrate();
        MetadataDriverStartupGuard::assertSchemaCurrent($this->metadata);
        self::assertSame('AES256', $this->metadata->getBucketEncryption('bucket')['sseAlgorithm'] ?? null);
    }

    public function test_direct_metadata_writes_reject_unsupported_fields_before_mutation(): void
    {
        foreach ([
            ['AES256', 'alias/legacy', false],
            ['AES256', null, true],
            ['aws:kms', null, false],
        ] as [$algorithm, $keyId, $bucketKey]) {
            try {
                $this->metadata->putBucketEncryption('bucket', $algorithm, $keyId, $bucketKey);
                self::fail('Unsupported encryption was stored.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Stored bucket encryption configuration is unsupported', $error->getMessage());
                self::assertNull($this->metadata->getBucketEncryption('bucket'));
            }
        }
    }

    public function test_uninitialized_sqlite_file_has_no_stored_encryption_inventory(): void
    {
        $path = sys_get_temp_dir() . '/s3-empty-encryption-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            (new SqliteMetadataStore($path))->assertSupportedBucketEncryptionInventory();
            self::assertFileExists($path);
        } finally {
            @unlink($path);
        }
    }

    public function test_current_sqlite_schema_rejects_direct_invalid_encryption_sql(): void
    {
        $path = sys_get_temp_dir() . '/s3-current-encryption-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

            $this->expectException(\PDOException::class);
            $pdo->exec("INSERT INTO s3_encryption_configs
                (bucket, sse_algorithm, kms_master_key_id, bucket_key_enabled)
                VALUES ('bucket', 'AES256', 'alias/invalid', 0)");
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_legacy_row_blocks_put_and_multipart_initiation_before_any_mutation(): void
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        $this->restoreLegacySchema($pdo);
        $pdo->exec("INSERT INTO s3_encryption_configs (bucket, sse_algorithm, kms_master_key_id) VALUES ('bucket', 'AES256', 'alias/legacy')");
        $storage = new InMemoryBackend();

        foreach ([
            fn() => (new PutObjectHandler($this->metadata, $storage))->handleRequest($this->request('PUT', ['x-amz-server-side-encryption' => 'AES256'])),
            fn() => (new CreateMultipartUploadHandler($this->metadata))->handleRequest($this->request('POST')),
        ] as $operation) {
            try {
                $operation();
                self::fail('Legacy row allowed an object write.');
            } catch (\RuntimeException $error) {
                self::assertStringContainsString('Stored bucket encryption configuration is unsupported', $error->getMessage());
            }
        }

        self::assertNull($this->metadata->getObjectMetadata('bucket', 'key'));
        self::assertSame([], $this->metadata->listMultipartUploads('bucket')['uploads']);
    }

    /**
     * @param 'GET'|'PUT'|'POST' $method
     * @param array<non-empty-string, array<string>|string> $headers
     */
    private function request(string $method, array $headers = []): Request
    {
        $request = new Request($this->createMock(Client::class), $method, Http::new('http://127.0.0.1/bucket/key'), $headers);
        $request->setAttribute('s3.bucket', 'bucket');
        $request->setAttribute('s3.key', 'key');
        $request->setAttribute('ownerId', 'owner');

        return $request;
    }

    private function restoreLegacySchema(\PDO $pdo): void
    {
        $pdo->beginTransaction();
        try {
            $pdo->exec('DROP TABLE s3_encryption_configs');
            $pdo->exec(<<<'SQL'
                CREATE TABLE s3_encryption_configs (
                    id INTEGER PRIMARY KEY AUTOINCREMENT,
                    bucket TEXT NOT NULL UNIQUE,
                    sse_algorithm TEXT NOT NULL DEFAULT 'AES256',
                    kms_master_key_id TEXT,
                    bucket_key_enabled INTEGER NOT NULL DEFAULT 0,
                    created_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now')),
                    updated_at TEXT NOT NULL DEFAULT (strftime('%Y-%m-%dT%H:%M:%SZ', 'now'))
                )
                SQL);
            $pdo->exec('DELETE FROM s3_schema_version WHERE version = 35');
            $pdo->commit();
        } catch (\Throwable $error) {
            $pdo->rollBack();
            throw $error;
        }
    }
}
