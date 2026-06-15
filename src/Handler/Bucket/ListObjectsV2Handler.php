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
 * Handles ListObjectsV2 (GET /{bucket}?list-type=2).
 *
 * Query parameters: prefix, delimiter, max-keys (default 1000),
 * continuation-token, start-after, fetch-owner, encoding-type.
 */
final class ListObjectsV2Handler implements RequestHandler
{
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

        // Parse query parameters.
        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());

        $prefix = $queryParams['prefix'] ?? null;
        $delimiter = $queryParams['delimiter'] ?? null;
        $maxKeys = isset($queryParams['max-keys']) ? max(0, min((int) $queryParams['max-keys'], 1000)) : 1000;
        $continuationToken = $queryParams['continuation-token'] ?? null;
        $startAfter = $queryParams['start-after'] ?? null;
        $fetchOwner = isset($queryParams['fetch-owner']) && strtolower($queryParams['fetch-owner']) === 'true';
        $encodingType = $queryParams['encoding-type'] ?? null;

        // Delegate to metadata store.
        $result = $this->metadata->listObjects(
            bucket: $bucket,
            prefix: $prefix,
            delimiter: $delimiter,
            maxKeys: $maxKeys,
            startAfter: $startAfter,
            continuationToken: $continuationToken,
        );

        $credential = $request->getAttribute('credential');
        $displayNameMap = ($credential !== null) ? [$credential->ownerId => $credential->displayName] : [];

        $xml = XmlResponseBuilder::listObjectsV2Result($result, $fetchOwner, $encodingType, $displayNameMap);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
