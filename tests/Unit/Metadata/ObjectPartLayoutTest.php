<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\ObjectPart;
use OpsFour\S3Server\Metadata\ObjectPartLayout;
use OpsFour\S3Server\Metadata\MultipartWriteIntent;
use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Metadata\SqliteMetadataStore;
use PHPUnit\Framework\TestCase;

final class ObjectPartLayoutTest extends TestCase
{
    public function test_layout_validation_requires_contiguous_plaintext_coverage(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 10, [
            new ObjectPart(1, 8, 0, 4),
            new ObjectPart(2, 9, 5, 5),
        ]);
    }

    public function test_multipart_layout_requires_unique_but_not_monotonic_upload_part_numbers(): void
    {
        $reordered = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 10, [
            new ObjectPart(1, 8, 0, 4),
            new ObjectPart(2, 3, 4, 6),
        ]);
        self::assertSame([8, 3], array_map(static fn(ObjectPart $part): ?int => $part->uploadPartNumber, $reordered->parts));

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Known multipart layouts require unique upload part numbers.');
        new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 10, [
            new ObjectPart(1, 8, 0, 4),
            new ObjectPart(2, 8, 4, 6),
        ]);
    }

    public function test_legacy_classifier_never_infers_multipart_boundaries(): void
    {
        self::assertSame(ObjectPartLayout::KNOWN_SINGLE, ObjectPartLayout::legacy('"d41d8cd98f00b204e9800998ecf8427e"', 0)->kind);
        $multipart = ObjectPartLayout::legacy('D41D8CD98F00B204E9800998ECF8427E-7', 123);
        self::assertSame(ObjectPartLayout::UNKNOWN_MULTIPART, $multipart->kind);
        self::assertSame(7, $multipart->compatibilityPartCount);
        self::assertSame([], $multipart->parts);
        self::assertSame(ObjectPartLayout::UNKNOWN, ObjectPartLayout::legacy('opaque-7', 123)->kind);
        self::assertSame(ObjectPartLayout::UNKNOWN, ObjectPartLayout::legacy('d41d8cd98f00b204e9800998ecf8427e-01', 123)->kind);
        self::assertSame(ObjectPartLayout::UNKNOWN, ObjectPartLayout::legacy('d41d8cd98f00b204e9800998ecf8427e-10001', 123)->kind);
        self::assertSame(ObjectPartLayout::UNKNOWN, ObjectPartLayout::legacy('"d41d8cd98f00b204e9800998ecf8427e', 123)->kind);
    }

    public function test_multipart_intent_digest_covers_verified_checksum_fields(): void
    {
        $part = [[
            'partNumber' => 1,
            'etag' => 'etag',
            'size' => 3,
            'storagePath' => 'part/1',
            'checksumAlgorithm' => 'SHA256',
            'checksumValue' => 'first',
        ]];
        $changed = $part;
        $changed[0]['checksumValue'] = 'second';

        self::assertNotSame(MultipartWriteIntent::digestParts($part), MultipartWriteIntent::digestParts($changed));
    }

    public function test_layout_accepts_existing_lowercase_checksum_metadata(): void
    {
        $part = new ObjectPart(1, 1, 0, 4, 'sha256', 'digest');

        self::assertSame('sha256', $part->checksumAlgorithm);
    }

    public function test_layout_summary_digest_is_bound_to_the_object_incarnation(): void
    {
        $layout = ObjectPartLayout::knownSingle(4);

        self::assertNotSame($layout->summary(1)->digest, $layout->summary(2)->digest);
        self::assertSame($layout->digestForObject(1), $layout->summary(1)->digest);
    }

    public function test_sqlite_publishes_and_reads_multipart_layout_atomically(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $layout = new ObjectPartLayout(ObjectPartLayout::KNOWN_MULTIPART, 9, [
            new ObjectPart(1, 8, 0, 4, 'SHA256', 'first'),
            new ObjectPart(2, 3, 4, 5),
        ]);

        $store->transaction(function () use ($store, $layout): void {
            $store->putObjectMetadata('bucket', 'key', 'owner', 9, '"etag-2"', 'text/plain', 'objects/key');
            $store->putObjectPartLayout('bucket', 'key', 'null', $layout);
        });

        $object = $store->getObjectMetadata('bucket', 'key');
        self::assertNotNull($object);
        self::assertSame(ObjectPartLayout::KNOWN_MULTIPART, $object->partLayout?->kind);
        $persisted = $store->getObjectPartLayout($object);
        self::assertSame(8, $persisted->parts[0]->uploadPartNumber);
        self::assertSame('SHA256', $persisted->parts[0]->checksumAlgorithm);
        self::assertSame(4, $persisted->parts[1]->offset);
    }

    public function test_zero_byte_ordinary_object_has_one_exact_known_part(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->putObjectMetadata('bucket', 'empty', 'owner', 0, '"d41d8cd98f00b204e9800998ecf8427e"', 'application/octet-stream', 'objects/empty');

        $object = $store->getObjectMetadata('bucket', 'empty');
        self::assertNotNull($object);
        self::assertSame(ObjectPartLayout::KNOWN_SINGLE, $object->partLayout?->kind);
        $layout = $store->getObjectPartLayout($object);
        self::assertCount(1, $layout->parts);
        self::assertSame(0, $layout->parts[0]->offset);
        self::assertSame(0, $layout->parts[0]->size);
        self::assertNull($layout->parts[0]->uploadPartNumber);
    }

    public function test_committed_layout_cannot_be_rewritten_in_a_later_transaction(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->putObjectMetadata('bucket', 'key', 'owner', 4, '"etag"', 'text/plain', 'objects/key');

        $this->expectException(\LogicException::class);
        $this->expectExceptionMessage('Published object part layouts are immutable.');
        $store->transaction(fn() => $store->putObjectPartLayout('bucket', 'key', 'null', ObjectPartLayout::knownSingle(4)));
    }

    public function test_full_layout_read_rejects_child_digest_tampering(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-layout-child-tamper-');
        self::assertIsString($path);
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            $store->putObjectMetadata('bucket', 'key', 'owner', 4, '"etag"', 'text/plain', 'objects/key');
            $object = $store->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);

            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec("UPDATE s3_object_parts SET checksum_algorithm = 'sha256', checksum_value = 'tampered'");

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Stored object part layout digest is inconsistent.');
            $store->getObjectPartLayout($object);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_full_layout_read_rejects_parent_count_tampering(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-layout-count-tamper-');
        self::assertIsString($path);
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            $store->putObjectMetadata('bucket', 'key', 'owner', 4, '"etag"', 'text/plain', 'objects/key');
            $object = $store->getObjectMetadata('bucket', 'key');
            self::assertNotNull($object);

            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec('UPDATE s3_objects SET part_layout_count = 2');

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Stored object part layout count is inconsistent.');
            $store->getObjectPartLayout($object);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_replacing_literal_null_object_invalidates_old_layout_snapshot(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $publish = static function () use ($store): void {
            $store->transaction(function () use ($store): void {
                $store->putObjectMetadata('bucket', 'key', 'owner', 0, '"d41d8cd98f00b204e9800998ecf8427e"', 'text/plain', 'objects/key');
                $store->putObjectPartLayout('bucket', 'key', 'null', ObjectPartLayout::knownSingle(0));
            });
        };

        $publish();
        $old = $store->getObjectMetadata('bucket', 'key');
        self::assertNotNull($old);
        $publish();
        $current = $store->getObjectMetadata('bucket', 'key');
        self::assertNotNull($current);
        self::assertNotSame($old->partLayout?->digest, $current->partLayout?->digest);

        $this->expectException(\RuntimeException::class);
        $store->getObjectPartLayout($old);
    }

    public function test_delete_marker_has_no_layout(): void
    {
        $store = new SqliteMetadataStore(':memory:');
        $store->initialize();
        $store->createBucket('owner', 'bucket', 'us-east-1');
        $store->putObjectMetadata('bucket', 'key', 'owner', 1, '"etag"', 'text/plain', 'objects/key');
        $store->deleteObjectVersioned('bucket', 'key', 'owner', true);

        $marker = $store->getCurrentObjectVersion('bucket', 'key');
        self::assertNotNull($marker);
        self::assertTrue($marker->isDeleteMarker);
        self::assertNull($marker->partLayout);

        $store->setBucketVersioning('bucket', 'Enabled');
        $versionId = $store->deleteObjectVersioned('bucket', 'numbered', 'owner');
        $numberedMarker = $store->getObjectMetadataByVersion('bucket', 'numbered', $versionId);
        self::assertNotNull($numberedMarker);
        self::assertTrue($numberedMarker->isDeleteMarker);
        self::assertNull($numberedMarker->partLayout);
    }

    public function test_delete_marker_with_layout_summary_is_corruption(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-layout-marker-corrupt-');
        self::assertIsString($path);
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            $store->setBucketVersioning('bucket', 'Enabled');
            $versionId = $store->deleteObjectVersioned('bucket', 'key', 'owner');

            $pdo = new \PDO('sqlite:' . $path);
            $statement = $pdo->prepare("UPDATE s3_objects SET part_layout_kind = 'unknown', part_layout_count = 0, part_layout_digest = ? WHERE bucket = 'bucket' AND key_name = 'key' AND version_id = ?");
            self::assertInstanceOf(\PDOStatement::class, $statement);
            $statement->execute([str_repeat('a', 64), $versionId]);

            try {
                $store->getObjectMetadataByVersion('bucket', 'key', $versionId);
                self::fail('A delete marker carrying an object part layout must be rejected as corruption.');
            } catch (\RuntimeException $error) {
                self::assertSame('Current metadata schema contains a delete marker with an object part layout.', $error->getMessage());
            }
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }

    public function test_missing_layout_on_current_schema_is_corruption(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-layout-corrupt-');
        self::assertIsString($path);
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            $store->putObjectMetadata('bucket', 'key', 'owner', 1, '"etag"', 'text/plain', 'objects/key');
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->exec("UPDATE s3_objects SET part_layout_kind = NULL, part_layout_count = NULL, part_layout_digest = NULL WHERE bucket = 'bucket' AND key_name = 'key'");

            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('Current metadata schema contains an object without a part layout.');
            $store->getObjectMetadata('bucket', 'key');
        } finally {
            @unlink($path);
        }
    }

    public function test_nullable_required_layout_summary_fields_are_corruption(): void
    {
        foreach (['part_layout_count', 'part_layout_digest'] as $column) {
            $path = tempnam(sys_get_temp_dir(), 's3-layout-null-summary-');
            self::assertIsString($path);
            try {
                $store = new SqliteMetadataStore($path);
                $store->initialize();
                $store->createBucket('owner', 'bucket', 'us-east-1');
                $store->putObjectMetadata('bucket', 'key', 'owner', 4, 'opaque-etag', 'text/plain', 'objects/key');
                $pdo = new \PDO('sqlite:' . $path);
                $pdo->exec("UPDATE s3_objects SET {$column} = NULL WHERE bucket = 'bucket' AND key_name = 'key'");

                try {
                    $store->getObjectMetadata('bucket', 'key');
                    self::fail("A NULL {$column} must be rejected as metadata corruption.");
                } catch (\RuntimeException $error) {
                    self::assertSame('Current metadata schema contains an incomplete object part layout summary.', $error->getMessage());
                }
            } finally {
                @unlink($path);
                @unlink($path . '-wal');
                @unlink($path . '-shm');
            }
        }
    }

    public function test_sqlite_migration_resumes_after_durable_keyset_checkpoint(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = new SchemaManager($pdo);
        $schema->migrate(40);
        $pdo->exec("INSERT INTO s3_objects (id, bucket, key_name, version_id, owner_id, etag, size, storage_path) VALUES (10001, 'bucket', 'simple', 'null', 'owner', 'd41d8cd98f00b204e9800998ecf8427e', 9, 'legacy/simple')");
        $pdo->exec("INSERT INTO s3_objects (id, bucket, key_name, version_id, owner_id, etag, size, storage_path) VALUES (10002, 'bucket', 'multipart', 'null', 'owner', 'd41d8cd98f00b204e9800998ecf8427e-7', 9, 'legacy/multipart')");
        $pdo->exec("INSERT INTO s3_objects (id, bucket, key_name, version_id, owner_id, etag, size, storage_path) VALUES (10003, 'bucket', 'opaque', 'null', 'owner', 'opaque-value', 9, 'legacy/opaque')");
        $pdo->exec("INSERT INTO s3_objects (id, bucket, key_name, version_id, is_delete_marker, owner_id, etag, size, storage_path) VALUES (10004, 'bucket', 'marker', 'marker-version', 1, 'owner', '', 0, '')");
        $pdo->exec('CREATE TABLE IF NOT EXISTS s3_migration_checkpoints (migration_name TEXT PRIMARY KEY, last_object_id INTEGER NOT NULL, target_object_id INTEGER)');
        $pdo->exec("INSERT INTO s3_migration_checkpoints (migration_name, last_object_id, target_object_id) VALUES ('object_part_layouts', 10000, 10004)");

        $schema->migrate();

        self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
        $query = $pdo->query('SELECT id, part_layout_kind, part_layout_count, part_layout_compat_count FROM s3_objects WHERE id >= 10001 ORDER BY id');
        self::assertInstanceOf(\PDOStatement::class, $query);
        $rows = $query->fetchAll(\PDO::FETCH_ASSOC);
        self::assertSame(ObjectPartLayout::KNOWN_SINGLE, $rows[0]['part_layout_kind']);
        self::assertSame(1, (int) $rows[0]['part_layout_count']);
        self::assertSame(ObjectPartLayout::UNKNOWN_MULTIPART, $rows[1]['part_layout_kind']);
        self::assertSame(7, (int) $rows[1]['part_layout_compat_count']);
        self::assertSame(ObjectPartLayout::UNKNOWN, $rows[2]['part_layout_kind']);
        self::assertSame(0, (int) $rows[2]['part_layout_count']);
        self::assertNull($rows[2]['part_layout_compat_count']);
        self::assertNull($rows[3]['part_layout_kind']);
        self::assertNull($rows[3]['part_layout_count']);
        $knownParts = $pdo->query('SELECT COUNT(*) FROM s3_object_parts WHERE object_id = 10001');
        self::assertInstanceOf(\PDOStatement::class, $knownParts);
        self::assertSame(1, (int) $knownParts->fetchColumn());
        $unknownParts = $pdo->query('SELECT COUNT(*) FROM s3_object_parts WHERE object_id IN (10002, 10003)');
        self::assertInstanceOf(\PDOStatement::class, $unknownParts);
        self::assertSame(0, (int) $unknownParts->fetchColumn());
    }

    public function test_sqlite_migration_rejects_rows_beyond_fixed_target_without_publishing_version(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = new SchemaManager($pdo);
        $schema->migrate(40);
        $pdo->exec("INSERT INTO s3_objects (id, bucket, key_name, version_id, owner_id, etag, size, storage_path) VALUES (10001, 'bucket', 'late', 'null', 'owner', 'opaque-value', 9, 'legacy/late')");
        $pdo->exec('CREATE TABLE IF NOT EXISTS s3_migration_checkpoints (migration_name TEXT PRIMARY KEY, last_object_id INTEGER NOT NULL, target_object_id INTEGER)');
        $pdo->exec("INSERT INTO s3_migration_checkpoints (migration_name, last_object_id, target_object_id) VALUES ('object_part_layouts', 10000, 10000)");

        try {
            $schema->migrate();
            self::fail('A row beyond the fixed migration target must prevent schema publication.');
        } catch (\RuntimeException $error) {
            self::assertSame('Object rows were created beyond the fixed object-layout migration target.', $error->getMessage());
        }
        self::assertSame(40, $schema->getCurrentVersion());
    }

    public function test_sqlite_migration_rejects_checkpoint_beyond_fixed_target_without_publishing_version(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = new SchemaManager($pdo);
        $schema->migrate(40);
        $pdo->exec('CREATE TABLE IF NOT EXISTS s3_migration_checkpoints (migration_name TEXT PRIMARY KEY, last_object_id INTEGER NOT NULL, target_object_id INTEGER)');
        $pdo->exec("INSERT INTO s3_migration_checkpoints (migration_name, last_object_id, target_object_id) VALUES ('object_part_layouts', 2, 1)");

        try {
            $schema->migrate();
            self::fail('A checkpoint beyond its fixed target must prevent schema publication.');
        } catch (\RuntimeException $error) {
            self::assertSame('Object part layout migration checkpoint exceeds its fixed target.', $error->getMessage());
        }
        self::assertSame(40, $schema->getCurrentVersion());
    }

    public function test_sqlite_layout_migration_rejects_versioned_schema_without_objects_table(): void
    {
        $pdo = new \PDO('sqlite::memory:');
        $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        $schema = new SchemaManager($pdo);
        $schema->migrate(40);
        $pdo->exec('PRAGMA foreign_keys = OFF');
        $pdo->exec('DROP TABLE s3_objects');

        try {
            $schema->migrate();
            self::fail('A versioned schema without s3_objects must be rejected.');
        } catch (\RuntimeException $error) {
            self::assertSame('SQLite metadata schema is corrupted: s3_objects is missing before object part layout migration.', $error->getMessage());
        }
        self::assertSame(40, $schema->getCurrentVersion());
    }

    public function test_layout_publication_rolls_back_with_parent_and_deletion_cascades_children(): void
    {
        $path = tempnam(sys_get_temp_dir(), 's3-layout-atomic-');
        self::assertIsString($path);
        try {
            $store = new SqliteMetadataStore($path);
            $store->initialize();
            $store->createBucket('owner', 'bucket', 'us-east-1');
            try {
                $store->transaction(function () use ($store): void {
                    $store->putObjectMetadata('bucket', 'rolled-back', 'owner', 1, '"etag"', 'text/plain', 'objects/rollback');
                    $store->putObjectPartLayout('bucket', 'rolled-back', 'null', ObjectPartLayout::knownSingle(1));
                    throw new \RuntimeException('rollback');
                });
            } catch (\RuntimeException $error) {
                self::assertSame('rollback', $error->getMessage());
            }
            self::assertNull($store->getObjectMetadata('bucket', 'rolled-back'));

            $versionId = $store->putObjectVersioned('bucket', 'deleted', 'owner', 3, '"etag"', 'text/plain', 'objects/deleted');
            $object = $store->getObjectMetadataByVersion('bucket', 'deleted', $versionId);
            self::assertNotNull($object);
            self::assertCount(1, $store->getObjectPartLayout($object)->parts);
            $pdo = new \PDO('sqlite:' . $path);
            $objectQuery = $pdo->query("SELECT id FROM s3_objects WHERE bucket = 'bucket' AND key_name = 'deleted'");
            self::assertInstanceOf(\PDOStatement::class, $objectQuery);
            $objectId = $objectQuery->fetchColumn();
            self::assertIsInt($objectId);
            $objectQuery->closeCursor();
            self::assertNotNull($store->deleteObjectVersion('bucket', 'deleted', $versionId));
            self::assertNull($store->getObjectMetadataByVersion('bucket', 'deleted', $versionId));
            $childrenQuery = $pdo->query('SELECT COUNT(*) FROM s3_object_parts WHERE object_id = ' . $objectId);
            self::assertInstanceOf(\PDOStatement::class, $childrenQuery);
            $children = $childrenQuery->fetchColumn();
            self::assertSame(0, $children);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
