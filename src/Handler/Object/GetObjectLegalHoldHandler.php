<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NoSuchObjectLockConfigurationException;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles GetObjectLegalHold (GET /{bucket}/{key}?legal-hold).
 *
 * Returns the legal hold status for a specific object version.
 */
final class GetObjectLegalHoldHandler implements RequestHandler
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
        $lockConfig = $this->metadata->getObjectLockConfig($bucket);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        if ($lockConfig === null) {
            throw new InvalidRequestException('Bucket does not have Object Lock enabled.');
        }

        try {
            $object = ObjectVersionResolver::resolve($this->metadata, $request, $bucket, $key);
        } catch (NoSuchKeyException|NoSuchVersionException $error) {
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            throw $error;
        }
        $status = $this->metadata->getObjectLegalHold($bucket, $key, $object->versionId);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        if ($status === null) {
            throw new NoSuchObjectLockConfigurationException('The specified object does not have a legal hold configuration.');
        }

        $xml = XmlResponseBuilder::legalHold($status);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
