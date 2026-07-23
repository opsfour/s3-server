<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Acl;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidArgumentException;

final class AclGrantResolver
{
    public const string ALL_USERS_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    public const string AUTHENTICATED_USERS_URI = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    public const string LOG_DELIVERY_URI = 'http://acs.amazonaws.com/groups/s3/LogDelivery';

    /**
     * This implementation principal lets an external identity provider map the
     * legacy AWS EC2 canned ACL grant without pretending it is a normal user.
     */
    public const string AWS_EXEC_PRINCIPAL = 'aws:ec2';

    /** @var array<string, string> */
    private const array GRANT_HEADERS = [
        'x-amz-grant-read' => 'READ',
        'x-amz-grant-write' => 'WRITE',
        'x-amz-grant-read-acp' => 'READ_ACP',
        'x-amz-grant-write-acp' => 'WRITE_ACP',
        'x-amz-grant-full-control' => 'FULL_CONTROL',
    ];

    /** @var list<string> */
    private const array PERMISSIONS = ['READ', 'WRITE', 'READ_ACP', 'WRITE_ACP', 'FULL_CONTROL'];

    /** @var list<string> */
    private const array GROUP_URIS = [
        self::ALL_USERS_URI,
        self::AUTHENTICATED_USERS_URI,
        self::LOG_DELIVERY_URI,
    ];

    /**
     * Resolve ACL request headers. Returns null when the request contains no ACL headers.
     *
     * @return list<array{granteeType: string, granteeId: string, permission: string}>|null
     */
    public static function fromHeaders(
        Request $request,
        string $ownerId,
        string $resourceType,
        ?string $bucketOwnerId = null,
    ): ?array {
        $cannedAcl = $request->getHeader('x-amz-acl');
        $hasCannedAcl = $cannedAcl !== null;
        $hasGrantHeaders = self::hasGrantHeaders($request);

        if ($hasCannedAcl && $hasGrantHeaders) {
            throw new InvalidArgumentException(
                'x-amz-acl and x-amz-grant-* headers are mutually exclusive.',
            );
        }

        if ($hasCannedAcl) {
            if ($cannedAcl === '') {
                throw new InvalidArgumentException('x-amz-acl must not be empty.');
            }

            return self::expandCannedAcl($cannedAcl, $ownerId, $resourceType, $bucketOwnerId);
        }

        if ($hasGrantHeaders) {
            return self::parseGrantHeaders($request, $ownerId);
        }

        return null;
    }

    /**
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    public static function privateAcl(string $ownerId): array
    {
        return [[
            'granteeType' => 'CanonicalUser',
            'granteeId' => $ownerId,
            'permission' => 'FULL_CONTROL',
        ]];
    }

    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $grants
     *
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    public static function validateGrants(array $grants): array
    {
        if ($grants === []) {
            throw new InvalidArgumentException('The access control policy must contain at least one grant.');
        }

        foreach ($grants as $grant) {
            if (! in_array($grant['permission'], self::PERMISSIONS, true)) {
                throw new InvalidArgumentException("Unsupported ACL permission: {$grant['permission']}.");
            }

            if ($grant['granteeId'] === '') {
                throw new InvalidArgumentException('Every ACL grantee must have a non-empty identifier.');
            }

            if ($grant['granteeType'] === 'AmazonCustomerByEmail') {
                throw new InvalidArgumentException(
                    'Email-address ACL grantees require an account resolver and are not supported.',
                );
            }

            if ($grant['granteeType'] === 'Group') {
                if (! in_array($grant['granteeId'], self::GROUP_URIS, true)) {
                    throw new InvalidArgumentException("Unsupported S3 ACL group URI: {$grant['granteeId']}.");
                }
            } elseif ($grant['granteeType'] !== 'CanonicalUser') {
                throw new InvalidArgumentException("Unsupported ACL grantee type: {$grant['granteeType']}.");
            }
        }

        return $grants;
    }

    /**
     * @param list<array{granteeType: string, granteeId: string, permission: string}> $grants
     */
    public static function isPublic(array $grants): bool
    {
        foreach ($grants as $grant) {
            if (
                $grant['granteeType'] === 'Group'
                && in_array($grant['granteeId'], [self::ALL_USERS_URI, self::AUTHENTICATED_USERS_URI], true)
            ) {
                return true;
            }
        }

        return false;
    }

    private static function hasGrantHeaders(Request $request): bool
    {
        foreach (array_keys(self::GRANT_HEADERS) as $header) {
            if ($request->hasHeader($header)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private static function expandCannedAcl(
        string $cannedAcl,
        string $ownerId,
        string $resourceType,
        ?string $bucketOwnerId,
    ): array {
        if (! in_array($resourceType, ['bucket', 'object'], true)) {
            throw new \LogicException("Unsupported ACL resource type: {$resourceType}.");
        }

        $ownerGrant = self::privateAcl($ownerId)[0];
        $publicRead = ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'READ'];

        if ($resourceType === 'bucket') {
            return match ($cannedAcl) {
                'private', 'bucket-owner-read', 'bucket-owner-full-control' => [$ownerGrant],
                'public-read' => [$ownerGrant, $publicRead],
                'public-read-write' => [
                    $ownerGrant,
                    $publicRead,
                    ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'WRITE'],
                ],
                'authenticated-read' => [
                    $ownerGrant,
                    ['granteeType' => 'Group', 'granteeId' => self::AUTHENTICATED_USERS_URI, 'permission' => 'READ'],
                ],
                'aws-exec-read' => [
                    $ownerGrant,
                    ['granteeType' => 'CanonicalUser', 'granteeId' => self::AWS_EXEC_PRINCIPAL, 'permission' => 'READ'],
                ],
                'log-delivery-write' => [
                    $ownerGrant,
                    ['granteeType' => 'Group', 'granteeId' => self::LOG_DELIVERY_URI, 'permission' => 'WRITE'],
                    ['granteeType' => 'Group', 'granteeId' => self::LOG_DELIVERY_URI, 'permission' => 'READ_ACP'],
                ],
                default => throw new InvalidArgumentException("Unsupported canned ACL: {$cannedAcl}."),
            };
        }

        $bucketOwnerId ??= $ownerId;

        return match ($cannedAcl) {
            'private' => [$ownerGrant],
            'public-read' => [$ownerGrant, $publicRead],
            'public-read-write' => [
                $ownerGrant,
                $publicRead,
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'WRITE'],
            ],
            'authenticated-read' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::AUTHENTICATED_USERS_URI, 'permission' => 'READ'],
            ],
            'aws-exec-read' => [
                $ownerGrant,
                ['granteeType' => 'CanonicalUser', 'granteeId' => self::AWS_EXEC_PRINCIPAL, 'permission' => 'READ'],
            ],
            'bucket-owner-read' => self::appendDistinctOwnerGrant($ownerGrant, $bucketOwnerId, 'READ'),
            'bucket-owner-full-control' => self::appendDistinctOwnerGrant($ownerGrant, $bucketOwnerId, 'FULL_CONTROL'),
            default => throw new InvalidArgumentException("Unsupported canned ACL: {$cannedAcl}."),
        };
    }

    /**
     * @param array{granteeType: string, granteeId: string, permission: string} $ownerGrant
     *
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private static function appendDistinctOwnerGrant(array $ownerGrant, string $bucketOwnerId, string $permission): array
    {
        if ($bucketOwnerId === $ownerGrant['granteeId'] && $permission === $ownerGrant['permission']) {
            return [$ownerGrant];
        }

        return [
            $ownerGrant,
            ['granteeType' => 'CanonicalUser', 'granteeId' => $bucketOwnerId, 'permission' => $permission],
        ];
    }

    /**
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private static function parseGrantHeaders(Request $request, string $ownerId): array
    {
        $grants = self::privateAcl($ownerId);

        foreach (self::GRANT_HEADERS as $header => $permission) {
            $value = $request->getHeader($header);
            if ($value === null) {
                continue;
            }
            if ($value === '') {
                throw new InvalidArgumentException("{$header} must not be empty.");
            }

            foreach (explode(',', $value) as $specification) {
                $specification = trim($specification);

                if (preg_match('/^id\s*=\s*"([^"]+)"$/i', $specification, $matches) === 1) {
                    $grants[] = [
                        'granteeType' => 'CanonicalUser',
                        'granteeId' => $matches[1],
                        'permission' => $permission,
                    ];
                    continue;
                }

                if (preg_match('/^uri\s*=\s*"([^"]+)"$/i', $specification, $matches) === 1) {
                    if (! in_array($matches[1], self::GROUP_URIS, true)) {
                        throw new InvalidArgumentException("Unsupported S3 ACL group URI: {$matches[1]}.");
                    }
                    $grants[] = [
                        'granteeType' => 'Group',
                        'granteeId' => $matches[1],
                        'permission' => $permission,
                    ];
                    continue;
                }

                if (preg_match('/^emailAddress\s*=\s*"([^"]+)"$/i', $specification) === 1) {
                    throw new InvalidArgumentException(
                        'Email-address ACL grantees require an account resolver and are not supported.',
                    );
                }

                throw new InvalidArgumentException("Invalid grantee in {$header}: {$specification}.");
            }
        }

        return $grants;
    }
}
