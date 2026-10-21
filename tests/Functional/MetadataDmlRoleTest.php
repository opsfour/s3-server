<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use OpsFour\S3Server\Factory\MetadataStoreFactory;
use PHPUnit\Framework\TestCase;

final class MetadataDmlRoleTest extends TestCase
{
    public function test_metadata_bucket_and_object_writes_with_a_role_denied_ddl(): void
    {
        $driver = getenv('S3_TEST_METADATA_DML_DRIVER');
        $adminDsn = getenv('S3_TEST_METADATA_DML_ADMIN_DSN');
        $runtimeDsn = getenv('S3_TEST_METADATA_DML_RUNTIME_DSN');
        $pdoDsn = getenv('S3_TEST_METADATA_DML_ADMIN_PDO_DSN');
        if (! in_array($driver, ['postgres', 'mysql'], true)
            || ! is_string($adminDsn)
            || ! is_string($runtimeDsn)
            || ! is_string($pdoDsn)
            || getenv('S3_TEST_DESTRUCTIVE_METADATA_MIGRATIONS') !== '1'
            || preg_match('/(?:^|[;\s])dbname=s3audit_metadata_dml(?:[;\s]|$)/i', $adminDsn) !== 1
            || preg_match('/(?:^|[;\s])dbname=s3audit_metadata_dml(?:[;\s]|$)/i', $runtimeDsn) !== 1
            || preg_match('/(?:^|;)dbname=s3audit_metadata_dml(?:;|$)/i', $pdoDsn) !== 1) {
            self::markTestSkipped('Requires an isolated disposable s3audit_metadata_dml database and destructive opt-in.');
        }

        $admin = new \PDO(
            $pdoDsn,
            getenv('S3_TEST_METADATA_DML_ADMIN_USER') ?: null,
            getenv('S3_TEST_METADATA_DML_ADMIN_PASSWORD') ?: null,
            [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
        );
        MetadataStoreFactory::create($driver, ['dsn' => $adminDsn]);
        $role = 's3audit_metadata_runtime';
        if ($driver === 'postgres') {
            $admin->exec("DROP ROLE IF EXISTS {$role}");
            $admin->exec("CREATE ROLE {$role} LOGIN PASSWORD 's3audit'");
            $admin->exec("GRANT CONNECT ON DATABASE s3audit_metadata_dml TO {$role}");
            $admin->exec("GRANT USAGE ON SCHEMA public TO {$role}");
            $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON ALL TABLES IN SCHEMA public TO {$role}");
        } else {
            $admin->exec("DROP USER IF EXISTS '{$role}'@'%'");
            $admin->exec("CREATE USER '{$role}'@'%' IDENTIFIED BY 's3audit'");
            $admin->exec("GRANT SELECT, INSERT, UPDATE, DELETE ON s3audit_metadata_dml.* TO '{$role}'@'%'");
            $admin->exec("GRANT EXECUTE ON FUNCTION s3audit_metadata_dml.s3_recovery_capacity_trigger_count TO '{$role}'@'%'");
        }

        $bucket = 'dml-' . bin2hex(random_bytes(6));
        try {
            $runtime = new \PDO(
                $pdoDsn,
                $role,
                's3audit',
                [\PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION],
            );
            try {
                $runtime->exec('CREATE TABLE s3audit_metadata_ddl_forbidden (id INT)');
                self::fail('The runtime role unexpectedly has DDL rights.');
            } catch (\PDOException) {
                // DML-only is an actual database grant, not a test convention.
            }

            $store = MetadataStoreFactory::create($driver, ['dsn' => $runtimeDsn], migrate: false);
            $store->createBucket('dml-owner', $bucket, 'us-east-1');
            self::assertSame('dml-owner', $store->getBucket($bucket)?->ownerId);
            if ($driver === 'postgres') {
                try {
                    $store->putObjectMetadata($bucket, 'key', 'dml-owner', 5, 'etag', 'text/plain', 'path');
                    self::fail('PostgreSQL BIGSERIAL insert unexpectedly worked without sequence USAGE.');
                } catch (\Throwable $error) {
                    self::assertStringContainsString('permission denied for sequence', $error->getMessage());
                }
                $admin->exec("GRANT USAGE ON ALL SEQUENCES IN SCHEMA public TO {$role}");
            }
            $store->putObjectMetadata($bucket, 'key', 'dml-owner', 5, 'etag', 'text/plain', 'path');
            self::assertSame('etag', $store->getObjectMetadata($bucket, 'key')?->etag);
            $store->deleteObjectMetadata($bucket, 'key');
            $store->deleteBucket('dml-owner', $bucket);
        } finally {
            $runtime = null;
            if ($driver === 'postgres') {
                $admin->exec("REVOKE ALL PRIVILEGES ON ALL SEQUENCES IN SCHEMA public FROM {$role}");
                $admin->exec("REVOKE ALL PRIVILEGES ON ALL TABLES IN SCHEMA public FROM {$role}");
                $admin->exec("REVOKE USAGE ON SCHEMA public FROM {$role}");
                $admin->exec("REVOKE CONNECT ON DATABASE s3audit_metadata_dml FROM {$role}");
                $admin->exec("DROP ROLE {$role}");
            } else {
                $admin->exec("DROP USER '{$role}'@'%'");
            }
        }
    }
}
