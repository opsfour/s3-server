<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Metadata;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Dto\BucketInfo;
use OpsFour\S3Server\Exception\OperationAbortedException;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;

final class BucketGenerationGuard
{
    /** @param \Closure():void $mutation */
    public static function mutate(MetadataStore $metadata, BucketInfo $observed, string $requesterId, \Closure $mutation, ?Request $request = null): void
    {
        $metadata->transaction(static function () use ($metadata, $observed, $requesterId, $mutation, $request): void {
            OwnerWriteLock::acquire($metadata, $requesterId, $observed->ownerId);
            self::assertCurrent($metadata, $observed, $request);
            if ($request !== null) {
                (new PolicyEnforcementMiddleware($metadata))->assertAllowedAtCommit($request);
            }
            $mutation();
        });
    }

    /** Write callers hold the owner lock; read callers recheck before returning data. */
    public static function assertCurrent(MetadataStore $metadata, BucketInfo $observed, ?Request $request = null): void
    {
        if ($request?->hasAttribute('s3.cachedBucketGeneration')
            && $request->getAttribute('s3.cachedBucketGeneration') !== $observed->generation) {
            throw new OperationAbortedException('Bucket ownership changed after authorization.');
        }
        $current = $metadata->getBucket($observed->name);
        if ($current === null
            || $current->ownerId !== $observed->ownerId
            || $current->generation !== $observed->generation) {
            throw new OperationAbortedException('Bucket ownership changed during the request.');
        }
    }
}
