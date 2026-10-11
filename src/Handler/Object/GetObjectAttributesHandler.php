<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Object;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Encryption\EncryptionRequestResolver;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Http\ConditionalHeaderEvaluator;
use OpsFour\S3Server\Http\ObjectVersionResolver;
use OpsFour\S3Server\Http\MissingObjectListVisibility;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchVersionException;
use OpsFour\S3Server\Exception\MethodNotAllowedException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Handles GetObjectAttributes (GET /{bucket}/{key}?attributes).
 *
 * Returns a subset of object metadata based on the x-amz-object-attributes header.
 */
final class GetObjectAttributesHandler implements RequestHandler
{
    public function __construct(
        private readonly MetadataStore $metadata,
    ) {}

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        try {
            $objectInfo = ObjectVersionResolver::resolve(
                $this->metadata,
                $request,
                $bucket,
                $key,
                allowExplicitDeleteMarker: true,
            );
        } catch (NoSuchKeyException|NoSuchVersionException $exception) {
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            // A current delete marker keeps its established 404 response.
            $denyMissingObject = ! isset(QueryStringParser::parse($request->getUri()->getQuery())['versionId'])
                && $this->metadata->getCurrentObjectVersion($bucket, $key) === null
                && ! MissingObjectListVisibility::canList($this->metadata, $request, $bucket);
            // Both lookups can yield after the first generation check.
            \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
            if ($denyMissingObject) {
                throw new \OpsFour\S3Server\Exception\AccessDeniedException();
            }
            throw $exception;
        }
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        if ($objectInfo->isDeleteMarker) {
            throw (new MethodNotAllowedException())->withExtraHeaders([
                'x-amz-delete-marker' => 'true',
                'x-amz-version-id' => $objectInfo->versionId ?? 'null',
                'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \\G\\M\\T'),
            ]);
        }

        $sseAlgorithm = $objectInfo->userMetadata['__sse-algorithm'] ?? null;
        $customerKey = EncryptionRequestResolver::resolveCustomerKey(
            $request,
            $sseAlgorithm === 'SSE-C',
            $objectInfo->userMetadata['__sse-customer-key-md5'] ?? null,
        );
        if ($sseAlgorithm !== 'SSE-C' && $customerKey !== null) {
            throw new InvalidArgumentException('SSE-C headers are not valid for this object.');
        }

        // Parse requested attributes.
        $attrHeaders = $request->getHeaderArray('x-amz-object-attributes');
        if ($attrHeaders === []) {
            throw new InvalidArgumentException('x-amz-object-attributes header is required.');
        }

        $requestedAttrs = [];
        foreach ($attrHeaders as $attrHeader) {
            foreach (explode(',', $attrHeader) as $attr) {
                $attr = trim($attr);
                if ($attr !== '') {
                    if (!in_array($attr, ['ETag', 'ObjectSize', 'StorageClass', 'Checksum', 'ObjectParts'], true)) {
                        throw new InvalidArgumentException('Invalid x-amz-object-attributes value.');
                    }
                    $requestedAttrs[$attr] = true;
                }
            }
        }
        if ($requestedAttrs === []) {
            throw new InvalidArgumentException('x-amz-object-attributes header is required.');
        }

        $emitNullVersion = $objectInfo->versionId === null
            && $this->metadata->getBucketVersioning($bucket) !== '';
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);
        ConditionalHeaderEvaluator::evaluate($request, $objectInfo, $emitNullVersion);

        $writer = new \XMLWriter();
        $writer->openMemory();
        $writer->startDocument('1.0', 'UTF-8');
        $writer->startElementNs(null, 'GetObjectAttributesResponse', 'http://s3.amazonaws.com/doc/2006-03-01/');

        foreach (['ETag', 'Checksum', 'ObjectParts', 'StorageClass', 'ObjectSize'] as $attr) {
            if (! isset($requestedAttrs[$attr])) {
                continue;
            }
            match ($attr) {
                'ETag' => $writer->writeElement('ETag', trim($objectInfo->etag, '"')),
                'ObjectSize' => $writer->writeElement('ObjectSize', (string) $objectInfo->size),
                'StorageClass' => $writer->writeElement('StorageClass', $objectInfo->storageClass),
                'Checksum' => self::writeChecksum($writer, $objectInfo),
                'ObjectParts' => self::writeObjectParts($writer, $objectInfo),
            };
        }

        $writer->endElement();
        $writer->endDocument();

        $headers = [
            'Content-Type' => 'application/xml',
            'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \G\M\T'),
        ];
        if ($objectInfo->versionId !== null) {
            $headers['x-amz-version-id'] = $objectInfo->versionId;
        } elseif ($emitNullVersion) {
            $headers['x-amz-version-id'] = 'null';
        }
        if ($sseAlgorithm === 'SSE-C') {
            $headers['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            $headers['x-amz-server-side-encryption-customer-key-MD5']
                = $objectInfo->userMetadata['__sse-customer-key-md5'];
        }

        // The versioning lookup above can yield after the earlier generation
        // check. Never return old-owner attributes if the bucket was reused.
        \OpsFour\S3Server\Metadata\BucketGenerationGuard::assertCurrent($this->metadata, $bucketInfo, $request);

        return new Response(
            status: 200,
            headers: $headers,
            body: $writer->outputMemory(),
        );
    }

    private static function writeChecksum(\XMLWriter $writer, \OpsFour\S3Server\Dto\ObjectInfo $obj): void
    {
        $hasChecksum = false;
        foreach (['checksum-crc32', 'checksum-crc32c', 'checksum-sha1', 'checksum-sha256'] as $key) {
            if (isset($obj->systemMetadata[$key]) && $obj->systemMetadata[$key] !== '') {
                $hasChecksum = true;
                break;
            }
        }

        if (!$hasChecksum) {
            return;
        }

        $writer->startElement('Checksum');
        if (isset($obj->systemMetadata['checksum-crc32']) && $obj->systemMetadata['checksum-crc32'] !== '') {
            $writer->writeElement('ChecksumCRC32', $obj->systemMetadata['checksum-crc32']);
        }
        if (isset($obj->systemMetadata['checksum-crc32c']) && $obj->systemMetadata['checksum-crc32c'] !== '') {
            $writer->writeElement('ChecksumCRC32C', $obj->systemMetadata['checksum-crc32c']);
        }
        if (isset($obj->systemMetadata['checksum-sha1']) && $obj->systemMetadata['checksum-sha1'] !== '') {
            $writer->writeElement('ChecksumSHA1', $obj->systemMetadata['checksum-sha1']);
        }
        if (isset($obj->systemMetadata['checksum-sha256']) && $obj->systemMetadata['checksum-sha256'] !== '') {
            $writer->writeElement('ChecksumSHA256', $obj->systemMetadata['checksum-sha256']);
        }
        $writer->writeElement('ChecksumType', 'FULL_OBJECT');
        $writer->endElement();
    }

    private static function writeObjectParts(\XMLWriter $writer, \OpsFour\S3Server\Dto\ObjectInfo $obj): void
    {
        // Check if ETag indicates multipart (contains "-N").
        $etag = trim($obj->etag, '"');
        if (!str_contains($etag, '-')) {
            return;
        }

        $parts = explode('-', $etag);
        $totalParts = (int) ($parts[1] ?? 0);

        if ($totalParts > 0) {
            $writer->startElement('ObjectParts');
            $writer->writeElement('PartsCount', (string) $totalParts);
            $writer->endElement();
        }
    }
}
