<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use PHPUnit\Framework\TestCase;

final class FutureSchemaVersionTest extends TestCase
{
    public function test_sqlite_rejects_a_newer_schema_but_accepts_the_current_version(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = new SchemaManager($pdo);
        $schema->migrate();
        $schema->migrate();

        $futureVersion = SqliteSchema::VERSION + 1;
        $pdo->exec("INSERT INTO s3_schema_version (version, description) VALUES ({$futureVersion}, 'Future-version fixture')");

        try {
            $schema->migrate();
            self::fail('The older package accepted a newer metadata schema.');
        } catch (\RuntimeException $error) {
            self::assertStringContainsString((string) $futureVersion, $error->getMessage());
            self::assertStringContainsString((string) SqliteSchema::VERSION, $error->getMessage());
        }
    }
}
