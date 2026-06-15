<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class ListMultipartUploadsHandler implements RequestHandler
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
        $maxUploads = isset($queryParams['max-uploads']) ? max(0, min((int) $queryParams['max-uploads'], 1000)) : 1000;
        $keyMarker = $queryParams['key-marker'] ?? null;
        $uploadIdMarker = $queryParams['upload-id-marker'] ?? null;
        $encodingType = $queryParams['encoding-type'] ?? null;

        $result = $this->metadata->listMultipartUploads(
            $bucket,
            $prefix,
            $delimiter,
            $maxUploads,
            $keyMarker,
            $uploadIdMarker,
        );

        $xmlUploads = array_map(fn ($u) => [
            'key' => $u['key_name'],
            'uploadId' => $u['upload_id'],
            'initiated' => rtrim(str_replace(' ', 'T', $u['created_at']), 'Z') . 'Z',
            'ownerId' => $u['owner_id'],
        ], $result['uploads']);

        $xml = XmlResponseBuilder::listMultipartUploadsResult([
            'bucket' => $bucket,
            'prefix' => $prefix ?? '',
            'delimiter' => $delimiter,
            'keyMarker' => $keyMarker ?? '',
            'uploadIdMarker' => $uploadIdMarker ?? '',
            'nextKeyMarker' => $result['nextKeyMarker'],
            'nextUploadIdMarker' => $result['nextUploadIdMarker'],
            'maxUploads' => $maxUploads,
            'isTruncated' => $result['isTruncated'],
            'uploads' => $xmlUploads,
            'commonPrefixes' => $result['commonPrefixes'],
            'encodingType' => $encodingType,
        ]);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
