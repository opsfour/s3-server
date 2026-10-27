<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Multipart;

use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Acl\ObjectWriteOwnershipDecision;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartDestinationOwnershipSnapshotTest extends TestCase
{
    public function test_round_trip_preserves_the_complete_destination_decision(): void
    {
        $decision = new ObjectWriteOwnershipDecision(
            'bucket-owner',
            AclGrantResolver::privateAcl('bucket-owner'),
            'bucket-owner',
            BucketOwnership::BUCKET_OWNER_PREFERRED,
        );

        $encoded = MultipartDestinationOwnershipSnapshot::fromDecision(
            $decision,
            'bucket-owner-full-control',
        )->encode();
        $decoded = MultipartDestinationOwnershipSnapshot::decode([
            MultipartDestinationOwnershipSnapshot::METADATA_KEY => $encoded,
            '__mpu-acl-header' => 'bucket-owner-full-control',
            '__mpu-acl-grants' => json_encode(
                AclGrantResolver::privateAcl('writer'),
                JSON_THROW_ON_ERROR,
            ),
        ]);

        self::assertNotNull($decoded);
        self::assertSame('bucket-owner', $decoded->ownerId);
        self::assertSame($decision->aclGrants, $decoded->aclGrants);
        self::assertSame('bucket-owner', $decoded->quotaPrincipalId);
        self::assertSame(BucketOwnership::BUCKET_OWNER_PREFERRED, $decoded->ownershipMode);
        self::assertSame('bucket-owner-full-control', $decoded->cannedAcl);
    }

    public function test_absent_marker_is_the_only_legacy_signal(): void
    {
        self::assertNull(MultipartDestinationOwnershipSnapshot::decode(['ordinary' => 'metadata']));
    }

    public function test_canned_acl_must_match_the_independent_policy_context_snapshot(): void
    {
        $decision = new ObjectWriteOwnershipDecision(
            'bucket-owner',
            AclGrantResolver::privateAcl('bucket-owner'),
            'bucket-owner',
            BucketOwnership::BUCKET_OWNER_PREFERRED,
        );
        $encoded = MultipartDestinationOwnershipSnapshot::fromDecision(
            $decision,
            'bucket-owner-full-control',
        )->encode();

        $this->expectException(InternalErrorException::class);
        MultipartDestinationOwnershipSnapshot::decode([
            MultipartDestinationOwnershipSnapshot::METADATA_KEY => $encoded,
            '__mpu-acl-header' => 'private',
            '__mpu-acl-grants' => json_encode(
                AclGrantResolver::privateAcl('bucket-owner'),
                JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    public function test_writer_owned_snapshot_must_match_the_initiation_acl(): void
    {
        $snapshotAcl = AclGrantResolver::privateAcl('writer');
        $snapshotAcl[] = [
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'reader',
            'permission' => 'READ',
        ];
        $encoded = MultipartDestinationOwnershipSnapshot::fromDecision(
            new ObjectWriteOwnershipDecision(
                'writer',
                $snapshotAcl,
                'writer',
                BucketOwnership::OBJECT_WRITER,
            ),
            null,
        )->encode();

        $this->expectException(InternalErrorException::class);
        MultipartDestinationOwnershipSnapshot::decode([
            MultipartDestinationOwnershipSnapshot::METADATA_KEY => $encoded,
            '__mpu-acl-grants' => json_encode(
                AclGrantResolver::privateAcl('writer'),
                JSON_THROW_ON_ERROR,
            ),
        ]);
    }

    public function test_reordered_snapshot_and_grant_members_are_accepted(): void
    {
        $grant = [
            'permission' => 'FULL_CONTROL',
            'granteeId' => 'writer',
            'granteeType' => 'CanonicalUser',
        ];
        $encoded = json_encode([
            'cannedAcl' => null,
            'ownershipMode' => BucketOwnership::OBJECT_WRITER,
            'quotaPrincipalId' => 'writer',
            'aclGrants' => [$grant],
            'ownerId' => 'writer',
            'version' => 1,
        ], JSON_THROW_ON_ERROR);

        $snapshot = MultipartDestinationOwnershipSnapshot::decode([
            MultipartDestinationOwnershipSnapshot::METADATA_KEY => $encoded,
            '__mpu-acl-grants' => json_encode([$grant], JSON_THROW_ON_ERROR),
        ]);

        self::assertNotNull($snapshot);
        self::assertSame('writer', $snapshot->ownerId);
    }

    /**
     * @return iterable<string, array{string, ?string, string, string, list<array{granteeType: string, granteeId: string, permission: string}>}>
     */
    public static function inconsistentSnapshots(): iterable
    {
        yield 'ObjectWriter uses bucket owner principal' => [
            BucketOwnership::OBJECT_WRITER,
            null,
            'bucket-owner',
            'bucket-owner',
            AclGrantResolver::privateAcl('writer'),
        ];
        yield 'non-transferring Preferred uses bucket owner principal' => [
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'private',
            'bucket-owner',
            'bucket-owner',
            AclGrantResolver::privateAcl('writer'),
        ];
        yield 'transferring Preferred keeps initiator principal' => [
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'bucket-owner-full-control',
            'writer',
            'writer',
            AclGrantResolver::privateAcl('bucket-owner'),
        ];
        yield 'transferring Preferred keeps noncanonical ACL' => [
            BucketOwnership::BUCKET_OWNER_PREFERRED,
            'bucket-owner-full-control',
            'bucket-owner',
            'bucket-owner',
            AclGrantResolver::privateAcl('writer'),
        ];
        yield 'Enforced keeps initiator principal' => [
            BucketOwnership::BUCKET_OWNER_ENFORCED,
            null,
            'writer',
            'writer',
            AclGrantResolver::privateAcl('bucket-owner'),
        ];
        yield 'Enforced records unsupported canned ACL' => [
            BucketOwnership::BUCKET_OWNER_ENFORCED,
            'private',
            'bucket-owner',
            'bucket-owner',
            AclGrantResolver::privateAcl('bucket-owner'),
        ];
    }

    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants
     */
    #[DataProvider('inconsistentSnapshots')]
    public function test_structurally_valid_but_semantically_inconsistent_snapshot_fails_closed(
        string $mode,
        ?string $cannedAcl,
        string $ownerId,
        string $quotaPrincipalId,
        array $aclGrants,
    ): void {
        $encoded = MultipartDestinationOwnershipSnapshot::fromDecision(
            new ObjectWriteOwnershipDecision($ownerId, $aclGrants, $quotaPrincipalId, $mode),
            $cannedAcl,
        )->encode();
        $metadata = [MultipartDestinationOwnershipSnapshot::METADATA_KEY => $encoded];
        $metadata['__mpu-acl-grants'] = json_encode(
            AclGrantResolver::privateAcl('writer'),
            JSON_THROW_ON_ERROR,
        );
        if ($cannedAcl !== null) {
            $metadata['__mpu-acl-header'] = $cannedAcl;
        }
        $snapshot = MultipartDestinationOwnershipSnapshot::decode($metadata);
        self::assertNotNull($snapshot);

        $this->expectException(InternalErrorException::class);
        $snapshot->assertConsistent('writer', 'bucket-owner');
    }

    /** @return iterable<string, array{string}> */
    public static function corruptRecords(): iterable
    {
        yield 'invalid json' => ['{'];
        yield 'wrong version' => [json_encode([
            'version' => 2,
            'ownerId' => 'owner',
            'aclGrants' => AclGrantResolver::privateAcl('owner'),
            'quotaPrincipalId' => 'owner',
            'ownershipMode' => BucketOwnership::OBJECT_WRITER,
            'cannedAcl' => null,
        ], JSON_THROW_ON_ERROR)];
        yield 'missing field' => [json_encode(['version' => 1], JSON_THROW_ON_ERROR)];
        yield 'invalid ACL' => [json_encode([
            'version' => 1,
            'ownerId' => 'owner',
            'aclGrants' => [],
            'quotaPrincipalId' => 'owner',
            'ownershipMode' => BucketOwnership::OBJECT_WRITER,
            'cannedAcl' => null,
        ], JSON_THROW_ON_ERROR)];
        yield 'unknown mode' => [json_encode([
            'version' => 1,
            'ownerId' => 'owner',
            'aclGrants' => AclGrantResolver::privateAcl('owner'),
            'quotaPrincipalId' => 'owner',
            'ownershipMode' => 'future-mode',
            'cannedAcl' => null,
        ], JSON_THROW_ON_ERROR)];
        yield 'extra field' => [json_encode([
            'version' => 1,
            'ownerId' => 'owner',
            'aclGrants' => AclGrantResolver::privateAcl('owner'),
            'quotaPrincipalId' => 'owner',
            'ownershipMode' => BucketOwnership::OBJECT_WRITER,
            'cannedAcl' => null,
            'extra' => true,
        ], JSON_THROW_ON_ERROR)];
    }

    #[DataProvider('corruptRecords')]
    public function test_present_invalid_marker_fails_closed(string $encoded): void
    {
        $this->expectException(InternalErrorException::class);
        MultipartDestinationOwnershipSnapshot::decode([
            MultipartDestinationOwnershipSnapshot::METADATA_KEY => $encoded,
            '__mpu-acl-grants' => json_encode(
                AclGrantResolver::privateAcl('owner'),
                JSON_THROW_ON_ERROR,
            ),
        ]);
    }
}
