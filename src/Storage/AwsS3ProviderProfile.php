<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Immutable, provider-specific configuration for the supported AWS S3 profile. */
final readonly class AwsS3ProviderProfile
{
    public const int MAX_RESERVED_PREFIX_BYTES = 700;

    private const int MAX_DATA_PREFIX_BYTES = 900;

    private const array COMMERCIAL_REGIONS = [
        'af-south-1',
        'ap-east-1',
        'ap-east-2',
        'ap-northeast-1',
        'ap-northeast-2',
        'ap-northeast-3',
        'ap-south-1',
        'ap-south-2',
        'ap-southeast-1',
        'ap-southeast-2',
        'ap-southeast-3',
        'ap-southeast-4',
        'ap-southeast-5',
        'ap-southeast-6',
        'ap-southeast-7',
        'ca-central-1',
        'ca-west-1',
        'eu-central-1',
        'eu-central-2',
        'eu-north-1',
        'eu-south-1',
        'eu-south-2',
        'eu-west-1',
        'eu-west-2',
        'eu-west-3',
        'il-central-1',
        'me-central-1',
        'me-south-1',
        'mx-central-1',
        'sa-east-1',
        'us-east-1',
        'us-east-2',
        'us-west-1',
        'us-west-2',
    ];

    public function __construct(
        public string $bucket,
        public string $region,
        public string $expectedOwnerId,
        public string $dataPrefix,
        public string $reservedPrefix,
        public int $connectTimeoutSeconds = 5,
        public int $readTimeoutSeconds = 15,
        public int $requestTimeoutSeconds = 20,
    ) {
        self::assertBucket($bucket);
        if (! self::isKnownCommercialRegion($region)) {
            throw new \InvalidArgumentException('AWS S3 provider region is not a known commercial AWS region.');
        }
        if (preg_match('/^[0-9]{12}$/D', $expectedOwnerId) !== 1) {
            throw new \InvalidArgumentException('AWS S3 provider expected owner must be a 12-digit account ID.');
        }

        self::assertCanonicalPrefix($dataPrefix, 'data', self::MAX_DATA_PREFIX_BYTES);
        self::assertCanonicalPrefix($reservedPrefix, 'reserved', self::MAX_RESERVED_PREFIX_BYTES);
        if (self::prefixContains($dataPrefix, $reservedPrefix)
            || self::prefixContains($reservedPrefix, $dataPrefix)) {
            throw new \InvalidArgumentException('AWS S3 provider data and reserved prefixes must not overlap.');
        }

        if ($connectTimeoutSeconds < 1 || $connectTimeoutSeconds > 30
            || $readTimeoutSeconds < 1 || $readTimeoutSeconds > 120
            || $requestTimeoutSeconds < 1 || $requestTimeoutSeconds > 120
            || $connectTimeoutSeconds > $requestTimeoutSeconds
            || $readTimeoutSeconds > $requestTimeoutSeconds) {
            throw new \InvalidArgumentException('AWS S3 provider timeouts are outside the supported bounds.');
        }
    }

    /** @param array<string, mixed> $config */
    public static function fromConfig(array $config): self
    {
        foreach ([
            'endpoint',
            'endpoint_url',
            'credentials',
            'credential_provider',
            'access_key',
            'access_key_id',
            'secret_key',
            'secret_access_key',
            'session_token',
            'path_style',
            'use_path_style_endpoint',
            'retries',
            'retry_mode',
            'max_attempts',
            'scheme',
            'use_accelerate_endpoint',
            'use_dual_stack_endpoint',
            'use_arn_region',
            'bucket_endpoint',
            'disable_multiregion_access_points',
            'disable_express_session_auth',
            's3_express_identity_provider',
            's3_us_east_1_regional_endpoint',
            'ignore_configured_endpoint_urls',
            'use_fips_endpoint',
            'endpoint_discovery',
            'disable_host_prefix_injection',
        ] as $forbiddenKey) {
            if (array_key_exists($forbiddenKey, $config)) {
                throw new \InvalidArgumentException('AWS S3 provider configuration contains a forbidden override.');
            }
        }

        return new self(
            bucket: self::requiredString($config, 'bucket'),
            region: self::requiredString($config, 'region'),
            expectedOwnerId: self::requiredString($config, 'expected_owner_id'),
            dataPrefix: self::requiredString($config, 'data_prefix'),
            reservedPrefix: self::requiredString($config, 'reserved_prefix'),
            connectTimeoutSeconds: self::integer($config, 'connect_timeout_seconds', 5),
            readTimeoutSeconds: self::integer($config, 'read_timeout_seconds', 15),
            requestTimeoutSeconds: self::integer($config, 'request_timeout_seconds', 20),
        );
    }

    /** @param array<string, mixed> $config */
    private static function requiredString(array $config, string $key): string
    {
        $value = $config[$key] ?? null;
        if (! is_string($value) || $value === '' || trim($value) !== $value) {
            throw new \InvalidArgumentException("AWS S3 provider {$key} must be a non-empty canonical string.");
        }

        return $value;
    }

    /** @param array<string, mixed> $config */
    private static function integer(array $config, string $key, int $default): int
    {
        $value = $config[$key] ?? $default;
        if (! is_int($value) && ! is_string($value)) {
            throw new \InvalidArgumentException("AWS S3 provider {$key} must be a decimal integer.");
        }
        $validated = filter_var($value, FILTER_VALIDATE_INT);
        if ($validated === false) {
            throw new \InvalidArgumentException("AWS S3 provider {$key} must be a decimal integer.");
        }

        return $validated;
    }

    private static function assertBucket(string $bucket): void
    {
        $reservedPrefix = preg_match('/^(?:xn--|sthree-|amzn-s3-demo-)/D', $bucket) === 1;
        $reservedSuffix = preg_match('/(?:-s3alias|--ol-s3|\.mrap|--x-s3|--table-s3)$/D', $bucket) === 1;
        if (strlen($bucket) < 3 || strlen($bucket) > 63
            || preg_match('/^[a-z0-9][a-z0-9.-]*[a-z0-9]$/D', $bucket) !== 1
            || str_contains($bucket, '.')
            || str_contains($bucket, '..')
            || filter_var($bucket, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false
            || $reservedPrefix || $reservedSuffix) {
            throw new \InvalidArgumentException('AWS S3 provider bucket must be a plain General Purpose Bucket name.');
        }
    }

    public static function assertCanonicalPrefix(string $prefix, string $kind, int $maxBytes): void
    {
        if ($prefix === '' || strlen($prefix) > $maxBytes
            || preg_match('//u', $prefix) !== 1
            || $prefix[0] === '/' || str_ends_with($prefix, '/')
            || preg_match('/[\x00-\x1F\x7F]/', $prefix) === 1
            || preg_match('#(?:^|/)(?:\.|\.\.)(?:/|$)#', $prefix) === 1
            || str_contains($prefix, '//')) {
            throw new \InvalidArgumentException("AWS S3 provider {$kind} prefix must be a canonical non-empty key prefix.");
        }
    }

    public static function prefixContains(string $parent, string $child): bool
    {
        return $parent === $child || str_starts_with($child, $parent . '/');
    }

    public static function isKnownCommercialRegion(string $region): bool
    {
        return in_array($region, self::COMMERCIAL_REGIONS, true);
    }
}
