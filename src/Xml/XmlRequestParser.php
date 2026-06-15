<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

use OpsFour\S3Server\Exception\MalformedXmlException;

/**
 * Parses S3 XML request bodies using SimpleXMLElement.
 *
 * All methods throw MalformedXmlException on parse failure.
 */
final class XmlRequestParser
{
    /**
     * Parse a CreateBucketConfiguration XML body.
     *
     * @return array{locationConstraint: string}
     *
     * @throws MalformedXmlException
     */
    public static function parseCreateBucketConfiguration(string $xml): array
    {
        $element = self::parse($xml);

        $locationConstraint = '';
        if (isset($element->LocationConstraint)) {
            $locationConstraint = (string) $element->LocationConstraint;
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
        $element = self::parse($xml);

        $quiet = false;
        if (isset($element->Quiet)) {
            $quiet = strtolower((string) $element->Quiet) === 'true';
        }

        $objects = [];
        if (isset($element->Object)) {
            foreach ($element->Object as $obj) {
                $key = (string) ($obj->Key ?? '');
                if ($key === '') {
                    throw new MalformedXmlException('Each Object element must contain a Key element.');
                }

                $versionId = null;
                if (isset($obj->VersionId) && (string) $obj->VersionId !== '') {
                    $versionId = (string) $obj->VersionId;
                }

                $objects[] = [
                    'key' => $key,
                    'versionId' => $versionId,
                ];
            }
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
        $element = self::parse($xml);

        $parts = [];
        if (isset($element->Part)) {
            foreach ($element->Part as $part) {
                $partNumber = isset($part->PartNumber) ? (int) (string) $part->PartNumber : 0;
                $etag = isset($part->ETag) ? (string) $part->ETag : '';

                if ($partNumber < 1 || $etag === '') {
                    throw new MalformedXmlException('Each Part element must contain valid PartNumber and ETag elements.');
                }

                $parts[] = [
                    'partNumber' => $partNumber,
                    'etag' => $etag,
                ];
            }
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
        $element = self::parse($xml);

        $rules = [];
        if (isset($element->CORSRule)) {
            foreach ($element->CORSRule as $rule) {
                $allowedOrigins = [];
                if (isset($rule->AllowedOrigin)) {
                    foreach ($rule->AllowedOrigin as $origin) {
                        $allowedOrigins[] = (string) $origin;
                    }
                }

                $allowedMethods = [];
                if (isset($rule->AllowedMethod)) {
                    foreach ($rule->AllowedMethod as $method) {
                        $allowedMethods[] = (string) $method;
                    }
                }

                $allowedHeaders = [];
                if (isset($rule->AllowedHeader)) {
                    foreach ($rule->AllowedHeader as $header) {
                        $allowedHeaders[] = (string) $header;
                    }
                }

                $exposeHeaders = [];
                if (isset($rule->ExposeHeader)) {
                    foreach ($rule->ExposeHeader as $header) {
                        $exposeHeaders[] = (string) $header;
                    }
                }

                $maxAgeSeconds = null;
                if (isset($rule->MaxAgeSeconds)) {
                    $maxAgeSeconds = (int) (string) $rule->MaxAgeSeconds;
                }

                $rules[] = [
                    'allowedOrigins' => $allowedOrigins,
                    'allowedMethods' => $allowedMethods,
                    'allowedHeaders' => $allowedHeaders,
                    'exposeHeaders' => $exposeHeaders,
                    'maxAgeSeconds' => $maxAgeSeconds,
                ];
            }
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
        $element = self::parse($xml);

        $result = [
            'status' => '',
        ];

        if (isset($element->Status)) {
            $status = (string) $element->Status;
            if (! in_array($status, ['Enabled', 'Suspended'], true)) {
                throw new MalformedXmlException("Invalid versioning status: {$status}. Must be 'Enabled' or 'Suspended'.");
            }
            $result['status'] = $status;
        }

        if (isset($element->MfaDelete)) {
            $result['mfaDelete'] = (string) $element->MfaDelete;
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
        $element = self::parse($xml);

        $tags = [];

        if (isset($element->TagSet, $element->TagSet->Tag)) {
            foreach ($element->TagSet->Tag as $tag) {
                $key = isset($tag->Key) ? (string) $tag->Key : '';
                $value = isset($tag->Value) ? (string) $tag->Value : '';

                if ($key === '') {
                    throw new MalformedXmlException('Each Tag element must contain a non-empty Key element.');
                }

                $tags[] = [
                    'key' => $key,
                    'value' => $value,
                ];
            }
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
        $element = self::parse($xml);

        $result = [
            'objectLockEnabled' => 'Enabled',
        ];

        if (isset($element->ObjectLockEnabled)) {
            $result['objectLockEnabled'] = (string) $element->ObjectLockEnabled;
        }

        if (isset($element->Rule, $element->Rule->DefaultRetention)) {
            $retention = $element->Rule->DefaultRetention;
            $retentionResult = [
                'mode' => (string) ($retention->Mode ?? 'GOVERNANCE'),
            ];

            if (isset($retention->Days)) {
                $retentionResult['days'] = (int) (string) $retention->Days;
            }

            if (isset($retention->Years)) {
                $retentionResult['years'] = (int) (string) $retention->Years;
            }

            $result['rule'] = [
                'defaultRetention' => $retentionResult,
            ];
        }

        return $result;
    }

    /**
     * Parse a Retention XML body.
     *
     * @return array{mode: string, retainUntilDate: string}
     *
     * @throws MalformedXmlException
     */
    public static function parseRetention(string $xml): array
    {
        $element = self::parse($xml);

        $mode = isset($element->Mode) ? (string) $element->Mode : '';
        $retainUntilDate = isset($element->RetainUntilDate) ? (string) $element->RetainUntilDate : '';

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
        $element = self::parse($xml);

        $status = isset($element->Status) ? (string) $element->Status : '';

        if (! in_array($status, ['ON', 'OFF'], true)) {
            throw new MalformedXmlException("Invalid legal hold status: {$status}. Must be 'ON' or 'OFF'.");
        }

        return [
            'status' => $status,
        ];
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
        $element = self::parse($xml);

        $ownerId = '';
        if (isset($element->Owner, $element->Owner->ID)) {
            $ownerId = (string) $element->Owner->ID;
        }

        $grants = [];
        if (isset($element->AccessControlList, $element->AccessControlList->Grant)) {
            foreach ($element->AccessControlList->Grant as $grant) {
                $permission = isset($grant->Permission) ? (string) $grant->Permission : '';

                if ($permission === '') {
                    throw new MalformedXmlException('Each Grant element must contain a Permission element.');
                }

                $granteeType = 'CanonicalUser';
                $granteeId = '';

                if (isset($grant->Grantee)) {
                    $grantee = $grant->Grantee;

                    // Check xsi:type attribute for grantee type.
                    $attributes = $grantee->attributes('http://www.w3.org/2001/XMLSchema-instance');
                    if ($attributes !== null && isset($attributes['type'])) {
                        $type = (string) $attributes['type'];
                        if ($type === 'Group') {
                            $granteeType = 'Group';
                            $granteeId = isset($grantee->URI) ? (string) $grantee->URI : '';
                        } else {
                            $granteeType = 'CanonicalUser';
                            $granteeId = isset($grantee->ID) ? (string) $grantee->ID : '';
                        }
                    } else {
                        // Fallback: check for URI (Group) or ID (CanonicalUser).
                        if (isset($grantee->URI)) {
                            $granteeType = 'Group';
                            $granteeId = (string) $grantee->URI;
                        } elseif (isset($grantee->ID)) {
                            $granteeType = 'CanonicalUser';
                            $granteeId = (string) $grantee->ID;
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
        $element = self::parse($xml);

        $sseAlgorithm = 'AES256';
        $kmsMasterKeyId = null;
        $bucketKeyEnabled = false;

        if (isset($element->Rule)) {
            $rule = $element->Rule;
            if (isset($rule->ApplyServerSideEncryptionByDefault)) {
                $defaults = $rule->ApplyServerSideEncryptionByDefault;
                if (isset($defaults->SSEAlgorithm)) {
                    $sseAlgorithm = (string) $defaults->SSEAlgorithm;
                    if (!in_array($sseAlgorithm, ['AES256', 'aws:kms'], true)) {
                        throw new MalformedXmlException("Invalid SSEAlgorithm: {$sseAlgorithm}. Must be 'AES256' or 'aws:kms'.");
                    }
                }
                if (isset($defaults->KMSMasterKeyID)) {
                    $kmsMasterKeyId = (string) $defaults->KMSMasterKeyID;
                }
            }
            if (isset($rule->BucketKeyEnabled)) {
                $bucketKeyEnabled = strtolower((string) $rule->BucketKeyEnabled) === 'true';
            }
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
        $element = self::parse($xml);

        $rules = [];
        if (isset($element->Rule)) {
            foreach ($element->Rule as $rule) {
                $ruleData = [
                    'id' => isset($rule->ID) ? (string) $rule->ID : bin2hex(random_bytes(8)),
                    'status' => isset($rule->Status) ? (string) $rule->Status : 'Enabled',
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
                    $ruleData['prefix'] = (string) $rule->Prefix;
                }

                // Expiration
                if (isset($rule->Expiration)) {
                    $expiration = [];
                    if (isset($rule->Expiration->Days)) {
                        $expiration['days'] = (int) (string) $rule->Expiration->Days;
                    }
                    if (isset($rule->Expiration->Date)) {
                        $expiration['date'] = (string) $rule->Expiration->Date;
                    }
                    if (isset($rule->Expiration->ExpiredObjectDeleteMarker)) {
                        $expiration['expiredObjectDeleteMarker'] = strtolower((string) $rule->Expiration->ExpiredObjectDeleteMarker) === 'true';
                    }
                    $ruleData['expiration'] = $expiration;
                }

                // Transitions
                if (isset($rule->Transition)) {
                    $transitions = [];
                    foreach ($rule->Transition as $transition) {
                        $t = [];
                        if (isset($transition->Days)) {
                            $t['days'] = (int) (string) $transition->Days;
                        }
                        if (isset($transition->Date)) {
                            $t['date'] = (string) $transition->Date;
                        }
                        if (isset($transition->StorageClass)) {
                            $t['storageClass'] = (string) $transition->StorageClass;
                        }
                        $transitions[] = $t;
                    }
                    $ruleData['transitions'] = $transitions;
                }

                // NoncurrentVersionTransition
                if (isset($rule->NoncurrentVersionTransition)) {
                    $noncurrentTransitions = [];
                    foreach ($rule->NoncurrentVersionTransition as $nvt) {
                        $t = [];
                        if (isset($nvt->NoncurrentDays)) {
                            $t['noncurrentDays'] = (int) (string) $nvt->NoncurrentDays;
                        }
                        if (isset($nvt->StorageClass)) {
                            $t['storageClass'] = (string) $nvt->StorageClass;
                        }
                        $noncurrentTransitions[] = $t;
                    }
                    $ruleData['noncurrentTransitions'] = $noncurrentTransitions;
                }

                // NoncurrentVersionExpiration
                if (isset($rule->NoncurrentVersionExpiration)) {
                    $nce = [];
                    if (isset($rule->NoncurrentVersionExpiration->NoncurrentDays)) {
                        $nce['noncurrentDays'] = (int) (string) $rule->NoncurrentVersionExpiration->NoncurrentDays;
                    }
                    $ruleData['noncurrentExpiration'] = $nce;
                }

                // AbortIncompleteMultipartUpload
                if (isset($rule->AbortIncompleteMultipartUpload, $rule->AbortIncompleteMultipartUpload->DaysAfterInitiation)) {
                    $ruleData['abortIncompleteDays'] = (int) (string) $rule->AbortIncompleteMultipartUpload->DaysAfterInitiation;
                }

                $rules[] = $ruleData;
            }
        }

        return $rules;
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
        $element = self::parse($xml);

        $configs = [];

        // Parse TopicConfiguration
        if (isset($element->TopicConfiguration)) {
            foreach ($element->TopicConfiguration as $config) {
                $configs[] = self::parseNotificationEntry($config, 'Topic', 'Topic');
            }
        }

        // Parse QueueConfiguration
        if (isset($element->QueueConfiguration)) {
            foreach ($element->QueueConfiguration as $config) {
                $configs[] = self::parseNotificationEntry($config, 'Queue', 'Queue');
            }
        }

        // Parse CloudFunctionConfiguration
        if (isset($element->CloudFunctionConfiguration)) {
            foreach ($element->CloudFunctionConfiguration as $config) {
                $configs[] = self::parseNotificationEntry($config, 'CloudFunction', 'CloudFunction');
            }
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
        $id = isset($config->Id) ? (string) $config->Id : bin2hex(random_bytes(8));
        $destinationArn = isset($config->{$arnElement}) ? (string) $config->{$arnElement} : '';

        // Collect all <Event> elements (AWS allows multiple per config entry).
        $events = [];
        if (isset($config->Event)) {
            foreach ($config->Event as $event) {
                $eventStr = (string) $event;
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
        }

        if ($events === []) {
            throw new \OpsFour\S3Server\Exception\MalformedXmlException(
                'Each notification configuration must specify at least one Event.',
            );
        }

        $filterRules = null;
        if (isset($config->Filter, $config->Filter->S3Key, $config->Filter->S3Key->FilterRule)) {
            $filterRules = [];
            foreach ($config->Filter->S3Key->FilterRule as $filterRule) {
                $filterRules[] = [
                    'name' => isset($filterRule->Name) ? (string) $filterRule->Name : '',
                    'value' => isset($filterRule->Value) ? (string) $filterRule->Value : '',
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
        $element = self::parse($xml);

        $result = [
            'indexDocument' => 'index.html',
            'errorDocument' => null,
            'redirectAllHost' => null,
            'redirectAllProtocol' => null,
            'routingRules' => null,
        ];

        // RedirectAllRequestsTo takes precedence.
        if (isset($element->RedirectAllRequestsTo)) {
            $redirect = $element->RedirectAllRequestsTo;
            $result['redirectAllHost'] = isset($redirect->HostName) ? (string) $redirect->HostName : '';
            if (isset($redirect->Protocol)) {
                $result['redirectAllProtocol'] = (string) $redirect->Protocol;
            }

            return $result;
        }

        if (isset($element->IndexDocument, $element->IndexDocument->Suffix)) {
            $result['indexDocument'] = (string) $element->IndexDocument->Suffix;
        }

        if (isset($element->ErrorDocument, $element->ErrorDocument->Key)) {
            $result['errorDocument'] = (string) $element->ErrorDocument->Key;
        }

        if (isset($element->RoutingRules, $element->RoutingRules->RoutingRule)) {
            $routingRules = [];
            foreach ($element->RoutingRules->RoutingRule as $rule) {
                $ruleData = [];

                if (isset($rule->Condition)) {
                    $condition = [];
                    if (isset($rule->Condition->KeyPrefixEquals)) {
                        $condition['keyPrefixEquals'] = (string) $rule->Condition->KeyPrefixEquals;
                    }
                    if (isset($rule->Condition->HttpErrorCodeReturnedEquals)) {
                        $condition['httpErrorCodeReturnedEquals'] = (int) (string) $rule->Condition->HttpErrorCodeReturnedEquals;
                    }
                    $ruleData['condition'] = $condition;
                }

                if (isset($rule->Redirect)) {
                    $redirect = [];
                    if (isset($rule->Redirect->ReplaceKeyPrefixWith)) {
                        $redirect['replaceKeyPrefixWith'] = (string) $rule->Redirect->ReplaceKeyPrefixWith;
                    }
                    if (isset($rule->Redirect->ReplaceKeyWith)) {
                        $redirect['replaceKeyWith'] = (string) $rule->Redirect->ReplaceKeyWith;
                    }
                    if (isset($rule->Redirect->Protocol)) {
                        $redirect['protocol'] = (string) $rule->Redirect->Protocol;
                    }
                    if (isset($rule->Redirect->HostName)) {
                        $redirect['hostName'] = (string) $rule->Redirect->HostName;
                    }
                    if (isset($rule->Redirect->HttpRedirectCode)) {
                        $redirect['httpRedirectCode'] = (int) (string) $rule->Redirect->HttpRedirectCode;
                    }
                    $ruleData['redirect'] = $redirect;
                }

                $routingRules[] = $ruleData;
            }
            $result['routingRules'] = $routingRules;
        }

        return $result;
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
        $restore = self::parse($xml);

        if (! isset($restore->Days)) {
            throw new MalformedXmlException('RestoreRequest must contain Days.');
        }

        $days = (int) (string) $restore->Days;
        if ($days < 1) {
            throw new MalformedXmlException('RestoreRequest Days must be greater than zero.');
        }

        $tier = null;
        if (isset($restore->GlacierJobParameters, $restore->GlacierJobParameters->Tier)) {
            $tier = (string) $restore->GlacierJobParameters->Tier;
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
    private static function parse(string $xml): \SimpleXMLElement
    {
        if (trim($xml) === '') {
            throw new MalformedXmlException('Request body is empty.');
        }

        $previousUseErrors = libxml_use_internal_errors(true);
        libxml_clear_errors();

        try {
            // Strip DTD to prevent entity expansion (billion laughs / XXE).
            // Handles inline DTD subsets including nested brackets: <!DOCTYPE root [<!ENTITY ...> [...]]>
            $xml = preg_replace('/<!DOCTYPE\b[^[>]*(?:\[(?:[^\]]*|\[[^\]]*\])*\])?[^>]*>/si', '', $xml);
            if ($xml === null) {
                throw new \RuntimeException('Failed to sanitize XML.');
            }
            $element = new \SimpleXMLElement($xml, LIBXML_NONET);
        } catch (\Exception $e) {
            libxml_clear_errors();
            libxml_use_internal_errors($previousUseErrors);
            throw new MalformedXmlException(
                'The XML you provided was not well-formed or did not validate against our published schema.',
            );
        }

        $errors = libxml_get_errors();
        libxml_clear_errors();
        libxml_use_internal_errors($previousUseErrors);

        if ($errors !== []) {
            throw new MalformedXmlException(
                'The XML you provided was not well-formed or did not validate against our published schema.',
            );
        }

        return $element;
    }
}
