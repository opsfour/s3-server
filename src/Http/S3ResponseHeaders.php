<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Http;

use OpsFour\S3Server\Dto\ObjectInfo;
use OpsFour\S3Server\Exception\InvalidArgumentException;

/**
 * Builds the standard S3 response headers for an object.
 *
 * Used by both GetObject and HeadObject handlers to ensure
 * consistent header generation.
 */
final class S3ResponseHeaders
{
    /** @var array<string, string> */
    private const array RESPONSE_HEADER_OVERRIDES = [
        'response-content-type' => 'Content-Type',
        'response-content-language' => 'Content-Language',
        'response-content-disposition' => 'Content-Disposition',
        'response-content-encoding' => 'Content-Encoding',
        'response-cache-control' => 'Cache-Control',
        'response-expires' => 'Expires',
    ];

    public static function checksumModeEnabled(?string $mode): bool
    {
        if ($mode === null || $mode === '') {
            return false;
        }
        if ($mode !== 'ENABLED') {
            throw new InvalidArgumentException('x-amz-checksum-mode must be ENABLED.');
        }

        return true;
    }

    public static function httpDate(\DateTimeImmutable $timestamp): string
    {
        return $timestamp
            ->setTimezone(new \DateTimeZone('UTC'))
            ->format('D, d M Y H:i:s \\G\\M\\T');
    }

    /** @return array<non-empty-string, string> */
    public static function build(ObjectInfo $objectInfo, int $contentLength, bool $includeNullVersionId = false, bool $includeChecksums = false): array
    {
        $headers = [
            'ETag' => $objectInfo->etag,
            'Last-Modified' => self::httpDate($objectInfo->lastModified),
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
        if ($objectInfo->versionId !== null || $includeNullVersionId) {
            $headers['x-amz-version-id'] = $objectInfo->versionId ?? 'null';
        }

        // Delete marker flag.
        if ($objectInfo->isDeleteMarker) {
            $headers['x-amz-delete-marker'] = 'true';
        }

        if (in_array($objectInfo->restoreStatus, ['pending', 'restored'], true)) {
            $headers['x-amz-restore'] = self::restoreHeader($objectInfo);
        }

        // Object checksums are opt-in and describe only the complete object.
        $hasFullObjectChecksum = false;
        if ($includeChecksums && isset($objectInfo->systemMetadata['checksum-crc32']) && $objectInfo->systemMetadata['checksum-crc32'] !== '') {
            $headers['x-amz-checksum-crc32'] = $objectInfo->systemMetadata['checksum-crc32'];
            $hasFullObjectChecksum = true;
        }

        if ($includeChecksums && isset($objectInfo->systemMetadata['checksum-crc32c']) && $objectInfo->systemMetadata['checksum-crc32c'] !== '') {
            $headers['x-amz-checksum-crc32c'] = $objectInfo->systemMetadata['checksum-crc32c'];
            $hasFullObjectChecksum = true;
        }

        if ($includeChecksums && isset($objectInfo->systemMetadata['checksum-sha1']) && $objectInfo->systemMetadata['checksum-sha1'] !== '') {
            $headers['x-amz-checksum-sha1'] = $objectInfo->systemMetadata['checksum-sha1'];
            $hasFullObjectChecksum = true;
        }

        if ($includeChecksums && isset($objectInfo->systemMetadata['checksum-sha256']) && $objectInfo->systemMetadata['checksum-sha256'] !== '') {
            $headers['x-amz-checksum-sha256'] = $objectInfo->systemMetadata['checksum-sha256'];
            $hasFullObjectChecksum = true;
        }

        if ($hasFullObjectChecksum) {
            $headers['x-amz-checksum-type'] = 'FULL_OBJECT';
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
            $headers['x-amz-meta-' . $metaKey] = $metaValue;
        }

        return $headers;
    }

    /**
     * @param array<non-empty-string, string> $headers
     * @param array<string, string> $queryParams
     * @return array<non-empty-string, string>
     */
    public static function withAuthenticatedOverrides(array $headers, array $queryParams, bool $isAuthenticated): array
    {
        if (! $isAuthenticated) {
            return $headers;
        }

        foreach (self::RESPONSE_HEADER_OVERRIDES as $parameter => $header) {
            $value = $queryParams[$parameter] ?? '';
            if ($value === '') {
                continue;
            }

            if (preg_match('/[\x00-\x1F\x7F]/', $value) === 1) {
                throw new InvalidArgumentException('Invalid response header override.');
            }

            $headers[$header] = $value;
        }

        return $headers;
    }

    private static function restoreHeader(ObjectInfo $objectInfo): string
    {
        if ($objectInfo->restoreStatus === 'restored' && $objectInfo->restoreExpiresAt !== null) {
            return sprintf(
                'ongoing-request="false", expiry-date="%s"',
                self::httpDate($objectInfo->restoreExpiresAt),
            );
        }

        if ($objectInfo->restoreStatus === 'restored') {
            return 'ongoing-request="false"';
        }

        return 'ongoing-request="true"';
    }
}
