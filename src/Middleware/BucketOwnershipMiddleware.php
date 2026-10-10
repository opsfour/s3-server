<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\AccessControlListNotSupportedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Routing\S3Operation;

final readonly class BucketOwnershipMiddleware implements Middleware
{
    public function __construct(private MetadataStore $metadata) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        $bucket = $request->hasAttribute('s3.bucket') ? $request->getAttribute('s3.bucket') : null;
        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        if (is_string($bucket) && $bucket !== '' && BucketOwnership::isEnforced($this->metadata, $bucket)) {
            if (in_array($operation, [S3Operation::PutBucketAcl, S3Operation::PutObjectAcl], true)) {
                throw new AccessControlListNotSupportedException();
            }
            if (in_array($operation, [S3Operation::PutObject, S3Operation::PostObject,
                S3Operation::CopyObject, S3Operation::CreateMultipartUpload], true)) {
                BucketOwnership::assertWriteAclAllowed($request);
            }
        }

        return $requestHandler->handleRequest($request);
    }
}
