<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;
use OpsFour\S3Server\Storage\FlysystemFilesystemFactory;

/** Test-only CA path; production AWS factory and process trust remain unchanged. */
final readonly class LocalTlsFlysystemFactory implements FlysystemFilesystemFactory
{
    public function __construct(
        private string $endpoint,
        private string $caFile,
        private string $remoteBucket = 'remote-bucket',
        private string $region = 'us-east-1',
        private string $accessKey = 'local-key',
        private string $secretKey = 'local-secret',
        private int $readTimeoutSeconds = 5,
    ) {}

    public function createFilesystem(): FilesystemOperator
    {
        $client = new S3Client([
            'version' => 'latest',
            'region' => $this->region,
            'endpoint' => $this->endpoint,
            'use_path_style_endpoint' => true,
            'credentials' => ['key' => $this->accessKey, 'secret' => $this->secretKey],
            'http' => ['connect_timeout' => 5, 'verify' => $this->caFile],
        ]);

        return new Filesystem(new AwsS3V3Adapter($client, $this->remoteBucket, options: [
            '@http' => ['stream' => true, 'timeout' => $this->readTimeoutSeconds, 'read_timeout' => $this->readTimeoutSeconds],
        ]));
    }

    public function cacheKey(): string
    {
        return self::class . ':' . hash('sha256', implode("\0", [
            $this->endpoint,
            $this->caFile,
            $this->remoteBucket,
            $this->region,
            $this->accessKey,
            hash('sha256', $this->secretKey),
            (string) $this->readTimeoutSeconds,
        ]));
    }
}
