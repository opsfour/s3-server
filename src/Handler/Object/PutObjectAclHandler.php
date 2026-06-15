<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutObjectAcl (PUT /{bucket}/{key}?acl).
 *
 * Supports both canned ACLs via the x-amz-acl header and
 * explicit ACL XML in the request body.
 */
final class PutObjectAclHandler implements RequestHandler
{
    private const string ALL_USERS_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    private const string AUTHENTICATED_USERS_URI = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        // Verify the object exists.
        if (! $this->metadata->objectExists($bucket, $key)) {
            throw new NoSuchKeyException;
        }

        // Check for canned ACL header first.
        $cannedAcl = $request->getHeader('x-amz-acl');

        if ($cannedAcl !== null) {
            $grants = self::expandCannedAcl($cannedAcl, $ownerId);
        } elseif ($request->hasHeader('x-amz-grant-read') || $request->hasHeader('x-amz-grant-write')
            || $request->hasHeader('x-amz-grant-read-acp') || $request->hasHeader('x-amz-grant-write-acp')
            || $request->hasHeader('x-amz-grant-full-control')) {
            $grants = self::parseGrantHeaders($request, $ownerId);
        } else {
            // Parse XML body.
            $body = $request->getBody()->buffer();
            $parsed = XmlRequestParser::parseAccessControlPolicy($body);
            $grants = $parsed['grants'];
        }

        // Check Public Access Block — reject public ACLs if blockPublicAcls is set.
        $pab = $this->metadata->getPublicAccessBlock($bucket);
        if ($pab !== null && $pab['blockPublicAcls']) {
            foreach ($grants as $grant) {
                if ($grant['granteeType'] === 'Group' && in_array($grant['granteeId'], [self::ALL_USERS_URI, self::AUTHENTICATED_USERS_URI], true)) {
                    throw new AccessDeniedException('The bucket policy does not allow the specified public access.');
                }
            }
        }

        $resourceName = $bucket.'/'.$key;
        $this->metadata->putAcl('object', $resourceName, $ownerId, $grants);

        return new Response(status: 200);
    }

    /**
     * Expand a canned ACL name into a list of grants.
     *
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private static function expandCannedAcl(string $cannedAcl, string $ownerId): array
    {
        $ownerGrant = [
            'granteeType' => 'CanonicalUser',
            'granteeId' => $ownerId,
            'permission' => 'FULL_CONTROL',
        ];

        return match ($cannedAcl) {
            'private' => [$ownerGrant],
            'public-read' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'READ'],
            ],
            'public-read-write' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'READ'],
                ['granteeType' => 'Group', 'granteeId' => self::ALL_USERS_URI, 'permission' => 'WRITE'],
            ],
            'authenticated-read' => [
                $ownerGrant,
                ['granteeType' => 'Group', 'granteeId' => self::AUTHENTICATED_USERS_URI, 'permission' => 'READ'],
            ],
            default => [$ownerGrant],
        };
    }

    /**
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private static function parseGrantHeaders(Request $request, string $ownerId): array
    {
        $grants = [
            ['granteeType' => 'CanonicalUser', 'granteeId' => $ownerId, 'permission' => 'FULL_CONTROL'],
        ];

        $headerMap = [
            'x-amz-grant-read' => 'READ',
            'x-amz-grant-write' => 'WRITE',
            'x-amz-grant-read-acp' => 'READ_ACP',
            'x-amz-grant-write-acp' => 'WRITE_ACP',
            'x-amz-grant-full-control' => 'FULL_CONTROL',
        ];

        foreach ($headerMap as $header => $permission) {
            $value = $request->getHeader($header);
            if ($value === null || $value === '') {
                continue;
            }
            foreach (explode(',', $value) as $grantee) {
                $grantee = trim($grantee);
                if (preg_match('/^id\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                    $grants[] = ['granteeType' => 'CanonicalUser', 'granteeId' => $m[1], 'permission' => $permission];
                } elseif (preg_match('/^uri\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                    $grants[] = ['granteeType' => 'Group', 'granteeId' => $m[1], 'permission' => $permission];
                }
            }
        }

        return $grants;
    }
}
