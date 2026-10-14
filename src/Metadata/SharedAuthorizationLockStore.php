<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

interface SharedAuthorizationLockStore
{
    public function prepareOwnerReadLock(string $ownerId): void;

    public function lockOwnerForRead(string $ownerId): void;

    /**
     * @param  list<string>  $policyNames
     * @return array<string, string>
     */
    public function lockNamedPoliciesForRead(array $policyNames): array;
}
