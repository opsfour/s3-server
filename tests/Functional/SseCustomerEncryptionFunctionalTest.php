<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\S3\Exception\S3Exception;
use OpsFour\S3Server\Storage\StoragePath;

final class SseCustomerEncryptionFunctionalTest extends S3FunctionalTestCase
{
    private static string|false $previousMasterKey = false;

    public static function setUpBeforeClass(): void
    {
        self::$previousMasterKey = getenv('S3_ENCRYPTION_MASTER_KEY');
        putenv('S3_ENCRYPTION_MASTER_KEY=' . base64_encode(str_repeat('M', 32)));

        parent::setUpBeforeClass();
        self::allowSseCOverLocalHttp();
    }

    public static function tearDownAfterClass(): void
    {
        parent::tearDownAfterClass();

        if (self::$previousMasterKey === false) {
            putenv('S3_ENCRYPTION_MASTER_KEY');
        } else {
            putenv('S3_ENCRYPTION_MASTER_KEY=' . self::$previousMasterKey);
        }
    }

    public function test_sse_c_round_trip_copy_and_restart_fail_closed(): void
    {
        if ((getenv('S3_TEST_SERVER_STORAGE_DRIVER') ?: 'filesystem') !== 'filesystem') {
            self::markTestSkipped('The physical ciphertext assertion requires the filesystem storage driver.');
        }

        $bucket = 'sse-c-' . bin2hex(random_bytes(4));
        $key = 'source.txt';
        $copyKey = 'copy.txt';
        $plaintext = 'sse-c-plaintext-marker-' . bin2hex(random_bytes(24));
        $customerKey = hash('sha256', 'functional-sse-c-customer-key', true);
        $wrongKey = hash('sha256', 'functional-sse-c-wrong-key', true);

        self::$s3->createBucket(['Bucket' => $bucket]);

        try {
            $put = self::$s3->putObject([
                'Bucket' => $bucket,
                'Key' => $key,
                'Body' => $plaintext,
                'ContentType' => 'text/plain',
            ] + self::sseCHeaders($customerKey));

            self::assertSame(200, $put['@metadata']['statusCode']);
            self::assertSame('AES256', $put['SSECustomerAlgorithm']);
            self::assertSame(base64_encode(md5($customerKey, true)), $put['SSECustomerKeyMD5']);

            self::assertSseCRoundTrip($bucket, $key, $plaintext, $customerKey);
            self::assertSseCReadRejected($bucket, $key, []);
            self::assertSseCReadRejected($bucket, $key, self::sseCHeaders($wrongKey));
            self::assertCiphertextDoesNotContainPlaintext($bucket, $key, $plaintext);

            $copy = self::$s3->copyObject([
                'Bucket' => $bucket,
                'Key' => $copyKey,
                'CopySource' => $bucket . '/' . $key,
            ] + self::sseCHeaders($customerKey) + self::copySourceSseCHeaders($customerKey));

            self::assertSame(200, $copy['@metadata']['statusCode']);
            self::assertSame('AES256', $copy['SSECustomerAlgorithm']);
            self::assertSseCRoundTrip($bucket, $copyKey, $plaintext, $customerKey);
            self::assertCiphertextDoesNotContainPlaintext($bucket, $copyKey, $plaintext);

            self::restartServer();
            self::allowSseCOverLocalHttp();

            self::assertSseCRoundTrip($bucket, $key, $plaintext, $customerKey);
            self::assertSseCReadRejected($bucket, $copyKey, self::sseCHeaders($wrongKey));
        } finally {
            foreach ([$key, $copyKey] as $objectKey) {
                try {
                    self::$s3->deleteObject(['Bucket' => $bucket, 'Key' => $objectKey]);
                } catch (\Throwable) {
                    // Preserve the original test failure when cleanup is unavailable.
                }
            }
            self::deleteBucketWithDiagnostics($bucket);
        }
    }

    private static function allowSseCOverLocalHttp(): void
    {
        // The SDK correctly requires TLS for production SSE-C traffic. This
        // loopback-only functional server has no TLS listener, so provide the
        // already encoded wire values while retaining SDK signing and transport.
        self::$s3->getHandlerList()->remove('s3.ssec');
    }

    /** @return array{SSECustomerAlgorithm: string, SSECustomerKey: string, SSECustomerKeyMD5: string} */
    private static function sseCHeaders(string $key): array
    {
        return [
            'SSECustomerAlgorithm' => 'AES256',
            'SSECustomerKey' => base64_encode($key),
            'SSECustomerKeyMD5' => base64_encode(md5($key, true)),
        ];
    }

    /** @return array{CopySourceSSECustomerAlgorithm: string, CopySourceSSECustomerKey: string, CopySourceSSECustomerKeyMD5: string} */
    private static function copySourceSseCHeaders(string $key): array
    {
        return [
            'CopySourceSSECustomerAlgorithm' => 'AES256',
            'CopySourceSSECustomerKey' => base64_encode($key),
            'CopySourceSSECustomerKeyMD5' => base64_encode(md5($key, true)),
        ];
    }

    private static function assertSseCRoundTrip(string $bucket, string $key, string $plaintext, string $customerKey): void
    {
        $head = self::$s3->headObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ] + self::sseCHeaders($customerKey));
        self::assertSame(200, $head['@metadata']['statusCode']);
        self::assertSame(strlen($plaintext), $head['ContentLength']);
        self::assertSame('AES256', $head['SSECustomerAlgorithm']);
        self::assertSame(base64_encode(md5($customerKey, true)), $head['SSECustomerKeyMD5']);

        $get = self::$s3->getObject([
            'Bucket' => $bucket,
            'Key' => $key,
        ] + self::sseCHeaders($customerKey));
        self::assertSame(200, $get['@metadata']['statusCode']);
        self::assertSame($plaintext, (string) $get['Body']);
        self::assertSame('AES256', $get['SSECustomerAlgorithm']);
    }

    /** @param array<string, string> $headers */
    private static function assertSseCReadRejected(string $bucket, string $key, array $headers): void
    {
        foreach (['headObject', 'getObject'] as $operation) {
            try {
                self::$s3->{$operation}([
                    'Bucket' => $bucket,
                    'Key' => $key,
                ] + $headers);
                self::fail("{$operation} accepted missing or incorrect SSE-C credentials.");
            } catch (S3Exception $error) {
                self::assertSame(400, $error->getStatusCode(), $operation);
                if ($operation === 'getObject') {
                    self::assertSame('InvalidArgument', $error->getAwsErrorCode(), $operation);
                }
            }
        }
    }

    private static function assertCiphertextDoesNotContainPlaintext(
        string $bucket,
        string $key,
        string $plaintext,
    ): void {
        $directory = StoragePath::directoryForObject(self::$storagePath, $bucket, $key);
        $paths = glob($directory . '/*');
        self::assertIsArray($paths);
        $files = array_values(array_filter($paths, 'is_file'));
        self::assertCount(1, $files);

        $physicalBytes = file_get_contents($files[0]);
        self::assertIsString($physicalBytes);
        self::assertNotSame($plaintext, $physicalBytes);
        self::assertStringNotContainsString($plaintext, $physicalBytes);
    }
}
