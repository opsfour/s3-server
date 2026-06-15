<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NoSuchEncryptionConfigurationException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class GetBucketEncryptionHandler implements RequestHandler
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
            throw new NoSuchBucketException;
        }

        $config = $this->metadata->getBucketEncryption($bucket);
        if ($config === null) {
            throw new NoSuchEncryptionConfigurationException;
        }

        /** @var array{sseAlgorithm: string, kmsMasterKeyId?: string, bucketKeyEnabled?: bool} $encConfig */
        $encConfig = array_filter($config, fn ($v) => $v !== null);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: XmlResponseBuilder::encryptionConfiguration($encConfig),
        );
    }
}
