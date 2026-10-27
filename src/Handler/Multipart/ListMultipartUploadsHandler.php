<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Http\ListingQuery;
use OpsFour\S3Server\Metadata\GenerationBoundMultipartListingStore;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Multipart\MultipartDestinationOwnershipSnapshot;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class ListMultipartUploadsHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $prefix = ListingQuery::xmlText($queryParams, 'prefix');
        $delimiter = ListingQuery::xmlText($queryParams, 'delimiter');
        $keyMarker = ListingQuery::xmlText($queryParams, 'key-marker');
        $uploadIdMarker = ListingQuery::xmlText($queryParams, 'upload-id-marker');
        $maxUploads = ListingQuery::limit($queryParams, 'max-uploads');
        $encodingType = ListingQuery::encodingType($queryParams);

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        if (! $this->metadata instanceof GenerationBoundMultipartListingStore) {
            throw new NotImplementedException('Generation-bound multipart listing is unavailable for this metadata backend.');
        }

        $result = $this->metadata->listMultipartUploadsForBucket(
            $bucketInfo,
            $prefix,
            $delimiter,
            $maxUploads,
            $keyMarker,
            $uploadIdMarker,
        );
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        $bucketOwnsObjects = BucketOwnership::isEnforced($this->metadata, $bucket);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        $xmlUploads = array_map(fn($u) => [
            'key' => $u['key_name'],
            'uploadId' => $u['upload_id'],
            'initiated' => rtrim(str_replace(' ', 'T', $u['created_at']), 'Z') . 'Z',
            'initiatorId' => $u['owner_id'],
            'ownerId' => self::destinationOwner(
                $u['user_metadata'] ?? null,
                $u['owner_id'],
                $bucketInfo->ownerId,
                $bucketOwnsObjects,
            ),
            'storageClass' => self::storageClass($u['user_metadata'] ?? null),
        ], $result['uploads']);

        $params = [
            'bucket' => $bucket,
            'prefix' => $prefix ?? '',
            'keyMarker' => $keyMarker ?? '',
            'uploadIdMarker' => $uploadIdMarker ?? '',
            'maxUploads' => $maxUploads,
            'isTruncated' => $result['isTruncated'],
            'uploads' => $xmlUploads,
            'commonPrefixes' => $result['commonPrefixes'],
        ];
        if ($delimiter !== null) {
            $params['delimiter'] = $delimiter;
        }
        if ($result['nextKeyMarker'] !== null) {
            $params['nextKeyMarker'] = $result['nextKeyMarker'];
        }
        if ($result['nextUploadIdMarker'] !== null) {
            $params['nextUploadIdMarker'] = $result['nextUploadIdMarker'];
        }
        if ($encodingType !== null) {
            $params['encodingType'] = $encodingType;
        }

        $xml = XmlResponseBuilder::listMultipartUploadsResult($params);

        return new Response(
            status: 200,
            headers: ['content-type' => 'application/xml'],
            body: $xml,
        );
    }

    private static function storageClass(?string $encodedMetadata): string
    {
        if ($encodedMetadata === null) {
            return 'STANDARD';
        }

        $metadata = json_decode($encodedMetadata, true);
        $storageClass = is_array($metadata) ? ($metadata['__mpu-storage-class'] ?? null) : null;

        return is_string($storageClass) && $storageClass !== '' ? $storageClass : 'STANDARD';
    }

    private static function destinationOwner(
        ?string $encodedMetadata,
        string $initiatorId,
        string $bucketOwnerId,
        bool $legacyBucketOwnsObjects,
    ): string {
        if ($encodedMetadata !== null) {
            try {
                $metadata = json_decode($encodedMetadata, true, 64, JSON_THROW_ON_ERROR);
            } catch (\JsonException $error) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException(
                    'Multipart upload metadata is invalid.',
                    $error,
                );
            }
            if (! is_array($metadata)) {
                throw new \OpsFour\S3Server\Exception\InternalErrorException('Multipart upload metadata is invalid.');
            }
            /** @var array<string, string> $metadata */
            $snapshot = MultipartDestinationOwnershipSnapshot::decode($metadata);
            if ($snapshot !== null) {
                $snapshot->assertConsistent($initiatorId, $bucketOwnerId);

                return $snapshot->ownerId;
            }
        }

        return $legacyBucketOwnsObjects ? $bucketOwnerId : $initiatorId;
    }
}
