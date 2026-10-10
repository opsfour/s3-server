<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Encryption;

use OpsFour\S3Server\Exception\InternalErrorException;

/** Self-describing ciphertext for one encrypted multipart part. */
final class EncryptedMultipartPartCodec
{
    public const string FORMAT = 'encrypted-v2';

    public const string PLAINTEXT_FORMAT = 'plaintext-v1';

    private const string MAGIC = "S3MPU2\0";

    private const int CONTEXT_BYTES = 32;

    private const int IV_BYTES = 12;

    private const int TAG_BYTES = 16;

    private const int MAX_WRAPPED_KEY_BYTES = 512;

    public const int MIN_OVERHEAD = 7 + 1 + 2 + self::IV_BYTES + self::TAG_BYTES + self::CONTEXT_BYTES;

    public const int MAX_OVERHEAD = 7 + 1 + 2 + self::IV_BYTES + self::TAG_BYTES + self::MAX_WRAPPED_KEY_BYTES + self::CONTEXT_BYTES;

    public static function encode(
        EncryptionServiceInterface $encryption,
        string $mode,
        string $plaintext,
        ?string $customerKey,
        string $bucketGeneration,
        string $bucket,
        string $uploadId,
        int $partNumber,
    ): string {
        $boundPlaintext = self::contextDigest($bucketGeneration, $bucket, $uploadId, $partNumber) . $plaintext;
        if ($mode === EncryptionRequestResolver::SSE_C) {
            if ($customerKey === null || strlen($customerKey) !== 32) {
                throw new InternalErrorException('Multipart customer key is unavailable.');
            }
            $encrypted = $encryption->encryptSseC($boundPlaintext, $customerKey);
            $wrappedKey = '';
            $modeByte = "\x02";
        } elseif ($mode === EncryptionRequestResolver::SSE_S3) {
            $encrypted = $encryption->encryptSseS3($boundPlaintext);
            $wrappedKey = $encrypted['encryptedDataKey'];
            $modeByte = "\x01";
        } else {
            throw new InternalErrorException('Unsupported encrypted multipart part mode.');
        }

        $iv = base64_decode($encrypted['iv'], true);
        $tag = base64_decode($encrypted['tag'], true);
        if ($iv === false || strlen($iv) !== self::IV_BYTES
            || $tag === false || strlen($tag) !== self::TAG_BYTES
            || strlen($encrypted['ciphertext']) !== strlen($boundPlaintext)
            || ($mode === EncryptionRequestResolver::SSE_S3 && ($wrappedKey === '' || base64_decode($wrappedKey, true) === false))
            || strlen($wrappedKey) > self::MAX_WRAPPED_KEY_BYTES) {
            throw new InternalErrorException('Encrypted multipart part envelope is invalid.');
        }

        return self::MAGIC . $modeByte . pack('n', strlen($wrappedKey))
            . $iv . $tag . $wrappedKey . $encrypted['ciphertext'];
    }

    public static function decode(
        EncryptionServiceInterface $encryption,
        string $mode,
        string $stored,
        ?string $customerKey,
        string $bucketGeneration,
        string $bucket,
        string $uploadId,
        int $partNumber,
    ): string {
        $minimum = strlen(self::MAGIC) + 1 + 2 + self::IV_BYTES + self::TAG_BYTES + self::CONTEXT_BYTES;
        if (strlen($stored) < $minimum || ! str_starts_with($stored, self::MAGIC)) {
            throw new InternalErrorException('Encrypted multipart part header is invalid.');
        }
        $offset = strlen(self::MAGIC);
        $modeByte = $stored[$offset++];
        $length = unpack('n', substr($stored, $offset, 2));
        if ($length === false) {
            throw new InternalErrorException('Encrypted multipart part key envelope is invalid.');
        }
        $wrappedKeyLength = $length[1];
        $offset += 2;
        if ($wrappedKeyLength > self::MAX_WRAPPED_KEY_BYTES || strlen($stored) < $minimum + $wrappedKeyLength) {
            throw new InternalErrorException('Encrypted multipart part key envelope is invalid.');
        }
        $iv = base64_encode(substr($stored, $offset, self::IV_BYTES));
        $offset += self::IV_BYTES;
        $tag = base64_encode(substr($stored, $offset, self::TAG_BYTES));
        $offset += self::TAG_BYTES;
        $wrappedKey = substr($stored, $offset, $wrappedKeyLength);
        $offset += $wrappedKeyLength;
        $ciphertext = substr($stored, $offset);

        if ($mode === EncryptionRequestResolver::SSE_C && $modeByte === "\x02" && $wrappedKeyLength === 0 && $customerKey !== null && strlen($customerKey) === 32) {
            $boundPlaintext = $encryption->decryptSseC($ciphertext, $customerKey, $iv, $tag);
        } elseif ($mode === EncryptionRequestResolver::SSE_S3 && $modeByte === "\x01" && $wrappedKeyLength > 0) {
            $boundPlaintext = $encryption->decryptSseS3($ciphertext, $wrappedKey, $iv, $tag);
        } else {
            throw new InternalErrorException('Encrypted multipart part mode does not match its upload.');
        }

        $expectedContext = self::contextDigest($bucketGeneration, $bucket, $uploadId, $partNumber);
        if (! hash_equals($expectedContext, substr($boundPlaintext, 0, self::CONTEXT_BYTES))) {
            throw new InternalErrorException('Encrypted multipart part belongs to another upload or part.');
        }

        return substr($boundPlaintext, self::CONTEXT_BYTES);
    }

    private static function contextDigest(string $bucketGeneration, string $bucket, string $uploadId, int $partNumber): string
    {
        return hash('sha256', json_encode([
            'domain' => 'opsfour-s3-encrypted-multipart-part-v2',
            'generation' => $bucketGeneration,
            'bucket' => $bucket,
            'uploadId' => $uploadId,
            'partNumber' => $partNumber,
        ], JSON_THROW_ON_ERROR), true);
    }
}
