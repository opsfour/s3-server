<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;

/**
 * Functional tests for S3 error handling: XML format, HTTP status codes,
 * error conditions, and request ID presence.
 *
 * Uses both the AWS SDK (for standard error assertions) and raw Guzzle HTTP
 * (for XML format verification, since the SDK hides response details).
 */
final class ErrorHandlingTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'error-test-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Authentication errors
    // -----------------------------------------------------------------

    public function test_invalid_access_key(): void
    {
        $badClient = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => 'INVALID_ACCESS_KEY',
                'secret' => self::$secretKey,
            ],
        ]);

        try {
            $badClient->listBuckets();
            $this->fail('Expected InvalidAccessKeyId (403)');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('InvalidAccessKeyId', $e->getAwsErrorCode());
        }
    }

    public function test_invalid_secret_key(): void
    {
        $badClient = new \Aws\S3\S3Client([
            'version' => 'latest',
            'region' => 'us-east-1',
            'endpoint' => sprintf('http://%s:%d', self::$host, self::$port),
            'use_path_style_endpoint' => true,
            'credentials' => [
                'key' => self::$accessKey,
                'secret' => 'WRONG_SECRET_KEY_VALUE',
            ],
        ]);

        try {
            $badClient->listBuckets();
            $this->fail('Expected SignatureDoesNotMatch (403)');
        } catch (S3Exception $e) {
            $this->assertSame(403, $e->getStatusCode());
            $this->assertSame('SignatureDoesNotMatch', $e->getAwsErrorCode());
        }
    }

    public function test_missing_auth_header(): void
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->get(sprintf('http://%s:%d/', self::$host, self::$port));

        $this->assertSame(403, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Object not found errors
    // -----------------------------------------------------------------

    public function test_get_non_existent_object(): void
    {
        try {
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'no-such-key-' . uniqid(),
            ]);
            $this->fail('Expected NoSuchKey (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }
    }

    public function test_head_non_existent_object(): void
    {
        try {
            self::$s3->headObject([
                'Bucket' => self::$bucket,
                'Key' => 'no-such-key-' . uniqid(),
            ]);
            $this->fail('Expected 404 for HeadObject');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    public function test_get_from_non_existent_bucket(): void
    {
        try {
            self::$s3->getObject([
                'Bucket' => 'nonexistent-bucket-' . bin2hex(random_bytes(4)),
                'Key' => 'any-key',
            ]);
            $this->fail('Expected NoSuchBucket (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchBucket', $e->getAwsErrorCode());
        }
    }

    public function test_put_object_to_non_existent_bucket(): void
    {
        try {
            self::$s3->putObject([
                'Bucket' => 'nonexistent-bucket-' . bin2hex(random_bytes(4)),
                'Key' => 'any-key',
                'Body' => 'content',
            ]);
            $this->fail('Expected NoSuchBucket (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchBucket', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Idempotent delete
    // -----------------------------------------------------------------

    public function test_delete_non_existent_object_returns_204(): void
    {
        $result = self::$s3->deleteObject([
            'Bucket' => self::$bucket,
            'Key' => 'nonexistent-' . uniqid(),
        ]);

        $this->assertSame(204, $result['@metadata']['statusCode']);
    }

    // -----------------------------------------------------------------
    // Error XML format
    // -----------------------------------------------------------------

    public function test_error_response_xml_format(): void
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->get(
            sprintf('http://%s:%d/nonexistent-bucket-%s/key', self::$host, self::$port, bin2hex(random_bytes(4))),
        );

        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());

        $body = $response->getBody()->getContents();

        // Verify it's valid XML with the S3 error structure.
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml, 'Error response should be valid XML');
        $this->assertSame('Error', $xml->getName());
        $this->assertNotEmpty((string) $xml->Code, 'Error XML should contain <Code>');
        $this->assertNotEmpty((string) $xml->Message, 'Error XML should contain <Message>');
        $this->assertNotNull($xml->RequestId, 'Error XML should contain <RequestId>');
        $this->assertNotNull($xml->Resource, 'Error XML should contain <Resource>');
    }

    // -----------------------------------------------------------------
    // Request ID headers
    // -----------------------------------------------------------------

    public function test_request_id_header_present_on_success(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'reqid-test.txt',
            'Body' => 'test',
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => 'reqid-test.txt',
        ]);

        // The x-amz-request-id header should be present.
        $requestId = $result['@metadata']['headers']['x-amz-request-id'] ?? null;
        $this->assertNotNull($requestId, 'x-amz-request-id header should be present');
        $this->assertNotEmpty($requestId);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'reqid-test.txt']);
    }

    public function test_request_id_in_error_matches_header(): void
    {
        $client = new Client(['http_errors' => false]);
        $response = $client->get(
            sprintf('http://%s:%d/nonexistent-bucket-%s/key', self::$host, self::$port, bin2hex(random_bytes(4))),
        );

        $headerRequestId = $response->getHeaderLine('x-amz-request-id');
        $this->assertNotEmpty($headerRequestId, 'x-amz-request-id header should be present on errors');

        $body = $response->getBody()->getContents();
        $xml = simplexml_load_string($body);
        $xmlRequestId = (string) ($xml->RequestId ?? '');

        $this->assertSame($headerRequestId, $xmlRequestId, 'RequestId in XML should match header');
    }

    // -----------------------------------------------------------------
    // Invalid XML body
    // -----------------------------------------------------------------

    public function test_invalid_xml_body(): void
    {
        $client = new Client(['http_errors' => false]);

        // Manually construct a CreateBucket request with malformed XML.
        $cmd = self::$s3->getCommand('CreateBucket', [
            'Bucket' => 'xml-test-' . bin2hex(random_bytes(4)),
        ]);
        $request = self::$s3->createPresignedRequest($cmd, '+5 minutes');
        $presignedUrl = (string) $request->getUri();

        $response = $client->put($presignedUrl, [
            'body' => '<invalid><xml',
            'headers' => ['Content-Type' => 'application/xml'],
        ]);

        // Should return an error (400 or similar) — not crash.
        $this->assertGreaterThanOrEqual(400, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Copy non-existent source
    // -----------------------------------------------------------------

    public function test_copy_object_source_does_not_exist(): void
    {
        try {
            self::$s3->copyObject([
                'Bucket' => self::$bucket,
                'Key' => 'copy-dest.txt',
                'CopySource' => self::$bucket . '/nonexistent-source-' . uniqid(),
            ]);
            $this->fail('Expected NoSuchKey (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchKey', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Multipart non-existent upload errors
    // -----------------------------------------------------------------

    public function test_complete_multipart_with_no_upload(): void
    {
        try {
            self::$s3->completeMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => 'nokey',
                'UploadId' => 'nonexistent-upload-id',
                'MultipartUpload' => [
                    'Parts' => [
                        ['PartNumber' => 1, 'ETag' => '"abc"'],
                    ],
                ],
            ]);
            $this->fail('Expected NoSuchUpload (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchUpload', $e->getAwsErrorCode());
        }
    }

    public function test_abort_non_existent_multipart_upload(): void
    {
        try {
            self::$s3->abortMultipartUpload([
                'Bucket' => self::$bucket,
                'Key' => 'nokey',
                'UploadId' => 'nonexistent-upload-id',
            ]);
            $this->fail('Expected NoSuchUpload (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchUpload', $e->getAwsErrorCode());
        }
    }

    public function test_list_parts_non_existent_upload(): void
    {
        try {
            self::$s3->listParts([
                'Bucket' => self::$bucket,
                'Key' => 'nokey',
                'UploadId' => 'nonexistent-upload-id',
            ]);
            $this->fail('Expected NoSuchUpload (404)');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchUpload', $e->getAwsErrorCode());
        }
    }
}
