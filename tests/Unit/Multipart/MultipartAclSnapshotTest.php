<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Multipart;

use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Multipart\MultipartAclSnapshot;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class MultipartAclSnapshotTest extends TestCase
{
    public function test_valid_snapshot_is_normalized_and_validated(): void
    {
        $grants = AclGrantResolver::privateAcl('owner');

        self::assertSame($grants, MultipartAclSnapshot::decode(json_encode($grants, JSON_THROW_ON_ERROR)));
        self::assertSame($grants, MultipartAclSnapshot::fromDecoded($grants));
    }

    public function test_missing_snapshot_falls_back_only_for_genuinely_legacy_metadata(): void
    {
        self::assertSame(
            AclGrantResolver::privateAcl('legacy-owner'),
            MultipartAclSnapshot::fromMetadata([], 'legacy-owner'),
        );

        foreach ([
            ['__mpu-policy-context-version' => '1'],
            [MultipartDestinationOwnershipSnapshot::METADATA_KEY => '{}'],
        ] as $metadata) {
            try {
                MultipartAclSnapshot::fromMetadata($metadata, 'owner');
                self::fail('A current-format upload without its ACL snapshot was accepted.');
            } catch (InternalErrorException $error) {
                self::assertSame('Multipart upload ACL snapshot is missing.', $error->getMessage());
            }
        }
    }

    /** @return iterable<string, array{mixed}> */
    public static function invalidDecodedSnapshots(): iterable
    {
        yield 'scalar' => ['invalid'];
        yield 'map instead of list' => [['granteeType' => 'CanonicalUser']];
        yield 'empty list' => [[]];
        yield 'missing field' => [[[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'owner',
        ]]];
        yield 'extra field' => [[[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'owner',
            'permission' => 'FULL_CONTROL',
            'extra' => 'value',
        ]]];
        yield 'non-string field' => [[[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 42,
            'permission' => 'FULL_CONTROL',
        ]]];
        yield 'invalid semantics' => [[[
            'granteeType' => 'CanonicalUser',
            'granteeId' => 'owner',
            'permission' => 'FUTURE_PERMISSION',
        ]]];
        yield 'XML-invalid canonical ID' => [[[
            'granteeType' => 'CanonicalUser',
            'granteeId' => "owner-\u{FFFF}",
            'permission' => 'FULL_CONTROL',
        ]]];
    }

    #[DataProvider('invalidDecodedSnapshots')]
    public function test_invalid_decoded_snapshot_fails_as_stored_corruption(mixed $value): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Multipart upload ACL snapshot is invalid.');

        MultipartAclSnapshot::fromDecoded($value);
    }

    public function test_invalid_json_fails_as_stored_corruption(): void
    {
        $this->expectException(InternalErrorException::class);
        $this->expectExceptionMessage('Multipart upload ACL snapshot is invalid.');

        MultipartAclSnapshot::decode('{');
    }

    public function test_json_object_with_numeric_keys_is_not_accepted_as_a_list(): void
    {
        $this->expectException(InternalErrorException::class);

        MultipartAclSnapshot::decode(json_encode([
            '0' => (object) AclGrantResolver::privateAcl('owner')[0],
        ], JSON_FORCE_OBJECT | JSON_THROW_ON_ERROR));
    }

    public function test_oversized_snapshot_is_rejected_before_json_decoding(): void
    {
        $this->expectException(InternalErrorException::class);

        MultipartAclSnapshot::decode(str_repeat(' ', 200_001));
    }

    public function test_expected_owner_must_retain_canonical_full_control(): void
    {
        $this->expectException(InternalErrorException::class);

        MultipartAclSnapshot::decode(json_encode(
            AclGrantResolver::privateAcl('different-owner'),
            JSON_THROW_ON_ERROR,
        ), 'owner');
    }
}
