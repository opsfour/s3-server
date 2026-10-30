<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;

/** One complete, exact AWS tier runtime binding. */
final readonly class AwsS3TierRuntimeBinding
{
    public function __construct(
        public string $tier,
        public AwsS3StorageBackend $backend,
        public ObjectWriteIntentStore $metadata,
        public AwsS3OperationalState $operationalState,
        public AwsS3ForegroundObjectWriter $foregroundWriter,
        public AwsS3FencedBackgroundObjectWriter $backgroundWriter,
        public AwsS3ObjectWriteIntentCollector $objectIntentCollector,
    ) {
        if ($tier === '') {
            throw new \InvalidArgumentException('AWS tier runtime binding requires a tier name.');
        }
        $provider = $backend->validatedBinding();
        $profile = $provider?->profile;
        if ($provider === null || $profile === null
            || $backend->operationalState() !== $operationalState
            || ! $operationalState->isBoundTo($provider, $tier, $metadata)
            || ! $foregroundWriter->isBoundToOperationalGraph(
                $backend,
                $profile,
                $metadata,
                $operationalState,
            )
            || ! $backgroundWriter->isBoundTo($foregroundWriter, $backend, $tier)
            || ! $objectIntentCollector->isBoundTo(
                $metadata,
                $tier,
                $operationalState,
                $provider,
            )) {
            throw new \InvalidArgumentException('AWS tier runtime binding is incomplete or internally inconsistent.');
        }
    }
}
