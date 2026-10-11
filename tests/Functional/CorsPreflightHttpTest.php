<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use GuzzleHttp\Client;
use Psr\Http\Message\ResponseInterface;

/** Exercises browser preflight through the actual router and middleware stack. */
final class CorsPreflightHttpTest extends S3FunctionalTestCase
{
    private static string|false $previousBaseDomain = false;

    private static string $bucket = 'cors-http-bucket';

    public static function setUpBeforeClass(): void
    {
        self::$previousBaseDomain = getenv('S3_BASE_DOMAIN');
        putenv('S3_BASE_DOMAIN=s3.test');
        try {
            parent::setUpBeforeClass();
            self::$s3->createBucket(['Bucket' => self::$bucket]);
        } catch (\Throwable $e) {
            self::restoreBaseDomain();

            throw $e;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            self::restoreBaseDomain();
        }
    }

    public function test_path_style_preflight_allows_matching_headers_but_does_not_authorize_upload(): void
    {
        $this->putCors(self::$bucket);
        try {
            $response = $this->preflight(
                '/' . self::$bucket . '/object.txt',
                's3.test',
                'https://client.example',
                'PUT',
                'X-Amz-Meta-Title, Content-Type',
            );

            self::assertSame(200, $response->getStatusCode());
            self::assertSame('https://client.example', $response->getHeaderLine('Access-Control-Allow-Origin'));
            self::assertSame('true', $response->getHeaderLine('Access-Control-Allow-Credentials'));
            self::assertSame('GET, PUT', $response->getHeaderLine('Access-Control-Allow-Methods'));
            self::assertSame('x-amz-meta-title, content-type', $response->getHeaderLine('Access-Control-Allow-Headers'));
            self::assertSame('600', $response->getHeaderLine('Access-Control-Max-Age'));
            self::assertSame('Origin, Access-Control-Request-Headers, Access-Control-Request-Method', $response->getHeaderLine('Vary'));

            $upload = $this->httpClient()->put('/' . self::$bucket . '/object.txt', [
                'headers' => ['Host' => 's3.test', 'Origin' => 'https://client.example'],
                'body' => 'unauthorized',
            ]);
            self::assertSame(403, $upload->getStatusCode());
            try {
                self::$s3->headObject(['Bucket' => self::$bucket, 'Key' => 'object.txt']);
                self::fail('Preflight must not authorize the following unsigned PUT.');
            } catch (S3Exception $e) {
                self::assertSame(404, $e->getStatusCode());
            }
        } finally {
            self::$s3->deleteBucketCors(['Bucket' => self::$bucket]);
        }
    }

    public function test_preflight_rejects_unmatched_origin_method_and_headers(): void
    {
        $this->putCors(self::$bucket);
        try {
            foreach ([
                ['https://other.example', 'PUT', 'Content-Type'],
                ['https://client.example', 'DELETE', 'Content-Type'],
                ['https://client.example', 'PUT', 'Authorization'],
                ['https://client.example', 'PUT', 'Content-Type,'],
                ['https://client.example', null, 'Content-Type'],
            ] as [$origin, $method, $headers]) {
                $response = $this->preflight('/' . self::$bucket, 's3.test', $origin, $method, $headers);
                self::assertSame(403, $response->getStatusCode());
                self::assertSame('', $response->getHeaderLine('Access-Control-Allow-Origin'));
                self::assertSame('Origin, Access-Control-Request-Headers, Access-Control-Request-Method', $response->getHeaderLine('Vary'));
            }
        } finally {
            self::$s3->deleteBucketCors(['Bucket' => self::$bucket]);
        }
    }

    public function test_signed_invalid_cors_header_name_preserves_previous_configuration(): void
    {
        self::$s3->putBucketCors([
            'Bucket' => self::$bucket,
            'CORSConfiguration' => ['CORSRules' => [[
                'AllowedOrigins' => ['https://client.example'],
                'AllowedMethods' => ['GET'],
                'ExposeHeaders' => ['ETag'],
            ]]],
        ]);
        try {
            $before = self::$s3->getBucketCors(['Bucket' => self::$bucket])['CORSRules'];
            self::assertSame('ETag', $this->preflight('/' . self::$bucket, 's3.test', 'https://client.example', 'GET')
                ->getHeaderLine('Access-Control-Expose-Headers'));

            foreach ([
                ['ExposeHeaders', "X-Ok\nBad"],
                ['ExposeHeaders', 'X-Ok: Bad'],
                ['AllowedHeaders', "X-Ok\nBad"],
                ['AllowedHeaders', 'X-Ok: Bad'],
            ] as [$field, $invalid]) {
                try {
                    self::$s3->putBucketCors([
                        'Bucket' => self::$bucket,
                        'CORSConfiguration' => ['CORSRules' => [[
                            'AllowedOrigins' => ['https://client.example'],
                            'AllowedMethods' => ['GET'],
                            $field => [$invalid],
                        ]]],
                    ]);
                    self::fail('An invalid CORS header name was accepted.');
                } catch (S3Exception $error) {
                    self::assertSame(400, $error->getStatusCode());
                }
                self::assertSame($before, self::$s3->getBucketCors(['Bucket' => self::$bucket])['CORSRules']);
                $response = $this->preflight('/' . self::$bucket, 's3.test', 'https://client.example', 'GET');
                self::assertSame(200, $response->getStatusCode());
                self::assertSame('ETag', $response->getHeaderLine('Access-Control-Expose-Headers'));
            }
        } finally {
            self::$s3->deleteBucketCors(['Bucket' => self::$bucket]);
        }
    }

    public function test_virtual_host_preflight_resolves_bucket_before_cors_and_path_bucket_cannot_override_it(): void
    {
        $this->putCors(self::$bucket);
        try {
            $root = $this->preflight('/', self::$bucket . '.s3.test', 'https://client.example', 'GET');
            self::assertSame(200, $root->getStatusCode());
            self::assertSame('https://client.example', $root->getHeaderLine('Access-Control-Allow-Origin'));

            $object = $this->preflight('/nested/object.txt', self::$bucket . '.s3.test', 'https://client.example', 'PUT', 'Content-Type');
            self::assertSame(200, $object->getStatusCode());

            $wrongHost = $this->preflight('/' . self::$bucket . '/object.txt', 'other-bucket.s3.test', 'https://client.example', 'PUT');
            self::assertSame(403, $wrongHost->getStatusCode());
            self::assertSame('', $wrongHost->getHeaderLine('Access-Control-Allow-Origin'));
        } finally {
            self::$s3->deleteBucketCors(['Bucket' => self::$bucket]);
        }
    }

    public function test_same_owner_bucket_recreation_does_not_inherit_old_preflight_permission(): void
    {
        $bucket = 'cors-reuse-' . bin2hex(random_bytes(5));
        self::$s3->createBucket(['Bucket' => $bucket]);
        try {
            $this->putCors($bucket);
            self::assertSame(200, $this->preflight('/' . $bucket, 's3.test', 'https://client.example', 'PUT')->getStatusCode());

            self::$s3->deleteBucket(['Bucket' => $bucket]);
            self::$s3->createBucket(['Bucket' => $bucket]);

            $oldOrigin = $this->preflight('/' . $bucket, 's3.test', 'https://client.example', 'PUT');
            self::assertSame(403, $oldOrigin->getStatusCode());
            self::assertSame('', $oldOrigin->getHeaderLine('Access-Control-Allow-Origin'));

            $this->putCors($bucket, 'https://new-client.example');
            self::assertSame(403, $this->preflight('/' . $bucket, 's3.test', 'https://client.example', 'PUT')->getStatusCode());
            self::assertSame(200, $this->preflight('/' . $bucket, 's3.test', 'https://new-client.example', 'PUT')->getStatusCode());
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    private function putCors(string $bucket, string $origin = 'https://client.example'): void
    {
        self::$s3->putBucketCors([
            'Bucket' => $bucket,
            'CORSConfiguration' => ['CORSRules' => [[
                'AllowedOrigins' => [$origin],
                'AllowedMethods' => ['GET', 'PUT'],
                'AllowedHeaders' => ['X-Amz-*', 'Content-Type'],
                'MaxAgeSeconds' => 600,
            ]]],
        ]);
    }

    private function preflight(
        string $path,
        string $host,
        string $origin,
        ?string $method,
        ?string $requestedHeaders = null,
    ): ResponseInterface {
        $headers = ['Host' => $host, 'Origin' => $origin];
        if ($method !== null) {
            $headers['Access-Control-Request-Method'] = $method;
        }
        if ($requestedHeaders !== null) {
            $headers['Access-Control-Request-Headers'] = $requestedHeaders;
        }

        return $this->httpClient()->request('OPTIONS', $path, ['headers' => $headers]);
    }

    private function httpClient(): Client
    {
        return new Client([
            'base_uri' => sprintf('http://%s:%d', self::$host, self::$port),
            'http_errors' => false,
            'timeout' => 5,
        ]);
    }

    private static function restoreBaseDomain(): void
    {
        putenv(self::$previousBaseDomain === false
            ? 'S3_BASE_DOMAIN'
            : 'S3_BASE_DOMAIN=' . self::$previousBaseDomain);
    }
}
