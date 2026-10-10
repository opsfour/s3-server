<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Xml\S3XmlDocument;
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

        $this->metadata->transaction(function () use ($request, $bucketInfo, $ownerId, $bucket, $config): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $bucketInfo->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            (new \OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
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
        S3XmlDocument::assertRoot($element, 'PublicAccessBlockConfiguration');

        $fieldNames = [
            'BlockPublicAcls' => 'blockPublicAcls',
            'IgnorePublicAcls' => 'ignorePublicAcls',
            'BlockPublicPolicy' => 'blockPublicPolicy',
            'RestrictPublicBuckets' => 'restrictPublicBuckets',
        ];
        $config = [
            'blockPublicAcls' => false,
            'ignorePublicAcls' => false,
            'blockPublicPolicy' => false,
            'restrictPublicBuckets' => false,
        ];
        $seen = [];
        foreach (S3XmlDocument::children($element) as $child) {
            $name = $child->getName();
            if (!isset($fieldNames[$name]) || isset($seen[$name]) || S3XmlDocument::children($child, allowText: true) !== []) {
                throw new MalformedXmlException('Invalid PublicAccessBlockConfiguration field.');
            }
            $seen[$name] = true;
            $value = strtolower(trim((string) $child));
            if (!in_array($value, ['true', 'false', '1', '0'], true)) {
                throw new MalformedXmlException('Invalid PublicAccessBlockConfiguration boolean value.');
            }
            $config[$fieldNames[$name]] = $value === 'true' || $value === '1';
        }
        return $config;
    }
}
