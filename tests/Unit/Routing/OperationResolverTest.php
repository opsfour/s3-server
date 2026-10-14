<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Routing;

use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Routing\OperationResolver;
use OpsFour\S3Server\Routing\S3Operation;
use PHPUnit\Framework\TestCase;

final class OperationResolverTest extends TestCase
{
    public function test_service_scope(): void
    {
        $op = OperationResolver::resolve('GET', 'service', [], []);
        $this->assertSame(S3Operation::ListBuckets, $op);
    }

    public function test_bucket_scope_put(): void
    {
        $op = OperationResolver::resolve('PUT', 'bucket', [], []);
        $this->assertSame(S3Operation::CreateBucket, $op);
    }

    public function test_bucket_scope_delete(): void
    {
        $op = OperationResolver::resolve('DELETE', 'bucket', [], []);
        $this->assertSame(S3Operation::DeleteBucket, $op);
    }

    public function test_bucket_scope_head(): void
    {
        $op = OperationResolver::resolve('HEAD', 'bucket', [], []);
        $this->assertSame(S3Operation::HeadBucket, $op);
    }

    public function test_bucket_scope_get_versioning(): void
    {
        $op = OperationResolver::resolve('GET', 'bucket', ['versioning' => ''], []);
        $this->assertSame(S3Operation::GetBucketVersioning, $op);
    }

    public function test_bucket_scope_put_versioning(): void
    {
        $op = OperationResolver::resolve('PUT', 'bucket', ['versioning' => ''], []);
        $this->assertSame(S3Operation::PutBucketVersioning, $op);
    }

    public function test_bucket_scope_list_objects_v2(): void
    {
        $op = OperationResolver::resolve('GET', 'bucket', ['list-type' => '2'], []);
        $this->assertSame(S3Operation::ListObjectsV2, $op);
    }

    public function test_bucket_scope_rejects_unknown_list_type_instead_of_falling_back_to_v1(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationResolver::resolve('GET', 'bucket', ['list-type' => '3'], []);
    }

    public function test_bucket_scope_list_objects_v1_default(): void
    {
        $op = OperationResolver::resolve('GET', 'bucket', [], []);
        $this->assertSame(S3Operation::ListObjects, $op);
    }

    public function test_bucket_scope_delete_objects(): void
    {
        $op = OperationResolver::resolve('POST', 'bucket', ['delete' => ''], []);
        $this->assertSame(S3Operation::DeleteObjects, $op);
    }

    public function test_object_scope_put_object(): void
    {
        $op = OperationResolver::resolve('PUT', 'object', [], []);
        $this->assertSame(S3Operation::PutObject, $op);
    }

    public function test_object_scope_get_object(): void
    {
        $op = OperationResolver::resolve('GET', 'object', [], []);
        $this->assertSame(S3Operation::GetObject, $op);
    }

    public function test_object_scope_head_object(): void
    {
        $op = OperationResolver::resolve('HEAD', 'object', [], []);
        $this->assertSame(S3Operation::HeadObject, $op);
    }

    public function test_object_scope_delete_object(): void
    {
        $op = OperationResolver::resolve('DELETE', 'object', [], []);
        $this->assertSame(S3Operation::DeleteObject, $op);
    }

    public function test_object_scope_copy_object(): void
    {
        $op = OperationResolver::resolve('PUT', 'object', [], ['x-amz-copy-source' => '/bucket/key']);
        $this->assertSame(S3Operation::CopyObject, $op);
    }

    public function test_object_scope_upload_part(): void
    {
        $op = OperationResolver::resolve('PUT', 'object', ['partNumber' => '1', 'uploadId' => 'abc'], []);
        $this->assertSame(S3Operation::UploadPart, $op);
    }

    public function test_object_scope_upload_part_copy(): void
    {
        $op = OperationResolver::resolve('PUT', 'object', ['partNumber' => '1', 'uploadId' => 'abc'], ['x-amz-copy-source' => '/bucket/key']);
        $this->assertSame(S3Operation::UploadPartCopy, $op);
    }

    public function test_object_scope_create_multipart_upload(): void
    {
        $op = OperationResolver::resolve('POST', 'object', ['uploads' => ''], []);
        $this->assertSame(S3Operation::CreateMultipartUpload, $op);
    }

    public function test_object_scope_complete_multipart_upload(): void
    {
        $op = OperationResolver::resolve('POST', 'object', ['uploadId' => 'abc'], []);
        $this->assertSame(S3Operation::CompleteMultipartUpload, $op);
    }

    public function test_object_scope_restore_object(): void
    {
        $op = OperationResolver::resolve('POST', 'object', ['restore' => ''], []);
        $this->assertSame(S3Operation::RestoreObject, $op);
    }

    public function test_object_scope_abort_multipart_upload(): void
    {
        $op = OperationResolver::resolve('DELETE', 'object', ['uploadId' => 'abc'], []);
        $this->assertSame(S3Operation::AbortMultipartUpload, $op);
    }

    public function test_object_scope_list_parts(): void
    {
        $op = OperationResolver::resolve('GET', 'object', ['uploadId' => 'abc'], []);
        $this->assertSame(S3Operation::ListParts, $op);
    }

    public function test_object_scope_get_acl(): void
    {
        $op = OperationResolver::resolve('GET', 'object', ['acl' => ''], []);
        $this->assertSame(S3Operation::GetObjectAcl, $op);
    }

    public function test_object_scope_get_tagging(): void
    {
        $op = OperationResolver::resolve('GET', 'object', ['tagging' => ''], []);
        $this->assertSame(S3Operation::GetObjectTagging, $op);
    }

    public function test_bucket_scope_get_acl(): void
    {
        $op = OperationResolver::resolve('GET', 'bucket', ['acl' => ''], []);
        $this->assertSame(S3Operation::GetBucketAcl, $op);
    }

    public function test_bucket_scope_get_uploads(): void
    {
        $op = OperationResolver::resolve('GET', 'bucket', ['uploads' => ''], []);
        $this->assertSame(S3Operation::ListMultipartUploads, $op);
    }

    public function test_bucket_scope_get_versions(): void
    {
        $op = OperationResolver::resolve('GET', 'bucket', ['versions' => ''], []);
        $this->assertSame(S3Operation::ListObjectVersions, $op);
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedBucketSubresourceMethods')]
    public function test_bucket_subresource_does_not_fall_through_to_a_base_operation(
        string $method,
        array $query,
    ): void {
        $this->expectException(MethodNotAllowedException::class);
        OperationResolver::resolve($method, 'bucket', $query, []);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function unsupportedBucketSubresourceMethods(): iterable
    {
        yield 'delete bucket acl must not delete the bucket' => ['DELETE', ['acl' => '']];
        yield 'delete bucket logging must not delete the bucket' => ['DELETE', ['logging' => '']];
        yield 'put bucket location must not create the bucket' => ['PUT', ['location' => '']];
        yield 'post bucket policy must not become a form upload' => ['POST', ['policy' => '']];
        yield 'head bucket tagging must not become HeadBucket' => ['HEAD', ['tagging' => '']];
        yield 'delete bucket list-type must not delete the bucket' => ['DELETE', ['list-type' => '2']];
        yield 'put bucket list-type must not create the bucket' => ['PUT', ['list-type' => '2']];
        yield 'delete bucket uploadId must not delete the bucket' => ['DELETE', ['uploadId' => 'upload']];
        yield 'delete bucket partNumber must not delete the bucket' => ['DELETE', ['partNumber' => '1']];
        yield 'delete bucket select must not delete the bucket' => ['DELETE', ['select' => '']];
        yield 'delete bucket response override must not delete the bucket' => ['DELETE', ['response-content-type' => 'text/plain']];
        yield 'delete bucket versionId must not delete the bucket' => ['DELETE', ['versionId' => 'version']];
        yield 'delete bucket object retention must not delete the bucket' => ['DELETE', ['retention' => '']];
        foreach (self::listingQuerySelectors() as $selector) {
            yield "delete bucket with {$selector} must not delete the bucket" => ['DELETE', [$selector => '1']];
            yield "put bucket with {$selector} must not create the bucket" => ['PUT', [$selector => '1']];
        }
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedObjectSubresourceMethods')]
    public function test_object_subresource_does_not_fall_through_to_a_base_operation(
        string $method,
        array $query,
    ): void {
        $this->expectException(MethodNotAllowedException::class);
        OperationResolver::resolve($method, 'object', $query, []);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function unsupportedObjectSubresourceMethods(): iterable
    {
        yield 'delete object acl must not delete the object' => ['DELETE', ['acl' => '']];
        yield 'delete object retention must not delete the object' => ['DELETE', ['retention' => '']];
        yield 'put object attributes must not overwrite the object' => ['PUT', ['attributes' => '']];
        yield 'head object tagging must not become HeadObject' => ['HEAD', ['tagging' => '']];
        yield 'delete object partNumber must not delete the object' => ['DELETE', ['partNumber' => '1']];
        yield 'delete object partNumber must not abort upload' => ['DELETE', ['partNumber' => '1', 'uploadId' => 'upload']];
        yield 'delete object select-type must not delete the object' => ['DELETE', ['select-type' => '2']];
        yield 'put object select-type must not overwrite the object' => ['PUT', ['select-type' => '2']];
        yield 'post object select-type must not complete upload' => ['POST', ['select-type' => '2', 'uploadId' => 'upload']];
        yield 'delete object response override must not delete the object' => ['DELETE', ['response-content-type' => 'text/plain']];
        yield 'put object response override must not overwrite the object' => ['PUT', ['response-content-type' => 'text/plain']];
        yield 'post object response override must not complete upload' => ['POST', ['response-content-type' => 'text/plain', 'uploadId' => 'upload']];
        yield 'delete object list-type must not delete the object' => ['DELETE', ['list-type' => '2']];
        yield 'delete object versions must not delete the object' => ['DELETE', ['versions' => '']];
        yield 'delete object bucket policy must not delete the object' => ['DELETE', ['policy' => '']];
        foreach (self::listingQuerySelectors() as $selector) {
            yield "delete object with {$selector} must not delete the object" => ['DELETE', [$selector => '1']];
        }
        yield 'delete object with max-parts must not delete the object' => ['DELETE', ['max-parts' => '1']];
        yield 'put object with part-number-marker must not overwrite the object' => ['PUT', ['part-number-marker' => '1']];
        yield 'post object with max-parts must not complete upload' => ['POST', ['max-parts' => '1', 'uploadId' => 'upload']];
        yield 'put object with versionId must not overwrite the object' => ['PUT', ['versionId' => 'version']];
        yield 'delete object with versionId must not abort upload' => ['DELETE', ['versionId' => 'version', 'uploadId' => 'upload']];
        yield 'post object with versionId must not complete upload' => ['POST', ['versionId' => 'version', 'uploadId' => 'upload']];
    }

    /** @return list<string> */
    private static function listingQuerySelectors(): array
    {
        return [
            'bucket-region', 'max-buckets',
            'continuation-token', 'delimiter', 'encoding-type', 'fetch-owner',
            'key-marker', 'marker', 'max-keys', 'max-uploads', 'prefix',
            'start-after', 'upload-id-marker', 'version-id-marker',
        ];
    }

    /** @param array<string, string> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('incompleteListingSelectors')]
    public function test_listing_selector_requires_its_operation_subresource(string $scope, array $query): void
    {
        $this->expectException(MethodNotAllowedException::class);
        OperationResolver::resolve('GET', $scope, $query, []);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function incompleteListingSelectors(): iterable
    {
        yield 'max-parts without uploadId' => ['object', ['max-parts' => '1']];
        yield 'part-number-marker without uploadId' => ['object', ['part-number-marker' => '1']];
        yield 'key-marker without versions or uploads' => ['bucket', ['key-marker' => 'key']];
        yield 'upload-id-marker without uploads' => ['bucket', ['upload-id-marker' => 'upload']];
        yield 'upload-id-marker with versions' => ['bucket', ['versions' => '', 'upload-id-marker' => 'upload']];
        yield 'version-id-marker without versions' => ['bucket', ['version-id-marker' => 'version']];
        yield 'version-id-marker with uploads' => ['bucket', ['uploads' => '', 'version-id-marker' => 'version']];
        yield 'max-uploads without uploads' => ['bucket', ['max-uploads' => '1']];
        yield 'fetch-owner without list-type' => ['bucket', ['fetch-owner' => 'true']];
        yield 'start-after without list-type' => ['bucket', ['start-after' => 'key']];
        yield 'continuation-token without list-type' => ['bucket', ['continuation-token' => 'next']];
    }

    /** @param array<string, string> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongBucketListingOperations')]
    public function test_bucket_listing_selector_is_rejected_for_the_wrong_listing_operation(array $query): void
    {
        $this->expectException(MethodNotAllowedException::class);
        OperationResolver::resolve('GET', 'bucket', $query, []);
    }

    /** @return iterable<string, array{array<string, string>}> */
    public static function wrongBucketListingOperations(): iterable
    {
        yield 'v1 marker on v2 listing' => [['list-type' => '2', 'marker' => 'key']];
        yield 'v1 marker on versions listing' => [['versions' => '', 'marker' => 'key']];
        yield 'max-keys on multipart listing' => [['uploads' => '', 'max-keys' => '1']];
        yield 'fetch-owner on multipart listing' => [['uploads' => '', 'fetch-owner' => 'true']];
        yield 'start-after on versions listing' => [['versions' => '', 'start-after' => 'key']];
        yield 'continuation-token on multipart listing' => [['uploads' => '', 'continuation-token' => 'next']];
        yield 'max-uploads on versions listing' => [['versions' => '', 'max-uploads' => '1']];
        yield 'key-marker on v2 listing' => [['list-type' => '2', 'key-marker' => 'key']];
        yield 'list-type on multipart listing' => [['uploads' => '', 'list-type' => '2']];
        yield 'prefix on bucket policy read' => [['policy' => '', 'prefix' => 'logs/']];
    }

    /** @param array<string, string> $query */
    #[\PHPUnit\Framework\Attributes\DataProvider('wrongObjectModifierOperations')]
    public function test_object_modifier_is_rejected_for_the_wrong_resolved_operation(string $method, array $query): void
    {
        $this->expectException(MethodNotAllowedException::class);
        OperationResolver::resolve($method, 'object', $query, []);
    }

    /** @return iterable<string, array{string, array<string, string>}> */
    public static function wrongObjectModifierOperations(): iterable
    {
        yield 'partNumber on GetObjectTagging' => ['GET', ['tagging' => '', 'partNumber' => '1']];
        yield 'partNumber on PutObjectAcl' => ['PUT', ['acl' => '', 'partNumber' => '1']];
        yield 'partNumber on ListParts' => ['GET', ['uploadId' => 'upload', 'partNumber' => '1']];
        yield 'response override on ListParts' => ['GET', ['uploadId' => 'upload', 'response-content-type' => 'text/plain']];
        foreach ([
            'response-cache-control', 'response-content-disposition',
            'response-content-encoding', 'response-content-language',
            'response-content-type', 'response-expires',
        ] as $override) {
            yield "{$override} on GetObjectAcl" => ['GET', ['acl' => '', $override => 'value']];
        }
    }

    public function test_object_version_selector_remains_valid_for_head_and_delete(): void
    {
        self::assertSame(
            S3Operation::HeadObject,
            OperationResolver::resolve('HEAD', 'object', ['versionId' => 'version'], []),
        );
        self::assertSame(
            S3Operation::DeleteObject,
            OperationResolver::resolve('DELETE', 'object', ['versionId' => 'version'], []),
        );
    }

    public function test_conflicting_bucket_subresources_are_rejected_instead_of_using_priority(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationResolver::resolve('GET', 'bucket', ['acl' => '', 'policy' => ''], []);
    }

    public function test_conflicting_object_subresources_are_rejected_instead_of_using_priority(): void
    {
        $this->expectException(InvalidArgumentException::class);
        OperationResolver::resolve('POST', 'object', ['restore' => '', 'uploadId' => 'upload'], []);
    }

    public function test_subresource_selectors_are_not_mistaken_for_conflicting_operations(): void
    {
        self::assertSame(
            S3Operation::GetObjectAcl,
            OperationResolver::resolve('GET', 'object', ['acl' => '', 'versionId' => 'version'], []),
        );
        self::assertSame(
            S3Operation::UploadPart,
            OperationResolver::resolve(
                'PUT',
                'object',
                ['partNumber' => '1', 'uploadId' => 'upload'],
                [],
            ),
        );
        self::assertSame(
            S3Operation::SelectObjectContent,
            OperationResolver::resolve('POST', 'object', ['select' => '', 'select-type' => '2'], []),
        );
        self::assertSame(
            S3Operation::ListObjectsV2,
            OperationResolver::resolve('GET', 'bucket', ['list-type' => '2', 'prefix' => 'logs/', 'continuation-token' => 'next', 'fetch-owner' => 'true', 'start-after' => 'key'], []),
        );
        self::assertSame(
            S3Operation::ListObjects,
            OperationResolver::resolve('GET', 'bucket', ['marker' => 'key', 'max-keys' => '1'], []),
        );
        self::assertSame(
            S3Operation::ListMultipartUploads,
            OperationResolver::resolve('GET', 'bucket', ['uploads' => '', 'key-marker' => 'key', 'upload-id-marker' => 'upload', 'max-uploads' => '1'], []),
        );
        self::assertSame(
            S3Operation::ListObjectVersions,
            OperationResolver::resolve('GET', 'bucket', ['versions' => '', 'key-marker' => 'key', 'version-id-marker' => 'version', 'max-keys' => '1'], []),
        );
        self::assertSame(
            S3Operation::ListParts,
            OperationResolver::resolve('GET', 'object', ['uploadId' => 'upload', 'max-parts' => '1', 'part-number-marker' => '2'], []),
        );
        self::assertSame(
            S3Operation::GetObject,
            OperationResolver::resolve('GET', 'object', ['partNumber' => '1', 'response-content-type' => 'text/plain'], []),
        );
        self::assertSame(
            S3Operation::HeadObject,
            OperationResolver::resolve('HEAD', 'object', ['response-cache-control' => 'max-age=60'], []),
        );
        self::assertSame(
            S3Operation::PutObjectAcl,
            OperationResolver::resolve('PUT', 'object', ['acl' => '', 'versionId' => 'version'], []),
        );
    }

    /**
     * @param array<string, string> $query
     */
    #[\PHPUnit\Framework\Attributes\DataProvider('unsupportedAwsSubresources')]
    public function test_unsupported_aws_subresource_does_not_fall_through_to_supported_operation(
        string $method,
        string $scope,
        array $query,
    ): void {
        $this->expectException(NotImplementedException::class);
        OperationResolver::resolve($method, $scope, $query, []);
    }

    /** @return iterable<string, array{string, string, array<string, string>}> */
    public static function unsupportedAwsSubresources(): iterable
    {
        yield 'delete replication must not delete bucket' => ['DELETE', 'bucket', ['replication' => '']];
        yield 'put analytics must not create bucket' => ['PUT', 'bucket', ['analytics' => '']];
        yield 'metadata table must not become form upload' => ['POST', 'bucket', ['metadataTable' => '']];
        yield 'torrent must not return object body' => ['GET', 'object', ['torrent' => '']];
        yield 'object encryption must not overwrite object' => ['PUT', 'object', ['encryption' => '']];
        yield 'annotation must not delete object' => ['DELETE', 'object', ['annotation' => '']];
        yield 'rename must not overwrite object' => ['PUT', 'object', ['renameObject' => '']];
        yield 'bucket torrent must not delete bucket' => ['DELETE', 'bucket', ['torrent' => '']];
        yield 'object replication must not delete object' => ['DELETE', 'object', ['replication' => '']];
    }

    public function test_every_operation_has_a_direct_route_fixture(): void
    {
        $fixtures = self::routeFixtures();
        self::assertSame(
            array_map(static fn(S3Operation $operation): string => $operation->name, S3Operation::cases()),
            array_keys($fixtures),
            'Every S3Operation must have one direct resolver fixture in enum order.',
        );

        foreach ($fixtures as $expected => [$method, $scope, $query, $headers]) {
            self::assertSame(
                $expected,
                OperationResolver::resolve($method, $scope, $query, $headers)->name,
                $expected,
            );
        }
    }

    /**
     * @return array<string, array{string, string, array<string, string>, array<string, string>}>
     */
    private static function routeFixtures(): array
    {
        return [
            'ListBuckets' => ['GET', 'service', [], []],
            'CreateBucket' => ['PUT', 'bucket', [], []],
            'DeleteBucket' => ['DELETE', 'bucket', [], []],
            'HeadBucket' => ['HEAD', 'bucket', [], []],
            'ListObjects' => ['GET', 'bucket', [], []],
            'ListObjectsV2' => ['GET', 'bucket', ['list-type' => '2'], []],
            'ListObjectVersions' => ['GET', 'bucket', ['versions' => ''], []],
            'GetBucketLocation' => ['GET', 'bucket', ['location' => ''], []],
            'GetBucketVersioning' => ['GET', 'bucket', ['versioning' => ''], []],
            'PutBucketVersioning' => ['PUT', 'bucket', ['versioning' => ''], []],
            'GetBucketAcl' => ['GET', 'bucket', ['acl' => ''], []],
            'PutBucketAcl' => ['PUT', 'bucket', ['acl' => ''], []],
            'GetBucketPolicy' => ['GET', 'bucket', ['policy' => ''], []],
            'PutBucketPolicy' => ['PUT', 'bucket', ['policy' => ''], []],
            'DeleteBucketPolicy' => ['DELETE', 'bucket', ['policy' => ''], []],
            'GetBucketCors' => ['GET', 'bucket', ['cors' => ''], []],
            'PutBucketCors' => ['PUT', 'bucket', ['cors' => ''], []],
            'DeleteBucketCors' => ['DELETE', 'bucket', ['cors' => ''], []],
            'GetBucketTagging' => ['GET', 'bucket', ['tagging' => ''], []],
            'PutBucketTagging' => ['PUT', 'bucket', ['tagging' => ''], []],
            'DeleteBucketTagging' => ['DELETE', 'bucket', ['tagging' => ''], []],
            'GetBucketLifecycle' => ['GET', 'bucket', ['lifecycle' => ''], []],
            'PutBucketLifecycle' => ['PUT', 'bucket', ['lifecycle' => ''], []],
            'DeleteBucketLifecycle' => ['DELETE', 'bucket', ['lifecycle' => ''], []],
            'GetBucketNotification' => ['GET', 'bucket', ['notification' => ''], []],
            'PutBucketNotification' => ['PUT', 'bucket', ['notification' => ''], []],
            'GetBucketEncryption' => ['GET', 'bucket', ['encryption' => ''], []],
            'PutBucketEncryption' => ['PUT', 'bucket', ['encryption' => ''], []],
            'DeleteBucketEncryption' => ['DELETE', 'bucket', ['encryption' => ''], []],
            'GetObjectLockConfig' => ['GET', 'bucket', ['object-lock' => ''], []],
            'PutObjectLockConfig' => ['PUT', 'bucket', ['object-lock' => ''], []],
            'ListMultipartUploads' => ['GET', 'bucket', ['uploads' => ''], []],
            'DeleteObjects' => ['POST', 'bucket', ['delete' => ''], []],
            'PostObject' => ['POST', 'bucket', [], []],
            'GetBucketWebsite' => ['GET', 'bucket', ['website' => ''], []],
            'PutBucketWebsite' => ['PUT', 'bucket', ['website' => ''], []],
            'DeleteBucketWebsite' => ['DELETE', 'bucket', ['website' => ''], []],
            'GetPublicAccessBlock' => ['GET', 'bucket', ['publicAccessBlock' => ''], []],
            'PutPublicAccessBlock' => ['PUT', 'bucket', ['publicAccessBlock' => ''], []],
            'DeletePublicAccessBlock' => ['DELETE', 'bucket', ['publicAccessBlock' => ''], []],
            'GetBucketPolicyStatus' => ['GET', 'bucket', ['policyStatus' => ''], []],
            'GetBucketLogging' => ['GET', 'bucket', ['logging' => ''], []],
            'PutBucketLogging' => ['PUT', 'bucket', ['logging' => ''], []],
            'GetBucketOwnershipControls' => ['GET', 'bucket', ['ownershipControls' => ''], []],
            'PutBucketOwnershipControls' => ['PUT', 'bucket', ['ownershipControls' => ''], []],
            'DeleteBucketOwnershipControls' => ['DELETE', 'bucket', ['ownershipControls' => ''], []],
            'PutObject' => ['PUT', 'object', [], []],
            'GetObject' => ['GET', 'object', [], []],
            'HeadObject' => ['HEAD', 'object', [], []],
            'DeleteObject' => ['DELETE', 'object', [], []],
            'CopyObject' => ['PUT', 'object', [], ['x-amz-copy-source' => '/source/key']],
            'GetObjectAcl' => ['GET', 'object', ['acl' => ''], []],
            'PutObjectAcl' => ['PUT', 'object', ['acl' => ''], []],
            'GetObjectTagging' => ['GET', 'object', ['tagging' => ''], []],
            'PutObjectTagging' => ['PUT', 'object', ['tagging' => ''], []],
            'DeleteObjectTagging' => ['DELETE', 'object', ['tagging' => ''], []],
            'GetObjectRetention' => ['GET', 'object', ['retention' => ''], []],
            'PutObjectRetention' => ['PUT', 'object', ['retention' => ''], []],
            'GetObjectLegalHold' => ['GET', 'object', ['legal-hold' => ''], []],
            'PutObjectLegalHold' => ['PUT', 'object', ['legal-hold' => ''], []],
            'SelectObjectContent' => ['POST', 'object', ['select' => '', 'select-type' => '2'], []],
            'GetObjectAttributes' => ['GET', 'object', ['attributes' => ''], []],
            'RestoreObject' => ['POST', 'object', ['restore' => ''], []],
            'CreateMultipartUpload' => ['POST', 'object', ['uploads' => ''], []],
            'UploadPart' => ['PUT', 'object', ['partNumber' => '1', 'uploadId' => 'upload'], []],
            'UploadPartCopy' => ['PUT', 'object', ['partNumber' => '1', 'uploadId' => 'upload'], ['x-amz-copy-source' => '/source/key']],
            'CompleteMultipartUpload' => ['POST', 'object', ['uploadId' => 'upload'], []],
            'AbortMultipartUpload' => ['DELETE', 'object', ['uploadId' => 'upload'], []],
            'ListParts' => ['GET', 'object', ['uploadId' => 'upload'], []],
        ];
    }
}
