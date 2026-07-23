<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Acl;

use OpsFour\S3Server\Routing\S3Operation;

/**
 * Evaluates ACL grants against S3 operations.
 *
 * Maps operations to required permissions and checks if the
 * requestor has the necessary grant.
 */
final class AclEvaluator
{
    private const string ALL_USERS = 'http://acs.amazonaws.com/groups/global/AllUsers';
    private const string AUTH_USERS = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    /**
     * Check if the given grants allow the operation.
     *
     * @param S3Operation $operation The S3 operation being performed.
     * @param string $requesterId The ID of the requester.
     * @param string $ownerId The resource owner ID.
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $grants
     * @param bool $isAuthenticated Whether the requester is authenticated.
     * @param bool $ignorePublicAcls If true, strip AllUsers/AuthenticatedUsers grants.
     * @return bool True if the operation is allowed.
     */
    public static function isAllowed(
        S3Operation $operation,
        string $requesterId,
        string $ownerId,
        array $grants,
        bool $isAuthenticated = true,
        bool $ignorePublicAcls = false,
    ): bool {
        // Owner always has FULL_CONTROL.
        if ($requesterId === $ownerId && $requesterId !== '') {
            return true;
        }

        $requiredPermission = self::operationToPermission($operation);
        if ($requiredPermission === null) {
            // Operations without an ACL permission are owner/policy-only.
            // The owner bypass and explicit policy allow are handled before
            // this evaluator is called.
            return false;
        }

        foreach ($grants as $grant) {
            // Skip public grants if ignorePublicAcls is set.
            if ($ignorePublicAcls && $grant['granteeType'] === 'Group'
                && in_array($grant['granteeId'], [self::ALL_USERS, self::AUTH_USERS], true)) {
                continue;
            }

            if (!self::grantMatchesRequester($grant, $requesterId, $isAuthenticated)) {
                continue;
            }

            if (self::permissionIncludes($grant['permission'], $requiredPermission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Map an S3 operation to the required ACL permission.
     */
    public static function operationToPermission(S3Operation $operation): ?string
    {
        return match ($operation) {
            S3Operation::GetObject, S3Operation::HeadObject,
            S3Operation::SelectObjectContent, S3Operation::GetObjectAttributes,
            S3Operation::HeadBucket,
            S3Operation::ListObjectsV2, S3Operation::ListObjects,
            S3Operation::ListObjectVersions => 'READ',

            S3Operation::PutObject, S3Operation::PostObject,
            S3Operation::DeleteObject,
            S3Operation::DeleteObjects, S3Operation::CopyObject,
            S3Operation::CreateMultipartUpload, S3Operation::UploadPart,
            S3Operation::UploadPartCopy, S3Operation::CompleteMultipartUpload,
            S3Operation::AbortMultipartUpload => 'WRITE',

            S3Operation::GetBucketAcl, S3Operation::GetObjectAcl => 'READ_ACP',
            S3Operation::PutBucketAcl, S3Operation::PutObjectAcl => 'WRITE_ACP',

            // Service, bucket configuration, object subresource, restore, and
            // multipart-listing operations require owner access or an explicit
            // identity/resource policy allow. Listing every case makes a newly
            // added operation fail loudly until its authorization is defined.
            S3Operation::ListBuckets,
            S3Operation::CreateBucket,
            S3Operation::DeleteBucket,
            S3Operation::GetBucketLocation,
            S3Operation::GetBucketVersioning,
            S3Operation::PutBucketVersioning,
            S3Operation::GetBucketPolicy,
            S3Operation::PutBucketPolicy,
            S3Operation::DeleteBucketPolicy,
            S3Operation::GetBucketCors,
            S3Operation::PutBucketCors,
            S3Operation::DeleteBucketCors,
            S3Operation::GetBucketTagging,
            S3Operation::PutBucketTagging,
            S3Operation::DeleteBucketTagging,
            S3Operation::GetBucketLifecycle,
            S3Operation::PutBucketLifecycle,
            S3Operation::DeleteBucketLifecycle,
            S3Operation::GetBucketNotification,
            S3Operation::PutBucketNotification,
            S3Operation::GetBucketEncryption,
            S3Operation::PutBucketEncryption,
            S3Operation::DeleteBucketEncryption,
            S3Operation::GetObjectLockConfig,
            S3Operation::PutObjectLockConfig,
            S3Operation::ListMultipartUploads,
            S3Operation::GetBucketWebsite,
            S3Operation::PutBucketWebsite,
            S3Operation::DeleteBucketWebsite,
            S3Operation::GetPublicAccessBlock,
            S3Operation::PutPublicAccessBlock,
            S3Operation::DeletePublicAccessBlock,
            S3Operation::GetBucketPolicyStatus,
            S3Operation::GetBucketLogging,
            S3Operation::PutBucketLogging,
            S3Operation::GetObjectTagging,
            S3Operation::PutObjectTagging,
            S3Operation::DeleteObjectTagging,
            S3Operation::GetObjectRetention,
            S3Operation::PutObjectRetention,
            S3Operation::GetObjectLegalHold,
            S3Operation::PutObjectLegalHold,
            S3Operation::RestoreObject,
            S3Operation::ListParts => null,
        };
    }

    /** @param array<string, string> $grant */
    private static function grantMatchesRequester(array $grant, string $requesterId, bool $isAuthenticated): bool
    {
        if ($grant['granteeType'] === 'CanonicalUser') {
            return $grant['granteeId'] === $requesterId;
        }

        if ($grant['granteeType'] === 'Group') {
            if ($grant['granteeId'] === self::ALL_USERS) {
                return true;
            }
            if ($grant['granteeId'] === self::AUTH_USERS && $isAuthenticated) {
                return true;
            }
        }

        return false;
    }

    private static function permissionIncludes(string $grantedPermission, string $requiredPermission): bool
    {
        if ($grantedPermission === 'FULL_CONTROL') {
            return true;
        }

        return $grantedPermission === $requiredPermission;
    }
}
