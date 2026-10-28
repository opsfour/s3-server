<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Tests\Support;

use OpsFour\S3Server\Handler\HandlerRegistrar;
use OpsFour\S3Server\Metadata\AwsS3OperationalSummaryReader;
use OpsFour\S3Server\Runtime\StorageMutatorInventory;
use OpsFour\S3Server\S3Server;
use OpsFour\S3Server\Storage\AwsS3ObjectWriteIntentReconciler;
use OpsFour\S3Server\Storage\AwsS3OperationalState;
use OpsFour\S3Server\Storage\AwsS3ProviderAdmission;
use OpsFour\S3Server\Storage\AwsS3ProviderProbe;
use OpsFour\S3Server\Storage\AwsS3ProviderProfile;
use OpsFour\S3Server\Storage\AwsS3ProviderValidatedBinding;
use OpsFour\S3Server\Storage\AwsS3StorageBackend;

/** Test-only exact-binding operational state with no provider or database I/O. */
final class AwsS3OperationalStateFixture
{
    public static function attach(
        AwsS3StorageBackend $backend,
        AwsS3ProviderValidatedBinding $binding,
        string $storageTier,
        ?AwsS3OperationalSummaryReader $metadata = null,
    ): AwsS3OperationalState {
        $metadata ??= new FixtureAwsS3OperationalSummary();
        $state = new AwsS3OperationalState(
            $binding,
            new FixtureAwsS3ProviderProbe($binding->admission),
            StorageMutatorInventory::forEnabledRuntime([
                ...HandlerRegistrar::storageMutatorRegistrations(),
                ...S3Server::storageMutatorRegistrations(),
                ...AwsS3ObjectWriteIntentReconciler::storageMutatorRegistrations(),
            ]),
            $metadata,
            $storageTier,
        );
        $backend->attachOperationalState($state, $storageTier, $metadata);
        $backend->commitOperationalStateAttachment($state, $storageTier, $metadata);

        return $state;
    }
}

final readonly class FixtureAwsS3ProviderProbe implements AwsS3ProviderProbe
{
    public function __construct(private AwsS3ProviderAdmission $admission) {}

    public function revalidate(AwsS3ProviderProfile $profile): AwsS3ProviderAdmission
    {
        if ($profile->region !== $this->admission->configuredRegion) {
            throw new \LogicException('Fixture AWS profile changed.');
        }

        return $this->admission;
    }
}

final class FixtureAwsS3OperationalSummary implements AwsS3OperationalSummaryReader
{
    public function getAwsS3OperationalSummary(string $storageTier): array
    {
        if ($storageTier === '') {
            throw new \LogicException('Fixture AWS storage tier is empty.');
        }

        return [
            'open' => 0,
            'ambiguous' => 0,
            'seal_reserved' => 0,
            'sealed' => 0,
            'multipart' => 0,
            'part' => 0,
            'cleanup' => 0,
            'failures' => 0,
            'oldest_age_seconds' => 0.0,
            'max_owner_open' => 0,
            'max_owner_sealed' => 0,
            'max_owner_multipart' => 0,
            'max_owner_part' => 0,
            'global_open' => 0,
            'global_seal_reserved' => 0,
            'global_multipart' => 0,
            'global_part' => 0,
            'global_cleanup' => 0,
        ];
    }
}
