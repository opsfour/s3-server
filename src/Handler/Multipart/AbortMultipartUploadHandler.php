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
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Event\S3Event;

final class AbortMultipartUploadHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?NotificationDispatcher $notifications = null,
        ?StorageTierRegistry $storageTiers = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

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

        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if ($upload['owner_id'] !== $ownerId) {
            throw new NoSuchUploadException();
        }

        $event = $this->notifications?->createEvent('s3:MultipartUpload:Aborted', $bucket, $key, ownerId: $ownerId)
            ?? new S3Event('s3:MultipartUpload:Aborted', $bucket, $key, ownerId: $ownerId);
        $parts = $this->metadata->transaction(function () use ($uploadId, $bucketInfo, $bucket, $key, $ownerId, $event): array {
            \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            $parts = MultipartCleanup::stage(
                $this->metadata,
                $bucket,
                $key,
                $uploadId,
                $ownerId,
                $this->storageTiers->defaultTier()->name,
            );
            $this->notifications?->enqueueWebhooks($event);

            return $parts;
        });
        MultipartCleanup::clean(
            $this->metadata,
            $this->storage,
            $bucket,
            $key,
            $uploadId,
            $this->storageTiers->defaultTier()->name,
            $parts,
        );
        $this->notifications?->dispatchInternalEvent($event);

        return new Response(status: 204);
    }
}
