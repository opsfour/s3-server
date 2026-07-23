<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;

/**
 * Functional tests for checksum validation: Content-MD5, CRC32, CRC32C,
 * SHA-1, SHA-256, and ETag format for multipart uploads.
 */
final class ChecksumValidationTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'checksum-test-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Content-MD5 validation
    // -----------------------------------------------------------------

    public function test_put_object_with_content_md5_valid(): void
    {
        $body = 'Hello, Content-MD5!';
        $md5 = base64_encode(md5($body, true));

        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'md5-valid.txt',
            'Body' => $body,
            'ContentMD5' => $md5,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'md5-valid.txt']);
    }

    public function test_put_object_with_content_md5_invalid(): void
    {
        $body = 'Hello, Content-MD5!';
        $wrongMd5 = base64_encode(md5('wrong content', true));

        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => 'md5-invalid.txt',
                'Body' => $body,
                'ContentMD5' => $wrongMd5,
            ]);
            $this->fail('Expected BadDigest (400)');
        } catch (S3Exception $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('BadDigest', $e->getAwsErrorCode());
        }
    }

    public function test_put_object_over_16_mib_with_content_md5_is_validated_and_round_trips(): void
    {
        $body = str_repeat('large-md5-block-', 1_400_000);
        $key = 'md5-large-valid.bin';

        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentMD5' => base64_encode(md5($body, true)),
            ]);
        } catch (S3Exception $e) {
            $this->fail($e->getMessage() . "\n" . self::serverLogs());
        }

        $result = self::$s3->getObject(['Bucket' => self::$bucket, 'Key' => $key]);
        $this->assertSame(strlen($body), $result['ContentLength']);
        $this->assertSame(md5($body), md5((string) $result['Body']));

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_over_16_mib_with_wrong_content_md5_is_rejected_before_write(): void
    {
        $body = str_repeat('large-invalid-md5-', 1_100_000);
        $key = 'md5-large-invalid.bin';

        try {
            self::$s3->putObject([
                'Bucket' => self::$bucket,
                'Key' => $key,
                'Body' => $body,
                'ContentMD5' => base64_encode(md5('wrong', true)),
            ]);
            $this->fail('Expected BadDigest for large object.');
        } catch (S3Exception $e) {
            $this->assertSame(400, $e->getStatusCode());
            $this->assertSame('BadDigest', $e->getAwsErrorCode());
        }

        try {
            self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => $key]);
            $this->fail('Large object with invalid Content-MD5 must not be persisted.');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // ETag matches MD5 for non-multipart uploads
    // -----------------------------------------------------------------

    public function test_get_object_etag_matches_md5(): void
    {
        $body = 'ETag should be MD5 hex';
        $expectedEtag = '"' . md5($body) . '"';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'etag-md5.txt',
            'Body' => $body,
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'etag-md5.txt',
        ]);

        $this->assertSame($expectedEtag, $result['ETag']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'etag-md5.txt']);
    }

    // -----------------------------------------------------------------
    // Multipart ETag format
    // -----------------------------------------------------------------

    public function test_multipart_etag_format(): void
    {
        $key = 'multipart-etag.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        $part1 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => str_repeat('A', 5 * 1024 * 1024),
        ]);

        $part2 = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 2,
            'Body' => str_repeat('B', 1024),
        ]);

        $complete = self::$s3->completeMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'MultipartUpload' => [
                'Parts' => [
                    ['PartNumber' => 1, 'ETag' => $part1['ETag']],
                    ['PartNumber' => 2, 'ETag' => $part2['ETag']],
                ],
            ],
        ]);

        // Multipart ETag should have -N suffix indicating number of parts.
        $etag = $complete['ETag'];
        $this->assertMatchesRegularExpression('/^"[a-f0-9]+-2"$/', $etag, 'Multipart ETag should match format "hash-2"');

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // SHA-256 checksum via x-amz-content-sha256
    // -----------------------------------------------------------------

    public function test_put_object_with_sha256_checksum_header(): void
    {
        $body = 'SHA-256 checksum test';

        // The SDK normally handles x-amz-content-sha256, so we verify it works.
        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'sha256-valid.txt',
            'Body' => $body,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        // Verify content is stored correctly.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'sha256-valid.txt',
        ]);
        $this->assertSame($body, (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'sha256-valid.txt']);
    }

    // -----------------------------------------------------------------
    // Checksum algorithms via SDK
    // -----------------------------------------------------------------

    public function test_put_object_with_crc32_checksum(): void
    {
        $body = 'CRC32 checksum test content';

        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'crc32-test.txt',
            'Body' => $body,
            'ChecksumAlgorithm' => 'CRC32',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'crc32-test.txt']);
    }

    public function test_put_object_with_sha1_checksum(): void
    {
        $body = 'SHA1 checksum test content';

        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'sha1-test.txt',
            'Body' => $body,
            'ChecksumAlgorithm' => 'SHA1',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'sha1-test.txt']);
    }

    public function test_put_object_with_sha256_checksum(): void
    {
        $body = 'SHA256 checksum test content';

        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'sha256-algo-test.txt',
            'Body' => $body,
            'ChecksumAlgorithm' => 'SHA256',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'sha256-algo-test.txt']);
    }

    // -----------------------------------------------------------------
    // Copy preserves checksum
    // -----------------------------------------------------------------

    public function test_copy_object_preserves_etag_content(): void
    {
        $body = 'copy checksum test';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'checksum-src.txt',
            'Body' => $body,
        ]);

        $srcHead = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'checksum-src.txt',
        ]);

        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'checksum-dst.txt',
            'CopySource' => self::$bucket . '/checksum-src.txt',
        ]);

        $dstHead = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'checksum-dst.txt',
        ]);

        // The content is the same, so the ETags should match.
        $this->assertSame($srcHead['ETag'], $dstHead['ETag']);

        // Verify the content is identical.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'checksum-dst.txt',
        ]);
        $this->assertSame($body, (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'checksum-src.txt']);
        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'checksum-dst.txt']);
    }

    // -----------------------------------------------------------------
    // Content-MD5 with valid content
    // -----------------------------------------------------------------

    public function test_put_object_content_md5_roundtrip(): void
    {
        $body = 'Content-MD5 roundtrip test with special chars: äöü 日本語';
        $md5 = base64_encode(md5($body, true));

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'md5-roundtrip.txt',
            'Body' => $body,
            'ContentMD5' => $md5,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'md5-roundtrip.txt',
        ]);

        $this->assertSame($body, (string) $result['Body']);

        // ETag should match the MD5 of the content.
        $expectedEtag = '"' . md5($body) . '"';
        $this->assertSame($expectedEtag, $result['ETag']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'md5-roundtrip.txt']);
    }

    // -----------------------------------------------------------------
    // Multipart upload part checksums
    // -----------------------------------------------------------------

    public function test_multipart_upload_parts_have_etags(): void
    {
        $key = 'multipart-parts-etag.txt';

        $create = self::$s3->createMultipartUpload([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $uploadId = $create['UploadId'];

        $partData = 'part content for etag test';
        $expectedPartEtag = '"' . md5($partData) . '"';

        $part = self::$s3->uploadPart([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'UploadId' => $uploadId,
            'PartNumber' => 1,
            'Body' => $partData,
        ]);

        // Each part should have an ETag that matches its MD5.
        $this->assertSame($expectedPartEtag, $part['ETag']);

        // Complete and clean up.
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

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Empty body Content-MD5
    // -----------------------------------------------------------------

    // -----------------------------------------------------------------
    // Negative: wrong checksum should be rejected (BadDigest)
    // -----------------------------------------------------------------

    public function test_put_object_with_wrong_crc32_checksum_rejected(): void
    {
        $body = 'test body for wrong checksum';
        $key = 'wrong-crc32.txt';
        $wrongChecksum = 'AAAABB=='; // deliberately wrong CRC32

        $response = $this->signedPutWithChecksum(self::$bucket, $key, $body, 'x-amz-checksum-crc32', $wrongChecksum);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('BadDigest', (string) $response->getBody());
    }

    public function test_put_object_with_wrong_sha256_checksum_rejected(): void
    {
        $body = 'test body for wrong sha256';
        $key = 'wrong-sha256.txt';
        $wrongChecksum = base64_encode(random_bytes(32)); // deliberately wrong SHA-256

        $response = $this->signedPutWithChecksum(self::$bucket, $key, $body, 'x-amz-checksum-sha256', $wrongChecksum);

        $this->assertSame(400, $response->getStatusCode());
        $this->assertStringContainsString('BadDigest', (string) $response->getBody());
    }

    /**
     * Send a PUT request with a manually computed SigV4 signature and a custom checksum header.
     * Uses UNSIGNED-PAYLOAD so the checksum header doesn't affect the signature.
     */
    private function signedPutWithChecksum(string $bucket, string $key, string $body, string $checksumHeader, string $checksumValue): \Psr\Http\Message\ResponseInterface
    {
        $now = new \DateTimeImmutable('now', new \DateTimeZone('UTC'));
        $date = $now->format('Ymd');
        $amzDate = $now->format('Ymd\THis\Z');
        $region = 'us-east-1';
        $service = 's3';
        $host = self::$host . ':' . self::$port;
        $uri = "/{$bucket}/{$key}";

        $headers = [
            'host' => $host,
            'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
            'x-amz-date' => $amzDate,
            $checksumHeader => $checksumValue,
        ];

        // Canonical request
        ksort($headers);
        $signedHeaderNames = implode(';', array_keys($headers));
        $canonicalHeaders = '';
        foreach ($headers as $k => $v) {
            $canonicalHeaders .= "{$k}:{$v}\n";
        }

        $canonicalRequest = "PUT\n{$uri}\n\n{$canonicalHeaders}\n{$signedHeaderNames}\nUNSIGNED-PAYLOAD";

        // String to sign
        $credentialScope = "{$date}/{$region}/{$service}/aws4_request";
        $stringToSign = "AWS4-HMAC-SHA256\n{$amzDate}\n{$credentialScope}\n" . hash('sha256', $canonicalRequest);

        // Signing key
        $kDate = hash_hmac('sha256', $date, 'AWS4' . self::$secretKey, true);
        $kRegion = hash_hmac('sha256', $region, $kDate, true);
        $kService = hash_hmac('sha256', $service, $kRegion, true);
        $kSigning = hash_hmac('sha256', 'aws4_request', $kService, true);

        $signature = hash_hmac('sha256', $stringToSign, $kSigning);

        $authHeader = "AWS4-HMAC-SHA256 Credential=" . self::$accessKey . "/{$credentialScope}, SignedHeaders={$signedHeaderNames}, Signature={$signature}";

        $client = new Client(['http_errors' => false]);
        return $client->put("http://{$host}{$uri}", [
            'body' => $body,
            'headers' => [
                'Authorization' => $authHeader,
                'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
                'x-amz-date' => $amzDate,
                $checksumHeader => $checksumValue,
            ],
        ]);
    }

    // -----------------------------------------------------------------
    // Empty body Content-MD5
    // -----------------------------------------------------------------

    public function test_put_empty_object_with_content_md5(): void
    {
        $body = '';
        $md5 = base64_encode(md5($body, true));

        $result = self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'empty-md5.txt',
            'Body' => $body,
            'ContentMD5' => $md5,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'empty-md5.txt',
        ]);
        $this->assertSame('', (string) $get['Body']);
        $this->assertSame(0, $get['ContentLength']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'empty-md5.txt']);
    }
}
