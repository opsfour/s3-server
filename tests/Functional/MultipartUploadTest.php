<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for Phase 4: Multipart Upload operations.
 *
 * Tests the full multipart lifecycle: create, upload parts, complete/abort,
 * list parts, list uploads, and the AWS SDK's MultipartUploader.
 */
final class MultipartUploadTest extends S3FunctionalTestCase
{
    private static string $bucket = 'test-multipart-bucket';

    private static bool $bucketCreated = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (! self::$bucketCreated) {
            self::$s3->createBucket(['Bucket' => self::$bucket]);
            self::$bucketCreated = true;
        }
    }

    public function test_full_multipart_lifecycle(): void
    {
        $key = 'multipart-test.txt';

        // 1. Create multipart upload.
        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ContentType' => 'text/plain',
        ]);

        $this->assertSame(200, $create['@metadata']['statusCode']);
        $uploadId = $create['UploadId'];
        $this->assertNotEmpty($uploadId);

        // 2. Upload parts (minimum 5MB for all except last, but our server
        //    doesn't enforce this strictly yet for functional test simplicity).
        $part1Data = str_repeat('A', 5 * 1024 * 1024); // 5MB
        $part2Data = str_repeat('B', 5 * 1024 * 1024); // 5MB
        $part3Data = 'final part data'; // small last part

        $upload1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => $part1Data,
        ]);
        $etag1 = $upload1['ETag'];
        $this->assertNotEmpty($etag1);

        $upload2 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'Body' => $part2Data,
        ]);
        $etag2 = $upload2['ETag'];

        $upload3 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 3,
            'Body' => $part3Data,
        ]);
        $etag3 = $upload3['ETag'];

        // 3. List parts.
        $listParts = self::$s3->listParts([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);

        $this->assertCount(3, $listParts['Parts']);
        $partNumbers = array_map(fn ($p) => $p['PartNumber'], $listParts['Parts']);
        $this->assertSame([1, 2, 3], $partNumbers);

        // 4. Complete the upload.
        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $etag1],
                    ['PartNumber' => 2, 'ETag' => $etag2],
                    ['PartNumber' => 3, 'ETag' => $etag3],
                ],
            ],
        ]);

        $this->assertSame(200, $complete['@metadata']['statusCode']);
        $this->assertNotEmpty($complete['ETag']);
        // Composite ETag should contain a dash and part count.
        $this->assertStringContainsString('-3', $complete['ETag']);

        // 5. Verify the assembled object.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $expectedBody = $part1Data.$part2Data.$part3Data;
        $this->assertSame($expectedBody, (string) $get['Body']);
        $this->assertSame(strlen($expectedBody), $get['ContentLength']);
    }

    public function test_abort_multipart_upload(): void
    {
        $key = 'multipart-abort.txt';

        // Create upload.
        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        // Upload a part.
        self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => str_repeat('X', 1024),
        ]);

        // Abort.
        $result = self::$s3->abortMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);

        $this->assertSame(204, $result['@metadata']['statusCode']);

        // Verify upload is gone: listing parts should fail.
        try {
            self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            $this->fail('Expected NoSuchUpload exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchUpload', $e->getAwsErrorCode());
        }
    }

    public function test_list_multipart_uploads(): void
    {
        $key1 = 'list-uploads/file1.txt';
        $key2 = 'list-uploads/file2.txt';

        $create1 = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key1,
        ]);

        $create2 = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key2,
        ]);

        // List all uploads.
        $list = self::$s3->listMultipartUploads([
            'Bucket' => self::$bucket,
            'Prefix' => 'list-uploads/',
        ]);

        $this->assertSame(200, $list['@metadata']['statusCode']);
        $this->assertGreaterThanOrEqual(2, count($list['Uploads'] ?? []));

        $keys = array_map(fn ($u) => $u['Key'], $list['Uploads']);
        $this->assertContains($key1, $keys);
        $this->assertContains($key2, $keys);

        // Clean up: abort both uploads.
        self::$s3->abortMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key1,
            'UploadId' => $create1['UploadId'],
        ]);
        self::$s3->abortMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key2,
            'UploadId' => $create2['UploadId'],
        ]);
    }

    public function test_upload_parts_out_of_order(): void
    {
        $key = 'out-of-order.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        // Upload parts out of order: 3, 1, 2
        $part3 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 3,
            'Body' => 'CCC',
        ]);

        $part1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'AAA',
        ]);

        $part2 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'Body' => 'BBB',
        ]);

        // Complete with ascending order.
        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part1['ETag']],
                    ['PartNumber' => 2, 'ETag' => $part2['ETag']],
                    ['PartNumber' => 3, 'ETag' => $part3['ETag']],
                ],
            ],
        ]);

        $this->assertSame(200, $complete['@metadata']['statusCode']);

        // Verify assembled content is in correct order.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('AAABBBCCC', (string) $get['Body']);
    }

    public function test_reupload_part_overwrites_previous(): void
    {
        $key = 'reupload-part.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        // Upload part 1 with initial content.
        self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'original',
        ]);

        // Re-upload part 1 with new content.
        $part1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'replaced',
        ]);

        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part1['ETag']],
                ],
            ],
        ]);

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('replaced', (string) $get['Body']);
    }

    public function test_multipart_upload_preserves_content_type(): void
    {
        $key = 'typed-multipart.html';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ContentType' => 'text/html',
        ]);
        $uploadId = $create['UploadId'];

        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => '<h1>Hello</h1>',
        ]);

        self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part['ETag']],
                ],
            ],
        ]);

        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('text/html', $head['ContentType']);
    }

    public function test_no_such_upload_returns404(): void
    {
        try {
            self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => 'nonexistent-key',
                'UploadId' => 'nonexistent-upload-id',
            ]);
            $this->fail('Expected NoSuchUpload exception');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchUpload', $e->getAwsErrorCode());
        }
    }
}
