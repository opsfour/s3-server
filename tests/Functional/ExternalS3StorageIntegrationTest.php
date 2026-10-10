<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Functional;

use Amp\ByteStream\ReadableBuffer;
use Aws\S3\S3Client;
use OpsFour\S3Server\Storage\AwsS3FlysystemFilesystemFactory;
use OpsFour\S3Server\Storage\ParallelFlysystemBackend;
use PHPUnit\Framework\TestCase;

final class ExternalS3StorageIntegrationTest extends TestCase
{
    private S3Client $client;

    private ParallelFlysystemBackend $backend;

    private string $bucket;

    private string $logicalBucket;

    /** @var list<string> */
    private array $createdStoragePaths = [];

    /** @var list<string> */
    private array $createdUploadIds = [];

    private bool $markerProvisionAttempted = false;

    public static function setUpBeforeClass(): void
    {
        self::loadIntegrationEnv();
    }

    protected function setUp(): void
    {
        if (! self::envBool('S3_INTEGRATION_ENABLED')) {
            self::markTestSkipped('Set S3_INTEGRATION_ENABLED=1 to run external S3-compatible storage tests.');
        }

        $endpoint = self::requiredEnv('S3_INTEGRATION_ENDPOINT');
        $region = self::requiredEnv('S3_INTEGRATION_REGION');
        $accessKey = self::requiredEnv('S3_INTEGRATION_ACCESS_KEY');
        $secretKey = self::requiredEnv('S3_INTEGRATION_SECRET_KEY');
        $this->bucket = self::integrationBucket();
        $this->logicalBucket = self::prefix() . '-' . bin2hex(random_bytes(16));

        $this->client = new S3Client([
            'version' => 'latest',
            'region' => $region,
            'endpoint' => $endpoint,
            'use_path_style_endpoint' => self::envBool('S3_INTEGRATION_PATH_STYLE', true),
            'credentials' => [
                'key' => $accessKey,
                'secret' => $secretKey,
            ],
            'http' => [
                'connect_timeout' => 10,
                'timeout' => self::envInt('S3_INTEGRATION_TIMEOUT', 120),
            ],
        ]);

        $this->backend = new ParallelFlysystemBackend(
            new AwsS3FlysystemFilesystemFactory(
                remoteBucket: $this->bucket,
                region: $region,
                accessKeyId: $accessKey,
                secretAccessKey: $secretKey,
                endpoint: $endpoint,
                pathStyle: self::envBool('S3_INTEGRATION_PATH_STYLE', true),
                prefix: self::prefix(),
            ),
            sys_get_temp_dir(),
            self::envInt('S3_INTEGRATION_FLYSYSTEM_WORKERS', 4),
        );
    }

    protected function tearDown(): void
    {
        if (! isset($this->client)) {
            return;
        }

        $cleanupFailed = false;
        try {
            foreach ($this->createdStoragePaths as $path) {
                try {
                    $this->client->deleteObject([
                        'Bucket' => $this->bucket,
                        'Key' => self::remoteKeyForPath($path),
                    ]);
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }

            if ($this->markerProvisionAttempted) {
                // The AWS Flysystem adapter creates exactly this directory marker.
                // Never recursively delete the logical bucket prefix: another
                // generation could already be writing below the same name.
                try {
                    $this->client->deleteObject([
                        'Bucket' => $this->bucket,
                        'Key' => self::remoteKeyForPath($this->logicalBucket . '/'),
                    ]);
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }

            $prefixes = $this->markerProvisionAttempted ? [$this->logicalBucket . '/'] : [];
            foreach ($this->createdUploadIds as $uploadId) {
                $prefixes[] = '.parts/' . $uploadId . '/';
            }
            foreach ($prefixes as $prefix) {
                try {
                    $listed = $this->client->listObjectsV2([
                        'Bucket' => $this->bucket,
                        'Prefix' => self::remoteKeyForPath($prefix),
                        'MaxKeys' => 1,
                    ]);
                    if (($listed['Contents'] ?? []) !== [] || ($listed['IsTruncated'] ?? false)) {
                        $cleanupFailed = true;
                    }
                } catch (\Throwable) {
                    $cleanupFailed = true;
                }
            }
        } finally {
            if (isset($this->backend)) {
                $this->backend->shutdown();
            }
        }

        self::assertFalse($cleanupFailed, 'Exact external S3 test cleanup failed or left objects in a test prefix.');
    }

    public function test_external_s3_backend_round_trips_large_object_copy_and_delete(): void
    {
        $this->createLogicalBucket();

        $payload = self::deterministicPayload(self::envInt('S3_INTEGRATION_OBJECT_BYTES', 2 * 1024 * 1024));
        $write = $this->backend->putObject($this->logicalBucket, 'objects/large.bin', new ReadableBuffer($payload));
        $this->createdStoragePaths[] = $write->path;

        self::assertSame(strlen($payload), $write->size);
        self::assertSame(hash('md5', $payload), $write->md5Hex);
        self::assertSame($payload, self::readAll($this->backend->getObjectByPath($write->path)));

        $slice = $this->backend->getObjectByPath($write->path, 1024, 4096);
        self::assertSame(substr($payload, 1024, 4096), self::readAll($slice));

        $copy = $this->backend->copyObjectBounded($write->path, $this->logicalBucket, 'objects/copy.bin', strlen($payload) + 1);
        $this->createdStoragePaths[] = $copy->path;
        self::assertSame($payload, self::readAll($this->backend->getObjectByPath($copy->path)));

        $this->backend->deleteObjectByPath($write->path, $this->logicalBucket);
        $this->createdStoragePaths = array_values(array_filter(
            $this->createdStoragePaths,
            static fn(string $path): bool => $path !== $write->path,
        ));

        $this->expectException(\OpsFour\S3Server\Exception\NoSuchKeyException::class);
        $this->backend->getObjectByPath($write->path);
    }

    public function test_external_s3_backend_multipart_parts_survive_assembly_until_commit_cleanup(): void
    {
        $this->createLogicalBucket();
        $uploadId = 'integration-' . bin2hex(random_bytes(16));
        $this->createdUploadIds[] = $uploadId;
        $partBytes = self::envInt('S3_INTEGRATION_MULTIPART_PART_BYTES', 1024 * 1024);
        $expected = '';
        $parts = [];

        for ($partNumber = 1; $partNumber <= 3; $partNumber++) {
            $payload = str_repeat(chr(64 + $partNumber), $partBytes);
            $expected .= $payload;
            $part = $this->backend->putPart(
                $this->logicalBucket,
                'multipart/assembled.bin',
                $uploadId,
                $partNumber,
                new ReadableBuffer($payload),
            );
            $this->createdStoragePaths[] = $part->path;
            $parts[] = [
                'partNumber' => $partNumber,
                'etag' => $part->md5Hex,
                'storagePath' => $part->path,
            ];
        }

        $assembled = $this->backend->assembleMultipartUpload(
            $this->logicalBucket,
            'multipart/assembled.bin',
            $uploadId,
            $parts,
        );
        $this->createdStoragePaths[] = $assembled->path;

        self::assertSame(strlen($expected), $assembled->size);
        self::assertStringEndsWith('-3', $assembled->md5Hex);
        self::assertSame($expected, self::readAll($this->backend->getObjectByPath($assembled->path)));

        $list = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => self::prefix() . '/.parts/' . $uploadId . '/',
        ]);

        self::assertSame(3, count($list['Contents'] ?? []));

        $this->backend->abortMultipartUpload(
            $this->logicalBucket,
            'multipart/assembled.bin',
            $uploadId,
        );
        $afterAbort = $this->client->listObjectsV2([
            'Bucket' => $this->bucket,
            'Prefix' => self::prefix() . '/.parts/' . $uploadId . '/',
        ]);
        self::assertSame(0, count($afterAbort['Contents'] ?? []));
    }

    private function createLogicalBucket(): void
    {
        $this->markerProvisionAttempted = true;
        $this->backend->createBucket($this->logicalBucket);
    }

    private static function remoteKeyForPath(string $path): string
    {
        $prefix = self::prefix();

        return ($prefix === '' ? '' : $prefix . '/') . $path;
    }

    private static function loadIntegrationEnv(): void
    {
        $path = __DIR__ . '/../../.env.integration';
        if (! is_file($path)) {
            return;
        }

        foreach (file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $line) {
            $line = trim($line);
            if ($line === '' || str_starts_with($line, '#') || ! str_contains($line, '=')) {
                continue;
            }

            [$key, $value] = explode('=', $line, 2);
            $key = trim($key);
            $value = trim($value);
            $value = trim($value, "\"'");

            if ($key !== '' && getenv($key) === false) {
                putenv($key . '=' . $value);
                $_ENV[$key] = $value;
            }
        }
    }

    private static function requiredEnv(string $name): string
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            self::markTestSkipped("Missing {$name} for external S3 integration test.");
        }

        return (string) $value;
    }

    private static function integrationBucket(): string
    {
        $bucket = getenv('S3_INTEGRATION_BUCKET');
        if ($bucket !== false && $bucket !== '') {
            return $bucket;
        }

        $fallback = getenv('S3_INTEGRATION_BUCKET_PREFIX');
        if ($fallback !== false && $fallback !== '') {
            return $fallback;
        }

        self::markTestSkipped('Missing S3_INTEGRATION_BUCKET for external S3 integration test.');
    }

    private static function prefix(): string
    {
        $prefix = getenv('S3_INTEGRATION_PREFIX');
        if ($prefix !== false && $prefix !== '') {
            return trim($prefix, '/');
        }

        $fallback = getenv('S3_INTEGRATION_BUCKET_PREFIX');
        if ($fallback !== false && $fallback !== '') {
            return trim($fallback, '/') . '/opsfour-s3server-integration';
        }

        return 'opsfour-s3server-integration';
    }

    private static function envBool(string $name, bool $default = false): bool
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true);
    }

    private static function envInt(string $name, int $default): int
    {
        $value = getenv($name);
        if ($value === false || $value === '') {
            return $default;
        }

        return max(1, (int) $value);
    }

    private static function deterministicPayload(int $bytes): string
    {
        $chunk = hash('sha256', 'opsfour-s3server-integration', true);
        $payload = '';

        while (strlen($payload) < $bytes) {
            $payload .= $chunk;
        }

        return substr($payload, 0, $bytes);
    }

    private static function readAll(\Amp\ByteStream\ReadableStream $stream): string
    {
        $buffer = '';
        while (($chunk = $stream->read()) !== null) {
            $buffer .= $chunk;
        }

        return $buffer;
    }
}
