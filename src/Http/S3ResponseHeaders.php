<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Dto\ObjectInfo;

/**
 * Builds the standard S3 response headers for an object.
 *
 * Used by both GetObject and HeadObject handlers to ensure
 * consistent header generation.
 */
final class S3ResponseHeaders
{
    /** @return array<non-empty-string, string> */
    public static function build(ObjectInfo $objectInfo, int $contentLength): array
    {
        $headers = [
            'ETag' => $objectInfo->etag,
            'Last-Modified' => $objectInfo->lastModified->format('D, d M Y H:i:s \\G\\M\\T'),
            'Content-Length' => (string) $contentLength,
            'Content-Type' => $objectInfo->contentType,
            'Accept-Ranges' => 'bytes',
            'x-amz-storage-class' => $objectInfo->storageClass,
        ];

        // Optional system metadata headers.
        if (isset($objectInfo->systemMetadata['content-encoding']) && $objectInfo->systemMetadata['content-encoding'] !== '') {
            $headers['Content-Encoding'] = $objectInfo->systemMetadata['content-encoding'];
        }

        if (isset($objectInfo->systemMetadata['content-disposition']) && $objectInfo->systemMetadata['content-disposition'] !== '') {
            $headers['Content-Disposition'] = $objectInfo->systemMetadata['content-disposition'];
        }

        if (isset($objectInfo->systemMetadata['cache-control']) && $objectInfo->systemMetadata['cache-control'] !== '') {
            $headers['Cache-Control'] = $objectInfo->systemMetadata['cache-control'];
        }

        // Expires header (stored as __expires in user metadata).
        if (isset($objectInfo->userMetadata['__expires']) && $objectInfo->userMetadata['__expires'] !== '') {
            $headers['Expires'] = $objectInfo->userMetadata['__expires'];
        }

        // Version ID.
        if ($objectInfo->versionId !== null) {
            $headers['x-amz-version-id'] = $objectInfo->versionId;
        }

        // Delete marker flag.
        if ($objectInfo->isDeleteMarker) {
            $headers['x-amz-delete-marker'] = 'true';
        }

        if ($objectInfo->restoreStatus !== null) {
            $headers['x-amz-restore'] = self::restoreHeader($objectInfo);
        }

        // Checksum headers.
        if (isset($objectInfo->systemMetadata['checksum-crc32']) && $objectInfo->systemMetadata['checksum-crc32'] !== '') {
            $headers['x-amz-checksum-crc32'] = $objectInfo->systemMetadata['checksum-crc32'];
        }

        if (isset($objectInfo->systemMetadata['checksum-crc32c']) && $objectInfo->systemMetadata['checksum-crc32c'] !== '') {
            $headers['x-amz-checksum-crc32c'] = $objectInfo->systemMetadata['checksum-crc32c'];
        }

        if (isset($objectInfo->systemMetadata['checksum-sha1']) && $objectInfo->systemMetadata['checksum-sha1'] !== '') {
            $headers['x-amz-checksum-sha1'] = $objectInfo->systemMetadata['checksum-sha1'];
        }

        if (isset($objectInfo->systemMetadata['checksum-sha256']) && $objectInfo->systemMetadata['checksum-sha256'] !== '') {
            $headers['x-amz-checksum-sha256'] = $objectInfo->systemMetadata['checksum-sha256'];
        }

        // Encryption headers.
        $sseAlgo = $objectInfo->userMetadata['__sse-algorithm'] ?? null;
        if ($sseAlgo === 'SSE-C') {
            $headers['x-amz-server-side-encryption-customer-algorithm'] = 'AES256';
            if (isset($objectInfo->userMetadata['__sse-customer-key-md5'])) {
                $headers['x-amz-server-side-encryption-customer-key-MD5'] = $objectInfo->userMetadata['__sse-customer-key-md5'];
            }
        } elseif ($sseAlgo === 'AES256') {
            $headers['x-amz-server-side-encryption'] = 'AES256';
        }

        // User metadata: x-amz-meta-* headers (filter out internal __sse-* keys).
        foreach ($objectInfo->userMetadata as $metaKey => $metaValue) {
            if (str_starts_with($metaKey, '__')) {
                continue;
            }
            $headers['x-amz-meta-'.$metaKey] = $metaValue;
        }

        return $headers;
    }

    private static function restoreHeader(ObjectInfo $objectInfo): string
    {
        if ($objectInfo->restoreStatus === 'restored' && $objectInfo->restoreExpiresAt !== null) {
            return sprintf(
                'ongoing-request="false", expiry-date="%s"',
                $objectInfo->restoreExpiresAt->format('D, d M Y H:i:s \\G\\M\\T'),
            );
        }

        if ($objectInfo->restoreStatus === 'restored') {
            return 'ongoing-request="false"';
        }

        return 'ongoing-request="true"';
    }
}
