<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles ListObjects v1 (GET /{bucket} without list-type=2).
 *
 * Query parameters: prefix, delimiter, max-keys (default 1000), marker.
 * Uses marker-based pagination instead of continuation tokens.
 */
final class ListObjectsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());

        $prefix = $queryParams['prefix'] ?? null;
        $delimiter = $queryParams['delimiter'] ?? null;
        $maxKeys = isset($queryParams['max-keys']) ? max(0, min((int) $queryParams['max-keys'], 1000)) : 1000;
        $marker = $queryParams['marker'] ?? null;
        $encodingType = $queryParams['encoding-type'] ?? null;

        // V1 uses marker as startAfter.
        $result = $this->metadata->listObjects(
            bucket: $bucket,
            prefix: $prefix,
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            startAfter: $marker,
        );

        // Determine nextMarker: take the lexicographically greatest of the last
        // object key and the last common prefix so pagination doesn't skip entries.
        $nextMarker = null;
        if ($result->isTruncated) {
            $lastKey = count($result->objects) > 0
                ? $result->objects[count($result->objects) - 1]->key
                : null;
            $lastPrefix = count($result->commonPrefixes) > 0
                ? $result->commonPrefixes[count($result->commonPrefixes) - 1]
                : null;

            if ($lastKey !== null && $lastPrefix !== null) {
                $nextMarker = $lastKey > $lastPrefix ? $lastKey : $lastPrefix;
            } else {
                $nextMarker = $lastKey ?? $lastPrefix;
            }
        }

        /** @var array{name: string, prefix: string, marker: string, nextMarker?: string, maxKeys: int, delimiter?: string, isTruncated: bool, encodingType?: string, objects: list<\OpsFour\S3Server\Dto\ObjectInfo>, commonPrefixes: list<string>} $xmlParams */
        $xmlParams = array_filter([
            'name' => $result->name,
            'prefix' => $result->prefix,
            'marker' => $marker ?? '',
            'nextMarker' => $nextMarker,
            'maxKeys' => $result->maxKeys,
            'delimiter' => $result->delimiter,
            'isTruncated' => $result->isTruncated,
            'encodingType' => $encodingType,
            'objects' => $result->objects,
            'commonPrefixes' => $result->commonPrefixes,
        ], fn ($v) => $v !== null);

        $credential = $request->getAttribute('credential');
        if ($credential !== null) {
            $xmlParams['displayNameMap'] = [$credential->ownerId => $credential->displayName];
        }

        $xml = XmlResponseBuilder::listObjectsResult($xmlParams);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
