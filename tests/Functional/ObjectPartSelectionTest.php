<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class ObjectPartSelectionTest extends S3FunctionalTestCase
{
    public function test_get_and_head_stream_completed_multipart_ordinals_over_the_wire(): void
    {
        $bucket = 'part-select-' . bin2hex(random_bytes(4));
        $key = 'unequal.bin';
        self::$s3->createBucket(['Bucket' => $bucket]);
        $created = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key]);
        $uploadId = (string) $created['UploadId'];

        try {
            $firstBody = str_repeat('a', 5 * 1024 * 1024);
            $first = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => $firstBody,
            ]);
            $second = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 7,
                'Body' => 'second!',
            ]);
            self::$s3->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [
                    ['PartNumber' => 1, 'ETag' => $first['ETag']],
                    ['PartNumber' => 7, 'ETag' => $second['ETag']],
                ]],
            ]);
            $uploadId = '';

            $get = self::$s3->getObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'PartNumber' => 2,
                'ChecksumMode' => 'ENABLED',
            ]);
            $head = self::$s3->headObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'PartNumber' => 2,
                'ChecksumMode' => 'ENABLED',
            ]);

            self::assertSame(206, $get['@metadata']['statusCode']);
            self::assertSame('second!', (string) $get['Body']);
            self::assertSame('bytes 5242880-5242886/5242887', $get['ContentRange']);
            self::assertSame(7, $get['ContentLength']);
            self::assertSame(2, $get['PartsCount']);
            self::assertArrayNotHasKey('ChecksumSHA256', $get);
            self::assertArrayNotHasKey('ChecksumType', $get);

            self::assertSame(200, $head['@metadata']['statusCode']);
            self::assertSame(7, $head['ContentLength']);
            self::assertSame(2, $head['PartsCount']);
            self::assertArrayNotHasKey('ContentRange', $head);
            self::assertArrayNotHasKey('ChecksumSHA256', $head);

            $ordinary = self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
            self::assertSame(200, $ordinary['@metadata']['statusCode']);
            self::assertSame($firstBody . 'second!', (string) $ordinary['Body']);

            try {
                self::$s3->getObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'PartNumber' => 1,
                    'Range' => 'bytes=0-0',
                ]);
                self::fail('Range and partNumber must be rejected together.');
            } catch (S3Exception $error) {
                self::assertSame(400, $error->getStatusCode());
                self::assertSame('InvalidRequest', $error->getAwsErrorCode());
            }
        } finally {
            if ($uploadId !== '') {
                self::$s3->abortMultipartUpload([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ]);
            }
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }
}
