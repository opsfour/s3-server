<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Xml;

use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Dto\ListObjectsResult;
use OpsFour\S3Server\Dto\ObjectInfo;

/**
 * Builds S3 XML response bodies using XMLWriter.
 *
 * All methods return complete XML strings with the XML declaration
 * and S3 namespace where required.
 */
final class XmlResponseBuilder
{
    private const string S3_NAMESPACE = 'http://s3.amazonaws.com/doc/2006-03-01/';

    /**
     * Build an S3 error response XML.
     *
     * Delegates to ErrorResponseBuilder.
     */
    public static function error(string $code, string $message, string $resource, string $requestId): string
    {
        return ErrorResponseBuilder::build($code, $message, $resource, $requestId);
    }

    /**
     * Build a ListAllMyBucketsResult XML response.
     *
     * @param  list<BucketInfo>  $buckets  The list of buckets.
     * @param  string  $ownerId  The owner ID.
     * @param  string  $displayName  The owner display name.
     */
    public static function listBucketsResult(array $buckets, string $ownerId = '', string $displayName = ''): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'ListAllMyBucketsResult', self::S3_NAMESPACE);

        // Owner
        $writer->startElement('Owner');
        $writer->writeElement('ID', $ownerId);
        $writer->writeElement('DisplayName', $displayName);
        $writer->endElement();

        // Buckets
        $writer->startElement('Buckets');
        foreach ($buckets as $bucket) {
            $writer->startElement('Bucket');
            $writer->writeElement('Name', $bucket->name);
            $writer->writeElement('CreationDate', $bucket->creationDate->format('Y-m-d\TH:i:s.000\Z'));
            $writer->endElement();
        }
        $writer->endElement(); // Buckets

        $writer->endElement(); // ListAllMyBucketsResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a CopyObjectResult XML response.
     */
    public static function copyObjectResult(string $etag, string $lastModified): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'CopyObjectResult', self::S3_NAMESPACE);
        $writer->writeElement('ETag', $etag);
        $writer->writeElement('LastModified', $lastModified);
        $writer->endElement();

        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build an InitiateMultipartUploadResult XML response.
     */
    public static function initiateMultipartUploadResult(string $bucket, string $key, string $uploadId): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'InitiateMultipartUploadResult', self::S3_NAMESPACE);
        $writer->writeElement('Bucket', $bucket);
        $writer->writeElement('Key', self::safeXmlValue($key, false));
        $writer->writeElement('UploadId', $uploadId);
        $writer->endElement();

        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a CompleteMultipartUploadResult XML response.
     */
    public static function completeMultipartUploadResult(
        string $location,
        string $bucket,
        string $key,
        string $etag,
    ): string {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'CompleteMultipartUploadResult', self::S3_NAMESPACE);
        $writer->writeElement('Location', $location);
        $writer->writeElement('Bucket', $bucket);
        $writer->writeElement('Key', self::safeXmlValue($key, false));
        $writer->writeElement('ETag', $etag);
        $writer->endElement();

        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a ListBucketResult (V2) XML response.
     *
     * @param  bool  $fetchOwner  Whether to include Owner in Contents.
     * @param  string|null  $encodingType  When 'url', rawurlencode Key/Prefix/Delimiter.
     */
    /**
     * @param array<string, string> $displayNameMap Owner ID → display name lookup.
     */
    public static function listObjectsV2Result(ListObjectsResult $result, bool $fetchOwner = true, ?string $encodingType = null, array $displayNameMap = []): string
    {
        $writer = self::createWriter();
        $urlEncode = $encodingType === 'url';

        $writer->startElementNs(null, 'ListBucketResult', self::S3_NAMESPACE);

        $writer->writeElement('Name', $result->name);
        $writer->writeElement('Prefix', self::safeXmlValue($result->prefix, $urlEncode));
        $writer->writeElement('MaxKeys', (string) $result->maxKeys);
        $writer->writeElement('KeyCount', (string) $result->keyCount);
        $writer->writeElement('IsTruncated', $result->isTruncated ? 'true' : 'false');

        if ($result->delimiter !== null) {
            $writer->writeElement('Delimiter', self::safeXmlValue($result->delimiter, $urlEncode));
        }

        if ($result->startAfter !== null) {
            $writer->writeElement('StartAfter', self::safeXmlValue($result->startAfter, $urlEncode));
        }

        if ($result->continuationToken !== null) {
            $writer->writeElement('ContinuationToken', $result->continuationToken);
        }

        if ($result->nextContinuationToken !== null) {
            $writer->writeElement('NextContinuationToken', $result->nextContinuationToken);
        }

        if ($encodingType !== null) {
            $writer->writeElement('EncodingType', $encodingType);
        }

        foreach ($result->objects as $object) {
            self::writeContentsElement($writer, $object, $fetchOwner, $urlEncode, $displayNameMap);
        }

        foreach ($result->commonPrefixes as $prefix) {
            $writer->startElement('CommonPrefixes');
            $writer->writeElement('Prefix', self::safeXmlValue($prefix, $urlEncode));
            $writer->endElement();
        }

        $writer->endElement(); // ListBucketResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a ListBucketResult (V1) XML response.
     *
     * @param array{
     *     name: string,
     *     prefix: string,
     *     marker: string,
     *     nextMarker?: string,
     *     maxKeys: int,
     *     delimiter?: string,
     *     isTruncated: bool,
     *     encodingType?: string,
     *     objects: list<ObjectInfo>,
     *     commonPrefixes?: list<string>,
     * } $params
     */
    public static function listObjectsResult(array $params): string
    {
        $writer = self::createWriter();
        $urlEncode = ($params['encodingType'] ?? '') === 'url';

        $writer->startElementNs(null, 'ListBucketResult', self::S3_NAMESPACE);

        $writer->writeElement('Name', $params['name']);
        $writer->writeElement('Prefix', self::safeXmlValue($params['prefix'], $urlEncode));
        $writer->writeElement('Marker', self::safeXmlValue($params['marker'], $urlEncode));
        $writer->writeElement('MaxKeys', (string) $params['maxKeys']);
        $writer->writeElement('IsTruncated', $params['isTruncated'] ? 'true' : 'false');

        if (isset($params['delimiter'])) {
            $writer->writeElement('Delimiter', self::safeXmlValue($params['delimiter'], $urlEncode));
        }

        if (isset($params['nextMarker'])) {
            $writer->writeElement('NextMarker', self::safeXmlValue($params['nextMarker'], $urlEncode));
        }

        if (isset($params['encodingType']) && $params['encodingType'] !== '') {
            $writer->writeElement('EncodingType', $params['encodingType']);
        }

        foreach ($params['objects'] as $object) {
            self::writeContentsElement($writer, $object, true, $urlEncode, $params['displayNameMap'] ?? []);
        }

        foreach ($params['commonPrefixes'] ?? [] as $prefix) {
            $writer->startElement('CommonPrefixes');
            $writer->writeElement('Prefix', self::safeXmlValue($prefix, $urlEncode));
            $writer->endElement();
        }

        $writer->endElement(); // ListBucketResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a DeleteResult XML response.
     *
     * @param  list<array{key: string, versionId?: string, deleteMarker?: bool, deleteMarkerVersionId?: string}>  $deleted
     * @param  list<array{key: string, versionId?: string, code: string, message: string}>  $errors
     */
    public static function deleteResult(array $deleted, array $errors, bool $urlEncode = false): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'DeleteResult', self::S3_NAMESPACE);

        foreach ($deleted as $item) {
            $writer->startElement('Deleted');
            $writer->writeElement('Key', self::safeXmlValue($item['key'], $urlEncode));
            if (isset($item['versionId'])) {
                $writer->writeElement('VersionId', $item['versionId']);
            }
            if (isset($item['deleteMarker']) && $item['deleteMarker']) {
                $writer->writeElement('DeleteMarker', 'true');
            }
            if (isset($item['deleteMarkerVersionId'])) {
                $writer->writeElement('DeleteMarkerVersionId', $item['deleteMarkerVersionId']);
            }
            $writer->endElement();
        }

        foreach ($errors as $error) {
            $writer->startElement('Error');
            $writer->writeElement('Key', self::safeXmlValue($error['key'], $urlEncode));
            if (isset($error['versionId'])) {
                $writer->writeElement('VersionId', $error['versionId']);
            }
            $writer->writeElement('Code', $error['code']);
            $writer->writeElement('Message', $error['message']);
            $writer->endElement();
        }

        $writer->endElement(); // DeleteResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a ListPartsResult XML response.
     *
     * @param array{
     *     bucket: string,
     *     key: string,
     *     uploadId: string,
     *     storageClass?: string,
     *     partNumberMarker?: int,
     *     nextPartNumberMarker?: int,
     *     maxParts?: int,
     *     isTruncated?: bool,
     *     ownerId?: string,
     *     displayName?: string,
     *     parts: list<array{partNumber: int, lastModified: string, etag: string, size: int}>,
     * } $params
     */
    public static function listPartsResult(array $params): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'ListPartsResult', self::S3_NAMESPACE);

        $writer->writeElement('Bucket', $params['bucket']);
        $writer->writeElement('Key', self::safeXmlValue($params['key'], false));
        $writer->writeElement('UploadId', $params['uploadId']);

        // Initiator and Owner
        if (isset($params['ownerId'])) {
            $writer->startElement('Initiator');
            $writer->writeElement('ID', $params['ownerId']);
            $writer->writeElement('DisplayName', $params['displayName'] ?? '');
            $writer->endElement();

            $writer->startElement('Owner');
            $writer->writeElement('ID', $params['ownerId']);
            $writer->writeElement('DisplayName', $params['displayName'] ?? '');
            $writer->endElement();
        }

        $writer->writeElement('StorageClass', $params['storageClass'] ?? 'STANDARD');
        $writer->writeElement('PartNumberMarker', (string) ($params['partNumberMarker'] ?? 0));
        $writer->writeElement('NextPartNumberMarker', (string) ($params['nextPartNumberMarker'] ?? 0));
        $writer->writeElement('MaxParts', (string) ($params['maxParts'] ?? 1000));
        $writer->writeElement('IsTruncated', ($params['isTruncated'] ?? false) ? 'true' : 'false');

        foreach ($params['parts'] as $part) {
            $writer->startElement('Part');
            $writer->writeElement('PartNumber', (string) $part['partNumber']);
            $writer->writeElement('LastModified', $part['lastModified']);
            $writer->writeElement('ETag', $part['etag']);
            $writer->writeElement('Size', (string) $part['size']);
            $writer->endElement();
        }

        $writer->endElement(); // ListPartsResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a ListMultipartUploadsResult XML response.
     *
     * @param array{
     *     bucket: string,
     *     prefix?: string,
     *     delimiter?: string,
     *     keyMarker?: string,
     *     uploadIdMarker?: string,
     *     nextKeyMarker?: string,
     *     nextUploadIdMarker?: string,
     *     maxUploads?: int,
     *     isTruncated?: bool,
     *     encodingType?: string,
     *     uploads: list<array{key: string, uploadId: string, initiated: string, storageClass?: string, ownerId?: string, displayName?: string}>,
     *     commonPrefixes?: list<string>,
     * } $params
     */
    public static function listMultipartUploadsResult(array $params): string
    {
        $writer = self::createWriter();
        $urlEncode = ($params['encodingType'] ?? '') === 'url';

        $writer->startElementNs(null, 'ListMultipartUploadsResult', self::S3_NAMESPACE);

        $writer->writeElement('Bucket', $params['bucket']);
        $writer->writeElement('KeyMarker', self::safeXmlValue($params['keyMarker'] ?? '', $urlEncode));
        $writer->writeElement('UploadIdMarker', $params['uploadIdMarker'] ?? '');

        if (isset($params['nextKeyMarker'])) {
            $writer->writeElement('NextKeyMarker', self::safeXmlValue($params['nextKeyMarker'], $urlEncode));
        }
        if (isset($params['nextUploadIdMarker'])) {
            $writer->writeElement('NextUploadIdMarker', $params['nextUploadIdMarker']);
        }

        if ($urlEncode) {
            $writer->writeElement('EncodingType', 'url');
        }

        if (isset($params['prefix'])) {
            $writer->writeElement('Prefix', self::safeXmlValue($params['prefix'], $urlEncode));
        }

        if (isset($params['delimiter'])) {
            $writer->writeElement('Delimiter', self::safeXmlValue($params['delimiter'], $urlEncode));
        }

        $writer->writeElement('MaxUploads', (string) ($params['maxUploads'] ?? 1000));
        $writer->writeElement('IsTruncated', ($params['isTruncated'] ?? false) ? 'true' : 'false');

        foreach ($params['uploads'] as $upload) {
            $writer->startElement('Upload');
            $writer->writeElement('Key', self::safeXmlValue($upload['key'], $urlEncode));
            $writer->writeElement('UploadId', $upload['uploadId']);

            if (isset($upload['ownerId'])) {
                $writer->startElement('Initiator');
                $writer->writeElement('ID', $upload['ownerId']);
                $writer->writeElement('DisplayName', $upload['displayName'] ?? '');
                $writer->endElement();

                $writer->startElement('Owner');
                $writer->writeElement('ID', $upload['ownerId']);
                $writer->writeElement('DisplayName', $upload['displayName'] ?? '');
                $writer->endElement();
            }

            $writer->writeElement('StorageClass', $upload['storageClass'] ?? 'STANDARD');
            $writer->writeElement('Initiated', $upload['initiated']);
            $writer->endElement(); // Upload
        }

        foreach ($params['commonPrefixes'] ?? [] as $prefix) {
            $writer->startElement('CommonPrefixes');
            $writer->writeElement('Prefix', self::safeXmlValue($prefix, $urlEncode));
            $writer->endElement();
        }

        $writer->endElement(); // ListMultipartUploadsResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a VersioningConfiguration XML response.
     *
     * @param  string  $status  The versioning status ('Enabled', 'Suspended', or '' for never enabled).
     */
    public static function versioningConfiguration(string $status): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'VersioningConfiguration', self::S3_NAMESPACE);

        if ($status !== '') {
            $writer->writeElement('Status', $status);
        }

        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a ListVersionsResult XML response.
     *
     * @param array{
     *     name: string,
     *     prefix?: string,
     *     delimiter?: string,
     *     keyMarker?: string,
     *     versionIdMarker?: string,
     *     nextKeyMarker?: string,
     *     nextVersionIdMarker?: string,
     *     maxKeys?: int,
     *     isTruncated?: bool,
     *     encodingType?: string,
     *     versions: list<array{key: string, versionId: string, isLatest: bool, lastModified: string, etag: string, size: int, storageClass?: string, ownerId?: string, displayName?: string}>,
     *     deleteMarkers?: list<array{key: string, versionId: string, isLatest: bool, lastModified: string, ownerId?: string, displayName?: string}>,
     *     commonPrefixes?: list<string>,
     * } $params
     */
    public static function listObjectVersionsResult(array $params): string
    {
        $writer = self::createWriter();
        $urlEncode = ($params['encodingType'] ?? '') === 'url';

        $writer->startElementNs(null, 'ListVersionsResult', self::S3_NAMESPACE);

        $writer->writeElement('Name', $params['name']);
        $writer->writeElement('Prefix', self::safeXmlValue($params['prefix'] ?? '', $urlEncode));
        $writer->writeElement('KeyMarker', self::safeXmlValue($params['keyMarker'] ?? '', $urlEncode));
        $writer->writeElement('VersionIdMarker', $params['versionIdMarker'] ?? '');
        $writer->writeElement('MaxKeys', (string) ($params['maxKeys'] ?? 1000));
        $writer->writeElement('IsTruncated', ($params['isTruncated'] ?? false) ? 'true' : 'false');

        if (isset($params['delimiter'])) {
            $writer->writeElement('Delimiter', self::safeXmlValue($params['delimiter'], $urlEncode));
        }

        if (isset($params['nextKeyMarker'])) {
            $writer->writeElement('NextKeyMarker', self::safeXmlValue($params['nextKeyMarker'], $urlEncode));
        }

        if (isset($params['nextVersionIdMarker'])) {
            $writer->writeElement('NextVersionIdMarker', $params['nextVersionIdMarker']);
        }

        if ($urlEncode) {
            $writer->writeElement('EncodingType', 'url');
        }

        foreach ($params['versions'] as $version) {
            $writer->startElement('Version');
            $writer->writeElement('Key', self::safeXmlValue($version['key'], $urlEncode));
            $writer->writeElement('VersionId', $version['versionId']);
            $writer->writeElement('IsLatest', $version['isLatest'] ? 'true' : 'false');
            $writer->writeElement('LastModified', $version['lastModified']);
            $writer->writeElement('ETag', $version['etag']);
            $writer->writeElement('Size', (string) $version['size']);
            $writer->writeElement('StorageClass', $version['storageClass'] ?? 'STANDARD');

            if (isset($version['ownerId'])) {
                $writer->startElement('Owner');
                $writer->writeElement('ID', $version['ownerId']);
                $writer->writeElement('DisplayName', $version['displayName'] ?? '');
                $writer->endElement();
            }

            $writer->endElement(); // Version
        }

        foreach ($params['deleteMarkers'] ?? [] as $marker) {
            $writer->startElement('DeleteMarker');
            $writer->writeElement('Key', self::safeXmlValue($marker['key'], $urlEncode));
            $writer->writeElement('VersionId', $marker['versionId']);
            $writer->writeElement('IsLatest', $marker['isLatest'] ? 'true' : 'false');
            $writer->writeElement('LastModified', $marker['lastModified']);

            if (isset($marker['ownerId'])) {
                $writer->startElement('Owner');
                $writer->writeElement('ID', $marker['ownerId']);
                $writer->writeElement('DisplayName', $marker['displayName'] ?? '');
                $writer->endElement();
            }

            $writer->endElement(); // DeleteMarker
        }

        foreach ($params['commonPrefixes'] ?? [] as $prefix) {
            $writer->startElement('CommonPrefixes');
            $writer->writeElement('Prefix', self::safeXmlValue($prefix, $urlEncode));
            $writer->endElement();
        }

        $writer->endElement(); // ListVersionsResult
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build an ObjectLockConfiguration XML response.
     *
     * @param array{
     *     objectLockEnabled: string,
     *     rule?: array{
     *         defaultRetention: array{
     *             mode: string,
     *             days?: int,
     *             years?: int,
     *         },
     *     },
     * } $config
     */
    public static function objectLockConfiguration(array $config): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'ObjectLockConfiguration', self::S3_NAMESPACE);
        $writer->writeElement('ObjectLockEnabled', $config['objectLockEnabled']);

        if (isset($config['rule']['defaultRetention'])) {
            $retention = $config['rule']['defaultRetention'];
            $writer->startElement('Rule');
            $writer->startElement('DefaultRetention');
            $writer->writeElement('Mode', $retention['mode']);
            if (isset($retention['days'])) {
                $writer->writeElement('Days', (string) $retention['days']);
            }
            if (isset($retention['years'])) {
                $writer->writeElement('Years', (string) $retention['years']);
            }
            $writer->endElement(); // DefaultRetention
            $writer->endElement(); // Rule
        }

        $writer->endElement(); // ObjectLockConfiguration
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a Retention XML response.
     */
    public static function retention(string $mode, string $retainUntilDate): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'Retention', self::S3_NAMESPACE);
        $writer->writeElement('Mode', $mode);
        $writer->writeElement('RetainUntilDate', $retainUntilDate);
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a LegalHold XML response.
     */
    public static function legalHold(string $status): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'LegalHold', self::S3_NAMESPACE);
        $writer->writeElement('Status', $status);
        $writer->endElement();
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build an AccessControlPolicy XML response.
     *
     * @param  string  $ownerId  The bucket/object owner ID.
     * @param  string  $displayName  The owner display name.
     * @param  list<array{granteeType: string, granteeId: string, permission: string}>  $grants
     */
    /**
     * @param array<string, string> $displayNameMap Owner ID → display name lookup.
     */
    public static function aclResult(string $ownerId, string $displayName, array $grants, array $displayNameMap = []): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'AccessControlPolicy', self::S3_NAMESPACE);

        // Owner
        $writer->startElement('Owner');
        $writer->writeElement('ID', $ownerId);
        $writer->writeElement('DisplayName', $displayName);
        $writer->endElement();

        // AccessControlList
        $writer->startElement('AccessControlList');

        foreach ($grants as $grant) {
            $writer->startElement('Grant');

            $writer->startElement('Grantee');
            $writer->writeAttribute('xmlns:xsi', 'http://www.w3.org/2001/XMLSchema-instance');

            if ($grant['granteeType'] === 'Group') {
                $writer->writeAttribute('xsi:type', 'Group');
                $writer->writeElement('URI', $grant['granteeId']);
            } else {
                $writer->writeAttribute('xsi:type', 'CanonicalUser');
                $writer->writeElement('ID', $grant['granteeId']);
                $writer->writeElement('DisplayName', $displayNameMap[$grant['granteeId']] ?? $displayName);
            }

            $writer->endElement(); // Grantee

            $writer->writeElement('Permission', $grant['permission']);
            $writer->endElement(); // Grant
        }

        $writer->endElement(); // AccessControlList
        $writer->endElement(); // AccessControlPolicy
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a Tagging XML response.
     *
     * @param  list<array{key: string, value: string}>  $tags
     */
    public static function taggingResult(array $tags): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'Tagging', self::S3_NAMESPACE);
        $writer->startElement('TagSet');

        foreach ($tags as $tag) {
            $writer->startElement('Tag');
            $writer->writeElement('Key', $tag['key']);
            $writer->writeElement('Value', $tag['value']);
            $writer->endElement();
        }

        $writer->endElement(); // TagSet
        $writer->endElement(); // Tagging
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a CORSConfiguration XML response.
     *
     * @param  list<array{allowedOrigins: list<string>, allowedMethods: list<string>, allowedHeaders: list<string>, exposeHeaders: list<string>, maxAgeSeconds: int|null}>  $rules
     */
    public static function corsConfiguration(array $rules): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'CORSConfiguration', self::S3_NAMESPACE);

        foreach ($rules as $rule) {
            $writer->startElement('CORSRule');

            foreach ($rule['allowedOrigins'] as $origin) {
                $writer->writeElement('AllowedOrigin', $origin);
            }

            foreach ($rule['allowedMethods'] as $method) {
                $writer->writeElement('AllowedMethod', $method);
            }

            foreach ($rule['allowedHeaders'] as $header) {
                $writer->writeElement('AllowedHeader', $header);
            }

            foreach ($rule['exposeHeaders'] as $header) {
                $writer->writeElement('ExposeHeader', $header);
            }

            if ($rule['maxAgeSeconds'] !== null) {
                $writer->writeElement('MaxAgeSeconds', (string) $rule['maxAgeSeconds']);
            }

            $writer->endElement(); // CORSRule
        }

        $writer->endElement(); // CORSConfiguration
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a ServerSideEncryptionConfiguration XML response.
     *
     * @param  array{sseAlgorithm: string, kmsMasterKeyId?: string, bucketKeyEnabled?: bool}  $config
     */
    public static function encryptionConfiguration(array $config): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'ServerSideEncryptionConfiguration', self::S3_NAMESPACE);
        $writer->startElement('Rule');
        $writer->startElement('ApplyServerSideEncryptionByDefault');
        $writer->writeElement('SSEAlgorithm', $config['sseAlgorithm']);
        if (isset($config['kmsMasterKeyId'])) {
            $writer->writeElement('KMSMasterKeyID', $config['kmsMasterKeyId']);
        }
        $writer->endElement(); // ApplyServerSideEncryptionByDefault
        if (isset($config['bucketKeyEnabled']) && $config['bucketKeyEnabled']) {
            $writer->writeElement('BucketKeyEnabled', 'true');
        }
        $writer->endElement(); // Rule
        $writer->endElement(); // ServerSideEncryptionConfiguration
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a LifecycleConfiguration XML response.
     *
     * @param  list<array{id: string, status: string, prefix: ?string, filter: ?array<string, mixed>, transitions: ?array<int, mixed>, expiration: ?array<string, mixed>, noncurrentTransitions: ?array<int, mixed>, noncurrentExpiration: ?array<string, mixed>, abortIncompleteDays: ?int}>  $rules
     */
    public static function lifecycleConfiguration(array $rules): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'LifecycleConfiguration', self::S3_NAMESPACE);

        foreach ($rules as $rule) {
            $writer->startElement('Rule');
            $writer->writeElement('ID', $rule['id']);
            $writer->writeElement('Status', $rule['status']);

            // Filter or Prefix
            if (isset($rule['filter'])) {
                $writer->startElement('Filter');
                if (isset($rule['filter']['and'])) {
                    $writer->startElement('And');
                    if (isset($rule['filter']['and']['prefix'])) {
                        $writer->writeElement('Prefix', $rule['filter']['and']['prefix']);
                    }
                    foreach ($rule['filter']['and']['tags'] ?? [] as $tag) {
                        $writer->startElement('Tag');
                        $writer->writeElement('Key', $tag['key']);
                        $writer->writeElement('Value', $tag['value']);
                        $writer->endElement();
                    }
                    $writer->endElement(); // And
                } elseif (isset($rule['filter']['tag'])) {
                    $writer->startElement('Tag');
                    $writer->writeElement('Key', $rule['filter']['tag']['key']);
                    $writer->writeElement('Value', $rule['filter']['tag']['value']);
                    $writer->endElement();
                } elseif (isset($rule['filter']['prefix'])) {
                    $writer->writeElement('Prefix', $rule['filter']['prefix']);
                }
                $writer->endElement();
            } elseif (isset($rule['prefix'])) {
                $writer->writeElement('Prefix', $rule['prefix']);
            }

            // Expiration
            if (isset($rule['expiration'])) {
                $writer->startElement('Expiration');
                if (isset($rule['expiration']['days'])) {
                    $writer->writeElement('Days', (string) $rule['expiration']['days']);
                }
                if (isset($rule['expiration']['date'])) {
                    $writer->writeElement('Date', $rule['expiration']['date']);
                }
                if (isset($rule['expiration']['expiredObjectDeleteMarker'])) {
                    $writer->writeElement('ExpiredObjectDeleteMarker', $rule['expiration']['expiredObjectDeleteMarker'] ? 'true' : 'false');
                }
                $writer->endElement();
            }

            // Transitions
            if (isset($rule['transitions'])) {
                foreach ($rule['transitions'] as $transition) {
                    $writer->startElement('Transition');
                    if (isset($transition['days'])) {
                        $writer->writeElement('Days', (string) $transition['days']);
                    }
                    if (isset($transition['date'])) {
                        $writer->writeElement('Date', $transition['date']);
                    }
                    if (isset($transition['storageClass'])) {
                        $writer->writeElement('StorageClass', $transition['storageClass']);
                    }
                    $writer->endElement();
                }
            }

            // NoncurrentVersionTransition
            if (isset($rule['noncurrentTransitions'])) {
                foreach ($rule['noncurrentTransitions'] as $nvt) {
                    $writer->startElement('NoncurrentVersionTransition');
                    if (isset($nvt['noncurrentDays'])) {
                        $writer->writeElement('NoncurrentDays', (string) $nvt['noncurrentDays']);
                    }
                    if (isset($nvt['storageClass'])) {
                        $writer->writeElement('StorageClass', $nvt['storageClass']);
                    }
                    $writer->endElement();
                }
            }

            // NoncurrentVersionExpiration
            if (isset($rule['noncurrentExpiration'])) {
                $writer->startElement('NoncurrentVersionExpiration');
                if (isset($rule['noncurrentExpiration']['noncurrentDays'])) {
                    $writer->writeElement('NoncurrentDays', (string) $rule['noncurrentExpiration']['noncurrentDays']);
                }
                $writer->endElement();
            }

            // AbortIncompleteMultipartUpload
            if (isset($rule['abortIncompleteDays'])) {
                $writer->startElement('AbortIncompleteMultipartUpload');
                $writer->writeElement('DaysAfterInitiation', (string) $rule['abortIncompleteDays']);
                $writer->endElement();
            }

            $writer->endElement(); // Rule
        }

        $writer->endElement(); // LifecycleConfiguration
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a NotificationConfiguration XML response.
     *
     * @param  list<array{id: string, events: list<string>, destinationType: string, destinationArn: string, filterRules: ?array<int, array{name: string, value: string}>}>  $configs
     */
    public static function notificationConfiguration(array $configs): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'NotificationConfiguration', self::S3_NAMESPACE);

        foreach ($configs as $config) {
            $elementName = match ($config['destinationType']) {
                'Topic' => 'TopicConfiguration',
                'Queue' => 'QueueConfiguration',
                'CloudFunction', 'Lambda' => 'CloudFunctionConfiguration',
                default => 'TopicConfiguration',
            };

            $writer->startElement($elementName);
            $writer->writeElement('Id', $config['id']);

            $arnElement = match ($config['destinationType']) {
                'Topic' => 'Topic',
                'Queue' => 'Queue',
                'CloudFunction', 'Lambda' => 'CloudFunction',
                default => 'Topic',
            };
            $writer->writeElement($arnElement, $config['destinationArn']);

            foreach ($config['events'] as $event) {
                $writer->writeElement('Event', $event);
            }

            if (isset($config['filterRules']) && ! empty($config['filterRules'])) {
                $writer->startElement('Filter');
                $writer->startElement('S3Key');
                foreach ($config['filterRules'] as $filterRule) {
                    $writer->startElement('FilterRule');
                    $writer->writeElement('Name', $filterRule['name']);
                    $writer->writeElement('Value', $filterRule['value']);
                    $writer->endElement();
                }
                $writer->endElement(); // S3Key
                $writer->endElement(); // Filter
            }

            $writer->endElement(); // TopicConfiguration/QueueConfiguration/CloudFunctionConfiguration
        }

        $writer->endElement(); // NotificationConfiguration
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Build a WebsiteConfiguration XML response.
     *
     * @param  array{indexDocument: string, errorDocument: ?string, redirectAllHost: ?string, redirectAllProtocol: ?string, routingRules: ?list<array<string, mixed>>}  $config
     */
    public static function websiteConfiguration(array $config): string
    {
        $writer = self::createWriter();

        $writer->startElementNs(null, 'WebsiteConfiguration', self::S3_NAMESPACE);

        if (isset($config['redirectAllHost'])) {
            $writer->startElement('RedirectAllRequestsTo');
            $writer->writeElement('HostName', $config['redirectAllHost']);
            if (isset($config['redirectAllProtocol'])) {
                $writer->writeElement('Protocol', $config['redirectAllProtocol']);
            }
            $writer->endElement();
        } else {
            $writer->startElement('IndexDocument');
            $writer->writeElement('Suffix', $config['indexDocument']);
            $writer->endElement();

            if (isset($config['errorDocument'])) {
                $writer->startElement('ErrorDocument');
                $writer->writeElement('Key', $config['errorDocument']);
                $writer->endElement();
            }

            if (isset($config['routingRules']) && ! empty($config['routingRules'])) {
                $writer->startElement('RoutingRules');
                foreach ($config['routingRules'] as $rule) {
                    $writer->startElement('RoutingRule');

                    if (isset($rule['condition'])) {
                        $writer->startElement('Condition');
                        if (isset($rule['condition']['keyPrefixEquals'])) {
                            $writer->writeElement('KeyPrefixEquals', $rule['condition']['keyPrefixEquals']);
                        }
                        if (isset($rule['condition']['httpErrorCodeReturnedEquals'])) {
                            $writer->writeElement('HttpErrorCodeReturnedEquals', (string) $rule['condition']['httpErrorCodeReturnedEquals']);
                        }
                        $writer->endElement();
                    }

                    if (isset($rule['redirect'])) {
                        $writer->startElement('Redirect');
                        if (isset($rule['redirect']['replaceKeyPrefixWith'])) {
                            $writer->writeElement('ReplaceKeyPrefixWith', $rule['redirect']['replaceKeyPrefixWith']);
                        }
                        if (isset($rule['redirect']['replaceKeyWith'])) {
                            $writer->writeElement('ReplaceKeyWith', $rule['redirect']['replaceKeyWith']);
                        }
                        if (isset($rule['redirect']['protocol'])) {
                            $writer->writeElement('Protocol', $rule['redirect']['protocol']);
                        }
                        if (isset($rule['redirect']['hostName'])) {
                            $writer->writeElement('HostName', $rule['redirect']['hostName']);
                        }
                        if (isset($rule['redirect']['httpRedirectCode'])) {
                            $writer->writeElement('HttpRedirectCode', (string) $rule['redirect']['httpRedirectCode']);
                        }
                        $writer->endElement();
                    }

                    $writer->endElement(); // RoutingRule
                }
                $writer->endElement(); // RoutingRules
            }
        }

        $writer->endElement(); // WebsiteConfiguration
        $writer->endDocument();

        return $writer->outputMemory();
    }

    /**
     * Write a Contents element for an object in a listing.
     */
    /**
     * @param array<string, string> $displayNameMap Owner ID → display name lookup.
     */
    private static function writeContentsElement(\XMLWriter $writer, ObjectInfo $object, bool $includeOwner = true, bool $urlEncode = false, array $displayNameMap = []): void
    {
        $writer->startElement('Contents');
        $writer->writeElement('Key', self::safeXmlValue($object->key, $urlEncode));
        $writer->writeElement('LastModified', $object->lastModified->format('Y-m-d\TH:i:s.000\Z'));
        $writer->writeElement('ETag', $object->etag);
        $writer->writeElement('Size', (string) $object->size);
        $writer->writeElement('StorageClass', $object->storageClass);

        if ($includeOwner && $object->ownerId !== '') {
            $writer->startElement('Owner');
            $writer->writeElement('ID', $object->ownerId);
            $writer->writeElement('DisplayName', $displayNameMap[$object->ownerId] ?? '');
            $writer->endElement();
        }

        $writer->endElement(); // Contents
    }

    /**
     * Create a new XMLWriter configured for S3 XML output.
     */
    private static function createWriter(): \XMLWriter
    {
        $writer = new \XMLWriter;
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');

        return $writer;
    }

    /**
     * Encode a string for safe inclusion in XML output.
     *
     * When $urlEncode is true, uses AWS S3-compatible percent-encoding:
     * rawurlencode() but preserving '/' and '*' unencoded (matching AWS behavior).
     * Otherwise, invalid UTF-8 bytes are stripped to prevent XML parse errors.
     */
    private static function safeXmlValue(string $value, bool $urlEncode): string
    {
        if ($urlEncode) {
            // AWS S3 encoding-type=url preserves '/' (path separator) and '*' (wildcard).
            return str_replace(['%2F', '%2A'], ['/', '*'], rawurlencode($value));
        }

        // Strip invalid UTF-8 sequences to prevent XML parse errors.
        // mb_convert_encoding with substitute character replaces invalid bytes.
        if (!mb_check_encoding($value, 'UTF-8')) {
            return mb_convert_encoding($value, 'UTF-8', 'UTF-8');
        }

        return $value;
    }
}
