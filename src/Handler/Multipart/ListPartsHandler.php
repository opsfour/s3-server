<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Http\ListingQuery;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\PagedMultipartPartsStore;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use OpsFour\S3Server\Xml\XmlResponseBuilder;
use OpsFour\S3Server\Checksum\StoredCrc64Nvme;
use OpsFour\S3Server\Exception\InternalErrorException;

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
            throw new NoSuchBucketException();
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $queryParams['uploadId'] ?? '';
        $maxParts = ListingQuery::limit($queryParams, 'max-parts');
        $partNumberMarker = ListingQuery::marker($queryParams, 'part-number-marker');

        $upload = $this->metadata->getMultipartUpload($uploadId);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if (
            $upload['owner_id'] !== $ownerId
            && $bucketInfo->ownerId !== $ownerId
            && $request->getAttribute('s3.policyResult') !== 'Allow'
        ) {
            throw new NoSuchUploadException();
        }
        $request->setAttribute('s3.listPartsUploadOwner', $upload['owner_id']);
        $uploadGeneration = $upload['bucket_generation'] ?? '';
        if ($uploadGeneration !== $bucketInfo->generation && $upload['owner_id'] !== $bucketInfo->ownerId) {
            throw new NoSuchUploadException();
        }
        // Pre-migration uploads have no generation. Keep their owner-only drain
        // path, but never expose them to a later or delegated bucket owner.
        if ($uploadGeneration !== $bucketInfo->generation
            && ! ($uploadGeneration === '' && $upload['owner_id'] === $ownerId && $bucketInfo->ownerId === $ownerId)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload belongs to another bucket generation.');
        }

        if ($maxParts === 0 || $partNumberMarker >= 10000) {
            $page = [];
        } elseif ($this->metadata instanceof PagedMultipartPartsStore) {
            $page = $this->metadata->listPartsPage($uploadId, $partNumberMarker, $maxParts + 1);
        } else {
            // Preserve support for application-provided MetadataStore implementations.
            $page = array_values(array_filter(
                $this->metadata->getParts($uploadId),
                static fn(array $part): bool => $part['part_number'] > $partNumberMarker,
            ));
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        $isTruncated = $maxParts > 0 && count($page) > $maxParts;
        $parts = array_slice($page, 0, $maxParts);

        $nextPartNumberMarker = 0;
        if ($isTruncated && count($parts) > 0) {
            $nextPartNumberMarker = $parts[count($parts) - 1]['part_number'];
        }

        $checksumContract = MultipartChecksumContract::fromStored($upload['user_metadata']);
        $xmlParts = array_map(function (array $part) use ($checksumContract): array {
            $algorithm = $part['checksum_algorithm'] ?? null;
            $value = $part['checksum_value'] ?? null;
            if ($checksumContract !== null && ($algorithm !== $checksumContract->algorithm || ! is_string($value))) {
                throw new InternalErrorException('Stored CRC64NVME multipart part checksum is incomplete.');
            }
            if (is_string($algorithm) && strtolower($algorithm) === 'crc64nvme') {
                if ($algorithm !== 'crc64nvme' || ! is_string($value)) {
                    throw new InternalErrorException('Stored CRC64NVME multipart part checksum is incomplete.');
                }
                $value = StoredCrc64Nvme::requireCanonical($value);
            } elseif ($value !== null && $algorithm === null) {
                throw new InternalErrorException('Stored multipart part checksum is incomplete.');
            }

            return [
                'partNumber' => $part['part_number'],
                'lastModified' => rtrim(str_replace(' ', 'T', $part['created_at']), 'Z') . 'Z',
                'etag' => $part['etag'],
                'size' => $part['size'],
                'checksumAlgorithm' => $algorithm,
                'checksumValue' => $value,
            ];
        }, $parts);
        $ownershipSnapshot = MultipartDestinationOwnershipSnapshot::decode($upload['user_metadata']);
        if ($ownershipSnapshot !== null) {
            $ownershipSnapshot->assertConsistent($upload['owner_id'], $bucketInfo->ownerId);
        }
        $listedOwnerId = $ownershipSnapshot instanceof MultipartDestinationOwnershipSnapshot
            ? $ownershipSnapshot->ownerId
            : (BucketOwnership::isEnforced($this->metadata, $bucket) ? $bucketInfo->ownerId : $upload['owner_id']);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        $xml = XmlResponseBuilder::listPartsResult([
            'bucket' => $bucket,
            'key' => $key,
            'uploadId' => $uploadId,
            'partNumberMarker' => $partNumberMarker,
            'nextPartNumberMarker' => $nextPartNumberMarker,
            'maxParts' => $maxParts,
            'isTruncated' => $isTruncated,
            'initiatorId' => $upload['owner_id'],
            'ownerId' => $listedOwnerId,
            'storageClass' => $upload['user_metadata']['__mpu-storage-class'] ?? 'STANDARD',
            'checksumAlgorithm' => $checksumContract === null ? null : 'CRC64NVME',
            'checksumType' => $checksumContract?->type,
            'parts' => $xmlParts,
        ]);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
