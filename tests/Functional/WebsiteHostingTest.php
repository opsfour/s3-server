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

    private static string|false $previousWebsiteHostPattern = false;

    public static function setUpBeforeClass(): void
    {
        self::$previousWebsiteHostPattern = getenv('S3_WEBSITE_HOST_PATTERN');
        putenv('S3_WEBSITE_HOST_PATTERN=*.s3-website.test');
        try {
            parent::setUpBeforeClass();
        } catch (\Throwable $e) {
            self::restoreWebsiteHostPattern();

            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            self::restoreWebsiteHostPattern();
        }
    }

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

    public function test_website_host_get_and_root_head_reach_redirect_handler(): void
    {
        self::$s3->putBucketWebsite([
            'Bucket' => self::$bucket,
            'WebsiteConfiguration' => [
                'RedirectAllRequestsTo' => [
                    'HostName' => 'example.com',
                    'Protocol' => 'https',
                ],
            ],
        ]);

        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
                'http_errors' => false,
                'allow_redirects' => false,
            ]);
            $host = self::$bucket . '.s3-website.test';

            $get = $client->get('/docs', ['headers' => ['Host' => $host]]);
            self::assertSame(301, $get->getStatusCode());
            self::assertSame('https://example.com/docs', $get->getHeaderLine('Location'));

            $head = $client->head('/', ['headers' => ['Host' => $host]]);
            self::assertSame(301, $head->getStatusCode());
            self::assertSame('https://example.com/', $head->getHeaderLine('Location'));
        } finally {
            self::$s3->deleteBucketWebsite(['Bucket' => self::$bucket]);
        }

        $missing = $client->get('/docs', ['headers' => ['Host' => $host]]);
        self::assertSame(404, $missing->getStatusCode());
        self::assertStringContainsString('NoSuchWebsiteConfiguration', (string) $missing->getBody());
    }

    public function test_public_website_serves_index_head_and_error_document_over_http(): void
    {
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'index.html',
            'Body' => '<h1>public index</h1>',
            'ContentType' => 'text/html',
        ]);
        self::$s3->putObject([
            'Bucket' => self::$bucket,
            'Key' => 'error.html',
            'Body' => '<h1>missing page</h1>',
            'ContentType' => 'text/html',
        ]);
        self::$s3->putBucketWebsite([
            'Bucket' => self::$bucket,
            'WebsiteConfiguration' => [
                'IndexDocument' => ['Suffix' => 'index.html'],
                'ErrorDocument' => ['Key' => 'error.html'],
            ],
        ]);
        self::$s3->putBucketPolicy([
            'Bucket' => self::$bucket,
            'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [[
                    'Effect' => 'Allow',
                    'Principal' => '*',
                    'Action' => 's3:GetObject',
                    'Resource' => 'arn:aws:s3:::' . self::$bucket . '/*',
                ]],
            ], JSON_THROW_ON_ERROR),
        ]);

        $publicAccessBlockSet = false;
        try {
            $client = new \GuzzleHttp\Client([
                'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
                'http_errors' => false,
            ]);
            $headers = ['Host' => self::$bucket . '.s3-website.test'];

            $index = $client->get('/', ['headers' => $headers]);
            self::assertSame(200, $index->getStatusCode());
            self::assertSame('<h1>public index</h1>', (string) $index->getBody());
            self::assertSame('text/html', $index->getHeaderLine('Content-Type'));

            $head = $client->head('/', ['headers' => $headers]);
            self::assertSame(200, $head->getStatusCode());
            self::assertSame('', (string) $head->getBody());
            self::assertSame((string) strlen('<h1>public index</h1>'), $head->getHeaderLine('Content-Length'));

            $missing = $client->get('/unknown', ['headers' => $headers]);
            self::assertSame(404, $missing->getStatusCode());
            self::assertSame('<h1>missing page</h1>', (string) $missing->getBody());

            self::$s3->putPublicAccessBlock([
                'Bucket' => self::$bucket,
                'PublicAccessBlockConfiguration' => [
                    'BlockPublicAcls' => false,
                    'IgnorePublicAcls' => false,
                    'BlockPublicPolicy' => false,
                    'RestrictPublicBuckets' => true,
                ],
            ]);
            $publicAccessBlockSet = true;
            $blocked = $client->get('/', ['headers' => $headers]);
            self::assertSame(403, $blocked->getStatusCode());
        } finally {
            if ($publicAccessBlockSet) {
                self::$s3->deletePublicAccessBlock(['Bucket' => self::$bucket]);
            }
            self::$s3->deleteBucketPolicy(['Bucket' => self::$bucket]);
            self::$s3->deleteBucketWebsite(['Bucket' => self::$bucket]);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'index.html']);
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => 'error.html']);
        }
    }

    private static function restoreWebsiteHostPattern(): void
    {
        putenv(self::$previousWebsiteHostPattern === false
            ? 'S3_WEBSITE_HOST_PATTERN'
            : 'S3_WEBSITE_HOST_PATTERN=' . self::$previousWebsiteHostPattern);
    }
}
