<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Http\ObjectTagValidator;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles GetObjectTagging (GET /{bucket}/{key}?tagging).
 *
 * Returns the tag set for the specified object. Unlike bucket tagging,
 * real S3 returns an empty TagSet (not an error) when no tags exist.
 */
final class GetObjectTaggingHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
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

        $tags = $this->metadata->getObjectTagging($bucket, $key, $object->versionId);
        $versionId = $object->versionId
            ?? ($this->metadata->getBucketVersioning($bucket) !== '' ? 'null' : null);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        $tags = ObjectTagValidator::validateStored($tags, 10);

        // For objects, return empty TagSet (not an error) when no tags exist.
        $xml = XmlResponseBuilder::taggingResult($tags);

        return new Response(
            status: 200,
            headers: array_filter([
                'Content-Type' => 'application/xml',
                'x-amz-version-id' => $versionId,
            ], static fn(?string $value): bool => $value !== null),
            body: $xml,
        );
    }
}
