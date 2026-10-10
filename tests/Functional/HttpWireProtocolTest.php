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

    public function test_reordering_duplicate_signed_acl_headers_rejects_the_same_signature(): void
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

            $accepted = self::sendRawRequest($signed);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $accepted);
            self::assertSame($body, (string) self::$s3->getObject(['Bucket' => $bucket, 'Key' => $key])['Body']);
            self::assertCount(1, self::$s3->getObjectAcl(['Bucket' => $bucket, 'Key' => $key])['Grants']);

            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            $rejected = self::sendRawRequest($signed, reverseAclHeaders: true);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 403 /', $rejected);
            self::assertStringContainsString('<Code>SignatureDoesNotMatch</Code>', $rejected);
            self::assertFalse(self::$s3->doesObjectExistV2($bucket, $key));
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
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

    private static function sendRawRequest(\Psr\Http\Message\RequestInterface $request, bool $reverseAclHeaders = false): string
    {
        $wire = $request->getMethod() . ' ' . $request->getRequestTarget() . " HTTP/1.1\r\n";
        foreach ($request->getHeaders() as $name => $values) {
            if ($reverseAclHeaders && strtolower($name) === 'x-amz-acl') {
                $values = array_reverse($values);
            }
            foreach ($values as $value) {
                $wire .= $name . ': ' . $value . "\r\n";
            }
        }
        $wire .= "Connection: close\r\n\r\n" . (string) $request->getBody();

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
}
