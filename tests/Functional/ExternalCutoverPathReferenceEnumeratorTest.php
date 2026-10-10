<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\Mysql\MysqlConfig;
use Amp\Mysql\MysqlConnectionPool;
use Amp\Postgres\PostgresConfig;
use Amp\Postgres\PostgresConnectionPool;
use OpsFour\S3Server\Factory\MetadataStoreFactory;
use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator as Inventory;
use OpsFour\S3Server\Metadata\ExternalCutoverPathReferenceEnumerator;
use OpsFour\S3Server\Metadata\MultipartUploadTierStore;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class ExternalCutoverPathReferenceEnumeratorTest extends TestCase
{
    /** @return iterable<string, array{string, string}> */
    public static function databases(): iterable
    {
        yield 'postgres' => ['postgres', 'S3_TEST_POSTGRES_METADATA_DSN'];
        yield 'mysql' => ['mysql', 'S3_TEST_MYSQL_METADATA_DSN'];
    }

    #[DataProvider('databases')]
    public function test_oversized_path_and_json_fail_before_a_digest_or_cursor_is_returned(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for an isolated disposable database.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        if ($driver === 'postgres') {
            $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
            $inventory = ExternalCutoverPathReferenceEnumerator::forPostgres($pool);
        } else {
            $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
            $inventory = ExternalCutoverPathReferenceEnumerator::forMysql($pool);
        }
        $owner = 'cutover-bound-owner-' . bin2hex(random_bytes(5));
        $bucket = 'cutover-bound-' . bin2hex(random_bytes(5));
        $upload = bin2hex(random_bytes(16));
        $oversizedPath = '/private/' . str_repeat('x', 4_097);

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $metadata->createMultipartUpload($upload, $bucket, 'bounded', $owner);
            $metadata->putPart($upload, 1, 'etag', 1, $oversizedPath);
            $this->assertOversizedPage($inventory, Inventory::PART, $oversizedPath);

            if ($driver === 'mysql') {
                $nulPath = "/private/nul\0path";
                $this->execute(
                    $pool,
                    $driver,
                    'UPDATE s3_parts SET storage_path = $1 WHERE upload_id = $2',
                    'UPDATE s3_parts SET storage_path = ? WHERE upload_id = ?',
                    [$nulPath, $upload],
                );
                try {
                    $inventory->page(Inventory::PART, limit: 500);
                    self::fail('A NUL-containing MySQL part path produced a digest.');
                } catch (\UnexpectedValueException $error) {
                    self::assertSame('Cutover inventory row contains an invalid byte.', $error->getMessage());
                    self::assertStringNotContainsString($nulPath, (string) $error);
                }
            }

            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_parts SET storage_path = $1 WHERE upload_id = $2',
                'UPDATE s3_parts SET storage_path = ? WHERE upload_id = ?',
                ['/private/short', $upload],
            );
            $hugeJson = json_encode(['__sse-algorithm' => str_repeat('A', 17_000)], JSON_THROW_ON_ERROR);
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_multipart_uploads SET user_metadata = CAST($1 AS jsonb) WHERE upload_id = $2',
                'UPDATE s3_multipart_uploads SET user_metadata = ? WHERE upload_id = ?',
                [$hugeJson, $upload],
            );
            $this->assertOversizedPage($inventory, Inventory::PART, $hugeJson);
        } finally {
            $this->execute(
                $pool,
                $driver,
                'DELETE FROM s3_parts WHERE upload_id = $1',
                'DELETE FROM s3_parts WHERE upload_id = ?',
                [$upload],
            );
            $this->execute(
                $pool,
                $driver,
                'DELETE FROM s3_multipart_uploads WHERE upload_id = $1',
                'DELETE FROM s3_multipart_uploads WHERE upload_id = ?',
                [$upload],
            );
            $this->execute(
                $pool,
                $driver,
                'DELETE FROM s3_buckets WHERE name = $1',
                'DELETE FROM s3_buckets WHERE name = ?',
                [$bucket],
            );
        }
    }

    #[DataProvider('databases')]
    public function test_all_reference_sources_page_without_raw_paths_or_wrong_sse_keys(string $driver, string $dsnEnv): void
    {
        $dsn = getenv($dsnEnv);
        if ($dsn === false || trim($dsn) === '') {
            self::markTestSkipped("Set {$dsnEnv} for an isolated disposable database.");
        }

        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        self::assertInstanceOf(MultipartUploadTierStore::class, $metadata);
        if ($driver === 'postgres') {
            $pool = new PostgresConnectionPool(PostgresConfig::fromString($dsn));
            $inventory = ExternalCutoverPathReferenceEnumerator::forPostgres($pool);
        } else {
            $pool = new MysqlConnectionPool(MysqlConfig::fromString($dsn));
            $inventory = ExternalCutoverPathReferenceEnumerator::forMysql($pool);
        }
        $owner = 'cutover-owner-' . bin2hex(random_bytes(5));
        $bucket = 'cutover-' . bin2hex(random_bytes(5));
        $uploadLegacy = bin2hex(random_bytes(16));
        $uploadPinned = bin2hex(random_bytes(16));
        $objectAttempt = bin2hex(random_bytes(16));
        $emptyObjectAttempt = bin2hex(random_bytes(16));
        $multipartAttempt = bin2hex(random_bytes(16));
        $emptyMultipartAttempt = bin2hex(random_bytes(16));
        $prefix = '/cutover/' . $bucket . '/';

        try {
            $metadata->createBucket($owner, $bucket, 'us-east-1');
            $generation = $metadata->getBucket($bucket)?->generation;
            self::assertNotNull($generation);
            $metadata->putObjectMetadata($bucket, 'real', $owner, 4, 'etag', 'application/octet-stream', $prefix . 'object-real', userMetadata: ['__sse-algorithm' => 'AES256']);
            $metadata->putObjectMetadata($bucket, 'wrong', $owner, 4, 'etag', 'application/octet-stream', $prefix . 'object-wrong', userMetadata: ['sse-algorithm' => 'AES256']);
            $metadata->putObjectMetadata($bucket, 'missing-path', $owner, 4, 'etag', 'application/octet-stream', '');
            $metadata->putObjectMetadata($bucket, 'valid-marker', $owner, 0, '', 'application/octet-stream', '');
            $metadata->putObjectMetadata($bucket, 'bad-marker', $owner, 0, '', 'application/octet-stream', $prefix . 'marker-debris');
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_objects SET is_delete_marker = TRUE WHERE bucket = $1 AND key_name IN ($2, $3)',
                'UPDATE s3_objects SET is_delete_marker = 1 WHERE bucket = ? AND key_name IN (?, ?)',
                [$bucket, 'valid-marker', 'bad-marker'],
            );
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_objects SET restored_storage_path = $1, restored_storage_tier = $2, restore_status = $3 WHERE bucket = $4 AND key_name = $5',
                'UPDATE s3_objects SET restored_storage_path = ?, restored_storage_tier = ?, restore_status = ? WHERE bucket = ? AND key_name = ?',
                [$prefix . 'restored', 'COLD', 'restored', $bucket, 'real'],
            );
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_objects SET restored_storage_path = $1, restored_storage_tier = $2, restore_status = $3 WHERE bucket = $4 AND key_name = $5',
                'UPDATE s3_objects SET restored_storage_path = ?, restored_storage_tier = ?, restore_status = ? WHERE bucket = ? AND key_name = ?',
                ['', 'STANDARD', 'restored', $bucket, 'wrong'],
            );

            $metadata->createMultipartUpload($uploadLegacy, $bucket, 'part-key', $owner, userMetadata: ['__sse-algorithm' => 'SSE-C', '__mpu-part-format' => 'encrypted-v2']);
            // Model a pre-tier-pinning upload; current creation assigns STANDARD.
            $this->execute(
                $pool,
                $driver,
                'UPDATE s3_multipart_uploads SET storage_tier = NULL WHERE upload_id = $1',
                'UPDATE s3_multipart_uploads SET storage_tier = NULL WHERE upload_id = ?',
                [$uploadLegacy],
            );
            $metadata->putPart($uploadLegacy, 1, 'etag', 4, $prefix . 'part');
            $this->execute(
                $pool,
                $driver,
                'INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path) VALUES ($1, 2, $2, 4, $3)',
                'INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path) VALUES (?, 2, ?, 4, ?)',
                [$uploadLegacy, 'etag-empty', ''],
            );
            $metadata->createMultipartUploadWithTier(
                $uploadPinned,
                $bucket,
                'completion-key',
                $owner,
                'STANDARD',
                userMetadata: ['__sse-algorithm' => 'AES256', '__mpu-part-format' => 'encrypted-v2'],
            );
            $metadata->createMultipartUploadWithTier('B', $bucket, 'binary-upper', $owner, 'STANDARD');
            $metadata->createMultipartUploadWithTier('a', $bucket, 'binary-lower', $owner, 'STANDARD');
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, storage_tier, user_metadata) VALUES ($1, $2, 'invalid-path-id', $3, 'STANDARD', '{}')",
                "INSERT INTO s3_multipart_uploads (upload_id, bucket, key_name, owner_id, storage_tier, user_metadata) VALUES (?, ?, 'invalid-path-id', ?, 'STANDARD', '{}')",
                ['../escape', $bucket, $owner],
            );
            if ($driver === 'mysql') {
                // The FK uses a case-insensitive collation; preserve an unmatched bytewise part reference.
                $metadata->createMultipartUploadWithTier(
                    'CaseID',
                    $bucket,
                    'case-parent',
                    $owner,
                    'COLD',
                    userMetadata: ['__sse-algorithm' => 'AES256'],
                );
                $this->execute(
                    $pool,
                    $driver,
                    'INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path) VALUES ($1, 1, $2, 4, $3)',
                    'INSERT INTO s3_parts (upload_id, part_number, etag, size, storage_path) VALUES (?, 1, ?, 4, ?)',
                    ['caseid', 'etag', $prefix . 'case-part'],
                );
            }
            $metadata->enqueueStorageGarbage($bucket, 'STANDARD', $prefix . 'garbage');
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, next_attempt_at) VALUES ($1, 'STANDARD', '', 'delete_path', 0)",
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, next_attempt_at) VALUES (?, 'STANDARD', '', 'delete_path', 0)",
                [$bucket],
            );
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at) VALUES ($1, 'STANDARD', '', 'abort_multipart', $2, 0), ($1, 'STANDARD', '', 'abort_multipart', $3, 0)",
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at) VALUES (?, 'STANDARD', '', 'abort_multipart', ?, 0), (?, 'STANDARD', '', 'abort_multipart', ?, 0)",
                $driver === 'postgres' ? [$bucket, 'Legacy.1', '../escape'] : [$bucket, 'Legacy.1', $bucket, '../escape'],
            );
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at) VALUES ($1, 'STANDARD', $2, 'abort_multipart', $3, 0)",
                "INSERT INTO s3_storage_garbage (bucket, storage_tier, storage_path, operation, upload_id, next_attempt_at) VALUES (?, 'STANDARD', ?, 'abort_multipart', ?, 0)",
                [$bucket, $prefix . 'ignored-abort-path', 'Valid.1'],
            );
            $transitionWithoutTarget = $metadata->enqueueTierTransitionJob(
                $bucket,
                'transition-pending',
                null,
                'STANDARD',
                'COLD',
                'GLACIER',
                $prefix . 'transition-source-pending',
            );
            $transitionWithTarget = $metadata->enqueueTierTransitionJob(
                $bucket,
                'transition-completed',
                null,
                'STANDARD',
                'COLD',
                'GLACIER',
                $prefix . 'transition-source-completed',
            );
            $metadata->updateTierTransitionJobStatus(
                $transitionWithTarget,
                'completed',
                incrementAttempts: false,
                targetStoragePath: $prefix . 'transition-target',
            );
            $transitionWithEmptyPaths = $metadata->enqueueTierTransitionJob(
                $bucket,
                'transition-empty',
                null,
                'STANDARD',
                'COLD',
                'GLACIER',
                '',
            );
            $metadata->updateTierTransitionJobStatus($transitionWithEmptyPaths, 'completed', incrementAttempts: false, targetStoragePath: '');
            $restoreWithoutTarget = $metadata->enqueueRestoreJob(
                $bucket,
                'restore-pending',
                null,
                'COLD',
                $prefix . 'restore-source-pending',
                1,
            );
            $restoreWithTarget = $metadata->enqueueRestoreJob(
                $bucket,
                'restore-completed',
                null,
                'COLD',
                $prefix . 'restore-source-completed',
                1,
            );
            $metadata->updateRestoreJobStatus(
                $restoreWithTarget,
                'completed',
                incrementAttempts: false,
                restoredStoragePath: $prefix . 'restore-target',
            );
            $restoreWithEmptyPaths = $metadata->enqueueRestoreJob($bucket, 'restore-empty', null, 'COLD', '', 1);
            $metadata->updateRestoreJobStatus($restoreWithEmptyPaths, 'completed', incrementAttempts: false, restoredStoragePath: '');

            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until) VALUES ($1, $2, $3, $4, 'STANDARD', $5, $6, 'put', 0)",
                "INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until) VALUES (?, ?, ?, ?, 'STANDARD', ?, ?, 'put', 0)",
                [$objectAttempt, $bucket, $generation, $owner, $prefix . 'object-intent', hash('sha256', $prefix . 'object-intent')],
            );
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until) VALUES ($1, $2, $3, $4, 'STANDARD', '', $5, 'put', 0)",
                "INSERT INTO s3_object_write_intents (attempt_id, bucket, bucket_generation, owner_id, storage_tier, storage_path, path_digest, purpose, lease_until) VALUES (?, ?, ?, ?, 'STANDARD', '', ?, 'put', 0)",
                [$emptyObjectAttempt, $bucket, $generation, $owner, hash('sha256', 'empty-' . $emptyObjectAttempt)],
            );
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, sse_mode, lease_until, next_attempt_at) VALUES ($1, $2, $3, $4, $5, 'STANDARD', $6, $7, $8, 'AES256', 0, 0)",
                "INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, sse_mode, lease_until, next_attempt_at) VALUES (?, ?, ?, ?, ?, 'STANDARD', ?, ?, ?, 'AES256', 0, 0)",
                [$multipartAttempt, $uploadPinned, $bucket, $generation, $owner, $prefix . 'assembly', $prefix . 'final', hash('sha256', 'parts')],
            );
            $this->execute(
                $pool,
                $driver,
                "INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, sse_mode, lease_until, next_attempt_at) VALUES ($1, $2, $3, $4, $5, 'STANDARD', '', '', $6, 'AES256', 0, 0)",
                "INSERT INTO s3_multipart_write_intents (attempt_id, upload_id, bucket, bucket_generation, owner_id, storage_tier, assembly_path, final_path, part_digest, sse_mode, lease_until, next_attempt_at) VALUES (?, ?, ?, ?, ?, 'STANDARD', '', '', ?, 'AES256', 0, 0)",
                [$emptyMultipartAttempt, $uploadPinned, $bucket, $generation, $owner, hash('sha256', 'empty-parts')],
            );

            $expectations = [
                Inventory::OBJECT => [$prefix . 'object-real', 'AES256', null],
                Inventory::RESTORED_OBJECT => [$prefix . 'restored', 'AES256', null],
                Inventory::PART => [$prefix . 'part', 'SSE-C', 'encrypted-v2'],
                Inventory::GARBAGE => [$prefix . 'garbage', null, null],
                Inventory::OBJECT_INTENT => [$prefix . 'object-intent', null, null],
                Inventory::MULTIPART_ASSEMBLY_INTENT => [$prefix . 'assembly', 'AES256', null],
                Inventory::MULTIPART_FINAL_INTENT => [$prefix . 'final', 'AES256', null],
                Inventory::TIER_TRANSITION_SOURCE => [$prefix . 'transition-source-completed', null, null],
                Inventory::TIER_TRANSITION_TARGET => [$prefix . 'transition-target', null, null],
                Inventory::RESTORE_SOURCE => [$prefix . 'restore-source-completed', null, null],
                Inventory::RESTORE_TARGET => [$prefix . 'restore-target', null, null],
            ];
            foreach ($expectations as $source => [$path, $mode, $format]) {
                $row = $this->findByPathHash($inventory, $source, hash('sha256', $path));
                self::assertNotNull($row, $source);
                self::assertSame($mode, $row['sse_mode'], $source);
                self::assertSame($format, $row['part_format'], $source);
                self::assertNull($row['blocking_anomaly'], $source);
                self::assertArrayNotHasKey('path', $row);
            }
            foreach ([
                Inventory::OBJECT => 'empty_object_path',
                Inventory::RESTORED_OBJECT => 'empty_restored_object_path',
                Inventory::PART => 'empty_part_path',
                Inventory::GARBAGE => 'empty_garbage_path',
                Inventory::OBJECT_INTENT => 'empty_object_intent_path',
                Inventory::MULTIPART_ASSEMBLY_INTENT => 'empty_multipart_assembly_intent_path',
                Inventory::MULTIPART_FINAL_INTENT => 'empty_multipart_final_intent_path',
                Inventory::TIER_TRANSITION_SOURCE => 'empty_tier_transition_source_path',
                Inventory::TIER_TRANSITION_TARGET => 'empty_tier_transition_target_path',
                Inventory::RESTORE_SOURCE => 'empty_restore_source_path',
                Inventory::RESTORE_TARGET => 'empty_restore_target_path',
            ] as $source => $anomaly) {
                $matches = array_values(array_filter(
                    $inventory->page($source, limit: 500),
                    static fn(array $row): bool => $row['blocking_anomaly'] === $anomaly,
                ));
                self::assertCount(1, $matches, $source);
                self::assertNull($matches[0]['path_sha256'], $source);
                if (str_ends_with($source, '_target') || str_ends_with($source, '_source')) {
                    self::assertSame('completed', $matches[0]['state'], $source);
                }
            }
            $part = $this->findByPathHash($inventory, Inventory::PART, hash('sha256', $prefix . 'part'));
            self::assertNotNull($part);
            self::assertNull($part['storage_tier']);
            $wrong = $this->findByPathHash($inventory, Inventory::OBJECT, hash('sha256', $prefix . 'object-wrong'));
            self::assertNotNull($wrong);
            self::assertNull($wrong['sse_mode']);
            $objects = $inventory->page(Inventory::OBJECT, limit: 500);
            self::assertCount(4, $objects);
            $missingPaths = array_values(array_filter($objects, static fn(array $row): bool => $row['state'] === 'missing_object_path'));
            self::assertCount(1, $missingPaths);
            self::assertNull($missingPaths[0]['path_sha256']);
            $badMarker = $this->findByPathHash($inventory, Inventory::OBJECT, hash('sha256', $prefix . 'marker-debris'));
            self::assertNotNull($badMarker);
            self::assertSame('delete_marker_with_path', $badMarker['state']);
            $garbage = $inventory->page(Inventory::GARBAGE, limit: 500);
            $abortWithDigest = array_values(array_filter(
                $garbage,
                static fn(array $row): bool => $row['relative_path_sha256'] === hash('sha256', '.parts/Legacy.1'),
            ));
            self::assertCount(1, $abortWithDigest);
            self::assertNull($abortWithDigest[0]['path_sha256']);
            self::assertNull($abortWithDigest[0]['blocking_anomaly']);
            $abortWithoutDigest = array_values(array_filter(
                $garbage,
                static fn(array $row): bool => $row['operation'] === 'abort_multipart' && $row['relative_path_sha256'] === null,
            ));
            self::assertCount(1, $abortWithoutDigest);
            self::assertSame('uncorrelatable_abort_multipart_scope', $abortWithoutDigest[0]['blocking_anomaly']);
            $abortWithUnexpectedPath = array_values(array_filter(
                $garbage,
                static fn(array $row): bool => $row['blocking_anomaly'] === 'unexpected_abort_multipart_path',
            ));
            self::assertCount(1, $abortWithUnexpectedPath);
            self::assertSame(hash('sha256', '.parts/Valid.1'), $abortWithUnexpectedPath[0]['relative_path_sha256']);
            self::assertStringNotContainsString('Legacy.1', json_encode($garbage, JSON_THROW_ON_ERROR));
            $noPartUploads = $inventory->page(Inventory::MULTIPART_UPLOAD_NO_PARTS, limit: 500);
            $uploadIds = array_column($noPartUploads, 'id');
            self::assertContains('B', $uploadIds);
            self::assertContains('a', $uploadIds);
            self::assertLessThan(array_search('a', $uploadIds, true), array_search('B', $uploadIds, true));
            $upper = array_values(array_filter($noPartUploads, static fn(array $row): bool => $row['id'] === 'B'));
            $lower = array_values(array_filter($noPartUploads, static fn(array $row): bool => $row['id'] === 'a'));
            $invalid = array_values(array_filter($noPartUploads, static fn(array $row): bool => $row['id'] === '../escape'));
            self::assertSame(hash('sha256', '.parts/B'), $upper[0]['relative_path_sha256']);
            self::assertSame(hash('sha256', '.parts/a'), $lower[0]['relative_path_sha256']);
            self::assertNotSame($upper[0]['relative_path_sha256'], $lower[0]['relative_path_sha256']);
            self::assertNull($invalid[0]['relative_path_sha256']);
            self::assertSame('uncorrelatable_active_upload_scope', $invalid[0]['blocking_anomaly']);
            if ($driver === 'mysql') {
                self::assertContains('CaseID', $uploadIds);
                $caseUpload = array_values(array_filter($noPartUploads, static fn(array $row): bool => $row['id'] === 'CaseID'));
                self::assertSame(hash('sha256', '.parts/CaseID'), $caseUpload[0]['relative_path_sha256']);
                self::assertNotSame(hash('sha256', '.parts/caseid'), $caseUpload[0]['relative_path_sha256']);
                $casePart = $this->findByPathHash($inventory, Inventory::PART, hash('sha256', $prefix . 'case-part'));
                self::assertNotNull($casePart);
                self::assertNull($casePart['storage_tier']);
                self::assertNull($casePart['sse_mode']);
                self::assertNull($casePart['part_format']);
            }
            $noPartUploads = array_values(array_filter($noPartUploads, static fn(array $row): bool => $row['id'] === $uploadPinned));
            self::assertCount(1, $noPartUploads);
            self::assertNull($noPartUploads[0]['path_sha256']);
            self::assertSame(hash('sha256', '.parts/' . $uploadPinned), $noPartUploads[0]['relative_path_sha256']);
            self::assertSame('AES256', $noPartUploads[0]['sse_mode']);
            self::assertSame('encrypted-v2', $noPartUploads[0]['part_format']);
            self::assertSame('active_no_parts', $noPartUploads[0]['state']);
            self::assertNull($noPartUploads[0]['blocking_anomaly']);
            self::assertSame([], array_values(array_filter(
                $inventory->page(Inventory::MULTIPART_UPLOAD_NO_PARTS, limit: 500),
                static fn(array $row): bool => $row['id'] === $uploadLegacy,
            )));
            $transitionTargets = $inventory->page(Inventory::TIER_TRANSITION_TARGET, limit: 500);
            self::assertNotContains((string) $transitionWithoutTarget, array_column($transitionTargets, 'id'));
            self::assertContains((string) $transitionWithTarget, array_column($transitionTargets, 'id'));
            $restoreTargets = $inventory->page(Inventory::RESTORE_TARGET, limit: 500);
            self::assertNotContains((string) $restoreWithoutTarget, array_column($restoreTargets, 'id'));
            self::assertContains((string) $restoreWithTarget, array_column($restoreTargets, 'id'));
            $restoreTarget = $this->findByPathHash($inventory, Inventory::RESTORE_TARGET, hash('sha256', $prefix . 'restore-target'));
            self::assertNotNull($restoreTarget);
            self::assertNull($restoreTarget['storage_tier']);
            self::assertSame('completed', $restoreTarget['state']);
        } finally {
            foreach ([
                ['DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1', 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = ?', [$emptyMultipartAttempt]],
                ['DELETE FROM s3_multipart_write_intents WHERE attempt_id = $1', 'DELETE FROM s3_multipart_write_intents WHERE attempt_id = ?', [$multipartAttempt]],
                ['DELETE FROM s3_object_write_intents WHERE attempt_id = $1', 'DELETE FROM s3_object_write_intents WHERE attempt_id = ?', [$emptyObjectAttempt]],
                ['DELETE FROM s3_object_write_intents WHERE attempt_id = $1', 'DELETE FROM s3_object_write_intents WHERE attempt_id = ?', [$objectAttempt]],
                ['DELETE FROM s3_storage_garbage WHERE bucket = $1', 'DELETE FROM s3_storage_garbage WHERE bucket = ?', [$bucket]],
                ['DELETE FROM s3_parts WHERE storage_path = $1', 'DELETE FROM s3_parts WHERE storage_path = ?', [$prefix . 'case-part']],
                ['DELETE FROM s3_tier_transition_jobs WHERE bucket = $1', 'DELETE FROM s3_tier_transition_jobs WHERE bucket = ?', [$bucket]],
                ['DELETE FROM s3_restore_jobs WHERE bucket = $1', 'DELETE FROM s3_restore_jobs WHERE bucket = ?', [$bucket]],
                ['DELETE FROM s3_parts WHERE upload_id = $1', 'DELETE FROM s3_parts WHERE upload_id = ?', [$uploadLegacy]],
                ['DELETE FROM s3_multipart_uploads WHERE bucket = $1', 'DELETE FROM s3_multipart_uploads WHERE bucket = ?', [$bucket]],
                ['DELETE FROM s3_objects WHERE bucket = $1', 'DELETE FROM s3_objects WHERE bucket = ?', [$bucket]],
                ['DELETE FROM s3_buckets WHERE name = $1', 'DELETE FROM s3_buckets WHERE name = ?', [$bucket]],
            ] as [$postgresSql, $mysqlSql, $args]) {
                $this->execute($pool, $driver, $postgresSql, $mysqlSql, $args);
            }
        }
    }

    /**
     * @param PostgresConnectionPool|MysqlConnectionPool $pool
     * @param list<mixed> $args
     */
    private function execute(PostgresConnectionPool|MysqlConnectionPool $pool, string $driver, string $postgresSql, string $mysqlSql, array $args): void
    {
        $pool->execute($driver === 'postgres' ? $postgresSql : $mysqlSql, $args);
    }

    private function assertOversizedPage(Inventory $inventory, string $source, string $secret): void
    {
        try {
            $inventory->page($source, limit: 500);
            self::fail('Oversized SQL value produced an inventory page.');
        } catch (\UnexpectedValueException $error) {
            self::assertSame('Cutover inventory row exceeds its byte limit.', $error->getMessage());
            self::assertStringNotContainsString($secret, (string) $error);
        }
    }

    /** @return array<string, mixed>|null */
    private function findByPathHash(Inventory $inventory, string $source, string $pathHash): ?array
    {
        $after = null;
        for ($pageNumber = 0; $pageNumber < 100; $pageNumber++) {
            $page = $inventory->page($source, $after, 1);
            if ($page === []) {
                return null;
            }
            if ($page[0]['path_sha256'] === $pathHash) {
                return $page[0];
            }
            $after = $page[0]['id'];
        }

        self::fail('Disposable inventory fixture exceeded 100 rows in one source.');
    }
}
