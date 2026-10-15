<?php

declare(strict_types=1);

namespace OpsFour\S3Server\Storage;

use OpsFour\S3Server\Checksum\ChecksumResult;
use OpsFour\S3Server\Dto\ObjectInfo;

final class StoredObjectChecksumVerifier
{
    public static function shouldVerify(ObjectInfo $object): bool
    {
        if (isset($object->userMetadata['__sse-algorithm'])) {
            return false;
        }

        foreach (['checksum-crc32', 'checksum-crc32c', 'checksum-sha1', 'checksum-sha256'] as $key) {
            if (isset($object->systemMetadata[$key])) {
                return true;
            }
        }

        return false;
    }

    public static function assertMatches(ObjectInfo $object, ChecksumResult $checksums, string $failureMessage): void
    {
        // Encrypted payloads are copied as ciphertext while their persisted
        // object checksums describe the plaintext.
        if (isset($object->userMetadata['__sse-algorithm'])) {
            return;
        }

        $expected = [
            'crc32' => $object->systemMetadata['checksum-crc32'] ?? null,
            'crc32c' => $object->systemMetadata['checksum-crc32c'] ?? null,
            'sha1' => $object->systemMetadata['checksum-sha1'] ?? null,
            'sha256' => $object->systemMetadata['checksum-sha256'] ?? null,
        ];
        $actual = [
            'crc32' => $checksums->crc32Base64(),
            'crc32c' => $checksums->crc32cBase64(),
            'sha1' => $checksums->sha1Base64(),
            'sha256' => $checksums->sha256Base64(),
        ];

        foreach ($expected as $algorithm => $value) {
            if ($value !== null && ! hash_equals($value, $actual[$algorithm])) {
                throw new \RuntimeException($failureMessage);
            }
        }
    }
}
