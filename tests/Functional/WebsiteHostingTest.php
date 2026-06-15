<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;

/**
 * Functional tests for Phase 8: Static Website Hosting configuration.
 */
final class WebsiteHostingTest extends S3FunctionalTestCase
{
    private static string $bucket = 'test-website-bucket';

    private static bool $seeded = false;

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$seeded) {
            return;
        }

        self::$s3->createBucket(['Bucket' => self::$bucket]);
        self::$seeded = true;
    }

    // -----------------------------------------------------------------
    // Bucket Website
    // -----------------------------------------------------------------

    public function test_get_bucket_website_not_set(): void
    {
        try {
            self::$s3->getBucketWebsite([
                'Bucket' => self::$bucket,
            ]);
            $this->fail('Expected NoSuchWebsiteConfiguration');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchWebsiteConfiguration', $e->getAwsErrorCode());
        }
    }

    public function test_put_and_get_bucket_website(): void
    {
        $putResult = self::$s3->putBucketWebsite([
            'Bucket' => self::$bucket,
            'WebsiteConfiguration' => [
                'IndexDocument' => [
                    'Suffix' => 'index.html',
                ],
                'ErrorDocument' => [
                    'Key' => 'error.html',
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get website config.
        $getResult = self::$s3->getBucketWebsite([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $this->assertSame('index.html', $getResult['IndexDocument']['Suffix']);
        $this->assertSame('error.html', $getResult['ErrorDocument']['Key']);
    }

    public function test_delete_bucket_website(): void
    {
        // Ensure website config exists first.
        self::$s3->putBucketWebsite([
            'Bucket' => self::$bucket,
            'WebsiteConfiguration' => [
                'IndexDocument' => [
                    'Suffix' => 'index.html',
                ],
            ],
        ]);

        $deleteResult = self::$s3->deleteBucketWebsite([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(204, $deleteResult['@metadata']['statusCode']);

        // Get after delete should fail.
        try {
            self::$s3->getBucketWebsite([
                'Bucket' => self::$bucket,
            ]);
            $this->fail('Expected NoSuchWebsiteConfiguration after delete');
        } catch (S3Exception $e) {
            $this->assertSame(404, $e->getStatusCode());
            $this->assertSame('NoSuchWebsiteConfiguration', $e->getAwsErrorCode());
        }
    }

    public function test_put_bucket_website_with_redirect(): void
    {
        $putResult = self::$s3->putBucketWebsite([
            'Bucket' => self::$bucket,
            'WebsiteConfiguration' => [
                'RedirectAllRequestsTo' => [
                    'HostName' => 'example.com',
                    'Protocol' => 'https',
                ],
            ],
        ]);

        $this->assertSame(200, $putResult['@metadata']['statusCode']);

        // Get website config.
        $getResult = self::$s3->getBucketWebsite([
            'Bucket' => self::$bucket,
        ]);

        $this->assertSame(200, $getResult['@metadata']['statusCode']);
        $this->assertSame('example.com', $getResult['RedirectAllRequestsTo']['HostName']);
        $this->assertSame('https', $getResult['RedirectAllRequestsTo']['Protocol']);

        // Clean up.
        self::$s3->deleteBucketWebsite(['Bucket' => self::$bucket]);
    }
}
