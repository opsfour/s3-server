<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\MysqlSchema;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use PHPUnit\Framework\TestCase;

final class ExternalSchemaTest extends TestCase
{
    public function test_mysql_schema_contains_owner_write_lock_migration(): void
    {
        self::assertSame(15, MysqlSchema::VERSION);
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", MysqlSchema::getCreateStatements()),
        );
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", MysqlSchema::getMigrationStatements(11)),
        );
        self::assertStringContainsString(
            'max_multipart_uploads_per_bucket',
            implode("\n", MysqlSchema::getMigrationStatements(13)),
        );
        self::assertStringContainsString(
            's3_storage_garbage',
            implode("\n", MysqlSchema::getMigrationStatements(14)),
        );
    }

    public function test_postgres_schema_contains_owner_write_lock_migration(): void
    {
        self::assertSame(15, PostgresSchema::VERSION);
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", PostgresSchema::getCreateStatements()),
        );
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", PostgresSchema::getMigrationStatements(11)),
        );
        self::assertStringContainsString(
            'max_multipart_uploads_per_bucket',
            implode("\n", PostgresSchema::getMigrationStatements(13)),
        );
        self::assertStringContainsString(
            's3_storage_garbage',
            implode("\n", PostgresSchema::getMigrationStatements(14)),
        );
    }
}
