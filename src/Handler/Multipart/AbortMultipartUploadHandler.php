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
use OpsFour\S3Server\Metadata\MultipartWriteIntentStore;
use OpsFour\S3Server\Multipart\MultipartCleanup;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Notification\NotificationDispatcher;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Event\S3Event;

final class AbortMultipartUploadHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        StorageBackend $storage,
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
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        $policyAllowed = $request->hasAttribute('s3.policyResult')
            && $request->getAttribute('s3.policyResult') === 'Allow';
        if ($ownerId !== $upload['owner_id'] && $ownerId !== $bucketInfo->ownerId && !$policyAllowed) {
            throw new NoSuchUploadException();
        }
        $uploadGeneration = $upload['bucket_generation'] ?? '';
        if ($uploadGeneration !== $bucketInfo->generation && $upload['owner_id'] !== $bucketInfo->ownerId) {
            throw new NoSuchUploadException();
        }
        // Pre-migration uploads have no generation. Keep their owner-only drain
        // path, but never let a later or delegated bucket owner consume them.
        if ($uploadGeneration !== $bucketInfo->generation
            && ! ($uploadGeneration === '' && $upload['owner_id'] === $ownerId && $bucketInfo->ownerId === $ownerId)) {
            throw new \OpsFour\S3Server\Exception\OperationAbortedException('Multipart upload belongs to another bucket generation.');
        }
        $tier = MultipartUploadTierResolver::resolve($upload, $this->storageTiers);

        $event = $this->notifications?->createEvent('s3:MultipartUpload:Aborted', $bucket, $key, ownerId: $ownerId, bucketGeneration: $bucketInfo->generation)
            ?? new S3Event('s3:MultipartUpload:Aborted', $bucket, $key, ownerId: $ownerId);
        $uploadOwner = $upload['owner_id'];
        $parts = $this->metadata->transaction(function () use ($request, $uploadId, $bucketInfo, $bucket, $key, $ownerId, $uploadOwner, $uploadGeneration, $event, $tier): array {
            \OpsFour\S3Server\Metadata\OwnerWriteLock::acquire($this->metadata, $ownerId, $uploadOwner, $bucketInfo->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
            if ($ownerId !== $uploadOwner
                && $ownerId !== $bucketInfo->ownerId
                && (! $request->hasAttribute('s3.policyResult')
                    || $request->getAttribute('s3.policyResult') !== 'Allow')) {
                throw new NoSuchUploadException();
            }
            $parts = MultipartCleanup::stage(
                $this->metadata,
                $bucket,
                $key,
                $uploadId,
                $uploadOwner,
                $tier->name,
                $uploadGeneration,
            );
            if ($this->metadata instanceof MultipartWriteIntentStore) {
                $this->metadata->abandonMultipartWriteIntentsForUpload($uploadId, $this->metadata->multipartWriteIntentNow());
            }
            $this->notifications?->enqueueWebhooks($event);

            return $parts;
        });
        MultipartCleanup::clean(
            $this->metadata,
            $tier->backend,
            $bucket,
            $key,
            $uploadId,
            $tier->name,
            $parts,
        );
        $this->notifications?->dispatchInternalEvent($event);

        return new Response(status: 204);
    }
}
