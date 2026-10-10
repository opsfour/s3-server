<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

final class WebsiteRoutingReuseHttpTest extends S3FunctionalTestCase
{
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

    public function test_routing_rules_observe_anonymous_get_head_and_access_outcomes(): void
    {
        $bucket = 'website-routes-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'index.html', 'Body' => 'public index', 'ACL' => 'public-read']);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'private/secret.txt', 'Body' => 'private']);
            self::$s3->putBucketWebsite(['Bucket' => $bucket, 'WebsiteConfiguration' => [
                'IndexDocument' => ['Suffix' => 'index.html'],
                'RoutingRules' => [
                    [
                        'Condition' => ['KeyPrefixEquals' => 'old/'],
                        'Redirect' => ['Protocol' => 'https', 'HostName' => 'redirect.example', 'ReplaceKeyPrefixWith' => 'archive/', 'HttpRedirectCode' => '302'],
                    ],
                    [
                        'Condition' => ['KeyPrefixEquals' => 'private/', 'HttpErrorCodeReturnedEquals' => '403'],
                        'Redirect' => ['HostName' => 'denied.example', 'ReplaceKeyWith' => 'denied.html', 'HttpRedirectCode' => '307'],
                    ],
                    [
                        'Condition' => ['KeyPrefixEquals' => 'gone/', 'HttpErrorCodeReturnedEquals' => '404'],
                        'Redirect' => ['HostName' => 'missing.example', 'ReplaceKeyPrefixWith' => 'found/', 'HttpRedirectCode' => '308'],
                    ],
                ],
            ]]);

            $redirect = $this->websiteRequest($bucket, 'GET', '/old/a%23b%3Fc.txt');
            self::assertSame(302, $redirect->getStatusCode());
            self::assertSame('https://redirect.example/archive/a%23b%3Fc.txt', $redirect->getHeaderLine('Location'));
            self::assertSame('', (string) $redirect->getBody());

            $headRedirect = $this->websiteRequest($bucket, 'HEAD', '/old/file.txt');
            self::assertSame(302, $headRedirect->getStatusCode());
            self::assertSame('https://redirect.example/archive/file.txt', $headRedirect->getHeaderLine('Location'));
            self::assertSame('', (string) $headRedirect->getBody());

            self::assertSame('public index', (string) $this->websiteRequest($bucket, 'GET', '/')->getBody());
            $indexHead = $this->websiteRequest($bucket, 'HEAD', '/');
            self::assertSame(200, $indexHead->getStatusCode());
            self::assertSame('', (string) $indexHead->getBody());

            $private = $this->websiteRequest($bucket, 'GET', '/private/secret.txt');
            self::assertSame(307, $private->getStatusCode());
            self::assertSame('http://denied.example/denied.html', $private->getHeaderLine('Location'));
            self::assertStringNotContainsString('private', (string) $private->getBody());

            self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => self::publicReadPolicy($bucket)]);
            $missing = $this->websiteRequest($bucket, 'GET', '/gone/file.txt');
            self::assertSame(308, $missing->getStatusCode());
            self::assertSame('http://missing.example/found/file.txt', $missing->getHeaderLine('Location'));

            self::$s3->putPublicAccessBlock(['Bucket' => $bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => true,
            ]]);
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/gone/file.txt')->getStatusCode());
            self::assertSame(307, $this->websiteRequest($bucket, 'GET', '/private/secret.txt')->getStatusCode());
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/')->getStatusCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            self::$s3->deleteBucketWebsite(['Bucket' => $bucket]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'index.html']);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'private/secret.txt']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_same_owner_bucket_reuse_cannot_reuse_old_website_redirect_or_public_acl(): void
    {
        $bucket = 'website-reuse-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'index.html', 'Body' => 'old generation', 'ACL' => 'public-read']);
            self::$s3->putBucketWebsite(['Bucket' => $bucket, 'WebsiteConfiguration' => [
                'RedirectAllRequestsTo' => ['HostName' => 'old.example', 'Protocol' => 'https'],
            ]]);
            self::assertSame('https://old.example/docs', $this->websiteRequest($bucket, 'GET', '/docs')->getHeaderLine('Location'));

            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'index.html']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
            $deleted = $this->websiteRequest($bucket, 'GET', '/docs');
            self::assertSame(404, $deleted->getStatusCode());
            self::assertStringContainsString('NoSuchWebsiteConfiguration', (string) $deleted->getBody());

            self::$s3->createBucket(['Bucket' => $bucket]);
            $recreated = $this->websiteRequest($bucket, 'GET', '/docs');
            self::assertSame(404, $recreated->getStatusCode());
            self::assertSame('', $recreated->getHeaderLine('Location'));

            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'index.html', 'Body' => 'new generation']);
            self::$s3->putBucketWebsite(['Bucket' => $bucket, 'WebsiteConfiguration' => [
                'IndexDocument' => ['Suffix' => 'index.html'],
            ]]);
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/')->getStatusCode());
            self::$s3->putObjectAcl(['Bucket' => $bucket, 'Key' => 'index.html', 'ACL' => 'public-read']);
            $newIndex = $this->websiteRequest($bucket, 'GET', '/');
            self::assertSame(200, $newIndex->getStatusCode());
            self::assertSame('new generation', (string) $newIndex->getBody());
            self::assertSame('', $newIndex->getHeaderLine('Location'));

            self::$s3->putPublicAccessBlock(['Bucket' => $bucket, 'PublicAccessBlockConfiguration' => [
                'BlockPublicAcls' => false,
                'IgnorePublicAcls' => true,
                'BlockPublicPolicy' => false,
                'RestrictPublicBuckets' => false,
            ]]);
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/')->getStatusCode());
            self::assertSame(403, $this->websiteRequest($bucket, 'HEAD', '/')->getStatusCode());
        } finally {
            self::$s3->deletePublicAccessBlock(['Bucket' => $bucket]);
            self::$s3->deleteBucketWebsite(['Bucket' => $bucket]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'index.html']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_website_tag_policy_uses_the_current_version_in_enabled_and_suspended_buckets(): void
    {
        $bucket = 'website-tags-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            self::$s3->putBucketWebsite(['Bucket' => $bucket, 'WebsiteConfiguration' => [
                'IndexDocument' => ['Suffix' => 'index.html'],
                'ErrorDocument' => ['Key' => 'error.html'],
            ]]);
            self::$s3->putBucketPolicy(['Bucket' => $bucket, 'Policy' => json_encode([
                'Version' => '2012-10-17',
                'Statement' => [
                    [
                        'Effect' => 'Allow',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => 'arn:aws:s3:::' . $bucket . '/*',
                    ],
                    [
                        'Effect' => 'Deny',
                        'Principal' => '*',
                        'Action' => 's3:GetObject',
                        'Resource' => 'arn:aws:s3:::' . $bucket . '/*',
                        'Condition' => ['StringEquals' => ['s3:ExistingObjectTag/classification' => 'private']],
                    ],
                ],
            ], JSON_THROW_ON_ERROR)]);

            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'index.html', 'Body' => 'numbered private', 'Tagging' => 'classification=private', 'ACL' => 'public-read']);
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/')->getStatusCode());
            self::assertSame(403, $this->websiteRequest($bucket, 'HEAD', '/')->getStatusCode());
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'error.html', 'Body' => 'private error', 'Tagging' => 'classification=private', 'ACL' => 'public-read']);
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/missing')->getStatusCode());
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'error.html', 'Body' => 'public error', 'Tagging' => 'classification=public', 'ACL' => 'public-read']);
            $missing = $this->websiteRequest($bucket, 'GET', '/missing');
            self::assertSame(404, $missing->getStatusCode());
            self::assertSame('public error', (string) $missing->getBody());

            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Suspended']]);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'index.html', 'Body' => 'null private', 'Tagging' => 'classification=private', 'ACL' => 'public-read']);
            self::assertSame(403, $this->websiteRequest($bucket, 'GET', '/')->getStatusCode());

            self::$s3->putBucketVersioning(['Bucket' => $bucket, 'VersioningConfiguration' => ['Status' => 'Enabled']]);
            self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'index.html', 'Body' => 'numbered public', 'Tagging' => 'classification=public', 'ACL' => 'public-read']);
            $public = $this->websiteRequest($bucket, 'GET', '/');
            self::assertSame(200, $public->getStatusCode());
            self::assertSame('numbered public', (string) $public->getBody());

            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'index.html']);
            self::assertSame(404, $this->websiteRequest($bucket, 'GET', '/')->getStatusCode());
        } finally {
            self::$s3->deleteBucketPolicy(['Bucket' => $bucket]);
            self::$s3->deleteBucketWebsite(['Bucket' => $bucket]);
            $versions = self::$s3->listObjectVersions(['Bucket' => $bucket]);
            foreach (array_merge($versions['Versions'] ?? [], $versions['DeleteMarkers'] ?? []) as $version) {
                self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $version['Key'], 'VersionId' => $version['VersionId']]);
            }
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    private function websiteRequest(string $bucket, string $method, string $path): ResponseInterface
    {
        $client = new Client([
            'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
            'http_errors' => false,
            'allow_redirects' => false,
            'timeout' => 5,
        ]);

        return $client->request($method, $path, ['headers' => ['Host' => $bucket . '.s3-website.test']]);
    }

    private static function publicReadPolicy(string $bucket): string
    {
        return json_encode([
            'Version' => '2012-10-17',
            'Statement' => [[
                'Effect' => 'Allow',
                'Principal' => '*',
                'Action' => 's3:GetObject',
                'Resource' => 'arn:aws:s3:::' . $bucket . '/*',
            ]],
        ], JSON_THROW_ON_ERROR);
    }

    private static function restoreWebsiteHostPattern(): void
    {
        putenv(self::$previousWebsiteHostPattern === false
            ? 'S3_WEBSITE_HOST_PATTERN'
            : 'S3_WEBSITE_HOST_PATTERN=' . self::$previousWebsiteHostPattern);
    }
}
