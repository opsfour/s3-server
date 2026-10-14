<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Quota;

use OpsFour\S3Server\Metadata\MetadataStore;

/** Serializes quota changes with writes for the same owner. */
final class AccountQuotaWriter
{
    public static function put(MetadataStore $metadata, string $ownerId, QuotaConfig $quota): void
    {
        OwnerIdValidator::assertValid($ownerId);

        $metadata->transaction(static function () use ($metadata, $ownerId, $quota): void {
            $metadata->lockOwnerForUpdate($ownerId);
            $metadata->putAccountQuota($ownerId, $quota);
        });
    }

    public static function delete(MetadataStore $metadata, string $ownerId): void
    {
        OwnerIdValidator::assertValid($ownerId);

        $metadata->transaction(static function () use ($metadata, $ownerId): void {
            $metadata->lockOwnerForUpdate($ownerId);
            $metadata->deleteAccountQuota($ownerId);
        });
    }
}
