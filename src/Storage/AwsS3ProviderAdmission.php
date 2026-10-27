<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

/** Closed, secret-free provider admission result suitable for diagnostics. */
final readonly class AwsS3ProviderAdmission
{
    public const string PROVIDER_TYPE = 'aws-s3';
    public const string VERSIONING_NOT_CHECKED = 'not-checked';
    public const string VERSIONING_DISABLED = 'disabled';
    public const string VERSIONING_ENABLED = 'enabled';
    public const string VERSIONING_SUSPENDED = 'suspended';
    public const string VERSIONING_UNKNOWN = 'unknown';
    public const string REASON_ADMITTED = 'admitted';
    public const string REASON_CONFIGURATION_INVALID = 'configuration-invalid';
    public const string REASON_SDK_UNAVAILABLE = 'sdk-unavailable';
    public const string REASON_CLIENT_UNAVAILABLE = 'client-unavailable';
    public const string REASON_OWNER_OR_ACCESS_CHECK_FAILED = 'owner-or-access-check-failed';
    public const string REASON_REGION_MISMATCH = 'region-mismatch';
    public const string REASON_BUCKET_TYPE_UNSUPPORTED = 'bucket-type-unsupported';
    public const string REASON_VERSIONING_CHECK_FAILED = 'versioning-check-failed';
    public const string REASON_VERSIONING_ENABLED = 'versioning-enabled';
    public const string REASON_VERSIONING_SUSPENDED = 'versioning-suspended';
    public const string REASON_VERSIONING_UNKNOWN = 'versioning-unknown';

    /** @var array<string, array{configurationValid: bool, ownerCheckPassed: bool|null, versioningState: string}> */
    private const array DENIAL_CONTRACTS = [
        self::REASON_CONFIGURATION_INVALID => [
            'configurationValid' => false,
            'ownerCheckPassed' => false,
            'versioningState' => self::VERSIONING_NOT_CHECKED,
        ],
        self::REASON_SDK_UNAVAILABLE => [
            'configurationValid' => true,
            'ownerCheckPassed' => false,
            'versioningState' => self::VERSIONING_NOT_CHECKED,
        ],
        self::REASON_CLIENT_UNAVAILABLE => [
            'configurationValid' => true,
            'ownerCheckPassed' => false,
            'versioningState' => self::VERSIONING_NOT_CHECKED,
        ],
        self::REASON_OWNER_OR_ACCESS_CHECK_FAILED => [
            'configurationValid' => true,
            'ownerCheckPassed' => false,
            'versioningState' => self::VERSIONING_NOT_CHECKED,
        ],
        self::REASON_REGION_MISMATCH => [
            'configurationValid' => true,
            'ownerCheckPassed' => null,
            'versioningState' => self::VERSIONING_NOT_CHECKED,
        ],
        self::REASON_BUCKET_TYPE_UNSUPPORTED => [
            'configurationValid' => true,
            'ownerCheckPassed' => true,
            'versioningState' => self::VERSIONING_NOT_CHECKED,
        ],
        self::REASON_VERSIONING_CHECK_FAILED => [
            'configurationValid' => true,
            'ownerCheckPassed' => true,
            'versioningState' => self::VERSIONING_UNKNOWN,
        ],
        self::REASON_VERSIONING_ENABLED => [
            'configurationValid' => true,
            'ownerCheckPassed' => true,
            'versioningState' => self::VERSIONING_ENABLED,
        ],
        self::REASON_VERSIONING_SUSPENDED => [
            'configurationValid' => true,
            'ownerCheckPassed' => true,
            'versioningState' => self::VERSIONING_SUSPENDED,
        ],
        self::REASON_VERSIONING_UNKNOWN => [
            'configurationValid' => true,
            'ownerCheckPassed' => true,
            'versioningState' => self::VERSIONING_UNKNOWN,
        ],
    ];

    private function __construct(
        public string $configuredRegion,
        public bool $configurationValid,
        public bool $ownerCheckPassed,
        public string $versioningState,
        public bool $providerAdmitted,
        public string $reason,
        public string $providerType = self::PROVIDER_TYPE,
        public bool $fenceComplete = false,
        public bool $productionQualified = false,
    ) {}

    public static function denied(
        string $configuredRegion,
        bool $configurationValid,
        bool $ownerCheckPassed,
        string $versioningState,
        string $reason,
    ): self {
        $contract = self::DENIAL_CONTRACTS[$reason] ?? null;
        $regionValid = $configuredRegion === 'invalid'
            || AwsS3ProviderProfile::isKnownCommercialRegion($configuredRegion);
        if ($contract === null || ! $regionValid
            || $contract['configurationValid'] !== $configurationValid
            || ($contract['ownerCheckPassed'] !== null && $contract['ownerCheckPassed'] !== $ownerCheckPassed)
            || $contract['versioningState'] !== $versioningState
            || ($configuredRegion === 'invalid' && $configurationValid)) {
            throw new \InvalidArgumentException('Invalid closed AWS S3 provider admission denial.');
        }

        return new self(
            configuredRegion: $configuredRegion,
            configurationValid: $configurationValid,
            ownerCheckPassed: $ownerCheckPassed,
            versioningState: $versioningState,
            providerAdmitted: false,
            reason: $reason,
        );
    }

    public static function admitted(string $configuredRegion): self
    {
        if (! AwsS3ProviderProfile::isKnownCommercialRegion($configuredRegion)) {
            throw new \InvalidArgumentException('Invalid closed AWS S3 provider admission success.');
        }

        return new self(
            configuredRegion: $configuredRegion,
            configurationValid: true,
            ownerCheckPassed: true,
            versioningState: self::VERSIONING_DISABLED,
            providerAdmitted: true,
            reason: self::REASON_ADMITTED,
        );
    }

    public function providerType(): string
    {
        return $this->providerType;
    }

    /** Writer and qualification state deliberately remain closed in Story 4.1. */
    public function fenceComplete(): bool
    {
        return $this->fenceComplete;
    }

    public function productionQualified(): bool
    {
        return $this->productionQualified;
    }

    /**
     * @return array{provider_type: string, configured_region: string, configuration_valid: bool, owner_check_passed: bool, versioning_state: string, provider_admitted: bool, fence_complete: bool, production_qualified: bool, reason: string}
     */
    public function toArray(): array
    {
        return [
            'provider_type' => $this->providerType,
            'configured_region' => $this->configuredRegion,
            'configuration_valid' => $this->configurationValid,
            'owner_check_passed' => $this->ownerCheckPassed,
            'versioning_state' => $this->versioningState,
            'provider_admitted' => $this->providerAdmitted,
            'fence_complete' => $this->fenceComplete,
            'production_qualified' => $this->productionQualified,
            'reason' => $this->reason,
        ];
    }
}
