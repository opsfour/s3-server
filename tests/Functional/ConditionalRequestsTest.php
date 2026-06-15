<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for conditional request headers:
 * If-Match, If-None-Match, If-Modified-Since, If-Unmodified-Since
 * for GET, HEAD, and CopyObject operations.
 *
 * Notes:
 * - 304 responses: AWS SDK returns a result (not exception), check statusCode.
 * - 412 responses: SDK throws S3Exception with PreconditionFailed code.
 */
final class ConditionalRequestsTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    private static bool $seeded = false;

    private static string $testKey = 'conditional-test.txt';

    private static string $testEtag = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$bucket = 'cond-req-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);

        // Create a test object.
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'Body' => 'conditional request test content',
        ]);

        // Get its ETag.
        $head = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
        ]);
        self::$testEtag = $head['ETag'];

        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // GET: If-Match
    // -----------------------------------------------------------------

    public function test_get_object_if_match_success(): void
    {
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfMatch' => self::$testEtag,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
        $this->assertSame('conditional request test content', (string) $result['Body']);
    }

    public function test_get_object_if_match_failure(): void
    {
        try {
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => self::$testKey,
                'IfMatch' => '"0000000000000000000000000000dead"',
            ]);
            $this->fail('Expected PreconditionFailed (412)');
        } catch (S3Exception $e) {
            $this->assertSame(412, $e->getStatusCode());
            $this->assertSame('PreconditionFailed', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // GET: If-None-Match
    // -----------------------------------------------------------------

    public function test_get_object_if_none_match_success(): void
    {
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfNoneMatch' => '"0000000000000000000000000000dead"',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    public function test_get_object_if_none_match_not_modified(): void
    {
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfNoneMatch' => self::$testEtag,
        ]);

        $this->assertSame(304, $result['@metadata']['statusCode']);
        $this->assertSame('', (string) $result['Body']);
    }

    // -----------------------------------------------------------------
    // GET: If-Modified-Since
    // -----------------------------------------------------------------

    public function test_get_object_if_modified_since_success(): void
    {
        // Use a date far in the past — object was modified after this.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfModifiedSince' => new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    public function test_get_object_if_modified_since_not_modified(): void
    {
        // Use a date far in the future — object was NOT modified since then.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfModifiedSince' => new \DateTimeImmutable('+1 year', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame(304, $result['@metadata']['statusCode']);
    }

    // -----------------------------------------------------------------
    // GET: If-Unmodified-Since
    // -----------------------------------------------------------------

    public function test_get_object_if_unmodified_since_success(): void
    {
        // Use a date far in the future — object was NOT modified after this.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfUnmodifiedSince' => new \DateTimeImmutable('+1 year', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    public function test_get_object_if_unmodified_since_failure(): void
    {
        // Use a date far in the past — object WAS modified after this.
        try {
            self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => self::$testKey,
                'IfUnmodifiedSince' => new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
            ]);
            $this->fail('Expected PreconditionFailed (412)');
        } catch (S3Exception $e) {
            $this->assertSame(412, $e->getStatusCode());
            $this->assertSame('PreconditionFailed', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // HEAD: If-Match
    // -----------------------------------------------------------------

    public function test_head_object_if_match_success(): void
    {
        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfMatch' => self::$testEtag,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    public function test_head_object_if_match_failure(): void
    {
        try {
            self::$s3->headObject([
                'Bucket' => self::$bucket,
                'Key' => self::$testKey,
                'IfMatch' => '"0000000000000000000000000000dead"',
            ]);
            $this->fail('Expected PreconditionFailed (412)');
        } catch (S3Exception $e) {
            $this->assertSame(412, $e->getStatusCode());
        }
    }

    // -----------------------------------------------------------------
    // HEAD: If-None-Match
    // -----------------------------------------------------------------

    public function test_head_object_if_none_match_not_modified(): void
    {
        $result = self::$s3->headObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfNoneMatch' => self::$testEtag,
        ]);

        $this->assertSame(304, $result['@metadata']['statusCode']);
    }

    // -----------------------------------------------------------------
    // CopyObject: conditional headers
    // -----------------------------------------------------------------

    public function test_copy_object_if_match_success(): void
    {
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'copy-if-match.txt',
            'CopySource' => self::$bucket . '/' . self::$testKey,
            'CopySourceIfMatch' => self::$testEtag,
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'copy-if-match.txt']);
    }

    public function test_copy_object_if_match_failure(): void
    {
        try {
            self::$s3->copyObject([
                'Bucket' => self::$bucket,
                'Key' => 'copy-if-match-fail.txt',
                'CopySource' => self::$bucket . '/' . self::$testKey,
                'CopySourceIfMatch' => '"0000000000000000000000000000dead"',
            ]);
            $this->fail('Expected PreconditionFailed (412)');
        } catch (S3Exception $e) {
            $this->assertSame(412, $e->getStatusCode());
            $this->assertSame('PreconditionFailed', $e->getAwsErrorCode());
        }
    }

    public function test_copy_object_if_none_match_success(): void
    {
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'copy-if-none-match.txt',
            'CopySource' => self::$bucket . '/' . self::$testKey,
            'CopySourceIfNoneMatch' => '"0000000000000000000000000000dead"',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'copy-if-none-match.txt']);
    }

    public function test_copy_object_if_modified_since_success(): void
    {
        $result = self::$s3->copyObject([
            'Bucket' => self::$bucket,
            'Key' => 'copy-if-modified.txt',
            'CopySource' => self::$bucket . '/' . self::$testKey,
            'CopySourceIfModifiedSince' => new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);

        self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'copy-if-modified.txt']);
    }

    public function test_copy_object_if_unmodified_since_failure(): void
    {
        try {
            self::$s3->copyObject([
                'Bucket' => self::$bucket,
                'Key' => 'copy-if-unmod-fail.txt',
                'CopySource' => self::$bucket . '/' . self::$testKey,
                'CopySourceIfUnmodifiedSince' => new \DateTimeImmutable('2020-01-01', new \DateTimeZone('UTC')),
            ]);
            $this->fail('Expected PreconditionFailed (412)');
        } catch (S3Exception $e) {
            $this->assertSame(412, $e->getStatusCode());
            $this->assertSame('PreconditionFailed', $e->getAwsErrorCode());
        }
    }

    // -----------------------------------------------------------------
    // Wildcard If-Match
    // -----------------------------------------------------------------

    public function test_get_object_if_match_wildcard(): void
    {
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfMatch' => '*',
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }

    // -----------------------------------------------------------------
    // Multiple conditions combined
    // -----------------------------------------------------------------

    public function test_get_object_multiple_conditions(): void
    {
        // If-Match (passes) + If-Unmodified-Since (future, passes) → 200.
        $result = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => self::$testKey,
            'IfMatch' => self::$testEtag,
            'IfUnmodifiedSince' => new \DateTimeImmutable('+1 year', new \DateTimeZone('UTC')),
        ]);

        $this->assertSame(200, $result['@metadata']['statusCode']);
    }
}
