<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class ListPartsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException;
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $queryParams['uploadId'] ?? '';
        $maxParts = isset($queryParams['max-parts']) ? max(0, min((int) $queryParams['max-parts'], 1000)) : 1000;
        $partNumberMarker = isset($queryParams['part-number-marker']) ? (int) $queryParams['part-number-marker'] : 0;

        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException;
        }
        if (($upload['owner_id'] ?? '') !== $ownerId) {
            throw new NoSuchUploadException;
        }

        $allParts = $this->metadata->getParts($uploadId);

        // Filter by part number marker.
        $filteredParts = array_values(array_filter(
            $allParts,
            fn ($p) => $p['part_number'] > $partNumberMarker,
        ));

        $isTruncated = count($filteredParts) > $maxParts;
        $parts = array_slice($filteredParts, 0, $maxParts);

        $nextPartNumberMarker = 0;
        if ($isTruncated && count($parts) > 0) {
            $nextPartNumberMarker = $parts[count($parts) - 1]['part_number'];
        }

        $xmlParts = array_map(fn ($p) => [
            'partNumber' => $p['part_number'],
            'lastModified' => rtrim(str_replace(' ', 'T', $p['created_at']), 'Z') . 'Z',
            'etag' => $p['etag'],
            'size' => $p['size'],
        ], $parts);

        $xml = XmlResponseBuilder::listPartsResult([
            'bucket' => $bucket,
            'key' => $key,
            'uploadId' => $uploadId,
            'partNumberMarker' => $partNumberMarker,
            'nextPartNumberMarker' => $nextPartNumberMarker,
            'maxParts' => $maxParts,
            'isTruncated' => $isTruncated,
            'ownerId' => $ownerId,
            'parts' => $xmlParts,
        ]);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
