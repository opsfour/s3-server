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
        self::assertSame(12, MysqlSchema::VERSION);
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", MysqlSchema::getCreateStatements()),
        );
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", MysqlSchema::getMigrationStatements(11)),
        );
        self::assertSame([], MysqlSchema::getMigrationStatements(12));
    }

    public function test_postgres_schema_contains_owner_write_lock_migration(): void
    {
        self::assertSame(12, PostgresSchema::VERSION);
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", PostgresSchema::getCreateStatements()),
        );
        self::assertStringContainsString(
            's3_owner_write_locks',
            implode("\n", PostgresSchema::getMigrationStatements(11)),
        );
        self::assertSame([], PostgresSchema::getMigrationStatements(12));
    }
}
