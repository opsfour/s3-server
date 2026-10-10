<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class CorsMetadataStoreTest extends TestCase
{
    public function test_ids_and_zero_maximum_age_round_trip_and_legacy_zero_migrates_as_absent(): void
    {
        $path = sys_get_temp_dir() . '/s3-cors-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $path);
            (new SchemaManager($pdo))->migrate(38);
            $pdo->exec("INSERT INTO s3_cors_rules
                (bucket, rule_order, allowed_origins, allowed_methods, allowed_headers, expose_headers, max_age_seconds)
                VALUES ('legacy', 0, '[\"*\"]', '[\"GET\"]', '[]', '[]', 0)");
            unset($pdo);

            $store = new SqliteMetadataStore($path);
            $store->initialize();

            self::assertSame(SqliteSchema::VERSION, self::schemaVersion($path));
            self::assertSame(null, $store->getBucketCors('legacy')[0]['maxAgeSeconds']);

            $store->putBucketCors('current', [
                self::rule('sdk-rule', 0),
                self::rule(null, null),
                self::rule('largest', 2_147_483_647),
            ]);

            self::assertSame([
                self::rule('sdk-rule', 0),
                self::rule(null, null),
                self::rule('largest', 2_147_483_647),
            ], $store->getBucketCors('current'));
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    /** @return array{id?: string, allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null} */
    private static function rule(?string $id, ?int $maxAgeSeconds): array
    {
        $rule = [
            'allowedOrigins' => ['*'],
            'allowedMethods' => ['GET'],
            'allowedHeaders' => [],
            'exposeHeaders' => [],
            'maxAgeSeconds' => $maxAgeSeconds,
        ];
        if ($id !== null) {
            $rule['id'] = $id;
        }

        return $rule;
    }

    private static function schemaVersion(string $path): int
    {
        $pdo = new \PDO('sqlite:' . $path);
        $statement = $pdo->query('SELECT MAX(version) FROM s3_schema_version');
        self::assertInstanceOf(\PDOStatement::class, $statement);
        $value = $statement->fetchColumn();

        return $value !== false ? (int) $value : 0;
    }
}
