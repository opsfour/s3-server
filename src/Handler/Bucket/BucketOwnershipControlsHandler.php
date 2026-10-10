<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Bucket;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Acl\BucketOwnership;
use OpsFour\S3Server\Exception\MalformedXmlException;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\OwnershipControlsNotFoundException;
use OpsFour\S3Server\Http\RequestBody;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Metadata\OwnerWriteLock;
use OpsFour\S3Server\Routing\S3Operation;
use OpsFour\S3Server\Xml\S3XmlDocument;
use OpsFour\S3Server\Xml\SafeXmlParser;

final class BucketOwnershipControlsHandler implements RequestHandler
{
    public function __construct(private readonly MetadataStore $metadata) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = (string) $request->getAttribute('s3.bucket');
        $ownerId = (string) $request->getAttribute('ownerId');
        $info = $this->metadata->getBucket($bucket);
        if ($info === null) {
            throw new NoSuchBucketException();
        }

        $operation = $request->getAttribute('s3.operation');
        if ($operation === S3Operation::GetBucketOwnershipControls) {
            $mode = $this->metadata->getBucketOwnershipControls($bucket);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $info, $request);
            if ($mode === null) {
                throw new OwnershipControlsNotFoundException();
            }
            $writer = new \XMLWriter();
            $writer->openMemory();
            $writer->startDocument('1.0', 'UTF-8');
            $writer->startElementNs(null, 'OwnershipControls', 'http://s3.amazonaws.com/doc/2006-03-01/');
            $writer->startElement('Rule');
            $writer->writeElement('ObjectOwnership', $mode);
            $writer->endElement();
            $writer->endElement();
            $writer->endDocument();

            return new Response(200, ['Content-Type' => 'application/xml'], $writer->outputMemory());
        }

        if ($operation === S3Operation::PutBucketOwnershipControls) {
            $mode = self::parseMode(RequestBody::buffer($request, 65_536));
            $this->metadata->transaction(function () use ($request, $bucket, $ownerId, $info, $mode): void {
                OwnerWriteLock::acquire($this->metadata, $ownerId, $info->ownerId);
                \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $info, $request);
                (new \OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
                if ($mode === BucketOwnership::BUCKET_OWNER_ENFORCED) {
                    BucketOwnership::assertPrivateBucketAcl($this->metadata, $bucket, $info->ownerId);
                }
                $this->metadata->putBucketOwnershipControls($bucket, $mode);
            });

            return new Response(200);
        }

        $this->metadata->transaction(function () use ($request, $bucket, $ownerId, $info): void {
            OwnerWriteLock::acquire($this->metadata, $ownerId, $info->ownerId);
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $info, $request);
            (new \OpsFour\S3Server\Middleware\PolicyEnforcementMiddleware($this->metadata))->assertAllowedAtCommit($request);
            $this->metadata->deleteBucketOwnershipControls($bucket);
        });

        return new Response(204);
    }

    private static function parseMode(string $body): string
    {
        $root = SafeXmlParser::parse($body, 'Invalid OwnershipControls XML.');
        S3XmlDocument::assertRoot($root, 'OwnershipControls');
        $rootChildren = S3XmlDocument::children($root);
        if (count($rootChildren) !== 1) {
            throw new MalformedXmlException('OwnershipControls requires exactly one Rule.');
        }
        $rule = $rootChildren[0];
        $ruleChildren = S3XmlDocument::children($rule);
        if ($rule->getName() !== 'Rule' || count($ruleChildren) !== 1
            || $ruleChildren[0]->getName() !== 'ObjectOwnership'
            || S3XmlDocument::children($ruleChildren[0], allowText: true) !== []) {
            throw new MalformedXmlException('OwnershipControls requires one ObjectOwnership value.');
        }

        return BucketOwnership::validateMode(trim((string) $ruleChildren[0]));
    }
}
