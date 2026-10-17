<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Aws\Credentials\Credentials;
use Aws\Signature\S3SignatureV4;
use GuzzleHttp\Psr7\Request;
use OpsFour\S3Server\Factory\MetadataStoreFactory;

/** One API process must recheck external metadata changed by another client. */
final class ExternalMetadataMutationHttpTest extends S3FunctionalTestCase
{
    /** @var array<string, string|false> */
    private static array $previousEnv = [];

    public static function setUpBeforeClass(): void
    {
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        if ($driver === false || $dsn === false || $dsn === '') {
            self::markTestSkipped('Set S3_TEST_MULTINODE_METADATA_DRIVER and S3_TEST_MULTINODE_METADATA_DSN to a disposable database.');
        }
        if (! in_array($driver, ['postgres', 'mysql'], true)) {
            throw new \InvalidArgumentException('The external metadata mutation profile supports postgres and mysql.');
        }
        if (preg_match('/(?:^|[;\s])dbname=s3audit_[a-z0-9_]+(?:[;\s]|$)/i', $dsn) !== 1) {
            throw new \InvalidArgumentException('The external metadata mutation profile requires a disposable dbname starting with s3audit_.');
        }

        // API processes validate rather than migrate external schemas.
        MetadataStoreFactory::create($driver, ['dsn' => $dsn]);

        foreach ([
            'S3_METADATA_DRIVER' => $driver,
            'S3_METADATA_DSN' => $dsn,
            'S3_OWNER_ID' => 'metadata-mutation-' . bin2hex(random_bytes(4)),
            'S3_NOTIFICATION_WORKER' => '0',
        ] as $name => $value) {
            self::$previousEnv[$name] = getenv($name);
            putenv($name . '=' . $value);
        }

        try {
            parent::setUpBeforeClass();
        } catch (\Throwable $error) {
            self::restoreEnv();
            throw $error;
        }
    }

    public static function tearDownAfterClass(): void
    {
        try {
            parent::tearDownAfterClass();
        } finally {
            self::restoreEnv();
        }
    }

    public function test_upload_part_rechecks_policy_changed_during_body_read(): void
    {
        $driver = getenv('S3_TEST_MULTINODE_METADATA_DRIVER');
        $dsn = getenv('S3_TEST_MULTINODE_METADATA_DSN');
        self::assertIsString($driver);
        self::assertIsString($dsn);
        $metadata = MetadataStoreFactory::create($driver, ['dsn' => $dsn]);
        $bucket = 'metadata-part-policy-' . bin2hex(random_bytes(8));
        $key = 'part-key';
        $body = str_repeat('p', 64);
        self::$s3->createBucket(['Bucket' => $bucket]);
        $uploadId = (string) self::$s3->createMultipartUpload(['Bucket' => $bucket, 'Key' => $key])['UploadId'];
        $socket = null;

        try {
            $url = sprintf('http://%s:%d/%s/%s?partNumber=1&uploadId=%s', self::$host, self::$port, $bucket, $key, $uploadId);
            $signed = (new S3SignatureV4('s3', 'us-east-1'))->signRequest(
                new Request('PUT', $url, [
                    'Content-Length' => (string) strlen($body),
                    'x-amz-content-sha256' => 'UNSIGNED-PAYLOAD',
                ], $body),
                new Credentials(self::$accessKey, self::$secretKey),
            );
            $wire = $signed->getMethod() . ' ' . $signed->getRequestTarget() . " HTTP/1.1\r\n";
            foreach ($signed->getHeaders() as $name => $values) {
                foreach ($values as $value) {
                    $wire .= $name . ': ' . $value . "\r\n";
                }
            }
            $wire .= "Connection: close\r\n\r\n";

            $socket = stream_socket_client(sprintf('tcp://%s:%d', self::$host, self::$port), $errorCode, $error, 5);
            self::assertNotFalse($socket, $error ?: 'Could not connect to the API process.');
            stream_set_timeout($socket, 10);
            $tempDir = self::$storagePath . '/.tmp';
            $before = glob($tempDir . '/*') ?: [];
            self::writeAll($socket, $wire . substr($body, 0, 8));

            // The backend has passed initial authorization once the request is
            // staging. Mutate SQL directly to model an independent admin client.
            $deadline = microtime(true) + 5;
            $staged = false;
            do {
                $staged = array_diff(glob($tempDir . '/*') ?: [], $before) !== [];
                if ($staged) {
                    break;
                }
                usleep(10_000);
            } while (microtime(true) < $deadline);
            self::assertTrue($staged, 'The API process did not begin staging the paused part.');

            $metadata->putBucketPolicy($bucket, json_encode([
                'Statement' => [[
                    'Effect' => 'Deny',
                    'Principal' => '*',
                    'Action' => 's3:PutObject',
                    'Resource' => 'arn:aws:s3:::' . $bucket . '/' . $key,
                ]],
            ], JSON_THROW_ON_ERROR));
            self::writeAll($socket, substr($body, 8));
            $response = stream_get_contents($socket);
            self::assertIsString($response);
            self::assertFalse(stream_get_meta_data($socket)['timed_out']);
            self::assertMatchesRegularExpression('/^HTTP\/1\.1 403 /', $response);
            self::assertStringContainsString('<Code>AccessDenied</Code>', $response);
            self::assertSame([], self::$s3->listParts([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
            ])['Parts'] ?? []);

            $metadata->deleteBucketPolicy($bucket);
            self::$s3->uploadPart([
                'Bucket' => $bucket,
                'Key' => $key,
                'UploadId' => $uploadId,
                'PartNumber' => 1,
                'Body' => $body,
            ]);
            self::assertCount(1, self::$s3->listParts([
                'Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId,
            ])['Parts'] ?? []);
        } finally {
            if (is_resource($socket)) {
                fclose($socket);
            }
            try {
                $metadata->deleteBucketPolicy($bucket);
            } catch (\Throwable) {
            }
            self::$s3->abortMultipartUpload(['Bucket' => $bucket, 'Key' => $key, 'UploadId' => $uploadId]);
            self::$s3->deleteBucket(['Bucket' => $bucket]);
        }
    }

    /** @param resource $socket */
    private static function writeAll($socket, string $bytes): void
    {
        while ($bytes !== '') {
            $written = fwrite($socket, $bytes);
            self::assertNotFalse($written);
            self::assertGreaterThan(0, $written);
            $bytes = substr($bytes, $written);
        }
    }

    private static function restoreEnv(): void
    {
        foreach (self::$previousEnv as $name => $value) {
            putenv($value === false ? $name : $name . '=' . $value);
        }
        self::$previousEnv = [];
    }
}
