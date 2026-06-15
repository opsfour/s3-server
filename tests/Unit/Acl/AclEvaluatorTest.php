<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Acl;

use OpsFour\S3Server\Acl\AclEvaluator;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;

final class AclEvaluatorTest extends TestCase
{
    private const string ALL_USERS = 'http://acs.amazonaws.com/groups/global/AllUsers';
    private const string AUTH_USERS = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    // -----------------------------------------------------------------
    // Owner Bypass
    // -----------------------------------------------------------------

    public function test_owner_always_allowed(): void
    {
        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'owner-1',
            ownerId: 'owner-1',
            grants: [],
        );

        $this->assertTrue($result);
    }

    public function test_empty_requester_id_blocks_owner_bypass(): void
    {
        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: '',
            ownerId: '',
            grants: [],
        );

        $this->assertFalse($result);
    }

    public function test_non_owner_without_grants_denied(): void
    {
        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: [],
        );

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------
    // Operation-to-Permission Mapping
    // -----------------------------------------------------------------

    #[DataProvider('readOperationsProvider')]
    public function test_read_operations(S3Operation $operation): void
    {
        $this->assertSame('READ', AclEvaluator::operationToPermission($operation));
    }

    public static function readOperationsProvider(): iterable
    {
        yield 'GetObject' => [S3Operation::GetObject];
        yield 'HeadObject' => [S3Operation::HeadObject];
        yield 'ListObjectsV2' => [S3Operation::ListObjectsV2];
        yield 'ListObjects' => [S3Operation::ListObjects];
        yield 'ListObjectVersions' => [S3Operation::ListObjectVersions];
    }

    #[DataProvider('writeOperationsProvider')]
    public function test_write_operations(S3Operation $operation): void
    {
        $this->assertSame('WRITE', AclEvaluator::operationToPermission($operation));
    }

    public static function writeOperationsProvider(): iterable
    {
        yield 'PutObject' => [S3Operation::PutObject];
        yield 'DeleteObject' => [S3Operation::DeleteObject];
        yield 'DeleteObjects' => [S3Operation::DeleteObjects];
        yield 'CopyObject' => [S3Operation::CopyObject];
        yield 'CreateMultipartUpload' => [S3Operation::CreateMultipartUpload];
        yield 'UploadPart' => [S3Operation::UploadPart];
        yield 'UploadPartCopy' => [S3Operation::UploadPartCopy];
        yield 'CompleteMultipartUpload' => [S3Operation::CompleteMultipartUpload];
        yield 'AbortMultipartUpload' => [S3Operation::AbortMultipartUpload];
    }

    #[DataProvider('readAcpOperationsProvider')]
    public function test_read_acp_operations(S3Operation $operation): void
    {
        $this->assertSame('READ_ACP', AclEvaluator::operationToPermission($operation));
    }

    public static function readAcpOperationsProvider(): iterable
    {
        yield 'GetBucketAcl' => [S3Operation::GetBucketAcl];
        yield 'GetObjectAcl' => [S3Operation::GetObjectAcl];
    }

    #[DataProvider('writeAcpOperationsProvider')]
    public function test_write_acp_operations(S3Operation $operation): void
    {
        $this->assertSame('WRITE_ACP', AclEvaluator::operationToPermission($operation));
    }

    public static function writeAcpOperationsProvider(): iterable
    {
        yield 'PutBucketAcl' => [S3Operation::PutBucketAcl];
        yield 'PutObjectAcl' => [S3Operation::PutObjectAcl];
    }

    #[DataProvider('configOperationsProvider')]
    public function test_config_operations_return_null(S3Operation $operation): void
    {
        $this->assertNull(AclEvaluator::operationToPermission($operation));
    }

    public static function configOperationsProvider(): iterable
    {
        yield 'CreateBucket' => [S3Operation::CreateBucket];
        yield 'GetBucketVersioning' => [S3Operation::GetBucketVersioning];
        yield 'PutBucketPolicy' => [S3Operation::PutBucketPolicy];
        yield 'PutBucketVersioning' => [S3Operation::PutBucketVersioning];
        yield 'ListBuckets' => [S3Operation::ListBuckets];
    }

    // -----------------------------------------------------------------
    // CanonicalUser Grants
    // -----------------------------------------------------------------

    public function test_canonical_user_exact_match_allows(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        );

        $this->assertTrue($result);
    }

    public function test_canonical_user_wrong_user_denied(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-b', 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        );

        $this->assertFalse($result);
    }

    public function test_canonical_user_wrong_permission_denied(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::PutObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        );

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------
    // Group Grants
    // -----------------------------------------------------------------

    public function test_all_users_allows_unauthenticated(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS, 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'anon',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: false,
        );

        $this->assertTrue($result);
    }

    public function test_all_users_allows_authenticated(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS, 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: true,
        );

        $this->assertTrue($result);
    }

    public function test_authenticated_users_allows_authenticated(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => self::AUTH_USERS, 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: true,
        );

        $this->assertTrue($result);
    }

    public function test_authenticated_users_denies_unauthenticated(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => self::AUTH_USERS, 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'anon',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: false,
        );

        $this->assertFalse($result);
    }

    public function test_unknown_group_uri_denied(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => 'http://invalid/group', 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        );

        $this->assertFalse($result);
    }

    // -----------------------------------------------------------------
    // FULL_CONTROL
    // -----------------------------------------------------------------

    public function test_full_control_covers_read(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'FULL_CONTROL'],
        ];

        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    public function test_full_control_covers_write(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'FULL_CONTROL'],
        ];

        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::PutObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    public function test_full_control_covers_read_acp(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'FULL_CONTROL'],
        ];

        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::GetBucketAcl,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    public function test_full_control_covers_write_acp(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'FULL_CONTROL'],
        ];

        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::PutBucketAcl,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    // -----------------------------------------------------------------
    // ignorePublicAcls
    // -----------------------------------------------------------------

    public function test_ignore_public_acls_skips_all_users(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS, 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: true,
            ignorePublicAcls: true,
        );

        $this->assertFalse($result);
    }

    public function test_ignore_public_acls_skips_authenticated_users(): void
    {
        $grants = [
            ['granteeType' => 'Group', 'granteeId' => self::AUTH_USERS, 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: true,
            ignorePublicAcls: true,
        );

        $this->assertFalse($result);
    }

    public function test_ignore_public_acls_does_not_skip_canonical_user(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'READ'],
        ];

        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
            isAuthenticated: true,
            ignorePublicAcls: true,
        );

        $this->assertTrue($result);
    }

    public function test_owner_bypass_with_ignore_public_acls(): void
    {
        $result = AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'owner-1',
            ownerId: 'owner-1',
            grants: [],
            ignorePublicAcls: true,
        );

        $this->assertTrue($result);
    }

    // -----------------------------------------------------------------
    // Multiple Grants
    // -----------------------------------------------------------------

    public function test_first_matching_grant_wins(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-a', 'permission' => 'READ'],
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-b', 'permission' => 'READ'],
        ];

        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    public function test_no_match_across_all_grants(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-b', 'permission' => 'READ'],
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-c', 'permission' => 'WRITE'],
        ];

        $this->assertFalse(AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    public function test_mixed_grant_types_group_matches(): void
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => 'user-b', 'permission' => 'READ'],
            ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS, 'permission' => 'READ'],
        ];

        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: $grants,
        ));
    }

    // -----------------------------------------------------------------
    // Edge Cases
    // -----------------------------------------------------------------

    public function test_empty_grants_non_owner_denied(): void
    {
        $this->assertFalse(AclEvaluator::isAllowed(
            operation: S3Operation::GetObject,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: [],
        ));
    }

    public function test_null_returning_operation_always_allowed(): void
    {
        $this->assertTrue(AclEvaluator::isAllowed(
            operation: S3Operation::CreateBucket,
            requesterId: 'user-a',
            ownerId: 'owner-1',
            grants: [],
        ));
    }
}
