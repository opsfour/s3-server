<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Http\StorageClassValidator;
use OpsFour\S3Server\Middleware\AclEnforcementMiddleware;
use OpsFour\S3Server\Middleware\BucketOwnershipMiddleware;
use OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware;
use OpsFour\S3Server\ObjectLock\ObjectLockRequestApplier;

/** Check the form's final key and write headers before its file reaches disk. */
final readonly class PostObjectAuthorizationPreflight
{
    public function __construct(private MetadataStore $metadata) {}

    /** @param array<string, string> $fields */
    public function check(Request $request, ?Credential $credential, array $fields, string $key): void
    {
        // Do not change the multipart request while StreamingFormParser still
        // reads it. The ordinary middleware stack checks the real request again
        // after streaming, so policy or ACL changes during upload still deny it.
        $request->setAttribute('s3.key', $key);
        $request->setAttribute('ownerId', $credential === null ? '' : $credential->ownerId);
        $preflightRequest = clone $request;
        $preflightRequest->setAttribute('credential', $credential);
        $preflightRequest->setAttribute('signedHeaders', []);
        PostObjectFormParser::applyFormHeaders($preflightRequest, $fields);

        $handler = \Amp\Http\Server\Middleware\stackMiddleware(
            new class implements RequestHandler {
                public function handleRequest(Request $request): Response
                {
                    return new Response(204);
                }
            },
            new PolicyEnforcementMiddleware($this->metadata),
            new BucketOwnershipMiddleware($this->metadata),
            new AclEnforcementMiddleware($this->metadata),
        );
        try {
            $handler->handleRequest($preflightRequest);
        } finally {
            // Preserve context for the outer access logger even when a denial
            // happens before the actual middleware stack is entered.
            foreach (['s3.cachedBucketOwner', 's3.cachedBucketGeneration'] as $attribute) {
                if ($preflightRequest->hasAttribute($attribute)) {
                    $request->setAttribute($attribute, $preflightRequest->getAttribute($attribute));
                }
            }
        }

        // Authenticated callers may pass ACL checks for a missing bucket;
        // avoid buffering their file before the handler returns NoSuchBucket.
        $bucket = $request->getAttribute('s3.bucket');
        if (!is_string($bucket) || $this->metadata->getBucket($bucket) === null) {
            throw new NoSuchBucketException();
        }
        StorageClassValidator::requested($preflightRequest->getHeader('x-amz-storage-class'));
        (new ObjectLockRequestApplier($this->metadata))->validate($preflightRequest, $bucket);
    }
}
