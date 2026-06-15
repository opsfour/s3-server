<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use OpsFour\S3Server\Exception\MethodNotAllowedException;

/**
 * Resolves an HTTP method + scope + query params + headers to an S3Operation.
 *
 * This is the second tier of the two-tier routing system. The Amp Router
 * handles the first tier (path-based dispatch to S3Router), and this class
 * performs fine-grained operation resolution based on:
 *
 * - HTTP method (GET, PUT, DELETE, HEAD, POST)
 * - Scope (service, bucket, object)
 * - Query parameter key existence (e.g., ?acl, ?tagging, ?uploads)
 * - Query parameter values (e.g., list-type=2)
 * - Header presence (e.g., x-amz-copy-source)
 *
 * Dispatch tables are priority-ordered: first match wins.
 */
final class OperationResolver
{
    /**
     * Resolve the S3 operation from request components.
     *
     * @param  string  $method  HTTP method (GET, PUT, DELETE, HEAD, POST).
     * @param  string  $scope  One of 'service', 'bucket', or 'object'.
     * @param  array<string, mixed>  $queryParams  Query parameter keys (values checked only where needed).
     * @param  array<string, string>  $headers  Lowercase header names to values.
     * @return S3Operation The resolved operation.
     *
     * @throws \InvalidArgumentException If the method/scope combination cannot be resolved.
     */
    public static function resolve(string $method, string $scope, array $queryParams, array $headers): S3Operation
    {
        return match ($scope) {
            'service' => self::resolveService($method),
            'bucket' => self::resolveBucket($method, $queryParams),
            'object' => self::resolveObject($method, $queryParams, $headers),
            default => throw new MethodNotAllowedException("Unknown scope: {$scope}"),
        };
    }

    /**
     * Service-level: only GET / is valid (ListBuckets).
     */
    private static function resolveService(string $method): S3Operation
    {
        return match ($method) {
            'GET' => S3Operation::ListBuckets,
            default => throw new MethodNotAllowedException(
                "Unsupported method '{$method}' for service scope.",
            ),
        };
    }

    /**
     * Bucket-level dispatch.
     *
     * Priority order follows the S3 API dispatch convention:
     * - PUT/DELETE/HEAD with no query params match the bucket CRUD operations immediately.
     * - For methods with query params, check specific keys first, then fall through to defaults.
     *
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveBucket(string $method, array $queryParams): S3Operation
    {
        return match ($method) {
            'PUT' => self::resolveBucketPut($queryParams),
            'DELETE' => self::resolveBucketDelete($queryParams),
            'HEAD' => S3Operation::HeadBucket,
            'GET' => self::resolveBucketGet($queryParams),
            'POST' => self::resolveBucketPost($queryParams),
            default => throw new MethodNotAllowedException(
                "Unsupported method '{$method}' for bucket scope.",
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveBucketPut(array $queryParams): S3Operation
    {
        if (\array_key_exists('versioning', $queryParams)) {
            return S3Operation::PutBucketVersioning;
        }
        if (\array_key_exists('acl', $queryParams)) {
            return S3Operation::PutBucketAcl;
        }
        if (\array_key_exists('policy', $queryParams)) {
            return S3Operation::PutBucketPolicy;
        }
        if (\array_key_exists('cors', $queryParams)) {
            return S3Operation::PutBucketCors;
        }
        if (\array_key_exists('tagging', $queryParams)) {
            return S3Operation::PutBucketTagging;
        }
        if (\array_key_exists('lifecycle', $queryParams)) {
            return S3Operation::PutBucketLifecycle;
        }
        if (\array_key_exists('notification', $queryParams)) {
            return S3Operation::PutBucketNotification;
        }
        if (\array_key_exists('encryption', $queryParams)) {
            return S3Operation::PutBucketEncryption;
        }
        if (\array_key_exists('object-lock', $queryParams)) {
            return S3Operation::PutObjectLockConfig;
        }
        if (\array_key_exists('website', $queryParams)) {
            return S3Operation::PutBucketWebsite;
        }
        if (\array_key_exists('publicAccessBlock', $queryParams)) {
            return S3Operation::PutPublicAccessBlock;
        }
        if (\array_key_exists('logging', $queryParams)) {
            return S3Operation::PutBucketLogging;
        }

        // No recognized query key: CreateBucket
        return S3Operation::CreateBucket;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveBucketDelete(array $queryParams): S3Operation
    {
        if (\array_key_exists('policy', $queryParams)) {
            return S3Operation::DeleteBucketPolicy;
        }
        if (\array_key_exists('cors', $queryParams)) {
            return S3Operation::DeleteBucketCors;
        }
        if (\array_key_exists('tagging', $queryParams)) {
            return S3Operation::DeleteBucketTagging;
        }
        if (\array_key_exists('lifecycle', $queryParams)) {
            return S3Operation::DeleteBucketLifecycle;
        }
        if (\array_key_exists('encryption', $queryParams)) {
            return S3Operation::DeleteBucketEncryption;
        }
        if (\array_key_exists('website', $queryParams)) {
            return S3Operation::DeleteBucketWebsite;
        }
        if (\array_key_exists('publicAccessBlock', $queryParams)) {
            return S3Operation::DeletePublicAccessBlock;
        }

        // No recognized query key: DeleteBucket
        return S3Operation::DeleteBucket;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveBucketGet(array $queryParams): S3Operation
    {
        if (\array_key_exists('location', $queryParams)) {
            return S3Operation::GetBucketLocation;
        }
        if (\array_key_exists('versioning', $queryParams)) {
            return S3Operation::GetBucketVersioning;
        }
        if (\array_key_exists('acl', $queryParams)) {
            return S3Operation::GetBucketAcl;
        }
        if (\array_key_exists('policy', $queryParams)) {
            return S3Operation::GetBucketPolicy;
        }
        if (\array_key_exists('cors', $queryParams)) {
            return S3Operation::GetBucketCors;
        }
        if (\array_key_exists('tagging', $queryParams)) {
            return S3Operation::GetBucketTagging;
        }
        if (\array_key_exists('lifecycle', $queryParams)) {
            return S3Operation::GetBucketLifecycle;
        }
        if (\array_key_exists('notification', $queryParams)) {
            return S3Operation::GetBucketNotification;
        }
        if (\array_key_exists('encryption', $queryParams)) {
            return S3Operation::GetBucketEncryption;
        }
        if (\array_key_exists('object-lock', $queryParams)) {
            return S3Operation::GetObjectLockConfig;
        }
        if (\array_key_exists('uploads', $queryParams)) {
            return S3Operation::ListMultipartUploads;
        }
        if (\array_key_exists('versions', $queryParams)) {
            return S3Operation::ListObjectVersions;
        }
        if (\array_key_exists('website', $queryParams)) {
            return S3Operation::GetBucketWebsite;
        }
        if (\array_key_exists('publicAccessBlock', $queryParams)) {
            return S3Operation::GetPublicAccessBlock;
        }
        if (\array_key_exists('policyStatus', $queryParams)) {
            return S3Operation::GetBucketPolicyStatus;
        }
        if (\array_key_exists('logging', $queryParams)) {
            return S3Operation::GetBucketLogging;
        }
        if (\array_key_exists('list-type', $queryParams) && (string) ($queryParams['list-type'] ?? '') === '2') {
            return S3Operation::ListObjectsV2;
        }

        // Default GET on bucket: ListObjects (v1)
        return S3Operation::ListObjects;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveBucketPost(array $queryParams): S3Operation
    {
        if (\array_key_exists('delete', $queryParams)) {
            return S3Operation::DeleteObjects;
        }

        // POST to /{bucket} without query params = POST Object (HTML form upload).
        return S3Operation::PostObject;
    }

    /**
     * Object-level dispatch.
     *
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, string>  $headers  Lowercase header names to values.
     */
    private static function resolveObject(string $method, array $queryParams, array $headers): S3Operation
    {
        return match ($method) {
            'GET' => self::resolveObjectGet($queryParams),
            'PUT' => self::resolveObjectPut($queryParams, $headers),
            'DELETE' => self::resolveObjectDelete($queryParams),
            'HEAD' => S3Operation::HeadObject,
            'POST' => self::resolveObjectPost($queryParams),
            default => throw new MethodNotAllowedException(
                "Unsupported method '{$method}' for object scope.",
            ),
        };
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveObjectGet(array $queryParams): S3Operation
    {
        if (\array_key_exists('acl', $queryParams)) {
            return S3Operation::GetObjectAcl;
        }
        if (\array_key_exists('tagging', $queryParams)) {
            return S3Operation::GetObjectTagging;
        }
        if (\array_key_exists('retention', $queryParams)) {
            return S3Operation::GetObjectRetention;
        }
        if (\array_key_exists('legal-hold', $queryParams)) {
            return S3Operation::GetObjectLegalHold;
        }
        if (\array_key_exists('uploadId', $queryParams)) {
            return S3Operation::ListParts;
        }
        if (\array_key_exists('attributes', $queryParams)) {
            return S3Operation::GetObjectAttributes;
        }

        // Default: GetObject
        return S3Operation::GetObject;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, string>  $headers
     */
    private static function resolveObjectPut(array $queryParams, array $headers): S3Operation
    {
        if (\array_key_exists('acl', $queryParams)) {
            return S3Operation::PutObjectAcl;
        }
        if (\array_key_exists('tagging', $queryParams)) {
            return S3Operation::PutObjectTagging;
        }
        if (\array_key_exists('retention', $queryParams)) {
            return S3Operation::PutObjectRetention;
        }
        if (\array_key_exists('legal-hold', $queryParams)) {
            return S3Operation::PutObjectLegalHold;
        }
        if (\array_key_exists('partNumber', $queryParams) && \array_key_exists('uploadId', $queryParams)) {
            // UploadPartCopy vs UploadPart: distinguished by x-amz-copy-source header
            if (\array_key_exists('x-amz-copy-source', $headers)) {
                return S3Operation::UploadPartCopy;
            }

            return S3Operation::UploadPart;
        }

        // CopyObject vs PutObject: distinguished by x-amz-copy-source header
        if (\array_key_exists('x-amz-copy-source', $headers)) {
            return S3Operation::CopyObject;
        }

        // Default: PutObject
        return S3Operation::PutObject;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveObjectDelete(array $queryParams): S3Operation
    {
        if (\array_key_exists('tagging', $queryParams)) {
            return S3Operation::DeleteObjectTagging;
        }
        if (\array_key_exists('uploadId', $queryParams)) {
            return S3Operation::AbortMultipartUpload;
        }

        // Default: DeleteObject
        return S3Operation::DeleteObject;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveObjectPost(array $queryParams): S3Operation
    {
        if (\array_key_exists('restore', $queryParams)) {
            return S3Operation::RestoreObject;
        }
        if (\array_key_exists('uploads', $queryParams)) {
            return S3Operation::CreateMultipartUpload;
        }
        if (\array_key_exists('uploadId', $queryParams)) {
            return S3Operation::CompleteMultipartUpload;
        }
        if (\array_key_exists('select', $queryParams) && \array_key_exists('select-type', $queryParams)) {
            return S3Operation::SelectObjectContent;
        }

        throw new MethodNotAllowedException(
            'Unsupported POST on object scope without recognized query parameter.',
        );
    }
}
