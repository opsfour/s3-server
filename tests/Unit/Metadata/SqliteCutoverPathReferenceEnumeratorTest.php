<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Metadata;

use OpsFour\S3Server\Metadata\Schema\SchemaManager;
use OpsFour\S3Server\Metadata\SqliteCutoverPathReferenceEnumerator as Inventory;
use PHPUnit\Framework\TestCase;

final class SqliteCutoverPathReferenceEnumeratorTest extends TestCase
{
    private string $databasePath;

    private \PDO $writer;

    protected function setUp(): void
    {
        $this->databasePath = sys_get_temp_dir() . '/s3-cutover-inventory-' . bin2hex(random_bytes(8)) . '.sqlite';
        $this->writer = new \PDO('sqlite:' . $this->databasePath);
        $this->writer->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
        (new SchemaManager($this->writer))->migrate();
    }

    protected function tearDown(): void
    {
        unset($this->writer);
        @unlink($this->databasePath);
        @unlink($this->databasePath . '-wal');
        @unlink($this->databasePath . '-shm');
    }

    public function test_all_seven_reference_sources_are_keyset_paged_and_redacted(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_objects (bucket, key_name, version_id, owner_id, etag, size, storage_path, user_metadata)
            VALUES ('bucket', 'secret-key', 'v1', 'owner', 'etag', 4, '/storage/object-1', '{"__sse-algorithm":"AES256"}'),
                   ('bucket', 'secret-key', 'v2', 'owner', 'etag', 4, '/storage/object-2', '{"__sse-algorithm":"SSE-C"}'),
                   ('bucket', 'deleted-key', 'v3', 'owner', 'etag', 0, '', '{}'),
                   ('bucket', 'wrong-key', 'v4', 'owner', 'etag', 4, '/storage/old-key', '{"sse-algorithm":"AES256"}')
            SQL);
        $this->writer->exec("UPDATE s3_objects SET is_delete_marker = 1 WHERE version_id = 'v3'");
        $this->writer->exec("UPDATE s3_objects SET restored_storage_path = '/storage/restored-1', restored_storage_tier = 'COLD', restore_status = 'restored' WHERE version_id = 'v1'");
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, storage_tier, user_metadata)
            VALUES ('upload-1', 'bucket', 'secret-multipart-key', 'owner', 'STANDARD', '{"__sse-algorithm":"AES256"}'),
                   ('upload-2', 'bucket', 'other-key', 'owner', NULL, '{"__sse-algorithm":"SSE-C","__mpu-part-format":"encrypted-v2"}')
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path)
            VALUES ('upload-1', 1, 'etag', 4, '/storage/old-plain-part'),
                   ('upload-2', 1, 'etag', 4, '/storage/new-cipher-part')
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, status, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '/storage/queued-part', 'delete_path', 'pending', 0),
                   ('bucket', 'STANDARD', '', 'abort_multipart', 'processing', 0)
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until)
            VALUES ('b', 'bucket', 'generation', 'owner', 'STANDARD', '/storage/object-intent-b', 'digest-b', 'put', 0),
                   ('a', 'bucket', 'generation', 'owner', 'STANDARD', '/storage/object-intent-a', 'digest-a', 'copy', 0)
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, sse_mode, lease_until, next_attempt_at)
            VALUES ('b', 'upload-1', 'bucket', 'generation', 'owner', 'STANDARD',
                    '/storage/assembly-b', '/storage/final-b', 'digest-b', 'AES256', 0, 0),
                   ('a', 'upload-2', 'bucket', 'generation', 'owner', 'STANDARD',
                    '/storage/assembly-a', '/storage/final-a', 'digest-a', 'SSE-C', 0, 0)
            SQL);

        $inventory = new Inventory($this->databasePath);
        $objects = $this->all($inventory, Inventory::OBJECT);
        self::assertSame(['1', '2', '4'], array_column($objects, 'id'));
        self::assertSame(['AES256', 'SSE-C', null], array_column($objects, 'sse_mode'));
        self::assertSame(hash('sha256', '/storage/object-1'), $objects[0]['path_sha256']);
        self::assertSame(['1'], array_column($this->all($inventory, Inventory::RESTORED_OBJECT), 'id'));
        self::assertSame('COLD', $this->all($inventory, Inventory::RESTORED_OBJECT)[0]['storage_tier']);

        $parts = $this->all($inventory, Inventory::PART);
        self::assertSame(['1', '2'], array_column($parts, 'id'));
        self::assertNull($parts[0]['part_format']);
        self::assertSame('encrypted-v2', $parts[1]['part_format']);
        self::assertNull($parts[1]['storage_tier']);

        $garbage = $this->all($inventory, Inventory::GARBAGE);
        self::assertSame(['delete_path', 'abort_multipart'], array_column($garbage, 'operation'));
        self::assertNull($garbage[1]['path_sha256']);

        self::assertSame(['a', 'b'], array_column($this->all($inventory, Inventory::OBJECT_INTENT), 'id'));
        $assemblies = $this->all($inventory, Inventory::MULTIPART_ASSEMBLY_INTENT);
        $finals = $this->all($inventory, Inventory::MULTIPART_FINAL_INTENT);
        self::assertSame(['a', 'b'], array_column($assemblies, 'id'));
        self::assertSame(hash('sha256', '/storage/assembly-a'), $assemblies[0]['path_sha256']);
        self::assertSame(hash('sha256', '/storage/final-a'), $finals[0]['path_sha256']);
        self::assertSame('SSE-C', $finals[0]['sse_mode']);

        $encoded = json_encode([$objects, $parts, $garbage, $assemblies, $finals], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-key', $encoded);
        self::assertStringNotContainsString('/storage/', $encoded);
    }

    public function test_cursor_is_strictly_greater_and_unsupported_inputs_fail_before_query(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '/storage/first', 0),
                   ('bucket', 'STANDARD', '/storage/second', 0)
            SQL);
        $inventory = new Inventory($this->databasePath);
        $first = $inventory->page(Inventory::GARBAGE, limit: 1);
        self::assertSame(['1'], array_column($first, 'id'));
        $this->writer->exec("INSERT INTO s3_storage_garbage (id, bucket, storage_tier, storage_path, next_attempt_at) VALUES (0, 'bucket', 'STANDARD', '/storage/earlier', 0)");
        self::assertSame(['2'], array_column($inventory->page(Inventory::GARBAGE, $first[0]['id'], 1), 'id'));
        self::assertSame([], $inventory->page(Inventory::GARBAGE, '2', 1));

        foreach ([
            ['unknown', null, 1],
            [Inventory::GARBAGE, null, 0],
            [Inventory::GARBAGE, null, 501],
            [Inventory::GARBAGE, 'not-an-id', 1],
            [Inventory::TIER_TRANSITION_SOURCE, '9223372036854775808', 1],
            [Inventory::RESTORE_TARGET, 'not-an-id', 1],
            [Inventory::OBJECT_INTENT, "a\0b", 1],
            [Inventory::MULTIPART_UPLOAD_NO_PARTS, "a\0b", 1],
        ] as [$source, $cursor, $limit]) {
            try {
                $inventory->page($source, $cursor, $limit);
                self::fail('Invalid inventory page input was accepted.');
            } catch (\InvalidArgumentException) {
                // Expected before SQL access.
            }
        }
    }

    public function test_object_and_restored_object_require_the_internal_sse_key(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_objects
                (bucket, key_name, version_id, owner_id, etag, size, storage_path,
                 restored_storage_path, restored_storage_tier, user_metadata)
            VALUES ('bucket', 'old-key-only', 'v1', 'owner', 'etag', 4, '/storage/one',
                    '/storage/restore-one', 'COLD', '{"sse-algorithm":"AES256"}'),
                   ('bucket', 'internal-key', 'v2', 'owner', 'etag', 4, '/storage/two',
                    '/storage/restore-two', 'COLD', '{"__sse-algorithm":"SSE-C","sse-algorithm":"AES256"}')
            SQL);
        $inventory = new Inventory($this->databasePath);
        foreach ([Inventory::OBJECT, Inventory::RESTORED_OBJECT] as $source) {
            $rows = $inventory->page($source, limit: 2);
            self::assertCount(2, $rows);
            self::assertNull($rows[0]['sse_mode']);
            self::assertSame('SSE-C', $rows[1]['sse_mode']);
        }
    }

    public function test_invalid_object_path_roles_are_visible_without_valid_empty_delete_markers(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_objects
                (bucket, key_name, version_id, is_delete_marker, owner_id, etag, size, storage_path)
            VALUES ('bucket', 'missing-path-key', 'm', 0, 'owner', 'etag', 4, ''),
                   ('bucket', 'valid-delete-marker-key', 'v', 1, 'owner', '', 0, ''),
                   ('bucket', 'bad-delete-marker-key', 'b', 1, 'owner', '', 0, '/storage/marker-debris')
            SQL);
        $this->writer->exec("UPDATE s3_objects SET restored_storage_path = '', restored_storage_tier = 'STANDARD', restore_status = 'restored' WHERE version_id = 'm'");

        $inventory = new Inventory($this->databasePath);
        $objects = $this->all($inventory, Inventory::OBJECT);
        self::assertSame(['1', '3'], array_column($objects, 'id'));
        self::assertSame(['missing_object_path', 'delete_marker_with_path'], array_column($objects, 'state'));
        self::assertNull($objects[0]['path_sha256']);
        self::assertSame('empty_object_path', $objects[0]['blocking_anomaly']);
        self::assertSame(hash('sha256', '/storage/marker-debris'), $objects[1]['path_sha256']);
        self::assertNull($objects[1]['blocking_anomaly']);
        self::assertNull($objects[0]['relative_path_sha256']);
        $restored = $this->all($inventory, Inventory::RESTORED_OBJECT);
        self::assertSame(['1'], array_column($restored, 'id'));
        self::assertNull($restored[0]['path_sha256']);
        self::assertSame('restored', $restored[0]['state']);
        self::assertSame('empty_restored_object_path', $restored[0]['blocking_anomaly']);
        self::assertStringNotContainsString('bad-delete-marker-key', json_encode($objects, JSON_THROW_ON_ERROR));
    }

    public function test_legacy_abort_directory_digest_is_relative_and_invalid_ids_remain_uncorrelated(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '', 'abort_multipart', 'Legacy.1', 0),
                   ('bucket', 'STANDARD', '', 'abort_multipart', '../escape', 0),
                   ('bucket', 'STANDARD', '', 'abort_multipart', NULL, 0),
                   ('bucket', 'STANDARD', '/storage/exact-file', 'delete_path', 'other-id', 0),
                   ('bucket', 'STANDARD', '/storage/ignored-path', 'abort_multipart', 'Valid.1', 0)
            SQL);

        $inventory = new Inventory($this->databasePath);
        $garbage = $this->all($inventory, Inventory::GARBAGE);
        self::assertSame(hash('sha256', '.parts/Legacy.1'), $garbage[0]['relative_path_sha256']);
        self::assertNotSame(hash('sha256', '/storage/.parts/Legacy.1'), $garbage[0]['relative_path_sha256']);
        self::assertNull($garbage[0]['path_sha256']);
        self::assertNull($garbage[0]['blocking_anomaly']);
        self::assertNull($garbage[1]['relative_path_sha256']);
        self::assertSame('uncorrelatable_abort_multipart_scope', $garbage[1]['blocking_anomaly']);
        self::assertNull($garbage[2]['relative_path_sha256']);
        self::assertSame('uncorrelatable_abort_multipart_scope', $garbage[2]['blocking_anomaly']);
        self::assertNull($garbage[3]['relative_path_sha256']);
        self::assertSame(hash('sha256', '/storage/exact-file'), $garbage[3]['path_sha256']);
        self::assertSame(hash('sha256', '.parts/Valid.1'), $garbage[4]['relative_path_sha256']);
        self::assertSame('unexpected_abort_multipart_path', $garbage[4]['blocking_anomaly']);
        $encoded = json_encode($garbage, JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('Legacy.1', $encoded);
        self::assertStringNotContainsString('../escape', $encoded);
    }

    public function test_missing_database_is_not_created(): void
    {
        $missing = $this->databasePath . '.missing';
        $this->expectException(\InvalidArgumentException::class);
        try {
            new Inventory($missing);
        } finally {
            self::assertFileDoesNotExist($missing);
        }
    }

    public function test_first_page_includes_zero_id_and_orphan_part_reference(): void
    {
        $this->writer->exec("INSERT INTO s3_storage_garbage (id, bucket, storage_tier, storage_path, next_attempt_at) VALUES (0, 'bucket', 'STANDARD', '/storage/zero', 0)");
        // Simulate historical corruption; inventory must not hide its path.
        $this->writer->exec('PRAGMA foreign_keys=OFF');
        $this->writer->exec("INSERT INTO s3_parts (id, upload_id, part_number, etag, size, storage_path) VALUES (0, 'missing-upload', 1, 'etag', 4, '/storage/orphan')");
        $this->writer->exec('PRAGMA foreign_keys=ON');

        $inventory = new Inventory($this->databasePath);
        $garbage = $inventory->page(Inventory::GARBAGE, limit: 1);
        $parts = $inventory->page(Inventory::PART, limit: 1);
        self::assertSame('0', $garbage[0]['id']);
        self::assertSame('0', $parts[0]['id']);
        self::assertNull($parts[0]['storage_tier']);
        self::assertNull($parts[0]['sse_mode']);
        self::assertSame(hash('sha256', '/storage/orphan'), $parts[0]['path_sha256']);
    }

    public function test_pathless_uploads_are_bytewise_paged_and_uploads_with_parts_are_excluded(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_multipart_uploads
                (upload_id, bucket, bucket_generation, key_name, owner_id, storage_tier, user_metadata)
            VALUES ('', 'bucket', '', 'secret-empty', 'owner', NULL,
                    '{"__sse-algorithm":"SSE-C","__mpu-part-format":"plaintext-v1"}'),
                   ('A', 'bucket', '', 'secret-upper', 'owner', 'STANDARD',
                    '{"sse-algorithm":"AES256"}'),
                   ('a', 'bucket', 'generation', 'secret-lower', 'owner', 'COLD', '{}'),
                   ('z', 'bucket', 'generation', 'secret-with-part', 'owner', 'STANDARD', '{}')
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path)
            VALUES ('z', 1, 'etag', 4, '/storage/part')
            SQL);

        $inventory = new Inventory($this->databasePath);
        $rows = $this->all($inventory, Inventory::MULTIPART_UPLOAD_NO_PARTS);
        self::assertSame(['', 'A', 'a'], array_column($rows, 'id'));
        self::assertSame([null, 'STANDARD', 'COLD'], array_column($rows, 'storage_tier'));
        self::assertSame(['SSE-C', null, null], array_column($rows, 'sse_mode'));
        self::assertSame('plaintext-v1', $rows[0]['part_format']);
        self::assertSame([null, null, null], array_column($rows, 'path_sha256'));
        self::assertNull($rows[0]['relative_path_sha256']);
        self::assertSame('uncorrelatable_active_upload_scope', $rows[0]['blocking_anomaly']);
        self::assertSame(hash('sha256', '.parts/A'), $rows[1]['relative_path_sha256']);
        self::assertNull($rows[1]['blocking_anomaly']);
        self::assertSame(hash('sha256', '.parts/a'), $rows[2]['relative_path_sha256']);
        self::assertNotSame($rows[1]['relative_path_sha256'], $rows[2]['relative_path_sha256']);
        self::assertSame(['active_no_parts', 'active_no_parts', 'active_no_parts'], array_column($rows, 'state'));
        self::assertSame([], $inventory->page(Inventory::MULTIPART_UPLOAD_NO_PARTS, 'a', 1));
        self::assertStringNotContainsString('secret-', json_encode($rows, JSON_THROW_ON_ERROR));
    }

    public function test_pathless_upload_with_invalid_path_segment_remains_visible_but_uncorrelated(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, storage_tier)
            VALUES ('../escape', 'bucket', 'key', 'owner', 'STANDARD')
            SQL);

        $rows = $this->all(new Inventory($this->databasePath), Inventory::MULTIPART_UPLOAD_NO_PARTS);
        self::assertCount(1, $rows);
        self::assertSame('../escape', $rows[0]['id']);
        self::assertNull($rows[0]['path_sha256']);
        self::assertNull($rows[0]['relative_path_sha256']);
        self::assertSame('uncorrelatable_active_upload_scope', $rows[0]['blocking_anomaly']);
    }

    public function test_empty_part_garbage_and_intent_paths_are_explicit_blockers(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, storage_tier)
            VALUES ('upload-one', 'bucket', 'key', 'owner', 'STANDARD')
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path)
            VALUES ('upload-one', 1, 'etag', 4, '')
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '', 'delete_path', 0)
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_object_write_intents
                (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path,
                 path_digest, purpose, lease_until)
            VALUES ('attempt-one', 'bucket', 'generation', 'owner', 'STANDARD', '',
                    'digest', 'put', 0)
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_multipart_write_intents
                (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier,
                 assembly_path, final_path, part_digest, lease_until, next_attempt_at)
            VALUES ('attempt-two', 'upload-one', 'bucket', 'generation', 'owner', 'STANDARD',
                    '', '', 'digest', 0, 0)
            SQL);

        $inventory = new Inventory($this->databasePath);
        foreach ([
            Inventory::PART => 'empty_part_path',
            Inventory::GARBAGE => 'empty_garbage_path',
            Inventory::OBJECT_INTENT => 'empty_object_intent_path',
            Inventory::MULTIPART_ASSEMBLY_INTENT => 'empty_multipart_assembly_intent_path',
            Inventory::MULTIPART_FINAL_INTENT => 'empty_multipart_final_intent_path',
        ] as $source => $expected) {
            $rows = $this->all($inventory, $source);
            self::assertCount(1, $rows, $source);
            self::assertNull($rows[0]['path_sha256'], $source);
            self::assertSame($expected, $rows[0]['blocking_anomaly'], $source);
        }
    }

    public function test_transition_and_restore_jobs_retain_all_statuses_and_unknown_restore_target_tier(): void
    {
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_tier_transition_jobs
                (id, bucket, key_name, source_tier, target_tier, target_storage_class,
                 source_storage_path, target_storage_path, status, next_attempt_at)
            VALUES (0, 'bucket', 'secret-transition-zero', 'STANDARD', 'COLD',
                    'GLACIER', '/storage/transition-source-zero', NULL, 'pending', 0),
                   (2, 'bucket', 'secret-transition-two', 'COLD', 'STANDARD',
                    'STANDARD', '', '', 'completed', 0),
                   (3, 'bucket', 'secret-transition-three', 'STANDARD', 'COLD',
                    'GLACIER', '/storage/transition-source-three', '/storage/transition-target-three',
                    'dead_letter', 0)
            SQL);
        $this->writer->exec(<<<'SQL'
            INSERT INTO s3_restore_jobs
                (id, bucket, key_name, source_tier, source_storage_path, restored_storage_path,
                 restore_days, status, next_attempt_at)
            VALUES (0, 'bucket', 'secret-restore-zero', 'COLD', '/storage/restore-source-zero',
                    NULL, 1, 'processing', 0),
                   (2, 'bucket', 'secret-restore-two', 'COLD', '',
                    '', 1, 'completed', 0),
                   (3, 'bucket', 'secret-restore-three', 'COLD', '/storage/restore-source-three',
                    '/storage/restore-target-three', 1, 'dead_letter', 0)
            SQL);

        $inventory = new Inventory($this->databasePath);
        $transitionSources = $this->all($inventory, Inventory::TIER_TRANSITION_SOURCE);
        $transitionTargets = $this->all($inventory, Inventory::TIER_TRANSITION_TARGET);
        $restoreSources = $this->all($inventory, Inventory::RESTORE_SOURCE);
        $restoreTargets = $this->all($inventory, Inventory::RESTORE_TARGET);
        self::assertSame(['0', '2', '3'], array_column($transitionSources, 'id'));
        self::assertSame(['2', '3'], array_column($transitionTargets, 'id'));
        self::assertSame(['STANDARD', 'COLD'], array_column($transitionTargets, 'storage_tier'));
        self::assertSame(['pending', 'completed', 'dead_letter'], array_column($transitionSources, 'state'));
        self::assertSame('empty_tier_transition_source_path', $transitionSources[1]['blocking_anomaly']);
        self::assertNull($transitionTargets[0]['path_sha256']);
        self::assertSame('empty_tier_transition_target_path', $transitionTargets[0]['blocking_anomaly']);
        self::assertSame('completed', $transitionTargets[0]['state']);
        self::assertNull($transitionTargets[1]['blocking_anomaly']);
        self::assertSame(hash('sha256', '/storage/transition-target-three'), $transitionTargets[1]['path_sha256']);
        self::assertSame(['0', '2', '3'], array_column($restoreSources, 'id'));
        self::assertSame(['2', '3'], array_column($restoreTargets, 'id'));
        self::assertSame(['processing', 'completed', 'dead_letter'], array_column($restoreSources, 'state'));
        self::assertSame('empty_restore_source_path', $restoreSources[1]['blocking_anomaly']);
        self::assertSame([null, null], array_column($restoreTargets, 'storage_tier'));
        self::assertNull($restoreTargets[0]['path_sha256']);
        self::assertSame('empty_restore_target_path', $restoreTargets[0]['blocking_anomaly']);
        self::assertSame('completed', $restoreTargets[0]['state']);
        self::assertNull($restoreTargets[1]['blocking_anomaly']);
        self::assertSame(hash('sha256', '/storage/restore-target-three'), $restoreTargets[1]['path_sha256']);
        self::assertSame([], $inventory->page(Inventory::RESTORE_TARGET, '3', 1));
        $encoded = json_encode([$transitionSources, $transitionTargets, $restoreSources, $restoreTargets], JSON_THROW_ON_ERROR);
        self::assertStringNotContainsString('secret-', $encoded);
        self::assertStringNotContainsString('/storage/', $encoded);
    }

    public function test_oversized_path_and_embedded_nul_fail_before_hashing_without_leaking_values(): void
    {
        $oversized = '/private/' . str_repeat('p', 4_097);
        $insert = $this->writer->prepare(<<<'SQL'
            INSERT INTO s3_objects (bucket, key_name, version_id, owner_id, etag, size, storage_path)
            VALUES ('bucket', 'key', 'v1', 'owner', 'etag', 4, ?)
            SQL);
        $insert->execute([$oversized]);
        $this->assertClosedProjectionFailure(Inventory::OBJECT, $oversized);

        $this->writer->exec('DELETE FROM s3_objects');
        $nulSensitive = 'private-nul-' . bin2hex(random_bytes(8));
        $insert->execute(["/private/nul\0" . $nulSensitive]);
        $this->assertClosedProjectionFailure(Inventory::OBJECT, $nulSensitive);
    }

    public function test_oversized_abort_and_pathless_upload_ids_never_become_scope_digests_or_cursors(): void
    {
        $oversizedId = 'private-upload-' . str_repeat('u', 4_097);
        $garbage = $this->writer->prepare(<<<'SQL'
            INSERT INTO s3_storage_garbage
                (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at)
            VALUES ('bucket', 'STANDARD', '', 'abort_multipart', ?, 0)
            SQL);
        $garbage->execute([$oversizedId]);
        $this->assertClosedProjectionFailure(Inventory::GARBAGE, $oversizedId);

        $upload = $this->writer->prepare(<<<'SQL'
            INSERT INTO s3_multipart_uploads
                (upload_id, bucket, key_name, owner_id, storage_tier, user_metadata)
            VALUES (?, 'bucket', 'key', 'owner', 'STANDARD', '{}')
            SQL);
        $upload->execute([$oversizedId]);
        $this->assertClosedProjectionFailure(Inventory::MULTIPART_UPLOAD_NO_PARTS, $oversizedId);

        $this->writer->exec('DELETE FROM s3_multipart_uploads');
        $cursorTooLong = 'C' . str_repeat('u', 255);
        $upload->execute([$cursorTooLong]);
        $this->assertClosedProjectionFailure(Inventory::MULTIPART_UPLOAD_NO_PARTS, $cursorTooLong);
    }

    public function test_oversized_json_and_extracted_sse_value_fail_before_classification(): void
    {
        $insert = $this->writer->prepare(<<<'SQL'
            INSERT INTO s3_objects
                (bucket, key_name, version_id, owner_id, etag, size, storage_path, user_metadata)
            VALUES ('bucket', 'key', 'v1', 'owner', 'etag', 4, '/storage/object', ?)
            SQL);
        $jsonSensitive = 'private-json-' . bin2hex(random_bytes(8));
        $oversizedJson = json_encode(['__sse-algorithm' => 'AES256', 'padding' => str_repeat($jsonSensitive, 512)], JSON_THROW_ON_ERROR);
        $insert->execute([$oversizedJson]);
        $this->assertClosedProjectionFailure(Inventory::OBJECT, $jsonSensitive);

        $this->writer->exec('DELETE FROM s3_objects');
        $oversizedSse = 'private-sse-' . str_repeat('S', 4_097);
        $insert->execute([json_encode(['__sse-algorithm' => $oversizedSse], JSON_THROW_ON_ERROR)]);
        $this->assertClosedProjectionFailure(Inventory::OBJECT, $oversizedSse);
    }

    public function test_exact_byte_limit_and_multibyte_path_are_hashed_without_truncation(): void
    {
        $path = '/storage/' . str_repeat('é', 2_043) . 'x';
        self::assertSame(4_096, strlen($path));
        $insert = $this->writer->prepare(<<<'SQL'
            INSERT INTO s3_objects (bucket, key_name, version_id, owner_id, etag, size, storage_path)
            VALUES ('bucket', 'key', 'v1', 'owner', 'etag', 4, ?)
            SQL);
        $insert->execute([$path]);
        $rows = (new Inventory($this->databasePath))->page(Inventory::OBJECT);
        self::assertCount(1, $rows);
        self::assertSame(hash('sha256', $path), $rows[0]['path_sha256']);
        self::assertNull($rows[0]['blocking_anomaly']);

        $this->writer->exec('DELETE FROM s3_objects');
        $insert->execute([$path . 'x']);
        $this->assertClosedProjectionFailure(Inventory::OBJECT, $path);
    }

    private function assertClosedProjectionFailure(string $source, string $sensitive): void
    {
        try {
            (new Inventory($this->databasePath))->page($source);
            self::fail('An oversized or invalid projection was accepted.');
        } catch (\RuntimeException $error) {
            self::assertStringNotContainsString($sensitive, (string) $error);
            self::assertNull($error->getPrevious());
        }
    }

    /** @return list<array<string, mixed>> */
    private function all(Inventory $inventory, string $source): array
    {
        $all = [];
        $after = null;
        do {
            $page = $inventory->page($source, $after, 1);
            array_push($all, ...$page);
            $after = $page !== [] ? $page[array_key_last($page)]['id'] : null;
        } while ($page !== []);

        return $all;
    }
}
