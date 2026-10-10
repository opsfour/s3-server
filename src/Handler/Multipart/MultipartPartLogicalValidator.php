<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Handler\Multipart;

use Amp\Http\Server\Request;
use OpsFour\S3Server\Checksum\ChecksumResult;
use OpsFour\S3Server\Exception\BadDigestException;
use OpsFour\S3Server\Exception\IncompleteBodyException;
use OpsFour\S3Server\Exception\InternalErrorException;
use OpsFour\S3Server\Exception\InvalidArgumentException;
use OpsFour\S3Server\Exception\InvalidRequestException;
use OpsFour\S3Server\Exception\NotImplementedException;
use OpsFour\S3Server\Storage\StorageWriteResult;

/** Validates logical S3 part bytes before a local one-shot write is armed. */
final class MultipartPartLogicalValidator
{
    /** @return array<string, string> */
    public static function clientChecksums(Request $request): array
    {
        $supportedHeaders = [
            'x-amz-checksum-crc32',
            'x-amz-checksum-crc32c',
            'x-amz-checksum-sha1',
            'x-amz-checksum-sha256',
        ];
        foreach (array_keys($request->getHeaders()) as $header) {
            $header = strtolower($header);
            if (str_starts_with($header, 'x-amz-checksum-') && ! in_array($header, $supportedHeaders, true)) {
                throw new NotImplementedException('This multipart checksum algorithm is not supported.');
            }
        }

        foreach ($supportedHeaders as $header) {
            self::assertSingleHeaderValue($request, $header);
        }

        $checksums = array_filter([
            'crc32' => $request->getHeader('x-amz-checksum-crc32'),
            'crc32c' => $request->getHeader('x-amz-checksum-crc32c'),
            'sha1' => $request->getHeader('x-amz-checksum-sha1'),
            'sha256' => $request->getHeader('x-amz-checksum-sha256'),
        ], static fn(?string $value): bool => $value !== null);

        if (count($checksums) > 1) {
            throw new InvalidArgumentException('Only one x-amz-checksum-* header may be specified.');
        }

        foreach ($checksums as $algorithm => $value) {
            $decoded = base64_decode($value, strict: true);
            $expectedLength = match ($algorithm) {
                'crc32', 'crc32c' => 4,
                'sha1' => 20,
                'sha256' => 32,
            };
            if ($decoded === false || strlen($decoded) !== $expectedLength) {
                throw new BadDigestException('Multipart part checksum is not a valid Base64-encoded digest.');
            }
        }

        return $checksums;
    }

    public static function assertSdkChecksumUsesVerifiedTrailer(Request $request): void
    {
        self::expectedClientChecksumAlgorithm($request);
    }

    public static function expectedClientChecksumAlgorithm(Request $request): ?string
    {
        $checksums = self::clientChecksums($request);
        self::assertSingleHeaderValue($request, 'x-amz-sdk-checksum-algorithm');
        self::assertSingleHeaderValue($request, 'x-amz-trailer');
        $suppliedAlgorithm = array_key_first($checksums);
        $declaredAlgorithm = $request->getHeader('x-amz-sdk-checksum-algorithm');
        $declared = $declaredAlgorithm === null ? null : self::normalizeAlgorithm($declaredAlgorithm);

        $trailers = array_values(array_filter(array_map(
            static fn(string $name): string => strtolower(trim($name)),
            explode(',', $request->getHeader('x-amz-trailer') ?? ''),
        )));
        $trailerAlgorithm = null;
        if ($trailers !== []) {
            if (count($trailers) !== 1) {
                throw new InvalidRequestException('A multipart part may declare exactly one checksum trailer.');
            }
            $trailerAlgorithm = self::algorithmForHeader($trailers[0]);
            $payloadHash = $request->getHeader('x-amz-content-sha256');
            if (! in_array($payloadHash, [
                'STREAMING-AWS4-HMAC-SHA256-PAYLOAD-TRAILER',
                'STREAMING-UNSIGNED-PAYLOAD-TRAILER',
            ], true)) {
                throw new InvalidRequestException('A checksum trailer requires a verified streaming trailer payload.');
            }
            if ($suppliedAlgorithm !== null && ! $request->hasAttribute('s3.verifiedChecksumTrailers')) {
                throw new InvalidRequestException('A checksum trailer must not be pre-supplied as a normal header.');
            }
        }

        if ($declared !== null && $suppliedAlgorithm !== null && $declared !== $suppliedAlgorithm) {
            throw new InvalidRequestException('The declared SDK checksum algorithm does not match the checksum header.');
        }
        if ($declared !== null && $trailerAlgorithm !== null && $declared !== $trailerAlgorithm) {
            throw new InvalidRequestException('The declared SDK checksum algorithm does not match the checksum trailer.');
        }
        if ($declared !== null && $suppliedAlgorithm === null && $trailerAlgorithm === null) {
            throw new InvalidRequestException('A declared SDK checksum requires a matching checksum header or verified trailer.');
        }

        return $declared ?? $trailerAlgorithm ?? $suppliedAlgorithm;
    }

    /** @return array<string, string> */
    public static function verifiedClientChecksums(Request $request, ?string $expectedAlgorithm): array
    {
        $checksums = self::clientChecksums($request);
        $declaredTrailers = array_values(array_filter(array_map(
            static fn(string $name): string => strtolower(trim($name)),
            explode(',', $request->getHeader('x-amz-trailer') ?? ''),
        )));
        if ($declaredTrailers !== []) {
            $verifiedTrailers = $request->hasAttribute('s3.verifiedChecksumTrailers')
                ? $request->getAttribute('s3.verifiedChecksumTrailers') : null;
            if ($verifiedTrailers !== $declaredTrailers) {
                throw new InvalidRequestException('Multipart checksum trailer verification did not complete.');
            }
        }
        if ($expectedAlgorithm === null) {
            return $checksums;
        }
        if (array_keys($checksums) !== [$expectedAlgorithm]) {
            throw new InvalidRequestException('The declared multipart checksum was not supplied after body verification.');
        }

        return $checksums;
    }

    public static function normalizeAlgorithm(string $algorithm): string
    {
        return match (strtoupper($algorithm)) {
            'CRC32' => 'crc32',
            'CRC32C' => 'crc32c',
            'SHA1' => 'sha1',
            'SHA256' => 'sha256',
            default => throw new InvalidRequestException('Unsupported multipart checksum algorithm.'),
        };
    }

    /** @return non-empty-string */
    public static function headerForAlgorithm(string $algorithm): string
    {
        self::normalizeAlgorithm($algorithm);

        return 'x-amz-checksum-' . strtolower($algorithm);
    }

    private static function algorithmForHeader(string $header): string
    {
        return match ($header) {
            'x-amz-checksum-crc32' => 'crc32',
            'x-amz-checksum-crc32c' => 'crc32c',
            'x-amz-checksum-sha1' => 'sha1',
            'x-amz-checksum-sha256' => 'sha256',
            default => throw new InvalidRequestException('Unsupported multipart checksum trailer.'),
        };
    }

    /** @param array<string, string> $clientChecksums */
    public static function assertValid(
        array $clientChecksums,
        ChecksumResult|StorageWriteResult $logical,
        ?int $declaredLength,
    ): void {
        foreach ($clientChecksums as $algorithm => $clientValue) {
            $computed = self::checksum($logical, $algorithm);
            if ($computed === null) {
                throw new InternalErrorException("Storage backend did not compute checksum for algorithm: {$algorithm}");
            }
            if (! hash_equals($computed, $clientValue)) {
                throw new BadDigestException(
                    "Checksum mismatch: client sent {$clientValue}, computed {$computed}",
                );
            }
        }

        if ($declaredLength !== null && $declaredLength !== $logical->size) {
            throw new IncompleteBodyException(
                'Content-Length mismatch: declared ' . $declaredLength . ', received ' . $logical->size,
            );
        }
    }

    public static function checksum(ChecksumResult|StorageWriteResult $logical, string $algorithm): ?string
    {
        if ($logical instanceof ChecksumResult) {
            return match ($algorithm) {
                'crc32' => $logical->crc32Base64(),
                'crc32c' => $logical->crc32cBase64(),
                'sha1' => $logical->sha1Base64(),
                'sha256' => $logical->sha256Base64(),
                default => null,
            };
        }

        return match ($algorithm) {
            'crc32' => $logical->crc32Base64,
            'crc32c' => $logical->crc32cBase64,
            'sha1' => $logical->sha1Base64,
            'sha256' => $logical->sha256Base64,
            default => null,
        };
    }

    public static function assertPhysicalMatches(ChecksumResult $staged, StorageWriteResult $written): void
    {
        if ($staged->size !== $written->size
            || ! hash_equals($staged->md5Hex, strtolower($written->md5Hex))
            || $staged->crc32Base64() !== $written->crc32Base64
            || $staged->crc32cBase64() !== $written->crc32cBase64
            || $staged->sha1Base64() !== $written->sha1Base64
            || $staged->sha256Base64() !== $written->sha256Base64) {
            throw new InternalErrorException('Multipart worker result does not match the validated staged bytes.');
        }
    }

    public static function stagedResult(ChecksumResult $checksums): StorageWriteResult
    {
        return new StorageWriteResult(
            '',
            $checksums->size,
            $checksums->md5Hex,
            $checksums->crc32Base64(),
            $checksums->crc32cBase64(),
            $checksums->sha1Base64(),
            $checksums->sha256Base64(),
        );
    }

    public static function assertEncodedBytes(ChecksumResult $staged, string $encoded): void
    {
        if ($staged->size !== strlen($encoded)
            || ! hash_equals($staged->sha256Hex, hash('sha256', $encoded))) {
            throw new InternalErrorException('Multipart ciphertext staging does not match its encoded payload.');
        }
    }

    private static function assertSingleHeaderValue(Request $request, string $header): void
    {
        if (count($request->getHeaderArray($header)) > 1) {
            throw new InvalidArgumentException("{$header} must be supplied at most once.");
        }
    }
}
