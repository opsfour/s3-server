<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Psr7\Request;

final class HttpWireProtocolTest extends S3FunctionalTestCase
{
    public function test_expect_continue_is_emitted_before_put_body_is_sent(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        $key = 'expect-continue';
        $body = 'body-after-continue';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $request = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request(
                    'PUT',
                    sprintf('http://%s:%d/%s/%s', self::$host, self::$port, $bucket, $key),
                    [
                        'content-length' => (string) strlen($body),
                        'expect' => '100-continue',
                        'x-amz-content-sha256' => hash('sha256', $body),
                    ],
                    $body,
                ),
                new Credentials(self::$accessKey, self::$secretKey),
            );
            $wire = $request->getMethod() . ' ' . $request->getRequestTarget() . " HTTP/1.1\r\n";
            foreach ($request->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $wire .= $name . ': ' . $value . "\r\n";
                }
            }
            $wire .= "Connection: close\r\n\r\n";

            $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
            self::assertNotFalse($socket, $error ?? 'Could not connect to the test server.');
            try {
                stream_set_timeout($socket, 5);
                self::assertSame(strlen($wire), fwrite($socket, $wire));
                $interim = '';
                while (($line = fgets($socket)) !== false) {
                    $interim .= $line;
                    if ($line === "\r\n") {
                        break;
                    }
                }
                self::assertSame("HTTP/1.1 100 Continue\r\n\r\n", $interim);
                self::assertSame(strlen($body), fwrite($socket, $body));
                $final = stream_get_contents($socket);
                self::assertIsString($final);
                self::assertFalse(stream_get_meta_data($socket)['timed_out']);
                self::assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $final);
            } finally {
                fclose($socket);
            }

            self::assertSame($body, (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_duplicate_signed_acl_headers_are_rejected_before_dispatch_in_either_order(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        $key = 'duplicate-acl';
        $body = 'signed-payload';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request(
                    'PUT',
                    sprintf('http://%s:%d/%s/%s', self::$host, self::$port, $bucket, $key),
                    [
                        'content-length' => (string) strlen($body),
                        'x-amz-acl' => ['private', 'public-read'],
                        'x-amz-content-sha256' => hash('sha256', $body),
                    ],
                    $body,
                ),
                new Credentials(self::$accessKey, self::$secretKey),
            );
            self::assertSame(['private', 'public-read'], $signed->getHeader('x-amz-acl'));

            $rejected = self::sendRawRequest($signed);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 400 /', $rejected);
            self::assertStringContainsString('<Code>InvalidArgument</Code>', $rejected);
            self::assertFalse(self::$s3->doesObjectExistV2($bucket, $key));

            $rejected = self::sendRawRequest($signed, reverseAclHeaders: true);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 400 /', $rejected);
            self::assertStringContainsString('<Code>InvalidArgument</Code>', $rejected);
            self::assertFalse(self::$s3->doesObjectExistV2($bucket, $key));
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_repeated_unsigned_cache_control_lines_are_combined_and_persisted(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        $key = 'repeated-lists';
        $body = 'list-valued headers';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request(
                    'PUT',
                    sprintf('http://%s:%d/%s/%s', self::$host, self::$port, $bucket, $key),
                    [
                        'content-length' => (string) strlen($body),
                        'cache-control' => ['no-cache', 'no-store'],
                        'x-amz-content-sha256' => hash('sha256', $body),
                    ],
                    $body,
                ),
                new Credentials(self::$accessKey, self::$secretKey),
            );

            $response = self::sendRawRequest($signed);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $response);

            $head = self::$s3->headObject(['Bucket' => $bucket, 'Key' => $key]);
            self::assertSame('no-cache, no-store', $head['CacheControl']);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_content_length_and_transfer_encoding_are_rejected_on_the_wire(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        $key = 'ambiguous-framing';
        $body = 'framed-body';
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request(
                    'PUT',
                    sprintf('http://%s:%d/%s/%s', self::$host, self::$port, $bucket, $key),
                    [
                        'content-length' => (string) strlen($body),
                        'x-amz-content-sha256' => hash('sha256', $body),
                    ],
                    $body,
                ),
                new Credentials(self::$accessKey, self::$secretKey),
            );

            $followUp = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request(
                    'PUT',
                    sprintf('http://%s:%d/%s/%s', self::$host, self::$port, $bucket, 'must-not-run'),
                    [
                        'content-length' => '6',
                        'x-amz-content-sha256' => hash('sha256', 'second'),
                    ],
                    'second',
                ),
                new Credentials(self::$accessKey, self::$secretKey),
            );
            $response = self::sendRawRequest(
                $signed,
                additionalHeaders: ['Transfer-Encoding' => 'chunked'],
                bodyOverride: dechex(strlen($body)) . "\r\n{$body}\r\n0\r\n\r\n",
                connection: 'keep-alive',
                trailingWire: self::serializeWireRequest($followUp, 'close'),
            );
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 400 /', $response);
            self::assertStringContainsString('<Code>InvalidArgument</Code>', $response);
            self::assertMatchesRegularExpression('/^connection: close\r?$/im', $response);
            self::assertSame(1, substr_count($response, 'HTTP/1.1 '));
            self::assertFalse(self::$s3->doesObjectExistV2($bucket, $key));
            self::assertObjectStaysAbsent($bucket, 'must-not-run');
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'must-not-run']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_ambiguous_framing_is_rejected_before_management_and_router_dispatch(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            foreach ([
                'management route' => ['GET', '/.live'],
                'router method rejection' => ['PATCH', '/.live'],
            ] as $label => [$method, $path]) {
                $followKey = strtolower(str_replace(' ', '-', $label));
                $followUp = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                    new Request(
                        'PUT',
                        sprintf('http://%s:%d/%s/%s', self::$host, self::$port, $bucket, $followKey),
                        [
                            'content-length' => '6',
                            'x-amz-content-sha256' => hash('sha256', 'second'),
                        ],
                        'second',
                    ),
                    new Credentials(self::$accessKey, self::$secretKey),
                );
                $ambiguous = new Request(
                    $method,
                    sprintf('http://%s:%d%s', self::$host, self::$port, $path),
                    ['content-length' => '6'],
                    'framed',
                );
                $response = self::sendRawRequest(
                    $ambiguous,
                    additionalHeaders: ['Transfer-Encoding' => 'chunked'],
                    bodyOverride: "6\r\nframed\r\n0\r\n\r\n",
                    connection: 'keep-alive',
                    trailingWire: self::serializeWireRequest($followUp, 'close'),
                );

                self::assertMatchesRegularExpression('/^HTTP\/1\.1 400 /', $response, $label);
                self::assertStringContainsString('<Code>InvalidArgument</Code>', $response, $label);
                self::assertMatchesRegularExpression('/^connection: close\r?$/im', $response, $label);
                self::assertSame(1, substr_count($response, 'HTTP/1.1 '), $label);
                self::assertObjectStaysAbsent($bucket, $followKey, $label);
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'management-route']);
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'router-method-rejection']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_bodyless_delete_has_no_framing_and_closes_http_one_connection(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            self::assertBodylessWireResponse(new Request(
                'DELETE',
                sprintf('http://%s:%d/%s/missing', self::$host, self::$port, $bucket),
            ), 204);
        } finally {
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_not_modified_get_has_no_framing_and_closes_http_one_connection(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $etag = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'conditional', 'Body' => 'stored'])['ETag'];
            self::assertIsString($etag);
            self::assertBodylessWireResponse(new Request(
                'GET',
                sprintf('http://%s:%d/%s/conditional', self::$host, self::$port, $bucket),
                ['If-None-Match' => $etag],
            ), 304);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'conditional']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_not_modified_head_has_no_framing_and_closes_http_one_connection(): void
    {
        $bucket = 'wire-' . bin2hex(random_bytes(8));
        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $etag = self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'conditional', 'Body' => 'stored'])['ETag'];
            self::assertIsString($etag);
            self::assertBodylessWireResponse(new Request(
                'HEAD',
                sprintf('http://%s:%d/%s/conditional', self::$host, self::$port, $bucket),
                ['If-None-Match' => $etag],
            ), 304);
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'conditional']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    private static function assertBodylessWireResponse(Request $unsignedRequest, int $status): void
    {
        $request = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
            $unsignedRequest,
            new Credentials(self::$accessKey, self::$secretKey),
        );
        $wireRequest = $request->getMethod() . ' ' . $request->getRequestTarget() . " HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $wireRequest .= $name . ': ' . $value . "\r\n";
            }
        }
        $wireRequest .= "Connection: keep-alive\r\n\r\n";

        $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
        self::assertNotFalse($socket, $error ?? 'Could not connect to the test server.');
        try {
            stream_set_timeout($socket, 5);
            self::assertSame(strlen($wireRequest), fwrite($socket, $wireRequest));
            $response = stream_get_contents($socket);
            self::assertNotFalse($response);
            self::assertFalse(stream_get_meta_data($socket)['timed_out']);
            self::assertTrue(feof($socket), 'The server must close the HTTP/1 connection after a bodyless response.');
        } finally {
            fclose($socket);
        }

        [$headers, $body] = explode("\r\n\r\n", $response, 2);
        self::assertMatchesRegularExpression('/^HTTP\/1\.1 ' . $status . ' /', $headers);
        self::assertDoesNotMatchRegularExpression('/^content-length:/im', $headers);
        self::assertDoesNotMatchRegularExpression('/^transfer-encoding:/im', $headers);
        self::assertMatchesRegularExpression('/^connection: close\r?$/im', $headers);
        self::assertSame('', $body);
    }

    /** @param array<non-empty-string, string> $additionalHeaders */
    private static function sendRawRequest(
        \Psr\Http\Message\RequestInterface $request,
        bool $reverseAclHeaders = false,
        array $additionalHeaders = [],
        ?string $bodyOverride = null,
        string $connection = 'close',
        string $trailingWire = '',
    ): string {
        $wire = $request->getMethod() . ' ' . $request->getRequestTarget() . " HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            if ($reverseAclHeaders && strtolower($name) === 'x-amz-acl') {
                $values = array_reverse($values);
            }
            foreach ($values as $value) {
                $wire .= $name . ': ' . $value . "\r\n";
            }
        }
        foreach ($additionalHeaders as $name => $value) {
            $wire .= $name . ': ' . $value . "\r\n";
        }
        $wire .= 'Connection: ' . $connection . "\r\n\r\n"
            . ($bodyOverride ?? (string) $request->getBody()) . $trailingWire;

        $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
        self::assertNotFalse($socket, $error ?? 'Could not connect to the test server.');
        try {
            stream_set_timeout($socket, 5);
            self::assertSame(strlen($wire), fwrite($socket, $wire));
            $response = stream_get_contents($socket);
            self::assertIsString($response);
            self::assertFalse(stream_get_meta_data($socket)['timed_out']);

            return $response;
        } finally {
            fclose($socket);
        }
    }

    private static function serializeWireRequest(
        \Psr\Http\Message\RequestInterface $request,
        string $connection,
    ): string {
        $wire = $request->getMethod() . ' ' . $request->getRequestTarget() . " HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            foreach ($values as $value) {
                $wire .= $name . ': ' . $value . "\r\n";
            }
        }

        return $wire . 'Connection: ' . $connection . "\r\n\r\n" . (string) $request->getBody();
    }

    private static function assertObjectStaysAbsent(string $bucket, string $key, string $message = ''): void
    {
        $deadline = microtime(true) + 0.75;
        do {
            self::assertFalse(self::$s3->doesObjectExistV2($bucket, $key), $message);
            usleep(25_000);
        } while (microtime(true) < $deadline);
    }
}
