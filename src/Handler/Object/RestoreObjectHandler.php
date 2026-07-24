<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Xml\XmlRequestParser;

final class RestoreObjectHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $storageTiers,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = (string) $request->getAttribute('s3.bucket');
        $key = (string) $request->getAttribute('s3.key');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $queryString = $request->getUri()->getQuery();
        $queryParams = $queryString !== '' ? QueryStringParser::parse($queryString) : [];
        $versionId = $queryParams['versionId'] ?? null;
        $object = $versionId !== null
            ? $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId)
            : $this->metadata->getObjectMetadata($bucket, $key);

        if ($object === null || $object->isDeleteMarker) {
            throw new NoSuchKeyException();
        }

        $tier = $this->storageTiers->has($object->storageTier)
            ? $this->storageTiers->tier($object->storageTier)
            : null;
        if ($tier === null || ! $tier->restoreRequired) {
            throw new InvalidObjectStateException('RestoreObject is only valid for objects in restore-required storage tiers.');
        }

        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 65_536);
        $restore = XmlRequestParser::parseRestoreRequest($body);
        $alreadyRestored = $this->metadata->transaction(function () use ($bucketInfo, $bucket, $key, $versionId, $object, $restore): bool {
            OwnerWriteLock::acquire($this->metadata, $bucketInfo->ownerId, $object->ownerId);
            $current = $versionId !== null
                ? $this->metadata->getObjectMetadataByVersion($bucket, $key, $versionId)
                : $this->metadata->getObjectMetadata($bucket, $key);
            if ($current === null || $current->isDeleteMarker) {
                throw new NoSuchKeyException();
            }

            $tier = $this->storageTiers->has($current->storageTier)
                ? $this->storageTiers->tier($current->storageTier)
                : null;
            if ($tier === null || ! $tier->restoreRequired) {
                throw new InvalidObjectStateException('RestoreObject is only valid for objects in restore-required storage tiers.');
            }
            if ($current->restoreStatus === 'pending') {
                throw new OperationAbortedException('Restore is already in progress for this object.');
            }
            if (
                $current->restoreStatus === 'restored'
                && $current->restoreExpiresAt !== null
                && $current->restoreExpiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                return true;
            }

            $sourceStoragePath = $current->systemMetadata['storagePath'] ?? null;
            if ($sourceStoragePath === null || $sourceStoragePath === '') {
                throw new InvalidObjectStateException('Object storage path is missing.');
            }

            $this->metadata->enqueueRestoreJob(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $current->versionId,
                sourceTier: $current->storageTier,
                sourceStoragePath: $sourceStoragePath,
                restoreDays: $restore['days'],
            );
            $this->metadata->updateObjectRestoreState(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $current->versionId,
                restoreStatus: 'pending',
            );

            return false;
        });

        return new Response(status: $alreadyRestored ? 200 : 202);
    }
}
