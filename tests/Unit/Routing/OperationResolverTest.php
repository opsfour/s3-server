<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Unit\Routing;

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
}
