<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for multipart upload edge cases: upload part copy,
 * error conditions, pagination, metadata preservation, and concurrent uploads.
 */
final class MultipartEdgeCasesTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'multipart-edge-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);

        // Seed a source object for copy tests.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'copy-source.txt',
            'Body' => str_repeat('S', 10 * 1024), // 10KB source
        ]);

        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // UploadPartCopy
    // -----------------------------------------------------------------

    public function test_completion_location_is_an_absolute_encoded_object_uri(): void
    {
        $key = 'dir/a b?#.txt';
        $uploadId = (string) self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['UploadId'];
        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'location payload',
        ]);
        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
        ]);

        self::assertSame(
            sprintf('http://%s:%d/%s/dir/a%%20b%%3F%%23.txt', self::$host, self::$port, self::$bucket),
            $complete['Location'],
        );
    }

    public function test_upload_part_copy(): void
    {
        $key = 'partcopy-test.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        // Copy source object as a part.
        $partCopy = self::$s3->uploadPartCopy([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'CopySource' => self::$bucket . '/copy-source.txt',
        ]);

        $etag = $partCopy['CopyPartResult']['ETag'] ?? '';
        $this->assertNotEmpty($etag);

        // Complete.
        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $etag],
                ],
            ],
        ]);

        $this->assertSame(200, $complete['@metadata']['statusCode']);

        // Verify content matches source.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame(str_repeat('S', 10 * 1024), (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_upload_part_copy_with_range(): void
    {
        $key = 'partcopy-range.txt';
        $sourceKey = 'copy-source-large.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $sourceKey,
            'Body' => str_repeat('S', 5 * 1024 * 1024 + 1),
        ]);

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        // Copy only first 1024 bytes of source.
        $partCopy = self::$s3->uploadPartCopy([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'CopySource' => self::$bucket . '/' . $sourceKey,
            'CopySourceRange' => 'bytes=0-1023',
        ]);

        $etag = $partCopy['CopyPartResult']['ETag'] ?? '';
        $this->assertNotEmpty($etag);

        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $etag],
                ],
            ],
        ]);

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame(1024, $get['ContentLength']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $sourceKey]);
    }

    public function test_upload_part_copy_rejects_invalid_ranges_without_writing_a_part(): void
    {
        $key = 'partcopy-invalid-range.txt';
        $uploadId = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['UploadId'];

        try {
            foreach (['bytes=1junk-2', 'bytes=1', 'items=0-1', 'bytes=-1-2', 'bytes=0-999999999999999999999999999999', 'bytes=0-1023'] as $range) {
                try {
                    self::$s3->uploadPartCopy([
                        'Bucket' => self::$bucket,
                        'Key' => $key,
                        'UploadId' => $uploadId,
                        'PartNumber' => 1,
                        'CopySource' => self::$bucket . '/copy-source.txt',
                        'CopySourceRange' => $range,
                    ]);
                    $this->fail('UploadPartCopy accepted malformed range: ' . $range);
                } catch (S3Exception $e) {
                    $this->assertSame(400, $e->getStatusCode(), $range);
                    $this->assertSame('InvalidArgument', $e->getAwsErrorCode(), $range);
                }

                $parts = self::$s3->listParts([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'UploadId' => $uploadId,
                ]);
                $this->assertEmpty($parts['Parts'] ?? [], $range);
            }
        } finally {
            self::$s3->abortMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        }
    }

    public function test_upload_part_copy_honors_source_preconditions_before_writing_a_part(): void
    {
        $key = 'partcopy-conditions.txt';
        $uploadId = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['UploadId'];
        $base = [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'CopySource' => self::$bucket . '/copy-source.txt',
        ];
        $listArgs = ['Bucket' => self::$bucket, 'Key' => $key, 'UploadId' => $uploadId];
        $sourceEtag = '"' . md5(str_repeat('S', 10 * 1024)) . '"';

        try {
            foreach ([
                'CopySourceIfMatch' => '"wrong-etag"',
                'CopySourceIfNoneMatch' => $sourceEtag,
                'CopySourceIfModifiedSince' => new \DateTimeImmutable('2100-01-01', new \DateTimeZone('UTC')),
                'CopySourceIfUnmodifiedSince' => new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
            ] as $condition => $value) {
                try {
                    $command = self::$s3->getCommand('UploadPartCopy', $base + [$condition => $value]);
                    self::$s3->execute($command);
                    $this->fail('UploadPartCopy ignored ' . $condition);
                } catch (S3Exception $exception) {
                    $this->assertSame(412, $exception->getStatusCode(), $condition);
                    $this->assertSame('PreconditionFailed', $exception->getAwsErrorCode(), $condition);
                }
                $this->assertEmpty(self::$s3->listParts($listArgs)['Parts'] ?? [], $condition);
            }

            $copied = self::$s3->uploadPartCopy($base + [
                'CopySourceIfMatch' => $sourceEtag,
                'CopySourceIfUnmodifiedSince' => new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
            ]);
            $this->assertSame(200, $copied['@metadata']['statusCode']);
            $this->assertCount(1, self::$s3->listParts($listArgs)['Parts'] ?? []);
        } finally {
            self::$s3->abortMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        }
    }

    // -----------------------------------------------------------------
    // Complete multipart error conditions
    // -----------------------------------------------------------------

    public function test_complete_multipart_with_wrong_etag(): void
    {
        $key = 'wrong-etag.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'part data',
        ]);

        try {
            self::$s3->completeMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => [
                    'Parts' => [
                        ['PartNumber' => 1, 'ETag' => '"0000000000000000000000000000dead"'],
                    ],
                ],
            ]);
            $this->fail('Expected InvalidPart (400)');
        } catch (S3Exception $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('InvalidPart', $e->getAwsErrorCode());
        }

        // Clean up.
        self::$s3->abortMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
        ]);
    }

    // -----------------------------------------------------------------
    // List multipart uploads with prefix
    // -----------------------------------------------------------------

    public function test_list_multipart_uploads_with_prefix(): void
    {
        $prefix = 'uploads/docs/';
        $key1 = $prefix . 'file1.txt';
        $key2 = $prefix . 'file2.txt';
        $key3 = 'other/file3.txt';

        $create1 = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key1]);
        $create2 = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key2]);
        $create3 = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key3]);

        $list = self::$s3->listMultipartUploads([
            'Bucket' => self::$bucket,
            'Prefix' => $prefix,
        ]);

        $this->assertSame(200, $list['@metadata']['statusCode']);
        $keys = array_map(fn($u) => $u['Key'], $list['Uploads'] ?? []);
        $this->assertContains($key1, $keys);
        $this->assertContains($key2, $keys);
        $this->assertNotContains($key3, $keys);

        // Clean up.
        self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key1, 'UploadId' => $create1['UploadId']]);
        self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key2, 'UploadId' => $create2['UploadId']]);
        self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key3, 'UploadId' => $create3['UploadId']]);
    }

    public function test_list_multipart_uploads_rejects_unsupported_encoding_type(): void
    {
        try {
            $command = self::$s3->getCommand('ListMultipartUploads', [
                'Bucket' => self::$bucket,
                'EncodingType' => 'gzip',
            ]);
            self::$s3->execute($command);
            $this->fail('An unsupported encoding type must not return a successful listing.');
        } catch (S3Exception $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('InvalidArgument', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Multipart with metadata
    // -----------------------------------------------------------------

    public function test_multipart_upload_with_metadata(): void
    {
        $key = 'multipart-meta.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ContentType' => 'text/csv',
            'Metadata' => [
                'project' => 'test-project',
                'version' => '1.0',
            ],
        ]);
        $uploadId = $create['UploadId'];

        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'csv,data,here',
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

        // Verify metadata is preserved after completion.
        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('text/csv', $head['ContentType']);
        $this->assertSame('test-project', $head['Metadata']['project'] ?? null);
        $this->assertSame('1.0', $head['Metadata']['version'] ?? null);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Multipart with content type
    // -----------------------------------------------------------------

    public function test_multipart_upload_with_content_type(): void
    {
        $key = 'multipart-ct.json';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ContentType' => 'application/json',
        ]);
        $uploadId = $create['UploadId'];

        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => '{"test": true}',
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

        $this->assertSame('application/json', $head['ContentType']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Single part multipart upload
    // -----------------------------------------------------------------

    public function test_multipart_single_part_upload(): void
    {
        $key = 'single-part.txt';
        $body = 'single part is valid';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => $body,
        ]);

        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part['ETag']],
                ],
            ],
        ]);

        $this->assertSame(200, $complete['@metadata']['statusCode']);

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Overwrite existing object with multipart
    // -----------------------------------------------------------------

    public function test_multipart_overwrite_existing_object(): void
    {
        $key = 'overwrite-multipart.txt';

        // Put a regular object.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'original content',
        ]);

        // Overwrite via multipart.
        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => 'multipart overwrite',
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

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('multipart overwrite', (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Concurrent multipart uploads to same key
    // -----------------------------------------------------------------

    public function test_concurrent_multipart_uploads(): void
    {
        $key = 'concurrent-multipart.txt';

        // Start two multipart uploads for the same key.
        $create1 = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId1 = $create1['UploadId'];

        $create2 = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId2 = $create2['UploadId'];

        // Upload IDs should be different.
        $this->assertNotSame($uploadId1, $uploadId2);

        // Upload parts to both.
        $part1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId1,
            'PartNumber' => 1,
            'Body' => 'upload 1 content',
        ]);

        $part2 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId2,
            'PartNumber' => 1,
            'Body' => 'upload 2 content',
        ]);

        // Complete both.
        self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId1,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part1['ETag']],
                ],
            ],
        ]);

        self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId2,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part2['ETag']],
                ],
            ],
        ]);

        // The last completed upload should win.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('upload 2 content', (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // List multipart uploads filtering
    // -----------------------------------------------------------------

    public function test_list_multipart_uploads_with_max_uploads(): void
    {
        $key1 = 'max-up/file1.txt';
        $key2 = 'max-up/file2.txt';

        $create1 = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key1]);
        $create2 = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key2]);

        // List with max-uploads=1 should return at most one upload.
        $list = self::$s3->listMultipartUploads([
            'Bucket' => self::$bucket,
            'Prefix' => 'max-up/',
            'MaxUploads' => 1,
        ]);

        $this->assertSame(200, $list['@metadata']['statusCode']);
        $this->assertCount(1, $list['Uploads'] ?? []);

        // Clean up.
        self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key1, 'UploadId' => $create1['UploadId']]);
        self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key2, 'UploadId' => $create2['UploadId']]);
    }
}
