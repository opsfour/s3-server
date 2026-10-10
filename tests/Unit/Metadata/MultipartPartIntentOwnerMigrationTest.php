<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\Schema\SqliteSchema;
use OpsFour\S3Server\Storage\StoragePath;
use PHPUnit\Framework\TestCase;

final class MultipartPartIntentOwnerMigrationTest extends TestCase
{
    public function test_upgrade_backfills_only_matching_live_upload_and_indexes_owner_admission(): void
    {
        $path = sys_get_temp_dir() . '/s3-part-owner-upgrade-' . bin2hex(random_bytes(6)) . '.sqlite';
        try {
            $pdo = new \PDO('sqlite:' . $path);
            $pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $schema = new SchemaManager($pdo);
            $schema->migrate(23);
            $uploadId = bin2hex(random_bytes(16));
            $generation = bin2hex(random_bytes(16));
            $oldGeneration = bin2hex(random_bytes(16));
            $pdo->prepare('INSERT INTO s3_buckets (name, owner_id, region, generation) VALUES (?, ?, ?, ?)')
                ->execute(['bucket', 'owner-a', 'us-east-1', $generation]);
            $pdo->prepare('INSERT INTO s3_multipart_uploads (upload_id, bucket, bucket_generation, key_name, owner_id, content_type, user_metadata)
                VALUES (?, ?, ?, ?, ?, ?, ?)')
                ->execute([$uploadId, 'bucket', $generation, 'key', 'owner-a', 'application/octet-stream', '{}']);
            $insert = $pdo->prepare("INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at)
                VALUES ('bucket', 'STANDARD', ?, 'part_write_intent', ?, 9999999999)");
            self::assertInstanceOf(\PDOStatement::class, $insert);
            $live = StoragePath::forPlannedPart('memory://', 'bucket', $generation, $uploadId, 1, bin2hex(random_bytes(16)));
            $reused = StoragePath::forPlannedPart('memory://', 'bucket', $oldGeneration, $uploadId, 2, bin2hex(random_bytes(16)));
            $abortedUploadId = bin2hex(random_bytes(16));
            $aborted = StoragePath::forPlannedPart('memory://', 'bucket', $oldGeneration, $abortedUploadId, 1, bin2hex(random_bytes(16)));
            $insert->execute([$live, $uploadId]);
            $insert->execute([$reused, $uploadId]);
            $insert->execute([$aborted, $abortedUploadId]);

            $schema->migrate();
            self::assertSame(SqliteSchema::VERSION, $schema->getCurrentVersion());
            $rows = $pdo->query("SELECT storage_path, owner_id FROM s3_storage_garbage WHERE operation = 'part_write_intent'");
            self::assertInstanceOf(\PDOStatement::class, $rows);
            $owners = [];
            foreach ($rows->fetchAll(\PDO::FETCH_ASSOC) as $row) {
                $owners[$row['storage_path']] = $row['owner_id'];
            }
            self::assertSame('owner-a', $owners[$live]);
            self::assertNull($owners[$reused]);
            self::assertNull($owners[$aborted]);

            $pdo->exec("WITH RECURSIVE seq(n) AS (SELECT 1 UNION ALL SELECT n + 1 FROM seq WHERE n < 10000)
                INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, next_attempt_at)
                SELECT 'bucket', 'STANDARD', 'ordinary-' || n, 9999999999 FROM seq");
            $explain = $pdo->query("EXPLAIN QUERY PLAN SELECT COUNT(*) FROM s3_storage_garbage
                WHERE operation = 'part_write_intent' AND owner_id = 'owner-a'");
            self::assertInstanceOf(\PDOStatement::class, $explain);
            $plan = implode(' ', array_column($explain->fetchAll(\PDO::FETCH_ASSOC), 'detail'));
            self::assertStringContainsString('idx_s3_storage_gc_operation_owner', $plan);
        } finally {
            @unlink($path);
            @unlink($path . '-wal');
            @unlink($path . '-shm');
        }
    }
}
