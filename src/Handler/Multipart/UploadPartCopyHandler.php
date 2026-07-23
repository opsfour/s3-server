<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Encryption\EncryptionService;
use OpsFour\S3Server\Encryption\EncryptionServiceInterface;
use OpsFour\S3Server\Exception\NoSuchBucketException;
use OpsFour\S3Server\Exception\InvalidObjectStateException;
use OpsFour\S3Server\Http\QueryStringParser;
use OpsFour\S3Server\Exception\NoSuchKeyException;
use OpsFour\S3Server\Exception\NoSuchUploadException;
use OpsFour\S3Server\Metadata\MetadataStore;
use OpsFour\S3Server\Storage\StorageBackend;
use OpsFour\S3Server\Storage\StorageTierRegistry;
use OpsFour\S3Server\Xml\XmlResponseBuilder;

final class UploadPartCopyHandler implements RequestHandler
{
    private readonly StorageTierRegistry $storageTiers;

    public function __construct(
        private readonly MetadataStore $metadata,
        private readonly StorageBackend $storage,
        private readonly ?EncryptionServiceInterface $encryption = null,
        private readonly int $maxEncryptedObjectSize = 268_435_456,
        ?StorageTierRegistry $storageTiers = null,
    ) {
        $this->storageTiers = $storageTiers ?? StorageTierRegistry::single($storage);
    }

    public function handleRequest(Request $request): Response
    {
        $bucket = $request->getAttribute('s3.bucket');
        $key = $request->getAttribute('s3.key');
        $ownerId = $request->getAttribute('ownerId');

        $bucketInfo = $this->metadata->getBucket($bucket);
        if ($bucketInfo === null) {
            throw new NoSuchBucketException();
        }

        $queryParams = QueryStringParser::parse($request->getUri()->getQuery());
        $uploadId = $queryParams['uploadId'] ?? '';
        $partNumber = isset($queryParams['partNumber']) ? (int) $queryParams['partNumber'] : 0;

        if ($partNumber < 1 || $partNumber > 10000) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Part number must be between 1 and 10000.',
            );
        }

        $upload = $this->metadata->getMultipartUpload($uploadId);
        if ($upload === null || $upload['bucket'] !== $bucket || $upload['key_name'] !== $key) {
            throw new NoSuchUploadException();
        }
        if ($upload['owner_id'] !== $ownerId) {
            throw new NoSuchUploadException();
        }

        // Parse copy source.
        $copySource = $request->getHeader('x-amz-copy-source') ?? '';
        [$srcBucket, $srcKey, $srcVersionId] = self::parseCopySource($copySource);

        $srcBucketInfo = $this->metadata->getBucket($srcBucket);
        if ($srcBucketInfo === null) {
            throw new NoSuchKeyException();
        }

        $srcObject = ($srcVersionId !== null)
            ? $this->metadata->getObjectMetadataByVersion($srcBucket, $srcKey, $srcVersionId)
            : $this->metadata->getObjectMetadata($srcBucket, $srcKey);
        if ($srcObject === null || $srcObject->isDeleteMarker) {
            throw new NoSuchKeyException();
        }

        // Parse optional range.
        $rangeHeader = $request->getHeader('x-amz-copy-source-range');
        $offset = null;
        $length = null;

        if ($rangeHeader !== null && str_starts_with($rangeHeader, 'bytes=')) {
            $rangeSpec = substr($rangeHeader, 6);
            $parts = explode('-', $rangeSpec, 2);
            if (count($parts) === 2) {
                $start = (int) $parts[0];
                $end = (int) $parts[1];
                if ($start > $end || $start >= $srcObject->size || $end >= $srcObject->size) {
                    throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                        'Range specified is not valid for source object size.',
                    );
                }
                $offset = $start;
                $length = $end - $start + 1;
            }
        }

        // Read source data — decrypt if the source object is encrypted.
        $srcPath = $srcObject->systemMetadata['storagePath'] ?? null;
        if ($srcPath === null || $srcPath === '') {
            throw new \OpsFour\S3Server\Exception\InternalErrorException('Source object storage path missing.');
        }
        $sourceTier = $this->storageTiers->tier($srcObject->storageTier);
        $sourceStorage = $sourceTier->backend;
        if ($sourceTier->restoreRequired) {
            if (
                $srcObject->restoreStatus !== 'restored'
                || $srcObject->restoredStoragePath === null
                || $srcObject->restoreExpiresAt === null
                || $srcObject->restoreExpiresAt <= new \DateTimeImmutable('now', new \DateTimeZone('UTC'))
            ) {
                throw new InvalidObjectStateException('The source object must be restored before it can be copied.');
            }
            $sourceStorage = $this->storageTiers->defaultBackend();
            $srcPath = $srcObject->restoredStoragePath;
        }
        $srcSseAlgo = $srcObject->userMetadata['__sse-algorithm'] ?? null;

        if ($srcSseAlgo !== null && $this->encryption !== null) {
            // Size guard: encrypted objects must be fully buffered for decryption.
            if ($srcObject->size > $this->maxEncryptedObjectSize) {
                throw new \OpsFour\S3Server\Exception\EntityTooLargeException(
                    'Encrypted source object exceeds maximum size for decryption (' . $this->maxEncryptedObjectSize . ' bytes).',
                );
            }

            // Source is encrypted: read ciphertext, decrypt, then write plaintext as part.
            $ciphertext = \Amp\ByteStream\buffer($sourceStorage->getObjectByPath($srcPath));

            if ($srcSseAlgo === 'SSE-C') {
                $copySrcAlgo = $request->getHeader('x-amz-copy-source-server-side-encryption-customer-algorithm');
                $copySrcKey = $request->getHeader('x-amz-copy-source-server-side-encryption-customer-key');
                $copySrcKeyMd5 = $request->getHeader('x-amz-copy-source-server-side-encryption-customer-key-MD5');

                if ($copySrcAlgo === null || $copySrcKey === null || $copySrcKeyMd5 === null) {
                    throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                        'SSE-C copy-source headers required for encrypted source object.',
                    );
                }

                $customerKey = EncryptionService::validateSseCHeaders($copySrcAlgo, $copySrcKey, $copySrcKeyMd5);
                $plaintext = $this->encryption->decryptSseC(
                    $ciphertext,
                    $customerKey,
                    $srcObject->userMetadata['__sse-iv'],
                    $srcObject->userMetadata['__sse-tag'],
                );
            } else {
                // SSE-S3.
                $plaintext = $this->encryption->decryptSseS3(
                    $ciphertext,
                    $srcObject->userMetadata['__sse-key'],
                    $srcObject->userMetadata['__sse-iv'],
                    $srcObject->userMetadata['__sse-tag'],
                );
            }

            // Apply range to decrypted plaintext if requested.
            if ($offset !== null && $length !== null) {
                $plaintext = substr($plaintext, $offset, $length);
            } elseif ($offset !== null) {
                $plaintext = substr($plaintext, $offset);
            }

            $stream = new \Amp\ByteStream\ReadableBuffer($plaintext);
        } else {
            $stream = $sourceStorage->getObjectByPath($srcPath, $offset, $length);
        }

        // Write as a part.
        $writeResult = $this->storage->putPart($bucket, $key, $uploadId, $partNumber, $stream);

        $etag = '"' . $writeResult->md5Hex . '"';

        try {
            $this->metadata->putPart($uploadId, $partNumber, $etag, $writeResult->size, $writeResult->path);
        } catch (\Throwable $e) {
            try {
                $this->storage->deleteObjectByPath($writeResult->path, $bucket);
            } catch (\Throwable) {
            }
            throw $e;
        }

        $lastModified = (new \DateTimeImmutable('now', new \DateTimeZone('UTC')))
            ->format('Y-m-d\TH:i:s.000\Z');

        $xml = XmlResponseBuilder::copyObjectResult($etag, $lastModified);

        return new Response(
            status: 200,
            headers: ['Content-Type' => 'application/xml'],
            body: $xml,
        );
    }

    /** @return array{0: string, 1: string, 2: string|null} */
    private static function parseCopySource(string $copySource): array
    {
        $versionId = null;
        $qPos = strpos($copySource, '?');
        if ($qPos !== false) {
            parse_str(substr($copySource, $qPos + 1), $queryParams);
            $versionId = isset($queryParams['versionId']) && is_string($queryParams['versionId'])
                ? $queryParams['versionId']
                : null;
            $copySource = substr($copySource, 0, $qPos);
        }
        $copySource = rawurldecode(ltrim($copySource, '/'));
        $slashPos = strpos($copySource, '/');
        if ($slashPos === false) {
            throw new \OpsFour\S3Server\Exception\InvalidArgumentException(
                'Invalid x-amz-copy-source format.',
            );
        }

        return [substr($copySource, 0, $slashPos), substr($copySource, $slashPos + 1), $versionId];
    }
}
