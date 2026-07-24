<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Xml\SafeXmlParser;

final class PutPublicAccessBlockHandler implements RequestHandler
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
        $config = self::parsePublicAccessBlock($body);

        $this->metadata->transaction(function () use ($bucketInfo, $ownerId, $bucket, $config): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            $this->metadata->putPublicAccessBlock(
                $bucket,
                $config['blockPublicAcls'],
                $config['ignorePublicAcls'],
                $config['blockPublicPolicy'],
                $config['restrictPublicBuckets'],
            );
        });

        return new Response(status: 200);
    }

    /**
     * @return array{blockPublicAcls: bool, ignorePublicAcls: bool, blockPublicPolicy: bool, restrictPublicBuckets: bool}
     */
    private static function parsePublicAccessBlock(string $xml): array
    {
        $element = SafeXmlParser::parse($xml, 'Invalid XML.');

        return [
            'blockPublicAcls' => isset($element->BlockPublicAcls) && strtolower((string) $element->BlockPublicAcls) === 'true',
            'ignorePublicAcls' => isset($element->IgnorePublicAcls) && strtolower((string) $element->IgnorePublicAcls) === 'true',
            'blockPublicPolicy' => isset($element->BlockPublicPolicy) && strtolower((string) $element->BlockPublicPolicy) === 'true',
            'restrictPublicBuckets' => isset($element->RestrictPublicBuckets) && strtolower((string) $element->RestrictPublicBuckets) === 'true',
        ];
    }
}
