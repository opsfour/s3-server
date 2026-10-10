<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

final class SsePrewriteFunctionalTest extends S3FunctionalTestCase
{
    private static string|false $previousMasterKey = false;

    private static string|false $previousMaxSize = false;

    private static string $bucket = '';

    public static function setUpBeforeClass(): void
    {
        self::$previousMasterKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        self::$previousMaxSize = getenv('S3_MAX_ENCRYPTED_OBJECT_SIZE');
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('P', 32)));
        putenv('S3_MAX_ENCRYPTED_OBJECT_SIZE=8');
        parent::setUpBeforeClass();
        self::$bucket = 'sse-prewrite-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();
        self::restoreEnvironment('S3_ENCRYPTION_MASTER_KEY', self::$previousMasterKey);
        self::restoreEnvironment('S3_MAX_ENCRYPTED_OBJECT_SIZE', self::$previousMaxSize);
    }

    public function test_signed_sse_put_and_copy_preserve_plaintext_etag_and_contents(): void
    {
        $body = '12345678';
        $checksum = base64_encode(hash('sha256', $body, true));
        $put = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'encrypted-source',
            'Body' => $body,
            'ServerSideEncryption' => 'AES256',
            'ContentMD5' => base64_encode(md5($body, true)),
            'ChecksumSHA256' => $checksum,
        ]);
        self::assertSame('"' . md5($body) . '"', $put['ETag']);
        self::assertSame($checksum, $put['ChecksumSHA256']);
        self::assertSame($body, (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => 'encrypted-source'])['Body']);

        $copy = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'encrypted-copy',
            'CopySource' => self::$bucket . '/encrypted-source',
            'ServerSideEncryption' => 'AES256',
        ]);
        self::assertSame('"' . md5($body) . '"', $copy['CopyObjectResult']['ETag']);
        self::assertSame($body, (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => 'encrypted-copy'])['Body']);
    }

    public function test_signed_zero_byte_sse_put_has_plaintext_etag_and_empty_get(): void
    {
        $put = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'empty',
            'Body' => '',
            'ServerSideEncryption' => 'AES256',
            'ChecksumSHA256' => base64_encode(hash('sha256', '', true)),
        ]);

        self::assertSame('"' . md5('') . '"', $put['ETag']);
        self::assertSame('', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => 'empty'])['Body']);
    }

    public function test_signed_oversize_sse_put_and_copy_fail_without_destination_object(): void
    {
        foreach (['oversize-put', 'oversize-copy'] as $key) {
            if ($key === 'oversize-copy') {
                self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => 'large-source', 'Body' => '123456789']);
            }

            try {
                if ($key === 'oversize-put') {
                    self::$s3->putObject([
                        'Bucket' => self::$bucket,
                        'Key' => $key,
                        'Body' => '123456789',
                        'ServerSideEncryption' => 'AES256',
                    ]);
                } else {
                    self::$s3->copyObject([
                        'Bucket' => self::$bucket,
                        'Key' => $key,
                        'CopySource' => self::$bucket . '/large-source',
                        'ServerSideEncryption' => 'AES256',
                    ]);
                }
                self::fail('Oversized SSE destination must fail.');
            } catch (S3Exception $e) {
                self::assertSame(400, $e->getStatusCode());
                self::assertSame('EntityTooLarge', $e->getAwsErrorCode());
            }

            try {
                self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => $key]);
                self::fail('Rejected SSE destination must not exist.');
            } catch (S3Exception $e) {
                self::assertSame(404, $e->getStatusCode());
            }
        }
    }

    public function test_signed_bad_checksum_rejects_sse_put_without_destination_object(): void
    {
        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => 'bad-checksum',
                'Body' => 'good',
                'ServerSideEncryption' => 'AES256',
                'ChecksumSHA256' => base64_encode(hash('sha256', 'wrong', true)),
            ]);
            self::fail('Mismatched signed checksum must fail.');
        } catch (S3Exception $e) {
            self::assertSame(400, $e->getStatusCode());
            self::assertSame('BadDigest', $e->getAwsErrorCode());
        }

        try {
            self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => 'bad-checksum']);
            self::fail('Rejected checksum destination must not exist.');
        } catch (S3Exception $e) {
            self::assertSame(404, $e->getStatusCode());
        }
    }

    public function test_signed_sse_multipart_upload_and_copy_complete_with_plaintext_etags(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'multipart-source',
            'Body' => '5678',
        ]);
        $created = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => 'multipart-encrypted',
            'ServerSideEncryption' => 'AES256',
        ]);
        $uploadId = $created['UploadId'];
        self::assertIsString($uploadId);
        $first = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => 'multipart-encrypted',
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => '1234',
            'ChecksumSHA256' => base64_encode(hash('sha256', '1234', true)),
        ]);
        self::assertSame('"' . md5('1234') . '"', $first['ETag']);
        self::assertSame('AES256', $first['ServerSideEncryption']);
        $second = self::$s3->uploadPartCopy([
            'Bucket' => self::$bucket,
            'Key' => 'multipart-encrypted',
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'CopySource' => self::$bucket . '/multipart-source',
        ]);
        self::assertSame('"' . md5('5678') . '"', $second['CopyPartResult']['ETag']);
        self::assertSame('AES256', $second['ServerSideEncryption']);
        $completed = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => 'multipart-encrypted',
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $first['ETag']],
                    ['PartNumber' => 2, 'ETag' => $second['CopyPartResult']['ETag']],
                ],
            ],
        ]);
        $partMd5s = hex2bin(md5('1234') . md5('5678'));
        self::assertNotFalse($partMd5s);
        $expectedEtag = '"' . md5($partMd5s) . '-2"';
        self::assertSame($expectedEtag, $completed['ETag']);
        self::assertSame('12345678', (string) self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'multipart-encrypted',
        ])['Body']);
    }

    private static function restoreEnvironment(string $name, string|false $previous): void
    {
        putenv($previous === false ? $name : $name . '=' . $previous);
    }
}
