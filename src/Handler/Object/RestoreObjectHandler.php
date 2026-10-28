<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Exception\RestoreAlreadyInProgressException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Metadata\RestoredCopyTierStore;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Storage\RestoredCopyTierResolver;
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
        $ownerId = (string) $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        try {
            $object = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
        } catch (NoSuchKeyException|NoSuchVersionException $error) {
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            throw $error;
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        $tier = $this->storageTiers->has($object->storageTier)
            ? $this->storageTiers->tier($object->storageTier)
            : null;
        if ($tier === null || ! $tier->restoreRequired) {
            throw new InvalidObjectStateException('RestoreObject is only valid for objects in restore-required storage tiers.');
        }

        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request, 65_536);
        $restore = XmlRequestParser::parseRestoreRequest($body);
        if ($restore['tier'] !== null && $restore['tier'] !== 'Standard') {
            throw new NotImplementedException(
                "Restore retrieval tier {$restore['tier']} is not supported; use Standard.",
            );
        }
        $alreadyRestored = $this->metadata->transaction(function () use ($request, $bucketInfo, $bucket, $key, $object, $restore, $ownerId): bool {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId, $object->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            (new PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
            $current = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);

            $tier = $this->storageTiers->has($current->storageTier)
                ? $this->storageTiers->tier($current->storageTier)
                : null;
            if ($tier === null || ! $tier->restoreRequired) {
                throw new InvalidObjectStateException('RestoreObject is only valid for objects in restore-required storage tiers.');
            }
            if ($current->restoreStatus === 'pending') {
                throw new RestoreAlreadyInProgressException();
            }
            if (
                $current->restoreStatus === 'restored'
                && $current->restoreExpiresAt !== null
                && $current->restoreExpiresAt > new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                if (! $this->metadata instanceof RestoredCopyTierStore
                    || $current->restoredStoragePath === null) {
                    throw new InvalidObjectStateException('Restored object copy has incomplete physical metadata.');
                }
                $restoredTier = RestoredCopyTierResolver::resolve($current, $this->storageTiers);
                $requestedExpiresAt = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
                    ->modify('+' . $restore['days'] . ' days');
                $expiresAt = $current->restoreExpiresAt > $requestedExpiresAt
                    ? $current->restoreExpiresAt
                    : $requestedExpiresAt;
                $this->metadata->updateObjectRestoreStateWithTier(
                    bucket: $current->bucket,
                    key: $current->key,
                    versionId: $current->versionId ?? 'null',
                    restoreStatus: 'restored',
                    restoredStoragePath: $current->restoredStoragePath,
                    restoreExpiresAt: $expiresAt,
                    restoredStorageTier: $restoredTier->name,
                );

                return true;
            }

            $sourceStoragePath = $current->systemMetadata['storagePath'] ?? null;
            if ($sourceStoragePath === null || $sourceStoragePath === '') {
                throw new InvalidObjectStateException('Object storage path is missing.');
            }
            $targetVersionId = $current->versionId ?? 'null';

            // Replacing an expired restore must retain its exact physical
            // location for cleanup before the pending state clears the path.
            if ($current->restoredStoragePath !== null) {
                $restoredTier = RestoredCopyTierResolver::resolve($current, $this->storageTiers);
                $this->metadata->enqueueStorageGarbage(
                    $current->bucket,
                    $restoredTier->name,
                    $current->restoredStoragePath,
                    $current->ownerId,
                );
            }

            $this->metadata->enqueueRestoreJob(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $targetVersionId,
                sourceTier: $current->storageTier,
                sourceStoragePath: $sourceStoragePath,
                restoreDays: $restore['days'],
            );
            $this->metadata->updateObjectRestoreState(
                bucket: $current->bucket,
                key: $current->key,
                versionId: $targetVersionId,
                restoreStatus: 'pending',
            );

            return false;
        });

        return new Response(status: $alreadyRestored ? 200 : 202);
    }
}
