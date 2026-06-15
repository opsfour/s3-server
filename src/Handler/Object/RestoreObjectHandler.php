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

        if ($this->metadata->getBucket($bucket) === null) {
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

        if ($object->restoreStatus === 'pending') {
            throw new OperationAbortedException('Restore is already in progress for this object.');
        }

        if ($object->restoreStatus === 'restored' && $object->restoreExpiresAt !== null && $object->restoreExpiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))) {
            return new Response(status: 200);
        }

        $body = \Amp\ByteStream\buffer($request->getBody());
        $restore = XmlRequestParser::parseRestoreRequest($body);
        $sourceStoragePath = $object->systemMetadata['storagePath'] ?? null;
        if ($sourceStoragePath === null || $sourceStoragePath === '') {
            throw new InvalidObjectStateException('Object storage path is missing.');
        }

        $this->metadata->transaction(function () use ($object, $sourceStoragePath, $restore): void {
            $this->metadata->enqueueRestoreJob(
                bucket: $object->bucket,
                key: $object->key,
                versionId: $object->versionId,
                sourceTier: $object->storageTier,
                sourceStoragePath: $sourceStoragePath,
                restoreDays: $restore['days'],
            );
            $this->metadata->updateObjectRestoreState(
                bucket: $object->bucket,
                key: $object->key,
                versionId: $object->versionId,
                restoreStatus: 'pending',
            );
        });

        return new Response(status: 202);
    }
}
