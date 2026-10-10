<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\CommandPool;
use Aws\ResultInterface;
use Psr\Http\Message\UriInterface;

/**
 * HTTP/1 HEAD framing and connection reuse with both the AWS SDK CommandPool
 * and independent libcurl requests signed by the SDK.
 */
final class HeadConnectionReuseHttpTest extends S3FunctionalTestCase
{
    public function test_sdk_concurrent_head_then_get_reuses_the_same_client(): void
    {
        $bucket = 'head-sdk-reuse-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        $keys = [];
        $completed = false;

        try {
            $expected = [];
            for ($index = 0; $index < 10; $index++) {
                $key = "object/{$index}.bin";
                $body = str_repeat(chr(65 + $index), 65_536);
                $keys[] = ['Key' => $key];
                $expected[$index] = $body;
                self::$s3->putObject([
                    'Bucket' => $bucket,
                    'Key' => $key,
                    'Body' => $body,
                    'ContentSHA256' => 'UNSIGNED-PAYLOAD',
                ]);
            }

            // Repeated ten-way HEAD/GET batches exercise the same SDK cURL
            // pool without a concurrent write masking the transport result.
            for ($batch = 0; $batch < 30; $batch++) {
                $heads = [];
                $gets = [];
                for ($index = 0; $index < 10; $index++) {
                    $key = "object/{$index}.bin";
                    $heads[] = self::$s3->getCommand('HeadObject', ['Bucket' => $bucket, 'Key' => $key]);
                    $gets[$index] = self::$s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]);
                }

                $this->runSdkPool($heads, $batch, 'HEAD', static function (ResultInterface $result): void {
                    self::assertSame(65_536, $result['ContentLength']);
                });
                $this->runSdkPool($gets, $batch, 'GET', static function (ResultInterface $result, int|string $index) use ($expected): void {
                    self::assertSame($expected[(int) $index], (string) $result['Body']);
                });
            }
            $completed = true;
        } finally {
            if ($keys !== []) {
                self::$s3->deleteObjects(['Bucket' => $bucket, 'Delete' => ['Objects' => $keys]]);
            }
            // An in-flight failed request may still finish after the client
            // timeout. Preserve the first failure instead of masking it with
            // a BucketNotEmpty cleanup error.
            try {
                self::$s3->deleteBucket(['Bucket' => $bucket]);
            } catch (\Aws\S3\Exception\S3Exception $error) {
                if ($completed || $error->getAwsErrorCode() !== 'BucketNotEmpty') {
                    throw $error;
                }
            }
        }
    }

    public function test_parallel_head_bursts_leave_follow_up_get_usable(): void
    {
        $bucket = 'head-reuse-' . bin2hex(random_bytes(4));
        $key = 'five.txt';
        $body = 'abcde';
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => $key, 'Body' => $body]);

        try {
            $head = self::$s3->createPresignedRequest(
                self::$s3->getCommand('HeadObject', ['Bucket' => $bucket, 'Key' => $key]),
                '+5 minutes',
            );
            $get = self::$s3->createPresignedRequest(
                self::$s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => $key]),
                '+5 minutes',
            );

            $this->assertRawHeadFraming($head->getUri(), false);
            $this->assertRawHeadFraming($head->getUri(), true);

            foreach ([50, 100] as $parallel) {
                $multi = curl_multi_init();
                try {
                    /** @var list<array{handle: \CurlHandle, range: bool}> $requests */
                    $requests = [];
                    $headerLines = [];
                    for ($index = 0; $index < $parallel; $index++) {
                        $withRange = $index % 2 === 1;
                        $headerLines[$index] = '';
                        $handle = curl_init((string) $head->getUri());
                        self::assertInstanceOf(\CurlHandle::class, $handle);
                        curl_setopt_array($handle, [
                            CURLOPT_NOBODY => true,
                            CURLOPT_RETURNTRANSFER => true,
                            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                            CURLOPT_CONNECTTIMEOUT => 5,
                            CURLOPT_TIMEOUT => 20,
                            CURLOPT_HTTPHEADER => $withRange ? ['Range: bytes=1-2'] : [],
                            CURLOPT_HEADERFUNCTION => static function (\CurlHandle $curl, string $line) use (&$headerLines, $index): int {
                                $headerLines[$index] .= $line;

                                return strlen($line);
                            },
                        ]);
                        curl_multi_add_handle($multi, $handle);
                        $requests[] = ['handle' => $handle, 'range' => $withRange];
                    }

                    self::runMulti($multi);
                    foreach ($requests as $index => $request) {
                        $handle = $request['handle'];
                        $headers = $headerLines[$index];
                        self::assertSame(CURLE_OK, curl_errno($handle), curl_error($handle));
                        self::assertSame(200, curl_getinfo($handle, CURLINFO_RESPONSE_CODE));
                        self::assertSame('', curl_multi_getcontent($handle));
                        self::assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $headers);
                        self::assertMatchesRegularExpression(
                            '/^content-length: ' . ($request['range'] ? '2' : '5') . '\r?$/im',
                            $headers,
                        );
                        self::assertMatchesRegularExpression('/^connection: close\r?$/im', $headers);
                        self::assertDoesNotMatchRegularExpression('/^transfer-encoding:/im', $headers);
                        self::assertDoesNotMatchRegularExpression('/^content-range:/im', $headers);
                        curl_multi_remove_handle($multi, $handle);
                    }

                    // The same multi handle keeps its connection pool. A follow-up
                    // GET must complete even after all HEAD sockets were closed.
                    $followUp = curl_init((string) $get->getUri());
                    self::assertInstanceOf(\CurlHandle::class, $followUp);
                    curl_setopt_array($followUp, [
                        CURLOPT_RETURNTRANSFER => true,
                        CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
                        CURLOPT_CONNECTTIMEOUT => 5,
                        CURLOPT_TIMEOUT => 20,
                    ]);
                    curl_multi_add_handle($multi, $followUp);
                    self::runMulti($multi);
                    self::assertSame(CURLE_OK, curl_errno($followUp), curl_error($followUp));
                    self::assertSame(200, curl_getinfo($followUp, CURLINFO_RESPONSE_CODE));
                    self::assertSame($body, curl_multi_getcontent($followUp));
                    self::assertSame(1, curl_getinfo($followUp, CURLINFO_NUM_CONNECTS));
                    curl_multi_remove_handle($multi, $followUp);
                } finally {
                    curl_multi_close($multi);
                }
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $key]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    public function test_missing_head_does_not_leave_error_body_before_follow_up_get(): void
    {
        $bucket = 'head-missing-' . bin2hex(random_bytes(4));
        self::$s3->createBucket(['Bucket' => $bucket]);
        self::$s3->putObject(['Bucket' => $bucket, 'Key' => 'present', 'Body' => 'abcde']);

        try {
            $head = self::$s3->createPresignedRequest(
                self::$s3->getCommand('HeadObject', ['Bucket' => $bucket, 'Key' => 'missing']),
                '+5 minutes',
            );
            $get = self::$s3->createPresignedRequest(
                self::$s3->getCommand('GetObject', ['Bucket' => $bucket, 'Key' => 'present']),
                '+5 minutes',
            );

            $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
            self::assertIsResource($socket, $error ?? 'Could not connect to the test server.');
            try {
                stream_set_timeout($socket, 5);
                $request = sprintf(
                    "HEAD %s?%s HTTP/1.1\r\nHost: %s:%d\r\nConnection: keep-alive\r\n\r\n",
                    $head->getUri()->getPath(),
                    $head->getUri()->getQuery(),
                    self::$host,
                    self::$port,
                );
                self::assertSame(strlen($request), fwrite($socket, $request));
                $headers = self::readHeaders($socket);
                self::assertMatchesRegularExpression('/^HTTP\/1\.1 404 /', $headers);

                if (preg_match('/^connection: close\r?$/im', $headers) === 1) {
                    self::assertSame('', stream_get_contents($socket));
                    self::assertTrue(feof($socket));

                    return;
                }

                $request = sprintf(
                    "GET %s?%s HTTP/1.1\r\nHost: %s:%d\r\nConnection: close\r\n\r\n",
                    $get->getUri()->getPath(),
                    $get->getUri()->getQuery(),
                    self::$host,
                    self::$port,
                );
                self::assertSame(strlen($request), fwrite($socket, $request));
                $headers = self::readHeaders($socket);
                self::assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $headers);
                self::assertSame('abcde', stream_get_contents($socket));
            } finally {
                fclose($socket);
            }
        } finally {
            self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => 'present']);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    /** @param resource $socket */
    private static function readHeaders($socket): string
    {
        $headers = '';
        while (($line = fgets($socket)) !== false) {
            if ($line === "\r\n") {
                return $headers;
            }
            $headers .= $line;
        }

        self::fail('HTTP response headers were incomplete.');
    }

    private function assertRawHeadFraming(UriInterface $uri, bool $withRange): void
    {
        $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
        self::assertIsResource($socket, $error ?? 'Could not connect to the test server.');
        try {
            stream_set_timeout($socket, 5);
            $target = $uri->getPath() . '?' . $uri->getQuery();
            $host = $uri->getHost() . ':' . $uri->getPort();
            $range = $withRange ? "Range: bytes=1-2\r\n" : '';
            $request = "HEAD {$target} HTTP/1.1\r\nHost: {$host}\r\nConnection: keep-alive\r\n{$range}\r\n";
            self::assertSame(strlen($request), fwrite($socket, $request));

            $wire = stream_get_contents($socket);
            self::assertIsString($wire);
            self::assertTrue(feof($socket), 'The server must close HTTP/1 HEAD before the next response.');
            [$headers, $body] = array_pad(explode("\r\n\r\n", $wire, 2), 2, null);
            self::assertIsString($headers);
            self::assertSame('', $body);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 200 /', $headers);
            self::assertMatchesRegularExpression('/^connection: close\r?$/im', $headers);
            self::assertMatchesRegularExpression('/^content-length: ' . ($withRange ? '2' : '5') . '\r?$/im', $headers);
            self::assertDoesNotMatchRegularExpression('/^transfer-encoding:/im', $headers);
        } finally {
            fclose($socket);
        }
    }

    private static function runMulti(\CurlMultiHandle $multi): void
    {
        do {
            $result = curl_multi_exec($multi, $running);
            self::assertSame(CURLM_OK, $result);
            if ($running > 0) {
                curl_multi_select($multi, 0.2);
            }
        } while ($running > 0);
    }

    /**
     * @param array<int|string, \Aws\CommandInterface> $commands
     */
    private function runSdkPool(array $commands, int $batch, string $operation, ?callable $fulfilled = null): void
    {
        $errors = [];
        $pool = new CommandPool(self::$s3, $commands, [
            'concurrency' => 10,
            'fulfilled' => static function (ResultInterface $result, int|string $index) use ($fulfilled): void {
                if ($fulfilled !== null) {
                    $fulfilled($result, $index);
                }
            },
            'rejected' => static function ($reason) use (&$errors): void {
                $errors[] = $reason instanceof \Throwable ? $reason->getMessage() : (string) $reason;
            },
        ]);
        $pool->promise()->wait();

        self::assertSame([], $errors, "Batch {$batch} {$operation} failed.\n" . self::serverLogs());
    }
}
