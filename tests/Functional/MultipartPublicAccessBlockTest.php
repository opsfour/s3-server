<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class MultipartPublicAccessBlockTest extends S3FunctionalTestCase
{
    public function test_ignore_public_acls_masks_effective_bucket_and_object_grants(): void
    {
        $bucket = 'mpu-ignore-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            self::$s3->putBucketAcl(['Bucket' => $bucket, 'ACL' => 'public-read']);
            $created = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'key', 'ACL' => 'public-read']);
            $part = self::$s3->uploadPart([
                'Bucket' => $bucket, 'Key' => 'key', 'UploadId' => $created['UploadId'],
                'PartNumber' => 1, 'Body' => 'payload',
            ]);
            self::$s3->putPublicAccessBlock(['Bucket' => $bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false, 'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => false, 'RestrictPublicBuckets' => false,
            ]]);
            $completed = self::$s3->completeMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'key', 'UploadId' => $created['UploadId'],
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
            ]);
            self::assertSame(200, $completed['@metadata']['statusCode']);
            self::assertCount(1, self::$s3->getBucketAcl(['Bucket' => $bucket])['Grants']);
            self::assertCount(1, self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'key'])['Grants']);

            self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            self::assertCount(2, self::$s3->getBucketAcl(['Bucket' => $bucket])['Grants']);
            self::assertCount(2, self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'key'])['Grants']);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            try {
                self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            } catch (S3Exception) {
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_enforced_bucket_ownership_discards_public_acl_before_pab_check(): void
    {
        $bucket = 'mpu-enforced-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            $created = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => 'key', 'ACL' => 'public-read']);
            $part = self::$s3->uploadPart([
                'Bucket' => $bucket, 'Key' => 'key', 'UploadId' => $created['UploadId'],
                'PartNumber' => 1, 'Body' => 'payload',
            ]);
            self::$s3->putBucketOwnershipControls([
                'Bucket' => $bucket,
                'OwnershipControls' => ['Rules' => [['ObjectOwnership' => 'BucketOwnerEnforced']]],
            ]);
            self::$s3->putPublicAccessBlock(['Bucket' => $bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => true, 'IgnorePublicAcls' => false,
                'BlockPublicPolicy' => false, 'RestrictPublicBuckets' => false,
            ]]);
            self::$s3->completeMultipartUpload([
                'Bucket' => $bucket, 'Key' => 'key', 'UploadId' => $created['UploadId'],
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
            ]);
            self::assertCount(1, self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => 'key'])['Grants']);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'key']);
            self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            self::$s3->deleteBucketOwnershipControls(['Bucket' => $bucket]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_public_acl_is_rechecked_when_multipart_upload_completes(): void
    {
        $bucket = 'mpu-pab-' . bin2hex(random_bytes(5));
        $key = 'public.txt';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $created = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'ACL' => 'public-read']);
            $uploadId = $created['UploadId'];
            $part = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => 'payload',
            ]);
            $completion = [
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
            ];
            self::$s3->putPublicAccessBlock([
                'Bucket' => $bucket,
                'PublicAccessBlockConfiguration' => [
                    'BlockPublicAcls' => true,
                    'IgnorePublicAcls' => false,
                    'BlockPublicPolicy' => false,
                    'RestrictPublicBuckets' => false,
                ],
            ]);

            try {
                self::$s3->completeMultipartUpload($completion);
                self::fail('The public ACL must not become effective after BlockPublicAcls was enabled.');
            } catch (S3Exception $error) {
                self::assertSame('AccessDenied', $error->getAwsErrorCode());
            }
            try {
                self::$s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
                self::fail('Rejected completion must not publish an object.');
            } catch (S3Exception $error) {
                self::assertSame(404, $error->getStatusCode());
            }
            self::assertCount(1, self::$s3->listParts(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId])['Parts']);

            self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            self::assertSame(200, self::$s3->completeMultipartUpload($completion)['@metadata']['statusCode']);
            self::assertSame('payload', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
        } finally {
            try {
                self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            } catch (S3Exception) {
            }
            try {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            } catch (S3Exception) {
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }
}
