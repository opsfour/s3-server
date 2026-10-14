<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\CrossLocationLoggingProhibitedException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\InvalidTargetBucketForLoggingException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\S3XmlDocument;
use OpsFour\S3Server\Xml\SafeXmlParser;

final class PutBucketLoggingHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = (string) $request->getAttribute('ownerId');
        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $body = \OpsFour\S3Server\Http\RequestBody::buffer($request);

        $element = SafeXmlParser::parse($body, 'Invalid BucketLoggingStatus XML.');

        S3XmlDocument::assertRoot($element, 'BucketLoggingStatus');
        S3XmlDocument::assertOnlyAttributes($element);
        $loggingEnabled = null;
        foreach (S3XmlDocument::children($element) as $child) {
            if ($child->getName() !== 'LoggingEnabled') {
                throw new MalformedXmlException('Unexpected BucketLoggingStatus element.');
            }
            if ($loggingEnabled !== null) {
                throw new MalformedXmlException('Duplicate LoggingEnabled element.');
            }
            $loggingEnabled = $child;
        }

        if ($loggingEnabled !== null) {
            $targetBucket = null;
            $targetPrefix = null;
            foreach (S3XmlDocument::children($loggingEnabled) as $child) {
                switch ($child->getName()) {
                    case 'TargetBucket':
                        if ($targetBucket !== null || S3XmlDocument::children($child, allowText: true) !== []) {
                            throw new MalformedXmlException('Invalid TargetBucket element.');
                        }
                        $targetBucket = (string) $child;
                        break;
                    case 'TargetPrefix':
                        if ($targetPrefix !== null || S3XmlDocument::children($child, allowText: true) !== []) {
                            throw new MalformedXmlException('Invalid TargetPrefix element.');
                        }
                        $targetPrefix = (string) $child;
                        break;
                    case 'TargetGrants':
                    case 'TargetObjectKeyFormat':
                        throw new NotImplementedException('Logging target grants and key formats are not supported.');
                    default:
                        throw new MalformedXmlException('Unexpected LoggingEnabled element.');
                }
            }
            if ($targetBucket === null || $targetPrefix === null) {
                throw new MalformedXmlException('LoggingEnabled requires TargetBucket and TargetPrefix.');
            }

            if ($targetBucket === '') {
                throw new MalformedXmlException('TargetBucket must not be empty.');
            }

            $targetBucketInfo = $this->metadata->getBucket($targetBucket);
            if ($targetBucketInfo === null || $targetBucketInfo->ownerId !== $bucketInfo->ownerId) {
                throw new InvalidTargetBucketForLoggingException();
            }
            if ($targetBucketInfo->region !== $bucketInfo->region) {
                throw new CrossLocationLoggingProhibitedException();
            }
            if ($this->metadata->getObjectLockConfig($targetBucket) !== null) {
                throw new InvalidRequestException('A bucket with Object Lock cannot be an access log target.');
            }

            \OpsFour\S3Server\Metadata\BucketGenerationGuard::mutate(
                $this->metadata,
                $bucketInfo,
                $ownerId,
                function () use ($bucket, $targetBucket, $targetPrefix, $targetBucketInfo): void {
                    \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $targetBucketInfo);
                    if ($this->metadata->getObjectLockConfig($targetBucket) !== null) {
                        throw new InvalidRequestException('A bucket with Object Lock cannot be an access log target.');
                    }
                    $this->metadata->putBucketLogging($bucket, $targetBucket, $targetPrefix);
                },
                $request,
            );
        } else {
            // Empty LoggingEnabled means disable logging.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::mutate(
                $this->metadata,
                $bucketInfo,
                $ownerId,
                fn() => $this->metadata->deleteBucketLogging($bucket),
                $request,
            );
        }

        return new Response(status: 200);
    }
}
