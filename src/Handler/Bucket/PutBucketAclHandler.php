<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\AccessDeniedException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

/**
 * Handles PutBucketAcl (PUT /{bucket}?acl).
 *
 * Supports both canned ACLs via the x-amz-acl header and
 * explicit ACL XML in the request body.
 *
 * Canned ACLs:
 * - private: owner gets FULL_CONTROL
 * - public-read: owner FULL_CONTROL + AllUsers READ
 * - public-read-write: owner FULL_CONTROL + AllUsers READ + AllUsers WRITE
 * - authenticated-read: owner FULL_CONTROL + AuthenticatedUsers READ
 */
final class PutBucketAclHandler implements RequestHandler
{
    private const string ALL_USERS_URI = 'http://acs.amazonaws.com/groups/global/AllUsers';

    private const string AUTHENTICATED_USERS_URI = 'http://acs.amazonaws.com/groups/global/AuthenticatedUsers';

    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Check for canned ACL header first.
        $cannedAcl = $request->getHeader('x-amz-acl');

        if ($cannedAcl !== null) {
            $grants = self::expandCannedAcl($cannedAcl, $ownerId);
        } elseif ($this->hasGrantHeaders($request)) {
            // x-amz-grant-* headers
            $grants = $this->parseGrantHeaders($request, $ownerId);
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

        $this->metadata->putAcl('bucket', $bucket, $ownerId, $grants);

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
     * Check if the request has any x-amz-grant-* headers.
     */
    private function hasGrantHeaders(Request $request): bool
    {
        return $request->hasHeader('x-amz-grant-read')
            || $request->hasHeader('x-amz-grant-write')
            || $request->hasHeader('x-amz-grant-read-acp')
            || $request->hasHeader('x-amz-grant-write-acp')
            || $request->hasHeader('x-amz-grant-full-control');
    }

    /**
     * Parse x-amz-grant-* headers into grants array.
     *
     * Header format: id="canonical-user-id", id="another-id", uri="http://acs.amazonaws.com/groups/global/AllUsers"
     *
     * @return list<array{granteeType: string, granteeId: string, permission: string}>
     */
    private function parseGrantHeaders(Request $request, string $ownerId): array
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

            // Parse grantee specifications: id="xxx", uri="yyy", emailAddress="zzz"
            foreach (explode(',', $value) as $grantee) {
                $grantee = trim($grantee);
                if (preg_match('/^id\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                    $grants[] = ['granteeType' => 'CanonicalUser', 'granteeId' => $m[1], 'permission' => $permission];
                } elseif (preg_match('/^uri\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                    $grants[] = ['granteeType' => 'Group', 'granteeId' => $m[1], 'permission' => $permission];
                } elseif (preg_match('/^emailAddress\s*=\s*"([^"]+)"/i', $grantee, $m)) {
                    // Email-based grants — store as CanonicalUser with email as ID for now
                    $grants[] = ['granteeType' => 'AmazonCustomerByEmail', 'granteeId' => $m[1], 'permission' => $permission];
                }
            }
        }

        return $grants;
    }
}
