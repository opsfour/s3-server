<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;

/**
 * Functional tests for presigned URL edge cases and error conditions.
 *
 * Extends coverage beyond basic presigned GET/PUT to include all HTTP methods,
 * expiration, tampered signatures, response overrides, and special characters.
 */
final class PresignedUrlEdgeCasesTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'presigned-edge-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Basic presigned operations for all methods
    // -----------------------------------------------------------------

    public function test_presigned_get_object(): void
    {
        $key = 'presigned-get.txt';
        $body = 'presigned get content';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client();
        $response = $client->get($presignedUrl);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($body, $response->getBody()->getContents());
    }

    public function test_presigned_put_object(): void
    {
        $key = 'presigned-put.txt';
        $body = 'uploaded via presigned PUT';

        $cmd = self::$s3->getCommand('PutObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client();
        $response = $client->put($presignedUrl, ['body' => $body]);
        $this->assertSame(200, $response->getStatusCode());

        // Verify content was stored.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);
    }

    public function test_presigned_head_object(): void
    {
        $key = 'presigned-head.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'head test',
            'ContentType' => 'text/plain',
        ]);

        $cmd = self::$s3->getCommand('HeadObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client();
        $response = $client->head($presignedUrl);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('9', $response->getHeaderLine('Content-Length'));
    }

    public function test_presigned_delete_object(): void
    {
        $key = 'presigned-delete.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'delete me',
        ]);

        $cmd = self::$s3->getCommand('DeleteObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client();
        $response = $client->delete($presignedUrl);
        $this->assertSame(204, $response->getStatusCode());

        // Verify it's gone.
        try {
            self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => $key]);
            $this->fail('Expected 404 after presigned delete');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // Expired / tampered presigned URLs
    // -----------------------------------------------------------------

    public function test_presigned_url_expired(): void
    {
        $key = 'presigned-expired.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'will expire',
        ]);

        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        // Expire immediately (1 second, then sleep).
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+1 second')->getUri();

        sleep(2);

        $client = new Client(['http_errors' => false]);
        $response = $client->get($presignedUrl);

        $this->assertSame(403, $response->getStatusCode());

        // Verify the error body contains an S3 error code.
        $body = $response->getBody()->getContents();
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        $this->assertNotEmpty((string) $xml->Code);
    }

    public function test_presigned_url_wrong_signature(): void
    {
        $key = 'presigned-tampered.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'do not tamper',
        ]);

        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        // Tamper with the signature.
        $tamperedUrl = preg_replace('/X-Amz-Signature=[a-f0-9]+/', 'X-Amz-Signature=0000000000000000000000000000000000000000000000000000000000000000', $presignedUrl);
        self::assertIsString($tamperedUrl);

        $client = new Client(['http_errors' => false]);
        $response = $client->get($tamperedUrl);

        $this->assertSame(403, $response->getStatusCode());

        // Verify the error body contains SignatureDoesNotMatch.
        $body = $response->getBody()->getContents();
        $xml = simplexml_load_string($body);
        $this->assertNotFalse($xml);
        $this->assertSame('SignatureDoesNotMatch', (string) $xml->Code);
    }

    public function test_presigned_url_non_existent_object(): void
    {
        $key = 'presigned-nosuchkey-' . uniqid();

        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client(['http_errors' => false]);
        $response = $client->get($presignedUrl);

        $this->assertSame(404, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Response overrides
    // -----------------------------------------------------------------

    public function test_presigned_get_with_response_overrides(): void
    {
        $key = 'presigned-overrides.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'override test',
            'ContentType' => 'text/plain',
        ]);

        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ResponseContentType' => 'application/octet-stream',
            'ResponseContentDisposition' => 'attachment; filename="download.txt"',
            'ResponseCacheControl' => 'no-cache',
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client();
        $response = $client->get($presignedUrl);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame('application/octet-stream', $response->getHeaderLine('Content-Type'));
        $this->assertSame('attachment; filename="download.txt"', $response->getHeaderLine('Content-Disposition'));
        $this->assertSame('no-cache', $response->getHeaderLine('Cache-Control'));
    }

    // -----------------------------------------------------------------
    // Special characters in keys
    // -----------------------------------------------------------------

    public function test_presigned_url_with_special_character_key(): void
    {
        $key = 'presigned folder/my file (1).txt';
        $body = 'special chars content';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+5 minutes')->getUri();

        $client = new Client();
        $response = $client->get($presignedUrl);

        $this->assertSame(200, $response->getStatusCode());
        $this->assertSame($body, $response->getBody()->getContents());
    }

    // -----------------------------------------------------------------
    // Max expiration
    // -----------------------------------------------------------------

    public function test_presigned_url_max_expiration(): void
    {
        $key = 'presigned-maxexpiry.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'max expiry test',
        ]);

        // 7 days = 604800 seconds — the maximum allowed.
        $cmd = self::$s3->getCommand('GetObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $presignedUrl = (string) self::$s3->createPresignedRequest($cmd, '+7 days')->getUri();

        $client = new Client();
        $response = $client->get($presignedUrl);

        $this->assertSame(200, $response->getStatusCode());
    }

    // -----------------------------------------------------------------
    // Custom metadata via presigned PUT
    // -----------------------------------------------------------------

    public function test_presigned_put_with_custom_metadata(): void
    {
        $key = 'presigned-meta.txt';
        $body = 'metadata via presigned';

        $cmd = self::$s3->getCommand('PutObject', [
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ContentType' => 'text/plain',
            'Metadata' => [
                'custom-field' => 'custom-value',
            ],
        ]);
        $presignedRequest = self::$s3->createPresignedRequest($cmd, '+5 minutes');
        $presignedUrl = (string) $presignedRequest->getUri();

        // Include the signed headers when making the request.
        $headers = [];
        foreach ($presignedRequest->getHeaders() as $name => $values) {
            $lower = strtolower($name);
            if (str_starts_with($lower, 'x-amz-meta-') || $lower === 'content-type') {
                $headers[$name] = $values[0];
            }
        }

        $client = new Client();
        $response = $client->put($presignedUrl, [
            'body' => $body,
            'headers' => $headers,
        ]);
        $this->assertSame(200, $response->getStatusCode());

        // Verify metadata was stored.
        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame('custom-value', $head['Metadata']['custom-field'] ?? null);
    }
}
