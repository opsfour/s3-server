<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Http\WebsiteRedirectHost;

/**
 * Parses S3 XML request bodies using SimpleXMLElement.
 *
 * All methods throw MalformedXmlException on parse failure.
 */
final class XmlRequestParser
{
    private const int MAX_ACL_GRANTS = 100;
    private const int MAX_LIFECYCLE_RULES = 1_000;
    private const int MAX_OBJECT_KEY_BYTES = 1_024;
    /** Keep restore expiry representable across every built-in metadata backend. */
    public const int MAX_RESTORE_DAYS = 365_000;

    /** Prevent DateTime overflow in lifecycle cutoffs from becoming destructive. */
    public const int MAX_LIFECYCLE_DAYS = 365_000;

    public const int MAX_DEFAULT_RETENTION_DAYS = 365_000;

    public const int MAX_DEFAULT_RETENTION_YEARS = 1_000;

    private const string S3_XML_NAMESPACE = 'http://s3.amazonaws.com/doc/2006-03-01/';

    /**
     * Parse a CreateBucketConfiguration XML body.
     *
     * @return array{locationConstraint: string}
     *
     * @throws MalformedXmlException
     */
    public static function parseCreateBucketConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'CreateBucketConfiguration');
        self::assertAllowedChildren($element, ['LocationConstraint'], 'CreateBucketConfiguration');

        $locationConstraint = isset($element->LocationConstraint)
            ? self::scalarText($element->LocationConstraint, 'LocationConstraint') : '';
        if (isset($element->LocationConstraint)
            && ($locationConstraint === '' || strlen($locationConstraint) > 64
                || ($locationConstraint !== 'EU'
                    && preg_match('/^[a-z0-9](?:[a-z0-9-]{0,62}[a-z0-9])?$/D', $locationConstraint) !== 1))) {
            throw new MalformedXmlException('LocationConstraint must be a valid region identifier of at most 64 bytes.');
        }

        return [
            'locationConstraint' => $locationConstraint,
        ];
    }

    /**
     * Parse a Delete (DeleteObjects) XML body.
     *
     * @return array{quiet: bool, objects: list<array{key: string, versionId: string|null}>}
     *
     * @throws MalformedXmlException
     */
    public static function parseDeleteObjects(string $xml): array
    {
        $element = self::parse($xml, 'Delete');
        self::assertAllowedChildren($element, ['Object', 'Quiet'], 'Delete', ['Object']);

        $quiet = false;
        $objects = [];
        foreach (S3XmlDocument::children($element) as $child) {
            if ($child->getName() === 'Quiet') {
                $rawQuiet = self::scalarText($child, 'Delete Quiet');
                if (! in_array($rawQuiet, ['true', 'false'], true)) {
                    throw new MalformedXmlException('Quiet must be true or false.');
                }
                $quiet = $rawQuiet === 'true';

                continue;
            }

            self::assertAllowedChildren($child, ['Key', 'VersionId'], 'Delete Object');
            $key = '';
            $versionId = null;
            foreach ($child->xpath('./*') ?: [] as $field) {
                if ($field->getName() === 'Key') {
                    $key = self::scalarText($field, 'Delete Object Key');
                } else {
                    $versionId = self::scalarText($field, 'Delete Object VersionId');
                }
            }
            if ($key === '') {
                throw new MalformedXmlException('Each Object element must contain a Key element.');
            }
            if (strlen($key) > 1024) {
                throw new MalformedXmlException('Delete Object Key must be at most 1024 bytes.');
            }
            if ($versionId === '') {
                throw new MalformedXmlException('VersionId must not be empty when specified.');
            }

            $objects[] = [
                'key' => $key,
                'versionId' => $versionId,
            ];
        }

        if ($objects === []) {
            throw new MalformedXmlException('Delete must contain at least one Object.');
        }

        if (count($objects) > 1000) {
            throw new MalformedXmlException('DeleteObjects batch may contain at most 1000 objects.');
        }

        return [
            'quiet' => $quiet,
            'objects' => $objects,
        ];
    }

    /**
     * Parse a CompleteMultipartUpload XML body.
     *
     * @return list<array{partNumber: int, etag: string}>
     *
     * @throws MalformedXmlException
     */
    public static function parseCompleteMultipartUpload(string $xml): array
    {
        $element = self::parse($xml, 'CompleteMultipartUpload');
        $children = self::assertAllowedChildren($element, ['Part'], 'CompleteMultipartUpload', ['Part']);

        $parts = [];
        foreach ($children['Part'] ?? [] as $part) {
            $fields = self::assertExactChildren($part, ['PartNumber', 'ETag'], 'CompleteMultipartUpload Part');
            $partNumber = self::positiveDecimalInt(self::scalarText($fields['PartNumber'], 'PartNumber'));
            $etag = self::scalarText($fields['ETag'], 'ETag');

            if ($partNumber === null || $partNumber > 10_000 || $etag === '') {
                throw new MalformedXmlException('Each Part element must contain valid PartNumber and ETag elements.');
            }

            $parts[] = [
                'partNumber' => $partNumber,
                'etag' => $etag,
            ];
        }

        if ($parts === []) {
            throw new MalformedXmlException('You must specify at least one part.');
        }

        return $parts;
    }

    /**
     * Parse a CORSConfiguration XML body.
     *
     * @return list<array{
     *     id?: string,
     *     allowedOrigins: list<string>,
     *     allowedMethods: list<string>,
     *     allowedHeaders: list<string>,
     *     exposeHeaders: list<string>,
     *     maxAgeSeconds: int|null,
     * }>
     *
     * @throws MalformedXmlException
     */
    public static function parseCorsConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'CORSConfiguration');
        $configuration = self::assertAllowedChildren($element, ['CORSRule'], 'CORSConfiguration', ['CORSRule']);
        if (! isset($configuration['CORSRule'])) {
            throw new MalformedXmlException('CORSConfiguration must contain a CORSRule.');
        }
        if (count($configuration['CORSRule']) > 100) {
            throw new MalformedXmlException('CORSConfiguration supports at most 100 CORSRule elements.');
        }

        $rules = [];
        foreach ($configuration['CORSRule'] as $rule) {
            $fields = self::assertAllowedChildren(
                $rule,
                ['ID', 'AllowedOrigin', 'AllowedMethod', 'AllowedHeader', 'ExposeHeader', 'MaxAgeSeconds'],
                'CORSRule',
                ['AllowedOrigin', 'AllowedMethod', 'AllowedHeader', 'ExposeHeader'],
            );
            $id = null;
            if (isset($fields['ID'])) {
                $id = self::scalarText($fields['ID'][0], 'CORSRule ID');
                if (self::utf8CharacterLength($id) > 255) {
                    throw new MalformedXmlException('CORSRule ID must not exceed 255 characters.');
                }
            }
            $allowedOrigins = [];
            foreach ($fields['AllowedOrigin'] ?? [] as $origin) {
                $value = self::scalarText($origin, 'AllowedOrigin');
                if ($value === '' || substr_count($value, '*') > 1) {
                    throw new MalformedXmlException('AllowedOrigin must be non-empty and contain at most one wildcard.');
                }
                $allowedOrigins[] = $value;
            }

            $allowedMethods = [];
            foreach ($fields['AllowedMethod'] ?? [] as $method) {
                $allowedMethods[] = self::scalarText($method, 'AllowedMethod');
            }

            if ($allowedOrigins === [] || $allowedMethods === []) {
                throw new MalformedXmlException('Each CORSRule must contain AllowedOrigin and AllowedMethod.');
            }
            foreach ($allowedMethods as $method) {
                if (! in_array($method, ['GET', 'PUT', 'HEAD', 'POST', 'DELETE'], true)) {
                    throw new MalformedXmlException("Invalid CORS AllowedMethod: {$method}.");
                }
            }

            $allowedHeaders = [];
            foreach ($fields['AllowedHeader'] ?? [] as $header) {
                $name = self::scalarText($header, 'AllowedHeader');
                if (preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name) !== 1
                    || substr_count($name, '*') > 1) {
                    throw new MalformedXmlException(
                        'AllowedHeader must be a valid HTTP header name containing at most one wildcard.',
                    );
                }
                $allowedHeaders[] = $name;
            }

            $exposeHeaders = [];
            foreach ($fields['ExposeHeader'] ?? [] as $header) {
                $name = self::scalarText($header, 'ExposeHeader');
                // Each value becomes one Access-Control-Expose-Headers field
                // name. Reject anything outside the HTTP token grammar before
                // persisting a CORS rule that would break matching responses.
                if (preg_match('/\A[!#$%&\'*+\-.^_`|~0-9A-Za-z]+\z/', $name) !== 1) {
                    throw new MalformedXmlException('CORS ExposeHeader must be a valid HTTP header name.');
                }
                $exposeHeaders[] = $name;
            }

            $maxAgeSeconds = null;
            if (isset($fields['MaxAgeSeconds'])) {
                $rawMaxAge = self::scalarText($fields['MaxAgeSeconds'][0], 'MaxAgeSeconds');
                $maxAgeSeconds = self::nonNegativeDecimalInt($rawMaxAge);
                if ($maxAgeSeconds === null || $maxAgeSeconds > 2_147_483_647) {
                    throw new MalformedXmlException('CORS MaxAgeSeconds must be an integer between 0 and 2147483647.');
                }
            }

            $parsedRule = [
                'allowedOrigins' => $allowedOrigins,
                'allowedMethods' => $allowedMethods,
                'allowedHeaders' => $allowedHeaders,
                'exposeHeaders' => $exposeHeaders,
                'maxAgeSeconds' => $maxAgeSeconds,
            ];
            if ($id !== null) {
                $parsedRule['id'] = $id;
            }
            $rules[] = $parsedRule;
        }

        return $rules;
    }

    /**
     * Parse a VersioningConfiguration XML body.
     *
     * @return array{status: string, mfaDelete?: string}
     *
     * @throws MalformedXmlException
     */
    public static function parseVersioningConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'VersioningConfiguration');
        $children = self::assertAllowedChildren($element, ['Status', 'MfaDelete'], 'VersioningConfiguration');

        $result = [
            'status' => '',
        ];

        if (isset($children['Status'])) {
            $status = self::scalarText($children['Status'][0], 'Status');
            if (! in_array($status, ['Enabled', 'Suspended'], true)) {
                throw new MalformedXmlException("Invalid versioning status: {$status}. Must be 'Enabled' or 'Suspended'.");
            }
            $result['status'] = $status;
        }

        if (isset($children['MfaDelete'])) {
            $mfaDelete = self::scalarText($children['MfaDelete'][0], 'MfaDelete');
            if (! in_array($mfaDelete, ['Enabled', 'Disabled'], true)) {
                throw new MalformedXmlException('MfaDelete must be Enabled or Disabled.');
            }
            $result['mfaDelete'] = $mfaDelete;
        }

        return $result;
    }

    /**
     * Parse a Tagging XML body.
     *
     * @return list<array{key: string, value: string}>
     *
     * @throws MalformedXmlException
     */
    public static function parseTagging(string $xml): array
    {
        $element = self::parse($xml, 'Tagging');
        $children = self::assertAllowedChildren($element, ['TagSet'], 'Tagging');

        if (! isset($children['TagSet'])) {
            throw new MalformedXmlException('Tagging must contain TagSet.');
        }

        $tags = [];
        $tagSet = self::assertAllowedChildren($children['TagSet'][0], ['Tag'], 'TagSet', ['Tag']);
        foreach ($tagSet['Tag'] ?? [] as $tag) {
            $fields = self::assertAllowedChildren($tag, ['Key', 'Value'], 'Tag');
            $key = isset($fields['Key']) ? self::scalarText($fields['Key'][0], 'Tag Key') : '';
            if (! isset($fields['Value'])) {
                throw new MalformedXmlException('Each Tag element must contain a Value element.');
            }
            $value = self::scalarText($fields['Value'][0], 'Tag Value');

            if ($key === '') {
                throw new MalformedXmlException('Each Tag element must contain a non-empty Key element.');
            }

            $tags[] = [
                'key' => $key,
                'value' => $value,
            ];
        }

        return $tags;
    }

    /**
     * Parse an ObjectLockConfiguration XML body.
     *
     * @return array{
     *     objectLockEnabled: string,
     *     rule?: array{
     *         defaultRetention: array{
     *             mode: string,
     *             days?: int,
     *             years?: int,
     *         },
     *     },
     * }
     *
     * @throws MalformedXmlException
     */
    public static function parseObjectLockConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'ObjectLockConfiguration');
        $children = self::assertAllowedChildren($element, ['ObjectLockEnabled', 'Rule'], 'ObjectLockConfiguration');

        $result = [
            'objectLockEnabled' => 'Enabled',
        ];

        if (isset($children['ObjectLockEnabled'])) {
            if (self::scalarText($children['ObjectLockEnabled'][0], 'ObjectLockEnabled') !== 'Enabled') {
                throw new MalformedXmlException('ObjectLockEnabled must be Enabled.');
            }
        }

        if (isset($children['Rule'])) {
            $rule = self::assertAllowedChildren($children['Rule'][0], ['DefaultRetention'], 'Object Lock Rule');
            if (! isset($rule['DefaultRetention'])) {
                throw new MalformedXmlException('Object Lock Rule requires DefaultRetention.');
            }
            $retention = self::assertAllowedChildren($rule['DefaultRetention'][0], ['Mode', 'Days', 'Years'], 'DefaultRetention');
            $mode = isset($retention['Mode']) ? self::scalarText($retention['Mode'][0], 'DefaultRetention Mode') : '';
            if (! in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true)) {
                throw new MalformedXmlException('DefaultRetention Mode must be GOVERNANCE or COMPLIANCE.');
            }
            if (isset($retention['Days']) === isset($retention['Years'])) {
                throw new MalformedXmlException('DefaultRetention requires exactly one of Days or Years.');
            }
            $retentionResult = [
                'mode' => $mode,
            ];

            if (isset($retention['Days'])) {
                $retentionResult['days'] = self::positiveRetentionPeriod(
                    self::scalarText($retention['Days'][0], 'DefaultRetention Days'),
                    self::MAX_DEFAULT_RETENTION_DAYS,
                );
            }

            if (isset($retention['Years'])) {
                $retentionResult['years'] = self::positiveRetentionPeriod(
                    self::scalarText($retention['Years'][0], 'DefaultRetention Years'),
                    self::MAX_DEFAULT_RETENTION_YEARS,
                );
            }

            $result['rule'] = [
                'defaultRetention' => $retentionResult,
            ];
        }

        return $result;
    }

    private static function positiveRetentionPeriod(string $value, int $maximum): int
    {
        $period = self::positiveDecimalInt($value);
        if ($period === null || $period > $maximum) {
            throw new MalformedXmlException(
                "DefaultRetention period must be a positive integer no greater than {$maximum}.",
            );
        }

        return $period;
    }

    /**
     * Parse a Retention XML body.
     *
     * @return array{mode: string, retainUntilDate: string}|null
     *
     * @throws MalformedXmlException
     */
    public static function parseRetention(string $xml): ?array
    {
        $element = self::parse($xml, 'Retention');
        $children = self::assertAllowedChildren($element, ['Mode', 'RetainUntilDate'], 'Retention');
        if ($children === []) {
            return null;
        }
        if (! isset($children['Mode'], $children['RetainUntilDate'])) {
            throw new MalformedXmlException('Retention is missing a required element.');
        }

        $mode = self::scalarText($children['Mode'][0], 'Retention Mode');
        $retainUntilDate = self::scalarText($children['RetainUntilDate'][0], 'RetainUntilDate');

        if ($mode === '' || $retainUntilDate === '') {
            throw new MalformedXmlException('Retention element must contain both Mode and RetainUntilDate elements.');
        }

        if (! in_array($mode, ['GOVERNANCE', 'COMPLIANCE'], true)) {
            throw new MalformedXmlException("Invalid retention mode: {$mode}. Must be 'GOVERNANCE' or 'COMPLIANCE'.");
        }

        return [
            'mode' => $mode,
            'retainUntilDate' => $retainUntilDate,
        ];
    }

    /**
     * Parse a LegalHold XML body.
     *
     * @return array{status: string}
     *
     * @throws MalformedXmlException
     */
    public static function parseLegalHold(string $xml): array
    {
        $element = self::parse($xml, 'LegalHold');
        $children = self::assertExactChildren($element, ['Status'], 'LegalHold');

        $status = self::scalarText($children['Status'], 'LegalHold Status');

        if (! in_array($status, ['ON', 'OFF'], true)) {
            throw new MalformedXmlException("Invalid legal hold status: {$status}. Must be 'ON' or 'OFF'.");
        }

        return [
            'status' => $status,
        ];
    }

    /**
     * @param list<string> $expected
     * @return array<string, \SimpleXMLElement>
     */
    private static function assertExactChildren(\SimpleXMLElement $element, array $expected, string $root): array
    {
        $seen = [];
        $namespace = dom_import_simplexml($element)->namespaceURI;
        // xpath() includes prefixed children, which children() silently omits.
        // Unsupported extension fields must never be ignored on mutating XML.
        foreach (S3XmlDocument::children($element) as $child) {
            $name = $child->getName();
            if (dom_import_simplexml($child)->namespaceURI !== $namespace
                || ! in_array($name, $expected, true)
                || isset($seen[$name])) {
                throw new MalformedXmlException("{$root} contains an unexpected or duplicate element.");
            }
            $seen[$name] = $child;
        }

        if (count($seen) !== count($expected)) {
            throw new MalformedXmlException("{$root} is missing a required element.");
        }

        return $seen;
    }

    /**
     * Parse an AccessControlPolicy XML body.
     *
     * @return array{ownerId: string, grants: list<array{granteeType: string, granteeId: string, permission: string}>}
     *
     * @throws MalformedXmlException
     */
    public static function parseAccessControlPolicy(string $xml): array
    {
        $element = self::parse($xml, 'AccessControlPolicy', allowAclGranteeType: true);
        $children = self::assertAllowedChildren($element, ['Owner', 'AccessControlList'], 'AccessControlPolicy');

        $ownerId = '';
        if (isset($children['Owner'])) {
            $owner = self::assertAllowedChildren($children['Owner'][0], ['ID', 'DisplayName'], 'ACL Owner');
            if (isset($owner['ID'])) {
                $ownerId = self::scalarText($owner['ID'][0], 'ACL Owner ID');
            }
            if (isset($owner['DisplayName'])) {
                self::scalarText($owner['DisplayName'][0], 'ACL Owner DisplayName');
            }
        }

        $grants = [];
        if (isset($children['AccessControlList'])) {
            $accessControlList = self::assertAllowedChildren($children['AccessControlList'][0], ['Grant'], 'AccessControlList', ['Grant']);
            if (count($accessControlList['Grant'] ?? []) > self::MAX_ACL_GRANTS) {
                throw new MalformedXmlException('AccessControlList cannot contain more than 100 grants.');
            }
            foreach ($accessControlList['Grant'] ?? [] as $grant) {
                $fields = self::assertAllowedChildren($grant, ['Grantee', 'Permission'], 'ACL Grant');
                $permission = isset($fields['Permission']) ? self::scalarText($fields['Permission'][0], 'ACL Permission') : '';

                if ($permission === '') {
                    throw new MalformedXmlException('Each Grant element must contain a Permission element.');
                }

                $granteeType = 'CanonicalUser';
                $granteeId = '';

                if (isset($fields['Grantee'])) {
                    $grantee = $fields['Grantee'][0];
                    $granteeFields = self::assertAllowedChildren($grantee, ['ID', 'DisplayName', 'EmailAddress', 'URI'], 'ACL Grantee');
                    foreach ($granteeFields as $name => $value) {
                        self::scalarText($value[0], 'ACL Grantee ' . $name);
                    }
                    $identifierCount = (int) isset($granteeFields['ID'])
                        + (int) isset($granteeFields['EmailAddress'])
                        + (int) isset($granteeFields['URI']);
                    if ($identifierCount > 1) {
                        throw new MalformedXmlException('ACL Grantee must not contain conflicting identifiers.');
                    }

                    // Check xsi:type attribute for grantee type.
                    $attributes = $grantee->attributes('http://www.w3.org/2001/XMLSchema-instance');
                    if ($attributes !== null && isset($attributes['type'])) {
                        $type = (string) $attributes['type'];
                        if ($type === 'Group') {
                            $granteeType = 'Group';
                            $granteeId = isset($granteeFields['URI']) ? (string) $granteeFields['URI'][0] : '';
                        } elseif ($type === 'AmazonCustomerByEmail') {
                            $granteeType = 'AmazonCustomerByEmail';
                            $granteeId = isset($granteeFields['EmailAddress']) ? (string) $granteeFields['EmailAddress'][0] : '';
                        } else {
                            $granteeType = $type;
                            $granteeId = isset($granteeFields['ID']) ? (string) $granteeFields['ID'][0] : '';
                        }
                    } else {
                        // Fallback: check for URI (Group) or ID (CanonicalUser).
                        if (isset($granteeFields['URI'])) {
                            $granteeType = 'Group';
                            $granteeId = (string) $granteeFields['URI'][0];
                        } elseif (isset($granteeFields['ID'])) {
                            $granteeType = 'CanonicalUser';
                            $granteeId = (string) $granteeFields['ID'][0];
                        }
                    }
                }

                $grants[] = [
                    'granteeType' => $granteeType,
                    'granteeId' => $granteeId,
                    'permission' => $permission,
                ];
            }
        }

        return [
            'ownerId' => $ownerId,
            'grants' => $grants,
        ];
    }

    /**
     * Parse a ServerSideEncryptionConfiguration XML body.
     *
     * @return array{sseAlgorithm: string, kmsMasterKeyId: ?string, bucketKeyEnabled: bool}
     *
     * @throws MalformedXmlException
     */
    public static function parseEncryptionConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'ServerSideEncryptionConfiguration');
        $configuration = self::assertAllowedChildren($element, ['Rule'], 'ServerSideEncryptionConfiguration');
        $rule = isset($configuration['Rule'])
            ? self::assertAllowedChildren($configuration['Rule'][0], ['ApplyServerSideEncryptionByDefault', 'BucketKeyEnabled'], 'Encryption Rule')
            : [];
        $defaults = isset($rule['ApplyServerSideEncryptionByDefault'])
            ? self::assertAllowedChildren($rule['ApplyServerSideEncryptionByDefault'][0], ['SSEAlgorithm', 'KMSMasterKeyID'], 'Encryption defaults')
            : [];
        if (! isset($defaults['SSEAlgorithm'])) {
            throw new MalformedXmlException('Bucket encryption requires Rule, ApplyServerSideEncryptionByDefault, and SSEAlgorithm.');
        }

        $sseAlgorithm = self::scalarText($defaults['SSEAlgorithm'][0], 'SSEAlgorithm');
        if (!in_array($sseAlgorithm, ['AES256', 'aws:kms'], true)) {
            throw new MalformedXmlException("Invalid SSEAlgorithm: {$sseAlgorithm}. Must be 'AES256' or 'aws:kms'.");
        }
        $kmsMasterKeyId = isset($defaults['KMSMasterKeyID'])
            ? self::scalarText($defaults['KMSMasterKeyID'][0], 'KMSMasterKeyID')
            : null;
        $bucketKeyEnabled = false;
        if (isset($rule['BucketKeyEnabled'])) {
            $rawBucketKeyEnabled = self::scalarText($rule['BucketKeyEnabled'][0], 'BucketKeyEnabled');
            if (! in_array($rawBucketKeyEnabled, ['true', 'false'], true)) {
                throw new MalformedXmlException('BucketKeyEnabled must be true or false.');
            }
            $bucketKeyEnabled = $rawBucketKeyEnabled === 'true';
        }
        if ($sseAlgorithm === 'AES256' && ($kmsMasterKeyId !== null || $bucketKeyEnabled)) {
            throw new MalformedXmlException('KMSMasterKeyID and enabled BucketKeyEnabled require aws:kms.');
        }

        return [
            'sseAlgorithm' => $sseAlgorithm,
            'kmsMasterKeyId' => $kmsMasterKeyId,
            'bucketKeyEnabled' => $bucketKeyEnabled,
        ];
    }

    /**
     * Parse a LifecycleConfiguration XML body.
     *
     * @return list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>
     *
     * @throws MalformedXmlException
     */
    public static function parseLifecycleConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'LifecycleConfiguration');
        self::assertAllowedChildren($element, ['Rule'], 'LifecycleConfiguration', ['Rule']);

        if (! isset($element->Rule)) {
            throw new MalformedXmlException('LifecycleConfiguration must contain at least one Rule.');
        }
        if (count($element->Rule) > self::MAX_LIFECYCLE_RULES) {
            throw new MalformedXmlException('LifecycleConfiguration cannot contain more than 1000 rules.');
        }

        $rules = [];
        $seenRuleIds = [];
        if (isset($element->Rule)) {
            foreach ($element->Rule as $rule) {
                self::assertAllowedChildren(
                    $rule,
                    ['ID', 'Status', 'Filter', 'Prefix', 'Expiration', 'Transition', 'NoncurrentVersionTransition', 'NoncurrentVersionExpiration', 'AbortIncompleteMultipartUpload'],
                    'Lifecycle Rule',
                    ['Transition', 'NoncurrentVersionTransition'],
                );
                $status = isset($rule->Status) ? self::scalarText($rule->Status, 'Lifecycle Rule Status') : '';
                if (! in_array($status, ['Enabled', 'Disabled'], true)) {
                    throw new MalformedXmlException('Lifecycle Rule Status must be Enabled or Disabled.');
                }
                if (! isset($rule->Filter) && ! isset($rule->Prefix)) {
                    throw new MalformedXmlException('Lifecycle Rule requires a Filter or Prefix.');
                }
                if (isset($rule->Filter, $rule->Prefix)) {
                    throw new MalformedXmlException('Lifecycle Rule cannot combine Filter and Prefix.');
                }
                $ruleId = isset($rule->ID) ? self::scalarText($rule->ID, 'Lifecycle Rule ID') : bin2hex(random_bytes(8));
                if ($ruleId === '' || self::utf8CharacterLength($ruleId) > 255 || isset($seenRuleIds["\0{$ruleId}"])) {
                    throw new MalformedXmlException('Lifecycle Rule ID must be unique and between 1 and 255 characters.');
                }
                $seenRuleIds["\0{$ruleId}"] = true;
                $ruleData = [
                    'id' => $ruleId,
                    'status' => $status,
                    'prefix' => null,
                    'filter' => null,
                    'transitions' => null,
                    'expiration' => null,
                    'noncurrentTransitions' => null,
                    'noncurrentExpiration' => null,
                    'abortIncompleteDays' => null,
                ];

                // Prefix or Filter
                if (isset($rule->Filter)) {
                    self::validateLifecycleFilter($rule->Filter);
                    $filter = [];
                    if (isset($rule->Filter->Prefix)) {
                        $filter['prefix'] = (string) $rule->Filter->Prefix;
                    }
                    if (isset($rule->Filter->Tag)) {
                        $filter['tag'] = [
                            'key' => (string) ($rule->Filter->Tag->Key ?? ''),
                            'value' => (string) ($rule->Filter->Tag->Value ?? ''),
                        ];
                    }
                    if (isset($rule->Filter->And)) {
                        $and = [];
                        if (isset($rule->Filter->And->Prefix)) {
                            $and['prefix'] = (string) $rule->Filter->And->Prefix;
                        }
                        if (isset($rule->Filter->And->Tag)) {
                            $tags = [];
                            foreach ($rule->Filter->And->Tag as $tag) {
                                $tags[] = [
                                    'key' => (string) ($tag->Key ?? ''),
                                    'value' => (string) ($tag->Value ?? ''),
                                ];
                            }
                            $and['tags'] = $tags;
                        }
                        $filter['and'] = $and;
                    }
                    $ruleData['filter'] = $filter;
                } elseif (isset($rule->Prefix)) {
                    $ruleData['prefix'] = self::scalarText($rule->Prefix, 'Lifecycle Rule Prefix');
                }

                // Expiration
                if (isset($rule->Expiration)) {
                    self::assertAllowedChildren($rule->Expiration, ['Days', 'Date', 'ExpiredObjectDeleteMarker'], 'Lifecycle Expiration');
                    $expiration = [];
                    if (isset($rule->Expiration->Days)) {
                        $expiration['days'] = self::positiveLifecycleDays(self::scalarText($rule->Expiration->Days, 'Expiration Days'), 'Expiration Days');
                    }
                    if (isset($rule->Expiration->Date)) {
                        $expiration['date'] = self::lifecycleDate(self::scalarText($rule->Expiration->Date, 'Expiration Date'));
                    }
                    if (isset($rule->Expiration->ExpiredObjectDeleteMarker)) {
                        $rawDeleteMarker = self::scalarText($rule->Expiration->ExpiredObjectDeleteMarker, 'ExpiredObjectDeleteMarker');
                        if (! in_array($rawDeleteMarker, ['true', 'false'], true)) {
                            throw new MalformedXmlException('ExpiredObjectDeleteMarker must be true or false.');
                        }
                        $expiration['expiredObjectDeleteMarker'] = $rawDeleteMarker === 'true';
                    }
                    if ($expiration === [] || count($expiration) > 1) {
                        throw new MalformedXmlException('Expiration requires exactly one of Days, Date, or ExpiredObjectDeleteMarker.');
                    }
                    $ruleData['expiration'] = $expiration;
                }

                // Transitions
                if (isset($rule->Transition)) {
                    $transitions = [];
                    foreach ($rule->Transition as $transition) {
                        self::assertAllowedChildren($transition, ['Days', 'Date', 'StorageClass'], 'Lifecycle Transition');
                        $t = [];
                        if (isset($transition->Days)) {
                            $t['days'] = self::nonNegativeLifecycleDays(self::scalarText($transition->Days, 'Transition Days'), 'Transition Days');
                        }
                        if (isset($transition->Date)) {
                            $t['date'] = self::lifecycleDate(self::scalarText($transition->Date, 'Transition Date'));
                        }
                        if (isset($transition->StorageClass)) {
                            $t['storageClass'] = self::scalarText($transition->StorageClass, 'Transition StorageClass');
                        }
                        if (! isset($t['storageClass']) || (isset($t['days']) === isset($t['date']))) {
                            throw new MalformedXmlException('Transition requires StorageClass and exactly one of Days or Date.');
                        }
                        $transitions[] = $t;
                    }
                    $ruleData['transitions'] = $transitions;
                }

                // NoncurrentVersionTransition
                if (isset($rule->NoncurrentVersionTransition)) {
                    $noncurrentTransitions = [];
                    foreach ($rule->NoncurrentVersionTransition as $nvt) {
                        self::assertAllowedChildren($nvt, ['NoncurrentDays', 'StorageClass'], 'NoncurrentVersionTransition');
                        $t = [];
                        if (isset($nvt->NoncurrentDays)) {
                            $t['noncurrentDays'] = self::positiveLifecycleDays(self::scalarText($nvt->NoncurrentDays, 'NoncurrentDays'), 'NoncurrentDays');
                        }
                        if (isset($nvt->StorageClass)) {
                            $t['storageClass'] = self::scalarText($nvt->StorageClass, 'NoncurrentVersionTransition StorageClass');
                        }
                        if (! isset($t['noncurrentDays'], $t['storageClass'])) {
                            throw new MalformedXmlException('NoncurrentVersionTransition requires NoncurrentDays and StorageClass.');
                        }
                        $noncurrentTransitions[] = $t;
                    }
                    $ruleData['noncurrentTransitions'] = $noncurrentTransitions;
                }

                // NoncurrentVersionExpiration
                if (isset($rule->NoncurrentVersionExpiration)) {
                    self::assertAllowedChildren($rule->NoncurrentVersionExpiration, ['NoncurrentDays'], 'NoncurrentVersionExpiration');
                    $nce = [];
                    if (isset($rule->NoncurrentVersionExpiration->NoncurrentDays)) {
                        $nce['noncurrentDays'] = self::positiveLifecycleDays(self::scalarText($rule->NoncurrentVersionExpiration->NoncurrentDays, 'NoncurrentDays'), 'NoncurrentDays');
                    }
                    if ($nce === []) {
                        throw new MalformedXmlException('NoncurrentVersionExpiration requires NoncurrentDays.');
                    }
                    $ruleData['noncurrentExpiration'] = $nce;
                }

                // AbortIncompleteMultipartUpload
                if (isset($rule->AbortIncompleteMultipartUpload)) {
                    self::assertAllowedChildren($rule->AbortIncompleteMultipartUpload, ['DaysAfterInitiation'], 'AbortIncompleteMultipartUpload');
                    if (! isset($rule->AbortIncompleteMultipartUpload->DaysAfterInitiation)) {
                        throw new MalformedXmlException('AbortIncompleteMultipartUpload requires DaysAfterInitiation.');
                    }
                    $ruleData['abortIncompleteDays'] = self::positiveLifecycleDays(self::scalarText($rule->AbortIncompleteMultipartUpload->DaysAfterInitiation, 'DaysAfterInitiation'), 'DaysAfterInitiation');
                }

                $hasTagFilter = isset($rule->Filter->Tag) || isset($rule->Filter->And->Tag);
                if ($hasTagFilter && (isset($rule->AbortIncompleteMultipartUpload) || isset($rule->Expiration->ExpiredObjectDeleteMarker))) {
                    throw new MalformedXmlException('Tag filters cannot be combined with multipart abort or expired delete marker actions.');
                }
                if ($ruleData['transitions'] === null
                    && $ruleData['expiration'] === null
                    && $ruleData['noncurrentTransitions'] === null
                    && $ruleData['noncurrentExpiration'] === null
                    && $ruleData['abortIncompleteDays'] === null) {
                    throw new MalformedXmlException('Lifecycle Rule must contain at least one action.');
                }

                $rules[] = $ruleData;
            }
        }

        return $rules;
    }

    private static function validateLifecycleFilter(\SimpleXMLElement $filter): void
    {
        $predicates = self::assertAllowedChildren($filter, ['Prefix', 'Tag', 'And'], 'Lifecycle Filter');
        if (count($predicates) > 1) {
            throw new MalformedXmlException('Lifecycle Filter contains conflicting elements.');
        }
        if (isset($predicates['Prefix'])) {
            self::scalarText($predicates['Prefix'][0], 'Lifecycle Filter Prefix');
        }
        if (isset($predicates['Tag'])) {
            self::validateLifecycleTag($predicates['Tag'][0]);
        }
        if (isset($predicates['And'])) {
            $and = self::assertAllowedChildren(
                $predicates['And'][0],
                ['Prefix', 'Tag'],
                'Lifecycle Filter And',
                ['Tag'],
            );
            $predicateCount = count($and['Prefix'] ?? []) + count($and['Tag'] ?? []);
            if ($predicateCount < 2) {
                throw new MalformedXmlException('Lifecycle Filter And requires at least two predicates.');
            }
            if (isset($and['Prefix'])) {
                self::scalarText($and['Prefix'][0], 'Lifecycle Filter And Prefix');
            }

            $tagKeys = [];
            foreach ($and['Tag'] ?? [] as $tag) {
                $key = self::validateLifecycleTag($tag);
                if (isset($tagKeys["\0{$key}"])) {
                    throw new MalformedXmlException('Lifecycle Filter And tag keys must be unique.');
                }
                $tagKeys["\0{$key}"] = true;
            }
        }
    }

    private static function validateLifecycleTag(\SimpleXMLElement $tag): string
    {
        $fields = self::assertAllowedChildren($tag, ['Key', 'Value'], 'Lifecycle Tag');
        if (! isset($fields['Key'])) {
            throw new MalformedXmlException('Lifecycle Tag Key must not be empty.');
        }
        $key = self::scalarText($fields['Key'][0], 'Lifecycle Tag Key');
        $value = isset($fields['Value'])
            ? self::scalarText($fields['Value'][0], 'Lifecycle Tag Value')
            : '';
        try {
            \OpsFour\S3Server\Http\ObjectTagValidator::validate([
                ['key' => $key, 'value' => $value],
            ], 1);
        } catch (\OpsFour\S3Server\Exception\InvalidArgumentException) {
            throw new MalformedXmlException('Lifecycle Tag does not satisfy the S3 tag constraints.');
        }

        return $key;
    }

    private static function utf8CharacterLength(string $value): int
    {
        $length = preg_match_all('/./us', $value);
        if ($length === false) {
            throw new MalformedXmlException('XML text must be valid UTF-8.');
        }

        return $length;
    }

    private static function lifecycleDate(string $value): string
    {
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})T00:00:00(?:\.0+)?(?:Z|\+00:00)$/', $value, $parts) !== 1
            || ! checkdate((int) $parts[2], (int) $parts[3], (int) $parts[1])) {
            throw new MalformedXmlException('Lifecycle Date must be a valid ISO 8601 midnight UTC timestamp.');
        }

        return $value;
    }

    private static function positiveLifecycleDays(string $value, string $field): int
    {
        $days = self::positiveDecimalInt($value);
        if ($days === null || $days > self::MAX_LIFECYCLE_DAYS) {
            throw new MalformedXmlException(
                "{$field} must be a positive decimal integer no greater than " . self::MAX_LIFECYCLE_DAYS . '.',
            );
        }

        return $days;
    }

    private static function nonNegativeLifecycleDays(string $value, string $field): int
    {
        $days = self::nonNegativeDecimalInt($value);
        if ($days === null || $days > self::MAX_LIFECYCLE_DAYS) {
            throw new MalformedXmlException(
                "{$field} must be a non-negative decimal integer no greater than " . self::MAX_LIFECYCLE_DAYS . '.',
            );
        }

        return $days;
    }

    private static function positiveDecimalInt(string $value): ?int
    {
        $number = self::nonNegativeDecimalInt($value);

        return $number !== null && $number > 0 ? $number : null;
    }

    private static function nonNegativeDecimalInt(string $value): ?int
    {
        if ($value === '' || ! ctype_digit($value)) {
            return null;
        }

        $normalized = ltrim($value, '0');
        if ($normalized === '') {
            return 0;
        }
        if (strlen($normalized) > strlen((string) PHP_INT_MAX)
            || (strlen($normalized) === strlen((string) PHP_INT_MAX) && strcmp($normalized, (string) PHP_INT_MAX) > 0)) {
            return null;
        }

        return (int) $normalized;
    }

    /**
     * Parse a NotificationConfiguration XML body.
     *
     * @return list<array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, array{name: string, value: string}>}>
     *
     * @throws MalformedXmlException
     */
    public static function parseNotificationConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'NotificationConfiguration');
        $fields = self::assertAllowedChildren(
            $element,
            ['TopicConfiguration', 'QueueConfiguration', 'CloudFunctionConfiguration'],
            'NotificationConfiguration',
            ['TopicConfiguration', 'QueueConfiguration', 'CloudFunctionConfiguration'],
        );

        $configs = [];

        // Parse TopicConfiguration
        foreach ($fields['TopicConfiguration'] ?? [] as $config) {
            $configs[] = self::parseNotificationEntry($config, 'Topic', 'Topic');
        }

        // Parse QueueConfiguration
        foreach ($fields['QueueConfiguration'] ?? [] as $config) {
            $configs[] = self::parseNotificationEntry($config, 'Queue', 'Queue');
        }

        // Parse CloudFunctionConfiguration
        foreach ($fields['CloudFunctionConfiguration'] ?? [] as $config) {
            $configs[] = self::parseNotificationEntry($config, 'CloudFunction', 'CloudFunction');
        }

        return $configs;
    }

    /**
     * Parse a single notification configuration entry.
     *
     * @return array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, array{name: string, value: string}>}
     */
    private static function parseNotificationEntry(\SimpleXMLElement $config, string $destType, string $arnElement): array
    {
        $fields = self::assertAllowedChildren($config, ['Id', $arnElement, 'Event', 'Filter'], 'Notification entry', ['Event']);
        $id = isset($fields['Id']) ? self::scalarText($fields['Id'][0], 'Notification Id') : bin2hex(random_bytes(8));
        $destinationArn = isset($fields[$arnElement]) ? self::scalarText($fields[$arnElement][0], "Notification {$destType} destination") : '';
        if ($destinationArn === '') {
            throw new MalformedXmlException("Notification {$destType} destination is required.");
        }

        // Collect all <Event> elements (AWS allows multiple per config entry).
        $events = [];
        foreach ($fields['Event'] ?? [] as $event) {
            $eventStr = self::scalarText($event, 'Notification Event');
            if ($eventStr === '') {
                continue;
            }
            // Validate format: "s3:*" or "s3:Category:Action" or "s3:Category:*"
            // Pattern-based to support all current and future AWS event types.
            if ($eventStr !== 's3:*' && !preg_match('/^s3:[A-Za-z][A-Za-z0-9-]*(?::[A-Za-z0-9*-]+)?$/', $eventStr)) {
                throw new \OpsFour\S3Server\Exception\MalformedXmlException(
                    "Invalid event type: {$eventStr}",
                );
            }
            $events[] = $eventStr;
        }

        if ($events === []) {
            throw new \OpsFour\S3Server\Exception\MalformedXmlException(
                'Each notification configuration must specify at least one Event.',
            );
        }

        $filterRules = null;
        if (isset($fields['Filter'])) {
            $filterFields = self::assertExactChildren($fields['Filter'][0], ['S3Key'], 'Notification Filter');
            $keyFields = self::assertAllowedChildren($filterFields['S3Key'], ['FilterRule'], 'Notification S3Key', ['FilterRule']);
            if (! isset($keyFields['FilterRule'])) {
                throw new MalformedXmlException('Notification Filter requires S3Key FilterRule.');
            }
            $filterRules = [];
            $seenRuleNames = [];
            foreach ($keyFields['FilterRule'] as $filterRule) {
                $ruleFields = self::assertExactChildren($filterRule, ['Name', 'Value'], 'Notification FilterRule');
                $name = self::scalarText($ruleFields['Name'], 'Notification FilterRule Name');
                if (! in_array($name, ['prefix', 'suffix'], true)) {
                    throw new MalformedXmlException('Notification FilterRule requires prefix or suffix Name and Value.');
                }
                if (isset($seenRuleNames[$name])) {
                    throw new MalformedXmlException("Notification FilterRule {$name} must not be repeated.");
                }
                $seenRuleNames[$name] = true;
                $value = self::scalarText($ruleFields['Value'], 'Notification FilterRule Value');
                if (str_contains(urldecode($value), '*')) {
                    throw new MalformedXmlException('Notification FilterRule must not contain a wildcard.');
                }
                $filterRules[] = [
                    'name' => $name,
                    'value' => $value,
                ];
            }
        }

        return [
            'id' => $id,
            'events' => $events,
            'destinationType' => $destType,
            'destinationArn' => $destinationArn,
            'filterRules' => $filterRules,
        ];
    }

    /**
     * Parse a WebsiteConfiguration XML body.
     *
     * @return array{indexDocument: string, errorDocument: ?string, redirectAllHost: ?string, redirectAllProtocol: ?string, routingRules: ?list<array<string, mixed>>}
     *
     * @throws MalformedXmlException
     */
    public static function parseWebsiteConfiguration(string $xml): array
    {
        $element = self::parse($xml, 'WebsiteConfiguration');

        $children = self::assertAllowedChildren($element, ['IndexDocument', 'ErrorDocument', 'RedirectAllRequestsTo', 'RoutingRules'], 'WebsiteConfiguration');
        $indexDocument = $children['IndexDocument'][0] ?? null;
        $errorDocument = $children['ErrorDocument'][0] ?? null;
        $redirectAll = $children['RedirectAllRequestsTo'][0] ?? null;
        $routingRulesElement = $children['RoutingRules'][0] ?? null;
        if ($indexDocument === null && $redirectAll === null) {
            throw new MalformedXmlException('WebsiteConfiguration requires IndexDocument or RedirectAllRequestsTo.');
        }

        $result = [
            'indexDocument' => 'index.html',
            'errorDocument' => null,
            'redirectAllHost' => null,
            'redirectAllProtocol' => null,
            'routingRules' => null,
        ];

        // RedirectAllRequestsTo takes precedence.
        if ($redirectAll !== null) {
            if ($indexDocument !== null || $errorDocument !== null || $routingRulesElement !== null) {
                throw new MalformedXmlException('RedirectAllRequestsTo cannot be combined with other website settings.');
            }
            $redirectChildren = self::assertAllowedChildren($redirectAll, ['HostName', 'Protocol'], 'RedirectAllRequestsTo');
            $hostName = isset($redirectChildren['HostName'])
                ? self::scalarText($redirectChildren['HostName'][0], 'RedirectAllRequestsTo HostName') : '';
            if (! WebsiteRedirectHost::isValid($hostName)) {
                throw new MalformedXmlException('RedirectAllRequestsTo requires a valid HostName.');
            }
            $result['redirectAllHost'] = $hostName;
            if (isset($redirectChildren['Protocol'])) {
                $protocol = self::scalarText($redirectChildren['Protocol'][0], 'RedirectAllRequestsTo Protocol');
                if (! in_array($protocol, ['http', 'https'], true)) {
                    throw new MalformedXmlException('Website redirect Protocol must be http or https.');
                }
                $result['redirectAllProtocol'] = $protocol;
            }

            return $result;
        }

        if ($indexDocument !== null) {
            $indexChildren = self::assertExactChildren($indexDocument, ['Suffix'], 'IndexDocument');
            $suffix = self::scalarText($indexChildren['Suffix'], 'IndexDocument Suffix');
            if ($suffix === '' || str_contains($suffix, '/')) {
                throw new MalformedXmlException('IndexDocument Suffix must be non-empty and contain no slash.');
            }
            self::assertWebsiteKeyField($suffix, 'IndexDocument Suffix');
            $result['indexDocument'] = $suffix;
        }

        if ($errorDocument !== null) {
            $errorChildren = self::assertExactChildren($errorDocument, ['Key'], 'ErrorDocument');
            $errorKey = self::scalarText($errorChildren['Key'], 'ErrorDocument Key');
            if ($errorKey === '') {
                throw new MalformedXmlException('ErrorDocument Key must be non-empty.');
            }
            self::assertWebsiteKeyField($errorKey, 'ErrorDocument Key');
            $result['errorDocument'] = $errorKey;
        }

        if ($routingRulesElement !== null) {
            $routingRuleChildren = self::assertAllowedChildren($routingRulesElement, ['RoutingRule'], 'RoutingRules', ['RoutingRule']);
            if (! isset($routingRuleChildren['RoutingRule'])) {
                throw new MalformedXmlException('RoutingRules requires at least one RoutingRule.');
            }
            if (count($routingRuleChildren['RoutingRule']) > 50) {
                throw new MalformedXmlException('RoutingRules supports at most 50 RoutingRule elements.');
            }
            $routingRules = [];
            foreach ($routingRuleChildren['RoutingRule'] as $rule) {
                $ruleChildren = self::assertAllowedChildren($rule, ['Condition', 'Redirect'], 'RoutingRule');
                $ruleData = [];
                $conditionElement = $ruleChildren['Condition'][0] ?? null;
                $redirectElement = $ruleChildren['Redirect'][0] ?? null;

                if ($redirectElement === null) {
                    throw new MalformedXmlException('RoutingRule requires a non-empty Redirect.');
                }

                if ($conditionElement !== null) {
                    $conditionChildren = self::assertAllowedChildren($conditionElement, ['KeyPrefixEquals', 'HttpErrorCodeReturnedEquals'], 'RoutingRule Condition');
                    $condition = [];
                    if (isset($conditionChildren['KeyPrefixEquals'])) {
                        $keyPrefix = self::scalarText($conditionChildren['KeyPrefixEquals'][0], 'KeyPrefixEquals');
                        self::assertWebsiteKeyField($keyPrefix, 'KeyPrefixEquals');
                        $condition['keyPrefixEquals'] = $keyPrefix;
                    }
                    if (isset($conditionChildren['HttpErrorCodeReturnedEquals'])) {
                        $rawErrorCode = self::scalarText($conditionChildren['HttpErrorCodeReturnedEquals'][0], 'HttpErrorCodeReturnedEquals');
                        if (! in_array($rawErrorCode, ['403', '404'], true)) {
                            throw new MalformedXmlException('HttpErrorCodeReturnedEquals must be a supported 403 or 404 status.');
                        }
                        $condition['httpErrorCodeReturnedEquals'] = (int) $rawErrorCode;
                    }
                    if ($condition === []) {
                        throw new MalformedXmlException('RoutingRule Condition requires KeyPrefixEquals or HttpErrorCodeReturnedEquals.');
                    }
                    $ruleData['condition'] = $condition;
                }

                $redirectChildren = self::assertAllowedChildren($redirectElement, ['ReplaceKeyPrefixWith', 'ReplaceKeyWith', 'Protocol', 'HostName', 'HttpRedirectCode'], 'RoutingRule Redirect');
                if ($redirectChildren === []) {
                    throw new MalformedXmlException('RoutingRule requires a non-empty Redirect.');
                }
                $redirect = [];
                if (isset($redirectChildren['ReplaceKeyPrefixWith'], $redirectChildren['ReplaceKeyWith'])) {
                    throw new MalformedXmlException('ReplaceKeyPrefixWith and ReplaceKeyWith cannot be combined.');
                }
                if (isset($redirectChildren['ReplaceKeyPrefixWith'])) {
                    $replacementPrefix = self::scalarText($redirectChildren['ReplaceKeyPrefixWith'][0], 'ReplaceKeyPrefixWith');
                    self::assertWebsiteKeyField($replacementPrefix, 'ReplaceKeyPrefixWith');
                    $redirect['replaceKeyPrefixWith'] = $replacementPrefix;
                }
                if (isset($redirectChildren['ReplaceKeyWith'])) {
                    $replacementKey = self::scalarText($redirectChildren['ReplaceKeyWith'][0], 'ReplaceKeyWith');
                    self::assertWebsiteKeyField($replacementKey, 'ReplaceKeyWith');
                    $redirect['replaceKeyWith'] = $replacementKey;
                }
                if (isset($redirectChildren['Protocol'])) {
                    $protocol = self::scalarText($redirectChildren['Protocol'][0], 'RoutingRule Protocol');
                    if (! in_array($protocol, ['http', 'https'], true)) {
                        throw new MalformedXmlException('Website redirect Protocol must be http or https.');
                    }
                    $redirect['protocol'] = $protocol;
                }
                if (isset($redirectChildren['HostName'])) {
                    $hostName = self::scalarText($redirectChildren['HostName'][0], 'RoutingRule HostName');
                    if (! WebsiteRedirectHost::isValid($hostName)) {
                        throw new MalformedXmlException('Website redirect requires a valid HostName.');
                    }
                    $redirect['hostName'] = $hostName;
                }
                if (isset($redirectChildren['HttpRedirectCode'])) {
                    $rawRedirectCode = self::scalarText($redirectChildren['HttpRedirectCode'][0], 'HttpRedirectCode');
                    if (! in_array($rawRedirectCode, ['301', '302', '303', '307', '308'], true)) {
                        throw new MalformedXmlException('HttpRedirectCode must be a supported redirect status.');
                    }
                    $redirect['httpRedirectCode'] = (int) $rawRedirectCode;
                }
                $ruleData['redirect'] = $redirect;

                $routingRules[] = $ruleData;
            }
            $result['routingRules'] = $routingRules;
        }

        return $result;
    }

    private static function assertWebsiteKeyField(string $value, string $field): void
    {
        if (strlen($value) > self::MAX_OBJECT_KEY_BYTES || ! XmlText::isValid($value)) {
            throw new MalformedXmlException(
                "{$field} must be valid XML 1.0 text of at most 1024 bytes.",
            );
        }
    }

    /**
     * @param list<string> $allowed
     * @param list<string> $repeatable
     * @return array<string, list<\SimpleXMLElement>>
     */
    private static function assertAllowedChildren(\SimpleXMLElement $element, array $allowed, string $context, array $repeatable = []): array
    {
        $seen = [];
        $namespace = dom_import_simplexml($element)->namespaceURI;
        foreach (S3XmlDocument::children($element) as $child) {
            $name = $child->getName();
            if (dom_import_simplexml($child)->namespaceURI !== $namespace
                || ! in_array($name, $allowed, true)
                || (isset($seen[$name]) && ! in_array($name, $repeatable, true))) {
                throw new MalformedXmlException("{$context} contains an unsupported or duplicate element.");
            }
            $seen[$name][] = $child;
        }

        return $seen;
    }

    private static function scalarText(\SimpleXMLElement $element, string $context): string
    {
        if (($element->xpath('./*') ?: []) !== []) {
            throw new MalformedXmlException("{$context} must contain text only.");
        }

        return (string) $element;
    }

    /**
     * Parse a RestoreObject request XML body.
     *
     * @return array{days: int, tier: string|null}
     *
     * @throws MalformedXmlException
     */
    public static function parseRestoreRequest(string $xml): array
    {
        $restore = self::parse($xml, 'RestoreRequest');
        $fields = self::assertAllowedChildren($restore, ['Days', 'GlacierJobParameters'], 'RestoreRequest');
        if (! isset($fields['Days'])) {
            throw new MalformedXmlException('RestoreRequest must contain Days.');
        }

        $rawDays = self::scalarText($fields['Days'][0], 'RestoreRequest Days');
        $days = self::positiveDecimalInt($rawDays);
        if ($days === null || $days > self::MAX_RESTORE_DAYS) {
            throw new MalformedXmlException(
                'RestoreRequest Days must be a positive integer no greater than ' . self::MAX_RESTORE_DAYS . '.',
            );
        }

        $tier = null;
        if (isset($fields['GlacierJobParameters'])) {
            $job = self::assertExactChildren($fields['GlacierJobParameters'][0], ['Tier'], 'GlacierJobParameters');
            $tier = self::scalarText($job['Tier'], 'Restore Tier');
            if (! in_array($tier, ['Expedited', 'Standard', 'Bulk'], true)) {
                throw new MalformedXmlException("Invalid restore Tier: {$tier}.");
            }
        }

        return ['days' => $days, 'tier' => $tier];
    }

    /**
     * Parse an XML string into a SimpleXMLElement.
     *
     * @throws MalformedXmlException
     */
    private static function parse(
        string $xml,
        string $expectedRoot,
        bool $allowAclGranteeType = false,
    ): \SimpleXMLElement {
        $element = SafeXmlParser::parse($xml);
        $namespace = dom_import_simplexml($element)->namespaceURI;
        if ($element->getName() !== $expectedRoot
            || ! in_array($namespace, [null, self::S3_XML_NAMESPACE], true)) {
            throw new MalformedXmlException("Expected {$expectedRoot} XML root element.");
        }

        S3XmlDocument::assertOnlyAttributes(
            $element,
            $allowAclGranteeType
                ? ['Grantee' => ['{http://www.w3.org/2001/XMLSchema-instance}type']]
                : [],
        );

        return S3XmlDocument::namespaceNeutral($element);
    }
}
