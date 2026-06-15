<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\ByteStream;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;

final class PutBucketLoggingHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $body = ByteStream\buffer($request->getBody());

        try {
            $body = preg_replace('/<!DOCTYPE[^[>]*(?:\[[^\]]*\])?[^>]*>/i', '', $body);
            if ($body === null) {
                throw new \RuntimeException('Failed to sanitize XML.');
            }
            $element = new \SimpleXMLElement($body, LIBXML_NONET);
        } catch (\Exception) {
            throw new MalformedXmlException('Invalid BucketLoggingStatus XML.');
        }

        if (isset($element->LoggingEnabled)) {
            $targetBucket = (string) ($element->LoggingEnabled->TargetBucket ?? '');
            $targetPrefix = (string) ($element->LoggingEnabled->TargetPrefix ?? '');

            if ($targetBucket === '') {
                throw new MalformedXmlException('TargetBucket must not be empty.');
            }

            $targetBucketInfo = $this->metadata->getBucket($targetBucket);
            if ($targetBucketInfo === null || $targetBucketInfo->ownerId !== $ownerId) {
                throw new NoSuchBucketException();
            }

            $this->metadata->putBucketLogging($bucket, $targetBucket, $targetPrefix);
        } else {
            // Empty LoggingEnabled means disable logging.
            $this->metadata->deleteBucketLogging($bucket);
        }

        return new Response(status: 200);
    }
}
