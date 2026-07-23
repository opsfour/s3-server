<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use GuzzleHttp\Client;
use OpsFour\S3Server\Auth\SigningKey;

final class PostObjectTest extends S3FunctionalTestCase
{
    private static string $bucket = '';

    protected function setUp(): void
    {
        parent::setUp();

        if (self::$bucket !== '') {
            return;
        }

        self::$bucket = 'post-object-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => self::$bucket]);
    }

    public function test_sigv4_browser_form_uses_normal_object_write_path(): void
    {
        $body = 'signed browser upload';
        $response = $this->postV4(
            key: 'signed/${filename}',
            filename: 'hello.txt',
            body: $body,
            extraFields: [
                'success_action_status' => '201',
                'Content-Type' => 'text/plain',
                'x-amz-meta-origin' => 'browser',
                'tagging' => 'project=post-object',
            ],
        );

        $this->assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertStringContainsString('<Key>signed/hello.txt</Key>', (string) $response->getBody());

        $object = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'signed/hello.txt',
        ]);
        $this->assertSame($body, (string) $object['Body']);
        $this->assertSame('text/plain', $object['ContentType']);
        $this->assertSame('browser', $object['Metadata']['origin']);

        $tags = self::$s3->getObjectTagging([
            'Bucket' => self::$bucket,
            'Key' => 'signed/hello.txt',
        ]);
        $this->assertSame([['Key' => 'project', 'Value' => 'post-object']], $tags['TagSet']);
    }

    public function test_anonymous_form_is_denied_for_private_bucket(): void
    {
        $response = $this->postAnonymous('anonymous/denied.txt', 'denied');

        $this->assertSame(403, $response->getStatusCode());
        $this->assertStringContainsString('<Code>AccessDenied</Code>', (string) $response->getBody());
    }

    public function test_anonymous_form_is_allowed_by_public_write_acl(): void
    {
        self::$s3->putBucketAcl([
            'Bucket' => self::$bucket,
            'ACL' => 'public-read-write',
        ]);

        try {
            $response = $this->postAnonymous('anonymous/allowed.txt', 'public write');
            $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

            $object = self::$s3->getObject([
                'Bucket' => self::$bucket,
                'Key' => 'anonymous/allowed.txt',
            ]);
            $this->assertSame('public write', (string) $object['Body']);
        } finally {
            self::$s3->putBucketAcl([
                'Bucket' => self::$bucket,
                'ACL' => 'private',
            ]);
        }
    }

    public function test_large_sigv4_form_upload_round_trips_without_heap_buffering(): void
    {
        $bytes = (int) (getenv('S3_TEST_POST_OBJECT_BYTES') ?: 8 * 1024 * 1024);
        $resource = fopen('php://temp/maxmemory:1048576', 'w+b');
        $this->assertIsResource($resource);

        $chunk = str_repeat('post-object-stream-', 4096);
        $remaining = $bytes;
        $hash = hash_init('sha256');
        while ($remaining > 0) {
            $data = substr($chunk, 0, min(strlen($chunk), $remaining));
            fwrite($resource, $data);
            hash_update($hash, $data);
            $remaining -= strlen($data);
        }
        rewind($resource);
        $expectedHash = hash_final($hash);

        try {
            $response = $this->postV4(
                key: 'large/${filename}',
                filename: 'large.bin',
                body: $resource,
                extraFields: ['Content-Type' => 'application/octet-stream'],
            );
        } finally {
            if (is_resource($resource)) {
                fclose($resource);
            }
        }

        $this->assertSame(204, $response->getStatusCode(), (string) $response->getBody());

        $object = self::$s3->getObject([
            'Bucket' => self::$bucket,
            'Key' => 'large/large.bin',
        ]);
        $this->assertSame($bytes, (int) $object['ContentLength']);
        $this->assertSame($expectedHash, hash('sha256', (string) $object['Body']));
    }

    /**
     * @param resource|string $body
     * @param array<string, string> $extraFields
     */
    private function postV4(string $key, string $filename, mixed $body, array $extraFields = []): \Psr\Http\Message\ResponseInterface
    {
        $dateTime = gmdate('Ymd\THis\Z');
        $date = substr($dateTime, 0, 8);
        $credential = self::$accessKey . "/{$date}/us-east-1/s3/aws4_request";

        $fields = [
            'key' => $key,
            'x-amz-algorithm' => 'AWS4-HMAC-SHA256',
            'x-amz-credential' => $credential,
            'x-amz-date' => $dateTime,
            ...$extraFields,
        ];

        $conditions = [
            ['bucket' => self::$bucket],
            ['starts-with', '$key', strstr($key, '${filename}', true) ?: $key],
        ];
        foreach ($fields as $name => $value) {
            if ($name === 'key') {
                continue;
            }
            $conditions[] = [$name => $value];
        }

        $policy = base64_encode(json_encode([
            'expiration' => gmdate('Y-m-d\TH:i:s\Z', time() + 300),
            'conditions' => $conditions,
        ], JSON_THROW_ON_ERROR));
        $signature = hash_hmac(
            'sha256',
            $policy,
            SigningKey::derive(self::$secretKey, $date, 'us-east-1', 's3'),
        );

        $fields['policy'] = $policy;
        $fields['x-amz-signature'] = $signature;

        return $this->sendMultipart($fields, $filename, $body);
    }

    private function postAnonymous(string $key, string $body): \Psr\Http\Message\ResponseInterface
    {
        return $this->sendMultipart(['key' => $key], 'upload.txt', $body);
    }

    /**
     * @param array<string, string> $fields
     * @param resource|string $body
     */
    private function sendMultipart(array $fields, string $filename, mixed $body): \Psr\Http\Message\ResponseInterface
    {
        $multipart = [];
        foreach ($fields as $name => $value) {
            $multipart[] = ['name' => $name, 'contents' => $value];
        }
        $multipart[] = [
            'name' => 'file',
            'contents' => $body,
            'filename' => $filename,
        ];

        return (new Client([
            'http_errors' => false,
            'timeout' => 60,
        ]))->request(
            'POST',
            sprintf('http://%s:%d/%s', self::$host, self::$port, self::$bucket),
            ['multipart' => $multipart],
        );
    }
}
