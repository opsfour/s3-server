<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Middleware;

use Amp\Http\Server\Middleware;
use Amp\Http\Server\Request;
use Amp\Http\Server\RequestHandler;
use Amp\Http\Server\Response;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Http\RequestHeaderValues;
use OpsFour\S3Server\Http\RequestBody;
use OpsFour\S3Server\Routing\S3Operation;

/** Validates request-body digests before dispatch without buffering in memory. */
final class ContentMd5Middleware implements Middleware
{
    /** @var list<S3Operation> */
    private const array CHECKSUM_REQUIRED_OPERATIONS = [
        S3Operation::DeleteObjects,
        S3Operation::PutBucketAcl,
        S3Operation::PutBucketCors,
        S3Operation::PutBucketEncryption,
        S3Operation::PutBucketLifecycle,
        S3Operation::PutBucketLogging,
        S3Operation::PutBucketOwnershipControls,
        S3Operation::PutBucketPolicy,
        S3Operation::PutBucketTagging,
        S3Operation::PutBucketVersioning,
        S3Operation::PutBucketWebsite,
        S3Operation::PutObjectAcl,
        S3Operation::PutObjectLegalHold,
        S3Operation::PutObjectLockConfig,
        S3Operation::PutObjectRetention,
        S3Operation::PutObjectTagging,
        S3Operation::PutPublicAccessBlock,
    ];

    public function __construct(private readonly RequestBodySpool $spool) {}

    public function handleRequest(Request $request, RequestHandler $requestHandler): Response
    {
        if (! in_array($request->getMethod(), ['PUT', 'POST'], true)) {
            return $requestHandler->handleRequest($request);
        }
        $contentMd5 = RequestHeaderValues::singleton($request, 'content-md5');

        /** @var array<string, array{algorithm: string, expected: string}> $digests */
        $digests = [];
        if ($contentMd5 !== null) {
            $digests['Content-MD5'] = self::decodeDigest($contentMd5, 'md5', 16, 'Content-MD5');
        }

        if (RequestBody::isBodylessOperation($request) && isset($digests['Content-MD5'])) {
            $computed = md5('', binary: true);
            if (! hash_equals($digests['Content-MD5']['expected'], $computed)) {
                throw new BadDigestException(
                    "The Content-MD5 you specified did not match what we received. Expected: {$contentMd5}, Computed: "
                    . base64_encode($computed),
                );
            }
            if (RequestBody::hasPayloadBytes($request)) {
                throw new BadDigestException('A bodyless S3 operation cannot contain a payload.');
            }

            return $requestHandler->handleRequest($request);
        }

        $operation = $request->hasAttribute('s3.operation') ? $request->getAttribute('s3.operation') : null;
        $objectLockChecksumRequired = $request->hasAttribute(ObjectLockChecksumMiddleware::REQUIRED_ATTRIBUTE)
            && $request->getAttribute(ObjectLockChecksumMiddleware::REQUIRED_ATTRIBUTE) === true;
        $requiresChecksum = in_array($operation, self::CHECKSUM_REQUIRED_OPERATIONS, true)
            || $objectLockChecksumRequired;
        $declaredAlgorithm = null;
        $hasSupportedTrailerDeclaration = false;
        if ($requiresChecksum) {
            // Current AWS PHP SDKs may send a checksum header instead of
            // Content-MD5. Validate a supplied digest before dispatch.
            $hasSdkChecksum = false;
            foreach ([
                'x-amz-checksum-crc32' => ['crc32b', 4],
                'x-amz-checksum-crc32c' => ['crc32c', 4],
                'x-amz-checksum-sha1' => ['sha1', 20],
                'x-amz-checksum-sha256' => ['sha256', 32],
            ] as $header => [$algorithm, $length]) {
                $value = RequestHeaderValues::singleton($request, $header);
                if ($value !== null) {
                    $digests[$header] = self::decodeDigest($value, $algorithm, $length, $header);
                    $hasSdkChecksum = true;
                }
            }
            $declaredAlgorithm = RequestHeaderValues::singleton($request, 'x-amz-sdk-checksum-algorithm');
            $hasSupportedTrailerDeclaration = $objectLockChecksumRequired
                && in_array($operation, [S3Operation::PutObject, S3Operation::UploadPart], true)
                && self::hasSupportedChecksumTrailer($request, $declaredAlgorithm);
            if ($declaredAlgorithm !== null) {
                $declaredHeader = match ($declaredAlgorithm) {
                    'CRC32' => 'x-amz-checksum-crc32',
                    'CRC32C' => 'x-amz-checksum-crc32c',
                    'SHA1' => 'x-amz-checksum-sha1',
                    'SHA256' => 'x-amz-checksum-sha256',
                    default => null,
                };
                if ($declaredHeader === null) {
                    throw new InvalidRequestException('Unsupported SDK checksum algorithm.');
                }
                if ($hasSdkChecksum && ! isset($digests[$declaredHeader])) {
                    throw new BadDigestException('The declared SDK checksum algorithm does not match the supplied checksum.');
                }
                if (! $hasSdkChecksum && ! $hasSupportedTrailerDeclaration) {
                    throw new InvalidRequestException('A declared SDK checksum requires a matching x-amz-checksum header.');
                }
            }
            if ($digests === [] && ! $hasSupportedTrailerDeclaration) {
                throw new InvalidRequestException('A request checksum is required for this operation.');
            }
        }
        if ($digests === [] && ! $hasSupportedTrailerDeclaration) {
            return $requestHandler->handleRequest($request);
        }

        $tempPath = $this->spool->create(
            's3-md5-',
            RequestBodySpool::declaredPlaintextBytes($request),
        );
        $reader = null;
        $primaryFailure = null;

        try {
            $hashes = [];
            foreach ($digests as $header => $digest) {
                $hashes[$header] = hash_init($digest['algorithm']);
            }

            while (($chunk = $request->getBody()->read()) !== null) {
                foreach ($hashes as $hash) {
                    hash_update($hash, $chunk);
                }
                $this->spool->append($tempPath, $chunk);
            }

            if ($hasSupportedTrailerDeclaration) {
                self::assertSupportedChecksumTrailerVerified($request, $declaredAlgorithm);
            }

            foreach ($hashes as $header => $hash) {
                $computed = hash_final($hash, binary: true);
                if (! hash_equals($digests[$header]['expected'], $computed)) {
                    if ($header === 'Content-MD5') {
                        throw new BadDigestException(
                            "The Content-MD5 you specified did not match what we received. Expected: {$contentMd5}, Computed: "
                            . base64_encode($computed),
                        );
                    }
                    throw new BadDigestException(
                        "The {$header} checksum did not match what we received.",
                    );
                }
            }

            $reader = $this->spool->openReadable($tempPath);
            $request->setBody($reader);

            return $requestHandler->handleRequest($request);
        } catch (\Throwable $error) {
            $primaryFailure = $error;
            throw $error;
        } finally {
            $closeFailure = null;
            try {
                $reader?->close();
            } catch (\Throwable $error) {
                $closeFailure = $error;
            }
            $deleteCancellation = null;
            try {
                $this->spool->delete($tempPath);
            } catch (\Amp\CancelledException $error) {
                $deleteCancellation = $error;
            } catch (\Throwable) {
            }
            if ($primaryFailure === null) {
                if ($closeFailure !== null) {
                    throw $closeFailure;
                }
                if ($deleteCancellation !== null) {
                    throw $deleteCancellation;
                }
            }
        }
    }

    /** @return array{algorithm: string, expected: string} */
    private static function decodeDigest(string $value, string $algorithm, int $length, string $header): array
    {
        $expected = base64_decode($value, strict: true);
        if ($expected === false || strlen($expected) !== $length) {
            if ($header === 'Content-MD5') {
                throw new BadDigestException('Content-MD5 must be a Base64-encoded 128-bit MD5 digest.');
            }
            throw new BadDigestException("{$header} must be a Base64-encoded digest of {$length} bytes.");
        }

        return ['algorithm' => $algorithm, 'expected' => $expected];
    }

    private static function hasSupportedChecksumTrailer(Request $request, ?string $declaredAlgorithm): bool
    {
        if ($declaredAlgorithm === null) {
            return false;
        }
        $expectedHeader = match ($declaredAlgorithm) {
            'CRC32' => 'x-amz-checksum-crc32',
            'CRC32C' => 'x-amz-checksum-crc32c',
            'SHA1' => 'x-amz-checksum-sha1',
            'SHA256' => 'x-amz-checksum-sha256',
            default => null,
        };
        $trailer = RequestHeaderValues::singleton($request, 'x-amz-trailer');
        if ($expectedHeader === null || $trailer === null
            || strtolower(trim($trailer)) !== $expectedHeader) {
            return false;
        }

        return in_array(RequestHeaderValues::singleton($request, 'x-amz-content-sha256'), [
            'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
            'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
        ], true);
    }

    private static function assertSupportedChecksumTrailerVerified(Request $request, ?string $declaredAlgorithm): void
    {
        $expectedHeader = match ($declaredAlgorithm) {
            'CRC32' => 'x-amz-checksum-crc32',
            'CRC32C' => 'x-amz-checksum-crc32c',
            'SHA1' => 'x-amz-checksum-sha1',
            'SHA256' => 'x-amz-checksum-sha256',
            default => null,
        };
        $verified = $request->hasAttribute('s3.verifiedChecksumTrailers')
            ? $request->getAttribute('s3.verifiedChecksumTrailers') : null;
        if ($expectedHeader === null || $verified !== [$expectedHeader]
            || RequestHeaderValues::singleton($request, $expectedHeader) === null) {
            throw new InvalidRequestException('Checksum trailer verification did not complete.');
        }
    }
}
