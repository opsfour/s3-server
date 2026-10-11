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
