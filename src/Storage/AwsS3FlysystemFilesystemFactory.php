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
        private int $connectTimeoutSeconds = 5,
        private int $readTimeoutSeconds = 15,
        private int $requestTimeoutSeconds = 0,
        private bool $allowInsecureHttpForTests = false,
    ) {
        if ($connectTimeoutSeconds < 1 || $readTimeoutSeconds < 1 || $requestTimeoutSeconds < 0 || ($requestTimeoutSeconds > 0 && $connectTimeoutSeconds > $requestTimeoutSeconds)) {
            throw new \InvalidArgumentException('Backing S3 timeouts must be valid and a finite request timeout must not be shorter than the connect timeout.');
        }

        $this->validateEndpoint();
    }

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
            'http' => [
                'connect_timeout' => $this->connectTimeoutSeconds,
            ],
        ];
        if ($this->requestTimeoutSeconds > 0) {
            $config['http']['timeout'] = $this->requestTimeoutSeconds;
        }
        if ($this->endpoint !== null && $this->endpoint !== '') {
            $config['endpoint'] = $this->endpoint;
        }

        $client = new S3Client($config);

        return new Filesystem(
            new AwsS3V3Adapter($client, $this->remoteBucket, $this->prefix, options: [
                '@http' => [
                    'stream' => true,
                    'timeout' => $this->readTimeoutSeconds,
                    'read_timeout' => $this->readTimeoutSeconds,
                ],
            ]),
        );
    }

    public function readTimeoutSeconds(): int
    {
        return $this->readTimeoutSeconds;
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
            (string) $this->connectTimeoutSeconds,
            (string) $this->readTimeoutSeconds,
            (string) $this->requestTimeoutSeconds,
            $this->allowInsecureHttpForTests ? '1' : '0',
        ]));
    }

    private function validateEndpoint(): void
    {
        if ($this->endpoint === null || $this->endpoint === '') {
            return;
        }

        if (preg_match('/[\x00-\x20\x7f]/', $this->endpoint) === 1) {
            throw new \InvalidArgumentException('Backing S3 endpoint must be a valid HTTP(S) URI with a valid host.');
        }

        $uri = parse_url($this->endpoint);
        if (! is_array($uri)
            || ! isset($uri['scheme'], $uri['host'])
            || ! is_string($uri['scheme'])
            || ! is_string($uri['host'])
            || $uri['host'] === ''
            || isset($uri['user'])
            || isset($uri['pass'])
            || isset($uri['query'])
            || isset($uri['fragment'])
            || (isset($uri['port']) && ($uri['port'] < 1 || $uri['port'] > 65_535))
        ) {
            throw new \InvalidArgumentException('Backing S3 endpoint must be a valid HTTP(S) URI with a valid host.');
        }

        $scheme = strtolower($uri['scheme']);
        if (! in_array($scheme, ['http', 'https'], true) || ! $this->isValidHost($uri['host'])) {
            throw new \InvalidArgumentException('Backing S3 endpoint must be a valid HTTP(S) URI with a valid host.');
        }

        if ($scheme === 'http' && ! $this->allowInsecureHttpForTests) {
            throw new \InvalidArgumentException('HTTP backing S3 endpoints require the unsafe test-only allowInsecureHttpForTests opt-in.');
        }
    }

    private function isValidHost(string $host): bool
    {
        $ipHost = $host;
        if (str_starts_with($host, '[') && str_ends_with($host, ']')) {
            $ipHost = substr($host, 1, -1);
        }

        return filter_var($ipHost, FILTER_VALIDATE_IP) !== false
            || filter_var($host, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;
    }
}
