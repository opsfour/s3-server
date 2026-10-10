<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Cutover;

use OpsFour\S3Server\Cutover\CutoverInventoryIdentity;
use OpsFour\S3Server\Cutover\CutoverPairClassifier as Pair;
use OpsFour\S3Server\Metadata\CutoverPathReferenceEnumerator as Source;
use PHPUnit\Framework\TestCase;

final class CutoverPairClassifierTest extends TestCase
{
    private const string ROOT = '/mnt/cutover/hot';

    private const string PATH = self::ROOT . '/bucket/ab/cd/object';

    public function test_exact_file_rows_are_only_candidates_even_with_a_recognized_ciphertext_header(): void
    {
        $sql = $this->sql();
        $physical = $this->physical();
        $identity = $this->identity();
        self::assertSame(Pair::EXACT_FILE_CANDIDATE, Pair::classify($sql, $identity, $physical, $identity));

        self::assertSame(Pair::EXACT_FILE_CANDIDATE, Pair::classify(
            $this->sql(['source' => Source::PART, 'sse_mode' => 'SSE-C', 'part_format' => 'encrypted-v2']),
            $identity,
            $this->physical(['format' => 'multipart_v2_header_unverified']),
            $identity,
        ));
        self::assertSame(Pair::EXACT_FILE_CANDIDATE, Pair::classify(
            $this->sql(['source' => Source::RESTORED_OBJECT, 'state' => 'restored']),
            $identity,
            $physical,
            $identity,
        ));
    }

    public function test_encrypted_v2_part_without_its_mandatory_header_is_not_a_cutover_candidate(): void
    {
        $identity = $this->identity();

        self::assertSame('encrypted_part_header_missing', Pair::classify(
            $this->sql(['source' => Source::PART, 'sse_mode' => 'SSE-C', 'part_format' => 'encrypted-v2']),
            $identity,
            $this->physical(['format' => 'opaque_unclassified']),
            $identity,
        ));
    }

    public function test_missing_and_mismatched_contexts_block_even_with_equal_path_digests(): void
    {
        $identity = $this->identity();
        $sql = $this->sql();
        $physical = $this->physical();
        self::assertSame('missing_context', Pair::classify($sql, null, $physical, $identity));
        self::assertSame('missing_context', Pair::classify($sql, $identity, $physical, null));
        self::assertSame('snapshot_context_mismatch', Pair::classify(
            $sql,
            $identity,
            $physical,
            $this->identity(snapshot: 'different'),
        ));
        self::assertSame('root_context_mismatch', Pair::classify(
            $sql,
            $identity,
            $physical,
            $this->identity(root: '/mnt/alias'),
        ));
        self::assertSame('tier_context_mismatch', Pair::classify(
            $sql,
            $identity,
            $physical,
            $this->identity(tier: 'COLD'),
        ));
        self::assertSame('sql_tier_mismatch', Pair::classify(
            $this->sql(['storage_tier' => null]),
            $identity,
            $physical,
            $identity,
        ));
        self::assertSame('physical_tier_mismatch', Pair::classify(
            $sql,
            $identity,
            $this->physical(['storage_tier' => 'COLD']),
            $identity,
        ));
    }

    public function test_cleanup_intent_and_job_rows_never_authorize_live_files_for_any_status(): void
    {
        $identity = $this->identity();
        foreach ([
            Source::GARBAGE => 'cleanup_reference',
            Source::OBJECT_INTENT => 'intent_reference',
            Source::MULTIPART_ASSEMBLY_INTENT => 'intent_reference',
            Source::MULTIPART_FINAL_INTENT => 'intent_reference',
            Source::TIER_TRANSITION_SOURCE => 'job_reference',
            Source::TIER_TRANSITION_TARGET => 'job_reference',
            Source::RESTORE_SOURCE => 'job_reference',
            Source::RESTORE_TARGET => 'job_reference',
        ] as $source => $expected) {
            foreach (['pending', 'processing', 'completed', 'sealed'] as $state) {
                self::assertSame($expected, Pair::classify(
                    $this->sql(['source' => $source, 'state' => $state]),
                    $identity,
                    $this->physical(),
                    $identity,
                ), $source . ':' . $state);
            }
        }
        self::assertSame('sql_tier_mismatch', Pair::classify(
            $this->sql(['source' => Source::RESTORE_TARGET, 'storage_tier' => null]),
            $identity,
            $this->physical(),
            $identity,
        ));
    }

    public function test_anomalies_wrong_roles_types_and_historical_aliases_are_blockers(): void
    {
        $identity = $this->identity();
        $physical = $this->physical();
        self::assertSame('sql_blocking_anomaly', Pair::classify(
            $this->sql(['blocking_anomaly' => 'empty_object_path']),
            $identity,
            $physical,
            $identity,
        ));
        self::assertSame('sql_state_not_candidate', Pair::classify(
            $this->sql(['state' => 'delete_marker_with_path']),
            $identity,
            $physical,
            $identity,
        ));
        self::assertSame('sql_state_not_candidate', Pair::classify(
            $this->sql(['source' => Source::RESTORED_OBJECT, 'state' => 'pending']),
            $identity,
            $physical,
            $identity,
        ));
        self::assertSame('sql_state_not_candidate', Pair::classify(
            $this->sql(['source' => Source::PART, 'state' => 'orphan']),
            $identity,
            $physical,
            $identity,
        ));
        self::assertSame('wrong_physical_type', Pair::classify(
            $this->sql(),
            $identity,
            $this->physical(['type' => 'directory', 'size_bytes' => null, 'format' => null]),
            $identity,
        ));
        self::assertSame('path_digest_mismatch', Pair::classify(
            $this->sql(['path_sha256' => hash('sha256', self::ROOT . '//bucket/ab/cd/object')]),
            $identity,
            $physical,
            $identity,
        ));
        self::assertSame('seal_marker_not_payload', Pair::classify(
            $this->sql(),
            $identity,
            $this->physical(['format' => 'object_seal_shape_unverified']),
            $identity,
        ));
        self::assertSame('invalid_physical_row', Pair::classify(
            $this->sql(),
            $identity,
            $this->physical(['format' => 'authenticated']),
            $identity,
        ));
        self::assertSame('unknown_sql_source', Pair::classify(
            $this->sql(['source' => 'future_source']),
            $identity,
            $physical,
            $identity,
        ));
        $incompleteSql = $this->sql();
        unset($incompleteSql['blocking_anomaly']);
        self::assertSame('invalid_sql_row', Pair::classify($incompleteSql, $identity, $physical, $identity));
        $incompletePhysical = $this->physical();
        unset($incompletePhysical['format']);
        self::assertSame('invalid_physical_row', Pair::classify($this->sql(), $identity, $incompletePhysical, $identity));
    }

    public function test_pathless_upload_matches_only_the_exact_physical_directory(): void
    {
        $identity = $this->identity();
        $scope = hash('sha256', '.parts/CaseID');
        $sql = $this->sql([
            'source' => Source::MULTIPART_UPLOAD_NO_PARTS,
            'path_sha256' => null,
            'relative_path_sha256' => $scope,
            'state' => 'active_no_parts',
        ]);
        $directory = $this->physical([
            'path_sha256' => hash('sha256', self::ROOT . '/.parts/CaseID'),
            'relative_path_sha256' => $scope,
            'multipart_scope_sha256' => $scope,
            'type' => 'directory',
            'size_bytes' => null,
            'format' => null,
        ]);
        self::assertSame(Pair::MULTIPART_DIRECTORY_OBSERVATION, Pair::classify($sql, $identity, $directory, $identity));
        self::assertSame('multipart_descendant_or_scope_mismatch', Pair::classify(
            $sql,
            $identity,
            array_replace($directory, [
                'relative_path_sha256' => hash('sha256', '.parts/CaseID/1-part'),
                'path_sha256' => hash('sha256', self::ROOT . '/.parts/CaseID/1-part'),
            ]),
            $identity,
        ));
        self::assertSame('wrong_physical_type', Pair::classify(
            $sql,
            $identity,
            array_replace($directory, ['type' => 'file', 'size_bytes' => 4, 'format' => 'opaque_unclassified']),
            $identity,
        ));
        self::assertSame('multipart_descendant_or_scope_mismatch', Pair::classify(
            $sql,
            $identity,
            array_replace($directory, ['relative_path_sha256' => hash('sha256', '.parts/caseid')]),
            $identity,
        ));
        self::assertSame('sql_state_not_candidate', Pair::classify(
            array_replace($sql, ['state' => 'completed']),
            $identity,
            $directory,
            $identity,
        ));
        self::assertSame('missing_sql_scope_digest', Pair::classify(
            array_replace($sql, ['relative_path_sha256' => null]),
            $identity,
            $directory,
            $identity,
        ));
    }

    private function identity(string $tier = 'HOT', string $root = self::ROOT, string $snapshot = 'caller-asserted-snapshot'): CutoverInventoryIdentity
    {
        return new CutoverInventoryIdentity($tier, hash('sha256', $root), $snapshot);
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    private function sql(array $changes = []): array
    {
        return array_replace([
            'source' => Source::OBJECT,
            'id' => '1',
            'storage_tier' => 'HOT',
            'path_sha256' => hash('sha256', self::PATH),
            'relative_path_sha256' => null,
            'sse_mode' => 'AES256',
            'part_format' => null,
            'operation' => null,
            'state' => null,
            'blocking_anomaly' => null,
        ], $changes);
    }

    /** @param array<string, mixed> $changes
     *  @return array<string, mixed>
     */
    private function physical(array $changes = []): array
    {
        return array_replace([
            'storage_tier' => 'HOT',
            'path_sha256' => hash('sha256', self::PATH),
            'relative_path_sha256' => hash('sha256', 'bucket/ab/cd/object'),
            'multipart_scope_sha256' => null,
            'type' => 'file',
            'size_bytes' => 12,
            'format' => 'opaque_unclassified',
        ], $changes);
    }
}
