<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Client;
use GuzzleHttp\Psr7\Request;
use Psr\Http\Message\ResponseInterface;

final class RangeResponseSemanticsTest extends S3FunctionalTestCase
{
    private static string $bucket = 'range-semantics-bucket';

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();
        self::$s3->createBucket(['Bucket' => self::$bucket]);
    }

    public function test_signed_get_and_head_reject_satisfiable_syntax_on_empty_object(): void
    {
        $key = 'empty-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => '']);
        try {
            foreach (['GET', 'HEAD'] as $method) {
                $plain = $this->signedRead($method, $key);
                self::assertSame(200, $plain->getStatusCode());
                self::assertSame('0', $plain->getHeaderLine('Content-Length'));

                foreach (['bytes=0-0', 'bytes=-1', 'bytes=0-'] as $range) {
                    $response = $this->signedRead($method, $key, ['Range' => $range]);
                    self::assertSame(416, $response->getStatusCode(), $method . ' ' . $range);
                    self::assertSame('bytes */0', $response->getHeaderLine('Content-Range'), $method . ' ' . $range);
                    if ($method === 'GET') {
                        self::assertStringContainsString('<Code>InvalidRange</Code>', (string) $response->getBody());
                    } else {
                        self::assertSame('', (string) $response->getBody());
                    }
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_signed_head_range_changes_only_content_length_while_get_returns_partial_body(): void
    {
        $key = 'five-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'abcde']);
        try {
            $get = $this->signedRead('GET', $key, ['Range' => 'bytes=1-2']);
            self::assertSame(206, $get->getStatusCode());
            self::assertSame('bytes 1-2/5', $get->getHeaderLine('Content-Range'));
            self::assertSame('2', $get->getHeaderLine('Content-Length'));
            self::assertSame('bc', (string) $get->getBody());

            $head = $this->signedRead('HEAD', $key, ['Range' => 'bytes=1-2']);
            self::assertSame(200, $head->getStatusCode());
            self::assertSame('2', $head->getHeaderLine('Content-Length'));
            self::assertSame('', $head->getHeaderLine('Content-Range'));
            self::assertSame('', (string) $head->getBody());

            $notModified = $this->signedRead('GET', $key, [
                'Range' => 'bytes=100-200',
                'If-None-Match' => $get->getHeaderLine('ETag'),
            ]);
            self::assertSame(304, $notModified->getStatusCode());
            self::assertSame('', (string) $notModified->getBody());

            foreach (['GET', 'HEAD'] as $method) {
                $invalid = $this->signedRead($method, $key, ['Range' => 'bytes=5-']);
                self::assertSame(416, $invalid->getStatusCode());
                self::assertSame('bytes */5', $invalid->getHeaderLine('Content-Range'));
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_signed_get_and_head_ignore_non_http_conditional_dates(): void
    {
        $key = 'invalid-date-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'abcde']);
        try {
            foreach (['GET', 'HEAD'] as $method) {
                foreach ([
                    ['If-Modified-Since' => 'tomorrow'],
                    ['If-Unmodified-Since' => 'yesterday'],
                ] as $headers) {
                    $response = $this->signedRead($method, $key, $headers);
                    self::assertSame(200, $response->getStatusCode(), $method . ' ' . key($headers));
                    self::assertSame($method === 'GET' ? 'abcde' : '', (string) $response->getBody());
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    public function test_signed_copy_ignores_non_http_source_conditional_dates(): void
    {
        $source = 'copy-invalid-date-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $source, 'Body' => 'abcde']);
        try {
            foreach ([
                'x-amz-copy-source-if-modified-since' => 'tomorrow',
                'x-amz-copy-source-if-unmodified-since' => 'yesterday',
            ] as $header => $date) {
                $destination = $source . '-' . $header;
                try {
                    $unsigned = new Request(
                        'PUT',
                        sprintf('http://%s:%d/%s/%s', self::$host, self::$port, self::$bucket, $destination),
                        ['x-amz-copy-source' => '/' . self::$bucket . '/' . $source, $header => $date],
                    );
                    $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                        $unsigned,
                        new Credentials(self::$accessKey, self::$secretKey),
                    );
                    $response = (new Client(['http_errors' => false, 'timeout' => 5]))->send($signed);
                    self::assertSame(200, $response->getStatusCode(), $header);
                    self::assertSame('abcde', (string) self::$s3->getObject([
                        'Bucket' => self::$bucket,
                        'Key' => $destination,
                    ])['Body']);
                } finally {
                    self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $destination]);
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $source]);
        }
    }

    public function test_signed_get_and_head_do_not_treat_empty_version_id_as_current_version(): void
    {
        $key = 'empty-version-' . bin2hex(random_bytes(4));
        self::$s3->putObject(['Bucket' => self::$bucket, 'Key' => $key, 'Body' => 'current']);
        try {
            foreach (['GET', 'HEAD'] as $method) {
                foreach (['versionId=', 'versionId', 'versionId=&versionId=null'] as $query) {
                    $unsigned = new Request(
                        $method,
                        sprintf('http://%s:%d/%s/%s?%s', self::$host, self::$port, self::$bucket, $key, $query),
                    );
                    $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                        $unsigned,
                        new Credentials(self::$accessKey, self::$secretKey),
                    );
                    $response = (new Client(['http_errors' => false, 'timeout' => 5]))->send($signed);
                    self::assertSame(
                        str_contains($query, '&versionId=') ? 403 : 404,
                        $response->getStatusCode(),
                        $method . ' ' . $query,
                    );
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => self::$bucket, 'Key' => $key]);
        }
    }

    /** @param array<string, string> $headers */
    private function signedRead(string $method, string $key, array $headers = []): ResponseInterface
    {
        $operation = $method === 'HEAD' ? 'HeadObject' : 'GetObject';
        $command = self::$s3->getCommand($operation, ['Bucket' => self::$bucket, 'Key' => $key]);
        $url = (string) self::$s3->createPresignedRequest($command, '+5 minutes')->getUri();

        return (new Client(['http_errors' => false, 'timeout' => 5]))->request($method, $url, ['headers' => $headers]);
    }
}
