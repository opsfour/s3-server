<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for HTTP header roundtripping, special object key handling,
 * response overrides, and metadata edge cases.
 */
final class ObjectHeadersAndSpecialKeysTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'headers-keys-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Header roundtripping
    // -----------------------------------------------------------------

    public function test_put_object_with_cache_control(): void
    {
        $key = 'cache-control-test.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'test',
            'CacheControl' => 'max-age=3600, public',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $cacheControl = $result['CacheControl']
            ?? $result['@metadata']['headers']['cache-control']
            ?? '';
        $this->assertSame('max-age=3600, public', $cacheControl);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_with_content_disposition(): void
    {
        $key = 'content-disp-test.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'test',
            'ContentDisposition' => 'attachment; filename="report.pdf"',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('attachment; filename="report.pdf"', $result['ContentDisposition'] ?? $result['@metadata']['headers']['content-disposition'] ?? '');

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_with_content_encoding(): void
    {
        $key = 'content-enc-test.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'test',
            'ContentEncoding' => 'gzip',
        ]);

        // Use headObject — getObject with Content-Encoding: gzip causes cURL
        // to attempt decompression of the non-gzip body.
        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('gzip', $result['ContentEncoding'] ?? $result['@metadata']['headers']['content-encoding'] ?? '');

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_default_content_type(): void
    {
        $key = 'default-ct-test.dat';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'binary data',
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        // Default content-type should be application/octet-stream.
        $this->assertSame('application/octet-stream', $result['ContentType']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_with_content_type(): void
    {
        $key = 'content-type-test.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => '<html></html>',
            'ContentType' => 'text/html; charset=utf-8',
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('text/html; charset=utf-8', $result['ContentType']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Multiple metadata headers
    // -----------------------------------------------------------------

    public function test_put_object_with_multiple_metadata(): void
    {
        $key = 'multi-meta.txt';
        $metadata = [
            'author' => 'Jane Doe',
            'department' => 'Engineering',
            'project' => 'S3Server',
            'version' => '1.0.0',
            'environment' => 'test',
        ];

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'multi metadata test',
            'Metadata' => $metadata,
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        foreach ($metadata as $metaKey => $metaValue) {
            $this->assertSame($metaValue, $result['Metadata'][$metaKey] ?? null, "Metadata '{$metaKey}' should roundtrip");
        }

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_put_object_with_empty_metadata_value(): void
    {
        $key = 'empty-meta.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'empty meta test',
            'Metadata' => [
                'empty-value' => '',
                'nonempty' => 'has-value',
            ],
        ]);

        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('', $result['Metadata']['empty-value'] ?? 'MISSING');
        $this->assertSame('has-value', $result['Metadata']['nonempty'] ?? null);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Special object keys
    // -----------------------------------------------------------------

    public function test_object_key_with_spaces(): void
    {
        $key = 'my folder/my file.txt';
        $body = 'space key content';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_object_key_with_unicode(): void
    {
        $key = 'tests/日本語/ファイル.txt';
        $body = 'unicode key content';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_object_key_with_special_chars(): void
    {
        $key = 'special/!@#$%^&()/file.txt';
        $body = 'special chars key content';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_object_key_with_dots(): void
    {
        // Keys with dots (not path-traversal patterns) should work fine.
        $key = 'path/to/file.v2.0.txt';
        $body = 'dots in key';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_object_key_max_length(): void
    {
        // 1024 chars — maximum S3 key length.
        $key = str_repeat('a', 1024);
        $body = 'max length key';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => $body,
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);
        $this->assertSame($body, (string) $result['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Response header overrides via query parameters
    // -----------------------------------------------------------------

    public function test_get_object_with_response_overrides(): void
    {
        $key = 'response-overrides.txt';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => 'override test',
            'ContentType' => 'text/plain',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ResponseContentType' => 'application/octet-stream',
            'ResponseContentDisposition' => 'attachment; filename="download.bin"',
            'ResponseCacheControl' => 'no-store',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame('application/octet-stream', $result['ContentType']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    public function test_get_object_with_response_content_type(): void
    {
        $key = 'response-ct.html';
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => '<h1>Hello</h1>',
            'ContentType' => 'text/html',
        ]);

        // Override content-type to force download.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'ResponseContentType' => 'application/octet-stream',
        ]);

        $this->assertSame('application/octet-stream', $result['ContentType']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Zero-byte object
    // -----------------------------------------------------------------

    public function test_put_object_zero_bytes(): void
    {
        $key = 'empty-object.dat';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
            'Body' => '',
        ]);

        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $key,
        ]);

        $this->assertSame('', (string) $result['Body']);
        $this->assertSame(0, $result['ContentLength']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
    }

    // -----------------------------------------------------------------
    // Copy with metadata REPLACE
    // -----------------------------------------------------------------

    public function test_copy_object_with_metadata_replace(): void
    {
        $srcKey = 'copy-meta-src.txt';
        $dstKey = 'copy-meta-dst.txt';

        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => $srcKey,
            'Body' => 'source content',
            'ContentType' => 'text/plain',
            'Metadata' => [
                'original' => 'true',
                'version' => '1',
            ],
        ]);

        // Copy with REPLACE directive — new metadata replaces all source metadata.
        self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => $dstKey,
            'CopySource' => self::$bucket . '/' . $srcKey,
            'MetadataDirective' => 'REPLACE',
            'ContentType' => 'application/json',
            'Metadata' => [
                'replaced' => 'yes',
                'new-field' => 'new-value',
            ],
        ]);

        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => $dstKey,
        ]);

        $this->assertSame('application/json', $head['ContentType']);
        $this->assertSame('yes', $head['Metadata']['replaced'] ?? null);
        $this->assertSame('new-value', $head['Metadata']['new-field'] ?? null);
        // Original metadata should NOT be present.
        $this->assertArrayNotHasKey('original', $head['Metadata'] ?? []);
        $this->assertArrayNotHasKey('version', $head['Metadata'] ?? []);

        // Content should be preserved despite metadata replacement.
        $get = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => $dstKey,
        ]);
        $this->assertSame('source content', (string) $get['Body']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $srcKey]);
        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $dstKey]);
    }
}
