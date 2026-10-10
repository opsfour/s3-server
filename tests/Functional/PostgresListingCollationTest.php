<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Metadata\PostgresMetadataStore;
use OpsFour\S3Server\Metadata\Schema\PostgresSchema;
use PHPUnit\Framework\TestCase;

final class PostgresListingCollationTest extends TestCase
{
    public function test_non_binary_database_lists_keys_and_resumes_in_utf8_byte_order(): void
    {
        $dsn = getenv('S3_TEST_POSTGRES_LISTING_ICU_DSN');
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped('Set S3_TEST_POSTGRES_LISTING_ICU_DSN to an isolated s3audit_ database with non-C ICU collation.');
        }

        $pool = new PostgresConnectionPool(PostgresConfig::fromString(trim($dsn)));
        $database = $pool->query('SELECT current_database() AS database_name')->fetchRow();
        self::assertIsString($database['database_name'] ?? null);
        self::assertMatchesRegularExpression('/^s3audit_listing(?:_|$)/', $database['database_name'], 'The ICU listing fixture requires its own disposable database.');
        $store = new PostgresMetadataStore($pool);
        $store->initialize();
        $defaultOrder = [];
        foreach ($pool->execute("SELECT key FROM (VALUES ('Z'), ('a'), ('z'), ('é')) AS items(key) ORDER BY key") as $row) {
            if (!is_string($row['key'] ?? null)) {
                throw new \UnexpectedValueException('Expected a text key from PostgreSQL.');
            }
            $defaultOrder[] = $row['key'];
        }
        if ($defaultOrder === ['Z', 'a', 'z', 'é']) {
            $pool->close();
            self::markTestSkipped('The fixture database does not have a non-binary default collation.');
        }

        $bucket = 's3audit-list-' . bin2hex(random_bytes(5));
        $owner = 's3audit-list-owner';
        $flat = ['Z', 'a', 'z', 'é'];
        $grouped = array_map(static fn(string $key): string => 'group/' . $key . '/item', $flat);
        $allKeys = array_merge($flat, $grouped);
        usort($allKeys, 'strcmp');
        $versionKeys = array_merge($allKeys, ['a', 'é']);
        usort($versionKeys, 'strcmp');
        $uploads = [];
        $store->createBucket($owner, $bucket, 'us-east-1');
        try {
            foreach ($allKeys as $key) {
                $store->putObjectMetadata($bucket, $key, $owner, 1, '"etag"', 'text/plain', 'fixture');
            }
            $store->putObjectVersioned($bucket, 'a', $owner, 1, '"etag"', 'text/plain', 'fixture-version');
            $store->putObjectVersioned($bucket, 'é', $owner, 1, '"etag"', 'text/plain', 'fixture-version');
            foreach ($allKeys as $key) {
                $id = 'upload-' . bin2hex(random_bytes(8));
                $store->createMultipartUpload($id, $bucket, $key, $owner);
                $uploads[] = $id;
            }

            $this->assertObjectPages($store, $bucket, $allKeys);
            $this->assertVersionPages($store, $bucket, $versionKeys);
            $this->assertMultipartPages($store, $bucket, $allKeys);
            $this->assertDelimitedPages($store, $bucket, ['group/Z/', 'group/a/', 'group/z/', 'group/é/']);
            self::assertSame(['é'], array_map(static fn($object): string => $object->key, $store->listObjects($bucket, prefix: 'é')->objects));
            self::assertSame(['é'], array_column($store->listMultipartUploads($bucket, prefix: 'é')['uploads'], 'key_name'));
            $pool->execute("UPDATE s3_objects SET created_at = '2020-01-01T00:00:00Z' WHERE bucket = $1", [$bucket]);
            $store->deleteObjectVersioned($bucket, 'orphan/Z', $owner);
            $store->deleteObjectVersioned($bucket, 'orphan/é', $owner);
            $this->assertLifecyclePages($store, $bucket, $allKeys);

            self::assertStringContainsString(
                '_listing_c',
                self::explainIndex($pool, 'SELECT key_name FROM s3_objects WHERE bucket = $1 AND is_latest = TRUE AND is_delete_marker = FALSE AND key_name COLLATE "C" > $2 ORDER BY key_name COLLATE "C" LIMIT 1', [$bucket, 'a']),
            );
            self::assertStringContainsString(
                'idx_s3_objects_versions_listing_c',
                self::explainIndex($pool, 'SELECT key_name FROM s3_objects WHERE bucket = $1 AND key_name COLLATE "C" > $2 ORDER BY key_name COLLATE "C", is_latest DESC, created_at DESC, id DESC LIMIT 1', [$bucket, 'a']),
            );
            self::assertStringContainsString(
                'idx_s3_multipart_uploads_listing_c',
                self::explainIndex($pool, 'SELECT key_name FROM s3_multipart_uploads WHERE bucket = $1 AND key_name COLLATE "C" > $2 ORDER BY key_name COLLATE "C", upload_id COLLATE "C" LIMIT 1', [$bucket, 'a']),
            );

            foreach ([
                'idx_s3_objects_latest_listing_c',
                'idx_s3_objects_versions_listing_c',
                'idx_s3_objects_lifecycle_listing_c',
                'idx_s3_multipart_uploads_listing_c',
            ] as $index) {
                $pool->execute('DROP INDEX ' . $index);
            }
            $pool->execute('DELETE FROM s3_schema_version WHERE version = 29');
            $pool->execute("INSERT INTO s3_schema_version (version, description) VALUES (28, 'Isolated v28 listing fixture')");
            $store->initialize();
            $version = $pool->execute('SELECT MAX(version) AS version FROM s3_schema_version')->fetchRow();
            self::assertSame(PostgresSchema::VERSION, (int) ($version['version'] ?? 0));
            foreach (PostgresSchema::getMigrationStatements(28) as $statement) {
                if (!str_contains($statement, '_listing_c')) {
                    continue;
                }
                self::assertNotNull($pool->execute('SELECT indexname FROM pg_indexes WHERE indexname = $1', [self::indexName($statement)])->fetchRow());
            }
            $this->assertObjectPages($store, $bucket, $allKeys);
        } finally {
            foreach ($uploads as $id) {
                $store->deleteMultipartUpload($id);
            }
            $pool->execute('DELETE FROM s3_objects WHERE bucket = $1', [$bucket]);
            $store->deleteBucket($owner, $bucket);
            $pool->close();
        }
    }

    /** @param list<string> $expected */
    private function assertObjectPages(PostgresMetadataStore $store, string $bucket, array $expected): void
    {
        $keys = [];
        $token = null;
        do {
            $page = $store->listObjects($bucket, maxKeys: 1, continuationToken: $token);
            foreach ($page->objects as $object) {
                $keys[] = $object->key;
            }
            $token = $page->nextContinuationToken;
        } while ($token !== null);
        self::assertSame($expected, $keys);

        $keys = [];
        $marker = null;
        do {
            $page = $store->listObjects($bucket, maxKeys: 1, startAfter: $marker);
            foreach ($page->objects as $object) {
                $keys[] = $object->key;
                $marker = $object->key;
            }
        } while ($page->isTruncated);
        self::assertSame($expected, $keys);
    }

    /** @param list<string> $expected */
    private function assertVersionPages(PostgresMetadataStore $store, string $bucket, array $expected): void
    {
        $keys = [];
        $keyMarker = null;
        $versionMarker = null;
        do {
            $page = $store->listObjectVersions($bucket, maxKeys: 1, keyMarker: $keyMarker, versionIdMarker: $versionMarker);
            foreach ($page['versions'] as $object) {
                $keys[] = $object->key;
            }
            $keyMarker = $page['nextKeyMarker'];
            $versionMarker = $page['nextVersionIdMarker'];
        } while ($page['isTruncated']);
        self::assertSame($expected, $keys);
    }

    /** @param list<string> $expected */
    private function assertMultipartPages(PostgresMetadataStore $store, string $bucket, array $expected): void
    {
        $keys = [];
        $keyMarker = null;
        $uploadMarker = null;
        do {
            $page = $store->listMultipartUploads($bucket, maxUploads: 1, keyMarker: $keyMarker, uploadIdMarker: $uploadMarker);
            array_push($keys, ...array_column($page['uploads'], 'key_name'));
            $keyMarker = $page['nextKeyMarker'];
            $uploadMarker = $page['nextUploadIdMarker'];
        } while ($page['isTruncated']);
        self::assertSame($expected, $keys);
    }

    /** @param list<string> $expected */
    private function assertDelimitedPages(PostgresMetadataStore $store, string $bucket, array $expected): void
    {
        $prefixes = [];
        $token = null;
        do {
            $page = $store->listObjects($bucket, prefix: 'group/', delimiter: '/', maxKeys: 1, continuationToken: $token);
            array_push($prefixes, ...$page->commonPrefixes);
            $token = $page->nextContinuationToken;
        } while ($token !== null);
        self::assertSame($expected, $prefixes);

        $prefixes = [];
        $keyMarker = null;
        $versionMarker = null;
        do {
            $page = $store->listObjectVersions($bucket, prefix: 'group/', delimiter: '/', maxKeys: 1, keyMarker: $keyMarker, versionIdMarker: $versionMarker);
            array_push($prefixes, ...$page['commonPrefixes']);
            $keyMarker = $page['nextKeyMarker'];
            $versionMarker = $page['nextVersionIdMarker'];
        } while ($page['isTruncated']);
        self::assertSame($expected, $prefixes);

        $prefixes = [];
        $keyMarker = null;
        $uploadMarker = null;
        do {
            $page = $store->listMultipartUploads($bucket, prefix: 'group/', delimiter: '/', maxUploads: 1, keyMarker: $keyMarker, uploadIdMarker: $uploadMarker);
            array_push($prefixes, ...$page['commonPrefixes']);
            $keyMarker = $page['nextKeyMarker'];
            $uploadMarker = $page['nextUploadIdMarker'];
        } while ($page['isTruncated']);
        self::assertSame($expected, $prefixes);
    }

    /** @param list<string> $expected */
    private function assertLifecyclePages(PostgresMetadataStore $store, string $bucket, array $expected): void
    {
        $keys = [];
        $marker = null;
        $iterations = 0;
        do {
            self::assertLessThanOrEqual(count($expected) + 1, ++$iterations);
            $page = $store->listExpiredObjects($bucket, null, new \DateTimeImmutable('2100-01-01T00:00:00Z'), limit: 1, afterKey: $marker);
            foreach ($page as $object) {
                $keys[] = $object->key;
                $marker = $object->key;
            }
        } while ($page !== []);
        self::assertSame($expected, $keys);

        $keys = [];
        $keyMarker = null;
        $versionMarker = null;
        $iterations = 0;
        do {
            self::assertLessThanOrEqual(3, ++$iterations);
            $page = $store->listExpiredNoncurrentVersions($bucket, null, 1, limit: 1, afterKey: $keyMarker, afterVersionId: $versionMarker);
            foreach ($page as $object) {
                $keys[] = $object->key;
                $keyMarker = $object->key;
                $versionMarker = $object->versionId ?? 'null';
            }
        } while ($page !== []);
        self::assertSame(['a', 'é'], $keys);

        $keys = [];
        $keyMarker = null;
        $uploadMarker = null;
        $iterations = 0;
        do {
            self::assertLessThanOrEqual(count($expected) + 1, ++$iterations);
            $page = $store->listExpiredMultipartUploads($bucket, 1, limit: 1, afterKey: $keyMarker, afterUploadId: $uploadMarker, createdBefore: new \DateTimeImmutable('2100-01-01T00:00:00Z'));
            foreach ($page as $upload) {
                $keys[] = $upload['key_name'];
                $keyMarker = $upload['key_name'];
                $uploadMarker = $upload['upload_id'];
            }
        } while ($page !== []);
        self::assertSame($expected, $keys);

        $keys = [];
        $keyMarker = null;
        $versionMarker = null;
        $iterations = 0;
        do {
            self::assertLessThanOrEqual(3, ++$iterations);
            $page = $store->listOrphanedDeleteMarkers($bucket, 'orphan/', limit: 1, afterKey: $keyMarker, afterVersionId: $versionMarker);
            foreach ($page as $marker) {
                $keys[] = $marker->key;
                $keyMarker = $marker->key;
                $versionMarker = $marker->versionId;
            }
        } while ($page !== []);
        self::assertSame(['orphan/Z', 'orphan/é'], $keys);
    }

    /** @param list<mixed> $params */
    private static function explainIndex(PostgresConnectionPool $pool, string $query, array $params): string
    {
        $tx = $pool->beginTransaction();
        try {
            $tx->execute('SET LOCAL enable_seqscan = off');
            $plan = '';
            foreach ($tx->execute('EXPLAIN (COSTS OFF) ' . $query, $params) as $row) {
                if (!is_string($row['QUERY PLAN'] ?? null)) {
                    throw new \UnexpectedValueException('Expected an EXPLAIN plan line from PostgreSQL.');
                }
                $plan .= $row['QUERY PLAN'] . "\n";
            }
            return $plan;
        } finally {
            $tx->rollback();
        }
    }

    private static function indexName(string $statement): string
    {
        preg_match('/\bidx_s3_[a-z_]+_listing_c\b/', $statement, $match);
        return $match[0] ?? '';
    }
}
