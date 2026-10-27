<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class NoncurrentTimestampTest extends TestCase
{
    private string $path = '';

    private SqliteMetadataStore $store;

    protected function setUp(): void
    {
        $this->path = sys_get_temp_dir() . '/s3-noncurrent-at-' . bin2hex(random_bytes(6)) . '.sqlite';
        $this->store = new SqliteMetadataStore($this->path);
        $this->store->initialize();
        $this->store->createBucket('owner', 'bucket', 'us-east-1');
        $this->store->setBucketVersioning('bucket', 'Enabled');
    }

    protected function tearDown(): void
    {
        @unlink($this->path);
        @unlink($this->path . '-wal');
        @unlink($this->path . '-shm');
    }

    public function test_noncurrent_days_starts_when_the_version_is_demoted(): void
    {
        $first = $this->store->putObjectVersioned('bucket', 'object', 'owner', 1, 'first', 'text/plain', 'first');
        $pdo = $this->pdo();
        $pdo->prepare('UPDATE s3_objects SET created_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?')
            ->execute(['2000-01-01T00:00:00Z', 'bucket', 'object', $first]);

        $this->store->putObjectVersioned('bucket', 'object', 'owner', 1, 'second', 'text/plain', 'second');

        $noncurrent = $this->store->getObjectMetadataByVersion('bucket', 'object', $first);
        self::assertNotNull($noncurrent);
        self::assertSame('2000-01-01T00:00:00Z', $noncurrent->systemMetadata['createdAt']);
        self::assertArrayHasKey('noncurrentAt', $noncurrent->systemMetadata);
        self::assertGreaterThanOrEqual(
            new \DateTimeImmutable('-5 seconds'),
            new \DateTimeImmutable($noncurrent->systemMetadata['noncurrentAt']),
        );
        self::assertSame([], $this->store->listExpiredNoncurrentVersions('bucket', null, 1));

        $pdo->prepare('UPDATE s3_objects SET noncurrent_at = ? WHERE bucket = ? AND key_name = ? AND version_id = ?')
            ->execute(['2000-01-02T00:00:00Z', 'bucket', 'object', $first]);
        $expired = $this->store->listExpiredNoncurrentVersions('bucket', null, 1);
        self::assertCount(1, $expired);
    }

    public function test_promoting_a_version_clears_its_noncurrent_timestamp(): void
    {
        $first = $this->store->putObjectVersioned('bucket', 'object', 'owner', 1, 'first', 'text/plain', 'first');
        $this->pdo()->prepare('UPDATE s3_objects SET updated_at = ? WHERE version_id = ?')
            ->execute(['2000-01-02T03:04:05Z', $first]);
        $second = $this->store->putObjectVersioned('bucket', 'object', 'owner', 1, 'second', 'text/plain', 'second');
        $noncurrent = $this->store->getObjectMetadataByVersion('bucket', 'object', $first);
        self::assertNotNull($noncurrent);
        self::assertArrayHasKey('noncurrentAt', $noncurrent->systemMetadata);
        self::assertSame('2000-01-02T03:04:05+00:00', $noncurrent->lastModified->format(DATE_ATOM));

        $listed = array_values(array_filter(
            $this->store->listObjectVersions('bucket')['versions'],
            static fn($version): bool => $version->versionId === $first,
        ));
        self::assertCount(1, $listed);
        self::assertArrayHasKey('noncurrentAt', $listed[0]->systemMetadata);
        self::assertSame('2000-01-02T03:04:05+00:00', $listed[0]->lastModified->format(DATE_ATOM));

        $this->store->deleteObjectVersion('bucket', 'object', $second);

        $promoted = $this->store->getObjectMetadataByVersion('bucket', 'object', $first);
        self::assertNotNull($promoted);
        self::assertSame('1', $promoted->systemMetadata['isLatest']);
        self::assertArrayNotHasKey('noncurrentAt', $promoted->systemMetadata);
        self::assertSame('2000-01-02T03:04:05+00:00', $promoted->lastModified->format(DATE_ATOM));
    }

    public function test_suspended_null_write_and_delete_marker_preserve_numbered_last_modified(): void
    {
        $first = $this->store->putObjectVersioned('bucket', 'suspended', 'owner', 1, 'first', 'text/plain', 'first');
        $this->pdo()->prepare('UPDATE s3_objects SET updated_at = ? WHERE version_id = ?')
            ->execute(['2000-01-02T03:04:05Z', $first]);
        $this->store->setBucketVersioning('bucket', 'Suspended');

        $this->store->putObjectMetadata('bucket', 'suspended', 'owner', 1, 'null', 'text/plain', 'null');
        $noncurrent = $this->store->getObjectMetadataByVersion('bucket', 'suspended', $first);
        self::assertNotNull($noncurrent);
        self::assertArrayHasKey('noncurrentAt', $noncurrent->systemMetadata);
        self::assertSame('2000-01-02T03:04:05+00:00', $noncurrent->lastModified->format(DATE_ATOM));

        $this->store->deleteObjectVersion('bucket', 'suspended', 'null');
        $promoted = $this->store->getObjectMetadataByVersion('bucket', 'suspended', $first);
        self::assertNotNull($promoted);
        self::assertSame('1', $promoted->systemMetadata['isLatest']);
        self::assertSame('2000-01-02T03:04:05+00:00', $promoted->lastModified->format(DATE_ATOM));

        self::assertSame('null', $this->store->deleteObjectVersioned('bucket', 'suspended', 'owner', true));
        $demoted = $this->store->getObjectMetadataByVersion('bucket', 'suspended', $first);
        self::assertNotNull($demoted);
        self::assertArrayHasKey('noncurrentAt', $demoted->systemMetadata);
        self::assertSame('2000-01-02T03:04:05+00:00', $demoted->lastModified->format(DATE_ATOM));
        $markers = $this->store->listObjectVersions('bucket')['deleteMarkers'];
        self::assertCount(1, $markers);
        self::assertNull($markers[0]->versionId);
        self::assertSame('1', $markers[0]->systemMetadata['isLatest']);
    }

    public function test_legacy_noncurrent_rows_start_at_migration_time_instead_of_creation_time(): void
    {
        $first = $this->store->putObjectVersioned('bucket', 'legacy', 'owner', 1, 'first', 'text/plain', 'first');
        $this->store->putObjectVersioned('bucket', 'legacy', 'owner', 1, 'second', 'text/plain', 'second');

        $pdo = $this->pdo();
        $pdo->exec('DROP INDEX idx_s3_objects_noncurrent_at');
        $pdo->exec('ALTER TABLE s3_objects DROP COLUMN noncurrent_at');
        $pdo->exec('DELETE FROM s3_schema_version WHERE version >= 36');
        $pdo->exec("DELETE FROM s3_migration_checkpoints WHERE migration_name = 'object_part_layouts'");
        $pdo->prepare('UPDATE s3_objects SET created_at = ? WHERE version_id = ?')
            ->execute(['2000-01-01T00:00:00Z', $first]);
        $migrationStarted = new \DateTimeImmutable('-5 seconds');

        (new SchemaManager($pdo))->migrate();

        $version = $pdo->query('SELECT MAX(version) FROM s3_schema_version');
        self::assertInstanceOf(\PDOStatement::class, $version);
        self::assertSame(SqliteSchema::VERSION, (int) $version->fetchColumn());
        $statement = $pdo->prepare('SELECT created_at, noncurrent_at FROM s3_objects WHERE version_id = ?');
        $statement->execute([$first]);
        $row = $statement->fetch(\PDO::FETCH_ASSOC);
        self::assertIsArray($row);
        self::assertSame('2000-01-01T00:00:00Z', $row['created_at']);
        self::assertGreaterThanOrEqual($migrationStarted, new \DateTimeImmutable((string) $row['noncurrent_at']));
    }

    private function pdo(): \PDO
    {
        $pdo = new \PDO('sqlite:' . $this->path);
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);

        return $pdo;
    }
}
