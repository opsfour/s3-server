<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use Aws\Result;
use Aws\Exception\AwsException;
use Aws\S3\S3Client;

/** Performs only read-only probes against an immutable AWS S3 profile. */
final class AwsS3ProviderValidator implements AwsS3ProviderProbe
{
    private readonly ?\Closure $httpHandler;

    public function __construct(?callable $httpHandler = null)
    {
        $this->httpHandler = $httpHandler === null ? null : \Closure::fromCallable($httpHandler);
    }

    /**
     * @param AwsS3ProviderProfile|array<string, mixed> $profile
     * @param (callable(AwsS3ProviderProfile): S3Client)|null $clientFactory
     */
    public function validate(AwsS3ProviderProfile|array $profile, ?callable $clientFactory = null): AwsS3ProviderAdmission
    {
        if (is_array($profile)) {
            $configuredRegion = $profile['region'] ?? null;
            $region = is_string($configuredRegion) && AwsS3ProviderProfile::isKnownCommercialRegion($configuredRegion)
                ? $configuredRegion : 'invalid';
            try {
                $profile = AwsS3ProviderProfile::fromConfig($profile);
            } catch (\InvalidArgumentException) {
                return AwsS3ProviderAdmission::denied(
                    $region,
                    false,
                    false,
                    AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                    AwsS3ProviderAdmission::REASON_CONFIGURATION_INVALID,
                );
            }
        }

        if (! class_exists(S3Client::class)) {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_SDK_UNAVAILABLE,
            );
        }

        try {
            $client = $clientFactory === null ? $this->createClient($profile) : $clientFactory($profile);
            if (! $client instanceof S3Client) {
                throw new \LogicException('AWS S3 provider client factory returned an invalid client.');
            }
        } catch (\Throwable) {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_CLIENT_UNAVAILABLE,
            );
        }

        try {
            /** @var Result<mixed> $head */
            $head = $client->headBucket([
                'Bucket' => $profile->bucket,
                'ExpectedBucketOwner' => $profile->expectedOwnerId,
            ]);
        } catch (AwsException $error) {
            $actualRegion = self::exceptionBucketRegion($error);
            if ($actualRegion !== null && ! hash_equals($profile->region, $actualRegion)) {
                return AwsS3ProviderAdmission::denied(
                    $profile->region,
                    true,
                    false,
                    AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                    AwsS3ProviderAdmission::REASON_REGION_MISMATCH,
                );
            }

            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED,
            );
        } catch (\Throwable) {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                false,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_OWNER_OR_ACCESS_CHECK_FAILED,
            );
        }

        $actualRegion = $head->get('BucketRegion');
        if (! is_string($actualRegion) || ! hash_equals($profile->region, $actualRegion)) {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                true,
                AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                AwsS3ProviderAdmission::REASON_REGION_MISMATCH,
            );
        }

        foreach (['BucketLocationType', 'BucketLocationName', 'BucketArn', 'AccessPointAlias'] as $unsupportedField) {
            if ($head->hasKey($unsupportedField)) {
                return AwsS3ProviderAdmission::denied(
                    $profile->region,
                    true,
                    true,
                    AwsS3ProviderAdmission::VERSIONING_NOT_CHECKED,
                    AwsS3ProviderAdmission::REASON_BUCKET_TYPE_UNSUPPORTED,
                );
            }
        }

        try {
            /** @var Result<mixed> $versioning */
            $versioning = $client->getBucketVersioning([
                'Bucket' => $profile->bucket,
                'ExpectedBucketOwner' => $profile->expectedOwnerId,
            ]);
        } catch (\Throwable) {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                true,
                AwsS3ProviderAdmission::VERSIONING_UNKNOWN,
                AwsS3ProviderAdmission::REASON_VERSIONING_CHECK_FAILED,
            );
        }

        $status = $versioning->get('Status');
        if ($status === null || $status === '') {
            return AwsS3ProviderAdmission::admitted($profile->region);
        }
        if ($status === 'Enabled') {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                true,
                AwsS3ProviderAdmission::VERSIONING_ENABLED,
                AwsS3ProviderAdmission::REASON_VERSIONING_ENABLED,
            );
        }
        if ($status === 'Suspended') {
            return AwsS3ProviderAdmission::denied(
                $profile->region,
                true,
                true,
                AwsS3ProviderAdmission::VERSIONING_SUSPENDED,
                AwsS3ProviderAdmission::REASON_VERSIONING_SUSPENDED,
            );
        }

        return AwsS3ProviderAdmission::denied(
            $profile->region,
            true,
            true,
            AwsS3ProviderAdmission::VERSIONING_UNKNOWN,
            AwsS3ProviderAdmission::REASON_VERSIONING_UNKNOWN,
        );
    }

    public function createClient(AwsS3ProviderProfile $profile): S3Client
    {
        if (! class_exists(S3Client::class)) {
            throw new \RuntimeException('The aws-s3 storage profile requires aws/aws-sdk-php.');
        }

        return new S3Client($this->clientConfiguration($profile));
    }

    public function revalidate(AwsS3ProviderProfile $profile): AwsS3ProviderAdmission
    {
        return $this->validate($profile);
    }

    /** @return array<string, mixed> */
    public function clientConfiguration(AwsS3ProviderProfile $profile): array
    {
        $configuration = [
            'version' => 'latest',
            'region' => $profile->region,
            'retries' => 0,
            'scheme' => 'https',
            'ignore_configured_endpoint_urls' => true,
            'bucket_endpoint' => false,
            'use_path_style_endpoint' => false,
            'use_arn_region' => false,
            'use_accelerate_endpoint' => false,
            'disable_multiregion_access_points' => true,
            'use_fips_endpoint' => false,
            'use_dual_stack_endpoint' => false,
            'endpoint_discovery' => ['enabled' => false],
            'disable_express_session_auth' => true,
            's3_express_identity_provider' => false,
            's3_us_east_1_regional_endpoint' => 'regional',
            'http' => [
                'connect_timeout' => $profile->connectTimeoutSeconds,
                'read_timeout' => $profile->readTimeoutSeconds,
                'timeout' => $profile->requestTimeoutSeconds,
            ],
        ];

        if ($this->httpHandler !== null) {
            $configuration['http_handler'] = $this->httpHandler;
        }

        return $configuration;
    }

    private static function exceptionBucketRegion(AwsException $error): ?string
    {
        $value = $error->getResponse()?->getHeaderLine('x-amz-bucket-region');
        if (! is_string($value) || $value === '') {
            $result = $error->getResult();
            $modeled = $result?->get('BucketRegion');
            if (is_string($modeled) && $modeled !== '') {
                return $modeled;
            }
            $metadata = $result?->get('@metadata');
            $headers = is_array($metadata) ? ($metadata['headers'] ?? null) : null;
            $value = is_array($headers) ? ($headers['x-amz-bucket-region'] ?? null) : null;
        }

        return is_string($value) && $value !== '' ? $value : null;
    }
}
