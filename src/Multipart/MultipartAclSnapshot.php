<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Multipart;

use OpsFour\S3Server\Acl\AclGrantResolver;
use OpsFour\S3Server\Exception\InternalErrorException;

/** Decode ACL grants owned by persisted multipart-upload metadata. */
final class MultipartAclSnapshot
{
    public const string METADATA_KEY = '__mpu-acl-grants';

    private const int MAX_ENCODED_BYTES = 200_000;

    /**
     * A missing ACL key is valid only for uploads that predate all exact
     * multipart snapshots.
     *
     * @param array<string, string> $metadata
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    public static function fromMetadata(array $metadata, string $legacyOwnerId): array
    {
        if (array_key_exists(self::METADATA_KEY, $metadata)) {
            return self::decode($metadata[self::METADATA_KEY], $legacyOwnerId);
        }

        if (array_key_exists('__mpu-policy-context-version', $metadata)
            || array_key_exists(MultipartDestinationOwnershipSnapshot::METADATA_KEY, $metadata)) {
            throw new InternalErrorException('Multipart upload ACL snapshot is missing.');
        }

        return AclGrantResolver::privateAcl($legacyOwnerId);
    }

    /** @return list<array{granteeType: string, granteeId: string, permission: string}> */
    public static function decode(string $encoded, ?string $expectedOwnerId = null): array
    {
        try {
            if (strlen($encoded) > self::MAX_ENCODED_BYTES) {
                throw new \UnexpectedValueException('Multipart ACL snapshot exceeds its encoded size limit.');
            }

            return self::validateDecoded(
                json_decode($encoded, false, 16, JSON_THROW_ON_ERROR),
                $expectedOwnerId,
            );
        } catch (\Throwable $error) {
            throw new InternalErrorException('Multipart upload ACL snapshot is invalid.', $error);
        }
    }

    /** @return list<array{granteeType: string, granteeId: string, permission: string}> */
    public static function fromDecoded(mixed $value, ?string $expectedOwnerId = null): array
    {
        try {
            return self::validateDecoded($value, $expectedOwnerId);
        } catch (\Throwable $error) {
            throw new InternalErrorException('Multipart upload ACL snapshot is invalid.', $error);
        }
    }

    /** @return list<array{granteeType: string, granteeId: string, permission: string}> */
    private static function validateDecoded(mixed $value, ?string $expectedOwnerId): array
    {
        if (! is_array($value) || ! array_is_list($value)) {
            throw new \UnexpectedValueException('Multipart ACL grants must be a list.');
        }

        $grants = [];
        foreach ($value as $grant) {
            if ($grant instanceof \stdClass) {
                $grant = get_object_vars($grant);
            }
            if (! is_array($grant)
                || count($grant) !== 3
                || array_diff(array_keys($grant), ['granteeType', 'granteeId', 'permission']) !== []
                || ! is_string($grant['granteeType'])
                || ! is_string($grant['granteeId'])
                || ! is_string($grant['permission'])) {
                throw new \UnexpectedValueException('Multipart ACL grant shape is invalid.');
            }

            $grants[] = [
                'granteeType' => $grant['granteeType'],
                'granteeId' => $grant['granteeId'],
                'permission' => $grant['permission'],
            ];
        }

        $grants = AclGrantResolver::validateGrants($grants);
        if ($expectedOwnerId !== null && ! in_array([
            'granteeType' => 'CanonicalUser',
            'granteeId' => $expectedOwnerId,
            'permission' => 'FULL_CONTROL',
        ], $grants, true)) {
            throw new \UnexpectedValueException('Multipart ACL snapshot omits the owner grant.');
        }

        return $grants;
    }
}
