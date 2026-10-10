<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Xml\XmlRequestParser;

final class PutBucketLifecycleHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageTierRegistry $storageTiers,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);
        $rules = XmlRequestParser::parseLifecycleConfiguration($body);

        foreach ($rules as $rule) {
            foreach (array_merge($rule['transitions'] ?? [], $rule['noncurrentTransitions'] ?? []) as $transition) {
                $storageClass = $transition['storageClass'];
                if (! $this->storageTiers->has($storageClass)) {
                    throw new InvalidRequestException("Storage class {$storageClass} has no configured storage tier.");
                }
            }
        }

        \OpsFour\S3Server\Metadata\BucketGenerationGuard::mutate(
            $this->metadata,
            $bucketInfo,
            (string) $ownerId,
            fn() => $this->metadata->putBucketLifecycle($bucket, $rules),
            $request,
        );

        return new Response(status: 200);
    }
}
