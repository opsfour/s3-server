<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Xml\XmlRequestParser;

final class PutBucketEncryptionHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly ?EncryptionServiceInterface $encryption = null,
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
        $config = XmlRequestParser::parseEncryptionConfiguration($body);
        if ($config['sseAlgorithm'] === 'aws:kms') {
            throw new NotImplementedException(
                'aws:kms bucket encryption requires a configured KMS adapter, which is not available.',
            );
        }
        EncryptionRequestResolver::requireEncryptionService($this->encryption);

        $this->metadata->putBucketEncryption(
            $bucket,
            $config['sseAlgorithm'],
            $config['kmsMasterKeyId'],
            $config['bucketKeyEnabled'],
        );

        return new Response(status: 200);
    }
}
