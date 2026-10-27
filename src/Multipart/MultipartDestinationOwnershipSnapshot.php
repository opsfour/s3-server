<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Multipart;

use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Acl\ObjectWriteOwnershipDecision;
use OpsFour\S3Server\Exception\InternalErrorException;

/** The immutable final-object decision captured when a multipart upload starts. */
final readonly class MultipartDestinationOwnershipSnapshot
{
    public const string METADATA_KEY = '__mpu-destination-ownership';

    private const int VERSION = 1;

    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $aclGrants
     */
    private function __construct(
        public string $ownerId,
        public array $aclGrants,
        public string $quotaPrincipalId,
        public string $ownershipMode,
        public ?string $cannedAcl,
    ) {}

    public static function fromDecision(ObjectWriteOwnershipDecision $decision, ?string $cannedAcl): self
    {
        return new self(
            $decision->ownerId,
            $decision->aclGrants,
            $decision->quotaPrincipalId,
            $decision->ownershipMode,
            $cannedAcl,
        );
    }

    public function encode(): string
    {
        return json_encode([
            'version' => self::VERSION,
            'ownerId' => $this->ownerId,
            'aclGrants' => $this->aclGrants,
            'quotaPrincipalId' => $this->quotaPrincipalId,
            'ownershipMode' => $this->ownershipMode,
            'cannedAcl' => $this->cannedAcl,
        ], JSON_THROW_ON_ERROR);
    }

    /**
     * A missing marker denotes a legacy upload. A present but invalid marker is
     * corruption and must never silently fall back to current bucket controls.
     *
     * @param array<string, string> $metadata
     */
    public static function decode(array $metadata): ?self
    {
        if (! array_key_exists(self::METADATA_KEY, $metadata)) {
            return null;
        }

        try {
            $record = json_decode($metadata[self::METADATA_KEY], true, 16, JSON_THROW_ON_ERROR);
            if (! is_array($record)
                || ! self::hasExactKeys($record, ['version', 'ownerId', 'aclGrants', 'quotaPrincipalId', 'ownershipMode', 'cannedAcl'])
                || $record['version'] !== self::VERSION
                || ! is_string($record['ownerId'])
                || $record['ownerId'] === ''
                || ! is_string($record['quotaPrincipalId'])
                || $record['quotaPrincipalId'] === ''
                || ! is_string($record['ownershipMode'])
                || ! (is_string($record['cannedAcl']) || $record['cannedAcl'] === null)
                || $record['cannedAcl'] === '') {
                throw new \UnexpectedValueException('Invalid multipart destination ownership record.');
            }
            $storedCannedAcl = $metadata['__mpu-acl-header'] ?? null;
            if (($storedCannedAcl !== null && ! is_string($storedCannedAcl))
                || $record['cannedAcl'] !== $storedCannedAcl
                || ($record['cannedAcl'] !== null && ! in_array($record['cannedAcl'], [
                    'private',
                    'public-read',
                    'public-read-write',
                    'authenticated-read',
                    'aws-exec-read',
                    'bucket-owner-read',
                    'bucket-owner-full-control',
                ], true))) {
                throw new \UnexpectedValueException('Invalid multipart canned ACL snapshot.');
            }

            $aclGrants = AclGrantResolver::validateGrants(self::decodeAclGrants($record['aclGrants']));
            $ownershipMode = BucketOwnership::validateMode($record['ownershipMode']);
            $encodedInitiationAcl = $metadata['__mpu-acl-grants'] ?? null;
            if (! is_string($encodedInitiationAcl)) {
                throw new \UnexpectedValueException('Multipart initiation ACL snapshot is missing.');
            }
            $initiationAcl = json_decode($encodedInitiationAcl, true, 16, JSON_THROW_ON_ERROR);
            $initiationAclGrants = AclGrantResolver::validateGrants(self::decodeAclGrants($initiationAcl));
            $bucketOwned = $ownershipMode === BucketOwnership::BUCKET_OWNER_ENFORCED
                || ($ownershipMode === BucketOwnership::BUCKET_OWNER_PREFERRED
                    && $record['cannedAcl'] === 'bucket-owner-full-control');
            if (! $bucketOwned && $aclGrants !== $initiationAclGrants) {
                throw new \UnexpectedValueException('Multipart destination ACL differs from its initiation snapshot.');
            }

            return new self(
                $record['ownerId'],
                $aclGrants,
                $record['quotaPrincipalId'],
                $ownershipMode,
                $record['cannedAcl'],
            );
        } catch (\Throwable $error) {
            throw new InternalErrorException('Multipart destination ownership snapshot is invalid.', $error);
        }
    }

    public function assertConsistent(string $initiatorId, string $bucketOwnerId): void
    {
        try {
            $bucketOwned = $this->ownershipMode === BucketOwnership::BUCKET_OWNER_ENFORCED
                || ($this->ownershipMode === BucketOwnership::BUCKET_OWNER_PREFERRED
                    && $this->cannedAcl === 'bucket-owner-full-control');

            if ($this->ownershipMode === BucketOwnership::BUCKET_OWNER_ENFORCED
                && $this->cannedAcl !== null
                && $this->cannedAcl !== 'bucket-owner-full-control') {
                throw new \UnexpectedValueException('Enforced multipart snapshot has an invalid canned ACL.');
            }

            $expectedPrincipal = $bucketOwned ? $bucketOwnerId : $initiatorId;
            if ($this->ownerId !== $expectedPrincipal || $this->quotaPrincipalId !== $expectedPrincipal) {
                throw new \UnexpectedValueException('Multipart destination principals are inconsistent.');
            }

            if ($bucketOwned && $this->aclGrants !== AclGrantResolver::privateAcl($bucketOwnerId)) {
                throw new \UnexpectedValueException('Bucket-owned multipart ACL is not canonical.');
            }
        } catch (\Throwable $error) {
            throw new InternalErrorException('Multipart destination ownership snapshot is inconsistent.', $error);
        }
    }

    /** @return list<array{granteeType: string, granteeId: string, permission: string}> */
    private static function decodeAclGrants(mixed $value): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException('Invalid multipart destination ACL.');
        }

        $grants = [];
        foreach ($value as $grant) {
            if (! is_array($grant)
                || ! self::hasExactKeys($grant, ['granteeType', 'granteeId', 'permission'])
                || ! is_string($grant['granteeType'])
                || ! is_string($grant['granteeId'])
                || ! is_string($grant['permission'])) {
                throw new \UnexpectedValueException('Invalid multipart destination ACL.');
            }
            $grants[] = [
                'granteeType' => $grant['granteeType'],
                'granteeId' => $grant['granteeId'],
                'permission' => $grant['permission'],
            ];
        }

        return $grants;
    }

    /**
     * @param array<array-key, mixed> $value
     * @param list<string>            $expected
     */
    private static function hasExactKeys(array $value, array $expected): bool
    {
        return count($value) === count($expected)
            && array_diff(array_keys($value), $expected) === [];
    }
}
