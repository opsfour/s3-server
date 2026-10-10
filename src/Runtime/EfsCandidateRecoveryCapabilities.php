<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Runtime;

use OpsFour\S3Server\Metadata\FencedMultipartPartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedMultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\FencedStorageGarbageStore;
use OpsFour\S3Server\Metadata\FencedQueueStore;
use OpsFour\S3Server\Metadata\LocalMultipartPartSealGuard;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Metadata\ObjectWriteIntentStore;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Metadata\StorageGarbageLeaseStore;

/** @internal Candidate-only check; it does not authorize recovery or serving. */
final class EfsCandidateRecoveryCapabilities
{
    public static function assertReady(MetadataStore $metadata): MultipartWriteIntentStore
    {
        if (! $metadata instanceof FencedStorageGarbageStore
            || ! $metadata instanceof StorageGarbageLeaseStore
            || ! $metadata instanceof FencedMultipartPartWriteIntentStore
            || ! $metadata instanceof LocalMultipartPartSealGuard
            || ! $metadata instanceof MultipartWriteIntentStore
            || ! $metadata instanceof FencedMultipartWriteIntentStore) {
            throw new \RuntimeException('EFS candidate metadata lacks generation-fenced storage and multipart recovery.');
        }

        return $metadata;
    }

    public static function assertBackgroundReady(MetadataStore $metadata): void
    {
        if (! $metadata instanceof FencedQueueStore
            || ! $metadata instanceof ObjectWriteIntentStore
            || ! $metadata instanceof RestoredCopyTierStore) {
            throw new \RuntimeException('EFS candidate metadata lacks fenced background queue and restored-copy capabilities.');
        }
    }
}
