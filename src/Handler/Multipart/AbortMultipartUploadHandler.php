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
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\StorageBackend;

final class AbortMultipartUploadHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?NotificationDispatcher $notifications = null,
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

        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException;
        }
        if (($upload['owner_id'] ?? '') !== $ownerId) {
            throw new NoSuchUploadException;
        }

        // Best-effort storage cleanup first, then unconditionally remove metadata.
        // If storage cleanup fails, the upload record must still be removed so the
        // upload doesn't become permanently stuck.
        try { $this->storage->abortMultipartUpload($bucket, $key, $uploadId); } catch (\Throwable) {}

        $this->metadata->deleteMultipartUpload($uploadId);
        $this->notifications?->dispatch('s3:MultipartUpload:Aborted', $bucket, $key, 0, '', $ownerId);

        return new Response(status: 204);
    }
}
