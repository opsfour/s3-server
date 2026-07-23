<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\S3\S3Client;
use League\Flysystem\AwsS3V3\AwsS3V3Adapter;
use League\Flysystem\Filesystem;
use League\Flysystem\FilesystemOperator;

/**
 * Serializable worker factory for S3-compatible Flysystem storage, including
 * AWS S3, Linode/Akamai Object Storage, MinIO, and Cloudflare R2.
 */
final readonly class AwsS3FlysystemFilesystemFactory implements FlysystemFilesystemFactory
{
    public function __construct(
        private string $remoteBucket,
        private string $region,
        private string $accessKeyId,
        private string $secretAccessKey,
        private ?string $endpoint = null,
        private bool $pathStyle = false,
        private string $prefix = '',
    ) {}

    public function createFilesystem(): FilesystemOperator
    {
        if (! class_exists(S3Client::class) || ! class_exists(AwsS3V3Adapter::class)) {
            throw new \RuntimeException(
                'AWS Flysystem worker mode requires aws/aws-sdk-php and league/flysystem-aws-s3-v3.',
            );
        }

        $config = [
            'version' => 'latest',
            'region' => $this->region,
            'use_path_style_endpoint' => $this->pathStyle,
            'credentials' => [
                'key' => $this->accessKeyId,
                'secret' => $this->secretAccessKey,
            ],
        ];
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $config['endpoint'] = $this->endpoint;
        }

        $client = new S3Client($config);

        return new Filesystem(
            new AwsS3V3Adapter($client, $this->remoteBucket, $this->prefix),
        );
    }

    public function cacheKey(): string
    {
        return self::class . ':' . hash('sha256', implode("\0", [
            $this->remoteBucket,
            $this->region,
            $this->accessKeyId,
            hash('sha256', $this->secretAccessKey),
            $this->endpoint ?? '',
            $this->pathStyle ? '1' : '0',
            $this->prefix,
        ]));
    }
}
