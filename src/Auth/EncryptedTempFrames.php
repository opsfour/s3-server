<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Auth;

use OpsFour\S3Server\Exception\InternalErrorException;

/** Encrypts one bounded spool frame before it reaches a temporary file. */
final class EncryptedTempFrames
{
    public const int MAX_PLAINTEXT_BYTES = 65_536;

    public const int HEADER_BYTES = 32;

    private const string AAD = 'opsfour-s3-temp-frame-v1';

    public static function worstCaseEncodedBytes(int $plaintextBytes): int
    {
        if ($plaintextBytes < 0) {
            throw new \InvalidArgumentException('Temporary spool plaintext size must be non-negative.');
        }
        if ($plaintextBytes === 0) {
            return 0;
        }
        $frames = intdiv($plaintextBytes - 1, self::MAX_PLAINTEXT_BYTES) + 1;
        if ($frames > intdiv(PHP_INT_MAX - $plaintextBytes, self::HEADER_BYTES)) {
            throw new \OverflowException('Temporary spool encoded size overflowed.');
        }

        return $plaintextBytes + ($frames * self::HEADER_BYTES);
    }

    public static function encode(string $key, int $index, string $plaintext): string
    {
        $length = strlen($plaintext);
        if (strlen($key) !== 32 || $index < 0 || $index > 0xffff_ffff
            || $length < 1 || $length > self::MAX_PLAINTEXT_BYTES) {
            throw new \InvalidArgumentException('Invalid temporary spool frame.');
        }

        $nonce = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt(
            $plaintext,
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            $nonce,
            $tag,
            self::AAD . pack('N', $index),
            16,
        );
        if ($ciphertext === false || strlen($tag) !== 16) {
            throw new InternalErrorException('Unable to encrypt temporary spool frame.');
        }

        return pack('N', $length) . $nonce . $tag . $ciphertext;
    }

    public static function decode(string $key, int $index, string $frame): string
    {
        if (strlen($key) !== 32 || $index < 0 || $index > 0xffff_ffff
            || strlen($frame) < self::HEADER_BYTES) {
            throw new InternalErrorException('Temporary spool frame is invalid.');
        }

        $unpacked = unpack('Nlength', substr($frame, 0, 4));
        if ($unpacked === false) {
            throw new InternalErrorException('Temporary spool frame is invalid.');
        }
        $length = $unpacked['length'];
        if ($length < 1 || $length > self::MAX_PLAINTEXT_BYTES
            || strlen($frame) !== self::HEADER_BYTES + $length) {
            throw new InternalErrorException('Temporary spool frame is invalid.');
        }

        $plaintext = openssl_decrypt(
            substr($frame, self::HEADER_BYTES),
            'aes-256-gcm',
            $key,
            OPENSSL_RAW_DATA,
            substr($frame, 4, 12),
            substr($frame, 16, 16),
            self::AAD . pack('N', $index),
        );
        if ($plaintext === false || strlen($plaintext) !== $length) {
            throw new InternalErrorException('Temporary spool frame authentication failed.');
        }

        return $plaintext;
    }
}
