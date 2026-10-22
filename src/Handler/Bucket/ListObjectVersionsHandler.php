<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\ListingQuery;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles ListObjectVersions (GET /{bucket}?versions).
 *
 * Lists all versions of all objects in the bucket, including
 * delete markers. Supports prefix, delimiter, key-marker,
 * version-id-marker, and max-keys query parameters.
 */
final class ListObjectVersionsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $prefix = ListingQuery::xmlText($queryParams, 'prefix');
        $delimiter = ListingQuery::xmlText($queryParams, 'delimiter');
        $keyMarker = ListingQuery::xmlText($queryParams, 'key-marker');
        $versionIdMarker = ListingQuery::xmlText($queryParams, 'version-id-marker');
        $maxKeys = ListingQuery::limit($queryParams, 'max-keys');
        $encodingType = ListingQuery::encodingType($queryParams);
        if ($versionIdMarker !== null && ($keyMarker === null || $keyMarker === '')) {
            throw new InvalidArgumentException('version-id-marker requires key-marker.');
        }

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        // Delegate to metadata store.
        $result = $this->metadata->listObjectVersions(
            bucket: $bucket,
            prefix: $prefix,
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            keyMarker: $keyMarker,
            versionIdMarker: $versionIdMarker,
        );
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        $credential = $request->getAttribute('credential');
        $displayNameMap = ($credential !== null) ? [$credential->ownerId => $credential->displayName] : [];

        // Build version entries for XML.
        $versions = [];
        foreach ($result['versions'] as $info) {
            $versions[] = [
                'key' => $info->key,
                'versionId' => $info->versionId ?? 'null',
                'isLatest' => $info->systemMetadata['isLatest'] ?? false,
                'lastModified' => $info->lastModified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'),
                'etag' => $info->etag,
                'size' => $info->size,
                'storageClass' => $info->storageClass,
                'ownerId' => $info->ownerId,
                'displayName' => $displayNameMap[$info->ownerId] ?? '',
            ];
        }

        $deleteMarkers = [];
        foreach ($result['deleteMarkers'] as $info) {
            $deleteMarkers[] = [
                'key' => $info->key,
                'versionId' => $info->versionId ?? 'null',
                'isLatest' => $info->systemMetadata['isLatest'] ?? false,
                'lastModified' => $info->lastModified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'),
                'ownerId' => $info->ownerId,
                'displayName' => $displayNameMap[$info->ownerId] ?? '',
            ];
        }

        $entries = null;
        foreach ($result['entries'] ?? [] as $info) {
            $entries ??= [];
            if ($info->isDeleteMarker) {
                $entries[] = [
                    'type' => 'DeleteMarker',
                    'key' => $info->key,
                    'versionId' => $info->versionId ?? 'null',
                    'isLatest' => $info->systemMetadata['isLatest'] ?? false,
                    'lastModified' => $info->lastModified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'),
                    'ownerId' => $info->ownerId,
                    'displayName' => $displayNameMap[$info->ownerId] ?? '',
                ];
            } else {
                $entries[] = [
                    'type' => 'Version',
                    'key' => $info->key,
                    'versionId' => $info->versionId ?? 'null',
                    'isLatest' => $info->systemMetadata['isLatest'] ?? false,
                    'lastModified' => $info->lastModified->setTimezone(new \DateTimeZone('UTC'))->format('Y-m-d\TH:i:s.000\Z'),
                    'etag' => $info->etag,
                    'size' => $info->size,
                    'storageClass' => $info->storageClass,
                    'ownerId' => $info->ownerId,
                    'displayName' => $displayNameMap[$info->ownerId] ?? '',
                ];
            }
        }

        /** @var array{name: string, prefix?: string, delimiter?: string, keyMarker?: string, versionIdMarker?: string, nextKeyMarker?: string, nextVersionIdMarker?: string, maxKeys?: int, isTruncated?: bool, versions: list<array{key: string, versionId: string, isLatest: bool, lastModified: string, etag: string, size: int, storageClass?: string, ownerId?: string}>, deleteMarkers?: list<array{key: string, versionId: string, isLatest: bool, lastModified: string, ownerId?: string}>, commonPrefixes?: list<string>} $xmlParams */
        $xmlParams = array_filter([
            'name' => $bucket,
            'prefix' => $prefix ?? '',
            'delimiter' => $delimiter,
            'keyMarker' => $keyMarker ?? '',
            'versionIdMarker' => $versionIdMarker ?? '',
            'maxKeys' => $maxKeys,
            'isTruncated' => $result['isTruncated'],
            'nextKeyMarker' => $result['nextKeyMarker'],
            'nextVersionIdMarker' => $result['nextVersionIdMarker'],
            'versions' => $versions,
            'deleteMarkers' => $deleteMarkers,
            'entries' => $entries,
            'commonPrefixes' => $result['commonPrefixes'],
            'encodingType' => $encodingType,
        ], fn($v) => $v !== null);
        $xml = XmlResponseBuilder::listObjectVersionsResult($xmlParams);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
