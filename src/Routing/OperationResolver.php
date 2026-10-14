<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Routing;

use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NotImplementedException;

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
    /** @var array<string, list<string>> */
    private const array BUCKET_SUBRESOURCE_METHODS = [
        'ownershipControls' => ['GET', 'PUT', 'DELETE'],
        'versioning' => ['GET', 'PUT'],
        'acl' => ['GET', 'PUT'],
        'policy' => ['GET', 'PUT', 'DELETE'],
        'cors' => ['GET', 'PUT', 'DELETE'],
        'tagging' => ['GET', 'PUT', 'DELETE'],
        'lifecycle' => ['GET', 'PUT', 'DELETE'],
        'notification' => ['GET', 'PUT'],
        'encryption' => ['GET', 'PUT', 'DELETE'],
        'object-lock' => ['GET', 'PUT'],
        'website' => ['GET', 'PUT', 'DELETE'],
        'publicAccessBlock' => ['GET', 'PUT', 'DELETE'],
        'logging' => ['GET', 'PUT'],
        'location' => ['GET'],
        'uploads' => ['GET'],
        'versions' => ['GET'],
        'policyStatus' => ['GET'],
        'delete' => ['POST'],
    ];

    /** @var list<string> */
    private const array UNSUPPORTED_BUCKET_SUBRESOURCES = [
        'abac',
        'accelerate',
        'analytics',
        'intelligent-tiering',
        'inventory',
        'metadataAnnotationTable',
        'metadataConfiguration',
        'metadataInventoryTable',
        'metadataJournalTable',
        'metadataTable',
        'metrics',
        'replication',
        'requestPayment',
        'session',
    ];

    /** @var array<string, list<string>> */
    private const array BUCKET_QUERY_MODIFIER_METHODS = [
        'list-type' => ['GET'],
        'continuation-token' => ['GET'],
        'delimiter' => ['GET'],
        'encoding-type' => ['GET'],
        'fetch-owner' => ['GET'],
        'key-marker' => ['GET'],
        'marker' => ['GET'],
        'max-keys' => ['GET'],
        'max-uploads' => ['GET'],
        'prefix' => ['GET'],
        'start-after' => ['GET'],
        'upload-id-marker' => ['GET'],
        'version-id-marker' => ['GET'],
        'bucket-region' => [],
        'max-buckets' => [],
        'max-parts' => [],
        'part-number-marker' => [],
        'versionId' => [],
        'uploadId' => [],
        'partNumber' => [],
        'select-type' => [],
        'response-cache-control' => [],
        'response-content-disposition' => [],
        'response-content-encoding' => [],
        'response-content-language' => [],
        'response-content-type' => [],
        'response-expires' => [],
    ];

    /** @var array<string, list<S3Operation>> */
    private const array BUCKET_LISTING_MODIFIER_OPERATIONS = [
        'list-type' => [S3Operation::ListObjectsV2],
        'continuation-token' => [S3Operation::ListObjectsV2],
        'delimiter' => [S3Operation::ListObjects, S3Operation::ListObjectsV2, S3Operation::ListObjectVersions, S3Operation::ListMultipartUploads],
        'encoding-type' => [S3Operation::ListObjects, S3Operation::ListObjectsV2, S3Operation::ListObjectVersions, S3Operation::ListMultipartUploads],
        'fetch-owner' => [S3Operation::ListObjectsV2],
        'key-marker' => [S3Operation::ListObjectVersions, S3Operation::ListMultipartUploads],
        'marker' => [S3Operation::ListObjects],
        'max-keys' => [S3Operation::ListObjects, S3Operation::ListObjectsV2, S3Operation::ListObjectVersions],
        'max-uploads' => [S3Operation::ListMultipartUploads],
        'prefix' => [S3Operation::ListObjects, S3Operation::ListObjectsV2, S3Operation::ListObjectVersions, S3Operation::ListMultipartUploads],
        'start-after' => [S3Operation::ListObjectsV2],
        'upload-id-marker' => [S3Operation::ListMultipartUploads],
        'version-id-marker' => [S3Operation::ListObjectVersions],
    ];

    /** @var array<string, list<string>> */
    private const array OBJECT_SUBRESOURCE_METHODS = [
        'acl' => ['GET', 'PUT'],
        'tagging' => ['GET', 'PUT', 'DELETE'],
        'retention' => ['GET', 'PUT'],
        'legal-hold' => ['GET', 'PUT'],
        'uploadId' => ['GET', 'PUT', 'DELETE', 'POST'],
        'attributes' => ['GET'],
        'restore' => ['POST'],
        'uploads' => ['POST'],
        'select' => ['POST'],
    ];

    /** @var list<string> */
    private const array UNSUPPORTED_OBJECT_SUBRESOURCES = [
        'annotation',
        'encryption',
        'renameObject',
        'torrent',
    ];

    /** @var array<string, list<string>> */
    private const array OBJECT_QUERY_MODIFIER_METHODS = [
        'list-type' => [],
        'bucket-region' => [],
        'continuation-token' => [],
        'delimiter' => [],
        'encoding-type' => [],
        'fetch-owner' => [],
        'key-marker' => [],
        'marker' => [],
        'max-buckets' => [],
        'max-keys' => [],
        'max-uploads' => [],
        'prefix' => [],
        'start-after' => [],
        'upload-id-marker' => [],
        'version-id-marker' => [],
        'max-parts' => ['GET'],
        'part-number-marker' => ['GET'],
        'partNumber' => ['GET', 'HEAD', 'PUT'],
        'select-type' => ['POST'],
        'response-cache-control' => ['GET', 'HEAD'],
        'response-content-disposition' => ['GET', 'HEAD'],
        'response-content-encoding' => ['GET', 'HEAD'],
        'response-content-language' => ['GET', 'HEAD'],
        'response-content-type' => ['GET', 'HEAD'],
        'response-expires' => ['GET', 'HEAD'],
    ];

    /** @var array<string, list<S3Operation>> */
    private const array OBJECT_QUERY_MODIFIER_OPERATIONS = [
        'max-parts' => [S3Operation::ListParts],
        'part-number-marker' => [S3Operation::ListParts],
        'partNumber' => [S3Operation::GetObject, S3Operation::HeadObject, S3Operation::UploadPart, S3Operation::UploadPartCopy],
        'select-type' => [S3Operation::SelectObjectContent],
        'response-cache-control' => [S3Operation::GetObject, S3Operation::HeadObject],
        'response-content-disposition' => [S3Operation::GetObject, S3Operation::HeadObject],
        'response-content-encoding' => [S3Operation::GetObject, S3Operation::HeadObject],
        'response-content-language' => [S3Operation::GetObject, S3Operation::HeadObject],
        'response-content-type' => [S3Operation::GetObject, S3Operation::HeadObject],
        'response-expires' => [S3Operation::GetObject, S3Operation::HeadObject],
    ];

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
        self::assertQueryModifierMethods($method, $queryParams, self::BUCKET_QUERY_MODIFIER_METHODS);
        self::assertSubresourceRoutingIsValid(
            $method,
            $queryParams,
            self::BUCKET_SUBRESOURCE_METHODS,
            self::UNSUPPORTED_BUCKET_SUBRESOURCES,
        );
        self::assertSubresourceScopeIsValid(
            $queryParams,
            self::OBJECT_SUBRESOURCE_METHODS,
            self::UNSUPPORTED_OBJECT_SUBRESOURCES,
            self::BUCKET_SUBRESOURCE_METHODS,
        );
        $operation = match ($method) {
            'PUT' => self::resolveBucketPut($queryParams),
            'DELETE' => self::resolveBucketDelete($queryParams),
            'HEAD' => S3Operation::HeadBucket,
            'GET' => self::resolveBucketGet($queryParams),
            'POST' => self::resolveBucketPost($queryParams),
            default => throw new MethodNotAllowedException(
                "Unsupported method '{$method}' for bucket scope.",
            ),
        };
        self::assertQueryModifiersForOperation($queryParams, $operation, self::BUCKET_LISTING_MODIFIER_OPERATIONS);

        return $operation;
    }

    /**
     * @param  array<string, mixed>  $queryParams
     */
    private static function resolveBucketPut(array $queryParams): S3Operation
    {
        if (\array_key_exists('ownershipControls', $queryParams)) {
            return S3Operation::PutBucketOwnershipControls;
        }
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
        if (\array_key_exists('ownershipControls', $queryParams)) {
            return S3Operation::DeleteBucketOwnershipControls;
        }
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
        if (\array_key_exists('ownershipControls', $queryParams)) {
            return S3Operation::GetBucketOwnershipControls;
        }
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
        if (\array_key_exists('list-type', $queryParams)) {
            if (! is_string($queryParams['list-type']) || $queryParams['list-type'] !== '2') {
                throw new InvalidArgumentException('list-type must be 2.');
            }

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
        self::assertQueryModifierMethods($method, $queryParams, self::OBJECT_QUERY_MODIFIER_METHODS);
        self::assertSubresourceRoutingIsValid(
            $method,
            $queryParams,
            self::OBJECT_SUBRESOURCE_METHODS,
            self::UNSUPPORTED_OBJECT_SUBRESOURCES,
        );
        self::assertSubresourceScopeIsValid(
            $queryParams,
            self::BUCKET_SUBRESOURCE_METHODS,
            self::UNSUPPORTED_BUCKET_SUBRESOURCES,
            self::OBJECT_SUBRESOURCE_METHODS,
        );

        $operation = match ($method) {
            'GET' => self::resolveObjectGet($queryParams),
            'PUT' => self::resolveObjectPut($queryParams, $headers),
            'DELETE' => self::resolveObjectDelete($queryParams),
            'HEAD' => S3Operation::HeadObject,
            'POST' => self::resolveObjectPost($queryParams),
            default => throw new MethodNotAllowedException(
                "Unsupported method '{$method}' for object scope.",
            ),
        };
        if (\array_key_exists('versionId', $queryParams) && ! $operation->selectsObjectVersion()) {
            throw new MethodNotAllowedException('versionId is unsupported for this object operation.');
        }
        self::assertQueryModifiersForOperation($queryParams, $operation, self::OBJECT_QUERY_MODIFIER_OPERATIONS);

        return $operation;
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
        $hasPartNumber = \array_key_exists('partNumber', $queryParams);
        $hasUploadId = \array_key_exists('uploadId', $queryParams);
        if ($hasPartNumber !== $hasUploadId) {
            throw new InvalidArgumentException('UploadPart requires both partNumber and uploadId.');
        }
        if ($hasPartNumber) {
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

    /**
     * A recognized subresource remains the request target even when its HTTP
     * method is unsupported. Never fall through to a base bucket or object
     * mutation merely because that method has no operation for the subresource.
     *
     * @param  array<string, mixed>  $queryParams
     * @param  array<string, list<string>>  $supportedMethods
     * @param  list<string>  $unsupportedSubresources
     */
    private static function assertSubresourceRoutingIsValid(
        string $method,
        array $queryParams,
        array $supportedMethods,
        array $unsupportedSubresources,
    ): void {
        foreach ($unsupportedSubresources as $subresource) {
            if (\array_key_exists($subresource, $queryParams)) {
                throw new NotImplementedException(
                    "The '{$subresource}' subresource is not implemented.",
                );
            }
        }

        $matchedSubresource = null;

        foreach ($supportedMethods as $subresource => $methods) {
            if (! \array_key_exists($subresource, $queryParams)) {
                continue;
            }

            if ($matchedSubresource !== null) {
                throw new InvalidArgumentException(
                    "Conflicting subresources '{$matchedSubresource}' and '{$subresource}'.",
                );
            }
            $matchedSubresource = $subresource;

            if (! \in_array($method, $methods, true)) {
                throw new MethodNotAllowedException(
                    "Unsupported method '{$method}' for the '{$subresource}' subresource.",
                );
            }
        }
    }

    /**
     * Query modifiers are not independent operations, but their presence must
     * not turn a request for one API into a destructive base operation.
     *
     * @param array<string, mixed> $queryParams
     * @param array<string, list<string>> $allowedMethods
     */
    private static function assertQueryModifierMethods(
        string $method,
        array $queryParams,
        array $allowedMethods,
    ): void {
        foreach ($allowedMethods as $modifier => $methods) {
            if (\array_key_exists($modifier, $queryParams) && ! \in_array($method, $methods, true)) {
                throw new MethodNotAllowedException(
                    "Unsupported method '{$method}' for the '{$modifier}' query modifier.",
                );
            }
        }
    }

    /**
     * A known subresource from the other scope must not become a base mutation.
     * Subresources shared by bucket and object scope remain valid in either.
     *
     * @param array<string, mixed> $queryParams
     * @param array<string, list<string>> $otherScope
     * @param list<string> $otherUnsupported
     * @param array<string, list<string>> $currentScope
     */
    private static function assertSubresourceScopeIsValid(
        array $queryParams,
        array $otherScope,
        array $otherUnsupported,
        array $currentScope,
    ): void {
        foreach ($otherScope as $subresource => $_methods) {
            if (\array_key_exists($subresource, $queryParams) && ! \array_key_exists($subresource, $currentScope)) {
                throw new MethodNotAllowedException(
                    "The '{$subresource}' subresource is unsupported for this scope.",
                );
            }
        }

        foreach ($otherUnsupported as $subresource) {
            if (\array_key_exists($subresource, $queryParams) && ! \array_key_exists($subresource, $currentScope)) {
                throw new NotImplementedException(
                    "The '{$subresource}' subresource is not implemented.",
                );
            }
        }
    }

    /**
     * @param array<string, mixed> $queryParams
     * @param array<string, list<S3Operation>> $allowedOperations
     */
    private static function assertQueryModifiersForOperation(
        array $queryParams,
        S3Operation $operation,
        array $allowedOperations,
    ): void {
        foreach ($allowedOperations as $modifier => $operations) {
            if (\array_key_exists($modifier, $queryParams) && ! \in_array($operation, $operations, true)) {
                throw new MethodNotAllowedException(
                    "The '{$modifier}' query modifier is unsupported for {$operation->value}.",
                );
            }
        }
    }
}
