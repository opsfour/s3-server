<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Metadata\MetadataStore;

/**
 * Resolves requested at-rest encryption before any payload is written.
 *
 * This centralizes fail-closed handling for explicit SSE headers and bucket
 * defaults. aws:kms is intentionally rejected until a real KMS adapter exists.
 */
final class EncryptionRequestResolver
{
    public const string SSE_C = 'SSE-C';

    public const string SSE_S3 = 'AES256';

    public static function resolveDestination(
        Request $request,
        MetadataStore $metadata,
        string $bucket,
        ?EncryptionServiceInterface $encryption,
    ): ?string {
        $algorithm = $request->getHeader('x-amz-server-side-encryption');
        $kmsKeyId = $request->getHeader('x-amz-server-side-encryption-aws-kms-key-id');
        $kmsContext = $request->getHeader('x-amz-server-side-encryption-context');
        $sseCHeaders = [
            $request->getHeader('x-amz-server-side-encryption-customer-algorithm'),
            $request->getHeader('x-amz-server-side-encryption-customer-key'),
            $request->getHeader('x-amz-server-side-encryption-customer-key-md5'),
        ];
        $sseCHeaderCount = count(array_filter($sseCHeaders, static fn(?string $value): bool => $value !== null));

        if ($sseCHeaderCount > 0 && $sseCHeaderCount < count($sseCHeaders)) {
            throw new InvalidArgumentException('All SSE-C headers must be provided together.');
        }
        if ($sseCHeaderCount === count($sseCHeaders)) {
            if ($algorithm !== null || $kmsKeyId !== null || $kmsContext !== null) {
                throw new InvalidArgumentException('SSE-C headers cannot be combined with server-managed encryption headers.');
            }
            self::requireEncryptionService($encryption);
            \assert($sseCHeaders[0] !== null && $sseCHeaders[1] !== null && $sseCHeaders[2] !== null);
            EncryptionService::validateSseCHeaders($sseCHeaders[0], $sseCHeaders[1], $sseCHeaders[2]);

            return self::SSE_C;
        }

        if (($kmsKeyId !== null || $kmsContext !== null) && $algorithm !== 'aws:kms') {
            throw new InvalidArgumentException('KMS key and context headers require aws:kms encryption.');
        }

        if ($algorithm === null) {
            $bucketEncryption = $metadata->getBucketEncryption($bucket);
            $algorithm = $bucketEncryption['sseAlgorithm'] ?? null;
        }

        if ($algorithm === null) {
            return null;
        }
        if ($algorithm === 'aws:kms') {
            throw new NotImplementedException('aws:kms requires a configured KMS adapter, which is not available.');
        }
        if ($algorithm !== self::SSE_S3) {
            throw new InvalidArgumentException("Unsupported server-side encryption algorithm: {$algorithm}");
        }

        self::requireEncryptionService($encryption);

        return self::SSE_S3;
    }

    /**
     * @param list<?string> $headers
     */
    public static function assertCompleteHeaderSet(array $headers, string $description): void
    {
        $count = count(array_filter($headers, static fn(?string $value): bool => $value !== null));
        if ($count > 0 && $count < count($headers)) {
            throw new InvalidArgumentException("All {$description} headers must be provided together.");
        }
    }

    public static function requireEncryptionService(?EncryptionServiceInterface $encryption): void
    {
        if ($encryption === null) {
            throw new NotImplementedException(
                'Server-side encryption was requested, but no master key provider is configured.',
            );
        }
    }

    public static function resolveCustomerKey(
        Request $request,
        bool $required,
        ?string $expectedMd5 = null,
        bool $copySource = false,
    ): ?string {
        $prefix = $copySource ? 'x-amz-copy-source-' : 'x-amz-';
        $algorithm = $request->getHeader($prefix . 'server-side-encryption-customer-algorithm');
        $key = $request->getHeader($prefix . 'server-side-encryption-customer-key');
        $keyMd5 = $request->getHeader($prefix . 'server-side-encryption-customer-key-md5');
        $headers = [$algorithm, $key, $keyMd5];
        $count = count(array_filter($headers, static fn(?string $value): bool => $value !== null));

        if ($count === 0) {
            if ($required) {
                throw new InvalidArgumentException(
                    $copySource
                        ? 'SSE-C copy-source headers are required for this object.'
                        : 'SSE-C headers are required for this object.',
                );
            }

            return null;
        }
        if ($count !== count($headers)) {
            throw new InvalidArgumentException(
                $copySource
                    ? 'All copy-source SSE-C headers must be provided together.'
                    : 'All SSE-C headers must be provided together.',
            );
        }

        \assert($algorithm !== null && $key !== null && $keyMd5 !== null);
        $customerKey = EncryptionService::validateSseCHeaders($algorithm, $key, $keyMd5);
        if ($expectedMd5 !== null && ! hash_equals($expectedMd5, $keyMd5)) {
            throw new InvalidArgumentException('The SSE-C key does not match the object.');
        }

        return $customerKey;
    }
}
