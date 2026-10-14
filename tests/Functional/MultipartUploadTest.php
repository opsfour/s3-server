<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\S3\Exception\S3Exception;
use Aws\S3\MultipartUploader;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;

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

        // 2. Upload parts (minimum 5 MiB for every part except the last).
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
        $partNumbers = array_map(fn($p) => $p['PartNumber'], $listParts['Parts']);
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

        $body = $get['Body'];
        self::assertInstanceOf(\Psr\Http\Message\StreamInterface::class, $body);
        foreach ([$part1Data, $part2Data, $part3Data] as $part) {
            $offset = 0;
            while ($offset < strlen($part)) {
                $chunk = $body->read(min(65_536, strlen($part) - $offset));
                self::assertNotSame('', $chunk);
                self::assertSame(substr($part, $offset, strlen($chunk)), $chunk);
                $offset += strlen($chunk);
            }
        }
        self::assertSame('', $body->read(1));
        self::assertTrue($body->eof());
        self::assertSame(strlen($part1Data) + strlen($part2Data) + strlen($part3Data), $get['ContentLength']);
    }

    public function test_aws_sdk_multipart_uploader_round_trip(): void
    {
        $key = 'sdk-multipart-uploader-' . bin2hex(random_bytes(4)) . '.bin';
        $bytes = MultipartUploader::PART_MIN_SIZE + 257;
        $pattern = '0123456789abcdef';
        $expected = substr(str_repeat($pattern, (int) ceil($bytes / strlen($pattern))), 0, $bytes);
        $source = tempnam(sys_get_temp_dir(), 's3-sdk-multipart-');
        if ($source === false) {
            self::fail('Could not create the MultipartUploader source file.');
        }

        try {
            if (file_put_contents($source, $expected) !== $bytes) {
                self::fail('Could not populate the MultipartUploader source file.');
            }

            $result = (new MultipartUploader(self::$s3, $source, [
                'bucket' => self::$bucket,
                'key' => $key,
                'part_size' => MultipartUploader::PART_MIN_SIZE,
            ]))->upload();

            self::assertSame(200, $result['@metadata']['statusCode']);

            $object = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
            ]);
            self::assertSame($bytes, $object['ContentLength']);
            self::assertSame(hash('sha256', $expected), hash('sha256', (string) $object['Body']));
        } finally {
            @unlink($source);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);

            $uploads = self::$s3->listMultipartUploads([
                'Bucket' => self::$bucket,
                'Prefix' => $key,
            ]);
            foreach ($uploads['Uploads'] ?? [] as $upload) {
                if (($upload['Key'] ?? null) !== $key || ! isset($upload['UploadId'])) {
                    continue;
                }
                self::$s3->abortMultipartUpload([
                    'Bucket' => self::$bucket,
                    'Key' => $key,
                    'UploadId' => $upload['UploadId'],
                ]);
            }
        }
    }

    public function test_list_parts_pages_preserve_zero_limit_gaps_and_markers(): void
    {
        $key = 'parts-pages-' . bin2hex(random_bytes(4));
        $uploadId = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key])['UploadId'];
        $args = ['Bucket' => self::$bucket, 'Key' => $key, 'UploadId' => $uploadId];

        try {
            foreach ([1, 3, 10] as $partNumber) {
                self::$s3->uploadPart($args + ['PartNumber' => $partNumber, 'Body' => 'part-' . $partNumber]);
            }

            $zero = self::$s3->listParts($args + ['MaxParts' => 0]);
            self::assertSame([], $zero['Parts'] ?? []);
            self::assertFalse($zero['IsTruncated']);
            self::assertSame(0, $zero['NextPartNumberMarker']);

            $thousand = self::$s3->listParts($args + ['MaxParts' => 1000]);
            self::assertSame([1, 3, 10], array_column($thousand['Parts'], 'PartNumber'));
            self::assertFalse($thousand['IsTruncated']);

            $first = self::$s3->listParts($args + ['MaxParts' => 1]);
            self::assertSame([1], array_column($first['Parts'], 'PartNumber'));
            self::assertTrue($first['IsTruncated']);
            self::assertSame(1, $first['NextPartNumberMarker']);

            $gap = self::$s3->listParts($args + ['MaxParts' => 1, 'PartNumberMarker' => 2]);
            self::assertSame([3], array_column($gap['Parts'], 'PartNumber'));
            self::assertTrue($gap['IsTruncated']);
            self::assertSame(3, $gap['NextPartNumberMarker']);

            $last = self::$s3->listParts($args + ['MaxParts' => 1, 'PartNumberMarker' => 3]);
            self::assertSame([10], array_column($last['Parts'], 'PartNumber'));
            self::assertFalse($last['IsTruncated']);
            self::assertSame(0, $last['NextPartNumberMarker']);

            $beyond = self::$s3->listParts($args + ['MaxParts' => 1, 'PartNumberMarker' => PHP_INT_MAX]);
            self::assertSame([], $beyond['Parts'] ?? []);
            self::assertFalse($beyond['IsTruncated']);
            self::assertSame(0, $beyond['NextPartNumberMarker']);
        } finally {
            self::$s3->abortMultipartUpload($args);
        }
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

        $keys = array_map(fn($u) => $u['Key'], $list['Uploads']);
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

    public function test_multipart_listings_report_the_initiated_storage_class(): void
    {
        $key = 'storage-class-' . bin2hex(random_bytes(6));
        $uploadId = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'StorageClass' => 'STANDARD_IA',
        ])['UploadId'];
        $completed = false;

        try {
            $part = self::$s3->uploadPart([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => 'multipart storage class',
            ]);

            $parts = self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            self::assertSame('STANDARD_IA', $parts['StorageClass']);

            foreach ([null, '/'] as $delimiter) {
                $request = ['Bucket' => self::$bucket, 'Prefix' => $key];
                if ($delimiter !== null) {
                    $request['Delimiter'] = $delimiter;
                }
                $uploads = self::$s3->listMultipartUploads($request);
                $listed = array_values(array_filter($uploads['Uploads'] ?? [], fn(array $upload): bool => $upload['UploadId'] === $uploadId));
                self::assertCount(1, $listed);
                self::assertSame('STANDARD_IA', $listed[0]['StorageClass']);
            }

            self::$s3->completeMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MultipartUpload' => ['Parts' => [['PartNumber' => 1, 'ETag' => $part['ETag']]]],
            ]);
            $completed = true;
            self::assertSame('STANDARD_IA', self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => $key])['StorageClass']);
        } finally {
            if ($completed) {
                self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
            } else {
                self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            }
        }
    }

    public function test_zero_multipart_listing_limits_return_terminal_empty_pages(): void
    {
        $key = 'zero-limit/file-' . bin2hex(random_bytes(4));
        $uploadId = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['UploadId'];

        try {
            self::$s3->uploadPart([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => 'one part',
            ]);

            $parts = self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'MaxParts' => 0,
            ]);
            self::assertSame([], $parts['Parts'] ?? []);
            self::assertFalse($parts['IsTruncated']);

            foreach ([null, '/'] as $delimiter) {
                $request = [
                    'Bucket' => self::$bucket,
                    'Prefix' => 'zero-limit/',
                    'MaxUploads' => 0,
                ];
                if ($delimiter !== null) {
                    $request['Delimiter'] = $delimiter;
                }
                $uploads = self::$s3->listMultipartUploads($request);
                self::assertSame([], $uploads['Uploads'] ?? []);
                self::assertSame([], $uploads['CommonPrefixes'] ?? []);
                self::assertFalse($uploads['IsTruncated']);
            }

            $normalParts = self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            self::assertSame([1], array_column($normalParts['Parts'], 'PartNumber'));
        } finally {
            self::$s3->abortMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        }
    }

    public function test_signed_listing_endpoints_reject_malformed_numeric_query_fields(): void
    {
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $client = new Client(['http_errors' => false]);
        $baseUrl = sprintf('http://%s:%d/%s', self::$host, self::$port, self::$bucket);

        $queries = [
            '?max-keys=1junk',
            '?list-type=2&max-keys=-1',
            '?versions&max-keys=1junk',
            '?uploads&max-uploads=1junk',
            '/key?uploadId=missing&max-parts=1junk',
            '/key?uploadId=missing&part-number-marker=1junk',
            '/key?uploadId=missing&part-number-marker=' . str_repeat('9', 40),
        ];

        foreach ($queries as $query) {
            $signed = $signer->signRequest(new Request('GET', $baseUrl . $query), $credentials);
            $response = $client->send($signed);
            self::assertSame(400, $response->getStatusCode(), $query . ': ' . (string) $response->getBody());
            self::assertStringContainsString('<Code>InvalidArgument</Code>', (string) $response->getBody(), $query);
        }

        $signed = $signer->signRequest(new Request('GET', $baseUrl . '?list-type=2&max-keys=0'), $credentials);
        self::assertSame(200, $client->send($signed)->getStatusCode());

        foreach (['?uploads&upload-id-marker=orphaned', '?uploads&key-marker=&upload-id-marker=orphaned'] as $query) {
            $signed = $signer->signRequest(new Request('GET', $baseUrl . $query), $credentials);
            self::assertSame(200, $client->send($signed)->getStatusCode());
        }
    }

    public function test_signed_incomplete_upload_part_query_cannot_overwrite_object(): void
    {
        $key = 'incomplete-part-query-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'original']);
        $uploadId = self::$s3->createMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key])['UploadId'];
        try {
            $signer = new S3SignatureV4('s3', 'us-east-1');
            $credentials = new Credentials(self::$accessKey, self::$secretKey);
            $client = new Client(['http_errors' => false]);
            $url = sprintf('http://%s:%d/%s/%s', self::$host, self::$port, self::$bucket, $key);
            foreach (['?uploadId=' . rawurlencode($uploadId), '?partNumber=1', '?partNumber=&uploadId=' . rawurlencode($uploadId)] as $query) {
                $signed = $signer->signRequest(new Request('PUT', $url . $query, [], 'replacement'), $credentials);
                $response = $client->send($signed);
                self::assertSame(400, $response->getStatusCode(), $query . ': ' . (string) $response->getBody());
                self::assertSame('original', (string) self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key])['Body']);
            }
            self::assertEmpty(self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ])['Parts'] ?? []);
        } finally {
            self::$s3->abortMultipartUpload(['Bucket' => self::$bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_signed_wrong_scope_query_selectors_cannot_mutate_bucket_or_object(): void
    {
        $bucket = 'query-guard-' . bin2hex(random_bytes(4));
        $key = 'guarded.txt';
        $signer = new S3SignatureV4('s3', 'us-east-1');
        $credentials = new Credentials(self::$accessKey, self::$secretKey);
        $client = new Client(['http_errors' => false]);
        $bucketUrl = sprintf('http://%s:%d/%s', self::$host, self::$port, $bucket);
        $objectUrl = $bucketUrl . '/' . $key;
        $sendSigned = static function (string $method, string $url, string $body = '') use ($signer, $credentials, $client): \Psr\Http\Message\ResponseInterface {
            return $client->send($signer->signRequest(new Request($method, $url, [], $body), $credentials));
        };

        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            foreach ([
                ['PUT', $bucketUrl . '?key-marker=key'],
                ['DELETE', $bucketUrl . '?max-keys=1'],
            ] as [$method, $url]) {
                $response = $sendSigned($method, $url);
                self::assertSame(405, $response->getStatusCode(), $url);
                self::assertStringContainsString('<Code>MethodNotAllowed</Code>', (string) $response->getBody(), $url);
                self::assertSame(200, self::$s3->headBucket(['Bucket' => $bucket])['@metadata']['statusCode']);
            }

            self::assertSame(200, $sendSigned('GET', $bucketUrl . '?list-type=2&prefix=guarded')->getStatusCode());

            self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => 'original']);
            foreach ([
                ['PUT', $objectUrl . '?versionId=version', 'replacement'],
                ['PUT', $objectUrl . '?acl&partNumber=1', 'replacement'],
                ['DELETE', $objectUrl . '?partNumber=1', ''],
            ] as [$method, $url, $body]) {
                $response = $sendSigned($method, $url, $body);
                self::assertSame(405, $response->getStatusCode(), $url);
                self::assertStringContainsString('<Code>MethodNotAllowed</Code>', (string) $response->getBody(), $url);
                self::assertSame('original', (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
            }

            $wrongRead = $sendSigned('GET', $objectUrl . '?acl&response-content-type=text%2Fplain');
            self::assertSame(405, $wrongRead->getStatusCode());
            self::assertStringContainsString('<Code>MethodNotAllowed</Code>', (string) $wrongRead->getBody());
            $validRead = $sendSigned('GET', $objectUrl . '?response-content-type=text%2Fplain');
            self::assertSame(200, $validRead->getStatusCode());
            self::assertSame('original', (string) $validRead->getBody());

            $uploadId = self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key])['UploadId'];
            try {
                self::assertSame(200, $sendSigned('GET', $objectUrl . '?uploadId=' . rawurlencode($uploadId) . '&max-parts=1')->getStatusCode());
            } finally {
                self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            }
        } finally {
            if (self::$s3->doesBucketExistV2($bucket)) {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
                self::$s3->deleteBucket(['Bucket' => $bucket]);
            }
        }
    }

    public function test_abort_rejects_upload_id_for_another_key_without_consuming_parts(): void
    {
        $key = 'abort-owner-key-' . bin2hex(random_bytes(4));
        $uploadId = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ])['UploadId'];

        try {
            self::$s3->uploadPart([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => 'part remains after rejected abort',
            ]);

            try {
                self::$s3->abortMultipartUpload([
                    'Bucket' => self::$bucket,
                    'Key' => 'another-key.txt',
                    'UploadId' => $uploadId,
                ]);
                self::fail('AbortMultipartUpload accepted an upload ID for another key.');
            } catch (S3Exception $e) {
                self::assertSame(404, $e->getStatusCode());
                self::assertSame('NoSuchUpload', $e->getAwsErrorCode());
            }

            $parts = self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
            self::assertSame([1], array_column($parts['Parts'], 'PartNumber'));
        } finally {
            self::$s3->abortMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
            ]);
        }
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
