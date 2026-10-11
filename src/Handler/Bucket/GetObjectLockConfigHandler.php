<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\ObjectLockConfigurationNotFoundException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

/**
 * Handles GetObjectLockConfiguration (GET /{bucket}?object-lock).
 *
 * Returns the Object Lock configuration for the bucket.
 * If Object Lock was never enabled, returns ObjectLockConfigurationNotFoundError.
 */
final class GetObjectLockConfigHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $ownerId = $request->getAttribute('ownerId');

        // Verify bucket exists and owner matches.
        $bucketInfo = $this->metadata->getBucket($bucket);

        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $config = $this->metadata->getObjectLockConfig($bucket);
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        // If no config exists, Object Lock was never enabled for this bucket.
        if ($config === null) {
            throw new ObjectLockConfigurationNotFoundException();
        }
        if (($config['objectLockEnabled'] ?? null) !== 'Enabled') {
            throw new InvalidRequestException('Stored Object Lock configuration is not enabled.');
        }

        /** @var array{objectLockEnabled: string, rule?: array{defaultRetention: array{mode: string, days?: int, years?: int}}} $config */
        $xml = XmlResponseBuilder::objectLockConfiguration($config);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }
}
