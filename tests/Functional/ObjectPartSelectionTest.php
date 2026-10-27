<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class ObjectPartSelectionTest extends S3FunctionalTestCase
{
    private static string|false $previousMasterKey = false;

    private static string|false $previousMasterKeys = false;

    public static function setUpBeforeClass(): void
    {
        self::$previousMasterKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        self::$previousMasterKeys = getenv('S3_ENCRYPTION_MASTER_KEYS');
        putenv('S3_ENCRYPTION_MASTER_KEYS');
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('P', 32)));
        try {
            parent::setUpBeforeClass();
        } catch (\Throwable $error) {
            self::restoreEncryptionEnvironment();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            self::restoreEncryptionEnvironment();
        }
    }

    private static function restoreEncryptionEnvironment(): void
    {
        putenv(self::$previousMasterKey === false
            ? 'S3_ENCRYPTION_MASTER_KEY'
            : 'S3_ENCRYPTION_MASTER_KEY=' . self::$previousMasterKey);
        putenv(self::$previousMasterKeys === false
            ? 'S3_ENCRYPTION_MASTER_KEYS'
            : 'S3_ENCRYPTION_MASTER_KEYS=' . self::$previousMasterKeys);
    }

    public function test_get_and_head_stream_completed_multipart_ordinals_over_the_wire(): void
    {
        $bucket = 'part-select-' . bin2hex(random_bytes(4));
        $key = 'unequal.bin';
        self::$s3->createBucket(['Bucket' => $bucket]);
        $created = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key]);
        $uploadId = (string) $created['UploadId'];

        try {
            $firstBody = str_repeat('a', 5 * 1024 * 1024);
            $firstChecksum = base64_encode(hash('sha256', $firstBody, true));
            $first = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => $firstBody,
                'ChecksumSHA256' => $firstChecksum,
            ]);
            $secondBody = 'second!';
            $secondChecksum = base64_encode(hash('sha256', $secondBody, true));
            $second = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 7,
                'Body' => $secondBody,
                'ChecksumSHA256' => $secondChecksum,
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
            self::assertSame($secondBody, (string) $get['Body']);
            self::assertSame('bytes 5242880-5242886/5242887', $get['ContentRange']);
            self::assertSame(7, $get['ContentLength']);
            self::assertSame(2, $get['PartsCount']);
            self::assertSame($secondChecksum, $get['ChecksumSHA256'] ?? null);
            self::assertArrayNotHasKey('ChecksumType', $get);

            self::assertSame(200, $head['@metadata']['statusCode']);
            self::assertSame(7, $head['ContentLength']);
            self::assertSame(2, $head['PartsCount']);
            self::assertArrayNotHasKey('ContentRange', $head);
            self::assertSame($secondChecksum, $head['ChecksumSHA256'] ?? null);
            self::assertArrayNotHasKey('ChecksumType', $head);

            $ordinary = self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key]);
            self::assertSame(200, $ordinary['@metadata']['statusCode']);
            self::assertSame($firstBody . $secondBody, (string) $ordinary['Body']);

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

    public function test_sse_s3_part_selection_and_verified_checksum_over_the_wire(): void
    {
        $bucket = 'encrypted-part-select-' . bin2hex(random_bytes(4));
        $key = 'encrypted.bin';
        $uploadId = '';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $created = self::$s3->createMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'ServerSideEncryption' => 'AES256',
            ]);
            $uploadId = (string) $created['UploadId'];
            $firstBody = str_repeat('a', 5 * 1024 * 1024);
            $secondBody = 'encrypted-second';
            $firstChecksum = base64_encode(hash('sha256', $firstBody, true));
            $secondChecksum = base64_encode(hash('sha256', $secondBody, true));
            $first = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => $firstBody,
                'ChecksumSHA256' => $firstChecksum,
            ]);
            $second = self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 9,
                'Body' => $secondBody,
                'ChecksumSHA256' => $secondChecksum,
            ]);
            self::$s3->completeMultipartUpload([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [
                    ['PartNumber' => 1, 'ETag' => $first['ETag']],
                    ['PartNumber' => 9, 'ETag' => $second['ETag']],
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
            self::assertSame($secondBody, (string) $get['Body']);
            self::assertSame($secondChecksum, $get['ChecksumSHA256'] ?? null);
            self::assertSame('AES256', $get['ServerSideEncryption'] ?? null);
            self::assertSame(2, $get['PartsCount']);
            self::assertArrayNotHasKey('ChecksumType', $get);
            self::assertSame(200, $head['@metadata']['statusCode']);
            self::assertSame(strlen($secondBody), $head['ContentLength']);
            self::assertSame($secondChecksum, $head['ChecksumSHA256'] ?? null);
            self::assertSame('AES256', $head['ServerSideEncryption'] ?? null);
            self::assertArrayNotHasKey('ChecksumType', $head);
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
